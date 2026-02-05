/**
 * SOLUFEED - Scripts JavaScript
 * Funciones de interacción básicas
 */

// Esperar a que el DOM esté completamente cargado
document.addEventListener('DOMContentLoaded', function () {

    // Confirmación antes de eliminar
    const botonesEliminar = document.querySelectorAll('.btn-eliminar');
    botonesEliminar.forEach(boton => {
        boton.addEventListener('click', function (e) {
            if (!confirm('¿Estás seguro de que deseas eliminar este elemento?')) {
                e.preventDefault();
            }
        });
    });

    // Auto-ocultar mensajes después de 5 segundos
    const mensajes = document.querySelectorAll('.mensaje');
    mensajes.forEach(mensaje => {
        setTimeout(() => {
            mensaje.style.transition = 'opacity 0.5s';
            mensaje.style.opacity = '0';
            setTimeout(() => {
                mensaje.remove();
            }, 500);
        }, 5000);
    });

    // Mobile Menu Logic
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const menuOverlay = document.getElementById('menuOverlay');

    if (menuToggle && sidebar && menuOverlay) {
        function toggleMenu() {
            sidebar.classList.toggle('open');
            menuOverlay.classList.toggle('active');

            // Cambiar ícono y bloquear scroll
            const isOpen = sidebar.classList.contains('open');
            menuToggle.textContent = isOpen ? '✕' : '☰'; // Cambia el icono pero mantiene el estilo Material Icon si es texto

            if (isOpen) {
                document.body.style.overflow = 'hidden'; // Bloquear scroll
                // Si usamos Material Icons y texto plano, quizas esto rompa el icono si estaba en span. 
                // Revisemos el HTML: <button><span class="material-icon">☰</span></button>
                // Al hacer textContent override, borramos el span.
                // Corrección: Manipular el innerHTML o el span.
                menuToggle.innerHTML = '<span class="material-icon">✕</span>';
            } else {
                document.body.style.overflow = ''; // Restaurar scroll
                menuToggle.innerHTML = '<span class="material-icon">☰</span>';
            }
        }

        function closeMenu() {
            sidebar.classList.remove('open');
            menuOverlay.classList.remove('active');
            document.body.style.overflow = ''; // Restaurar scroll
            menuToggle.innerHTML = '<span class="material-icon">☰</span>';
        }

        menuToggle.addEventListener('click', toggleMenu);
        menuOverlay.addEventListener('click', closeMenu);

        // Cerrar menú con tecla ESC
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) {
                closeMenu();
            }
        });
    }
});

/**
 * Valida que un campo no esté vacío
 */
function validarCampoRequerido(campo) {
    if (campo.value.trim() === '') {
        alert('Este campo es obligatorio');
        campo.focus();
        return false;
    }
    return true;
}

/**
 * Valida que un número sea positivo
 */
function validarNumeroPositivo(campo) {
    const valor = parseFloat(campo.value);
    if (isNaN(valor) || valor <= 0) {
        alert('Debe ingresar un número mayor a 0');
        campo.focus();
        return false;
    }
    return true;
}

/**
 * Formatea un número con separador de miles
 */
function formatearNumero(numero, decimales = 2) {
    return Number(numero).toLocaleString('es-AR', {
        minimumFractionDigits: decimales,
        maximumFractionDigits: decimales
    });
}

/**
 * Calcula el total de un array de inputs
 */
function calcularTotal(inputs) {
    let total = 0;
    inputs.forEach(input => {
        const valor = parseFloat(input.value) || 0;
        total += valor;
    });
    return total;
}

// Responsive tables: add data-labels from <th> to each <td>
document.addEventListener('DOMContentLoaded', () => {
  const tables = document.querySelectorAll('table.responsive-table');
  tables.forEach((table) => {
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => (th.textContent || '').trim());
    table.querySelectorAll('tbody tr').forEach((tr) => {
      Array.from(tr.children).forEach((cell, idx) => {
        if (cell && cell.tagName === 'TD') {
          const label = headers[idx] || '';
          if (label) cell.setAttribute('data-label', label);
        }
      });
    });
  });
});

// CSRF: inyectar token en formularios POST y refrescar periódicamente
async function refreshCsrfToken() {
  const base = window.BASE_URL || '';

  try {
    const res = await fetch(`${base}/admin/api/csrf.php`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    });

    if (!res.ok) return null;
    const data = await res.json();
    const token = data && data.csrf_token ? String(data.csrf_token) : '';
    if (!token) return null;

    window.CSRF_TOKEN = token;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', token);
    document.querySelectorAll('input[name="csrf_token"]').forEach((i) => { i.value = token; });

    return token;
  } catch (e) {
    return null;
  }
}

