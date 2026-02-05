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

$id_tropa = isset($_POST['id_tropa']) ? (int)$_POST['id_tropa'] : 0;
$return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : '';
$back_url = safe_return_to($return_to, BASE_URL . '/admin/lotes/listar.php');

if ($id_tropa <= 0) {
    flash_set('error', 'Lote inválido.');
    header('Location: ' . $back_url);
    exit();
}

$db = getConnection();

// Obtener lote
$stmt = $db->prepare('SELECT id_tropa, nombre, activo FROM tropa WHERE id_tropa = ?');
$stmt->execute([$id_tropa]);
$lote = $stmt->fetch();

if (!$lote) {
    flash_set('error', 'Lote no encontrado.');
    header('Location: ' . $back_url);
    exit();
}

$nuevo_estado = ((int)$lote['activo'] === 1) ? 0 : 1;

try {
    $upd = $db->prepare('UPDATE tropa SET activo = ? WHERE id_tropa = ?');
    $upd->execute([$nuevo_estado, $id_tropa]);

    $accion = $nuevo_estado ? 'activado' : 'desactivado';
    flash_set('success', "Lote '{$lote['nombre']}' {$accion} correctamente.");
} catch (Exception $e) {
    log_event('ERROR', 'TROPA_TOGGLE_FAILED', ['error' => $e->getMessage(), 'id_tropa' => $id_tropa]);
    flash_set('error', 'Error al cambiar el estado del lote.');
}

header('Location: ' . $back_url);
exit();
?>
