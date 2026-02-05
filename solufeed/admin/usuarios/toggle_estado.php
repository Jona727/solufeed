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

$return_to = isset($_POST['return_to']) ? (string)$_POST['return_to'] : '';
$back_url  = safe_return_to($return_to, BASE_URL . '/admin/usuarios/listar.php');

if (!csrf_verify_post()) {
    flash_set('error', csrf_fail_response());
    header('Location: ' . $back_url);
    exit();
}

$id_usuario = isset($_POST['id_usuario']) ? (int)$_POST['id_usuario'] : 0;

if ($id_usuario <= 0) {
    flash_set('error', 'Usuario inválido.');
    header('Location: ' . $back_url);
    exit();
}

$db = getConnection();

// Obtener usuario
$stmt = $db->prepare('SELECT id_usuario, nombre, tipo, activo FROM usuario WHERE id_usuario = ?');
$stmt->execute([$id_usuario]);
$usuario = $stmt->fetch();

if (!$usuario) {
    flash_set('error', 'Usuario no encontrado.');
    header('Location: ' . $back_url);
    exit();
}

// Prevenir que el admin se desactive a sí mismo
if ((int)$usuario['id_usuario'] === (int)($_SESSION['usuario_id'] ?? 0)) {
    flash_set('error', 'No podés cambiar tu propio estado.');
    header('Location: ' . $back_url);
    exit();
}

// No permitir activar/desactivar cuentas ADMIN (se gestiona fuera de este panel)
if (($usuario['tipo'] ?? '') === 'ADMIN') {
    flash_set('error', 'No se permite cambiar el estado de cuentas administrador desde aquí.');
    header('Location: ' . $back_url);
    exit();
}

$nuevo_estado = ((int)$usuario['activo'] === 1) ? 0 : 1;

try {
    $upd = $db->prepare('UPDATE usuario SET activo = ? WHERE id_usuario = ?');
    $upd->execute([$nuevo_estado, $id_usuario]);

    $accion = $nuevo_estado ? 'activado' : 'desactivado';
    flash_set('success', "Usuario '{$usuario['nombre']}' {$accion} correctamente.");
} catch (Exception $e) {
    log_event('ERROR', 'USUARIO_TOGGLE_FAILED', ['error' => $e->getMessage(), 'id_usuario' => $id_usuario]);
    flash_set('error', 'Error al cambiar el estado del usuario.');
}

header('Location: ' . $back_url);
exit();
?>
