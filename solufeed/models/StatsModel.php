<?php
require_once __DIR__ . '/../config/database.php';

class StatsModel {
    private $db;

    public function __construct() {
        $this->db = getConnection();
    }

    // Indicadores Principales
    public function getMainIndicators() {
        return [
            'lotes_activos' => $this->db->query("SELECT COUNT(*) FROM tropa WHERE activo = 1")->fetchColumn(),
            'total_animales' => $this->db->query("SELECT SUM(cantidad_inicial) FROM tropa WHERE activo = 1")->fetchColumn() ?? 0,
            'total_insumos' => $this->db->query("SELECT COUNT(*) FROM insumo WHERE activo = 1")->fetchColumn(),
            'total_dietas' => $this->db->query("SELECT COUNT(*) FROM dieta WHERE activo = 1")->fetchColumn()
        ];
    }

    public function getTodayStats() {
        $hoy = date('Y-m-d');
        $stmt = $this->db->prepare("SELECT COUNT(*) as alimentaciones, COALESCE(SUM(kg_totales_tirados), 0) as kg_totales FROM consumo_lote WHERE DATE(fecha) = ?");
        $stmt->execute([$hoy]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAdpvAvg() {
        $stmt = $this->db->query("
            SELECT AVG((p2.peso_promedio - p1.peso_promedio) / DATEDIFF(p2.fecha, p1.fecha)) as adpv
            FROM pesada p1
            INNER JOIN pesada p2 ON p1.id_tropa = p2.id_tropa 
                AND p2.fecha > p1.fecha
                AND p2.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            WHERE NOT EXISTS (
                SELECT 1 FROM pesada p3 
                WHERE p3.id_tropa = p1.id_tropa AND p3.fecha > p1.fecha AND p3.fecha < p2.fecha
            )
        ");
        return $stmt->fetchColumn() ?? 0;
    }

    public function getCmsAvg() {
        $stmt = $this->db->query("
            SELECT AVG(
                (SELECT SUM(kg_ms) FROM consumo_lote_detalle cld WHERE cld.id_consumo = cl.id_consumo) / cl.animales_presentes
            ) as cms
            FROM consumo_lote cl
            WHERE cl.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND cl.animales_presentes > 0
        ");
        return $stmt->fetchColumn() ?? 0;
    }

    // Alertas
    public function getAlerts() {
        $hoy = date('Y-m-d');
        
        $lotes_sin_dieta = $this->db->query("
            SELECT COUNT(*) FROM tropa t
            LEFT JOIN tropa_dieta_asignada tda ON t.id_tropa = tda.id_tropa AND tda.fecha_hasta IS NULL
            WHERE t.activo = 1 AND tda.id_tropa_dieta IS NULL
        ")->fetchColumn();

        $ajustes_pendientes = $this->db->query("SELECT COUNT(*) FROM ajuste_animales_pendiente WHERE estado = 'PENDIENTE'")->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT t.id_tropa) FROM tropa t
            LEFT JOIN consumo_lote cl ON t.id_tropa = cl.id_tropa AND DATE(cl.fecha) = ?
            WHERE t.activo = 1 AND cl.id_consumo IS NULL
        ");
        $stmt->execute([$hoy]);
        $lotes_sin_alimentar = $stmt->fetchColumn();

        return [
            'lotes_sin_dieta' => $lotes_sin_dieta,
            'ajustes_pendientes' => $ajustes_pendientes,
            'lotes_sin_alimentar' => $lotes_sin_alimentar
        ];
    }

    // Listados
    public function getTopActiveLotes($limit = 5) {
        $stmt = $this->db->prepare("
            SELECT 
                t.id_tropa, t.nombre, c.nombre as campo, t.cantidad_inicial as animales, d.nombre as dieta,
                (SELECT peso_promedio FROM pesada WHERE id_tropa = t.id_tropa ORDER BY fecha DESC LIMIT 1) as ultimo_peso,
                (SELECT DATE(fecha) FROM pesada WHERE id_tropa = t.id_tropa ORDER BY fecha DESC LIMIT 1) as fecha_peso,
                DATEDIFF(CURDATE(), t.fecha_inicio) as dias_feedlot
            FROM tropa t
            LEFT JOIN campo c ON t.id_campo = c.id_campo
            LEFT JOIN tropa_dieta_asignada tda ON t.id_tropa = tda.id_tropa AND tda.fecha_hasta IS NULL
            LEFT JOIN dieta d ON tda.id_dieta = d.id_dieta
            WHERE t.activo = 1
            ORDER BY t.fecha_inicio DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLastFeedings($limit = 5) {
        $stmt = $this->db->prepare("
            SELECT 
                cl.fecha, cl.hora, t.nombre as lote, cl.kg_totales_tirados, cl.animales_presentes, u.nombre as operario
            FROM consumo_lote cl
            INNER JOIN tropa t ON cl.id_tropa = t.id_tropa
            LEFT JOIN usuario u ON cl.id_usuario = u.id_usuario
            ORDER BY cl.fecha DESC, cl.hora DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Datos Gráficos
    public function getWeightEvolutionData() {
        return $this->db->query("
            SELECT DATE(p.fecha) as fecha, AVG(p.peso_promedio) as peso_promedio
            FROM pesada p
            INNER JOIN tropa t ON p.id_tropa = t.id_tropa
            WHERE t.activo = 1 AND p.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(p.fecha)
            ORDER BY fecha ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMsConsumptionData() {
        return $this->db->query("
            SELECT DATE(cl.fecha) as fecha, 
            SUM((SELECT SUM(cld.kg_ms) FROM consumo_lote_detalle cld WHERE cld.id_consumo = cl.id_consumo)) as ms_total
            FROM consumo_lote cl
            WHERE cl.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DATE(cl.fecha)
            ORDER BY fecha ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


// ==========================
// Dashboard por Lote (UI/UX)
// ==========================
public function getDashboardLotes($onlyActive = true) {
    $sql = "
        SELECT t.id_tropa, t.nombre, c.nombre AS campo, t.activo
        FROM tropa t
        LEFT JOIN campo c ON t.id_campo = c.id_campo
        " . ($onlyActive ? "WHERE t.activo = 1" : "") . "
        ORDER BY t.id_tropa DESC
    ";
    return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

public function getLatestActiveLoteId() {
    $id = $this->db->query("SELECT id_tropa FROM tropa WHERE activo = 1 ORDER BY id_tropa DESC LIMIT 1")->fetchColumn();
    return $id ? (int)$id : 0;
}

public function loteExists($id_tropa, $onlyActive = false) {
    $id_tropa = (int)$id_tropa;
    if ($id_tropa <= 0) return false;

    $sql = "SELECT COUNT(*) FROM tropa WHERE id_tropa = ?" . ($onlyActive ? " AND activo = 1" : "");
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$id_tropa]);
    return ((int)$stmt->fetchColumn()) > 0;
}

public function getLoteSummary($id_tropa) {
    $id_tropa = (int)$id_tropa;
    $stmt = $this->db->prepare("
        SELECT 
            t.id_tropa, t.nombre, t.cantidad_inicial AS animales, t.fecha_inicio, t.activo,
            c.nombre AS campo,
            d.id_dieta, d.nombre AS dieta
        FROM tropa t
        LEFT JOIN campo c ON t.id_campo = c.id_campo
        LEFT JOIN tropa_dieta_asignada tda ON t.id_tropa = tda.id_tropa AND tda.fecha_hasta IS NULL
        LEFT JOIN dieta d ON tda.id_dieta = d.id_dieta
        WHERE t.id_tropa = ?
        LIMIT 1
    ");
    $stmt->execute([$id_tropa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

public function getLastWeightForLote($id_tropa) {
    $id_tropa = (int)$id_tropa;
    $stmt = $this->db->prepare("SELECT fecha, peso_promedio FROM pesada WHERE id_tropa = ? ORDER BY fecha DESC LIMIT 1");
    $stmt->execute([$id_tropa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

public function getTodayStatsForLote($id_tropa) {
    $id_tropa = (int)$id_tropa;
    $hoy = date('Y-m-d');
    $stmt = $this->db->prepare("
        SELECT 
            COUNT(*) AS alimentaciones,
            COALESCE(SUM(kg_totales_tirados), 0) AS kg_totales,
            COALESCE(AVG(animales_presentes), 0) AS animales_prom
        FROM consumo_lote
        WHERE id_tropa = ? AND DATE(fecha) = ?
    ");
    $stmt->execute([$id_tropa, $hoy]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) $row = ['alimentaciones' => 0, 'kg_totales' => 0, 'animales_prom' => 0];
    $anim = (float)$row['animales_prom'];
    $row['kg_por_cab'] = ($anim > 0) ? ((float)$row['kg_totales'] / $anim) : 0;
    return $row;
}

public function getCmsForLote($id_tropa) {
    $id_tropa = (int)$id_tropa;
    $stmt = $this->db->prepare("
        SELECT AVG(ms_total / animales_presentes) AS cms
        FROM (
            SELECT 
                cl.id_consumo,
                cl.animales_presentes,
                (SELECT COALESCE(SUM(cld.kg_ms),0) FROM consumo_lote_detalle cld WHERE cld.id_consumo = cl.id_consumo) AS ms_total
            FROM consumo_lote cl
            WHERE cl.id_tropa = ? 
              AND cl.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
              AND cl.animales_presentes > 0
        ) x
    ");
    $stmt->execute([$id_tropa]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== null ? (float)$val : 0.0;
}

public function getAdpvForLote($id_tropa) {
    $id_tropa = (int)$id_tropa;

    // ADPV simple: diferencia entre últimas 2 pesadas (máx 30 días) / días
    $stmt = $this->db->prepare("
        SELECT fecha, peso_promedio
        FROM pesada
        WHERE id_tropa = ? AND fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY fecha DESC
        LIMIT 2
    ");
    $stmt->execute([$id_tropa]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) < 2) return 0.0;

    $p2 = (float)$rows[0]['peso_promedio'];
    $d2 = $rows[0]['fecha'];
    $p1 = (float)$rows[1]['peso_promedio'];
    $d1 = $rows[1]['fecha'];

    $days = (strtotime($d2) - strtotime($d1)) / 86400;
    if ($days <= 0) return 0.0;

    return ($p2 - $p1) / $days;
}

public function getLastFeedingsForLote($id_tropa, $limit = 8) {
    $id_tropa = (int)$id_tropa;
    // Evitamos placeholders en LIMIT para máxima compatibilidad (y es seguro por casteo)
    $limit = max(1, min(50, (int)$limit));

    $sql = "
        SELECT 
            cl.fecha, cl.hora, t.nombre AS lote, cl.kg_totales_tirados, cl.animales_presentes, u.nombre AS operario
        FROM consumo_lote cl
        INNER JOIN tropa t ON cl.id_tropa = t.id_tropa
        LEFT JOIN usuario u ON cl.id_usuario = u.id_usuario
        WHERE cl.id_tropa = :id_tropa
        ORDER BY cl.fecha DESC, cl.hora DESC
        LIMIT {$limit}
    ";
    $stmt = $this->db->prepare($sql);
    $stmt->execute([':id_tropa' => $id_tropa]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

public function getWeightEvolutionDataForLote($id_tropa) {
    $id_tropa = (int)$id_tropa;
    $stmt = $this->db->prepare("
        SELECT DATE(p.fecha) AS fecha, AVG(p.peso_promedio) AS peso_promedio
        FROM pesada p
        WHERE p.id_tropa = ? AND p.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(p.fecha)
        ORDER BY fecha ASC
    ");
    $stmt->execute([$id_tropa]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

public function getMsConsumptionDataForLote($id_tropa) {
    $id_tropa = (int)$id_tropa;
    $stmt = $this->db->prepare("
        SELECT DATE(cl.fecha) AS fecha, 
        SUM((SELECT COALESCE(SUM(cld.kg_ms),0) FROM consumo_lote_detalle cld WHERE cld.id_consumo = cl.id_consumo)) AS ms_total
        FROM consumo_lote cl
        WHERE cl.id_tropa = ? AND cl.fecha >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
        GROUP BY DATE(cl.fecha)
        ORDER BY fecha ASC
    ");
    $stmt->execute([$id_tropa]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

}
?>
