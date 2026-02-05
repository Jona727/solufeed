<?php
// Seguridad: este script solo debe ejecutarse por CLI (no por navegador)
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Script de Migración - Módulo de Usuarios
 * Ejecuta la creación de la tabla usuario
 */

require_once __DIR__ . '/config/database.php';

echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Migración - Módulo de Usuarios</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #2c5530 0%, #1e3a21 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            color: #2c5530;
            margin-bottom: 10px;
            font-size: 1.8rem;
        }
        
        .subtitle {
            color: #64748b;
            margin-bottom: 30px;
        }
        
        .step {
            background: #f8fafc;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #e2e8f0;
        }
        
        .step.success {
            border-left-color: #22c55e;
            background: #f0fdf4;
        }
        
        .step.error {
            border-left-color: #ef4444;
            background: #fef2f2;
        }
        
        .step.info {
            border-left-color: #3b82f6;
            background: #eff6ff;
        }
        
        .step-title {
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .step-content {
            color: #475569;
            font-size: 0.9rem;
        }
        
        .credentials {
            background: #fef3c7;
            border: 2px solid #fbbf24;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }
        
        .credentials h3 {
            color: #92400e;
            margin-bottom: 10px;
        }
        
        .credentials code {
            background: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2c5530;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            background: #1e3a21;
            transform: translateY(-2px);
        }
        
        pre {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 0.85rem;
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🚀 Migración - Módulo de Usuarios</h1>
        <p class='subtitle'>Instalación de la tabla de usuarios</p>
";

try {
    $db = getConnection();
    
    echo "<div class='step info'>
            <div class='step-title'>📡 Paso 1: Conexión a Base de Datos</div>
            <div class='step-content'>Conectado a: <strong>" . DB_NAME . "</strong></div>
          </div>";
    
    // Verificar si la tabla ya existe
    $stmt = $db->query("SHOW TABLES LIKE 'usuario'");
    $tabla_existe = $stmt->rowCount() > 0;
    
    if ($tabla_existe) {
        echo "<div class='step info'>
                <div class='step-title'>ℹ️ Tabla Existente</div>
                <div class='step-content'>La tabla 'usuario' ya existe. No se creará nuevamente.</div>
              </div>";
        
        // Mostrar estadísticas
        $stmt = $db->query("SELECT COUNT(*) as total FROM usuario");
        $total = $stmt->fetch()['total'];
        
        echo "<div class='step success'>
                <div class='step-title'>✅ Usuarios Existentes</div>
                <div class='step-content'>Total de usuarios en la base de datos: <strong>$total</strong></div>
              </div>";
    } else {
        echo "<div class='step info'>
                <div class='step-title'>🔨 Paso 2: Creando Tabla</div>
                <div class='step-content'>Ejecutando script de creación...</div>
              </div>";
        
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
        
        echo "<div class='step success'>
                <div class='step-title'>✅ Tabla Creada</div>
                <div class='step-content'>La tabla 'usuario' se creó exitosamente</div>
              </div>";
        
        // Insertar usuario administrador
        echo "<div class='step info'>
                <div class='step-title'>👤 Paso 3: Creando Usuario Administrador</div>
                <div class='step-content'>Insertando usuario por defecto...</div>
              </div>";
        
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
        
        echo "<div class='step success'>
                <div class='step-title'>✅ Usuario Creado</div>
                <div class='step-content'>Usuario administrador creado exitosamente</div>
              </div>";
    }
    
    // Mostrar estadísticas finales
    $stmt = $db->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN tipo = 'ADMIN' THEN 1 ELSE 0 END) as total_admin,
        SUM(CASE WHEN tipo = 'CAMPO' THEN 1 ELSE 0 END) as total_campo,
        SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) as total_activos
        FROM usuario");
    $stats = $stmt->fetch();
    
    echo "<div class='step success'>
            <div class='step-title'>📊 Estadísticas Finales</div>
            <div class='step-content'>
                <strong>Total:</strong> {$stats['total']} usuarios<br>
                <strong>Administradores:</strong> {$stats['total_admin']}<br>
                <strong>Personal de Campo:</strong> {$stats['total_campo']}<br>
                <strong>Activos:</strong> {$stats['total_activos']}
            </div>
          </div>";
    
    // Mostrar credenciales
    echo "<div class='credentials'>
            <h3>🔑 Credenciales de Acceso</h3>
            <p><strong>Email:</strong> <code>admin@solufeed.com</code></p>
            <p><strong>Contraseña:</strong> <code>admin123</code></p>
            <p style='margin-top: 10px; color: #92400e; font-size: 0.9rem;'>
                ⚠️ <strong>IMPORTANTE:</strong> Cambia esta contraseña inmediatamente después del primer login.
            </p>
          </div>";
    
    echo "<a href='/solufeed/admin/usuarios/listar.php' class='btn'>👥 Ir a Gestión de Usuarios</a>";
    echo "<a href='/solufeed/admin/login.php' class='btn' style='background: #3b82f6; margin-left: 10px;'>🔐 Ir al Login</a>";
    
} catch (Exception $e) {
    echo "<div class='step error'>
            <div class='step-title'>❌ Error</div>
            <div class='step-content'>{$e->getMessage()}</div>
          </div>";
    
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "
    </div>
</body>
</html>";
?>
