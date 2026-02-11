<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarAdmin();

$page_title = 'Establecimientos';
$db = getConnection();

// Compatibilidad por si alguna pantalla antigua redirige con ?msg=...
if (isset($_GET['msg']) && is_string($_GET['msg'])) {
    $msg = (string)$_GET['msg'];
    if ($msg === 'creado') {
        flash_set('success', 'Establecimiento creado correctamente.');
    } elseif ($msg === 'editado') {
        flash_set('success', 'Establecimiento actualizado correctamente.');
    }
}

$return_to = urlencode($_SERVER['REQUEST_URI']);

// Configuración de paginación
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

$estado = isset($_GET['estado']) ? (string)$_GET['estado'] : '';
$orden  = isset($_GET['orden']) ? (string)$_GET['orden'] : '';
$busqueda = isset($_GET['busqueda']) ? trim((string)$_GET['busqueda']) : '';

$allowedEstado = ['activos','inactivos','todos'];
if ($estado === '' || !in_array($estado, $allowedEstado, true)) $estado = 'activos';

$allowedOrden = ['recientes','nombre'];
if ($orden === '' || !in_array($orden, $allowedOrden, true)) $orden = 'recientes';

$where = 'WHERE 1=1';
$params = [];

if ($estado !== 'todos') {
    $where .= ' AND c.activo = :activo';
    $params[':activo'] = ($estado === 'activos') ? 1 : 0;
}

if ($busqueda !== '') {
    $where .= ' AND (c.nombre LIKE :b1 OR c.ubicacion LIKE :b2)';
    $params[':b1'] = '%' . $busqueda . '%';
    $params[':b2'] = '%' . $busqueda . '%';
}

// Total para paginación
$stmt_count = $db->prepare('SELECT COUNT(*) FROM campo c ' . $where);
$stmt_count->execute($params);
$total_registros = (int)$stmt_count->fetchColumn();
$total_paginas = (int)ceil($total_registros / $registros_por_pagina);
if ($total_paginas < 1) $total_paginas = 1;
if ($pagina_actual > $total_paginas) $pagina_actual = $total_paginas;

$offset = ($pagina_actual - 1) * $registros_por_pagina;

$orderSql = ($orden === 'nombre') ? 'c.nombre ASC' : 'c.id_campo DESC';

// Subquery de métricas de lotes/animales por establecimiento
$statsSub = "SELECT id_campo, COUNT(DISTINCT id_tropa) AS total_lotes, COALESCE(SUM(cantidad_inicial),0) AS total_animales\n            FROM tropa\n            WHERE activo = 1\n            GROUP BY id_campo";

$sql_final = "SELECT c.id_campo, c.nombre, c.ubicacion, c.activo,\n                     COALESCE(s.total_lotes,0) AS total_lotes,\n                     COALESCE(s.total_animales,0) AS total_animales\n              FROM campo c\n              LEFT JOIN ({$statsSub}) s ON s.id_campo = c.id_campo\n              {$where}\n              ORDER BY {$orderSql}\n              LIMIT :limit OFFSET :offset";

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
$establecimientos = $stmt->fetchAll();

$query_extra = '&estado=' . urlencode($estado) . '&orden=' . urlencode($orden) . (!empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : '');

require_once '../../includes/header.php';
?>

