<?php
// Seguridad: este script solo debe ejecutarse por CLI (no por navegador)
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once 'config/env.php';
$page_title = "Diagnóstico PWA";
include 'includes/header.php';
?>

<div class="card" style="max-width: 600px; margin: 20px auto; padding: 20px;">
    <h2 style="color: var(--accent-color); margin-bottom: 20px;">🐮 Diagnóstico de PWA Solufeed</h2>
    
    <div id="pwa-status">
        <div class="status-item" id="status-https" style="margin-bottom: 15px; padding: 10px; border-radius: 8px; background: #f8f9fa;">
            <b>🔐 Protocolo Seguro (HTTPS):</b> <span class="badge">Cargando...</span>
        </div>
        
        <div class="status-item" id="status-manifest" style="margin-bottom: 15px; padding: 10px; border-radius: 8px; background: #f8f9fa;">
            <b>📄 Manifiesto JSON:</b> <span class="badge">Cargando...</span>
        </div>
        
        <div class="status-item" id="status-sw" style="margin-bottom: 15px; padding: 10px; border-radius: 8px; background: #f8f9fa;">
            <b>⚙️ Service Worker:</b> <span class="badge">Cargando...</span>
        </div>

        <div class="status-item" id="status-install" style="margin-bottom: 15px; padding: 10px; border-radius: 8px; background: #f8d7da; color: #721c24;">
            <b>📲 Instalabilidad:</b> <span class="badge" style="padding: 2px 8px; border-radius: 4px; background: #f8d7da;">SIN DETECTAR</span>
            <p style="font-size: 0.8em; margin-top: 5px;">Si esta línea no se pone verde, el navegador no considera la web una PWA.</p>
        </div>
    </div>

    <button id="btn-install" class="btn btn-primary" style="margin-top: 10px; width: 100%; display: none; background: #28a745; border: none; padding: 10px; color: white;">📥 INSTALAR SOLUFEED</button>

    <div id="debug-info" style="margin-top: 20px; font-family: monospace; font-size: 0.9em; color: #666; background: #eee; padding: 10px; border-radius: 5px; max-height: 200px; overflow-y: auto;">
        <b>Consola de depuración:</b><br>
    </div>

    <button onclick="location.reload()" class="btn btn-primary" style="margin-top: 20px; width: 100%;">🔄 Re-escanea</button>
</div>

<script>
let deferredPrompt;
const btnInstall = document.getElementById('btn-install');

function log(msg, color = 'black') {
    const debug = document.getElementById('debug-info');
    debug.innerHTML += `<span style="color:${color}">${new Date().toLocaleTimeString()}: ${msg}</span><br>`;
    debug.scrollTop = debug.scrollHeight;
}

function updateStatus(id, text, type) {
    const el = document.querySelector(`#${id} .badge`);
    const container = document.getElementById(id);
    el.innerHTML = text;
    container.style.background = (type === 'success') ? '#d4edda' : '#f8d7da';
    container.style.color = (type === 'success') ? '#155724' : '#721c24';
    el.style.background = (type === 'success') ? '#c3e6cb' : '#f5c6cb';
}

// Escuchar el evento de instalación
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    updateStatus('status-install', '¡LISTO!', 'success');
    btnInstall.style.display = 'block';
    log('🚀 ¡Evento de instalación capturado! Pulsa el botón verde.', 'green');
});

// Lógica del botón de instalar
btnInstall.addEventListener('click', async () => {
    if (deferredPrompt) {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        log(`Resultado de la instalación: ${outcome}`);
        deferredPrompt = null;
        btnInstall.style.display = 'none';
    }
});

// 1. Check HTTPS
if (location.protocol === 'https:') {
    updateStatus('status-https', 'OK', 'success');
    log('✅ HTTPS activo');
} else {
    updateStatus('status-https', 'ERROR', 'danger');
    log('❌ NO HTTPS detectado', 'red');
}

// 2. Check Manifest
const manifestUrl = document.querySelector('link[rel="manifest"]').href;
log('Buscando manifiesto en: ' + manifestUrl);
fetch(manifestUrl)
    .then(r => r.json())
    .then(data => {
        updateStatus('status-manifest', 'OK', 'success');
        log('✅ Manifiesto leído correctamente. Name: ' + data.name);
    })
    .catch(e => {
        updateStatus('status-manifest', 'ERROR', 'danger');
        log('❌ Error al leer manifiesto: ' + e.message, 'red');
    });

// 3. Check Service Worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistration().then(reg => {
        if (reg) {
            updateStatus('status-sw', 'OK', 'success');
            log('✅ Service Worker activo en este dominio.');
        } else {
            updateStatus('status-sw', 'NO', 'danger');
            log('⚠️ SW no registrado todavía.');
        }
    });

    // Forzar registro
    navigator.serviceWorker.register('/sw.js').then(reg => {
        log('Registro de SW enviado...');
    }).catch(err => log('Error registro SW: ' + err, 'red'));
} else {
    log('❌ Navegador sin soporte para PWA', 'red');
}
</script>


<?php include 'includes/footer.php'; ?>
