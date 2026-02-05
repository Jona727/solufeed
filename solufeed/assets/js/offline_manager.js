// OfflineManager v3.1
// - Sincroniza contra endpoints JSON (evita falsos OK con HTML)
// - Chequea sesión antes de subir (evita perder datos por sesión vencida)
// - Idempotencia por client_uuid (evita duplicados al reconectar)

if (typeof OfflineManager === 'undefined') {
    const DB_NAME = 'SolufeedDB';
    const DB_VERSION = 1;
    const STORE_NAME = 'offline_queue';

    window.OfflineManager = {
        db: null,
        _syncInProgress: false,

        getBaseUrl: function () {
            return (window.BASE_URL || '').toString();
        },

        /**
         * UUID estable por registro para deduplicación.
         */
        generateUUID: function () {
            try {
                if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                    return window.crypto.randomUUID();
                }
            } catch (_) { }

            // Fallback RFC4122-ish
            const s4 = () => Math.floor((1 + Math.random()) * 0x10000).toString(16).substring(1);
            return `${s4()}${s4()}-${s4()}-${s4()}-${s4()}-${s4()}${s4()}${s4()}`;
        },

        /**
         * Obtiene el token CSRF actual de la página.
         */
        getCsrfToken: function () {
            return (window.CSRF_TOKEN ||
                document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                '').toString();
        },

        /**
         * Pide un token nuevo al servidor (rotación) y actualiza DOM/global.
         */
        fetchCsrfToken: async function () {
            const base = this.getBaseUrl();

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
                document.querySelectorAll('input[name="csrf_token"]').forEach(i => { i.value = token; });
                return token;
            } catch (e) {
                return null;
            }
        },

        /**
         * Chequea si la sesión sigue vigente (evita sincronizar al login).
         */
        pingSession: async function () {
            const base = this.getBaseUrl();
            try {
                const res = await fetch(`${base}/admin/api/ping.php`, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });

                if (res.status === 401) return false;
                if (!res.ok) return false;

                const data = await res.json().catch(() => null);
                return !!(data && data.ok === true);
            } catch (e) {
                return false;
            }
        },

        /**
         * Inicializa la base de datos IndexedDB
         */
        initDB: function () {
            return new Promise((resolve, reject) => {
                const request = indexedDB.open(DB_NAME, DB_VERSION);

                request.onupgradeneeded = (event) => {
                    const db = event.target.result;
                    if (!db.objectStoreNames.contains(STORE_NAME)) {
                        db.createObjectStore(STORE_NAME, { keyPath: 'id', autoIncrement: true });
                    }
                };

                request.onsuccess = (event) => {
                    this.db = event.target.result;
                    console.log('📦 [DB] IndexedDB inicializada con éxito');
                    this.updateUIStatus();
                    resolve(this.db);
                };

                request.onerror = (event) => {
                    console.error('❌ [DB] Error al abrir IndexedDB:', event.target.error);
                    reject(event.target.error);
                };
            });
        },

        /**
         * Guarda una operación en la base de datos local
         */
        saveToQueue: async function (endpoint, data, tipo) {
            if (!this.db) await this.initDB();

            const payload = (data && typeof data === 'object') ? Object.assign({}, data) : {};

            // UUID para idempotencia (evita duplicados)
            const uuid = (payload.client_uuid && String(payload.client_uuid).trim()) ? String(payload.client_uuid).trim() : this.generateUUID();
            payload.client_uuid = uuid;

            // Asegurar que quede un token CSRF en la cola (si el form no lo traía)
            if (!payload.csrf_token) {
                const t = this.getCsrfToken();
                if (t) payload.csrf_token = t;
            }

            // Marcar origen de forma idempotente (sin cambios de esquema)
            if (tipo === 'pesada' || tipo === 'alimentacion') {
                const o = (payload.origen_registro || '').toString().trim();
                if (!o || o === 'OFFLINE' || o === 'ONLINE') {
                    payload.origen_registro = `OFFLINE:${uuid}`;
                }
            }

            const operation = {
                endpoint: (endpoint || '').toString(),
                data: payload,
                tipo: (tipo || '').toString(),
                client_uuid: uuid,
                timestamp: new Date().toISOString()
            };

            const transaction = this.db.transaction([STORE_NAME], 'readwrite');
            const store = transaction.objectStore(STORE_NAME);

            return new Promise((resolve, reject) => {
                const request = store.add(operation);
                request.onsuccess = () => {
                    console.warn('📡 [Offline] Operación guardada en IndexedDB', operation);
                    if (typeof showToast === 'function') {
                        const label = (tipo === 'alimentacion') ? 'alimentación' : tipo;
                        showToast(`Sin conexión: Registro de ${label} guardado localmente.`, 'warning');
                    }
                    this.updateUIStatus();
                    resolve();
                };
                request.onerror = (e) => reject(e.target.error);
            });
        },

        /**
         * Recupera todas las operaciones pendientes
         */
        getPendingItems: async function () {
            if (!this.db) await this.initDB();

            return new Promise((resolve, reject) => {
                const transaction = this.db.transaction([STORE_NAME], 'readonly');
                const store = transaction.objectStore(STORE_NAME);
                const request = store.getAll();

                request.onsuccess = () => resolve(request.result);
                request.onerror = (e) => reject(e.target.error);
            });
        },

        /**
         * Elimina una operación procesada
         */
        removeItem: async function (id) {
            const transaction = this.db.transaction([STORE_NAME], 'readwrite');
            const store = transaction.objectStore(STORE_NAME);
            return new Promise((resolve) => {
                const request = store.delete(id);
                request.onsuccess = () => resolve();
            });
        },

        /**
         * Update a queued op (persist client_uuid, etc.)
         */
        updateItem: async function (item) {
            if (!item || typeof item !== "object" || !item.id) return;
            const transaction = this.db.transaction([STORE_NAME], "readwrite");
            const store = transaction.objectStore(STORE_NAME);
            return new Promise((resolve) => {
                const request = store.put(item);
                request.onsuccess = () => resolve();
                request.onerror = () => resolve();
            });
        },

        /**
         * Determina endpoint de sync según tipo.
         * Compatibilidad: si el item ya guarda un endpoint api, se respeta.
         */
        getSyncEndpoint: function (item) {
            const base = this.getBaseUrl();
            const ep = (item && item.endpoint) ? String(item.endpoint) : '';

            if (ep.includes('/admin/api/sync_')) return ep;

            if (item && item.tipo === 'pesada') {
                return `${base}/admin/api/sync_pesada.php`;
            }

            if (item && item.tipo === 'alimentacion') {
                return `${base}/admin/api/sync_alimentacion.php`;
            }

            // Fallback (mejor que nada, pero puede devolver HTML)
            return ep;
        },

        /**
         * Sincronización robusta v3.0
         */
        sync: async function () {
            if (!navigator.onLine) return;
            if (this._syncInProgress) return;
            this._syncInProgress = true;

            try {

            const pendingItems = await this.getPendingItems();
            // Filtrar: solo registros reales que tengan una URL válida (endpoint)
            const realData = pendingItems.filter(item => {
                return item.id !== 'current_session' &&
                    item.endpoint &&
                    item.endpoint !== 'undefined' &&
                    typeof item.endpoint === 'string';
            });

            if (realData.length === 0) return;

            console.log(`🔃 [Sync] Detectados ${realData.length} registros para subir.`);
            this.toggleSyncUI(true);

            // 1) Chequear sesión
            const sessionOk = await this.pingSession();
            if (!sessionOk) {
                this.toggleSyncUI(false);
                this.updateUIStatus();
                if (typeof showToast === 'function') {
                    showToast('Tenés registros offline pendientes. Iniciá sesión para sincronizarlos.', 'warning');
                }
                return;
            }

            // 2) Obtener CSRF fresco
            let csrfToken = await this.fetchCsrfToken();
            if (!csrfToken) csrfToken = this.getCsrfToken();
            if (!csrfToken) {
                this.toggleSyncUI(false);
                this.updateUIStatus();
                if (typeof showToast === 'function') {
                    showToast('No se pudo obtener un token de seguridad. Conectate y reintentá.', 'error');
                }
                return;
            }

            // 3) Procesar cola
            for (const item of realData) {
                try {
                    const endpoint = this.getSyncEndpoint(item);
                    if (!endpoint) continue;

                    console.log(`📤 Sincronizando ${item.tipo} con: ${endpoint}`);

                    const payload = Object.assign({}, item.data || {});
                    if (csrfToken) payload.csrf_token = csrfToken;

                    // Asegurar UUID
                    if (!payload.client_uuid) {
                        payload.client_uuid = (item.client_uuid && String(item.client_uuid).trim()) ? String(item.client_uuid).trim() : this.generateUUID();
                    }

                    // Ensure deterministic offline origin for idempotency (pesada/alimentacion)
                    if (item.tipo === 'pesada' || item.tipo === 'alimentacion') {
                        const desiredOrigin = 'OFFLINE:' + payload.client_uuid;
                        if (!payload.origen_registro || String(payload.origen_registro).indexOf('OFFLINE:') !== 0) {
                            payload.origen_registro = desiredOrigin;
                        }
                    }

                    // Persist generated UUID/origin back to the queue (covers older queued items)
                    if (!item.client_uuid || String(item.client_uuid) !== String(payload.client_uuid) || !item.data || !item.data.client_uuid) {
                        const updatedItem = Object.assign({}, item, {
                            client_uuid: payload.client_uuid,
                            data: Object.assign({}, item.data || {}, {
                                client_uuid: payload.client_uuid,
                                origen_registro: payload.origen_registro || (item.data ? item.data.origen_registro : undefined)
                            })
                        });
                        await this.updateItem(updatedItem);
                    }

                    const doPost = async () => {
                        return await fetch(endpoint, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: new URLSearchParams(payload)
                        });
                    };

                    let response = await doPost();

                    // Sesión vencida
                    if (response.status === 401) {
                        if (typeof showToast === 'function') {
                            showToast('Sesión vencida. Iniciá sesión para sincronizar los registros offline.', 'warning');
                        }
                        break;
                    }

                    // CSRF inválido: refrescar token y reintentar una vez
                    if (response.status === 419 || response.status === 403) {
                        const newToken = await this.fetchCsrfToken();
                        if (newToken) {
                            csrfToken = newToken;
                            payload.csrf_token = newToken;
                            response = await doPost();
                        }
                    }

                    let json = null;
                    try {
                        json = await response.json();
                    } catch (_) {
                        json = null;
                    }

                    // Solo borramos de la cola si el servidor confirma ok:true
                    if (response.ok && json && json.ok === true) {
                        await this.removeItem(item.id);
                        console.log(`✅ [Sync] Registro #${item.id} ok.`);
                        continue;
                    }

                    // Errores "no recuperables" o que requieren intervención
                    if (response.status === 422 && json && Array.isArray(json.errors) && json.errors.length) {
                        if (typeof showToast === 'function') {
                            showToast(`No se pudo sincronizar un registro: ${String(json.errors[0])}`, 'error');
                        }
                        break;
                    }

                    if (response.status === 404) {
                        // Endpoint no existe: evitar bloqueo de cola
                        await this.removeItem(item.id);
                        continue;
                    }

                    const msg = (json && (json.message || json.error)) ? String(json.message || json.error) : `Error del servidor (${response.status})`;
                    if (typeof showToast === 'function') {
                        showToast(`No se pudo sincronizar. ${msg}`, 'error');
                    }
                    break;

                } catch (error) {
                    console.error(`❌ [Sync] Error de red:`, error);
                    break; // Si falla la red del todo, paramos la cola
                }
            }

            this.toggleSyncUI(false);
            this.updateUIStatus();

            const remaining = (await this.getPendingItems()).filter(i => i.id !== 'current_session');
            if (remaining.length === 0) {
                if (typeof showToast === 'function') showToast('Sincronización finalizada.', 'success');

                // Recargar si estamos en páginas de consulta para ver cambios
                const path = window.location.pathname;
                if (path.includes('index.php') || path.includes('historial') || path.includes('consultar')) {
                    setTimeout(() => window.location.reload(), 1500);
                }
            }
            } finally {
                this._syncInProgress = false;
            }
        },

        /**
         * Actualiza el contador visual en la interfaz
         */
        updateUIStatus: async function () {
            const allItems = await this.getPendingItems();
            // Filtrar solo registros reales (excluir la sesión técnica)
            const items = allItems.filter(i => i.id !== 'current_session');

            const statusDiv = document.getElementById('connection-status');
            if (!statusDiv) return;

            if (items.length > 0) {
                statusDiv.innerHTML = `📡 Tienes <b>${items.length}</b> registros pendientes de sincronizar.`;
                statusDiv.className = 'card alerta-offline';
                statusDiv.style.display = 'block';
                statusDiv.style.background = '#fff9db';
                statusDiv.style.borderLeft = '5px solid #fab005';
                statusDiv.style.padding = '1rem';
                statusDiv.style.marginBottom = '1rem';
            } else {
                statusDiv.style.display = 'none';
            }
        },

        toggleSyncUI: function (show) {
            let overlay = document.getElementById('sync-progress-overlay');
            if (!overlay && show) {
                overlay = document.createElement('div');
                overlay.id = 'sync-progress-overlay';
                overlay.style = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(44,85,48,0.85); color:white; z-index:10000; display:flex; flex-direction:column; justify-content:center; align-items:center; font-family:Outfit, sans-serif;';
                overlay.innerHTML = `
                    <div style="font-size:3rem; margin-bottom:1rem; animation: rotate 2s linear infinite;">🔃</div>
                    <h2 style="margin:0;">Sincronizando con Solufeed...</h2>
                    <p>Por favor, no cierres el navegador.</p>
                    <style>@keyframes rotate { from {transform:rotate(0deg);} to {transform:rotate(360deg);} }</style>
                `;
                document.body.appendChild(overlay);
            }
            if (overlay) overlay.style.display = show ? 'flex' : 'none';
        },

        clearQueue: function () {
            return new Promise((resolve, reject) => {
                const go = () => {
                    try {
                        const tx = this.db.transaction([STORE_NAME], 'readwrite');
                        const store = tx.objectStore(STORE_NAME);
                        const req = store.clear();
                        req.onsuccess = () => resolve(true);
                        req.onerror = () => reject(req.error);
                    } catch (e) {
                        reject(e);
                    }
                };

                if (!this.db) {
                    this.initDB().then(go).catch(reject);
                } else {
                    go();
                }
            });
        },

        clearAll: async function () {
            try {
                await this.clearQueue();
            } catch (e) {
                // ignore
            }
            return true;
        }
    };

    // Inicialización y Listeners
    window.addEventListener('online', () => OfflineManager.sync());
    window.addEventListener('load', () => {
        OfflineManager.initDB().then(() => {
            if (navigator.onLine) OfflineManager.sync();
        });
    });
}
