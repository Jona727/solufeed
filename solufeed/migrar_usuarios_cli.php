<?php
// Seguridad: este script solo debe ejecutarse por CLI (no por navegador)
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Script de Migración CLI - Módulo de Usuarios
 * Ejecutar desde línea de comandos: php migrar_usuarios_cli.php
 */

// Simular entorno local para CLI
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Forzar configuración local explícita para este script de migración
if (!defined('DB_HOST')) {
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'solufeed_el_choli');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('BASE_URL', '/solufeed');
}

require_once __DIR__ . '/config/database.php';

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  🚀 MIGRACIÓN - MÓDULO DE USUARIOS - SOLUFEED            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

try {
    $db = getConnection();
    
    echo "📡 Paso 1: Conexión a Base de Datos\n";
    echo "   ✓ Conectado a: " . DB_NAME . "\n\n";
    
    // Verificar si la tabla ya existe
    $stmt = $db->query("SHOW TABLES LIKE 'usuario'");
    $tabla_existe = $stmt->rowCount() > 0;
    
    if ($tabla_existe) {
        echo "ℹ️  Paso 2: Verificación de Tabla\n";
        echo "   ⚠ La tabla 'usuario' ya existe\n";
        echo "   → No se creará nuevamente\n\n";
        
        // Mostrar estadísticas
        $stmt = $db->query("SELECT COUNT(*) as total FROM usuario");
        $total = $stmt->fetch()['total'];
        
        echo "📊 Usuarios Existentes: $total\n\n";
        
    } else {
        echo "🔨 Paso 2: Creando Tabla 'usuario'\n";
        
        // Crear tabla
        $sql_create = "CREATE TABLE `usuario` (
          `id_usuario` INT(11) NOT NULL AUTO_INCREMENT,
          `nombre` VARCHAR(100) NOT NULL COMMENT 'Nombre completo del usuario',
          `email` VARCHAR(100) NOT NULL COMMENT 'Email único para login',
          `password_hash` VARCHAR(255) NOT NULL COMMENT 'Contraseña hasheada con bcrypt',
          `tipo` ENUM('ADMIN', 'CAMPO') NOT NULL DEFAULT 'CAMPO' COMMENT 'Rol del usuario',
          `activo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Activo, 0=Inactivo',
          `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `fecha_modificacion` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id_usuario`),
          UNIQUE KEY `email_unique` (`email`),
          KEY `idx_email` (`email`),
          KEY `idx_tipo` (`tipo`),
          KEY `idx_activo` (`activo`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Tabla de usuarios del sistema Solufeed'";
        
        $db->exec($sql_create);
        
        echo "   ✓ Tabla creada exitosamente\n\n";
        
        // Insertar usuario administrador
        echo "👤 Paso 3: Creando Usuario Administrador\n";
        
        $sql_insert = "INSERT INTO `usuario` (`nombre`, `email`, `password_hash`, `tipo`, `activo`)
                       VALUES (?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($sql_insert);
        $stmt->execute([
            'Administrador Principal',
            'admin@solufeed.com',
            '$2y$10$IYM4.BRz3woZQG9lsVNQOOpBT8YNWM/Js77twrjzNkgJRSdJR3hj6', // admin123
            'ADMIN',
            1
        ]);
        
        echo "   ✓ Usuario administrador creado\n\n";
    }
    
    // Mostrar estadísticas finales
    $stmt = $db->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN tipo = 'ADMIN' THEN 1 ELSE 0 END) as total_admin,
        SUM(CASE WHEN tipo = 'CAMPO' THEN 1 ELSE 0 END) as total_campo,
        SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as total_activos
        FROM usuario");
    $stats = $stmt->fetch();
    
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  📊 ESTADÍSTICAS FINALES                                  ║\n";
    echo "╠════════════════════════════════════════════════════════════╣\n";
    echo "║  Total de usuarios:       " . str_pad($stats['total'], 28) . "║\n";
    echo "║  Administradores:         " . str_pad($stats['total_admin'], 28) . "║\n";
    echo "║  Personal de Campo:       " . str_pad($stats['total_campo'], 28) . "║\n";
    echo "║  Usuarios Activos:        " . str_pad($stats['total_activos'], 28) . "║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    
    // Mostrar credenciales
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  🔑 CREDENCIALES DE ACCESO                                ║\n";
    echo "╠════════════════════════════════════════════════════════════╣\n";
    echo "║  Email:     admin@solufeed.com                            ║\n";
    echo "║  Contraseña: admin123                                     ║\n";
    echo "╠════════════════════════════════════════════════════════════╣\n";
    echo "║  ⚠️  IMPORTANTE: Cambia esta contraseña después del       ║\n";
    echo "║     primer login por seguridad.                           ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    
    echo "✅ MIGRACIÓN COMPLETADA EXITOSAMENTE\n\n";
    echo "Próximos pasos:\n";
    echo "1. Acceder a: http://localhost/solufeed/admin/login.php\n";
    echo "2. Iniciar sesión con las credenciales mostradas arriba\n";
    echo "3. Ir a: Gestión > Usuarios\n";
    echo "4. Cambiar la contraseña del administrador\n";
    echo "5. Crear usuarios para tu equipo\n\n";
    
} catch (Exception $e) {
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  ❌ ERROR EN LA MIGRACIÓN                                 ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    echo "Detalles técnicos:\n";
    echo $e->getTraceAsString() . "\n\n";
    exit(1);
}
?>
