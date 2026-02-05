# Correcciones aplicadas (2026-01-30)

Este archivo resume los cambios realizados para mejorar **seguridad** y **funcionalidad** sin alterar el flujo del sistema.

## 1) CSRF en formularios POST
Se agregó el campo oculto `csrf_token` (via `<?php echo csrf_input(); ?>`) en formularios que validaban con `csrf_verify_post()` pero no enviaban token.

Archivos ajustados:
- `admin/dietas/crear.php`
- `admin/dietas/editar.php`
- `admin/establecimientos/crear.php`
- `admin/establecimientos/editar.php`
- `admin/establecimientos/gestionar_lotes.php`
- `admin/insumos/editar.php`
- `admin/lotes/crear.php`
- `admin/lotes/editar.php`
- `admin/usuarios/asignar_lotes.php`
- `admin/usuarios/crear.php`
- `admin/usuarios/editar.php`

## 2) Normalización de parámetros (IDs por GET)
Se normalizaron IDs recibidos por GET a enteros para evitar comportamientos inesperados:
- `admin/establecimientos/editar.php`
- `admin/usuarios/asignar_lotes.php`
- `admin/usuarios/editar.php`

## 3) Asignación de lotes: transacción y saneo
En `admin/usuarios/asignar_lotes.php`:
- Se envolvió el “borrar + insertar asignaciones” dentro de una **transacción** (commit/rollback).
- Se castean los IDs de lotes a entero y se ignoran valores inválidos.
- Se corrigió el tipo de alerta en caso de CSRF para que coincida con las clases existentes (`error`).

## 4) Filtros de usuarios: whitelist
En `admin/usuarios/listar.php`:
- Se agregaron whitelists para `tipo` y `estado` para evitar valores inesperados.
- Se normalizó `busqueda` con `trim()`.

## 5) Verificación rápida
- Se corrió `php -l` sobre todo el proyecto: **sin errores de sintaxis**.
