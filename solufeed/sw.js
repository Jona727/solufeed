/**
 * SOLUFEED - Service Worker (v4.2)
 *
 * Enfoque seguro + offline útil para CAMPO:
 * - Precaching SOLO estáticos (CSS/JS/IMG/manifest) y offline.html
 * - NO precachea páginas PHP autenticadas.
 * - Runtime-cache (solo) de pantallas del MÓDULO CAMPO para poder operar sin conexión
 *   (pesadas/alimentaciones/pendientes/hub). Se limpia en logout mediante postMessage.
 */

const STATIC_CACHE = 'solufeed-static-v4.2';
const CAMPO_PAGES_CACHE = 'solufeed-campo-pages-v1';

// Precaching (rutas relativas al scope del SW)
const PRECACHE_URLS = [
  './offline.html',
  './manifest.json',
  './assets/css/main.css',
  './assets/js/scripts.js',
  './assets/js/offline_manager.js',
  './assets/img/icon-144.png',
  './assets/img/icon-192.png',
  './assets/img/icon-512.png',
  './assets/img/icon-512-maskable.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_URLS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(
      keys.map((key) => {
        // Borra caches estáticos viejos; el cache CAMPO se limpia en logout (postMessage)
        if (key.startsWith('solufeed-static-') && key !== STATIC_CACHE) {
          return caches.delete(key);
        }
        return null;
      })
    );
    await self.clients.claim();
  })());
});

// Permite limpiar cache de pantallas CAMPO desde el cliente (logout o acción manual)
self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data && data.type === 'CLEAR_CAMPO_CACHE') {
    event.waitUntil(caches.delete(CAMPO_PAGES_CACHE));
  }
});

function isStaticAsset(url) {
  return (
    url.pathname.includes('/assets/') ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.png') ||
    url.pathname.endsWith('.jpg') ||
    url.pathname.endsWith('.jpeg') ||
    url.pathname.endsWith('.svg') ||
    url.pathname.endsWith('.ico') ||
    url.pathname.endsWith('.woff') ||
    url.pathname.endsWith('.woff2') ||
    url.pathname.endsWith('.ttf') ||
    // manifest / json
    url.pathname.endsWith('.json')
  );
}

function isCampoHtmlPage(url) {
  const p = url.pathname || '';
  // Solo pantallas del operario/campo (HTML GET)
  return (
    p.includes('/admin/campo/') ||
    p.endsWith('/admin/pesadas/registrar.php') ||
    p.endsWith('/admin/alimentaciones/registrar.php')
  );
}

async function networkFirst(req, fallbackResponse) {
  try {
    return await fetch(req);
  } catch (e) {
    return fallbackResponse;
  }
}

async function campoNavigate(req) {
  const cache = await caches.open(CAMPO_PAGES_CACHE);

  try {
    const res = await fetch(req);
    // Cacheamos solo respuestas OK (HTML) para uso offline posterior
    if (res && res.ok) {
      const clone = res.clone();
      cache.put(req, clone).catch(() => {});
    }
    return res;
  } catch (e) {
    // Offline: intentamos devolver una versión cacheada de ESA pantalla.
    // IMPORTANTE: hay pantallas (p.ej. registrar.php?lote=123) donde el query
    // define el contenido. Primero intentamos match exacto, y recién después
    // (si no existe) una versión genérica sin query.

    const cachedExact = await cache.match(req);
    if (cachedExact) return cachedExact;

    // Si es una pantalla registrar.php con ?lote=, NO devolvemos una versión genérica,
    // porque dejaría al usuario "atrapado" en el Paso 1 sin poder avanzar.
    // En ese caso vamos directo al offline.html.
    try {
      const u = new URL(req.url);
      const isRegistrar = u.pathname.endsWith('/admin/pesadas/registrar.php') || u.pathname.endsWith('/admin/alimentaciones/registrar.php');
      if (!isRegistrar || !u.searchParams.has('lote')) {
        const cachedGeneric = await cache.match(req, { ignoreSearch: true });
        if (cachedGeneric) return cachedGeneric;
      }
    } catch (_) {
      // si falla parsing, intentamos genérico
      const cachedGeneric = await cache.match(req, { ignoreSearch: true });
      if (cachedGeneric) return cachedGeneric;
    }

    // Último fallback: offline.html
    return caches.match('./offline.html', { ignoreSearch: true });
  }
}

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Solo GET
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Solo same-origin (evita cachear CDNs)
  if (url.origin !== self.location.origin) return;

  // Navegación HTML
  if (req.mode === 'navigate') {
    // Para CAMPO: cache útil (runtime) + fallback offline.html
    if (isCampoHtmlPage(url)) {
      event.respondWith(campoNavigate(req));
      return;
    }

    // Resto (admin): network-first con fallback offline.html (sin cachear PHP)
    event.respondWith(
      networkFirst(req, caches.match('./offline.html', { ignoreSearch: true }))
    );
    return;
  }

  // Prefetch/GET HTML de CAMPO (para tener pantallas disponibles offline)
  const accept = (req.headers.get('accept') || '').toLowerCase();
  if (isCampoHtmlPage(url) && accept.includes('text/html')) {
    event.respondWith(campoNavigate(req));
    return;
  }

  // Estáticos: cache-first
  if (isStaticAsset(url)) {
    event.respondWith(
      caches.match(req, { ignoreSearch: true }).then((cached) => {
        if (cached) return cached;

        return fetch(req)
          .then((res) => {
            if (res && res.ok) {
              const clone = res.clone();
              caches.open(STATIC_CACHE).then((c) => c.put(req, clone));
            }
            return res;
          })
          .catch(() => cached);
      })
    );
    return;
  }

  // Resto: network-only (no cache)
});
