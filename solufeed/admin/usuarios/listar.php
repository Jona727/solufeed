<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Solo ADMIN puede gestionar usuarios
verificarAdmin();

$db = getConnection();

$me = (int)($_SESSION['usuario_id'] ?? 0);

// Filtros
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// Normalizar filtros (whitelist)
$allowedTipos = ['ADMIN','CAMPO'];
if ($filtro_tipo && !in_array($filtro_tipo, $allowedTipos, true)) { $filtro_tipo = ''; }
$allowedEstados = ['activo','inactivo'];
if ($filtro_estado && !in_array($filtro_estado, $allowedEstados, true)) { $filtro_estado = ''; }
$busqueda = trim((string)$busqueda);

// Paginación
$por_pagina = 10;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $por_pagina;

// Construir WHERE (mantener visible el usuario ADMIN actual)
if ($filtro_tipo === 'ADMIN') {
    // Solo mostrar el usuario admin actual
    $where = "WHERE id_usuario = ?";
    $params = [$me];
} else {
    // Aplicar filtros solo a usuarios de campo, pero mantener visible el admin actual
    $campo_where = "tipo = 'CAMPO'";
    $campo_params = [];

    if ($filtro_estado === 'activo') {
        $campo_where .= " AND activo = 1";
    } elseif ($filtro_estado === 'inactivo') {
        $campo_where .= " AND activo = 0";
    }

    if ($busqueda) {
        $campo_where .= " AND (nombre LIKE ? OR email LIKE ?)";
        $campo_params[] = "%$busqueda%";
        $campo_params[] = "%$busqueda%";
    }

    $where = "WHERE id_usuario = ? OR ($campo_where)";
    $params = array_merge([$me], $campo_params);
}

// Total para paginación
$stmt = $db->prepare("SELECT COUNT(*) FROM usuario $where");
$stmt->execute($params);
$total_registros = (int)$stmt->fetchColumn();
$total_paginas = (int)ceil($total_registros / $por_pagina);
if ($total_paginas < 1) $total_paginas = 1;
if ($pagina_actual > $total_paginas) {
    $pagina_actual = $total_paginas;
    $offset = ($pagina_actual - 1) * $por_pagina;
}

// Query principal
$sql = "SELECT id_usuario, nombre, email, tipo, activo, fecha_creacion FROM usuario $where";
// Asegurar que el primer registro sea el usuario ADMIN actual
$sql .= " ORDER BY (id_usuario = ?) DESC, fecha_creacion DESC";
$sql .= " LIMIT $por_pagina OFFSET $offset";
$params_data = array_merge($params, [$me]);

$stmt = $db->prepare($sql);
$stmt->execute($params_data);
$usuarios = $stmt->fetchAll();

// Query extra para links de paginación
$query_extra = '';
if ($busqueda !== '') $query_extra .= '&busqueda=' . urlencode($busqueda);
if ($filtro_tipo !== '') $query_extra .= '&tipo=' . urlencode($filtro_tipo);
if ($filtro_estado !== '') $query_extra .= '&estado=' . urlencode($filtro_estado);

