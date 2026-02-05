<?php
// Seguridad: este script solo debe ejecutarse por CLI (no por navegador)
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Script de Migración - Tabla Usuario-Tropa
 */
require_once __DIR__ . '/config/database.php';

// Simular entorno local para CLI
if (php_sapi_name() === 'cli') {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

echo "Iniciando migración...\n";

try {
    $db = getConnection();
    
    $sql = "CREATE TABLE IF NOT EXISTS `usuario_tropa` (
      `id_usuario` INT(11) NOT NULL,
      `id_tropa` INT(11) NOT NULL,
      `fecha_asignacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_usuario`, `id_tropa`),
      CONSTRAINT `fk_ut_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE CASCADE,
      CONSTRAINT `fk_ut_tropa` FOREIGN KEY (`id_tropa`) REFERENCES `tropa` (`id_tropa`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $db->exec($sql);
    echo "Tabla 'usuario_tropa' creada o verificada exitosamente.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
