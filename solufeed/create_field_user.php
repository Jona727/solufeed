<?php
// Seguridad: este script solo debe ejecutarse por CLI (no por navegador)
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

// create_field_user.php
// Script temporal para crear un usuario de campo

require_once 'config/database.php';

$db = getConnection();

$nombre = "Operario Campo";
$email = "campo@solufeed.com";
$password = "campo123";
$rol = "CAMPO"; // Definimos este nuevo rol

// Hash seguro
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    // Verificar si ya existe
    $stmt = $db->prepare("SELECT id_usuario FROM usuario WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        // Actualizar
        $stmt = $db->prepare("UPDATE usuario SET password_hash = ?, nombre = ?, tipo = ?, activo = 1 WHERE email = ?");
        $stmt->execute([$hash, $nombre, $rol, $email]);
        echo "✅ Usuario actualizado: $email / $password (Rol: $rol)";
    } else {
        // Crear
        $stmt = $db->prepare("INSERT INTO usuario (nombre, email, password_hash, tipo, activo, fecha_creacion) VALUES (?, ?, ?, ?, 1, NOW())");
        $stmt->execute([$nombre, $email, $hash, $rol]);
        echo "✅ Usuario creado: $email / $password (Rol: $rol)";
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
