<?php
// admin/campo/pendientes_offline.php
// Pantalla para ver / sincronizar / descartar registros offline (IndexedDB)

require_once __DIR__ . '/../../includes/functions.php';

// Solo usuario CAMPO
verificarCampo();

$page_title = 'Pendientes Offline';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="campo-hub">
    <div class="card" style="padding: 1.5rem; display:flex; align-items:center; justify-content:space-between; gap: 1rem; flex-wrap: wrap;">
        <div>
            <h2 style="margin:0; font-weight: 800; color: var(--primary); display:flex; align-items:center; gap: 10px;">
                <span>📡</span> Pendientes offline
            </h2>
            <p style="margin: 0.35rem 0 0; color: var(--text-muted);">Acá podés ver lo que quedó guardado sin conexión, sincronizarlo o descartarlo.</p>
        </div>

        <div style="display:flex; gap: 0.5rem; flex-wrap: wrap;">
            <button id="btnSyncNow" class="btn btn-primary" type="button">🔃 Sincronizar ahora</button>
            <button id="btnRefresh" class="btn btn-secondary" type="button">🔄 Actualizar</button>
            <button id="btnClearAll" class="btn" type="button" style="background:#fee2e2; color:#991b1b; border: 1px solid #fecaca;">🗑️ Vaciar todo</button>
        </div>
    </div>

    <!-- Indicador / resumen -->
    <div id="offlineSummary" class="card" style="display:none; padding: 1rem; border-left: 5px solid var(--warning);"></div>

    <!-- Lista -->
    <div id="offlineList" style="display:grid; gap: 1rem;"></div>

    <!-- Vacío -->
    <div id="offlineEmpty" class="card" style="display:none; padding: 1.25rem; text-align:center; background:#f8fafc; border:none;">
        <div style="font-size: 2rem;">✅</div>
        <div style="font-weight:800; color: var(--primary); margin-top: 0.5rem;">No hay pendientes</div>
        <div style="color: var(--text-muted); margin-top: 0.25rem;">Cuando cargues datos sin internet, van a aparecer acá.</div>
    </div>

    <div class="card" style="background:#f1f5f9; border:none;">
        <h3 style="color: var(--primary); font-weight: 800; margin-bottom: 0.75rem; display:flex; align-items:center; gap: 10px;">
            <span>ℹ️</span> Importante
        </h3>
        <ul style="padding-left: 1.25rem; color: var(--text-main); display:grid; gap: 0.4rem; margin: 0;">
            <li>Para sincronizar necesitás estar <b>con internet</b> y con la sesión iniciada.</li>
            <li>Si descartás un registro, se borra <b>solo del teléfono</b> (no del servidor).</li>
            <li>Los registros se suben con deduplicación para evitar duplicados al reconectar.</li>
        </ul>
    </div>
</div>

