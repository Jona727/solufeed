<?php
require_once '../includes/functions.php';
iniciarSesion();
register_error_handlers();
send_security_headers();

log_event('INFO', 'LOGOUT', ['user' => $_SESSION['usuario_id'] ?? null]);

// Limpiar datos de sesión
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        $params['secure'] ?? false,
        $params['httponly'] ?? true
    );
}
session_destroy();

header('Location: ' . BASE_URL . '/admin/login.php');
exit();
?>
