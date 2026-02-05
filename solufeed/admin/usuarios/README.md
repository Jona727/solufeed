# 👥 Módulo de Gestión de Usuarios - Solufeed

## 📋 Descripción

Módulo completo para la administración de usuarios del sistema Solufeed. Permite crear, editar, activar/desactivar usuarios con diferentes roles (ADMIN y CAMPO).

## 🎯 Características

### ✅ Funcionalidades Implementadas

1. **Listado de Usuarios**
   - Vista completa con todos los usuarios del sistema
   - Filtros por tipo (ADMIN/CAMPO) y estado (Activo/Inactivo)
   - Búsqueda por nombre o email
   - Estadísticas en tiempo real (total, por tipo, activos)
   - Diseño responsive con tabla adaptativa

2. **Crear Usuario**
   - Formulario completo con validación
   - Selección visual de tipo de usuario (Admin/Campo)
   - Validación de contraseña con indicador de fortaleza
   - Verificación de email único
   - Confirmación de contraseña
   - Estado inicial (activo/inactivo)

3. **Editar Usuario**
   - Modificación de datos básicos (nombre, email)
   - Cambio de tipo de usuario
   - Cambio opcional de contraseña
   - Activar/desactivar usuario
   - Protección contra modificación del propio estado

4. **Toggle de Estado**
   - Activar/desactivar usuarios con un clic
   - Protección: el admin no puede desactivarse a sí mismo
   - Mensajes de confirmación

## 📁 Estructura de Archivos

```
admin/usuarios/
├── listar.php          # Vista principal con listado y filtros
├── crear.php           # Formulario de creación de usuarios
├── editar.php          # Formulario de edición de usuarios
└── toggle_estado.php   # Script para activar/desactivar usuarios

database/
└── crear_tabla_usuarios.sql  # Script SQL para crear la tabla
```

## 🗄️ Estructura de Base de Datos

### Tabla: `usuario`

```sql
CREATE TABLE `usuario` (
  `id_usuario` INT(11) NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `tipo` ENUM('ADMIN', 'CAMPO') NOT NULL DEFAULT 'CAMPO',
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_modificacion` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_usuario`)
);
```

### Campos:

- **id_usuario**: ID único autoincremental
- **nombre**: Nombre completo del usuario
- **email**: Correo electrónico (único, usado para login)
- **password_hash**: Contraseña hasheada con `password_hash()`
- **tipo**: Rol del usuario (ADMIN o CAMPO)
- **activo**: Estado del usuario (1=activo, 0=inactivo)
- **fecha_creacion**: Fecha de creación del registro
- **fecha_modificacion**: Última modificación (auto-actualizada)

## 🚀 Instalación

### 1. Crear la Tabla en la Base de Datos

Ejecuta el script SQL en tu base de datos:

```bash
# Desde phpMyAdmin: Importa el archivo
database/crear_tabla_usuarios.sql

# O desde línea de comandos:
mysql -u usuario -p nombre_base_datos < database/crear_tabla_usuarios.sql
```

### 2. Usuario Administrador por Defecto

El script crea automáticamente un usuario administrador:

- **Email**: `admin@solufeed.com`
- **Contraseña**: `admin123`

⚠️ **IMPORTANTE**: Cambia esta contraseña inmediatamente después del primer login.

### 3. Verificar Permisos

El módulo está protegido con `verificarAdmin()`, solo usuarios ADMIN pueden acceder.

## 🎨 Diseño y UX

### Estética "Rural-Premium"

- **Colores**: Paleta verde rural con acentos premium
- **Tipografía**: Outfit (Google Fonts)
- **Componentes**:
  - Cards con glassmorphism
  - Badges coloridos para estados y roles
  - Animaciones suaves en hover
  - Formularios con validación visual
  - Indicador de fortaleza de contraseña

### Responsive Design

- Mobile-first approach
- Tabla con scroll horizontal en móviles
- Grid adaptativo para estadísticas
- Formularios optimizados para touch

## 🔒 Seguridad

### Medidas Implementadas

1. **Autenticación**
   - Solo usuarios ADMIN pueden acceder al módulo
   - Verificación con `verificarAdmin()` en cada página

2. **Validación de Datos**
   - Sanitización de inputs (email, nombre)
   - Validación de formato de email
   - Longitud mínima de contraseña (6 caracteres)
   - Confirmación de contraseña

3. **Protección de Contraseñas**
   - Hash con `password_hash()` (bcrypt)
   - Nunca se almacenan contraseñas en texto plano
   - Verificación con `password_verify()`

