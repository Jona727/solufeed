<?php
// admin/campo/historial.php
// Historial completo del usuario de campo con filtros + acciones (editar/borrar propios registros)

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

verificarCampo();

$page_title = 'Historial';
$db = getConnection();

$id_usuario = (int)($_SESSION['usuario_id'] ?? 0);

// Filtros
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-7 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$tipo  = $_GET['tipo']  ?? 'TODOS'; // TODOS | ALIMENTACION | PESADA
$id_tropa = isset($_GET['lote']) ? (int)$_GET['lote'] : 0;
$orden = ($_GET['orden'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// Sanitizar fechas
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) $desde = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) $hasta = date('Y-m-d');
if (strtotime($desde) > strtotime($hasta)) {
    $tmp = $desde;
    $desde = $hasta;
    $hasta = $tmp;
}

// Lotes asignados al usuario (para filtro)
$stmt = $db->prepare("
    SELECT t.id_tropa, t.nombre, c.nombre as campo_nombre, t.activo
    FROM tropa t
    INNER JOIN usuario_tropa ut ON t.id_tropa = ut.id_tropa
    LEFT JOIN campo c ON t.id_campo = c.id_campo
    WHERE ut.id_usuario = ?
    ORDER BY t.nombre ASC
");
$stmt->execute([$id_usuario]);
$lotes = $stmt->fetchAll();

// Si el filtro de lote no está asignado al usuario, resetear.
if ($id_tropa > 0 && !usuarioCampoPuedeAccederLote($id_tropa)) {
    $id_tropa = 0;
}

$registros = [];

// Alimentaciones
if ($tipo === 'TODOS' || $tipo === 'ALIMENTACION') {
    $sql = "
        SELECT
            'ALIMENTACION' AS tipo,
            cl.id_consumo AS id_registro,
            cl.fecha AS fecha,
            cl.hora AS hora,
            CONCAT(cl.fecha, ' ', cl.hora) AS fecha_hora,
            t.id_tropa,
            t.nombre AS nombre_lote,
            c.nombre AS nombre_campo,
            cl.kg_totales_tirados,
            cl.animales_presentes,
            cl.sobrante_nivel,
            cl.origen_registro,
            NULL AS peso_promedio,
            NULL AS animales_vistos,
            NULL AS animales_esperados,
            NULL AS hay_diferencia
        FROM consumo_lote cl
        INNER JOIN tropa t ON cl.id_tropa = t.id_tropa
        LEFT JOIN campo c ON t.id_campo = c.id_campo
        WHERE cl.id_usuario = ?
          AND cl.fecha BETWEEN ? AND ?
    ";
    $params = [$id_usuario, $desde, $hasta];
    if ($id_tropa > 0) {
        $sql .= " AND cl.id_tropa = ?";
        $params[] = $id_tropa;
    }
    $sql .= " ORDER BY cl.fecha DESC, cl.hora DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $registros = array_merge($registros, $stmt->fetchAll());
}

// Pesadas
if ($tipo === 'TODOS' || $tipo === 'PESADA') {
    $sql = "
        SELECT
            'PESADA' AS tipo,
            p.id_pesada AS id_registro,
            p.fecha AS fecha,
            NULL AS hora,
            CONCAT(p.fecha, ' 00:00:00') AS fecha_hora,
            t.id_tropa,
            t.nombre AS nombre_lote,
            c.nombre AS nombre_campo,
            NULL AS kg_totales_tirados,
            NULL AS animales_presentes,
            NULL AS sobrante_nivel,
            p.origen_registro,
            p.peso_promedio,
            p.animales_vistos,
            p.animales_esperados,
            p.hay_diferencia
        FROM pesada p
        INNER JOIN tropa t ON p.id_tropa = t.id_tropa
        LEFT JOIN campo c ON t.id_campo = c.id_campo
        WHERE p.id_usuario = ?
          AND p.fecha BETWEEN ? AND ?
    ";
    $params = [$id_usuario, $desde, $hasta];
    if ($id_tropa > 0) {
        $sql .= " AND p.id_tropa = ?";
        $params[] = $id_tropa;
    }
    $sql .= " ORDER BY p.fecha DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $registros = array_merge($registros, $stmt->fetchAll());
}

// Ordenar en PHP por fecha_hora
usort($registros, function ($a, $b) use ($orden) {
    $cmp = strcmp($a['fecha_hora'], $b['fecha_hora']);
    return $orden === 'ASC' ? $cmp : -$cmp;
});


// Paginación (UI + evitar listas eternas)
$per_page = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$total_registros = count($registros);
$total_pages = max(1, (int)ceil($total_registros / $per_page));
if ($page > $total_pages) $page = $total_pages;

$from_idx = $total_registros > 0 ? (($page - 1) * $per_page + 1) : 0;
$to_idx   = $total_registros > 0 ? min($total_registros, $page * $per_page) : 0;

$registros_paged = array_slice($registros, ($page - 1) * $per_page, $per_page);

$build_page_url = function(int $p) {
    $params = $_GET;
    $params['page'] = $p;
    if ((int)$params['page'] <= 1) unset($params['page']);
    $qs = http_build_query($params);
    return basename($_SERVER['PHP_SELF']) . ($qs ? '?' . $qs : '');
};

$return_to = $_SERVER['REQUEST_URI'];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card" style="margin-bottom: 1.5rem;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap: 1rem; flex-wrap: wrap;">
        <div>
            <h1 class="card-title" style="margin:0;">📚 Historial</h1>
            <p style="margin: .25rem 0 0 0; color: var(--text-muted);">Tus alimentaciones y pesadas, con filtros y acciones.</p>
        </div>
        <a class="btn btn-secondary" href="<?php echo BASE_URL; ?>/admin/campo/index.php">← Volver al Hub</a>
    </div>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
    <h2 class="card-title">🔎 Filtros</h2>
    <form method="GET" class="formulario" style="margin-top: .5rem;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <div class="form-grupo">
                <label for="desde">Desde</label>
                <input type="date" id="desde" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
            </div>
            <div class="form-grupo">
                <label for="hasta">Hasta</label>
                <input type="date" id="hasta" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
            </div>
            <div class="form-grupo">
                <label for="tipo">Tipo</label>
                <select id="tipo" name="tipo">
                    <option value="TODOS" <?php echo $tipo==='TODOS'?'selected':''; ?>>Todos</option>
                    <option value="ALIMENTACION" <?php echo $tipo==='ALIMENTACION'?'selected':''; ?>>Alimentación</option>
                    <option value="PESADA" <?php echo $tipo==='PESADA'?'selected':''; ?>>Pesada</option>
                </select>
            </div>
            <div class="form-grupo">
                <label for="lote">Lote</label>
                <select id="lote" name="lote">
                    <option value="0">Todos mis lotes</option>
                    <?php foreach ($lotes as $l): ?>
                        <option value="<?php echo (int)$l['id_tropa']; ?>" <?php echo $id_tropa===(int)$l['id_tropa']?'selected':''; ?>>
                            <?php echo htmlspecialchars($l['nombre']); ?><?php echo ((int)$l['activo']===0)?' (Inactivo)':''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-grupo">
                <label for="orden">Orden</label>
                <select id="orden" name="orden">
                    <option value="DESC" <?php echo $orden==='DESC'?'selected':''; ?>>Más reciente primero</option>
                    <option value="ASC" <?php echo $orden==='ASC'?'selected':''; ?>>Más antiguo primero</option>
                </select>
            </div>
        </div>

        <div style="margin-top: 1rem; display:flex; gap: .75rem; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary">Aplicar</button>
            <a class="btn btn-secondary" href="<?php echo BASE_URL; ?>/admin/campo/historial.php">Limpiar</a>
        </div>
    </form>
</div>

<div class="card">
    <div style="display:flex; align-items:center; justify-content:space-between; gap: 1rem; flex-wrap: wrap;">
        <h2 class="card-title" style="margin:0;">📄 Resultados (<?php echo count($registros); ?>)</h2>
    </div>

    <?php if (empty($registros)): ?>
        <div style="padding: 1rem; color: var(--text-muted);">
            No hay registros con estos filtros.
        </div>
    <?php else: ?>

    <table class="tabla responsive-table" style="margin-top: 1rem;">
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Lote</th>
                <th>Detalle</th>
                <th style="width: 220px;">Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($registros_paged as $r): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars(formatearFecha($r['fecha'])); ?>
                        <?php if (!empty($r['hora'])): ?>
                            <div style="color: var(--text-muted); font-size: .85rem;"><?php echo htmlspecialchars(substr($r['hora'],0,5)); ?> hs</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['tipo'] === 'ALIMENTACION'): ?>🍽️ Alimentación<?php else: ?>⚖️ Pesada<?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo htmlspecialchars($r['nombre_lote']); ?></strong>
                        <div style="color: var(--text-muted); font-size: .85rem;"><?php echo htmlspecialchars($r['nombre_campo'] ?? '-'); ?></div>
                    </td>
                    <td>
                        <?php if ($r['tipo'] === 'ALIMENTACION'): ?>
                            <?php echo number_format((float)$r['kg_totales_tirados'], 0); ?> kg totales
                            <?php if (!empty($r['animales_presentes'])): ?>
                                <div style="color: var(--text-muted); font-size: .85rem;">
                                    <?php echo (int)$r['animales_presentes']; ?> animales · <?php echo number_format(((float)$r['kg_totales_tirados'] / max(1,(int)$r['animales_presentes'])), 2); ?> kg/animal
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <strong><?php echo number_format((float)$r['peso_promedio'], 0); ?> kg</strong> promedio
                            <div style="color: var(--text-muted); font-size: .85rem;">
                                <?php echo (int)$r['animales_esperados']; ?> esperados / <?php echo (int)$r['animales_vistos']; ?> vistos
                                <?php if ((int)$r['hay_diferencia'] === 1): ?> · <span style="color: var(--danger); font-weight: 700;">⚠️ Diferencia</span><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex; gap: .5rem; flex-wrap: wrap;">
                            <?php if ($r['tipo'] === 'ALIMENTACION'): ?>
                                <a class="btn btn-secondary" href="<?php echo BASE_URL; ?>/admin/campo/alimentacion_editar.php?id=<?php echo (int)$r['id_registro']; ?>&return_to=<?php echo urlencode($return_to); ?>">Editar</a>
                                <form method="POST" action="<?php echo BASE_URL; ?>/admin/campo/alimentacion_borrar.php" onsubmit="return confirm('¿Borrar esta alimentación? Esta acción no se puede deshacer.');" style="display:inline;">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id_registro']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-danger">Borrar</button>
                                </form>
                            <?php else: ?>
                                <a class="btn btn-secondary" href="<?php echo BASE_URL; ?>/admin/campo/pesada_editar.php?id=<?php echo (int)$r['id_registro']; ?>&return_to=<?php echo urlencode($return_to); ?>">Editar</a>
                                <form method="POST" action="<?php echo BASE_URL; ?>/admin/campo/pesada_borrar.php" onsubmit="return confirm('¿Borrar esta pesada? Esta acción no se puede deshacer.');" style="display:inline;">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int)$r['id_registro']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-danger">Borrar</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 1rem; display:flex; align-items:center; justify-content:space-between; gap: 1rem; flex-wrap: wrap;">
        <div style="color: var(--text-muted);">
            Mostrando <strong><?php echo (int)$from_idx; ?></strong>–<strong><?php echo (int)$to_idx; ?></strong> de <strong><?php echo (int)$total_registros; ?></strong>
        </div>

        <?php if ($total_pages > 1): ?>
            <div style="display:flex; gap: .5rem; flex-wrap: wrap;">
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary" href="<?php echo htmlspecialchars($build_page_url($page - 1)); ?>">← Anterior</a>
                <?php else: ?>
                    <span class="btn btn-secondary" style="opacity:.5; pointer-events:none;">← Anterior</span>
                <?php endif; ?>

                <span class="btn btn-secondary" style="pointer-events:none;">Página <?php echo (int)$page; ?> de <?php echo (int)$total_pages; ?></span>

                <?php if ($page < $total_pages): ?>
                    <a class="btn btn-secondary" href="<?php echo htmlspecialchars($build_page_url($page + 1)); ?>">Siguiente →</a>
                <?php else: ?>
                    <span class="btn btn-secondary" style="opacity:.5; pointer-events:none;">Siguiente →</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
