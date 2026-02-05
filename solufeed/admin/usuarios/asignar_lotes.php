<?php
require_once '../../config/database.php';
require_once '../../includes/functions.php';

verificarAdmin();

$db = getConnection();
$mensaje = '';
$tipo_mensaje = '';

// Obtener ID del usuario
$id_usuario = (int)($_GET['id'] ?? 0);

if (!$id_usuario) {
    header('Location: listar.php');
    exit();
}

// Obtener datos del usuario
$stmt = $db->prepare("SELECT * FROM usuario WHERE id_usuario = ?");
$stmt->execute([$id_usuario]);
$usuario = $stmt->fetch();

if (!$usuario) {
    header('Location: listar.php');
    exit();
}

// Verificar que sea tipo CAMPO (opcional, pero recomendado)
if ($usuario['tipo'] !== 'CAMPO') {
    // Si queremos permitir asignar a admin también para testing, quitamos esto.
    // Pero la lógica de negocio dice que los admin ven todo, así que esto es redundante para admins.
    // Dejaremos una advertencia visual en lugar de bloquear.
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify_post()) {
        $mensaje = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
        $tipo_mensaje = 'error';
    } else {
        try {
            $db->beginTransaction();

            // 1. Borrar asignaciones existentes
            $stmt = $db->prepare("DELETE FROM usuario_tropa WHERE id_usuario = ?");
            $stmt->execute([$id_usuario]);

            // 2. Insertar nuevas asignaciones
            if (isset($_POST['lotes']) && is_array($_POST['lotes'])) {
                $stmt = $db->prepare("INSERT INTO usuario_tropa (id_usuario, id_tropa) VALUES (?, ?)");
                foreach ($_POST['lotes'] as $id_tropa) {
                    $id_tropa = (int)$id_tropa;
                    if ($id_tropa > 0) {
                        $stmt->execute([$id_usuario, $id_tropa]);
                    }
                }
            }

            $db->commit();

            $mensaje = "Asignaciones actualizadas correctamente.";
            $tipo_mensaje = "success";

        } catch (Exception $e) {
            if ($db && $db->inTransaction()) {
                $db->rollBack();
            }
            log_event('ERROR', 'USUARIO_ASIGNAR_LOTES_FAILED', ['error' => $e->getMessage()]);
            $mensaje = 'Error al guardar la asignación. Reintentá o contactá soporte.';
            $tipo_mensaje = "error";
        }
    }
}

// Obtener campos activos (para filtros)
$stmt = $db->query("SELECT id_campo, nombre, ubicacion FROM campo WHERE activo = 1 ORDER BY nombre ASC");
$campos_activos = $stmt->fetchAll();

