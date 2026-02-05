<?php
require_once '../../includes/functions.php';

// Requiere sesión activa (admin o campo)
verificarSesion();

header('Content-Type: application/json; charset=utf-8');

// Rotar siempre al pedir refresh (el token anterior queda tolerado por ventana breve)
$token = csrf_rotate_token();

echo json_encode([
    'csrf_token' => $token,
    'issued_at' => time(),
]);