// AJAX HANDLER: Retorna solo el contenido de la tabla (incluye paginación)
if (isset($_GET['ajax'])) {
    if (count($usuarios) > 0) {
        ?>
        <div class="usuarios-cards" aria-label="Listado de usuarios (móvil)">
            <?php foreach ($usuarios as $usuario): ?>
                <?php
                    $is_me = ((int)$usuario['id_usuario'] === $me);
                    $is_admin = (($usuario['tipo'] ?? '') === 'ADMIN');
                    $is_campo = (($usuario['tipo'] ?? '') === 'CAMPO');
                    $is_activo = !empty($usuario['activo']);
                ?>
                <div class="usuario-card<?php echo $is_me ? ' me' : ''; ?>">
                    <div class="usuario-card-head">
                        <div class="usuario-card-name">
                            <?php echo htmlspecialchars($usuario['nombre']); ?>
                            <?php if ($is_me): ?>
                                <span class="usuario-me">(Vos)</span>
                            <?php endif; ?>
                        </div>
                        <div class="usuario-card-badges">
                            <span class="badge badge-<?php echo strtolower($usuario['tipo']); ?>">
                                <?php echo $is_admin ? '👔 Admin' : '🧑‍🌾 Campo'; ?>
                            </span>
                            <span class="badge badge-<?php echo $is_activo ? 'activo' : 'inactivo'; ?>">
                                <?php echo $is_activo ? '✓ Activo' : '✕ Inactivo'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="usuario-card-body">
                        <div class="usuario-row">
                            <span class="lbl">Email</span>
                            <span class="val"><?php echo htmlspecialchars($usuario['email']); ?></span>
                        </div>
                        <div class="usuario-row">
                            <span class="lbl">Creación</span>
                            <span class="val"><?php echo formatearFecha($usuario['fecha_creacion']); ?></span>
                        </div>
                    </div>

                    <div class="usuario-card-actions">
                        <a href="editar.php?id=<?php echo (int)$usuario['id_usuario']; ?>" class="btn btn-secondary btn-action">
                            <span>✏️</span> <span class="btn-text">Editar</span>
                        </a>
                        <?php if ($is_campo): ?>
                            <a href="asignar_lotes.php?id=<?php echo (int)$usuario['id_usuario']; ?>" class="btn btn-secondary btn-action">
                                <span>🐮</span> <span class="btn-text">Asignar Lotes</span>
                            </a>
                        <?php endif; ?>
                        <form method="POST" action="toggle_estado.php" style="display:inline;" onsubmit="return confirm('¿Confirmar cambio de estado?')">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="id_usuario" value="<?php echo (int)$usuario['id_usuario']; ?>">
                            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn-state <?php echo $is_activo ? 'on' : 'off'; ?>" title="<?php echo $is_activo ? 'Desactivar' : 'Activar'; ?>">
                                <?php echo $is_activo ? '⏸️ Desactivar' : '▶️ Activar'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>Fecha Creación</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($usuarios as $usuario): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($usuario['nombre']); ?></strong></td>
                        <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                        <td>
                            <span class="badge badge-<?php echo strtolower($usuario['tipo']); ?>">
                                <?php echo $usuario['tipo'] === 'ADMIN' ? '👔 Admin' : '🧑‍🌾 Campo'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo $usuario['activo'] ? 'activo' : 'inactivo'; ?>">
                                <?php echo $usuario['activo'] ? '✓ Activo' : '✕ Inactivo'; ?>
                            </span>
                        </td>
                        <td><?php echo formatearFecha($usuario['fecha_creacion']); ?></td>
                        <td>
                            <div class="actions">
                                <a href="editar.php?id=<?php echo $usuario['id_usuario']; ?>" class="btn-icon" title="Editar">✏️</a>
                                <?php if (($usuario['tipo'] ?? '') === 'CAMPO'): ?>
                                    <a href="asignar_lotes.php?id=<?php echo $usuario['id_usuario']; ?>" class="btn-icon" title="Asignar Lotes" style="background: #e0f2fe; color: #0284c7;">🐮</a>
                                <?php endif; ?>
                                <form method="POST" action="toggle_estado.php" style="display:inline;" onsubmit="return confirm('¿Confirmar cambio de estado?')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="id_usuario" value="<?php echo (int)$usuario['id_usuario']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn-state <?php echo $usuario['activo'] ? 'on' : 'off'; ?>" title="<?php echo $usuario['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                        <?php echo $usuario['activo'] ? '⏸️ Desactivar' : '▶️ Activar'; ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_paginas > 1): ?>
            <div style="display: flex; justify-content: center; margin-top: 1.5rem; gap: 0.5rem; flex-wrap: wrap;">
                <?php
                $range = 2;
                $initial_num = $pagina_actual - $range;
                $condition_limit_num = ($pagina_actual + $range) + 1;
                ?>

                <?php if ($pagina_actual > 1): ?>
                    <a data-page="1" href="?pagina=1<?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">«</a>
                    <a data-page="<?php echo $pagina_actual - 1; ?>" href="?pagina=<?php echo $pagina_actual - 1; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">‹</a>
                <?php endif; ?>

                <?php for ($x = $initial_num; $x < $condition_limit_num; $x++): ?>
                    <?php if (($x > 0) && ($x <= $total_paginas)): ?>
                        <?php if ($x == $pagina_actual): ?>
                            <span class="btn btn-primary" style="padding: 0.5rem 1rem; cursor: default;"><?php echo $x; ?></span>
                        <?php else: ?>
                            <a data-page="<?php echo $x; ?>" href="?pagina=<?php echo $x; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;"><?php echo $x; ?></a>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($pagina_actual < $total_paginas): ?>
                    <a data-page="<?php echo $pagina_actual + 1; ?>" href="?pagina=<?php echo $pagina_actual + 1; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">›</a>
                    <a data-page="<?php echo $total_paginas; ?>" href="?pagina=<?php echo $total_paginas; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">»</a>
                <?php endif; ?>
            </div>
            <div style="text-align: center; margin-top: 0.75rem; color: var(--text-muted); font-size: 0.9rem;">
                Mostrando <?php echo count($usuarios); ?> de <?php echo $total_registros; ?> resultados
            </div>
        <?php endif; ?>
        <?php
    } else {
        ?>
        <div class="empty-state">
            <div class="empty-state-icon">👤</div>
            <h3>No se encontraron usuarios</h3>
            <p>Intenta ajustar los filtros o crea un nuevo usuario</p>
        </div>
        <?php
    }
    exit;
}
?>
<?php
$page_title = 'Gestión de Usuarios';
require_once '../../includes/header.php';
?>
<style>
        .usuarios-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary);
        }

        .stat-card.admin {
            border-left-color: var(--secondary);
        }

        .stat-card.campo {
            border-left-color: var(--accent);
        }

        .stat-card.activos {
            border-left-color: var(--success);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .filters-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-group label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text);
        }

        .filter-group input,
        .filter-group select {
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
        }

        .btn-filter {
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-filter:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-clear {
            background: #6b7280;
        }

        .btn-clear:hover {
            background: #4b5563;
        }

        .usuarios-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        /* Cards (solo móvil) */
        .usuarios-cards { display: none; }

        .usuario-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .usuario-card.me {
            border-color: #bbf7d0;
        }

        .usuario-card-head {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }

        .usuario-card-name {
            font-weight: 900;
            color: var(--text);
            font-size: 1.05rem;
            line-height: 1.2;
        }

        .usuario-me {
            display: inline-block;
            margin-left: 0.35rem;
            font-weight: 800;
            font-size: 0.85rem;
            color: #166534;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            padding: 0.1rem 0.4rem;
            border-radius: 999px;
            vertical-align: middle;
        }

        .usuario-card-badges {
            display: flex;
            gap: 0.35rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .usuario-card-body {
            display: grid;
            gap: 0.5rem;
            margin-bottom: 0.85rem;
        }

        .usuario-row {
            display: flex;
            justify-content: space-between;
            gap: 0.75rem;
            border-top: 1px solid #f1f5f9;
            padding-top: 0.5rem;
        }

        .usuario-row:first-child {
            border-top: 0;
            padding-top: 0;
        }

        .usuario-row .lbl {
            color: var(--text-muted);
            font-weight: 700;
        }

        .usuario-row .val {
            color: var(--text);
            font-weight: 800;
            text-align: right;
        }

        .usuario-card-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
        }

        tbody tr {
            transition: background 0.2s ease;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-admin {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-campo {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-activo {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-inactivo {
            background: #fee2e2;
            color: #991b1b;
        }

        .actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            padding: 0.5rem;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1.2rem;
            transition: transform 0.2s ease;
            border-radius: 6px;
        }

        .btn-icon:hover {
            transform: scale(1.1);
            background: #f1f5f9;
        }

        .btn-nuevo {
            padding: 0.75rem 1.5rem;
            background: var(--success);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-nuevo:hover {
            background: #22c55e;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            font-weight: 500;
        }

        .alert-error {
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            color: #991b1b;
        }

        .alert-success {
            background: #d1fae5;
            border-left: 4px solid var(--success);
            color: #065f46;
        }

        @media (max-width: 768px) {
            /* En móvil mostrar cards y ocultar tabla */
            .usuarios-cards {
                display: grid;
                gap: 1rem;
                padding: 1rem;
            }

            .usuarios-table table { display: none; }

            .usuarios-table {
                overflow: visible;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
<div class="container">
        <div class="usuarios-header">
            <div>
                <h1>👥 Gestión de Usuarios</h1>
                <p style="color: var(--text-muted); margin-top: 0.5rem;">
                    Administra los usuarios del sistema
                </p>
            </div>
            <a href="crear.php" class="btn-nuevo">
                <span>➕</span>
                Nuevo Usuario
            </a>
        </div>

        <!-- Filtros -->
        <div class="filters-section">
            <form method="GET" class="filters-grid">
                <div class="filter-group">
                    <label>Buscar</label>
                    <input type="text" name="busqueda" placeholder="Nombre o email..." 
                           value="<?php echo htmlspecialchars($busqueda); ?>">
                </div>
                <div class="filter-group">
                    <label>Tipo</label>
                    <select name="tipo">
                        <option value="">Todos</option>
                        <option value="ADMIN" <?php echo $filtro_tipo === 'ADMIN' ? 'selected' : ''; ?>>Administrador</option>
                        <option value="CAMPO" <?php echo $filtro_tipo === 'CAMPO' ? 'selected' : ''; ?>>Campo</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="">Todos</option>
                        <option value="activo" <?php echo $filtro_estado === 'activo' ? 'selected' : ''; ?>>Activos</option>
                        <option value="inactivo" <?php echo $filtro_estado === 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                    </select>
                </div>
                <div class="filter-group" style="display: flex; gap: 0.5rem;">
                    <button type="submit" class="btn-filter">🔍 Filtrar</button>
                    <a href="listar.php" class="btn-filter btn-clear" style="text-decoration: none; text-align: center;">🔄 Limpiar</a>
                </div>
            </form>
        </div>

        <!-- Tabla de Usuarios -->
        <div class="usuarios-table">
            <?php if (count($usuarios) > 0): ?>
                <div class="usuarios-cards" aria-label="Listado de usuarios (móvil)">
                    <?php foreach ($usuarios as $usuario): ?>
                        <?php
                            $is_me = ((int)$usuario['id_usuario'] === $me);
                            $is_admin = (($usuario['tipo'] ?? '') === 'ADMIN');
                            $is_campo = (($usuario['tipo'] ?? '') === 'CAMPO');
                            $is_activo = !empty($usuario['activo']);
                        ?>
                        <div class="usuario-card<?php echo $is_me ? ' me' : ''; ?>">
                            <div class="usuario-card-head">
                                <div class="usuario-card-name">
                                    <?php echo htmlspecialchars($usuario['nombre']); ?>
                                    <?php if ($is_me): ?>
                                        <span class="usuario-me">(Vos)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="usuario-card-badges">
                                    <span class="badge badge-<?php echo strtolower($usuario['tipo']); ?>">
                                        <?php echo $is_admin ? '👔 Admin' : '🧑‍🌾 Campo'; ?>
                                    </span>
                                    <span class="badge badge-<?php echo $is_activo ? 'activo' : 'inactivo'; ?>">
                                        <?php echo $is_activo ? '✓ Activo' : '✕ Inactivo'; ?>
                                    </span>
                                </div>
                            </div>

                            <div class="usuario-card-body">
                                <div class="usuario-row">
                                    <span class="lbl">Email</span>
                                    <span class="val"><?php echo htmlspecialchars($usuario['email']); ?></span>
                                </div>
                                <div class="usuario-row">
                                    <span class="lbl">Creación</span>
                                    <span class="val"><?php echo formatearFecha($usuario['fecha_creacion']); ?></span>
                                </div>
                            </div>

                            <div class="usuario-card-actions">
                                <a href="editar.php?id=<?php echo (int)$usuario['id_usuario']; ?>" class="btn btn-secondary btn-action">
                                    <span>✏️</span> <span class="btn-text">Editar</span>
                                </a>
                                <?php if ($is_campo): ?>
                                    <a href="asignar_lotes.php?id=<?php echo (int)$usuario['id_usuario']; ?>" class="btn btn-secondary btn-action">
                                        <span>🐮</span> <span class="btn-text">Asignar Lotes</span>
                                    </a>
                                <?php endif; ?>
                                <form method="POST" action="toggle_estado.php" style="display:inline;" onsubmit="return confirm('¿Confirmar cambio de estado?')">
                                    <?php echo csrf_input(); ?>
                                    <input type="hidden" name="id_usuario" value="<?php echo (int)$usuario['id_usuario']; ?>">
                                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn-state <?php echo $is_activo ? 'on' : 'off'; ?>" title="<?php echo $is_activo ? 'Desactivar' : 'Activar'; ?>">
                                        <?php echo $is_activo ? '⏸️ Desactivar' : '▶️ Activar'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Email</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                            <th>Fecha Creación</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($usuario['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo strtolower($usuario['tipo']); ?>">
                                        <?php echo $usuario['tipo'] === 'ADMIN' ? '👔 Admin' : '🧑‍🌾 Campo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $usuario['activo'] ? 'activo' : 'inactivo'; ?>">
                                        <?php echo $usuario['activo'] ? '✓ Activo' : '✕ Inactivo'; ?>
                                    </span>
                                </td>
                                <td><?php echo formatearFecha($usuario['fecha_creacion']); ?></td>
                                <td>
                                    <div class="actions">
                                        <a href="editar.php?id=<?php echo $usuario['id_usuario']; ?>" 
                                           class="btn-icon" title="Editar">✏️</a>
                                        
                                        <?php if ($usuario['tipo'] === 'CAMPO'): ?>
                                            <a href="asignar_lotes.php?id=<?php echo $usuario['id_usuario']; ?>" 
                                               class="btn-icon" title="Asignar Lotes" style="background: #e0f2fe; color: #0284c7;">
                                                🐮
                                            </a>
                                        <?php endif; ?>

                                        <form method="POST" action="toggle_estado.php" style="display:inline;" onsubmit="return confirm('¿Confirmar cambio de estado?')">
                                            <?php echo csrf_input(); ?>
                                            <input type="hidden" name="id_usuario" value="<?php echo (int)$usuario['id_usuario']; ?>">
                                            <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, "UTF-8"); ?>">
                                            <button type="submit" class="btn-state <?php echo $usuario['activo'] ? 'on' : 'off'; ?>" title="<?php echo $usuario['activo'] ? 'Desactivar' : 'Activar'; ?>">
                                                <?php echo $usuario['activo'] ? '⏸️ Desactivar' : '▶️ Activar'; ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_paginas > 1): ?>
                    <div style="display: flex; justify-content: center; margin-top: 1.5rem; gap: 0.5rem; flex-wrap: wrap;">
                        <?php
                        $range = 2;
                        $initial_num = $pagina_actual - $range;
                        $condition_limit_num = ($pagina_actual + $range) + 1;
                        ?>

                        <?php if ($pagina_actual > 1): ?>
                            <a data-page="1" href="?pagina=1<?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">«</a>
                            <a data-page="<?php echo $pagina_actual - 1; ?>" href="?pagina=<?php echo $pagina_actual - 1; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">‹</a>
                        <?php endif; ?>

                        <?php for ($x = $initial_num; $x < $condition_limit_num; $x++): ?>
                            <?php if (($x > 0) && ($x <= $total_paginas)): ?>
                                <?php if ($x == $pagina_actual): ?>
                                    <span class="btn btn-primary" style="padding: 0.5rem 1rem; cursor: default;"><?php echo $x; ?></span>
                                <?php else: ?>
                                    <a data-page="<?php echo $x; ?>" href="?pagina=<?php echo $x; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;"><?php echo $x; ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($pagina_actual < $total_paginas): ?>
                            <a data-page="<?php echo $pagina_actual + 1; ?>" href="?pagina=<?php echo $pagina_actual + 1; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">›</a>
                            <a data-page="<?php echo $total_paginas; ?>" href="?pagina=<?php echo $total_paginas; ?><?php echo $query_extra; ?>" class="btn btn-secondary" style="padding: 0.5rem 1rem;">»</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div style="text-align: center; margin-top: 0.75rem; color: var(--text-muted); font-size: 0.9rem;">
                    Mostrando <?php echo count($usuarios); ?> de <?php echo $total_registros; ?> resultados
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">👤</div>
                    <h3>No se encontraron usuarios</h3>
                    <p>Intenta ajustar los filtros o crea un nuevo usuario</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.querySelector('input[name="busqueda"]');
            const typeSelect = document.querySelector('select[name="tipo"]');
            const statusSelect = document.querySelector('select[name="estado"]');
            const tableContainer = document.querySelector('.usuarios-table');
            const form = document.querySelector('.filters-section form');

            let currentPage = Number(new URLSearchParams(window.location.search).get('pagina') || '1');

            // Función de debounce para evitar muchas peticiones
            function debounce(func, wait) {
                let timeout;
                return function executedFunction(...args) {
                    const later = () => {
                        clearTimeout(timeout);
                        func(...args);
                    };
                    clearTimeout(timeout);
                    timeout = setTimeout(later, wait);
                };
            }

            // Función principal de búsqueda
            async function performSearch(page = 1) {
                const pageNum = Number(page) || 1;
                currentPage = pageNum;
                const params = new URLSearchParams({
                    ajax: '1',
                    busqueda: searchInput.value,
                    tipo: typeSelect.value,
                    estado: statusSelect.value,
                    pagina: String(pageNum)
                });

                // Efecto visual de carga
                tableContainer.style.opacity = '0.5';
                tableContainer.style.transition = 'opacity 0.2s';

                try {
                    const response = await fetch(`listar.php?${params.toString()}`);
                    if (!response.ok) throw new Error('Error en la petición');
                    
                    const html = await response.text();
                    tableContainer.innerHTML = html;
                    
                    // Actualizar URL sin recargar (opcional, para que al refrescar se mantenga)
                    const urlParams = new URLSearchParams(params);
                    urlParams.delete('ajax'); // No queremos ajax=1 en la URL visible
                    window.history.replaceState({}, '', `${window.location.pathname}?${urlParams.toString()}`);
                    
                } catch (error) {
                    console.error('Error:', error);
                    // Si falla, no hacemos nada crítico, solo log
                } finally {
                    tableContainer.style.opacity = '1';
                }
            }

            // Aplicar debounce a la búsqueda por texto (300ms)
            const debouncedSearch = debounce(() => {
                performSearch(1);
            }, 300);

            // Listeners
            if (searchInput) {
                searchInput.addEventListener('input', debouncedSearch);
                // Prevenir envío de formulario tradicional con Enter
                searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        debouncedSearch();
                    }
                });
            }

            if (typeSelect) {
                typeSelect.addEventListener('change', () => performSearch(1));
            }

            if (statusSelect) {
                statusSelect.addEventListener('change', () => performSearch(1));
            }

            // Interceptar el botón de "Filtrar" para que use AJAX también
            if (form) {
                form.addEventListener('submit', (e) => {
                    e.preventDefault();
                    performSearch(1);
                });
            }
            
            

            // Paginación (links con data-page dentro de .usuarios-table)
            if (tableContainer) {
                tableContainer.addEventListener('click', (e) => {
                    const a = e.target.closest('a[data-page]');
                    if (!a) return;
                    e.preventDefault();
                    const page = Number(a.getAttribute('data-page') || '1');
                    performSearch(page);
                });
            }

            // Botón Limpiar
            const cleanBtn = document.querySelector('.btn-clear');
            if (cleanBtn) {
                cleanBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    searchInput.value = '';
                    typeSelect.value = '';
                    statusSelect.value = '';
                    performSearch(1);
                });
            }
        });
    </script>

<?php require_once '../../includes/footer.php'; ?>
