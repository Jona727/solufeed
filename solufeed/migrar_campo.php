<?php
// Seguridad: este script solo debe ejecutarse por CLI (no por navegador)
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Script de Migración - Tabla Campo (Establecimientos)
 */
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // Force local
require_once __DIR__ . '/config/database.php';

echo "Iniciando migración de Establecimientos...\n";

try {
    $db = getConnection();
    
    // 1. Crear tabla campo
    $sql = "CREATE TABLE IF NOT EXISTS `campo` (
      `id_campo` INT(11) NOT NULL AUTO_INCREMENT,
      `nombre` VARCHAR(100) NOT NULL COMMENT 'Nombre del establecimiento (ej: Campo Norte)',
      `ubicacion` VARCHAR(255) NULL COMMENT 'Ubicación física o coordenadas',
      `activo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Activo, 0=Inactivo',
      `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_campo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->exec($sql);
    echo "Tabla 'campo' verificada.\n";
    
    // 2. Verificar datos
    $stmt = $db->query("SELECT count(*) as total FROM campo");
    $total = $stmt->fetch()['total'];
    
    if ($total == 0) {
        $db->exec("INSERT INTO `campo` (`nombre`) VALUES ('Campo Principal')");
        echo "Se insertó un campo por defecto.\n";
    } else {
        echo "Existen $total establecimientos.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
