<?php
// admin/lotes/listar.php - Listado consistente con Insumos/Establecimientos

require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarAdmin();

$page_title = 'Gestión de Lotes';
$db = getConnection();
$return_to = urlencode($_SERVER['REQUEST_URI']);

// Paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

$estado = isset($_GET['estado']) ? (string)$_GET['estado'] : '';
$orden  = isset($_GET['orden']) ? (string)$_GET['orden'] : '';
$busqueda = isset($_GET['busqueda']) ? trim((string)$_GET['busqueda']) : '';

$allowedEstado = ['activos','inactivos','todos'];
if ($estado === '' || !in_array($estado, $allowedEstado, true)) $estado = 'activos';

$allowedOrden = ['recientes','nombre','campo'];
if ($orden === '' || !in_array($orden, $allowedOrden, true)) $orden = 'recientes';

$where = 'WHERE 1=1';
$params = [];

if ($estado !== 'todos') {
    $where .= ' AND t.activo = :activo';
    $params[':activo'] = ($estado === 'activos') ? 1 : 0;
}

if ($busqueda !== '') {
    $where .= ' AND (
        t.nombre LIKE :b1 OR t.categoria LIKE :b2 OR
        c.nombre LIKE :b3 OR c.ubicacion LIKE :b4
    )';
    $like = '%' . $busqueda . '%';
    $params[':b1'] = $like;
    $params[':b2'] = $like;
    $params[':b3'] = $like;
    $params[':b4'] = $like;
}

// Total para paginación
$stmt_count = $db->prepare('SELECT COUNT(*) FROM tropa t INNER JOIN campo c ON t.id_campo = c.id_campo ' . $where);
$stmt_count->execute($params);
$total_registros = (int)$stmt_count->fetchColumn();
$total_paginas = (int)ceil($total_registros / $registros_por_pagina);
if ($total_paginas < 1) $total_paginas = 1;
if ($pagina_actual > $total_paginas) $pagina_actual = $total_paginas;

$offset = ($pagina_actual - 1) * $registros_por_pagina;

$orderSql = 't.fecha_inicio DESC';
if ($orden === 'nombre') $orderSql = 't.nombre ASC';
if ($orden === 'campo')  $orderSql = 'c.nombre ASC, t.nombre ASC';

// Consulta paginada
$sql_final = "
    SELECT
        t.id_tropa,
        t.nombre,
        t.categoria,
        t.fecha_inicio,
        t.cantidad_inicial,
        t.activo,
        c.nombre as campo_nombre,
        d.nombre as dieta_nombre,
        (
            SELECT GROUP_CONCAT(SUBSTRING_INDEX(u.nombre, ' ', 1) SEPARATOR ', ')
            FROM usuario_tropa ut
            INNER JOIN usuario u ON ut.id_usuario = u.id_usuario
            WHERE ut.id_tropa = t.id_tropa
        ) as operarios_asignados
    FROM tropa t
    INNER JOIN campo c ON t.id_campo = c.id_campo
    LEFT JOIN tropa_dieta_asignada tda ON t.id_tropa = tda.id_tropa
        AND tda.fecha_desde <= CURDATE()
        AND (tda.fecha_hasta IS NULL OR tda.fecha_hasta >= CURDATE())
    LEFT JOIN dieta d ON tda.id_dieta = d.id_dieta
    {$where}
    ORDER BY {$orderSql}
    LIMIT :limit OFFSET :offset
";

