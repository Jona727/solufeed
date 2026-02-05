-- =====================================================
-- MIGRACIÓN RÁPIDA: Módulo de Usuarios - Solufeed
-- =====================================================
-- Este script configura todo lo necesario para el módulo
-- de gestión de usuarios en una sola ejecución
-- =====================================================

-- 1. CREAR TABLA DE USUARIOS
-- =====================================================

CREATE TABLE IF NOT EXISTS `usuario` (
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
COMMENT='Tabla de usuarios del sistema Solufeed';

-- =====================================================
-- 2. INSERTAR USUARIO ADMINISTRADOR POR DEFECTO
-- =====================================================
-- Solo se inserta si NO existe ningún usuario en la tabla
-- 
-- Credenciales:
-- Email: admin@solufeed.com
-- Contraseña: admin123
-- 
-- ⚠️ IMPORTANTE: Cambiar esta contraseña después del primer login
-- =====================================================

INSERT INTO `usuario` (`nombre`, `email`, `password_hash`, `tipo`, `activo`)
SELECT 
    'Administrador Principal',
    'admin@solufeed.com',
    '$2y$10$IYM4.BRz3woZQG9lsVNQOOpBT8YNWM/Js77twrjzNkgJRSdJR3hj6',
    'ADMIN',
    1
WHERE NOT EXISTS (
    SELECT 1 FROM `usuario` LIMIT 1
);

-- =====================================================
-- 3. INSERTAR USUARIOS DE EJEMPLO (OPCIONAL)
-- =====================================================
-- Descomentar las siguientes líneas si deseas crear
-- usuarios de ejemplo para testing
-- =====================================================

/*
-- Usuario de Campo de Ejemplo
INSERT INTO `usuario` (`nombre`, `email`, `password_hash`, `tipo`, `activo`)
VALUES (
    'Juan Operario',
    'juan@campo.com',
    '$2y$10$IYM4.BRz3woZQG9lsVNQOOpBT8YNWM/Js77twrjzNkgJRSdJR3hj6', -- Contraseña: admin123
    'CAMPO',
    1
);

-- Administrador Secundario de Ejemplo
INSERT INTO `usuario` (`nombre`, `email`, `password_hash`, `tipo`, `activo`)
VALUES (
    'María Administradora',
    'maria@admin.com',
    '$2y$10$IYM4.BRz3woZQG9lsVNQOOpBT8YNWM/Js77twrjzNkgJRSdJR3hj6', -- Contraseña: admin123
    'ADMIN',
    1
);
*/

-- =====================================================
-- 4. VERIFICACIÓN DE LA INSTALACIÓN
-- =====================================================

-- Mostrar información de la tabla creada
SELECT 
    'Tabla usuario creada exitosamente' AS mensaje,
    COUNT(*) AS total_usuarios,
    SUM(CASE WHEN tipo = 'ADMIN' THEN 1 ELSE 0 END) AS total_admin,
    SUM(CASE WHEN tipo = 'CAMPO' THEN 1 ELSE 0 END) AS total_campo,
    SUM(CASE WHEN activo = 1 THEN 1 ELSE 0 END) AS total_activos
FROM usuario;

-- Mostrar usuarios creados
SELECT 
    id_usuario,
    nombre,
    email,
    tipo,
    CASE WHEN activo = 1 THEN 'Activo' ELSE 'Inactivo' END AS estado,
    fecha_creacion
FROM usuario
ORDER BY fecha_creacion DESC;

-- =====================================================
-- 5. INFORMACIÓN IMPORTANTE
-- =====================================================

/*
✅ MIGRACIÓN COMPLETADA

📋 Próximos Pasos:

1. Acceder al sistema con las credenciales por defecto:
   - Email: admin@solufeed.com
   - Contraseña: admin123

2. Ir a Gestión > Usuarios

3. Cambiar la contraseña del administrador:
   - Editar el usuario "Administrador Principal"
   - Marcar "Cambiar Contraseña"
   - Establecer una contraseña segura

4. Crear usuarios para tu equipo

5. Asignar roles apropiados (ADMIN o CAMPO)

🔒 Seguridad:
- Las contraseñas están hasheadas con bcrypt
- Solo usuarios ADMIN pueden gestionar usuarios
- Los usuarios inactivos no pueden iniciar sesión

📚 Documentación:
- Ver: admin/usuarios/README.md
- Ver: docs/modulo_usuarios_implementacion.md

🎯 Características:
- Crear, editar, activar/desactivar usuarios
- Filtros por tipo y estado
- Búsqueda por nombre o email
- Estadísticas en tiempo real
- Diseño responsive

¡El módulo de usuarios está listo para usar! 🎉
*/
