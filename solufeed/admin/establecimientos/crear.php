<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarAdmin();

$mensaje = '';
$error = '';

// Volver al origen (lista con filtros/paginación, si aplica)
$return_to = isset($_GET['return_to']) ? (string)$_GET['return_to'] : '';
$back_url = safe_return_to($return_to, BASE_URL . '/admin/establecimientos/listar.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $ubicacion = trim($_POST['ubicacion'] ?? '');

    $return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : $return_to;
    $back_url = safe_return_to($return_to, BASE_URL . '/admin/establecimientos/listar.php');

    if (!csrf_verify_post()) {
        $error = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    } elseif (empty($nombre)) {
        $error = "El nombre es obligatorio.";
    } else {
        try {
            $db = getConnection();
            // Compatibilidad: insertamos solo columnas esenciales para evitar errores por columna inexistente.
            $stmt = $db->prepare("INSERT INTO campo (nombre, ubicacion) VALUES (?, ?)");
            $stmt->execute([$nombre, $ubicacion !== '' ? $ubicacion : null]);

            flash_set('success', "Establecimiento '{$nombre}' creado correctamente.");
            header('Location: ' . $back_url);
            exit();
        } catch (Exception $e) {
            log_event('ERROR', 'ESTABLECIMIENTO_CREATE_FAILED', ['error' => $e->getMessage()]);
            $error = 'Error al crear el establecimiento. Reintentá o contactá soporte.';
        }
    }
}
?>
<?php
$page_title = 'Crear Establecimiento';
require_once '../../includes/header.php';
?>
<style>
        .form-container {
            max-width: 600px;
            margin: 2rem auto;
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 600; }
        .form-group input { width: 100%; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 8px; }
        .btn-submit { width: 100%; padding: 1rem; background: var(--primary); color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; }
        .btn-submit:hover { background: var(--primary-dark); }
    </style>
<div class="container">
        <a href="<?php echo htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8'); ?>" style="text-decoration: none; color: var(--text-muted);">← Volver</a>
        
        <div class="form-container">
            <h1 style="margin-top: 0;">🏭 Nuevo Establecimiento</h1>
            
            <?php if ($error): ?>
                <div class="alert alert-error" style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label>Nombre del Campo *</label>
                    <input type="text" name="nombre" required placeholder="Ej: Campo Norte - Sector A">
                </div>
                
                <div class="form-group">
                    <label>Ubicación / Referencia</label>
                    <input type="text" name="ubicacion" placeholder="Ej: Ruta 5 km 200">
                </div>
                
                <button type="submit" class="btn-submit">Guardar Establecimiento</button>
            </form>
        </div>
    </div>

<?php require_once '../../includes/footer.php'; ?>