<div class="insumos-container">
    <div class="page-header">
        <div>
            <h1 style="font-weight: 800; color: var(--primary); margin: 0; letter-spacing: -1px;">
                🏭 Establecimientos
            </h1>
            <p style="margin: 0.25rem 0 0 0; color: var(--text-muted); font-size: 0.95rem; font-weight: 500;">
                Gestioná los campos y sus unidades de producción
            </p>
        </div>
        <div class="header-actions">
            <div class="segmented" aria-label="Filtro de estado">
                <a href="listar.php?estado=activos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'activos' ? 'active' : ''; ?>">Activos</a>
                <a href="listar.php?estado=inactivos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'inactivos' ? 'active' : ''; ?>">Inactivos</a>
                <a href="listar.php?estado=todos&orden=<?php echo urlencode($orden); ?><?php echo !empty($busqueda) ? '&busqueda=' . urlencode($busqueda) : ''; ?>" class="<?php echo $estado === 'todos' ? 'active' : ''; ?>">Todos</a>
            </div>

            <a href="crear.php?return_to=<?php echo $return_to; ?>" class="btn btn-primary btn-sm">
                <span>➕</span> Nuevo Establecimiento
            </a>
        </div>
    </div>

    <div class="filters-bar">
        <form method="GET" action="listar.php" class="filters-row">
            <div class="filters-left">
                <input type="hidden" name="estado" value="<?php echo htmlspecialchars($estado); ?>">
                <input type="hidden" name="pagina" value="1">

                <div style="position: relative;">
                    <span style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); font-size: 1.1rem;">🔍</span>
                    <input class="filter-input" type="text" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Buscar por nombre o ubicación..." style="padding-left: 2.6rem;">
                </div>

                <select class="filter-select" name="orden">
                    <option value="recientes" <?php echo $orden === 'recientes' ? 'selected' : ''; ?>>Más recientes primero</option>
                    <option value="nombre" <?php echo $orden === 'nombre' ? 'selected' : ''; ?>>Nombre (A → Z)</option>
                </select>

                <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>

                <?php if (!empty($busqueda)): ?>
                    <a class="btn btn-secondary btn-sm" href="listar.php?estado=<?php echo urlencode($estado); ?>&orden=<?php echo urlencode($orden); ?>">Limpiar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title"><span>📋</span> Lista de Establecimientos</h3>

        <?php if (count($establecimientos) > 0): ?>
            <div class="table-container">
                <table class="responsive-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Ubicación</th>
                            <th style="text-align:center;">Lotes</th>
                            <th style="text-align:center;">Cabezas</th>
                            <th>Estado</th>
                            <th class="th-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($establecimientos as $campo): ?>
                            <?php $activo = ((int)$campo['activo'] === 1); ?>
                            <tr>
                                <td>
                                    <strong style="color: var(--primary); font-size: 1.05rem;">
                                        <?php echo htmlspecialchars($campo['nombre']); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if (!empty($campo['ubicacion'])): ?>
                                        <?php echo htmlspecialchars($campo['ubicacion']); ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: 800;">
                                    <span style="display:block; text-align:center;">
                                        <?php echo (int)$campo['total_lotes']; ?>
                                    </span>
                                </td>
                                <td style="font-weight: 800;">
                                    <span style="display:block; text-align:center;">
                                        <?php echo number_format((int)$campo['total_animales']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge" style="background: <?php echo $activo ? '#dcfce7' : '#fff7ed'; ?>; color: <?php echo $activo ? '#166534' : '#9a3412'; ?>; border: 1px solid <?php echo $activo ? '#bbf7d0' : '#ffedd5'; ?>;">
                                        <?php echo $activo ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <?php if ($activo): ?>
                                        <a href="gestionar_lotes.php?id=<?php echo (int)$campo['id_campo']; ?>&return_to=<?php echo $return_to; ?>" class="btn btn-secondary btn-action">
                                            <span>🐮</span> <span class="btn-text">Lotes</span>
                                        </a>
                                    <?php else: ?>
                                        <span class="btn btn-secondary btn-action" style="opacity: 0.5; cursor: not-allowed;" title="Activá el establecimiento para asignar lotes">
                                            <span>🐮</span> <span class="btn-text">Lotes</span>
                                        </span>
                                    <?php endif; ?>

                                    <a href="editar.php?id=<?php echo (int)$campo['id_campo']; ?>&return_to=<?php echo $return_to; ?>" class="btn btn-secondary btn-action">
                                        <span>✏️</span> <span class="btn-text">Editar</span>
                                    </a>

                                    <form method="POST" action="toggle_estado.php" style="display:inline;" onsubmit="return confirm('¿Confirmar cambio de estado del establecimiento?')">
                                        <?php echo csrf_input(); ?>
                                        <input type="hidden" name="id_campo" value="<?php echo (int)$campo['id_campo']; ?>">
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
                    Mostrando <?php echo count($establecimientos); ?> de <?php echo $total_registros; ?> resultados
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div style="text-align: center; padding: 4rem 2rem; border: 2px dashed var(--border); border-radius: var(--radius); opacity: 0.6;">
                <div style="font-size: 4rem; margin-bottom: 1.5rem;">🏭</div>
                <h2 style="color: var(--text-muted); font-weight: 800;">No se encontraron resultados</h2>
                <p style="color: var(--text-muted); margin-bottom: 2rem;">
                    <?php if (!empty($busqueda)): ?>
                        No hay establecimientos que coincidan con "<strong><?php echo htmlspecialchars($busqueda); ?></strong>".
                        <br><a href="listar.php?estado=<?php echo urlencode($estado); ?>&orden=<?php echo urlencode($orden); ?>" style="color: var(--primary); font-weight: 700;">Limpiar búsqueda</a>
                    <?php else: ?>
                        Creá el primer establecimiento para comenzar.
                    <?php endif; ?>
                </p>

                <?php if (empty($busqueda)): ?>
                    <a href="crear.php?return_to=<?php echo $return_to; ?>" class="btn btn-primary btn-lg">
                        <span>➕</span> Crear Primer Establecimiento
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