function ensureCsrfOnPostForms() {
  const token = (window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '').toString();
  if (!token) return;

  document.querySelectorAll('form').forEach((form) => {
    const method = (form.getAttribute('method') || form.method || '').toLowerCase();
    if (method !== 'post') return;

    let input = form.querySelector('input[name="csrf_token"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'csrf_token';
      form.appendChild(input);
    }
    input.value = token;
  });
}

document.addEventListener('DOMContentLoaded', () => {
  // Asegurar token en forms al cargar
  ensureCsrfOnPostForms();

  // Refresco inicial y por reconexión
  if (navigator.onLine) {
    refreshCsrfToken().then(() => ensureCsrfOnPostForms());
  }
  window.addEventListener('online', () => {
    refreshCsrfToken().then(() => ensureCsrfOnPostForms());
  });

  // Refrescar cada 10 minutos para evitar expiración
  setInterval(() => {
    if (navigator.onLine) {
      refreshCsrfToken().then(() => ensureCsrfOnPostForms());
    }
  }, 10 * 60 * 1000);

  // Limpiar cola offline en logout para no mezclar usuarios
  document.querySelectorAll('a.btn-logout').forEach((a) => {
    a.addEventListener('click', async (e) => {
      const href = a.getAttribute('href') || a.href;
      e.preventDefault();

      // Seguridad: limpiar cache runtime de pantallas CAMPO (para no mezclar usuarios en el mismo dispositivo)
      try {
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
          navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_CAMPO_CACHE' });
        }
      } catch (_) {}

      try {
        if (window.OfflineManager && typeof window.OfflineManager.clearAll === 'function') {
          await window.OfflineManager.clearAll();
        }
      } finally {
        window.location.href = href;
      }
    });
  });
});

document.addEventListener('DOMContentLoaded', () => {
  // PWA offline (CAMPO): precargar pantallas clave para que queden disponibles sin conexión
  // Se ejecuta solo una vez por sesión de navegador y solo para usuarios CAMPO.
  try {
    const roleEl = document.querySelector('.user-role');
    const role = roleEl ? String(roleEl.textContent || '').trim().toUpperCase() : '';
    if (role === 'CAMPO' && navigator.onLine && 'serviceWorker' in navigator) {
      if (sessionStorage.getItem('pwa_prefetch_campo') !== '1') {
        sessionStorage.setItem('pwa_prefetch_campo', '1');
        const base = (window.BASE_URL || '').toString();
        const urls = [
          `${base}/admin/campo/index.php`,
          `${base}/admin/pesadas/registrar.php`,
          `${base}/admin/alimentaciones/registrar.php`,
          `${base}/admin/campo/pendientes_offline.php`,
          `${base}/admin/campo/consultar_lotes.php`
        ];

        urls.forEach((u) => {
          fetch(u, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'text/html' }
          }).catch(() => {});
        });

        // Prefetch lote-específico: evita que al seleccionar un lote sin conexión
        // la pantalla quede en Paso 1 por falta de cache (?lote=...).
        // Se limita a una cantidad razonable para no sobrecargar.
        fetch(`${base}/admin/api/mis_lotes.php`, {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' }
        })
          .then(r => r.ok ? r.json() : null)
          .then((json) => {
            if (!json || json.ok !== true || !Array.isArray(json.lotes)) return;

            const lotes = json.lotes
              .map(x => parseInt(x && x.id_tropa, 10))
              .filter(n => Number.isFinite(n) && n > 0);

            const MAX_PREFETCH_LOTES = 60;
            const ids = lotes.slice(0, MAX_PREFETCH_LOTES);

            ids.forEach((id, idx) => {
              // Un pequeño delay escalonado para no disparar todo junto
              const delay = 80 * idx;
              setTimeout(() => {
                const u1 = `${base}/admin/pesadas/registrar.php?lote=${encodeURIComponent(id)}`;
                const u2 = `${base}/admin/alimentaciones/registrar.php?lote=${encodeURIComponent(id)}`;
                fetch(u1, { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'text/html' } }).catch(() => {});
                fetch(u2, { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'text/html' } }).catch(() => {});
              }, delay);
            });
          })
          .catch(() => {});
      }
    }
  } catch (_) {}
});

/**
 * PWA - Botón "Instalar App" en el menú.
 * - Se muestra solo si NO está instalada.
 * - En Chrome/Edge se usa beforeinstallprompt.
 * - En iOS se muestra con instrucción de "Agregar a pantalla de inicio".
 */
