<?php
require_once __DIR__ . '/includes/functions.php';

iniciarSesion();

// Si no hay sesión -> login
if (!isset($_SESSION['tipo'])) {
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit();
}

// Redirigir según rol
if ($_SESSION['tipo'] === 'CAMPO') {
    header('Location: ' . BASE_URL . '/admin/campo/index.php');
    exit();
}

if ($_SESSION['tipo'] === 'ADMIN') {
    header('Location: ' . BASE_URL . '/admin/dashboard.php');
    exit();
}

// Si quedó un tipo raro, mandarlo al login
header('Location: ' . BASE_URL . '/admin/login.php');
exit();
?>
