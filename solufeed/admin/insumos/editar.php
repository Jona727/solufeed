<?php
/**
 * SOLUFEED - Editar Insumo
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

$id_insumo = (int) $_GET['id'];
$db = getConnection();

$return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : (isset($_GET['return_to']) ? (string)$_GET['return_to'] : '');
$back_url = safe_return_to($return_to, BASE_URL . '/admin/insumos/listar.php');

// Obtener datos del insumo
$stmt = $db->prepare("SELECT * FROM insumo WHERE id_insumo = ?");
$stmt->execute([$id_insumo]);
$insumo = $stmt->fetch();

if (!$insumo) {
    header('Location: listar.php');
    exit();
}

$mensaje = '';
$error = '';

// Procesar formulario si se envió
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify_post()) {
        $error = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    }

	    if ($error === '') {
    
    // Recibir datos del formulario
    $nombre = trim($_POST['nombre']);
    $tipo = $_POST['tipo'];
    $porcentaje_ms = floatval($_POST['porcentaje_ms']);
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    // Validaciones básicas
    if (empty($nombre) || empty($tipo) || $porcentaje_ms <= 0 || $porcentaje_ms > 100) {
        $error = "Por favor, completá todos los campos correctamente. El % MS debe estar entre 0 y 100.";
    } else {
        // Validar unicidad del nombre (solo entre activos)
        $stmt_dup = $db->prepare("SELECT COUNT(*) FROM insumo WHERE nombre = ? AND activo = 1 AND id_insumo <> ?");
        $stmt_dup->execute([$nombre, $id_insumo]);
        if ((int)$stmt_dup->fetchColumn() > 0) {
            $error = "Ya existe otro insumo activo con ese nombre.";
        }

        // Bloquear desactivación si está asociado a dietas activas (server-side)
        if ($error === '' && $activo === 0) {
            $stmt_use = $db->prepare("
                SELECT COUNT(*)
                FROM dieta_detalle dd
                INNER JOIN dieta d ON dd.id_dieta = d.id_dieta
                WHERE dd.id_insumo = ? AND d.activo = 1
            ");
            $stmt_use->execute([$id_insumo]);
            if ((int)$stmt_use->fetchColumn() > 0) {
                $error = "No podés desactivar este insumo porque está asociado a dietas activas. Desactivá/actualizá esas dietas primero.";
                $activo = 1; // no permitir desactivar
            }
        }

        if ($error === '') {
            // Actualizar en la base de datos
            $stmt_update = $db->prepare("
                UPDATE insumo SET
                    nombre = ?,
                    tipo = ?,
                    porcentaje_ms = ?,
                    activo = ?,
                    fecha_actualizacion = NOW()
                WHERE id_insumo = ?
            ");

            if ($stmt_update->execute([$nombre, $tipo, $porcentaje_ms, $activo, $id_insumo])) {
                $mensaje = "✅ Insumo actualizado exitosamente";

                // Actualizar datos para mostrar en el formulario
                $insumo['nombre'] = $nombre;
                $insumo['tipo'] = $tipo;
                $insumo['porcentaje_ms'] = $porcentaje_ms;
                $insumo['activo'] = $activo;

                // Redirigir suavemente
                flash_set('success', 'Insumo actualizado exitosamente.');
                header('Location: ' . $back_url);
                exit();
            } else {
                $error = "Error al actualizar el insumo";
            }
        }
    }

        }
}
include '../../includes/header.php';
?>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
    <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">✏️ Editar Insumo</h1>
    <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-secondary"><span>←</span> Volver</a>
</div>

<div class="card">
    <?php if ($mensaje): ?>
        <div class="card" style="background: #dcfce7; border-left: 5px solid var(--success); color: #166534; padding: 1rem; margin-bottom: 1.5rem;">
            <p style="margin-bottom: 0.5rem;"><?php echo $mensaje; ?></p>
            <p style="font-size: 0.85rem;">Redirigiendo a la lista...</p>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="card" style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1rem; margin-bottom: 1.5rem;">
            ❌ <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div style="background: #fff8eb; padding: 1rem; border-radius: var(--radius); border-left: 4px solid var(--warning); margin-bottom: 2rem; font-size: 0.9rem;">
        ⚠️ <strong>Atención:</strong> Si este insumo está siendo usado en dietas activas, los cambios en el % MS 
        afectarán los cálculos de los nuevos consumos registrados a partir de ahora.
    </div>
    
    <form method="POST" class="formulario">
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($back_url); ?>">
                <?php echo csrf_input(); ?>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
            <div class="form-group">
                <label for="nombre">Nombre del Insumo *</label>
                <input type="text" id="nombre" name="nombre" required value="<?php echo htmlspecialchars($insumo['nombre']); ?>">
                <small style="color: var(--text-muted); font-size: 0.8rem;">El nombre debe ser descriptivo y único.</small>
            </div>
            
            <div class="form-group">
                <label for="tipo">Tipo de Insumo *</label>
                <select id="tipo" name="tipo" required>
                    <option value="GRANO" <?php echo ($insumo['tipo'] == 'GRANO') ? 'selected' : ''; ?>>Grano</option>
                    <option value="FORRAJE" <?php echo ($insumo['tipo'] == 'FORRAJE') ? 'selected' : ''; ?>>Forraje</option>
                    <option value="CONCENTRADO" <?php echo ($insumo['tipo'] == 'CONCENTRADO') ? 'selected' : ''; ?>>Concentrado</option>
                    <option value="SUPLEMENTO" <?php echo ($insumo['tipo'] == 'SUPLEMENTO') ? 'selected' : ''; ?>>Suplemento</option>
                    <option value="OTRO" <?php echo ($insumo['tipo'] == 'OTRO') ? 'selected' : ''; ?>>Otro</option>
                </select>
                <small style="color: var(--text-muted); font-size: 0.8rem;">Categorizá el insumo para mejor organización.</small>
            </div>
        </div>
        
        <div class="form-group" style="max-width: 300px; margin-top: 1rem;">
            <label for="porcentaje_ms">Porcentaje de Materia Seca (%) *</label>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="number" 
                       id="porcentaje_ms" 
                       name="porcentaje_ms" 
                       step="0.1" 
                       min="0" 
                       max="100" 
                       required 
                       style="font-weight: 800; font-size: 1.2rem; text-align: center;"
                       value="<?php echo $insumo['porcentaje_ms']; ?>">
                <span style="font-weight: 800; color: var(--text-muted);">%</span>
            </div>
            <small style="color: var(--text-muted); font-size: 0.8rem;">
                <strong>Importante:</strong> Se usa para CMS y Eficiencia.
            </small>
        </div>
        
        <div style="margin: 2rem 0; padding: 1rem; background: var(--bg-main); border-radius: var(--radius); display: flex; align-items: center; gap: 1rem;">
            <input type="checkbox" name="activo" id="activo" value="1" <?php echo $insumo['activo'] ? 'checked' : ''; ?> style="width: 20px; height: 20px;">
            <label for="activo" style="margin-bottom: 0; cursor: pointer;">Insumo activo (se podrá usar en nuevas dietas)</label>
        </div>
        
        <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 2rem; display: flex; gap: 1rem;">
            <span><strong>Creado:</strong> <?php echo date('d/m/Y H:i', strtotime($insumo['fecha_creacion'])); ?></span>
            <?php if ($insumo['fecha_actualizacion']): ?>
                <span>| <strong>Actualizado:</strong> <?php echo date('d/m/Y H:i', strtotime($insumo['fecha_actualizacion'])); ?></span>
            <?php endif; ?>
        </div>
        
        <div style="display: flex; gap: 1rem; padding-top: 1.5rem; border-top: 1px solid var(--border);">
            <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">💾 Guardar Cambios</button>
            <a href="listar.php" class="btn btn-secondary btn-lg" style="flex: 0.3;">Cancelar</a>
        </div>
        
</form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form.formulario');
    const activoCheckbox = document.getElementById('activo');
    // Obtenemos el conteo vía PHP para la validación de seguridad (aunque no mostremos la tabla)
    <?php
        $stmt_check = $db->prepare("
            SELECT COUNT(*) as total 
            FROM dieta_detalle dd 
            INNER JOIN dieta d ON dd.id_dieta = d.id_dieta 
            WHERE dd.id_insumo = ? AND d.activo = 1
        ");
        $stmt_check->execute([$id_insumo]);
        $count = $stmt_check->fetch()['total'];
    ?>
    const dietasUsoCount = <?php echo $count; ?>;

    form.addEventListener('submit', function(e) {
        // Si intenta desactivar y está en uso
        if (!activoCheckbox.checked && dietasUsoCount > 0) {
            const confirmacion = confirm(`⚠️ ADVERTENCIA CRÍTICA ⚠️\n\nEste insumo se está utilizando actualmente en ${dietasUsoCount} dieta(s) activa(s).\n\nSi lo desactivas, estas dietas quedarán incompletas o inválidas.\n\n¿Estás SEGURO de que quieres desactivarlo?`);
            
            if (!confirmacion) {
                e.preventDefault();
            }
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
