<?php
require_once __DIR__ . '/functions.php';
iniciarSesion();
register_error_handlers();
send_security_headers();
$__csrf_token = csrf_token();
flash_migrate_legacy();
$__flash_messages = flash_pull_all();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($__csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="base-url" content="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>">
    <title><?php echo isset($page_title) ? $page_title . ' - ' : ''; ?>Solufeed</title>
    <!-- Tipografía: Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS Principal -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/main.css">
    
    <!-- PWA -->
<link rel="manifest" href="<?php echo (BASE_URL === '' ? '' : BASE_URL) . '/manifest.json'; ?>">


    <meta name="theme-color" content="#2c5530">
    <link rel="apple-touch-icon" href="<?php echo (BASE_URL === '' ? '' : BASE_URL) . '/assets/img/icon-192.png'; ?>">

    <script>
        // Variables globales (para fetch y CSRF)
        window.BASE_URL = <?php echo json_encode(BASE_URL); ?>;
        window.CSRF_TOKEN = <?php echo json_encode($__csrf_token); ?>;

        function showToast(message, type = 'success') {
            let container = document.getElementById('toastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toastContainer';
                container.className = 'toast-container';
                document.body.appendChild(container);
            }

            const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            const iconEl = document.createElement('span');
            iconEl.className = 'toast-icon';
            iconEl.textContent = icons[type] || icons.info;

            const msgEl = document.createElement('span');
            msgEl.className = 'toast-message';
            // Evita XSS: siempre texto plano
            msgEl.textContent = String(message ?? '');
            // Si hay saltos de línea en el mensaje, mostrarlos bien
            msgEl.style.whiteSpace = 'pre-line';

            toast.appendChild(iconEl);
            toast.appendChild(msgEl);

            container.appendChild(toast);
            setTimeout(() => toast.classList.add('show'), 10);

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 400);
            }, 4000);
        }
    
        // Flash messages desde PHP
        window.__FLASH_MESSAGES = <?php echo json_encode($__flash_messages, JSON_UNESCAPED_UNICODE); ?>;
        document.addEventListener('DOMContentLoaded', () => {
            try {
                const msgs = Array.isArray(window.__FLASH_MESSAGES) ? window.__FLASH_MESSAGES : [];
                msgs.forEach((m) => {
                    if (m && m.message) showToast(String(m.message), m.type || 'info');
                });
            } catch (e) {}
        });
    </script>

    <!-- Chart.js para gráficos -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Offline Manager (cola offline + sync) -->
    <script src="<?php echo BASE_URL; ?>/assets/js/offline_manager.js"></script>

    <!-- Registro del Service Worker (PWA) -->
    <script>
    (function() {
        if (!('serviceWorker' in navigator)) return;

        window.addEventListener('load', () => {
            const swPath = <?php echo json_encode((BASE_URL === '' ? '' : BASE_URL) . '/sw.js'); ?>;
            navigator.serviceWorker.register(swPath)
                .then(reg => {
                    console.log('✅ [PWA] Service Worker registrado. Scope:', reg.scope);

                    reg.onupdatefound = () => {
                        const w = reg.installing;
                        if (!w) return;
                        w.onstatechange = () => {
                            if (w.state === 'installed' && navigator.serviceWorker.controller) {
                                console.log('🔄 [PWA] Nueva versión disponible. Recargue la página.');
                            }
                        };
                    };
                })
                .catch(err => console.error('❌ [PWA] Error al registrar SW:', err));
        });
    })();
    </script>
