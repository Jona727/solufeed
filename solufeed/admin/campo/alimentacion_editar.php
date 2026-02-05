<?php
// admin/campo/alimentacion_editar.php
// Permite al usuario CAMPO editar una alimentación propia (consumo_lote + detalle)

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

verificarCampo();

$db = getConnection();
$page_title = 'Editar Alimentación';

$id_usuario = (int)($_SESSION['usuario_id'] ?? 0);
$id_consumo = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$return_to = isset($_GET['return_to']) ? $_GET['return_to'] : (BASE_URL . '/admin/campo/historial.php');

$return_to = safe_return_to($return_to, BASE_URL . '/admin/campo/historial.php');
if ($id_consumo <= 0) {
    header('Location: ' . BASE_URL . '/admin/campo/historial.php');
    exit();
}

// Obtener registro y validar ownership
$stmt = $db->prepare("
    SELECT cl.*, t.nombre AS lote_nombre, c.nombre AS campo_nombre
    FROM consumo_lote cl
    INNER JOIN tropa t ON cl.id_tropa = t.id_tropa
    LEFT JOIN campo c ON t.id_campo = c.id_campo
    WHERE cl.id_consumo = ? AND cl.id_usuario = ?
    LIMIT 1
");
$stmt->execute([$id_consumo, $id_usuario]);
$consumo = $stmt->fetch();

if (!$consumo) {
    http_response_code(404);
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="card"><h1 class="card-title">No encontrado</h1><p>Este registro no existe o no te pertenece.</p></div>';
    require_once __DIR__ . '/../../includes/footer.php';
    exit();
}

// Dieta vigente para sugeridos (usa la fecha del registro)
$dieta = obtenerDietaVigente((int)$consumo['id_tropa'], $consumo['fecha']);

// Insumos de dieta (si existe), caso contrario, usar los del detalle existente
$insumos = [];
$detalle_map = [];

$stmt = $db->prepare("SELECT cld.id_insumo, cld.kg_sugeridos, cld.kg_reales FROM consumo_lote_detalle cld WHERE cld.id_consumo = ?");
$stmt->execute([$id_consumo]);
foreach ($stmt->fetchAll() as $d) {
    $detalle_map[(int)$d['id_insumo']] = $d;
}

if ($dieta) {
    $stmt = $db->prepare("
        SELECT i.id_insumo, i.nombre, i.tipo, i.porcentaje_ms, dd.porcentaje_teorico
        FROM dieta_detalle dd
        INNER JOIN insumo i ON dd.id_insumo = i.id_insumo
        WHERE dd.id_dieta = ?
        ORDER BY dd.porcentaje_teorico DESC
    ");
    $stmt->execute([(int)$dieta['id_dieta']]);
    $insumos = $stmt->fetchAll();
} else {
    $stmt = $db->prepare("
        SELECT i.id_insumo, i.nombre, i.tipo, i.porcentaje_ms, NULL as porcentaje_teorico
        FROM consumo_lote_detalle cld
        INNER JOIN insumo i ON cld.id_insumo = i.id_insumo
        WHERE cld.id_consumo = ?
        ORDER BY i.nombre ASC
    ");
    $stmt->execute([$id_consumo]);
    $insumos = $stmt->fetchAll();
}

$errores = [];
$exito = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_edicion'])) {
    // CSRF
    if (!csrf_verify_post()) {
        $errores[] = csrf_fail_response('Token expirado o inválido. Actualizá la página y reintentá.');
    }

    // Revalidar ownership
    if (!obtenerConsumoDeUsuarioCampo($id_consumo, $id_usuario)) {
        $errores[] = 'No tenés permisos para editar este registro.';
    }

    $hora = isset($_POST['hora']) ? limpiarDato($_POST['hora']) : '';
    $sobrante_nivel = isset($_POST['sobrante_nivel']) ? limpiarDato($_POST['sobrante_nivel']) : '';


    // Normalizar valores legacy (compatibilidad)
    $legacy_map = ['NADA' => 'SIN_SOBRAS', 'POCO' => 'POCAS_SOBRAS', 'MUCHO' => 'MUCHAS_SOBRAS'];
    if (isset($legacy_map[$sobrante_nivel])) {
        $sobrante_nivel = $legacy_map[$sobrante_nivel];
    }

    $valid_sobras = ['SIN_SOBRAS', 'POCAS_SOBRAS', 'NORMAL', 'MUCHAS_SOBRAS'];
    if ($sobrante_nivel === '') {
        $sobrante_nivel = 'NORMAL';
    }
    if (!in_array($sobrante_nivel, $valid_sobras, true)) {
        $errores[] = 'Nivel de sobras inválido.';
    }
    $kg_totales = isset($_POST['kg_totales']) ? (float)$_POST['kg_totales'] : 0;
    $kg_reales = $_POST['kg_real'] ?? [];

    if ($kg_totales <= 0) {
        $errores[] = 'Los kg totales deben ser mayores a 0.';
    }
    if ($hora === '') {
        $errores[] = 'La hora es obligatoria.';
    }

    $hay_insumos = false;
    $suma = 0.0;
    foreach ($kg_reales as $kg) {
        $kg = (float)$kg;
        if ($kg > 0) {
            $hay_insumos = true;
            $suma += $kg;
        }
    }
    if (!$hay_insumos) {
        $errores[] = 'Debés ingresar kg reales para al menos un insumo.';
    }
    if ($kg_totales > 0) {
        $margen = $kg_totales * 0.05;
        if (abs($suma - $kg_totales) > $margen) {
            $errores[] = 'La diferencia entre total y suma de insumos supera el 5% permitido.';
        }
    }

    if (empty($errores)) {
        try {
            $db->beginTransaction();

            // Update cabecera (fecha NO se modifica aquí para evitar efectos colaterales)
            $stmt = $db->prepare("
                UPDATE consumo_lote
                SET hora = ?, sobrante_nivel = ?, kg_totales_tirados = ?
                WHERE id_consumo = ? AND id_usuario = ?
            ");
            $stmt->execute([$hora, $sobrante_nivel, $kg_totales, $id_consumo, $id_usuario]);

            // Limpiar detalle y reinsertar según formulario
            $stmt = $db->prepare("DELETE FROM consumo_lote_detalle WHERE id_consumo = ?");
            $stmt->execute([$id_consumo]);

            // Map de teorico y ms
            $teorico = [];
            $ms = [];
            foreach ($insumos as $i) {
                $iid = (int)$i['id_insumo'];
                $teorico[$iid] = isset($i['porcentaje_teorico']) ? (float)$i['porcentaje_teorico'] : null;
                $ms[$iid] = isset($i['porcentaje_ms']) ? (float)$i['porcentaje_ms'] : 0.0;
            }

            $stmtIns = $db->prepare("
                INSERT INTO consumo_lote_detalle (id_consumo, id_insumo, kg_sugeridos, kg_reales, porcentaje_real, kg_ms)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            foreach ($kg_reales as $id_insumo => $kg_real_raw) {
                $id_insumo = (int)$id_insumo;
                $kg_real = (float)$kg_real_raw;
                if ($id_insumo <= 0 || $kg_real <= 0) {
                    continue;
                }

                $porc_teo = $teorico[$id_insumo] ?? null;
                $kg_sugeridos = ($porc_teo !== null) ? (($porc_teo * $kg_totales) / 100.0) : 0.0;
                $porcentaje_real = ($kg_totales > 0) ? (($kg_real / $kg_totales) * 100.0) : 0.0;
                $kg_ms = ($kg_real * ($ms[$id_insumo] ?? 0.0)) / 100.0;

                $stmtIns->execute([$id_consumo, $id_insumo, $kg_sugeridos, $kg_real, $porcentaje_real, $kg_ms]);
            }

            $db->commit();

            // Redirigir a donde estaba (solo rutas internas)
            $dest = safe_return_to($return_to, BASE_URL . '/admin/campo/historial.php');
            header('Location: ' . $dest);
            exit();

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errores[] = 'No se pudo guardar la edición.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card" style="margin-bottom: 1.5rem;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap: 1rem; flex-wrap: wrap;">
        <div>
            <h1 class="card-title" style="margin:0;">✏️ Editar Alimentación</h1>
            <p style="margin:.25rem 0 0 0; color: var(--text-muted);">
                Lote: <strong><?php echo htmlspecialchars($consumo['lote_nombre']); ?></strong> ·
                Fecha: <strong><?php echo htmlspecialchars(formatearFecha($consumo['fecha'])); ?></strong>
            </p>
        </div>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">← Volver</a>
    </div>
</div>

<?php if (!empty($errores)): ?>
    <div class="card" style="background: #fee2e2; border-left: 5px solid var(--danger); color: #991b1b; padding: 1rem; margin-bottom: 1.5rem;">
        <strong style="display:block; margin-bottom:.5rem;">Se encontraron errores:</strong>
        <ul style="margin:0; padding-left:1.25rem;">
            <?php foreach ($errores as $e): ?>
                <li><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <form method="POST" class="formulario">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="guardar_edicion" value="1">
        <input type="hidden" name="id" value="<?php echo (int)$id_consumo; ?>">

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
            <div class="form-grupo">
                <label>Fecha (no editable)</label>
                <input type="text" value="<?php echo htmlspecialchars($consumo['fecha']); ?>" disabled>
            </div>
            <div class="form-grupo">
                <label for="hora">Hora *</label>
                <input type="time" id="hora" name="hora" value="<?php echo htmlspecialchars(substr((string)$consumo['hora'],0,5)); ?>" required>
            </div>
            <div class="form-grupo">
                <label for="kg_totales">Kg totales tirados *</label>
                <input type="number" step="0.01" min="0" id="kg_totales" name="kg_totales" value="<?php echo htmlspecialchars((string)$consumo['kg_totales_tirados']); ?>" required>
            </div>
            <div class="form-grupo">
                <label for="sobrante_nivel">Nivel de sobras</label>
                <select id="sobrante_nivel" name="sobrante_nivel">
                    <?php
                    $niveles = [
                        'SIN_SOBRAS'    => '🟢 Sin sobras',
                        'POCAS_SOBRAS'  => '🟡 Pocas sobras',
                        'NORMAL'        => '🔵 Normal',
                        'MUCHAS_SOBRAS' => '🔴 Muchas sobras',
                    ];
                    $actual = (string)($consumo['sobrante_nivel'] ?? 'NORMAL');
                    $legacy_map = ['NADA' => 'SIN_SOBRAS', 'POCO' => 'POCAS_SOBRAS', 'MUCHO' => 'MUCHAS_SOBRAS'];
                    if (isset($legacy_map[$actual])) { $actual = $legacy_map[$actual]; }
                    foreach ($niveles as $val => $label) {
                        $sel = ($actual === $val) ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($val) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div style="margin-top: 1.25rem;">
            <h2 class="card-title">🥣 Insumos</h2>
            <p style="margin: .25rem 0 1rem 0; color: var(--text-muted);">Editá los kg reales. Podés dejar en 0 lo que no se usó.</p>
            <table class="tabla responsive-table">
                <thead>
                    <tr>
                        <th>Insumo</th>
                        <th>% Teórico</th>
                        <th>Kg real</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($insumos as $i):
                        $iid = (int)$i['id_insumo'];
                        $kgActual = isset($detalle_map[$iid]) ? (float)$detalle_map[$iid]['kg_reales'] : 0.0;
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($i['nombre']); ?></strong>
                            <div style="color: var(--text-muted); font-size: .85rem;"><?php echo htmlspecialchars($i['tipo'] ?? ''); ?></div>
                        </td>
                        <td><?php echo ($i['porcentaje_teorico'] !== null) ? number_format((float)$i['porcentaje_teorico'], 2) . '%' : '-'; ?></td>
                        <td>
                            <input type="number" step="0.01" min="0" name="kg_real[<?php echo $iid; ?>]" value="<?php echo htmlspecialchars((string)$kgActual); ?>" style="max-width: 160px;">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 1.25rem; display:flex; gap: .75rem; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary">Guardar cambios</button>
            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">Cancelar</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
