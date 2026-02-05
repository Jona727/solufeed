<?php
// admin/dashboard.php - Dashboard por lote (UI/UX)
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Bootstrap común (logs + seguridad) ANTES de cualquier acceso a DB/Model
iniciarSesion();
register_error_handlers();
send_security_headers();
require_once __DIR__ . '/../models/StatsModel.php';

verificarAdmin();

$page_title = "Dashboard - Panel de Control";


function check_required_tables(PDO $db, array $tables) {
    $missing = [];
    foreach ($tables as $t) {
        try {
            // MariaDB no permite placeholders en SHOW TABLES; usamos information_schema
            $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1");
            $stmt->execute([':t' => $t]);
            $found = $stmt->fetchColumn();
            if (!$found) $missing[] = $t;
        } catch (Throwable $e) {
            // Si no se puede chequear (permisos), no bloquear la app.
            if (function_exists('log_event')) {
                log_event('WARN', 'DB_TABLE_CHECK_FAIL', ['table' => $t, 'err' => $e->getMessage()]);
            }
            return []; // unknown
        }
    }
    return $missing;
}

function safe_call($op, $default, callable $fn) {
    try {
        return $fn();
    } catch (Throwable $e) {
        if (function_exists('log_event')) {
            log_event('ERROR', 'DASHBOARD_QUERY_FAIL', ['op' => $op, 'err' => $e->getMessage()]);
        }
        return $default;
    }
}


$__dashboard_db = null;
$__missing_tables = [];
try {
    $__dashboard_db = getConnection();
    $__missing_tables = check_required_tables($__dashboard_db, [
        // La tabla de usuarios en el dump es `usuario` (singular)
        'usuario','tropa','campo','consumo_lote','consumo_lote_detalle','pesada','dieta','tropa_dieta_asignada'
    ]);
} catch (Throwable $e) {
    if (function_exists('log_event')) {
        log_event('ERROR', 'DASHBOARD_DB_CONNECT_FAIL', ['err' => $e->getMessage()]);
    }
}