</head>
<body>
    <!-- Mobile Menu Overlay -->
    <div class="menu-overlay" id="menuOverlay"></div>

    <!-- Mobile Top Bar (Visible only on mobile) -->
    <div class="mobile-top-bar">
        <button id="menuToggle" class="menu-toggle">
            <span class="material-icon">☰</span>
        </button>
        <div class="mobile-brand">Solufeed 🐮</div>
    </div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">🐮</div>
            <h1 class="sidebar-title">Solufeed</h1>
            <p class="sidebar-subtitle">Sistema de Gestión</p>
        </div>

        <nav class="sidebar-menu">
            <ul>
                <?php if (!isset($_SESSION['tipo']) || $_SESSION['tipo'] === 'ADMIN'): ?>
                    <!-- MENÚ ADMINISTRADOR -->
                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/dashboard.php">
                            <span class="menu-icono">🏠</span>
                            <span class="menu-texto">Dashboard</span>
                        </a>
                    </li>

                    <li class="menu-separador"></li>
                    <li class="menu-titulo">Configuración</li>

                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/insumos/listar.php">
                            <span class="menu-icono">🌾</span>
                            <span class="menu-texto">Insumos</span>
                        </a>
                    </li>

                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/dietas/listar.php">
                            <span class="menu-icono">📋</span>
                            <span class="menu-texto">Dietas</span>
                        </a>
                    </li>

                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/establecimientos/listar.php">
                            <span class="menu-icono">🏭</span>
                            <span class="menu-texto">Establecimientos</span>
                        </a>
                    </li>

                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/lotes/listar.php">
                            <span class="menu-icono">🐮</span>
                            <span class="menu-texto">Lotes</span>
                        </a>
                    </li>

                    <li class="menu-separador"></li>
                    <li class="menu-titulo">Gestión</li>

                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/usuarios/listar.php">
                            <span class="menu-icono">👥</span>
                            <span class="menu-texto">Usuarios</span>
                        </a>
                    </li>

                    <li>
                        <a href="<?php echo BASE_URL; ?>/admin/reportes/consumo.php">
                            <span class="menu-icono">📈</span>
                            <span class="menu-texto">Reportes</span>
                        </a>
                    </li>
                    
                <?php endif; ?>

                <!-- MENÚ ESPECÍFICO CAMPO -->
                <?php if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'CAMPO'): ?>
                <li>
                    <a href="<?php echo BASE_URL; ?>/admin/campo/index.php">
                        <span class="menu-icono">👷</span>
                        <span class="menu-texto">Hub de Campo</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>/admin/campo/consultar_lotes.php">
                        <span class="menu-icono">🐮</span>
                        <span class="menu-texto">Consultar Lotes</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>/admin/campo/pendientes_offline.php">
                        <span class="menu-icono">📡</span>
                        <span class="menu-texto">Pendientes Offline</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>/admin/alimentaciones/registrar.php">
                        <span class="menu-icono">🍽️</span>
                        <span class="menu-texto">Registrar Alimentación</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>/admin/pesadas/registrar.php">
                        <span class="menu-icono">⚖️</span>
                        <span class="menu-texto">Registrar Pesada</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>/admin/campo/ver_dieta.php">
                        <span class="menu-icono">🥣</span>
                        <span class="menu-texto">Ver Dieta</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>/admin/campo/historial.php">
                        <span class="menu-icono">📚</span>
                        <span class="menu-texto">Historial</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>/admin/campo/historial_dia.php">
                        <span class="menu-icono">📅</span>
                        <span class="menu-texto">Historial del Día</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- PWA: opción de instalación (se muestra/oculta por JS) -->
                <li id="pwaInstallSeparator" class="menu-separador" style="display:none;"></li>
                <li id="pwaInstallMenuItem" style="display:none;">
                    <a href="#" id="pwaInstallBtn">
                        <span class="menu-icono">⬇️</span>
                        <span class="menu-texto">Instalar App</span>
                    </a>
                </li>
            </ul>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="user-details-group">
                    <div class="user-avatar">👤</div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['nombre'] ?? 'Usuario'); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($_SESSION['tipo'] ?? 'Invitado'); ?></div>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>/admin/logout.php" class="btn-logout">
                    <span class="logout-icon">⏻</span> Cerrar Sesión
                </a>
            </div>
        </div>
    </aside>

    <!-- MAIN WRAPPER -->
    <div class="main-wrapper">
        <main class="content">
