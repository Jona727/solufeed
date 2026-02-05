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

$id_dieta = isset($_POST['id_dieta']) ? (int)$_POST['id_dieta'] : 0;
$return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : '';
$back_url = safe_return_to($return_to, BASE_URL . '/admin/dietas/listar.php');

if ($id_dieta <= 0) {
    flash_set('error', 'Dieta inválida.');
    header('Location: ' . $back_url);
    exit();
}

$db = getConnection();

// Obtener dieta
$stmt = $db->prepare('SELECT id_dieta, nombre, activo FROM dieta WHERE id_dieta = ?');
$stmt->execute([$id_dieta]);
$dieta = $stmt->fetch();

if (!$dieta) {
    flash_set('error', 'Dieta no encontrada.');
    header('Location: ' . $back_url);
    exit();
}

$actual = (int)$dieta['activo'] === 1;
$nuevo_estado = $actual ? 0 : 1;

// Reglas:
// - Si se desactiva una dieta en uso (vigente en un lote activo), bloquear.
// - Si se activa una dieta, evitar duplicado de nombre entre dietas activas.
try {
    if ($actual && $nuevo_estado === 0) {
        $stmt_check = $db->prepare(
            'SELECT COUNT(*)
             FROM tropa_dieta_asignada tda
             INNER JOIN tropa t ON tda.id_tropa = t.id_tropa
             WHERE tda.id_dieta = ? AND tda.fecha_hasta IS NULL AND t.activo = 1'
        );
        $stmt_check->execute([$id_dieta]);
        $en_uso = (int)$stmt_check->fetchColumn() > 0;
        if ($en_uso) {
            flash_set('error', 'No se puede desactivar: la dieta está vigente en al menos un lote activo.');
            header('Location: ' . $back_url);
            exit();
        }
    }

    if (!$actual && $nuevo_estado === 1) {
        $stmt_dup = $db->prepare('SELECT COUNT(*) FROM dieta WHERE activo = 1 AND nombre = ? AND id_dieta <> ?');
        $stmt_dup->execute([(string)$dieta['nombre'], $id_dieta]);
        if ((int)$stmt_dup->fetchColumn() > 0) {
            flash_set('error', 'Ya existe una dieta activa con ese nombre. Cambiá el nombre antes de activarla.');
            header('Location: ' . $back_url);
            exit();
        }
    }

    $upd = $db->prepare('UPDATE dieta SET activo = ?, fecha_actualizacion = NOW() WHERE id_dieta = ?');
    $upd->execute([$nuevo_estado, $id_dieta]);

    $accion = $nuevo_estado ? 'activada' : 'desactivada';
    flash_set('success', "Dieta '{$dieta['nombre']}' {$accion} correctamente.");
} catch (Exception $e) {
    log_event('ERROR', 'DIETA_TOGGLE_FAILED', ['error' => $e->getMessage(), 'id_dieta' => $id_dieta]);
    flash_set('error', 'Error al cambiar el estado de la dieta.');
}

header('Location: ' . $back_url);
exit();
?>
