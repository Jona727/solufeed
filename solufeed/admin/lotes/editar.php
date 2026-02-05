<?php
/**
 * SOLUFEED - Editar Lote
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Verificar sesión
// Verificar permisos de administrador
verificarAdmin();

// Verificar que se recibió el ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: listar.php');
    exit();
}

$id_tropa = (int) $_GET['id'];

$return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : (isset($_GET['return_to']) ? (string)$_GET['return_to'] : '');
$back_url = safe_return_to($return_to, BASE_URL . '/admin/lotes/ver.php?id=' . $id_tropa);


$db = getConnection();

// Obtener datos del lote (PDO)
$stmt_lote = $db->prepare("
    SELECT t.*, c.nombre as campo_nombre
    FROM tropa t
    INNER JOIN campo c ON t.id_campo = c.id_campo
    WHERE t.id_tropa = ?
");
$stmt_lote->execute([$id_tropa]);
$lote = $stmt_lote->fetch();

if (!$lote) {
    header('Location: listar.php');
    exit();
}

// Obtener dieta vigente actual
$dieta_vigente = obtenerDietaVigente($id_tropa);
$id_dieta_actual = $dieta_vigente ? $dieta_vigente['id_dieta'] : null;

// Obtener campos disponibles (PDO)
$stmt_campos = $db->query("SELECT id_campo, nombre FROM campo WHERE activo = 1 ORDER BY nombre ASC");
$campos_disponibles = $stmt_campos->fetchAll();

// Obtener dietas disponibles (PDO)
$stmt_dietas = $db->query("SELECT id_dieta, nombre FROM dieta WHERE activo = 1 ORDER BY nombre ASC");
$dietas_disponibles = $stmt_dietas->fetchAll();

$mensaje = '';
$error = '';
$errores = [];

// Procesar formulario si se envió
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_post()) {
        $errores[] = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    }
    
    // Recibir datos del formulario
    $nombre = trim($_POST['nombre']);
    $id_campo = (int) $_POST['id_campo'];
    $categoria = trim($_POST['categoria']);
    $fecha_inicio = $_POST['fecha_inicio'];
    $cantidad_inicial = (int) $_POST['cantidad_inicial'];
    $id_dieta_nueva = !empty($_POST['id_dieta']) ? (int) $_POST['id_dieta'] : null;
    $fecha_cambio_dieta = !empty($_POST['fecha_cambio_dieta']) ? $_POST['fecha_cambio_dieta'] : date('Y-m-d');
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    // Validaciones
    if (empty($nombre)) $errores[] = "El nombre del lote es obligatorio.";
    if ($id_campo <= 0) $errores[] = "Debés seleccionar un campo.";
    if (empty($fecha_inicio)) $errores[] = "La fecha de inicio es obligatoria.";
    if ($cantidad_inicial <= 0) $errores[] = "La cantidad inicial de animales debe ser mayor a 0.";
    
    // Si no hay errores, actualizar el lote
    if (empty($errores)) {
        try {
            $db->beginTransaction();
            
            // Actualizar lote
            $stmt_upd = $db->prepare("
                UPDATE tropa SET
                    nombre = ?,
                    id_campo = ?,
                    categoria = ?,
                    fecha_inicio = ?,
                    cantidad_inicial = ?,
                    activo = ?,
                    fecha_actualizacion = NOW()
                WHERE id_tropa = ?
            ");
            
            $stmt_upd->execute([$nombre, $id_campo, $categoria, $fecha_inicio, $cantidad_inicial, $activo, $id_tropa]);
            
            // Verificar si cambió la dieta
            $dieta_cambio = false;
            
            if ($id_dieta_nueva != $id_dieta_actual) {
                
                // Si había una dieta asignada, cerrarla
                if ($id_dieta_actual !== null) {
                    $stmt_cerrar = $db->prepare("
                        UPDATE tropa_dieta_asignada 
                        SET fecha_hasta = ?
                        WHERE id_tropa = ?
                        AND fecha_hasta IS NULL
                    ");
                    $stmt_cerrar->execute([$fecha_cambio_dieta, $id_tropa]);
                }
                
                // Si hay una nueva dieta seleccionada, asignarla
                if ($id_dieta_nueva !== null && $id_dieta_nueva > 0) {
                    $stmt_ins_dieta = $db->prepare("
                        INSERT INTO tropa_dieta_asignada (id_tropa, id_dieta, fecha_desde, fecha_hasta)
                        VALUES (?, ?, ?, NULL)
                    ");
                    $stmt_ins_dieta->execute([$id_tropa, $id_dieta_nueva, $fecha_cambio_dieta]);
                }
                
                $dieta_cambio = true;
            }
            
            $db->commit();
            $mensaje = "✅ Lote actualizado exitosamente.";
            if ($dieta_cambio) {
                $mensaje .= " La dieta fue modificada.";
            }
            
            header("refresh:2;url=" . $back_url);
            
            // Actualizar datos para mostrar en el formulario
            $lote['nombre'] = $nombre;
            $lote['id_campo'] = $id_campo;
            $lote['categoria'] = $categoria;
            $lote['fecha_inicio'] = $fecha_inicio;
            $lote['cantidad_inicial'] = $cantidad_inicial;
            $lote['activo'] = $activo;
            
        } catch (Exception $e) {
            $db->rollBack();
            log_event('ERROR', 'LOTE_UPDATE_FAILED', ['error' => $e->getMessage()]);
            $errores[] = 'Error al actualizar el lote. Reintentá o contactá soporte.';
        }
    }
}

include '../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">✏️ Editar Lote</h1>
    <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><span>←</span> Volver</a>
</div>

<div class="card">
    <?php if ($mensaje): ?>
        <div class="card" style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1rem; margin-bottom: 1.5rem;">
            <p style="margin-bottom: 0.5rem;"><?php echo $mensaje; ?></p>
            <p style="font-size: 0.85rem;">Redirigiendo...</p>
        </div>
    <?php endif; ?>

    <?php if (!empty($errores)): ?>
        <div class="card" style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1.5rem; margin-bottom: 1.5rem;">
            <p style="font-weight: 700; margin-bottom: 0.5rem;">⚠️ Se encontraron errores:</p>
            <ul style="margin-left: 1.25rem; font-size: 0.9rem;">
                <?php foreach ($errores as $err): ?>
                    <li><?php echo $err; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div style="background: #f1f5f9; padding: 1rem; border-radius: var(--radius); border-left: 4px solid var(--secondary); margin-bottom: 2rem; font-size: 0.9rem;">
        ℹ️ <strong>Recordatorio:</strong> Los cambios en la cantidad inicial no afectan los movimientos 
        ya registrados. Para ajustar animales presentes, usá el módulo de movimientos.
    </div>
    
    <form method="POST" class="formulario">
                <?php echo csrf_input(); ?>
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($back_url); ?>">
        
        
<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div class="form-group">
                <label for="nombre">Nombre del Lote *</label>
                <input type="text" id="nombre" name="nombre" required value="<?php echo htmlspecialchars($lote['nombre']); ?>">
                <small style="color: var(--text-muted); font-size: 0.8rem;">Nombre identificador único.</small>
            </div>
            
            <div class="form-group">
                <label for="id_campo">Campo / Ubicación *</label>
                <select id="id_campo" name="id_campo" required>
                    <option value="">-- Seleccioná un campo --</option>
                    <?php foreach ($campos_disponibles as $campo): ?>
                        <option value="<?php echo $campo['id_campo']; ?>" <?php echo ($lote['id_campo'] == $campo['id_campo']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($campo['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="form-group">
            <label for="categoria">Categoría</label>
            <input type="text" id="categoria" name="categoria" value="<?php echo htmlspecialchars($lote['categoria']); ?>">
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1rem;">
            <div class="form-group">
                <label for="fecha_inicio">Fecha de Inicio *</label>
                <input type="date" id="fecha_inicio" name="fecha_inicio" required value="<?php echo $lote['fecha_inicio']; ?>">
            </div>
            
            <div class="form-group">
                <label for="cantidad_inicial">Cantidad Inicial *</label>
                <input type="number" id="cantidad_inicial" name="cantidad_inicial" required min="1" value="<?php echo $lote['cantidad_inicial']; ?>">
            </div>
        </div>

        <div style="font-size: 0.8rem; color: var(--text-muted); margin: 1.5rem 0; display: flex; gap: 1rem; background: var(--bg-main); padding: 0.75rem; border-radius: 8px;">
            <span><strong>Creado:</strong> <?php echo date('d/m/Y H:i', strtotime($lote['fecha_creacion'])); ?></span>
            <?php if ($lote['fecha_actualizacion']): ?>
                <span>| <strong>Actualizado:</strong> <?php echo date('d/m/Y H:i', strtotime($lote['fecha_actualizacion'])); ?></span>
            <?php endif; ?>
        </div>
        
        <div style="background: var(--bg-main); padding: 2rem; border-radius: var(--radius); border: 1px solid var(--border); margin: 2rem 0;">
            <h3 style="color: var(--primary); font-weight: 800; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>📋</span> Gestión de Dieta
            </h3>
            
            <?php if ($dieta_vigente): ?>
                <div style="background: white; padding: 1rem; border-radius: var(--radius); border-left: 4px solid var(--primary); margin-bottom: 1.5rem; font-size: 0.9rem;">
                    📌 <strong>Dieta actual:</strong> <?php echo htmlspecialchars($dieta_vigente['dieta_nombre']); ?>
                    <span style="display: block; color: var(--text-muted); font-size: 0.8rem;">Vigente desde: <?php echo date('d/m/Y', strtotime($dieta_vigente['fecha_desde'])); ?></span>
                </div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                <div class="form-group">
                    <label for="id_dieta">Cambiar Dieta Sugerida</label>
                    <select id="id_dieta" name="id_dieta">
                        <option value="">-- <?php echo $dieta_vigente ? 'Mantener actual' : 'Sin asignar'; ?> --</option>
                        <?php foreach ($dietas_disponibles as $dieta): 
                            $es_actual = ($id_dieta_actual && $dieta['id_dieta'] == $id_dieta_actual);
                        ?>
                            <option value="<?php echo $dieta['id_dieta']; ?>" <?php echo $es_actual ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dieta['nombre']); ?>
                                <?php echo $es_actual ? ' (actual)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="fecha_cambio_dieta">Fecha del Cambio</label>
                    <input type="date" id="fecha_cambio_dieta" name="fecha_cambio_dieta" value="<?php echo date('Y-m-d'); ?>">
                    <small style="color: var(--text-muted); font-size: 0.8rem;">¿Desde cuándo rige el cambio?</small>
                </div>
            </div>
        </div>
        
        <hr style="margin: 2rem 0; border: none; border-top: 2px solid #e9ecef;">
        
        <div style="margin: 2rem 0; padding: 1rem; background: var(--bg-main); border-radius: var(--radius); display: flex; align-items: center; gap: 1rem;">
            <input type="checkbox" name="activo" id="activo" value="1" <?php echo $lote['activo'] ? 'checked' : ''; ?> style="width: 20px; height: 20px;">
            <label for="activo" style="margin-bottom: 0; cursor: pointer; font-weight: 600;">Lote activo (aparece en Hub de Campo)</label>
        </div>
        
        <!-- Botones -->
        <div style="display: flex; gap: 1rem; padding-top: 2rem; border-top: 1px solid var(--border);">
            <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">💾 Guardar Cambios</button>
            <a href="ver.php?id=<?php echo $id_tropa; ?>" class="btn btn-secondary btn-lg" style="flex: 0.3;">Cancelar</a>
        </div>
        
    </form>
    
</div>

<!-- Historial de cambios de dieta -->
<?php
$stmt_hist = $db->prepare("
    SELECT 
        tda.fecha_desde,
        tda.fecha_hasta,
        d.nombre as dieta_nombre
    FROM tropa_dieta_asignada tda
    INNER JOIN dieta d ON tda.id_dieta = d.id_dieta
    WHERE tda.id_tropa = ?
    ORDER BY tda.fecha_desde DESC
");
$stmt_hist->execute([$id_tropa]);
$historial_dietas = $stmt_hist->fetchAll();
?>

<?php if (count($historial_dietas) > 0): ?>
    <h3 class="card-title" style="margin-top: 3rem;"><span>📜</span> Historial de Dietas Asignadas</h3>
    
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Dieta</th>
                    <th style="text-align: center;">Desde</th>
                    <th style="text-align: center;">Hasta</th>
                    <th style="text-align: right;">Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historial_dietas as $hist): ?>
                    <tr>
                        <td style="font-weight: 700; color: var(--primary);"><?php echo htmlspecialchars($hist['dieta_nombre']); ?></td>
                        <td style="text-align: center;"><?php echo date('d/m/Y', strtotime($hist['fecha_desde'])); ?></td>
                        <td style="text-align: center;">
                            <?php echo $hist['fecha_hasta'] ? date('d/m/Y', strtotime($hist['fecha_hasta'])) : '-'; ?>
                        </td>
                        <td style="text-align: right;">
                            <?php if (!$hist['fecha_hasta']): ?>
                                <span class="badge" style="background: #dcfce7; color: #166534;">Vigente</span>
                            <?php else: ?>
                                <span class="badge" style="background: #f1f5f9; color: #475569;">Finalizada</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>