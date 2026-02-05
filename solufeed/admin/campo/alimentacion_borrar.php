<?php
// admin/campo/alimentacion_borrar.php
// Borrado de una alimentación propia (consumo_lote + detalle)

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

$id_consumo = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$return_to = isset($_POST['return_to']) ? $_POST['return_to'] : (BASE_URL . '/admin/campo/historial.php');
$return_to = safe_return_to($return_to, BASE_URL . '/admin/campo/historial.php');

// Revalidar ownership
$consumo = obtenerConsumoDeUsuarioCampo($id_consumo, $id_usuario);
if (!$consumo) {
    header('Location: ' . BASE_URL . '/admin/campo/historial.php');
    exit();
}

try {
    $db->beginTransaction();
    $stmt = $db->prepare("DELETE FROM consumo_lote_detalle WHERE id_consumo = ?");
    $stmt->execute([$id_consumo]);
    $stmt = $db->prepare("DELETE FROM consumo_lote WHERE id_consumo = ? AND id_usuario = ?");
    $stmt->execute([$id_consumo, $id_usuario]);
    $db->commit();
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    header('Location: ' . BASE_URL . '/admin/campo/historial.php');
    exit();
}

$dest = safe_return_to($return_to, BASE_URL . '/admin/campo/historial.php');
header('Location: ' . $dest);
exit();