$stmt = $db->prepare($sql_final);
foreach ($params as $key => $val) {
    if (is_int($val)) {
        $stmt->bindValue($key, $val, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
}
$stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$lotes = $stmt->fetchAll();

$query_extra = '&estado=' . urlencode($estado) . '&orden=' . urlencode($orden) . (!empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : '');

require_once '../../includes/header.php';
?>

<div class="insumos-container">
    <div class="page-header">
        <div>
            <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">
                <?php echo ($estado === 'inactivos') ? '🗃️ Lotes Cerrados' : '🐮 Gestión de Lotes'; ?>
            </h1>
            <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.95rem; font-weight: 500;">
                <?php echo ($estado === 'inactivos') ? 'Visualizando lotes cerrados' : (($estado === 'todos') ? 'Visualizando todos los lotes' : 'Administrá los lotes activos del feedlot'); ?>
            </p>
        </div>
        <div class="header-actions">
            <div class="segmented" aria-label="Filtro de estado">
                <a href="listar.php?estado=activos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'activos' ? 'active' : ''; ?>">Activos</a>
                <a href="listar.php?estado=inactivos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'inactivos' ? 'active' : ''; ?>">Cerrados</a>
                <a href="listar.php?estado=todos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'todos' ? 'active' : ''; ?>">Todos</a>
            </div>

            <?php if ($estado !== 'inactivos'): ?>
                <a href="crear.php?return_to=<?php echo $return_to; ?>" class="btn btn-primary btn-sm">
                    <span>➕</span> Nuevo Lote
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="filters-bar">
        <form method="GET" action="listar.php" class="filters-row">
            <div class="filters-left">
                <input type="hidden" name="estado" value="<?php echo htmlspecialchars($estado); ?>">
                <input type="hidden" name="pagina" value="1">

                <div style="position: relative;">
                    <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); font-size: 1.1rem;">🔍</span>
                    <input class="filter-input" type="text" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Buscar por lote, categoría o campo..." style="padding-left: 2.6rem;">
                </div>

                <select class="filter-select" name="orden">
                    <option value="recientes" <?php echo $orden === 'recientes' ? 'selected' : ''; ?>>Más recientes primero</option>
                    <option value="nombre" <?php echo $orden === 'nombre' ? 'selected' : ''; ?>>Nombre (A → Z)</option>
                    <option value="campo" <?php echo $orden === 'campo' ? 'selected' : ''; ?>>Campo (A → Z)</option>
                </select>

                <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>

                <?php if (!empty($busqueda)): ?>
                    <a class="btn btn-secondary btn-sm" href="listar.php?estado=<?php echo urlencode($estado); ?>&orden=<?php echo urlencode($orden); ?>">Limpiar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title"><span>📋</span> Lista de Lotes</h3>

        <?php if (count($lotes) > 0): ?>
            <div class="table-container">
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Lote</th>
                            <th>Campo</th>
                            <th>Animales</th>
                            <th>Dieta</th>
                            <th style="text-align: center;">Días</th>
                            <th style="text-align: center;">Estado</th>
                            <th class="th-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lotes as $lote): ?>
                            <?php
                            $animales_presentes = obtenerAnimalesPresentes($lote['id_tropa']);
                            $fecha_inicio = new DateTime($lote['fecha_inicio']);
                            $fecha_hoy = new DateTime();
                            $dias_engorde = $fecha_inicio->diff($fecha_hoy)->days;
                            $activo = ((int)$lote['activo'] === 1);
                            ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--primary); font-size: 1.05rem; display: block; margin-bottom: 2px;">
                                        <?php echo htmlspecialchars($lote['nombre']); ?>
                                    </strong>
                                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 4px;">
                                        <?php echo htmlspecialchars($lote['categoria']); ?>
                                    </div>
                                    <?php if (!empty($lote['operarios_asignados'])): ?>
                                        <div style="font-size: 0.75rem; background: #e0f2fe; color: #0284c7; display: inline-flex; align-items: center; padding: 2px 6px; border-radius: 4px; gap: 4px;">
                                            <span>🧑‍🌾</span> <?php echo htmlspecialchars($lote['operarios_asignados']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($lote['campo_nombre']); ?></td>
                                <td>
                                    <span style="font-weight: 800; color: var(--primary); font-size: 1.1rem;">
                                        <?php echo (int)$animales_presentes; ?>
                                    </span>
                                    <?php if ((int)$animales_presentes !== (int)$lote['cantidad_inicial']): ?>
                                        <span style="font-size: 0.75rem; color: var(--text-muted); opacity: 0.7;">
                                            (de <?php echo (int)$lote['cantidad_inicial']; ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($lote['dieta_nombre'])): ?>
                                        <span style="color: var(--primary); font-weight: 600;">✓ <?php echo htmlspecialchars($lote['dieta_nombre']); ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--danger); font-weight: 700;">⚠ Sin dieta</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <span style="background: var(--bg-main); padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 0.85rem;">
                                        <?php echo (int)$dias_engorde; ?> d
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <span class="badge" style="background: <?php echo $activo ? '#dcfce7' : '#f1f5f9'; ?>; color: <?php echo $activo ? '#166534' : '#475569'; ?>; border: 1px solid <?php echo $activo ? '#bbf7d0' : '#e2e8f0'; ?>;">
                                        <?php echo $activo ? 'Activo' : 'Cerrado'; ?>
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <a href="ver.php?id=<?php echo (int)$lote['id_tropa']; ?>&return_to=<?php echo $return_to; ?>" class="btn btn-secondary btn-action">
                                        <span>👁️</span> <span class="btn-text">Ver</span>
                                    </a>
                                    <a href="editar.php?id=<?php echo (int)$lote['id_tropa']; ?>&return_to=<?php echo $return_to; ?>" class="btn btn-secondary btn-action">
                                        <span>✏️</span> <span class="btn-text">Editar</span>
                                    </a>
                                    <form method="POST" action="toggle_estado.php" style="display:inline;" onsubmit="return confirm('¿Confirmar cambio de estado del lote?')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="id_tropa" value="<?php echo (int)$lote['id_tropa']; ?>">
                                        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="btn-state <?php echo $activo ? 'on' : 'off'; ?>" title="<?php echo $activo ? 'Desactivar' : 'Activar'; ?>">
                                            <?php echo $activo ? '⏸️ Desactivar' : '▶️ Activar'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_paginas > 1): ?>
                <div style="display: flex; justify-content: center; margin-top: 2rem; gap: 0.5rem;">
                    <?php
                    $range = 2;
                    $initial_num = $pagina_actual - $range;
                    $condition_limit_num = ($pagina_actual + $range) + 1;
                    ?>

                    <?php if ($pagina_actual > 1): ?>
                        <a href="?pagina=1<?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">«</a>
                        <a href="?pagina=<?php echo $pagina_actual - 1; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">‹</a>
                    <?php endif; ?>

                    <?php for ($x = $initial_num; $x < $condition_limit_num; $x++): ?>
                        <?php if (($x > 0) && ($x <= $total_paginas)): ?>
                            <?php if ($x == $pagina_actual): ?>
                                <span class="btn btn-primary" style="padding: 0.5rem 1rem; cursor: default;"><?php echo $x; ?></span>
                            <?php else: ?>
                                <a href="?pagina=<?php echo $x; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;"><?php echo $x; ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($pagina_actual < $total_paginas): ?>
                        <a href="?pagina=<?php echo $pagina_actual + 1; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">›</a>
                        <a href="?pagina=<?php echo $total_paginas; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">»</a>
                    <?php endif; ?>
                </div>

                <div style="text-align: center; margin-top: 1rem; color: var(--text-muted); font-size: 0.9rem;">
                    Mostrando <?php echo count($lotes); ?> de <?php echo $total_registros; ?> resultados
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div style="text-align: center; padding: 4rem 2rem; border: 2px dashed var(--border); border-radius: var(--radius); opacity: 0.6;">
                <div style="font-size: 4rem; margin-bottom: 1.5rem;">🐮</div>
                <h2 style="color: var(--text-muted); font-weight: 800;">No hay lotes para mostrar</h2>
                <p style="color: var(--text-muted); margin-bottom: 2rem;">Probá cambiando filtros o creá un nuevo lote.</p>
                <?php if ($estado !== 'inactivos'): ?>
                    <a href="crear.php?return_to=<?php echo $return_to; ?>" class="btn btn-primary btn-lg">
                        <span>➕</span> Crear Lote
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
