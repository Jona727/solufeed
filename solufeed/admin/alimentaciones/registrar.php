<?php
/**
 * SOLUFEED - Registrar Alimentación
 * Módulo principal del sistema - Registra consumo real y calcula MS automáticamente
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar sesión y rol de campo
verificarCampo();

$db = getConnection();

// Verificar si viene un lote pre-seleccionado
$lote_preseleccionado = isset($_GET['lote']) ? (int) $_GET['lote'] : null;

// Obtener lotes activos (filtrados por usuario campo)
// Nota: ordenamos primero los lotes que NO fueron alimentados hoy (solo aviso, no bloquea)
// Usamos fecha "hoy" desde PHP para que sea consistente con el horario del servidor/app.
$hoy = date('Y-m-d');

$params_lotes = [$hoy];
$sql_lotes = "
    SELECT 
        t.id_tropa,
        t.nombre,
        c.nombre as campo_nombre,
        CASE WHEN cl.id_tropa IS NULL THEN 0 ELSE 1 END AS alimentado_hoy
    FROM tropa t
    INNER JOIN campo c ON t.id_campo = c.id_campo
";

if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'CAMPO') {
    $sql_lotes .= " INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa ";
}

$sql_lotes .= " LEFT JOIN (SELECT DISTINCT id_tropa FROM consumo_lote WHERE fecha = ?) cl ON t.id_tropa = cl.id_tropa ";

$sql_lotes .= " WHERE t.activo = 1 ";

if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'CAMPO') {
    $sql_lotes .= " AND ut.id_usuario = ? ";
    $params_lotes[] = (int)$_SESSION['usuario_id'];
}

$sql_lotes .= " ORDER BY alimentado_hoy ASC, t.nombre ASC ";

$lotes_disponibles = getAll($sql_lotes, $params_lotes);

// Variables para el formulario
$lote_seleccionado = null;
$dieta_vigente = null;
$insumos_dieta = [];
$animales_presentes = 0;
$alimentaciones_hoy = null;

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
    // Obtener datos del lote (defensa en profundidad: validar asignación en consulta)
    $params = [$id_lote_actual];
    $sql = "
        SELECT t.*, c.nombre as campo_nombre
        FROM tropa t
        INNER JOIN campo c ON t.id_campo = c.id_campo
    ";
    if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'CAMPO') {
        $sql .= " INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa ";
    }
    $sql .= " WHERE t.id_tropa = ? ";
    if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'CAMPO') {
        $sql .= " AND ut.id_usuario = ? ";
        $params[] = (int)$_SESSION['usuario_id'];
    }

    $lote_seleccionado = getOne($sql, $params);

    if ($lote_seleccionado) {
        // Aviso: ¿el lote ya fue alimentado hoy?
        $stmt_hoy = $db->prepare("SELECT COUNT(*) FROM consumo_lote WHERE id_tropa = ? AND fecha = ?");
        $stmt_hoy->execute([$id_lote_actual, $hoy]);
        $alimentaciones_hoy = (int)$stmt_hoy->fetchColumn();

        $animales_presentes = obtenerAnimalesPresentes($id_lote_actual);

        // Obtener dieta vigente
        $dieta_vigente = obtenerDietaVigente($id_lote_actual);

        // Si tiene dieta, obtener sus insumos
        if ($dieta_vigente && isset($dieta_vigente['id_dieta'])) {
            $sql_insumos = "
                SELECT 
                    i.id_insumo,
                    i.nombre,
                    i.tipo,
                    i.porcentaje_ms,
                    dd.porcentaje_teorico
                FROM dieta_detalle dd
                INNER JOIN insumo i ON dd.id_insumo = i.id_insumo
                WHERE dd.id_dieta = ?
                ORDER BY dd.porcentaje_teorico DESC
            ";
            $insumos_dieta = getAll($sql_insumos, [(int)$dieta_vigente['id_dieta']]);
        }
    }
}

// Valores del formulario (preservar ante error)
$form_fecha = isset($_POST['fecha']) ? $_POST['fecha'] : date('Y-m-d');
$form_hora = isset($_POST['hora']) ? $_POST['hora'] : date('H:i');
$form_sobrante = isset($_POST['sobrante_nivel']) ? $_POST['sobrante_nivel'] : 'NORMAL';
$form_kg_totales = isset($_POST['kg_totales']) ? (float)$_POST['kg_totales'] : '';
$form_kg_reales = isset($_POST['kg_real']) && is_array($_POST['kg_real']) ? $_POST['kg_real'] : [];

// Procesar formulario si se envió el registro completo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_alimentacion'])) {

    // Recibir datos del formulario
    $id_tropa = (int) $_POST['id_tropa'];
    $fecha = isset($_POST['fecha']) ? trim($_POST['fecha']) : '';
    $hora = isset($_POST['hora']) ? trim($_POST['hora']) : '';
    $sobrante_nivel = isset($_POST['sobrante_nivel']) ? trim($_POST['sobrante_nivel']) : '';
    $kg_totales = isset($_POST['kg_totales']) ? (float) $_POST['kg_totales'] : 0;
    $kg_reales = isset($_POST['kg_real']) && is_array($_POST['kg_real']) ? $_POST['kg_real'] : []; // Array de [id_insumo => kg]
    $origen_registro = isset($_POST['origen_registro']) ? trim($_POST['origen_registro']) : 'ONLINE';

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

    if (empty($fecha) || empty($hora)) {
        $errores[] = "La fecha y hora son obligatorias.";
    }

    if ($kg_totales <= 0) {
        $errores[] = "Los kg totales deben ser mayores a 0.";
    }

    // Verificar que se hayan ingresado kg para al menos un insumo
    $hay_insumos = false;
    $suma_kg_reales = 0;
    foreach ($kg_reales as $kg) {
        $kg_val = (float)$kg;
        if ($kg_val > 0) {
            $hay_insumos = true;
            $suma_kg_reales += $kg_val;
        }
    }

    if (!$hay_insumos) {
        $errores[] = "Debés ingresar kg reales para al menos un insumo.";
    }

    // Verificar que la suma de kg reales esté dentro de un margen razonable (+- 5%)
    $margen_permitido = $kg_totales * 0.05;
    if ($kg_totales > 0 && abs($suma_kg_reales - $kg_totales) > $margen_permitido) {
        $errores[] = "La diferencia entre el total (" . $kg_totales . " kg) y la suma de insumos (" . $suma_kg_reales . " kg) supera el 5% permitido (" . number_format($margen_permitido, 2) . " kg).";
    }

    // Obtener animales presentes
    $animales = obtenerAnimalesPresentes($id_tropa);

    // Calcular número de alimentación del día
    $stmt_num = $db->prepare("SELECT COALESCE(MAX(numero_alimentacion_dia), 0) + 1 AS siguiente FROM consumo_lote WHERE id_tropa = ? AND fecha = ?");
    $stmt_num->execute([$id_tropa, $fecha]);
    $row_num = $stmt_num->fetch(PDO::FETCH_ASSOC);
    $numero_alimentacion = $row_num ? (int)$row_num['siguiente'] : 1;

    // Si no hay errores, guardar
    if (empty($errores)) {
        try {
            $db->beginTransaction();

            // Insertar cabezal de consumo
            $stmt = $db->prepare(
                "INSERT INTO consumo_lote 
                (id_tropa, id_usuario, fecha, hora, numero_alimentacion_dia, sobrante_nivel, kg_totales_tirados, animales_presentes, origen_registro, fecha_creacion)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $id_tropa,
                (int)$_SESSION['usuario_id'],
                $fecha,
                $hora,
                $numero_alimentacion,
                $sobrante_nivel,
                $kg_totales,
                $animales,
                $origen_registro
            ]);

            $id_consumo = (int)$db->lastInsertId();

            // Obtener dieta vigente para calcular porcentajes
            $dieta = obtenerDietaVigente($id_tropa, $fecha);
            if (!$dieta || !isset($dieta['id_dieta'])) {
                throw new RuntimeException("Este lote no tiene una dieta asignada para la fecha seleccionada.");
            }

            // Obtener insumos de la dieta
            $stmt_ins = $db->prepare("SELECT i.id_insumo, i.porcentaje_ms, dd.porcentaje_teorico
                 FROM dieta_detalle dd
                 INNER JOIN insumo i ON dd.id_insumo = i.id_insumo
                 WHERE dd.id_dieta = ?");
            $stmt_ins->execute([(int)$dieta['id_dieta']]);
            $insumos_dieta_rows = $stmt_ins->fetchAll(PDO::FETCH_ASSOC);

            // Preparar insert detalle
            $stmt_det = $db->prepare(
                "INSERT INTO consumo_lote_detalle (id_consumo, id_insumo, kg_sugeridos, kg_reales, porcentaje_real, kg_ms)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            foreach ($insumos_dieta_rows as $insumo) {
                $id_insumo = (int)$insumo['id_insumo'];

                if (isset($kg_reales[$id_insumo]) && (float)$kg_reales[$id_insumo] > 0) {
                    $kg_real = (float)$kg_reales[$id_insumo];

                    // Calcular kg sugeridos según dieta teórica
                    $kg_sugeridos = ((float)$insumo['porcentaje_teorico'] * $kg_totales) / 100;

                    // Calcular porcentaje real
                    $porcentaje_real = ($kg_totales > 0) ? (($kg_real / $kg_totales) * 100) : 0;

                    // kg de Materia Seca
                    $kg_ms = ($kg_real * (float)$insumo['porcentaje_ms']) / 100;

                    $stmt_det->execute([
                        $id_consumo,
                        $id_insumo,
                        $kg_sugeridos,
                        $kg_real,
                        $porcentaje_real,
                        $kg_ms
                    ]);
                }
            }

            $db->commit();

            $exito = "✓ Alimentación registrada exitosamente. Los cálculos de MS se realizaron automáticamente.";
            header("refresh:2;url=../campo/index.php");

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            log_event('ERROR', 'ALIMENTACION_SAVE_FAILED', ['error' => $e->getMessage()]);
            $errores[] = 'Error al guardar la alimentación. Reintentá o contactá soporte.';
        }
    }
}

include '../../includes/header.php';
?>

<h1 style="font-weight: 800; color: var(--primary); margin-bottom: 2rem;">🍽️ Registrar Alimentación</h1>

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

    <form method="POST" class="formulario" id="formAlimentacion">

        <?php echo csrf_input(); ?>

        <!-- PASO 1: Seleccionar Lote -->
        <h3 class="card-title"><span>📍</span> Paso 1: Seleccionar Lote</h3>

        <div class="form-grupo">
            <label for="id_tropa">Lote a Alimentar *</label>
            <select id="id_tropa" name="id_tropa" required data-searchable="true" data-search-placeholder="Buscar lote..." onchange="if(this.value) window.location.href='?lote=' + this.value;">
                <option value="">-- Seleccioná un lote --</option>
                <?php foreach ($lotes_disponibles as $lote): ?>
                    <option value="<?php echo (int)$lote['id_tropa']; ?>" <?php echo ($id_lote_actual == (int)$lote['id_tropa']) ? 'selected' : ''; ?>>
                        <?php
                            $icon = ((int)($lote['alimentado_hoy'] ?? 0) === 1) ? '🟠' : '🟢';
                            echo $icon . ' ' . htmlspecialchars($lote['nombre']) . ' - ' . htmlspecialchars($lote['campo_nombre']);
                        ?>
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
                        <strong><?php echo (int)$animales_presentes; ?> cab</strong>
                    </div>
                    <div>
                        <small style="color: var(--text-muted); display: block; text-transform: uppercase; font-weight: 700; font-size: 0.7rem;">Dieta Vigente</small>
                        <?php if ($dieta_vigente): ?>
                            <span style="color: var(--success); font-weight: 700;"><?php echo htmlspecialchars($dieta_vigente['dieta_nombre']); ?></span>
                        <?php else: ?>
                            <span style="color: var(--danger); font-weight: 700;">Sin dieta</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($alimentaciones_hoy !== null): ?>
                <?php if ($alimentaciones_hoy > 0): ?>
                    <div class="card" style="background: #ffedd5; border-left: 5px solid var(--warning); color: #9a3412; padding: 1rem; margin-bottom: 1.5rem;">
                        🟠 <strong>Atención:</strong> hoy ya se registró <?php echo (int)$alimentaciones_hoy; ?> alimentación(es) para este lote.
                        Podés cargar otra si corresponde.
                    </div>
                <?php else: ?>
                    <div class="card" style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1rem; margin-bottom: 1.5rem;">
                        🟢 <strong>OK:</strong> hoy todavía no se registró ninguna alimentación para este lote.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!$dieta_vigente): ?>

                <div class="mensaje mensaje-error">
                    ⚠️ Este lote no tiene una dieta asignada.
                    <a href="../lotes/editar.php?id=<?php echo (int)$id_lote_actual; ?>">Asigná una dieta</a>
                    antes de registrar alimentaciones.
                </div>

            <?php else: ?>

                <hr style="margin: 2rem 0; border: none; border-top: 2px solid #e9ecef;">

                <!-- PASO 2: Datos de la Alimentación -->
                <h3 class="card-title"><span>📝</span> Paso 2: Datos de la Alimentación</h3>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">

                    <!-- Fecha -->
                    <div class="form-grupo">
                        <label for="fecha">Fecha *</label>
                        <input type="date" id="fecha" name="fecha" required value="<?php echo htmlspecialchars($form_fecha); ?>">
                    </div>

                    <!-- Hora -->
                    <div class="form-grupo">
                        <label for="hora">Hora *</label>
                        <input type="time" id="hora" name="hora" required value="<?php echo htmlspecialchars($form_hora); ?>">
                    </div>

                    <!-- Nivel de sobras -->
                    <div class="form-grupo">
                        <label for="sobrante_nivel">Estado del Comedero *</label>
                        <select id="sobrante_nivel" name="sobrante_nivel" required style="font-weight: 700;">
                            <option value="SIN_SOBRAS" <?php echo ($form_sobrante==='SIN_SOBRAS')?'selected':''; ?>>🟢 Sin sobras</option>
                            <option value="POCAS_SOBRAS" <?php echo ($form_sobrante==='POCAS_SOBRAS')?'selected':''; ?>>🟡 Pocas sobras</option>
                            <option value="NORMAL" <?php echo ($form_sobrante==='NORMAL')?'selected':''; ?>>🔵 Normal</option>
                            <option value="MUCHAS_SOBRAS" <?php echo ($form_sobrante==='MUCHAS_SOBRAS')?'selected':''; ?>>🔴 Muchas sobras</option>
                        </select>
                        <small>¿Cómo estaba el comedero ANTES de alimentar?</small>
                    </div>

                </div>

                <hr style="margin: 2rem 0; border: none; border-top: 2px solid #e9ecef;">

                <!-- PASO 3: Mezcla Real por Insumo -->
                <h3 class="card-title"><span>🌾</span> Paso 3: Mezcla Real Entregada</h3>

                <div class="card" style="background: #eef2ff; border: none; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 15px;">
                    <div style="font-size: 1.5rem;">ℹ️</div>
                    <div style="font-size: 0.9rem; color: var(--secondary); font-weight: 500;">
                        Ingresá los <strong>kg totales</strong> del mixer y luego los <strong>reales</strong> que salieron para cada insumo.
                    </div>
                </div>

                <div class="table-container" style="margin-bottom: 1rem;">
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Insumo</th>
                                <th style="text-align:left;">% MS</th>
                                <th style="width: 150px;">Kg Sugeridos</th>
                                <th style="width: 150px;">Kg Reales *</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($insumos_dieta as $insumo): ?>
                                <?php $id_ins = (int)$insumo['id_insumo']; ?>
                                <tr>
                                    <td>
                                        <strong style="display: block; color: var(--text-main);"><?php echo htmlspecialchars($insumo['nombre']); ?></strong>
                                        <small style="color: var(--text-muted);"><?php echo htmlspecialchars($insumo['tipo']); ?> | <?php echo formatearNumero($insumo['porcentaje_teorico'], 1); ?>% ración</small>
                                    </td>
                                    <td>
                                        <span style="background: var(--bg-main); padding: 4px 10px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; border: 1px solid var(--border);">
                                            <?php echo formatearNumero($insumo['porcentaje_ms'], 1); ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <input type="text" class="kg-sugerido" data-porcentaje="<?php echo $insumo['porcentaje_teorico']; ?>" readonly style="background: #f1f5f9; text-align: center; font-weight: 600; border-color: transparent;" placeholder="0.0">
                                    </td>
                                    <td>
                                        <input 
                                            type="number" 
                                            name="kg_real[<?php echo $id_ins; ?>]"
                                            class="kg-real"
                                            step="0.1" 
                                            min="0"
                                            inputmode="decimal"
                                            placeholder="0.0"
                                            value="<?php echo isset($form_kg_reales[$id_ins]) ? htmlspecialchars($form_kg_reales[$id_ins]) : ''; ?>"
                                            style="text-align: center; font-weight: 800; border-bottom: 3px solid var(--border);"
                                            data-ms="<?php echo $insumo['porcentaje_ms']; ?>"
                                        >
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <!-- Fila de totales -->
                            <tr style="background: #f8fafc; font-weight: bold;">
                                <td colspan="3" style="padding: 1.5rem; font-size: 1.1rem; color: var(--primary);">
                                    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                                        <span style="text-align:right; flex:1; min-width: 220px;">TOTAL DEL MIXER (Kg):</span>
                                        <button type="button" class="btn btn-secondary" id="btnCopiarSugeridos" style="white-space:nowrap;">↪️ Copiar sugeridos → reales</button>
                                    </div>
                                </td>
                                <td style="padding: 1rem;">
                                    <input 
                                        type="number" 
                                        id="kg_totales" 
                                        name="kg_totales"
                                        step="0.1" 
                                        min="0"
                                        required
                                        inputmode="decimal"
                                        placeholder="TOTAL"
                                        value="<?php echo ($form_kg_totales !== '') ? htmlspecialchars((string)$form_kg_totales) : ''; ?>"
                                        style="width: 100%; padding: 1rem; text-align: center; font-size: 1.5rem; font-weight: 900; border: 3px solid var(--primary); color: var(--primary); background: white;"
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="card" style="background: var(--bg-main); border: 2px dashed var(--border); padding: 1.5rem; margin-bottom: 2rem;">
                    <strong style="display: block; color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem; margin-bottom: 1rem; letter-spacing: 1px;">📊 Resumen automático</strong>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1.5rem;">
                        <div style="text-align: center;">
                            <div id="suma-kg-reales" style="font-size: 1.75rem; font-weight: 800; color: var(--primary);">0.0 <small style="font-size: 50%;">kg</small></div>
                            <small style="font-weight: 600; color: var(--text-muted);">Suma Reales</small>
                        </div>
                        <div style="text-align: center;">
                            <div id="total-ms" style="font-size: 1.75rem; font-weight: 800; color: var(--secondary);">0.0 <small style="font-size: 50%;">kg MS</small></div>
                            <small style="font-weight: 600; color: var(--text-muted);">Materia Seca</small>
                        </div>
                        <div style="text-align: center;">
                            <div id="ms-por-animal" style="font-size: 1.75rem; font-weight: 800; color: var(--accent);">0.0 <small style="font-size: 50%;">kg/an</small></div>
                            <small style="font-weight: 600; color: var(--text-muted);">MS por Animal</small>
                        </div>
                    </div>
                </div>

                <!-- Botones -->
                <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                    <input type="hidden" name="guardar_alimentacion" value="1">
                    <button type="submit" class="btn btn-primary" style="flex: 2; padding: 1.25rem;">💾 Guardar Alimentación</button>
                    <a href="../campo/index.php" class="btn btn-secondary" style="flex: 1; padding: 1.25rem;">❌ Cancelar</a>
                </div>

            <?php endif; ?>

        <?php endif; ?>

    </form>

</div>

<!-- JavaScript para cálculos automáticos -->
<script>
document.addEventListener('DOMContentLoaded', function() {

    const inputsKgReal = document.querySelectorAll('.kg-real');
    const inputKgTotales = document.getElementById('kg_totales');
    const inputsSugeridos = document.querySelectorAll('.kg-sugerido');
    const btnCopiar = document.getElementById('btnCopiarSugeridos');
    const animalesPresentes = <?php echo (int)$animales_presentes; ?>;

    if (!inputKgTotales) return; // Evitar errores si no hay lote seleccionado todavía

    function calcularSugeridos() {
        const kgTotales = parseFloat(inputKgTotales.value) || 0;
        inputsSugeridos.forEach(input => {
            const porcentaje = parseFloat(input.dataset.porcentaje) || 0;
            const kgSugerido = (kgTotales * porcentaje) / 100;
            input.value = kgSugerido.toFixed(1);
        });
    }

    function calcularTotales() {
        let sumaKgReales = 0;
        let totalMS = 0;

        inputsKgReal.forEach(input => {
            const kgReal = parseFloat(input.value) || 0;
            const porcentajeMS = parseFloat(input.dataset.ms) || 0;
            sumaKgReales += kgReal;
            totalMS += (kgReal * porcentajeMS) / 100;
        });

        const sumaEl = document.getElementById('suma-kg-reales');
        const totalMSEl = document.getElementById('total-ms');
        const msPorAnimalEl = document.getElementById('ms-por-animal');

        if (sumaEl) sumaEl.textContent = sumaKgReales.toFixed(1) + ' kg';
        if (totalMSEl) totalMSEl.textContent = totalMS.toFixed(1) + ' kg MS';

        const msPorAnimal = animalesPresentes > 0 ? totalMS / animalesPresentes : 0;
        if (msPorAnimalEl) msPorAnimalEl.textContent = msPorAnimal.toFixed(2) + ' kg/an';
    }

    function copiarSugeridosAReales() {
        inputsSugeridos.forEach((inputSug, idx) => {
            const kgSug = parseFloat(inputSug.value) || 0;
            const inputReal = inputsKgReal[idx];
            if (inputReal) {
                inputReal.value = kgSug.toFixed(1);
            }
        });
        calcularTotales();
    }

    // Eventos
    inputKgTotales.addEventListener('input', function() {
        calcularSugeridos();
        calcularTotales();
    });

    inputsKgReal.forEach(input => input.addEventListener('input', calcularTotales));

    if (btnCopiar) btnCopiar.addEventListener('click', copiarSugeridosAReales);

    // Inicializar
    calcularSugeridos();
    calcularTotales();

    // Validación antes de enviar (evita reinicio si hay error)
    const form = document.getElementById('formAlimentacion');
    if (form) {
        form.addEventListener('submit', function(e) {
            const kgTot = parseFloat(inputKgTotales.value) || 0;
            if (kgTot <= 0) {
                e.preventDefault();
                if (window.showToast) showToast('Los kg totales deben ser mayores a 0', 'error');
                return;
            }
            let suma = 0;
            let algun = false;
            inputsKgReal.forEach(inp => {
                const v = parseFloat(inp.value) || 0;
                if (v > 0) algun = true;
                suma += v;
            });
            if (!algun) {
                e.preventDefault();
                if (window.showToast) showToast('Ingresá kg reales para al menos un insumo', 'error');
                return;
            }
            const margen = kgTot * 0.05;
            if (Math.abs(suma - kgTot) > margen) {
                e.preventDefault();
                if (window.showToast) showToast('La suma de insumos difiere del total más de 5%', 'error');
            }

            // Offline: guardar en cola local y sincronizar luego (sin perder datos)
            if (!navigator.onLine) {
                // Si ya hubo un preventDefault por validación, no encolar
                if (e.defaultPrevented) return;

                e.preventDefault();

                try {
                    const formData = new FormData(form);
                    const data = {};
                    formData.forEach((value, key) => {
                        data[key] = value;
                    });

                    // Bandera para el servidor
                    data['guardar_alimentacion'] = '1';

                    const clientUUID = (window.OfflineManager && typeof OfflineManager.generateUUID === 'function')
                        ? OfflineManager.generateUUID()
                        : (String(Date.now()) + '-' + String(Math.random()).slice(2));

                    data['client_uuid'] = clientUUID;
                    data['origen_registro'] = 'OFFLINE:' + clientUUID;

                    const base = (window.BASE_URL || '').toString();
                    const syncEndpoint = base + '/admin/api/sync_alimentacion.php';

                    if (window.OfflineManager && typeof OfflineManager.saveToQueue === 'function') {
                        OfflineManager.saveToQueue(syncEndpoint, data, 'alimentacion');
                    }

                    setTimeout(() => {
                        window.location.href = '../campo/index.php';
                    }, 3000);
                } catch (err) {
                    if (window.showToast) showToast('No se pudo guardar offline. Reintentá.', 'error');
                }
            }
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