(function () {
  let deferredPrompt = null;
  let menuItem = null;
  let separator = null;
  let button = null;

  function isStandalone() {
    try {
      const mq = window.matchMedia && window.matchMedia('(display-mode: standalone)');
      const standaloneMq = !!(mq && mq.matches);
      const standaloneIos = (typeof navigator !== 'undefined' && 'standalone' in navigator) ? !!navigator.standalone : false;
      return standaloneMq || standaloneIos;
    } catch (e) {
      return false;
    }
  }

  function isIos() {
    const ua = navigator.userAgent || '';
    const iOS = /iphone|ipad|ipod/i.test(ua);
    // iPadOS 13+ puede reportar "MacIntel" con touch
    const iPadOS = (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    return iOS || iPadOS;
  }

  function showInstall() {
    if (!menuItem || !separator) return;
    menuItem.style.display = '';
    separator.style.display = '';
  }

  function hideInstall() {
    if (menuItem) menuItem.style.display = 'none';
    if (separator) separator.style.display = 'none';
  }

  // Capturar el prompt (Chrome/Edge)
  window.addEventListener('beforeinstallprompt', (e) => {
    if (isStandalone()) return;
    // Evitar que el navegador muestre su mini-infobar
    e.preventDefault();
    deferredPrompt = e;
    // Si el DOM ya cargó, mostrar la opción
    if (menuItem && separator) showInstall();
  });

  // Cuando se instala, ocultar opción
  window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    hideInstall();
    if (typeof window.showToast === 'function') {
      window.showToast('✅ App instalada', 'success');
    }
  });

  document.addEventListener('DOMContentLoaded', () => {
    menuItem = document.getElementById('pwaInstallMenuItem');
    separator = document.getElementById('pwaInstallSeparator');
    button = document.getElementById('pwaInstallBtn');
    if (!menuItem || !separator || !button) return;

    // Si ya está instalada, no mostrar
    if (isStandalone()) {
      hideInstall();
      return;
    }
    // Mostrar en móvil aunque el evento beforeinstallprompt no haya disparado todavía.
    // (en muchos Android esto depende de criterios de instalabilidad; igual damos la opción y mostramos instrucciones)
    const isCoarse = (window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    const isMobile = isCoarse || /android|iphone|ipad|ipod/i.test(navigator.userAgent || '');

    if (deferredPrompt || isIos() || isMobile) {
      showInstall();
    } else {
      hideInstall();
    }

    button.addEventListener('click', async (ev) => {
      ev.preventDefault();

      if (isStandalone()) {
        hideInstall();
        return;
      }

      // iOS: no existe beforeinstallprompt
      if (!deferredPrompt) {
        if (isIos()) {
          if (typeof window.showToast === 'function') {
            window.showToast('📲 Para instalar en iPhone/iPad: Compartir → “Agregar a pantalla de inicio”.', 'info');
          } else {
            alert('Para instalar en iPhone/iPad: Compartir → “Agregar a pantalla de inicio”.');
          }
        } else {
          if (typeof window.showToast === 'function') {
            window.showToast('Abrí el menú del navegador (⋮) y elegí “Instalar app” / “Agregar a pantalla de inicio”.', 'info');
          }
        }
        return;
      }

      try {
        deferredPrompt.prompt();
        const choice = await deferredPrompt.userChoice;
        if (choice && choice.outcome === 'accepted') {
          if (typeof window.showToast === 'function') window.showToast('Instalación iniciada…', 'success');
        } else {
          if (typeof window.showToast === 'function') window.showToast('Instalación cancelada.', 'info');
        }
      } catch (e) {
        // Silencioso
      } finally {
        deferredPrompt = null;
        hideInstall();
      }
    });
  });
})();



// Selects buscables: agrega un input para filtrar opciones (sin librerías)
document.addEventListener('DOMContentLoaded', () => {
  const selects = document.querySelectorAll('select[data-searchable="true"]');
  selects.forEach((select) => {
    if (select.dataset.searchInit === '1') return;
    select.dataset.searchInit = '1';

    const parent = select.parentElement;
    if (!parent) return;

    // Evitar duplicar si ya existe un input previo
    const prev = select.previousElementSibling;
    if (prev && prev.tagName === 'INPUT' && prev.dataset.selectSearch === '1') return;

    const input = document.createElement('input');
    input.type = 'text';
    input.autocomplete = 'off';
    input.dataset.selectSearch = '1';
    input.placeholder = select.getAttribute('data-search-placeholder') || 'Buscar...';
    input.setAttribute('aria-label', input.placeholder);
    input.style.marginBottom = '0.5rem';

    // Insertar arriba del select
    parent.insertBefore(input, select);

    const options = Array.from(select.options).map((opt) => ({
      opt,
      text: (opt.textContent || '').toLowerCase(),
      isPlaceholder: (opt.value || '') === ''
    }));

    const applyFilter = () => {
      const q = (input.value || '').trim().toLowerCase();
      options.forEach(({ opt, text, isPlaceholder }) => {
        if (isPlaceholder) {
          opt.hidden = false;
          return;
        }
        opt.hidden = q ? !text.includes(q) : false;
      });
    };

    input.addEventListener('input', applyFilter);

    // Si viene preseleccionado, no filtramos; solo dejamos listo el buscador
  });
});
