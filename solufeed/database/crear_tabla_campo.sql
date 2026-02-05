-- Tabla de Establecimientos (Campos)
CREATE TABLE IF NOT EXISTS `campo` (
  `id_campo` INT(11) NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL COMMENT 'Nombre del establecimiento (ej: Campo Norte)',
  `ubicacion` VARCHAR(255) NULL COMMENT 'Ubicación física o coordenadas',
  `activo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Activo, 0=Inactivo',
  `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_campo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Establecimientos físicos';

-- Insertar campos por defecto si está vacía
INSERT INTO `campo` (`nombre`, `ubicacion`) 
SELECT 'Campo Principal', 'Central' 
WHERE NOT EXISTS (SELECT 1 FROM `campo`);