// Si detectamos faltantes, mostramos mensaje claro en vez de 500.
if (!empty($__missing_tables)) {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="card" style="max-width:900px;margin:24px auto;padding:18px">';
    echo '<h2 style="margin:0 0 10px">⚠️ Base de datos incompleta</h2>';
    echo '<p>Faltan tablas requeridas para el dashboard: <b>' . htmlspecialchars(implode(', ', $__missing_tables), ENT_QUOTES, 'UTF-8') . '</b>.</p>';
    echo '<p>Importá el dump <code>solufeed_test_db-3.sql</code> en la base de datos configurada (la misma donde funciona el login).</p>';
    echo '</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$stats = new StatsModel();


// Selector de lote (por defecto: el último activo creado)
$lotes = safe_call('getDashboardLotes', [], function() use ($stats) { return $stats->getDashboardLotes(true); });
$selected = isset($_GET['lote']) ? (int)$_GET['lote'] : 0;

if ($selected <= 0) {
    $selected = (int)safe_call('getLatestActiveLoteId', 0, function() use ($stats) { return $stats->getLatestActiveLoteId(); });
}
if ($selected > 0 && !safe_call('loteExists', false, function() use ($stats, $selected) { return $stats->loteExists($selected, true); })) {
    $selected = (int)$stats->getLatestActiveLoteId();
}

$summary = $selected > 0 ? safe_call('getLoteSummary', null, function() use ($stats, $selected) { return $stats->getLoteSummary($selected); }) : null;

$today = $summary ? safe_call('getTodayStatsForLote', ['alimentaciones' => 0, 'kg_totales' => 0, 'kg_por_cab' => 0, 'animales_prom' => 0], function() use ($stats, $selected) { return $stats->getTodayStatsForLote($selected); }) : ['alimentaciones' => 0, 'kg_totales' => 0, 'kg_por_cab' => 0, 'animales_prom' => 0];
$lastWeight = $summary ? safe_call('getLastWeightForLote', null, function() use ($stats, $selected) { return $stats->getLastWeightForLote($selected); }) : null;

$adpv = $summary ? safe_call('getAdpvForLote', 0, function() use ($stats, $selected) { return $stats->getAdpvForLote($selected); }) : 0;
$cms = $summary ? safe_call('getCmsForLote', 0, function() use ($stats, $selected) { return $stats->getCmsForLote($selected); }) : 0;
$eficiencia = ($cms > 0) ? ($adpv / $cms) : 0;

$ultimasAlimentaciones = $summary ? safe_call('getLastFeedingsForLote', [], function() use ($stats, $selected) { return $stats->getLastFeedingsForLote($selected, 8); }) : [];
$datosPeso = $summary ? safe_call('getWeightEvolutionDataForLote', [], function() use ($stats, $selected) { return $stats->getWeightEvolutionDataForLote($selected); }) : [];
$datosMs = $summary ? safe_call('getMsConsumptionDataForLote', [], function() use ($stats, $selected) { return $stats->getMsConsumptionDataForLote($selected); }) : [];

$dias_feedlot = 0;
if ($summary && !empty($summary['fecha_inicio'])) {
    $dias_feedlot = (int)floor((time() - strtotime($summary['fecha_inicio'])) / 86400);
}

$return_to = urlencode($_SERVER['REQUEST_URI']);
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard">
    <div class="dashboard-header">
        <div>
            <h1 class="dashboard-title">📊 Dashboard</h1>
            <p class="dashboard-subtitle">Vista rápida por lote (por defecto: el último activo creado)</p>
        </div>

        <form class="dashboard-controls" method="GET" action="dashboard.php">
            <label for="lote">Lote</label>
            <select id="lote" name="lote" onchange="this.form.submit()">
                <?php if (count($lotes) === 0): ?>
                    <option value="">No hay lotes activos</option>
                <?php else: ?>
                    <?php foreach ($lotes as $l): ?>
                        <option value="<?php echo (int)$l['id_tropa']; ?>" <?php echo ((int)$l['id_tropa'] === (int)$selected) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($l['nombre']); ?><?php echo !empty($l['campo']) ? ' — ' . htmlspecialchars($l['campo']) : ''; ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <noscript><button class="btn btn-primary btn-sm" type="submit">Ver</button></noscript>
        </form>
    </div>

    <?php if (!$summary): ?>
        <div class="card">
            <h3 class="card-title">🐮 No hay lotes activos</h3>
            <p style="color: var(--text-muted); font-weight: 600;">Creá un lote o activá uno existente para ver métricas.</p>
            <a class="btn btn-primary" href="<?php echo BASE_URL; ?>/admin/lotes/crear.php">➕ Crear lote</a>
        </div>
    <?php else: ?>

        <div class="card" style="margin-bottom:0;">
            <div class="filters-row">
                <div>
                    <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                        <span class="badge-soft">🐮 <?php echo htmlspecialchars($summary['nombre']); ?></span>
                        <?php if (!empty($summary['campo'])): ?>
                            <span class="badge-soft muted">🏭 <?php echo htmlspecialchars($summary['campo']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($summary['dieta'])): ?>
                            <span class="badge-soft success">📋 <?php echo htmlspecialchars($summary['dieta']); ?></span>
                        <?php else: ?>
                            <span class="badge-soft danger">⚠️ Sin dieta asignada</span>
                        <?php endif; ?>
                        <span class="badge-soft muted">🗓️ <?php echo $dias_feedlot; ?> días</span>
                    </div>
                </div>
                </div>
        </div>

        <?php if (empty($summary['dieta'])): ?>
            <div class="alert info">
                <div class="alert-icon">📋</div>
                <div class="alert-body">
                    <strong>Este lote no tiene dieta vigente.</strong>
                    <div style="opacity:.9">Asigná una dieta para poder registrar consumos correctamente.</div>
                </div>
                </div>
        <?php endif; ?>

        <?php if ((int)$today['alimentaciones'] === 0): ?>
            <div class="alert">
                <div class="alert-icon">⚠️</div>
                <div class="alert-body">
                    <strong>Sin alimentación registrada hoy.</strong>
                    <div style="opacity:.9">Si ya se alimentó, revisá que el operario haya registrado la carga.</div>
                </div>
            </div>
        <?php endif; ?>

        <div class="dashboard-kpis">
            <div class="kpi">
                <div class="kpi-label">Animales</div>
                <div class="kpi-value"><?php echo number_format((int)($summary['animales'] ?? 0), 0, ',', '.'); ?></div>
                <div class="kpi-meta">Stock inicial del lote</div>
            </div>

            <div class="kpi">
                <div class="kpi-label">Kg tirados hoy</div>
                <div class="kpi-value"><?php echo number_format((float)$today['kg_totales'], 0, ',', '.'); ?></div>
                <div class="kpi-meta"><?php echo (int)$today['alimentaciones']; ?> registro(s) hoy</div>
            </div>

            <div class="kpi">
                <div class="kpi-label">Kg por animal hoy</div>
                <div class="kpi-value"><?php echo number_format((float)$today['kg_por_cab'], 2, ',', '.'); ?></div>
                <div class="kpi-meta">Promedio según animales presentes</div>
            </div>

            <div class="kpi">
                <div class="kpi-label">CMS (7 días)</div>
                <div class="kpi-value"><?php echo number_format((float)$cms, 2, ',', '.'); ?></div>
                <div class="kpi-meta">Kg MS / cab / día (prom.)</div>
            </div>

            <div class="kpi">
                <div class="kpi-label">ADPV (30 días)</div>
                <div class="kpi-value"><?php echo number_format((float)$adpv, 2, ',', '.'); ?></div>
                <div class="kpi-meta">Kg / día (según últimas 2 pesadas)</div>
            </div>

            <div class="kpi">
                <div class="kpi-label">Eficiencia</div>
                <div class="kpi-value"><?php echo number_format((float)$eficiencia, 3, ',', '.'); ?></div>
                <div class="kpi-meta">Kg carne / Kg MS</div>
            </div>

            <div class="kpi">
                <div class="kpi-label">Último peso</div>
                <div class="kpi-value">
                    <?php echo $lastWeight ? number_format((float)$lastWeight['peso_promedio'], 1, ',', '.') : '-'; ?>
                </div>
                <div class="kpi-meta">
                    <?php echo $lastWeight ? '📅 ' . date('d/m/Y', strtotime($lastWeight['fecha'])) : 'Sin registros'; ?>
                </div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="card">
                <h3 class="card-title">📈 Evolución de peso (30 días)</h3>
                <div class="chart-wrap">
                    <canvas id="graficoPeso"></canvas>
                </div>
            </div>

            <div class="card">
                <h3 class="card-title">🌾 Consumo MS (14 días)</h3>
                <div class="chart-wrap">
                    <canvas id="graficoMS"></canvas>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="filters-row" style="margin-bottom: 1rem;">
                <h3 class="card-title" style="margin:0;">🍽️ Últimas alimentaciones del lote</h3>
                </div>

            <?php if (count($ultimasAlimentaciones) > 0): ?>
                <div class="table-container">
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Fecha/Hora</th>
                                <th>Kg brutos</th>
                                <th>Kg/cab</th>
                                <th>Operario</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimasAlimentaciones as $alim): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('d/m', strtotime($alim['fecha'])); ?></strong>
                                        <span style="color: var(--text-muted); font-weight: 700;"> <?php echo date('H:i', strtotime($alim['hora'])); ?></span>
                                    </td>
                                    <td><strong><?php echo number_format((float)$alim['kg_totales_tirados'], 0, ',', '.'); ?> kg</strong></td>
                                    <td>
                                        <?php
                                            $kgCab = ((int)$alim['animales_presentes'] > 0) ? ((float)$alim['kg_totales_tirados'] / (int)$alim['animales_presentes']) : 0;
                                            echo number_format($kgCab, 2, ',', '.');
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($alim['operario'] ?? 'Mixer'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div style="text-align:center; padding: 2rem; color: var(--text-muted); font-weight: 700;">No hay alimentaciones recientes para este lote.</div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<script>
const dataPeso = <?php echo json_encode($datosPeso, JSON_UNESCAPED_UNICODE); ?>;
const dataMs = <?php echo json_encode($datosMs, JSON_UNESCAPED_UNICODE); ?>;

function seriesFrom(data, labelKey, valueKey) {
    return {
        labels: (data || []).map(d => {
            const dt = new Date(d[labelKey]);
            return dt.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' });
        }),
        values: (data || []).map(d => Number(d[valueKey] || 0))
    };
}

const sPeso = seriesFrom(dataPeso, 'fecha', 'peso_promedio');
const sMs = seriesFrom(dataMs, 'fecha', 'ms_total');

// Peso
const ctxP = document.getElementById('graficoPeso');
if (ctxP) {
    new Chart(ctxP.getContext('2d'), {
        type: 'line',
        data: {
            labels: sPeso.labels,
            datasets: [{
                label: 'Peso promedio (kg)',
                data: sPeso.values,
                borderColor: '#2c5530',
                backgroundColor: 'rgba(44, 85, 48, 0.12)',
                tension: 0.35,
                fill: true,
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: false } }
        }
    });
}

// MS
const ctxM = document.getElementById('graficoMS');
if (ctxM) {
    new Chart(ctxM.getContext('2d'), {
        type: 'bar',
        data: {
            labels: sMs.labels,
            datasets: [{
                label: 'MS total (kg)',
                data: sMs.values,
                backgroundColor: 'rgba(30, 96, 145, 0.25)',
                borderColor: '#1e6091',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
