<?php
/**
 * SOLUFEED - Registrar Pesada
 * Calcula ADPV automáticamente y detecta diferencias de animales
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar sesión y rol de campo
verificarCampo();

$db = getConnection();

// Verificar si viene un lote pre-seleccionado
$lote_preseleccionado = isset($_GET['lote']) ? (int) $_GET['lote'] : null;

// Obtener lotes activos (filtrados por usuario campo)
$params = [];
$sql_lotes = "\n    SELECT t.id_tropa, t.nombre, c.nombre as campo_nombre\n    FROM tropa t\n    INNER JOIN campo c ON t.id_campo = c.id_campo\n";

if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'CAMPO') {
    $sql_lotes .= " INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa \n                 WHERE t.activo = 1 AND ut.id_usuario = ?\n";
    $params[] = (int)$_SESSION['usuario_id'];
} else {
    $sql_lotes .= " WHERE t.activo = 1\n";
}

$sql_lotes .= " ORDER BY t.nombre ASC";
$stmt_lotes = $db->prepare($sql_lotes);
$stmt_lotes->execute($params);
$lotes_disponibles = $stmt_lotes->fetchAll(PDO::FETCH_ASSOC);

// Variables para el formulario
$lote_seleccionado = null;
$animales_esperados = 0;
$peso_anterior = null;
$fecha_anterior = null;
$dias_desde_ultima = 0;
$adpv_estimado = null;

$errores = [];

// Si se seleccionó un lote (por GET o POST)
$id_lote_actual = $lote_preseleccionado;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_tropa'])) {
    $id_lote_actual = (int) $_POST['id_tropa'];
}

// Seguridad: el usuario CAMPO solo puede acceder a sus lotes asignados
if ($id_lote_actual && !usuarioCampoPuedeAccederLote($id_lote_actual)) {
    $errores[] = "No tenés permisos para acceder a ese lote.";
    $id_lote_actual = null;
}

if ($id_lote_actual) {
    // Obtener datos del lote
    $params_lote = [$id_lote_actual];
    $sql_lote = "\n        SELECT t.*, c.nombre as campo_nombre\n        FROM tropa t\n        INNER JOIN campo c ON t.id_campo = c.id_campo\n        WHERE t.id_tropa = ? AND t.activo = 1\n    ";

    if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'CAMPO') {
        $sql_lote = "\n            SELECT t.*, c.nombre as campo_nombre\n            FROM tropa t\n            INNER JOIN campo c ON t.id_campo = c.id_campo\n            INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa\n            WHERE t.id_tropa = ? AND t.activo = 1 AND ut.id_usuario = ?\n        ";
        $params_lote = [$id_lote_actual, (int)$_SESSION['usuario_id']];
    }

    $stmt = $db->prepare($sql_lote);
    $stmt->execute($params_lote);
    $lote_seleccionado = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($lote_seleccionado) {
        $animales_esperados = obtenerAnimalesPresentes($id_lote_actual);

        // Obtener última pesada
        $stmt_u = $db->prepare("\n            SELECT peso_promedio, fecha\n            FROM pesada\n            WHERE id_tropa = ?\n            ORDER BY fecha DESC\n            LIMIT 1\n        ");
        $stmt_u->execute([$id_lote_actual]);
        $ultima_pesada = $stmt_u->fetch(PDO::FETCH_ASSOC);

        if ($ultima_pesada) {
            $peso_anterior = (float)$ultima_pesada['peso_promedio'];
            $fecha_anterior = $ultima_pesada['fecha'];

            // Calcular días desde última pesada
            try {
                $fecha_ant = new DateTime($fecha_anterior);
                $fecha_hoy = new DateTime();
                $dias_desde_ultima = $fecha_ant->diff($fecha_hoy)->days;
            } catch (Throwable $e) {
                $dias_desde_ultima = 0;
            }
        }
    }
}

// Procesar formulario si se envió
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_pesada'])) {

    // Recibir datos del formulario
    $id_tropa = (int) ($_POST['id_tropa'] ?? 0);
    $fecha = trim((string)($_POST['fecha'] ?? ''));
    $peso_promedio = (float) ($_POST['peso_promedio'] ?? 0);
    $animales_esperados_form = (int) ($_POST['animales_esperados'] ?? 0);
    $animales_vistos = (int) ($_POST['animales_vistos'] ?? 0);
    $hay_diferencia = ($animales_vistos != $animales_esperados_form) ? 1 : 0;
    $origen_registro = isset($_POST['origen_registro']) ? trim((string)$_POST['origen_registro']) : 'ONLINE';

    // Si hay diferencia, recibir datos adicionales
    $diferencia_animales = 0;
    $motivo_operario = '';

    if ($hay_diferencia) {
        $diferencia_animales = $animales_vistos - $animales_esperados_form;
        $motivo_operario = isset($_POST['motivo_operario']) ? trim((string)$_POST['motivo_operario']) : '';
    }

    // Validaciones
    $errores = [];

    // CSRF
    if (!csrf_verify_post()) {
        $errores[] = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    }

    // Seguridad: revalidar acceso al lote en el POST (evita formularios forzados)
    if ($id_tropa > 0 && !usuarioCampoPuedeAccederLote($id_tropa)) {
        $errores[] = "No tenés permisos para registrar en ese lote.";
    }

    if ($id_tropa <= 0) {
        $errores[] = "Debés seleccionar un lote.";
    }

    // Validar fecha YYYY-MM-DD
    if (empty($fecha)) {
        $errores[] = "La fecha es obligatoria.";
    } else {
        $dt = DateTime::createFromFormat('Y-m-d', $fecha);
        $is_valid = $dt && $dt->format('Y-m-d') == $fecha;
        if (!$is_valid) {
            $errores[] = "Fecha inválida.";
        }
    }

    if ($peso_promedio <= 0) {
        $errores[] = "El peso promedio debe ser mayor a 0.";
    }

    if ($animales_vistos <= 0) {
        $errores[] = "La cantidad de animales vistos debe ser mayor a 0.";
    }

    if ($hay_diferencia && $motivo_operario === '') {
        $errores[] = "Indicá un motivo si hay diferencia de animales.";
    }

    // Si no hay errores, guardar
    if (empty($errores)) {
        try {
            $db->beginTransaction();

            // Insertar pesada
            $stmt_i = $db->prepare("\n                INSERT INTO pesada
                (id_tropa, id_usuario, fecha, peso_promedio, animales_esperados, animales_vistos, hay_diferencia, origen_registro, fecha_creacion)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt_i->execute([
                $id_tropa,
                (int)$_SESSION['usuario_id'],
                $fecha,
                $peso_promedio,
                $animales_esperados_form,
                $animales_vistos,
                $hay_diferencia,
                $origen_registro
            ]);

            $id_pesada = (int)$db->lastInsertId();

            // Si hay diferencia, crear ajuste pendiente
            if ($hay_diferencia) {
                $stmt_a = $db->prepare("\n                    INSERT INTO ajuste_animales_pendiente
                    (id_pesada, id_tropa, diferencia_animales, motivo_operario, estado, fecha_creacion)
                    VALUES
                    (?, ?, ?, ?, 'PENDIENTE', NOW())
                ");
                $stmt_a->execute([
                    $id_pesada,
                    $id_tropa,
                    $diferencia_animales,
                    $motivo_operario
                ]);
            }

            $db->commit();

            $exito = "✓ Pesada registrada exitosamente.";
            if ($hay_diferencia) {
                $exito .= " Se creó un ajuste pendiente para revisión del administrador.";
            }

            header("refresh:2;url=../campo/index.php");

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            log_event('ERROR', 'PESADA_SAVE_FAILED', ['error' => $e->getMessage()]);
            $errores[] = 'Error al registrar la pesada. Reintentá o contactá soporte.';
        }
    }
}

include '../../includes/header.php';
?>

<h1 style="font-weight: 800; color: var(--primary); margin-bottom: 2rem;">⚖️ Registrar Pesada</h1>

<!-- Indicador de estado para PWA -->
<div id="connection-status" style="display:none; padding: 15px; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: bold;"></div>

<div class="card">
    
    <?php if (isset($exito)): ?>
        <div class="card" style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1rem; margin-bottom: 1.5rem;">
            <?php echo $exito; ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($errores)): ?>
        <div class="card" style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1rem; margin-bottom: 1.5rem;">
            <strong style="display: block; margin-bottom: 0.5rem;">Se encontraron errores:</strong>
            <ul style="margin: 0; padding-left: 1.5rem; font-size: 0.9rem;">
                <?php foreach ($errores as $error): ?>
                    <li><?php echo $error; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <form method="POST" class="formulario" id="formPesada">
        
        <?php echo csrf_input(); ?>

        
        <!-- PASO 1: Seleccionar Lote -->
        <h3 class="card-title"><span>📍</span> Paso 1: Seleccionar Lote</h3>
        
        <div class="form-grupo">
            <label for="id_tropa">Lote a Pesar *</label>
            <select id="id_tropa" name="id_tropa" required data-searchable="true" data-search-placeholder="Buscar lote..." onchange="if(this.value) window.location.href='?lote=' + this.value;">
                <option value="">-- Seleccioná un lote --</option>
                <?php foreach ($lotes_disponibles as $lote): ?>
                    <option value="<?php echo $lote['id_tropa']; ?>"
                        <?php echo ($id_lote_actual == $lote['id_tropa']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($lote['nombre']); ?> - <?php echo htmlspecialchars($lote['campo_nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($lote_seleccionado): ?>
            
            <!-- Información del lote seleccionado -->
            <div class="card" style="background: var(--bg-main); border: 1px solid var(--border); margin-bottom: 2rem;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div>
                        <small style="color: var(--text-muted); display: block; text-transform: uppercase; font-weight: 700; font-size: 0.7rem;">Lote</small>
                        <strong style="color: var(--primary);"><?php echo htmlspecialchars($lote_seleccionado['nombre']); ?></strong>
                    </div>
                    <div>
                        <small style="color: var(--text-muted); display: block; text-transform: uppercase; font-weight: 700; font-size: 0.7rem;">Animales</small>
                        <strong><?php echo $animales_esperados; ?> cab</strong>
                    </div>
                    <div>
                        <small style="color: var(--text-muted); display: block; text-transform: uppercase; font-weight: 700; font-size: 0.7rem;">Días en Feedlot</small>
                        <?php
                        $fecha_inicio = new DateTime($lote_seleccionado['fecha_inicio']);
                        $fecha_hoy = new DateTime();
                        $dias_total = $fecha_inicio->diff($fecha_hoy)->days;
                        ?>
                        <strong><?php echo $dias_total; ?> días</strong>
                    </div>
                </div>
            </div>
            
            <!-- Historial de pesadas -->
            <?php if ($peso_anterior): ?>
                <div class="card" style="background: #eef2ff; border: 1px solid var(--secondary); margin-bottom: 2rem; display: flex; align-items: center; gap: 15px;">
                    <div style="font-size: 1.5rem;">📈</div>
                    <div>
                        <strong style="color: var(--secondary); display: block; font-size: 0.8rem; text-transform: uppercase;">Última pesada: <?php echo formatearFecha($fecha_anterior); ?></strong>
                        <span style="font-size: 1.1rem; font-weight: 700; color: var(--secondary);"><?php echo formatearNumero($peso_anterior, 1); ?> kg</span>
                        <small style="color: var(--text-muted); margin-left: 5px;">(Hace <?php echo $dias_desde_ultima; ?> días)</small>
                    </div>
                </div>
            <?php else: ?>
                <div class="card" style="background: #f1f5f9; border: none; margin-bottom: 2rem;">
                    <div style="font-size: 0.9rem; color: var(--text-muted); font-weight: 500;">
                        ℹ️ Esta será la <strong>primera pesada</strong> registrada. Servirá como peso inicial.
                    </div>
                </div>
            <?php endif; ?>
            
            <hr style="margin: 2rem 0; border: none; border-top: 2px solid #e9ecef;">
            
            <!-- PASO 2: Datos de la Pesada -->
            <h3 class="card-title"><span>📝</span> Paso 2: Datos de la Pesada</h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                
                <!-- Fecha -->
                <div class="form-grupo">
                    <label for="fecha">Fecha de la Pesada *</label>
                    <input 
                        type="date" 
                        id="fecha" 
                        name="fecha" 
                        required 
                        value="<?php echo date('Y-m-d'); ?>"
                    >
                </div>
                
                <!-- Peso promedio -->
                <div class="form-group">
                    <label for="peso_promedio">Peso Promedio (kg) *</label>
                    <input 
                        type="number" 
                        id="peso_promedio" 
                        name="peso_promedio" 
                        step="0.01" 
                        min="0"
                        required 
                        inputmode="decimal"
                        placeholder="Ej: 410.5"
                        style="font-size: 1.5rem; font-weight: 900; color: var(--primary); padding: 1rem;"
                    >
                    <small style="color: var(--text-muted);">Ingresá el peso promedio del lote.</small>
                </div>
                
            </div>
            
            <!-- Cálculo de ADPV automático -->
            <?php if ($peso_anterior && $dias_desde_ultima > 0): ?>
                <div id="calculo-adpv" class="card" style="background: var(--bg-main); border: 2px dashed var(--border); margin-top: 1rem; text-align: center; display: none;">
                    <small style="color: var(--text-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 1px;">Aumento diario (ADPV)</small>
                    <div style="font-size: 2.5rem; font-weight: 900; margin: 0.5rem 0;" id="valor-adpv">0.00</div>
                    <small style="color: var(--text-muted);">kg ganados por día</small>
                </div>
            <?php endif; ?>
            
            <hr style="margin: 2rem 0; border: none; border-top: 2px solid #e9ecef;">
            
            <!-- PASO 3: Verificación de Animales -->
            <h3 class="card-title"><span>🔍</span> Paso 3: Contador de Animales</h3>
            
            <div class="mensaje mensaje-info" style="margin-bottom: 1.5rem;">
                ℹ️ Verificá si la cantidad de animales que viste coincide con la cantidad esperada.
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                
                <!-- Animales esperados (automático) -->
                <div class="form-grupo">
                    <label for="animales_esperados">Animales Esperados</label>
                    <input 
                        type="number" 
                        id="animales_esperados" 
                        name="animales_esperados" 
                        readonly 
                        value="<?php echo $animales_esperados; ?>"
                        style="background: #f8f9fa; font-weight: bold; font-size: 1.1rem;"
                    >
                    <small>Calculado automáticamente según movimientos.</small>
                </div>
                
                <!-- Animales vistos (ingreso manual) -->
                <div class="form-grupo">
                    <label for="animales_vistos">Animales Vistos en la Pesada *</label>
                    <input 
                        type="number" 
                        id="animales_vistos" 
                        name="animales_vistos" 
                        required 
                        min="0"
                        value="<?php echo $animales_esperados; ?>"
                        style="font-weight: bold; font-size: 1.1rem;"
                    >
                    <small>¿Cuántos animales contaste realmente?</small>
                </div>
                
            </div>
            
            <!-- Diferencia detectada -->
            <div id="diferencia-container" class="card" style="display: none; background: #fff3cd; border: 2px solid var(--warning);">
                <div style="display: flex; gap: 15px; align-items: center; margin-bottom: 1rem;">
                    <div style="font-size: 2rem;">⚠️</div>
                    <div>
                        <strong style="color: #856404; display: block;">¡Diferencia detectada!</strong>
                        <span id="texto-diferencia" style="font-weight: 700; color: #856404;"></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="motivo_operario">¿Qué pasó? (Opcional)</label>
                    <textarea 
                        id="motivo_operario" 
                        name="motivo_operario" 
                        rows="3"
                        placeholder="Ej: Se murió uno, se escaparon, error de conteo anterior, etc."
                    ></textarea>
                </div>
            </div>
            
            <!-- Botones -->
            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <input type="hidden" name="guardar_pesada" value="1">
                <button type="submit" class="btn btn-primary" style="flex: 2; padding: 1.25rem;">💾 Guardar Pesada</button>
                <a href="../campo/index.php" class="btn btn-secondary" style="flex: 1; padding: 1.25rem;">❌ Cancelar</a>
            </div>
            
        <?php endif; ?>
        
    </form>
    
</div>

<!-- JavaScript para cálculos automáticos -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    const inputPeso = document.getElementById('peso_promedio');
    const inputAnimalesVistos = document.getElementById('animales_vistos');
    
    if (!inputPeso || !inputAnimalesVistos) return; // Salir si no hay lote seleccionado
    
    const animalesEsperados = parseInt(document.getElementById('animales_esperados').value) || 0;
    const diferenciaContainer = document.getElementById('diferencia-container');
    const textoDiferencia = document.getElementById('texto-diferencia');
    
    <?php if ($peso_anterior && $dias_desde_ultima > 0): ?>
    const calculoADPV = document.getElementById('calculo-adpv');
    const valorADPV = document.getElementById('valor-adpv');
    const pesoAnterior = <?php echo $peso_anterior; ?>;
    const diasDesdeUltima = <?php echo $dias_desde_ultima; ?>;
    <?php endif; ?>
    
    // Función para calcular ADPV
    function calcularADPV() {
        <?php if ($peso_anterior && $dias_desde_ultima > 0): ?>
        const pesoActual = parseFloat(inputPeso.value) || 0;
        
        if (pesoActual > 0) {
            const adpv = (pesoActual - pesoAnterior) / diasDesdeUltima;
            valorADPV.textContent = adpv.toFixed(3);
            calculoADPV.style.display = 'block';
            
            // Cambiar color según ADPV
            if (adpv < 0) {
                valorADPV.style.color = '#dc3545'; // Rojo si perdió peso
            } else if (adpv < 0.5) {
                valorADPV.style.color = '#ffc107'; // Amarillo si es bajo
            } else {
                valorADPV.style.color = '#155724'; // Verde si es bueno
            }
        } else {
            calculoADPV.style.display = 'none';
        }
        <?php endif; ?>
    }
    
    // Función para verificar diferencia de animales
    function verificarDiferencia() {
        const animalesVistos = parseInt(inputAnimalesVistos.value) || 0;
        const diferencia = animalesVistos - animalesEsperados;
        
        if (diferencia !== 0) {
            diferenciaContainer.style.display = 'block';
            
            if (diferencia > 0) {
                textoDiferencia.innerHTML = '<span style="color: #28a745;">+' + diferencia + ' animal(es) más de lo esperado</span>';
            } else {
                textoDiferencia.innerHTML = '<span style="color: #dc3545;">' + diferencia + ' animal(es) menos de lo esperado</span>';
            }
        } else {
            diferenciaContainer.style.display = 'none';
        }
    }
    
    // Event listeners
    inputPeso.addEventListener('input', calcularADPV);
    inputAnimalesVistos.addEventListener('input', verificarDiferencia);
    
    // Calcular inicial
    calcularADPV();
    verificarDiferencia();
    
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        if (!navigator.onLine) {
            e.preventDefault();
            
            const formData = new FormData(form);
            const data = {};
            formData.forEach((value, key) => {
                data[key] = value;
            });
            
            // Bandera para el servidor
            data['guardar_pesada'] = '1';
            const clientUUID = (window.OfflineManager && typeof OfflineManager.generateUUID === 'function') ? OfflineManager.generateUUID() : (String(Date.now()) + '-' + String(Math.random()).slice(2));
            data['client_uuid'] = clientUUID;
            data['origen_registro'] = 'OFFLINE:' + clientUUID;

            const base = (window.BASE_URL || '').toString();
            const syncEndpoint = base + '/admin/api/sync_pesada.php';
            OfflineManager.saveToQueue(syncEndpoint, data, 'pesada');
            
            setTimeout(() => {
                window.location.href = '../campo/index.php';
            }, 3000);
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
