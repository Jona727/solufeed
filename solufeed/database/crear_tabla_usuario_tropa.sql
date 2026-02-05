-- Tabla intermedia para asignar lotes (tropas) a usuarios
CREATE TABLE IF NOT EXISTS `usuario_tropa` (
  `id_usuario` INT(11) NOT NULL,
  `id_tropa` INT(11) NOT NULL,
  `fecha_asignacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_usuario`, `id_tropa`),
  CONSTRAINT `fk_ut_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE CASCADE,
  CONSTRAINT `fk_ut_tropa` FOREIGN KEY (`id_tropa`) REFERENCES `tropa` (`id_tropa`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
