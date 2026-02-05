<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarAdmin();

// Solo POST para evitar cambios por URL
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: listar.php');
    exit();
}

if (!csrf_verify_post()) {
    flash_set('error', csrf_fail_response());
    header('Location: listar.php');
    exit();
}

$id_insumo = isset($_POST['id_insumo']) ? (int)$_POST['id_insumo'] : 0;
$return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : '';
$back_url  = safe_return_to($return_to, BASE_URL . '/admin/insumos/listar.php');

if ($id_insumo <= 0) {
    flash_set('error', 'Insumo inválido.');
    header('Location: ' . $back_url);
    exit();
}

$db = getConnection();

// Obtener insumo
$stmt = $db->prepare('SELECT id_insumo, nombre, activo FROM insumo WHERE id_insumo = ?');
$stmt->execute([$id_insumo]);
$insumo = $stmt->fetch();

if (!$insumo) {
    flash_set('error', 'Insumo no encontrado.');
    header('Location: ' . $back_url);
    exit();
}

$nuevo_estado = ((int)$insumo['activo'] === 1) ? 0 : 1;

try {
    $upd = $db->prepare('UPDATE insumo SET activo = ? WHERE id_insumo = ?');
    $upd->execute([$nuevo_estado, $id_insumo]);

    $accion = $nuevo_estado ? 'activado' : 'desactivado';
    flash_set('success', "Insumo '{$insumo['nombre']}' {$accion} correctamente.");
} catch (Exception $e) {
    log_event('ERROR', 'INSUMO_TOGGLE_FAILED', ['error' => $e->getMessage(), 'id_insumo' => $id_insumo]);
    flash_set('error', 'Error al cambiar el estado del insumo.');
}

header('Location: ' . $back_url);
exit();
?>