<script>
(function(){
    const listEl = document.getElementById('offlineList');
    const emptyEl = document.getElementById('offlineEmpty');
    const summaryEl = document.getElementById('offlineSummary');

    const btnSync = document.getElementById('btnSyncNow');
    const btnRefresh = document.getElementById('btnRefresh');
    const btnClear = document.getElementById('btnClearAll');

    function esc(s){
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function humanTs(iso){
        try {
            const d = new Date(iso);
            if (isNaN(d.getTime())) return String(iso || '');
            return d.toLocaleString('es-AR', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit' });
        } catch(e){
            return String(iso || '');
        }
    }

    function cleanDataForView(obj){
        const clone = Object.assign({}, obj || {});
        // No mostrar token CSRF
        if (clone.csrf_token) clone.csrf_token = '***';
        return clone;
    }

    function buildCard(item){
        const tipo = (item.tipo || '').toString();
        const data = item.data || {};

        const icon = (tipo === 'pesada') ? '⚖️' : (tipo === 'alimentacion') ? '🍽️' : '📄';
        const title = (tipo === 'pesada') ? 'Pesada' : (tipo === 'alimentacion') ? 'Alimentación' : (tipo || 'Registro');

        const idTropa = data.id_tropa || data.idTropa || '';
        const fecha = data.fecha || '';
        const hora = data.hora || '';

        let extra = '';
        if (tipo === 'pesada') {
            extra = `Peso prom: <b>${esc(data.peso_promedio || '')}</b> · Animales vistos: <b>${esc(data.animales_vistos || '')}</b>`;
        }
        if (tipo === 'alimentacion') {
            extra = `Kg totales: <b>${esc(data.kg_totales || data.kg_totales_tirados || '')}</b> · Sobrante: <b>${esc(data.sobrante_nivel || '')}</b>`;
        }

        const detailsObj = cleanDataForView(data);

        const wrap = document.createElement('div');
        wrap.className = 'card';
        wrap.style.padding = '1rem';
        wrap.style.borderLeft = '5px solid ' + (tipo === 'pesada' ? 'var(--secondary)' : 'var(--primary)');

        wrap.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap: 1rem; flex-wrap: wrap;">
                <div style="flex:1; min-width: 240px;">
                    <div style="display:flex; align-items:center; gap: 10px;">
                        <div style="font-size: 1.5rem;">${icon}</div>
                        <div>
                            <div style="font-weight: 800; color: var(--primary);">${esc(title)} <span style="font-weight:600; color: var(--text-muted);">#${esc(item.id)}</span></div>
                            <div style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.15rem;">
                                🕒 ${esc(humanTs(item.timestamp))}
                                ${idTropa ? ` · 🐮 Lote <b>${esc(idTropa)}</b>` : ''}
                                ${fecha ? ` · 📅 <b>${esc(fecha)}</b>` : ''}
                                ${hora ? ` · ⏱️ <b>${esc(hora)}</b>` : ''}
                            </div>
                        </div>
                    </div>
                    ${extra ? `<div style="margin-top: 0.6rem; font-size: 0.95rem;">${extra}</div>` : ''}
                </div>

                <div style="display:flex; gap: 0.5rem; flex-wrap: wrap; align-items:center;">
                    <button class="btn btn-secondary btnToggle" type="button">📄 Detalles</button>
                    <button class="btn" type="button" style="background:#fee2e2; color:#991b1b; border:1px solid #fecaca;" data-del="${esc(item.id)}">🗑️ Descartar</button>
                </div>
            </div>

            <div class="details" style="display:none; margin-top: 0.75rem; background:#0b1220; color:#e2e8f0; padding: 0.75rem; border-radius: 10px; overflow:auto; font-size: 0.85rem;">
                <pre style="margin:0; white-space:pre-wrap;">${esc(JSON.stringify(detailsObj, null, 2))}</pre>
            </div>
        `;

        // toggle details
        const btnToggle = wrap.querySelector('.btnToggle');
        const details = wrap.querySelector('.details');
        btnToggle.addEventListener('click', () => {
            const show = details.style.display === 'none';
            details.style.display = show ? 'block' : 'none';
            btnToggle.textContent = show ? '🙈 Ocultar' : '📄 Detalles';
        });

        // delete
        const btnDel = wrap.querySelector('[data-del]');
        btnDel.addEventListener('click', async () => {
            const ok = confirm('¿Descartar este registro offline? Esto no se puede deshacer.');
            if (!ok) return;
            try {
                await OfflineManager.removeItem(item.id);
                if (window.showToast) showToast('Registro descartado.', 'info');
                await render();
            } catch(e) {
                if (window.showToast) showToast('No se pudo descartar.', 'error');
            }
        });

        return wrap;
    }

    async function render(){
        listEl.innerHTML = '';
        summaryEl.style.display = 'none';
        emptyEl.style.display = 'none';

        if (!window.OfflineManager) {
            emptyEl.style.display = 'block';
            emptyEl.querySelector('div:nth-child(2)').textContent = 'OfflineManager no está disponible';
            return;
        }

        await OfflineManager.initDB();
        const all = await OfflineManager.getPendingItems();
        const items = (all || []).filter(i => i && i.id !== 'current_session');
        items.sort((a,b) => String(b.timestamp||'').localeCompare(String(a.timestamp||'')));

        if (items.length === 0) {
            emptyEl.style.display = 'block';
            return;
        }

        summaryEl.style.display = 'block';
        summaryEl.innerHTML = `📦 Tenés <b>${items.length}</b> registro(s) guardado(s) sin conexión.`;

        items.forEach(item => listEl.appendChild(buildCard(item)));
    }

    btnRefresh.addEventListener('click', () => render());

    btnSync.addEventListener('click', async () => {
        if (!navigator.onLine) {
            if (window.showToast) showToast('Estás sin conexión. Conectate para sincronizar.', 'warning');
            return;
        }
        if (window.OfflineManager && OfflineManager._syncInProgress) {
            if (window.showToast) showToast('Ya se está sincronizando. Esperá un momento…', 'info');
            return;
        }
        try {
            await OfflineManager.sync();
            setTimeout(() => render(), 600);
        } catch(e) {
            if (window.showToast) showToast('No se pudo sincronizar.', 'error');
        }
    });

    btnClear.addEventListener('click', async () => {
        const ok = confirm('¿Vaciar todos los registros offline pendientes? Esto no se puede deshacer.');
        if (!ok) return;
        try {
            await OfflineManager.clearAll();
            if (window.showToast) showToast('Cola offline vaciada.', 'info');
            await render();
        } catch(e) {
            if (window.showToast) showToast('No se pudo vaciar la cola.', 'error');
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) render();
    });

    // init
    render();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
