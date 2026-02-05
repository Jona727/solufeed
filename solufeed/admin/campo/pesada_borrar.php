<?php
// admin/campo/pesada_borrar.php
// Borrado de una pesada propia (y ajuste pendiente asociado si existe)

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

verificarCampo();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/admin/campo/historial.php');
    exit();
}

$db = getConnection();
$id_usuario = (int)($_SESSION['usuario_id'] ?? 0);

if (!csrf_verify_post()) {
    http_response_code(419);
    die(csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.'));
}

$id_pesada = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$return_to = isset($_POST['return_to']) ? $_POST['return_to'] : (BASE_URL . '/admin/campo/historial.php');
$return_to = safe_return_to($return_to, BASE_URL . '/admin/campo/historial.php');

$pesada = obtenerPesadaDeUsuarioCampo($id_pesada, $id_usuario);
if (!$pesada) {
    header('Location: ' . BASE_URL . '/admin/campo/historial.php');
    exit();
}

// Bloquear borrado si ya hay ajuste procesado
$stmt = $db->prepare("SELECT estado FROM ajuste_animales_pendiente WHERE id_pesada = ? ORDER BY fecha_creacion DESC LIMIT 1");
$stmt->execute([$id_pesada]);
$estado = $stmt->fetchColumn();
if ($estado && $estado !== 'PENDIENTE') {
    header('Location: ' . BASE_URL . '/admin/campo/historial.php');
    exit();
}

try {
    $db->beginTransaction();
    // Borrar ajuste pendiente (si existe)
    $stmt = $db->prepare("DELETE FROM ajuste_animales_pendiente WHERE id_pesada = ? AND estado = 'PENDIENTE'");
    $stmt->execute([$id_pesada]);
    // Borrar pesada
    $stmt = $db->prepare("DELETE FROM pesada WHERE id_pesada = ? AND id_usuario = ?");
    $stmt->execute([$id_pesada, $id_usuario]);
    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    header('Location: ' . BASE_URL . '/admin/campo/historial.php');
    exit();
}

$dest = safe_return_to($return_to, BASE_URL . '/admin/campo/historial.php');
header('Location: ' . $dest);
exit();
