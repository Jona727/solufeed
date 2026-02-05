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

$id_campo = isset($_POST['id_campo']) ? (int)$_POST['id_campo'] : 0;
$return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : '';
$back_url = safe_return_to($return_to, BASE_URL . '/admin/establecimientos/listar.php');

if ($id_campo <= 0) {
    flash_set('error', 'Establecimiento inválido.');
    header('Location: ' . $back_url);
    exit();
}

$db = getConnection();

// Obtener campo
$stmt = $db->prepare('SELECT id_campo, nombre, activo FROM campo WHERE id_campo = ?');
$stmt->execute([$id_campo]);
$campo = $stmt->fetch();

if (!$campo) {
    flash_set('error', 'Establecimiento no encontrado.');
    header('Location: ' . $back_url);
    exit();
}

$actual = (int)$campo['activo'] === 1;
$nuevo_estado = $actual ? 0 : 1;

try {
    // Si se desactiva, bloquear si hay lotes activos asociados
    if ($actual && $nuevo_estado === 0) {
        $stmt_lotes = $db->prepare('SELECT COUNT(*) FROM tropa WHERE id_campo = ? AND activo = 1');
        $stmt_lotes->execute([$id_campo]);
        if ((int)$stmt_lotes->fetchColumn() > 0) {
            flash_set('error', 'No se puede desactivar: el establecimiento tiene lotes activos.');
            header('Location: ' . $back_url);
            exit();
        }
    }

    $upd = $db->prepare('UPDATE campo SET activo = ? WHERE id_campo = ?');
    $upd->execute([$nuevo_estado, $id_campo]);

    $accion = $nuevo_estado ? 'activado' : 'desactivado';
    flash_set('success', "Establecimiento '{$campo['nombre']}' {$accion} correctamente.");
} catch (Exception $e) {
    log_event('ERROR', 'CAMPO_TOGGLE_FAILED', ['error' => $e->getMessage(), 'id_campo' => $id_campo]);
    flash_set('error', 'Error al cambiar el estado del establecimiento.');
}

header('Location: ' . $back_url);
exit();
?>