4. **Prevención de Errores**
   - No se puede desactivar el propio usuario
   - Verificación de email único
   - Manejo de excepciones en base de datos

5. **SQL Injection**
   - Uso de prepared statements en todas las consultas
   - Parámetros bindeados con PDO

## 📊 Funcionalidades Avanzadas

### Filtros Inteligentes

- **Por Tipo**: Muestra solo ADMIN o solo CAMPO
- **Por Estado**: Filtra activos o inactivos
- **Búsqueda**: Busca en nombre y email simultáneamente
- **Combinación**: Los filtros se pueden combinar

### Estadísticas en Tiempo Real

- Total de usuarios
- Total de administradores
- Total de personal de campo
- Total de usuarios activos

### Mensajes de Feedback

- Alertas de éxito (verde)
- Alertas de error (rojo)
- Mensajes persistentes con sesión
- Auto-redirección después de crear

## 🔧 Integración con el Sistema

### Menú de Navegación

El módulo se agregó al sidebar en la sección "Gestión":

```php
<li>
    <a href="<?php echo BASE_URL; ?>/admin/usuarios/listar.php">
        <span class="menu-icono">👥</span>
        <span class="menu-texto">Usuarios</span>
    </a>
</li>
```

### Sistema de Roles

El módulo respeta el sistema de roles existente:

- **ADMIN**: Acceso completo al módulo de usuarios
- **CAMPO**: Sin acceso (redirigido automáticamente)

## 📝 Uso

### Crear un Nuevo Usuario

1. Ir a **Gestión > Usuarios**
2. Clic en **"Nuevo Usuario"**
3. Completar el formulario:
   - Nombre completo
   - Email (será el usuario de login)
   - Tipo (Admin o Campo)
   - Contraseña (mínimo 6 caracteres)
   - Confirmar contraseña
   - Estado inicial (activo/inactivo)
4. Clic en **"Crear Usuario"**

### Editar un Usuario

1. En el listado, clic en el ícono ✏️ del usuario
2. Modificar los datos necesarios
3. **Opcional**: Marcar "Cambiar Contraseña" para establecer una nueva
4. Clic en **"Guardar Cambios"**

### Activar/Desactivar Usuario

1. En el listado, clic en el ícono 🔒 (desactivar) o 🔓 (activar)
2. Confirmar la acción
3. El usuario cambiará de estado inmediatamente

### Buscar y Filtrar

1. Usar la barra de búsqueda para buscar por nombre o email
2. Seleccionar tipo de usuario (Admin/Campo)
3. Seleccionar estado (Activo/Inactivo)
4. Clic en **"Filtrar"**
5. Clic en **"Limpiar"** para resetear filtros

## 🐛 Manejo de Errores

### Mensajes de Error Comunes

- **"Ya existe un usuario con ese email"**: El email debe ser único
- **"Las contraseñas no coinciden"**: Verificar confirmación de contraseña
- **"La contraseña debe tener al menos 6 caracteres"**: Usar contraseña más larga
- **"No puedes cambiar tu propio estado"**: Un admin no puede desactivarse a sí mismo

## 🔄 Compatibilidad

- ✅ Compatible con el sistema de autenticación existente
- ✅ No modifica archivos existentes del proyecto
- ✅ Usa las mismas funciones de seguridad (`verificarAdmin()`)
- ✅ Respeta el diseño "Rural-Premium" del sistema
- ✅ Integrado con el sistema de mensajes de sesión

## 📱 Responsive

El módulo es completamente responsive:

- **Desktop**: Vista completa con tabla expandida
- **Tablet**: Grid adaptativo, tabla con scroll
- **Mobile**: Cards apiladas, formularios optimizados

## 🎯 Próximas Mejoras Sugeridas

1. **Permisos Granulares**: Agregar permisos específicos por módulo
2. **Historial de Actividad**: Log de acciones de cada usuario
3. **Recuperación de Contraseña**: Sistema de reset por email
4. **Foto de Perfil**: Permitir subir avatar
5. **Últimos Accesos**: Mostrar fecha/hora del último login
6. **Exportar Usuarios**: Descargar listado en CSV/Excel
7. **Importar Usuarios**: Carga masiva desde archivo

## 📞 Soporte

Para cualquier consulta o problema con el módulo de usuarios, contactar al equipo de desarrollo.

---

**Versión**: 1.0  
**Fecha**: Enero 2026  
**Autor**: Equipo Solufeed  
**Licencia**: Uso interno