// Obtener todos los lotes activos (incluye campo/ubicación para filtros)
$stmt = $db->query("
    SELECT 
        t.id_tropa, 
        t.nombre,
        t.id_campo,
        c.nombre as nombre_campo,
        c.ubicacion as ubicacion_campo
    FROM tropa t 
    LEFT JOIN campo c ON t.id_campo = c.id_campo
    WHERE t.activo = 1 
    ORDER BY c.nombre ASC, t.nombre ASC
");
$lotes_activos = $stmt->fetchAll();

// Obtener lotes ya asignados
$stmt = $db->prepare("SELECT id_tropa FROM usuario_tropa WHERE id_usuario = ?");
$stmt->execute([$id_usuario]);
$lotes_asignados = $stmt->fetchAll(PDO::FETCH_COLUMN);

?>
<?php
$page_title = 'Asignar Lotes';
require_once '../../includes/header.php';
?>
<style>
        .assignment-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .user-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1.5rem;
            border-left: 5px solid var(--primary);
        }
        
        .user-avatar {
            font-size: 3rem;
            background: #f0fdf4;
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .lotes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .lote-option {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .lote-option:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        /* Checkbox oculto pero funcional */
        .lote-option input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--primary);
        }
        
        /* Estilos cuando está seleccionado */
        .lote-option.selected {
            background: #f0fdf4;
            border-color: var(--primary);
        }
        
        .lote-info h4 {
            margin: 0;
            color: var(--text);
            font-size: 1rem;
        }
        
        .lote-info small {
            color: var(--text-muted);
        }
        .actions-bar {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            margin-bottom: 1.25rem;
        }

        .filters {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .filters select,
        .filters input[type="text"] {
            padding: 0.65rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            min-width: 220px;
            font-weight: 600;
        }

        .small-btn {
            border: none;
            background: #f1f5f9;
            color: #0f172a;
            padding: 0.55rem 0.75rem;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 800;
            transition: transform 0.12s ease;
        }

        .small-btn:hover { transform: translateY(-1px); }

        .small-btn.primary { background: var(--primary); color: #fff; }

        @media (max-width: 600px) {
            .filters select,
            .filters input[type="text"] { min-width: 160px; }
        }
        
        .btn-save {
            background: var(--primary);
            color: white;
            padding: 1rem 2rem;
            border-radius: 8px;
            border: none;
            font-weight: bold;
            font-size: 1rem;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .btn-save:hover {
            background: var(--primary-dark);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }
        
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .alert-warning { background: #fef3c7; color: #92400e; }
    </style>
<div class="container assignment-container">
        
        <div style="margin-bottom: 1.5rem;">
            <a href="listar.php" style="text-decoration: none; color: var(--text-muted); font-weight: 500;">← Volver al listado</a>
        </div>

        <h1>📋 Asignación de Lotes</h1>
        <p style="color: var(--text-muted); margin-bottom: 2rem;">Selecciona los lotes que este operario podrá visualizar y gestionar.</p>

        <?php if ($mensaje): ?>
            <div class="alert alert-<?php echo $tipo_mensaje; ?>">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <div class="user-card">
            <div class="user-avatar">
                👤
            </div>
            <div>
                <h2 style="margin: 0; margin-bottom: 0.25rem;"><?php echo htmlspecialchars($usuario['nombre']); ?></h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <span style="background: #e2e8f0; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">
                        <?php echo $usuario['tipo']; ?>
                    </span>
                    <span style="color: var(--text-muted);"><?php echo htmlspecialchars($usuario['email']); ?></span>
                </div>
            </div>
        </div>

        <?php if ($usuario['tipo'] === 'ADMIN'): ?>
            <div class="alert alert-warning">
                ⚠️ <strong>Nota:</strong> Este usuario es ADMINISTRADOR. Por defecto tiene acceso a todos los lotes, la asignación aquí no restringirá su acceso, pero se guardará por si su rol cambia a futuro.
            </div>
        <?php endif; ?>

        <form method="POST">
                <?php echo csrf_input(); ?>
            <div class="actions-bar">
                <div class="filters">
                    <div style="font-weight: 800; color: var(--text);">
                        <span id="lotesCountTotal"><?php echo count($lotes_activos); ?></span> lotes (mostrando <span id="lotesCountVisible"><?php echo count($lotes_activos); ?></span>)
                    </div>

                    <select id="filtroCampo" aria-label="Filtrar por establecimiento">
                        <option value="">📍 Todos los establecimientos</option>
                        <?php foreach ($campos_activos as $c): ?>
                            <option value="<?php echo (int)$c['id_campo']; ?>">
                                <?php echo htmlspecialchars($c['nombre']); ?><?php echo !empty($c['ubicacion']) ? ' — ' . htmlspecialchars($c['ubicacion']) : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="text" id="buscarLote" placeholder="Buscar por nombre/ubicación..." autocomplete="off">

                    <button type="button" class="small-btn" id="btnSelVisibles">Seleccionar visibles</button>
                    <button type="button" class="small-btn" id="btnDesVisibles">Desmarcar visibles</button>
                </div>

                <div style="display: flex; gap: 0.5rem; align-items: center;">
                    <a href="listar.php" class="small-btn">Cancelar</a>
                    <button type="submit" class="btn-save">💾 Guardar</button>
                </div>
            </div>

            <div class="lotes-grid">
                <?php foreach ($lotes_activos as $lote): ?>
                    <?php $isChecked = in_array($lote['id_tropa'], $lotes_asignados); ?>
                    <label class="lote-option <?php echo $isChecked ? 'selected' : ''; ?>" data-campo-id="<?php echo (int)($lote['id_campo'] ?? 0); ?>" data-nombre="<?php echo htmlspecialchars($lote['nombre'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-campo-nombre="<?php echo htmlspecialchars($lote['nombre_campo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-ubicacion="<?php echo htmlspecialchars($lote['ubicacion_campo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="checkbox" name="lotes[]" value="<?php echo $lote['id_tropa']; ?>" 
                               <?php echo $isChecked ? 'checked' : ''; ?>
                               onchange="updateVisuals()">
                        <div class="lote-info">
                            <h4><?php echo htmlspecialchars($lote['nombre']); ?></h4>
                            <small>📍 <?php echo htmlspecialchars($lote['nombre_campo'] ?? 'Sin campo'); ?><?php echo !empty($lote['ubicacion_campo']) ? ' — ' . htmlspecialchars($lote['ubicacion_campo']) : ''; ?></small>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </form>
    </div>

    <script>
        function updateVisuals() {
            document.querySelectorAll('.lote-option input').forEach(input => {
                if (input.checked) {
                    input.closest('.lote-option').classList.add('selected');
                } else {
                    input.closest('.lote-option').classList.remove('selected');
                }
            });
        }

        function applyFilters() {
            const campoSel = document.getElementById('filtroCampo');
            const buscar = document.getElementById('buscarLote');
            const campo = campoSel ? campoSel.value : '';
            const q = (buscar ? buscar.value : '').trim().toLowerCase();

            let visible = 0;
            document.querySelectorAll('.lote-option').forEach(opt => {
                const campoId = (opt.dataset.campoId || '').toString();
                const nombre = (opt.dataset.nombre || '').toLowerCase();
                const campoNombre = (opt.dataset.campoNombre || '').toLowerCase();
                const ubic = (opt.dataset.ubicacion || '').toLowerCase();

                const matchCampo = !campo || campoId === campo;
                const matchText = !q || nombre.includes(q) || campoNombre.includes(q) || ubic.includes(q);

                const show = matchCampo && matchText;
                opt.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            const v = document.getElementById('lotesCountVisible');
            if (v) v.textContent = String(visible);
        }

        function selectVisible(checked) {
            document.querySelectorAll('.lote-option').forEach(opt => {
                if (opt.style.display === 'none') return;
                const cb = opt.querySelector('input[type="checkbox"]');
                if (cb) cb.checked = checked;
            });
            updateVisuals();
        }

        // Inicializar
        updateVisuals();
        applyFilters();

        const campoSel = document.getElementById('filtroCampo');
        const buscar = document.getElementById('buscarLote');
        if (campoSel) campoSel.addEventListener('change', applyFilters);
        if (buscar) buscar.addEventListener('input', applyFilters);

        const btnSel = document.getElementById('btnSelVisibles');
        const btnDes = document.getElementById('btnDesVisibles');
        if (btnSel) btnSel.addEventListener('click', () => selectVisible(true));
        if (btnDes) btnDes.addEventListener('click', () => selectVisible(false));
    </script>

<?php require_once '../../includes/footer.php'; ?>
