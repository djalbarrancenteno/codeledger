<?php
declare(strict_types=1);

// ============================================================
// Acceso a datos
// ============================================================
function db(): PDO
{
    global $pdo;
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function fetch_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function fetch_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function scalar(string $sql, array $params = [])
{
    return q($sql, $params)->fetchColumn();
}

// ============================================================
// Autenticación y RBAC
// ============================================================
function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['id_usuario'])) {
        return null;
    }
    $user = fetch_one('SELECT * FROM Usuario WHERE id_usuario = ?', [(int)$_SESSION['id_usuario']]);
    if (!$user || !(int)$user['estado_operativo']) {
        $_SESSION = [];
        $user = null;
    }
    return $user;
}

function require_login(array $roles = []): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if ($roles && !in_array($user['rol'], $roles, true)) {
        flash('danger', 'No tienes permisos para acceder a esa sección.');
        redirect('dashboard.php');
    }
    return $user;
}

function is_cfo(?array $user = null): bool
{
    $user ??= current_user();
    return $user && $user['rol'] === 'CFO';
}

function is_pm(?array $user = null): bool
{
    $user ??= current_user();
    return $user && $user['rol'] === 'PM';
}

function is_dev(?array $user = null): bool
{
    $user ??= current_user();
    return $user && $user['rol'] === 'DEV';
}

function can_view_financials(?array $user = null): bool
{
    return is_cfo($user) || is_pm($user);
}

function can_view_costs(?array $user = null): bool
{
    return is_cfo($user);
}

function can_manage_project(?array $user, array $project): bool
{
    if (is_cfo($user)) {
        return true;
    }
    if (is_pm($user)) {
        return (int)$project['id_manager'] === (int)$user['id_usuario'];
    }
    return false;
}

function project_access_filter(?array $user, string $alias = 'p'): array
{
    if (is_pm($user)) {
        return [" AND {$alias}.id_manager = ?", [(int)$user['id_usuario']]];
    }
    return ['', []];
}

// ============================================================
// Utilidades de presentación
// ============================================================
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return $flashes;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Token CSRF inválido. Recarga la página e intenta de nuevo.');
    }
}

function audit(string $accion, string $entidad = '', ?int $idEntidad = null, string $detalle = ''): void
{
    $user = current_user();
    q(
        'INSERT INTO EventoAuditoria (id_usuario, accion, entidad, id_entidad, detalle, ip) VALUES (?,?,?,?,?,?)',
        [$user['id_usuario'] ?? null, $accion, $entidad, $idEntidad, $detalle, $_SERVER['REMOTE_ADDR'] ?? null]
    );
}

function fmt_money($value, string $currency = 'USD'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $symbols = ['USD' => 'USD', 'EUR' => 'EUR', 'VES' => 'VES', 'COP' => 'COP'];
    $prefix = $symbols[$currency] ?? $currency;
    return $prefix . ' ' . number_format((float)$value, 2, ',', '.');
}

function fmt_money_short($value, string $currency = 'USD'): string
{
    $n = (float)$value;
    $abs = abs($n);
    if ($abs >= 1000000) {
        return $currency . ' ' . number_format($n / 1000000, 1, ',', '.') . 'M';
    }
    if ($abs >= 1000) {
        return $currency . ' ' . number_format($n / 1000, 1, ',', '.') . 'K';
    }
    return $currency . ' ' . number_format($n, 0, ',', '.');
}

function fmt_number($value, int $decimals = 0): string
{
    return number_format((float)$value, $decimals, ',', '.');
}

function fmt_hours($value): string
{
    return number_format((float)$value, 2, ',', '.') . ' h';
}

function fmt_pct($value, int $decimals = 1): string
{
    return number_format((float)$value, $decimals, ',', '.') . '%';
}

function fmt_ratio_pct($ratio, int $decimals = 1): string
{
    return fmt_pct((float)$ratio * 100, $decimals);
}

function fmt_date(?string $date, bool $withYear = true): string
{
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return e($date);
    }
    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ($withYear ? ' ' . date('Y', $ts) : '');
}

function fecha_larga(?string $date = null): string
{
    $ts = strtotime($date ?: date('Y-m-d'));
    if (!$ts) {
        return '';
    }
    $days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $days[(int)date('w', $ts)] . ', ' . date('j', $ts) . ' de ' . $months[(int)date('n', $ts) - 1] . ' de ' . date('Y', $ts);
}

function month_label(string $ym): string
{
    $months = ['01' => 'ene', '02' => 'feb', '03' => 'mar', '04' => 'abr', '05' => 'may', '06' => 'jun',
        '07' => 'jul', '08' => 'ago', '09' => 'sep', '10' => 'oct', '11' => 'nov', '12' => 'dic'];
    [$year, $month] = array_pad(explode('-', $ym), 2, '01');
    return ($months[$month] ?? $month) . ' ' . substr($year, 2);
}

function badge_for_status(string $status): string
{
    return match ($status) {
        'Aprobado', 'Cobrada', 'Entregado', 'Conectado', 'Closed', 'Activo', 'Reconocido' => 'success',
        'Pendiente', 'En Curso', 'Emitida', 'Pre-factura', 'Advertencia', 'En Desarrollo' => 'warning',
        'Facturado', 'En Produccion', 'Reconociendo', 'Open' => 'info',
        'Anulada', 'Error', 'Crítica', 'Vencida', 'Vencido', 'Excedido' => 'danger',
        default => 'secondary',
    };
}

function status_badge(string $status): string
{
    return '<span class="badge text-bg-' . badge_for_status($status) . ' badge-soft">' . e($status) . '</span>';
}

// ============================================================
// Moneda
// ============================================================
function latest_fx(string $from, string $to = 'USD'): float
{
    if (strtoupper($from) === strtoupper($to)) {
        return 1.0;
    }
    $rate = scalar(
        'SELECT tasa FROM TipoCambio WHERE moneda_origen = ? AND moneda_destino = ? ORDER BY fecha DESC LIMIT 1',
        [strtoupper($from), strtoupper($to)]
    );
    return $rate !== false && $rate !== null ? (float)$rate : 1.0;
}

function to_usd(float $amount, string $currency): float
{
    return $amount * latest_fx($currency, 'USD');
}

// ============================================================
// Costos de mano de obra
// ============================================================
function fully_loaded_rate(int $idUsuario): array
{
    static $cache = [];
    if (isset($cache[$idUsuario])) {
        return $cache[$idUsuario];
    }
    $ficha = fetch_one(
        'SELECT fs.*, u.nivel FROM FichaSalarial fs JOIN Usuario u ON u.id_usuario = fs.id_usuario
         WHERE fs.id_usuario = ? ORDER BY fs.vigente_desde DESC LIMIT 1',
        [$idUsuario]
    );
    if (!$ficha) {
        return $cache[$idUsuario] = [
            'base' => 0, 'prestaciones' => 0, 'impuestos' => 0, 'asignados' => 0,
            'mensual' => 0, 'horas' => 0, 'rate' => 0, 'modalidad' => 'Interno', 'nivel' => 'Junior',
        ];
    }
    $asignados = (float)scalar(
        'SELECT COALESCE(SUM(monto_mensual),0) FROM CostoAsignadoUsuario WHERE id_usuario = ? AND activo = 1',
        [$idUsuario]
    );
    $base = (float)$ficha['salario_base'];
    $prestaciones = (float)$ficha['prestaciones_sociales'];
    $impuestos = (float)$ficha['impuestos_patronales'];
    $mensual = $base + $prestaciones + $impuestos + $asignados;
    $horas = max(1.0, (float)$ficha['horas_laborales_mes']);
    return $cache[$idUsuario] = [
        'base' => $base,
        'prestaciones' => $prestaciones,
        'impuestos' => $impuestos,
        'asignados' => $asignados,
        'mensual' => $mensual,
        'horas' => $horas,
        'rate' => $mensual / $horas,
        'modalidad' => $ficha['modalidad_contratacion'],
        'nivel' => $ficha['nivel'],
        'moneda' => $ficha['moneda'],
    ];
}

function user_billable_rate(int $idUsuario, ?string $date = null): float
{
    $row = fetch_one(
        'SELECT u.nivel, fs.modalidad_contratacion FROM Usuario u
         LEFT JOIN FichaSalarial fs ON fs.id_usuario = u.id_usuario
         WHERE u.id_usuario = ? ORDER BY fs.vigente_desde DESC LIMIT 1',
        [$idUsuario]
    );
    if (!$row) {
        return 0.0;
    }
    $modality = $row['modalidad_contratacion'] ?? 'Interno';
    $rate = scalar(
        'SELECT monto_hora FROM TarifaVenta WHERE rol = ? AND modalidad_contratacion = ? AND vigente_desde <= ? ORDER BY vigente_desde DESC LIMIT 1',
        [$row['nivel'], $modality, $date ?: date('Y-m-d')]
    );
    if ($rate === false || $rate === null) {
        $rate = scalar(
            'SELECT monto_hora FROM TarifaVenta WHERE rol = ? AND modalidad_contratacion = ? ORDER BY vigente_desde DESC LIMIT 1',
            [$row['nivel'], $modality]
        );
    }
    return (float)($rate ?: 0);
}

function rate_matrix(): array
{
    $rows = fetch_all('SELECT * FROM TarifaVenta ORDER BY FIELD(rol, "Junior","Senior","Architect"), FIELD(modalidad_contratacion, "Interno","Contractor","Agencia")');
    $matrix = [];
    foreach ($rows as $row) {
        $matrix[$row['rol']][$row['modalidad_contratacion']] = (float)$row['monto_hora'];
    }
    return $matrix;
}

// ============================================================
// Métricas de proyecto / EVM
// ============================================================
function project_metrics(int $idProyecto): ?array
{
    $project = fetch_one(
        'SELECT p.*, c.nombre AS cliente, u.nombre_completo AS manager
         FROM Proyecto p JOIN Cliente c ON c.id_cliente = p.id_cliente
         LEFT JOIN Usuario u ON u.id_usuario = p.id_manager
         WHERE p.id_proyecto = ?',
        [$idProyecto]
    );
    if (!$project) {
        return null;
    }

    $labor = fetch_one(
        "SELECT
            COALESCE(SUM(r.horas_incurridas),0) AS horas,
            COALESCE(SUM(CASE WHEN r.estado_aprobacion IN ('Aprobado','Facturado') THEN r.horas_incurridas * r.fully_loaded_rate_hist ELSE 0 END),0) AS costo,
            COALESCE(SUM(CASE WHEN r.estado_aprobacion IN ('Aprobado','Facturado') AND t.categoria_contable = 'Billable' THEN r.horas_incurridas ELSE 0 END),0) AS horas_billable,
            COALESCE(SUM(CASE WHEN r.estado_aprobacion IN ('Aprobado','Facturado') AND t.categoria_contable = 'Operativa' THEN r.horas_incurridas ELSE 0 END),0) AS horas_operativas,
            COALESCE(SUM(CASE WHEN r.estado_aprobacion IN ('Aprobado','Facturado') AND t.categoria_contable = 'I+D' THEN r.horas_incurridas ELSE 0 END),0) AS horas_id,
            COALESCE(SUM(CASE WHEN r.estado_aprobacion IN ('Aprobado','Facturado') THEN r.horas_incurridas * r.billable_rate_hist ELSE 0 END),0) AS valor_billable,
            COALESCE(SUM(CASE WHEN r.estado_aprobacion IN ('Aprobado','Facturado') AND r.id_comprobante IS NULL THEN r.horas_incurridas * r.billable_rate_hist ELSE 0 END),0) AS valor_no_facturado,
            COALESCE(SUM(CASE WHEN r.estado_aprobacion = 'Pendiente' THEN r.horas_incurridas ELSE 0 END),0) AS horas_pendientes
         FROM RegistroTiempo r
         JOIN Tarea t ON t.id_tarea = r.id_tarea
         JOIN Hito h ON h.id_hito = t.id_hito
         WHERE h.id_proyecto = ?",
        [$idProyecto]
    );

    $cloud = fetch_one(
        'SELECT COALESCE(SUM(monto_cargado),0) total,
                COALESCE(SUM(CASE WHEN categoria = "Cloud" THEN monto_cargado ELSE 0 END),0) cloud,
                COALESCE(SUM(CASE WHEN categoria = "API" THEN monto_cargado ELSE 0 END),0) api,
                COALESCE(SUM(CASE WHEN categoria = "Licencia" THEN monto_cargado ELSE 0 END),0) licencia
         FROM ConsumoFinOps WHERE id_proyecto = ?',
        [$idProyecto]
    );

    $invoiced = (float)scalar(
        "SELECT COALESCE(SUM(monto_total_usd),0) FROM ComprobanteFacturacion
         WHERE id_proyecto = ? AND estado IN ('Emitida','Cobrada')",
        [$idProyecto]
    );
    $recognized = (float)scalar(
        "SELECT COALESCE(SUM(ri.monto_reconocido),0)
         FROM ReconocimientoIngreso ri JOIN ComprobanteFacturacion cf ON cf.id_comprobante = ri.id_comprobante
         WHERE cf.id_proyecto = ? AND cf.estado IN ('Emitida','Cobrada')",
        [$idProyecto]
    );
    $pendingAr = (float)scalar(
        "SELECT COALESCE(SUM(monto_total_usd),0) FROM ComprobanteFacturacion
         WHERE id_proyecto = ? AND estado = 'Emitida'",
        [$idProyecto]
    );

    $taskStats = fetch_one(
        'SELECT COALESCE(SUM(t.peso_horas_estimadas),0) total, COALESCE(SUM(CASE WHEN t.estado_tarea = "Closed" THEN t.peso_horas_estimadas ELSE 0 END),0) closed,
                COUNT(*) n, SUM(CASE WHEN t.estado_tarea = "Closed" THEN 1 ELSE 0 END) n_closed
         FROM Tarea t JOIN Hito h ON h.id_hito = t.id_hito WHERE h.id_proyecto = ?',
        [$idProyecto]
    );
    $hitoStats = fetch_one(
        'SELECT COUNT(*) total, SUM(CASE WHEN estado = "Entregado" THEN 1 ELSE 0 END) entregados,
                COALESCE(SUM(CASE WHEN estado = "Entregado" THEN valor_facturable ELSE 0 END),0) valor_entregado
         FROM Hito WHERE id_proyecto = ?',
        [$idProyecto]
    );

    $progress = (float)$taskStats['total'] > 0
        ? (float)$taskStats['closed'] / (float)$taskStats['total']
        : ((int)$hitoStats['total'] > 0 ? (int)$hitoStats['entregados'] / (int)$hitoStats['total'] : 0);

    $bac = (float)$project['bac_presupuesto_inicial'];
    $ev = $bac * $progress;
    $ac = (float)$labor['costo'] + (float)$cloud['total'];
    $cpi = $ac > 0 ? $ev / $ac : 0;
    $eac = $cpi > 0 ? $bac / $cpi : $bac;
    $vac = $bac - $eac;
    $margin = $invoiced - $ac;
    $marginPct = $invoiced > 0 ? $margin / $invoiced : 0;
    $budgetConsumed = $bac > 0 ? $ac / $bac : 0;

    $pendingApproval = (float)scalar(
        "SELECT COALESCE(SUM(r.horas_incurridas),0) FROM RegistroTiempo r
         JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito
         WHERE h.id_proyecto = ? AND r.estado_aprobacion = 'Pendiente'",
        [$idProyecto]
    );

    return array_merge($project, [
        'horas' => (float)$labor['horas'],
        'horas_billable' => (float)$labor['horas_billable'],
        'horas_operativas' => (float)$labor['horas_operativas'],
        'horas_id' => (float)$labor['horas_id'],
        'horas_pendientes' => $pendingApproval,
        'costo_mano_obra' => (float)$labor['costo'],
        'valor_billable' => (float)$labor['valor_billable'],
        'valor_no_facturado' => (float)$labor['valor_no_facturado'],
        'costo_cloud' => (float)$cloud['cloud'],
        'costo_api' => (float)$cloud['api'],
        'costo_licencias' => (float)$cloud['licencia'],
        'costo_insumos' => (float)$cloud['total'],
        'costo_directo' => $ac,
        'ingresos_facturados' => $invoiced,
        'ingresos_reconocidos' => $recognized,
        'cuentas_por_cobrar' => $pendingAr,
        'margen_bruto' => $margin,
        'margen_pct' => $marginPct,
        'bac' => $bac,
        'ev' => $ev,
        'ac' => $ac,
        'cpi' => $cpi,
        'eac' => $eac,
        'vac' => $vac,
        'avance' => $progress,
        'consumo_presupuesto' => $budgetConsumed,
        'tareas_total' => (int)$taskStats['n'],
        'tareas_cerradas' => (int)$taskStats['n_closed'],
        'hitos_total' => (int)$hitoStats['total'],
        'hitos_entregados' => (int)$hitoStats['entregados'],
        'valor_hitos_entregados' => (float)$hitoStats['valor_entregado'],
        'alertas' => (int)scalar('SELECT COUNT(*) FROM AlertaFinOps WHERE id_proyecto = ? AND leida = 0', [$idProyecto]),
    ]);
}

function project_phase_costs(int $idProyecto): array
{
    $result = [
        'Investigacion' => ['labor' => 0.0, 'cloud' => 0.0, 'total' => 0.0],
        'Desarrollo' => ['labor' => 0.0, 'cloud' => 0.0, 'total' => 0.0],
    ];
    $labor = fetch_all(
        "SELECT h.fase_contable fase, COALESCE(SUM(r.horas_incurridas * r.fully_loaded_rate_hist),0) total
         FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito
         WHERE h.id_proyecto = ? AND r.estado_aprobacion IN ('Aprobado','Facturado')
         GROUP BY h.fase_contable",
        [$idProyecto]
    );
    foreach ($labor as $row) {
        $result[$row['fase']]['labor'] = (float)$row['total'];
    }
    $cloud = fetch_all(
        'SELECT h.fase_contable fase, COALESCE(SUM(c.monto_cargado),0) total
         FROM ConsumoFinOps c LEFT JOIN Hito h ON h.id_hito = c.id_hito
         WHERE c.id_proyecto = ? GROUP BY h.fase_contable',
        [$idProyecto]
    );
    foreach ($cloud as $row) {
        $phase = $row['fase'] ?: 'Desarrollo';
        $result[$phase]['cloud'] = (float)$row['total'];
    }
    foreach ($result as $phase => $values) {
        $result[$phase]['total'] = $values['labor'] + $values['cloud'];
    }
    return $result;
}

function project_accounting(int $idProyecto): array
{
    $phases = project_phase_costs($idProyecto);
    $rules = fetch_all('SELECT * FROM ReglaContable WHERE id_proyecto = ? AND activo = 1', [$idProyecto]);
    $treatments = [];
    foreach ($rules as $rule) {
        $treatments[$rule['fase']] = $rule;
    }
    $opex = 0.0;
    $capex = 0.0;
    $detail = [];
    foreach ($phases as $phase => $values) {
        $treatment = $treatments[$phase]['tratamiento'] ?? 'OpEx';
        if ($treatment === 'CapEx') {
            $capex += $values['total'];
        } else {
            $opex += $values['total'];
        }
        $detail[$phase] = array_merge($values, [
            'tratamiento' => $treatment,
            'vida_util_meses' => (int)($treatments[$phase]['vida_util_meses'] ?? 0),
        ]);
    }
    return ['fases' => $detail, 'opex' => $opex, 'capex' => $capex];
}

function update_asset_amortization(int $idActivo): void
{
    $asset = fetch_one('SELECT * FROM ActivoIntangible WHERE id_activo = ?', [$idActivo]);
    if (!$asset) {
        return;
    }
    $start = new DateTimeImmutable($asset['fecha_capitalizacion']);
    $now = new DateTimeImmutable('now');
    if ($now < $start) {
        $months = 0;
    } else {
        $months = ((int)$now->format('Y') - (int)$start->format('Y')) * 12 + ((int)$now->format('n') - (int)$start->format('n'));
        if ((int)$now->format('j') >= (int)$start->format('j')) {
            $months++;
        }
    }
    $months = max(0, min((int)$asset['vida_util_meses'], $months));
    $accumulated = min((float)$asset['monto_capitalizado'], (float)$asset['amortizacion_mensual'] * $months);
    $status = $months >= (int)$asset['vida_util_meses'] ? 'Amortizado' : 'Activo';
    q('UPDATE ActivoIntangible SET amortizacion_acumulada = ?, estado = ? WHERE id_activo = ?', [$accumulated, $status, $idActivo]);
}

// ============================================================
// FinOps
// ============================================================
function evaluar_alertas_finops(): int
{
    $created = 0;
    $budgets = fetch_all('SELECT b.*, p.codigo_unico FROM PresupuestoFinOps b JOIN Proyecto p ON p.id_proyecto = b.id_proyecto WHERE b.activo = 1');
    foreach ($budgets as $budget) {
        $where = ["id_proyecto = ?", "DATE_FORMAT(fecha_consumo, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"];
        $params = [(int)$budget['id_proyecto']];
        if (!empty($budget['proveedor'])) {
            $where[] = 'proveedor = ?';
            $params[] = $budget['proveedor'];
        }
        if (!empty($budget['servicio'])) {
            $where[] = 'servicio = ?';
            $params[] = $budget['servicio'];
        }
        $actual = (float)scalar('SELECT COALESCE(SUM(monto_cargado),0) FROM ConsumoFinOps WHERE ' . implode(' AND ', $where), $params);
        $limit = (float)$budget['presupuesto_mensual'];
        if ($limit <= 0) {
            continue;
        }
        $threshold = $limit * ((float)$budget['umbral_alerta'] / 100);
        if ($actual < $threshold) {
            continue;
        }
        $exists = (int)scalar(
            "SELECT COUNT(*) FROM AlertaFinOps WHERE id_presupuesto = ? AND DATE_FORMAT(fecha, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')",
            [(int)$budget['id_presupuesto']]
        );
        if ($exists > 0) {
            continue;
        }
        $pct = round(($actual / $limit) * 100);
        $level = $actual > $limit ? 'Crítica' : 'Advertencia';
        $message = $budget['proveedor'] . ': consumo de $' . number_format($actual, 2) . ' vs presupuesto $' . number_format($limit, 2) . " ($pct%).";
        q(
            'INSERT INTO AlertaFinOps (id_presupuesto, id_proyecto, id_hito, tipo, mensaje, nivel, monto_actual, monto_limite, leida) VALUES (?,?,?,?,?,?,?,?,0)',
            [$budget['id_presupuesto'], $budget['id_proyecto'], $budget['id_hito'], 'Desviación OpEx', $message, $level, $actual, $limit]
        );
        $created++;
    }
    return $created;
}

function sync_local_cloud_costs(): array
{
    $files = [
        __DIR__ . '/../mocks/mock_aws_costs.json',
        __DIR__ . '/../mocks/mock_azure_costs.json',
    ];
    $inserted = 0;
    $skipped = 0;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || empty($data['cargos'])) {
            continue;
        }
        $provider = $data['proveedor'] ?? 'Cloud';
        foreach ($data['cargos'] as $charge) {
            $project = fetch_one('SELECT id_proyecto FROM Proyecto WHERE codigo_unico = ?', [$charge['tag_proyecto'] ?? '']);
            if (!$project) {
                $skipped++;
                continue;
            }
            $hito = fetch_one(
                "SELECT id_hito FROM Hito WHERE id_proyecto = ? AND estado IN ('En Curso','Pendiente') ORDER BY FIELD(estado,'En Curso','Pendiente'), id_hito LIMIT 1",
                [$project['id_proyecto']]
            );
            $ref = 'mock-' . ($charge['id_transaccion'] ?? uniqid());
            try {
                q(
                    'INSERT INTO ConsumoFinOps (id_proyecto, id_hito, proveedor, categoria, servicio, monto_cargado, moneda, fecha_consumo, referencia_externa, etiqueta_proyecto)
                     VALUES (?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE monto_cargado = VALUES(monto_cargado)',
                    [
                        $project['id_proyecto'], $hito['id_hito'] ?? null, $provider, 'Cloud',
                        $charge['servicio'] ?? 'Servicio Cloud', (float)($charge['monto'] ?? 0),
                        $charge['moneda'] ?? 'USD', substr((string)($charge['fecha_consumo'] ?? date('Y-m-d')), 0, 10),
                        $ref, $charge['tag_proyecto'] ?? null,
                    ]
                );
                $inserted++;
            } catch (Throwable $e) {
                $skipped++;
            }
        }
    }
    return ['inserted' => $inserted, 'skipped' => $skipped];
}

function sync_github_issues(): array
{
    $file = __DIR__ . '/../mocks/github_issues.json';
    if (!is_file($file)) {
        return ['inserted' => 0, 'updated' => 0, 'error' => 'No existe mocks/github_issues.json'];
    }
    $issues = json_decode((string)file_get_contents($file), true);
    if (!is_array($issues)) {
        return ['inserted' => 0, 'updated' => 0, 'error' => 'JSON inválido'];
    }
    $inserted = 0;
    $updated = 0;
    foreach ($issues as $issue) {
        $project = fetch_one('SELECT id_proyecto FROM Proyecto WHERE codigo_unico = ?', [$issue['project_code'] ?? '']);
        if (!$project) {
            continue;
        }
        $hito = fetch_one('SELECT id_hito FROM Hito WHERE id_proyecto = ? AND nombre_hito = ? LIMIT 1', [$project['id_proyecto'], $issue['hito'] ?? '']);
        if (!$hito) {
            $hito = fetch_one('SELECT id_hito FROM Hito WHERE id_proyecto = ? ORDER BY id_hito DESC LIMIT 1', [$project['id_proyecto']]);
        }
        if (!$hito) {
            continue;
        }
        $existing = fetch_one('SELECT id_tarea FROM Tarea WHERE issue_id_github = ? LIMIT 1', [$issue['issue_id']]);
        if ($existing) {
            q(
                'UPDATE Tarea SET titulo = ?, historia_usuario = ?, peso_horas_estimadas = ?, estado_tarea = ?, categoria_contable = ? WHERE id_tarea = ?',
                [$issue['title'], $issue['story'] ?? null, (float)($issue['estimate'] ?? 0), $issue['status'] ?? 'Open', $issue['category'] ?? 'Billable', $existing['id_tarea']]
            );
            $updated++;
        } else {
            q(
                'INSERT INTO Tarea (id_hito, issue_id_github, titulo, historia_usuario, peso_horas_estimadas, estado_tarea, categoria_contable, fecha_creacion) VALUES (?,?,?,?,?,?,?,?)',
                [$hito['id_hito'], $issue['issue_id'], $issue['title'], $issue['story'] ?? null, (float)($issue['estimate'] ?? 0), $issue['status'] ?? 'Open', $issue['category'] ?? 'Billable', date('Y-m-d')]
            );
            $inserted++;
        }
    }
    return ['inserted' => $inserted, 'updated' => $updated, 'error' => null];
}

// ============================================================
// Facturación / ingresos
// ============================================================
function next_invoice_number(string $prefix = 'INV'): string
{
    $year = date('Y');
    $last = scalar(
        'SELECT numero_correlativo FROM ComprobanteFacturacion WHERE numero_correlativo LIKE ? ORDER BY id_comprobante DESC LIMIT 1',
        [$prefix . '-' . $year . '-%']
    );
    $next = 1;
    if ($last && preg_match('/-(\d+)$/', (string)$last, $m)) {
        $next = (int)$m[1] + 1;
    }
    return $prefix . '-' . $year . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function recognized_amount(float $total, string $start, string $end, ?string $asOf = null): float
{
    $asOfTs = strtotime($asOf ?: date('Y-m-d'));
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if ($asOfTs <= $startTs) {
        return 0.0;
    }
    if ($asOfTs >= $endTs) {
        return $total;
    }
    $totalDays = max(1, ($endTs - $startTs) / 86400);
    $elapsed = ($asOfTs - $startTs) / 86400;
    return round($total * ($elapsed / $totalDays), 4);
}

function update_recognitions(): int
{
    $rows = fetch_all("SELECT * FROM ReconocimientoIngreso WHERE estado <> 'Reconocido'");
    $updated = 0;
    foreach ($rows as $row) {
        $amount = recognized_amount((float)$row['monto_total'], $row['fecha_inicio'], $row['fecha_fin']);
        $state = $amount >= (float)$row['monto_total'] ? 'Reconocido' : ($amount > 0 ? 'Reconociendo' : 'Diferido');
        q('UPDATE ReconocimientoIngreso SET monto_reconocido = ?, estado = ? WHERE id_reconocimiento = ?', [$amount, $state, $row['id_reconocimiento']]);
        $updated++;
    }
    return $updated;
}

function create_invoice_recognition(int $idComprobante, string $method = 'Lineal', ?string $start = null, ?string $end = null): void
{
    $invoice = fetch_one('SELECT * FROM ComprobanteFacturacion WHERE id_comprobante = ?', [$idComprobante]);
    if (!$invoice) {
        return;
    }
    $exists = (int)scalar('SELECT COUNT(*) FROM ReconocimientoIngreso WHERE id_comprobante = ?', [$idComprobante]);
    if ($exists > 0) {
        update_recognitions();
        return;
    }
    $start = $start ?: ($invoice['fecha_emision'] ?: date('Y-m-d'));
    $end = $end ?: ($invoice['fecha_vencimiento'] ?: date('Y-m-d', strtotime($start . ' +30 days')));
    if (strtotime($end) < strtotime($start)) {
        $end = $start;
    }
    $total = (float)$invoice['monto_total'];
    $recognized = recognized_amount($total, $start, $end);
    $state = $recognized >= $total ? 'Reconocido' : ($recognized > 0 ? 'Reconociendo' : 'Diferido');
    q(
        'INSERT INTO ReconocimientoIngreso (id_comprobante, monto_total, monto_reconocido, fecha_inicio, fecha_fin, metodo, estado) VALUES (?,?,?,?,?,?,?)',
        [$idComprobante, $total, $recognized, $start, $end, in_array($method, ['Lineal', 'Hitos', 'Entrega'], true) ? $method : 'Lineal', $state]
    );
    update_recognitions();
}

function mark_time_invoiced(int $idComprobante): int
{
    q("UPDATE RegistroTiempo SET estado_aprobacion = 'Facturado', facturado_en = NOW() WHERE id_comprobante = ? AND estado_aprobacion = 'Aprobado'", [$idComprobante]);
    return (int)scalar('SELECT COUNT(*) FROM RegistroTiempo WHERE id_comprobante = ?', [$idComprobante]);
}


function retainer_summary(int $idRetainer): array
{
    $contract = fetch_one('SELECT cr.*, p.nombre_proyecto, p.codigo_unico FROM ContratoRetainer cr JOIN Proyecto p ON p.id_proyecto = cr.id_proyecto WHERE cr.id_retainer = ?', [$idRetainer]);
    if (!$contract) {
        return [];
    }
    $period = date('Y-m');
    $consumed = (float)scalar(
        "SELECT COALESCE(SUM(r.horas_incurridas),0) FROM RegistroTiempo r
         JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito
         WHERE h.id_proyecto = ? AND r.estado_aprobacion IN ('Aprobado','Facturado') AND DATE_FORMAT(r.fecha_registro, '%Y-%m') = ?",
        [(int)$contract['id_proyecto'], $period]
    );
    $previous = fetch_one('SELECT * FROM ConsumoRetainer WHERE id_retainer = ? AND periodo < ? ORDER BY periodo DESC LIMIT 1', [$idRetainer, $period]);
    $saldoInicial = $previous ? (float)$previous['saldo_final'] : 0.0;
    $available = (float)$contract['horas_incluidas_mes'] + $saldoInicial;
    $saldoFinal = min((float)$contract['rollover_maximo'], max(0, $available - $consumed));
    return array_merge($contract, [
        'periodo' => $period,
        'horas_consumidas' => $consumed,
        'saldo_inicial' => $saldoInicial,
        'horas_disponibles' => $available,
        'horas_excedidas' => max(0, $consumed - $available),
        'saldo_final' => $saldoFinal,
        'consumo_pct' => $available > 0 ? $consumed / $available : 0,
    ]);
}

// ============================================================
// Reportes y series
// ============================================================
function company_metrics(): array
{
    $year = (int)date('Y');
    $revenue = (float)scalar(
        "SELECT COALESCE(SUM(monto_total_usd),0) FROM ComprobanteFacturacion WHERE estado IN ('Emitida','Cobrada') AND YEAR(COALESCE(fecha_emision, created_at)) = ?",
        [$year]
    );
    $labor = (float)scalar(
        "SELECT COALESCE(SUM(horas_incurridas * fully_loaded_rate_hist),0) FROM RegistroTiempo WHERE estado_aprobacion IN ('Aprobado','Facturado') AND YEAR(fecha_registro) = ?",
        [$year]
    );
    $cloud = (float)scalar(
        "SELECT COALESCE(SUM(monto_cargado),0) FROM ConsumoFinOps WHERE YEAR(fecha_consumo) = ?",
        [$year]
    );
    $cost = $labor + $cloud;
    $ar = (float)scalar("SELECT COALESCE(SUM(monto_total_usd),0) FROM ComprobanteFacturacion WHERE estado = 'Emitida'");
    $cash = (float)get_config('saldo_caja', '0');
    $projects = (int)scalar("SELECT COUNT(*) FROM Proyecto WHERE activo = 1");
    $people = (int)scalar('SELECT COUNT(*) FROM Usuario WHERE estado_operativo = 1');
    $pending = (int)scalar("SELECT COUNT(*) FROM RegistroTiempo WHERE estado_aprobacion = 'Pendiente'");
    $alerts = (int)scalar('SELECT COUNT(*) FROM AlertaFinOps WHERE leida = 0');
    return [
        'ingresos' => $revenue,
        'mano_obra' => $labor,
        'cloud' => $cloud,
        'costos' => $cost,
        'margen' => $revenue - $cost,
        'margen_pct' => $revenue > 0 ? ($revenue - $cost) / $revenue : 0,
        'cuentas_por_cobrar' => $ar,
        'caja' => $cash,
        'proyectos_activos' => $projects,
        'personas' => $people,
        'timesheets_pendientes' => $pending,
        'alertas' => $alerts,
    ];
}

function monthly_series(int $months = 6, ?int $managerId = null): array
{
    $labels = [];
    $keys = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("first day of -$i months"));
        $keys[] = $key;
        $labels[] = month_label($key);
    }
    $start = date('Y-m-01', strtotime("first day of -" . ($months - 1) . " months"));
    $managerRevenue = $managerId ? ' JOIN Proyecto p ON p.id_proyecto = cf.id_proyecto AND p.id_manager = ?' : '';
    $managerLabor = $managerId ? ' JOIN Proyecto p ON p.id_proyecto = h.id_proyecto AND p.id_manager = ?' : '';
    $managerCloud = $managerId ? ' JOIN Proyecto p ON p.id_proyecto = c.id_proyecto AND p.id_manager = ?' : '';
    $revenueRows = fetch_all(
        "SELECT DATE_FORMAT(cf.fecha_emision, '%Y-%m') k, SUM(cf.monto_total_usd) v FROM ComprobanteFacturacion cf
         $managerRevenue
         WHERE cf.estado IN ('Emitida','Cobrada') AND cf.fecha_emision >= ? GROUP BY k",
        $managerId ? [$managerId, $start] : [$start]
    );
    $laborRows = fetch_all(
        "SELECT DATE_FORMAT(r.fecha_registro, '%Y-%m') k, SUM(r.horas_incurridas * r.fully_loaded_rate_hist) v
         FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito
         $managerLabor
         WHERE r.estado_aprobacion IN ('Aprobado','Facturado') AND r.fecha_registro >= ? GROUP BY k",
        $managerId ? [$managerId, $start] : [$start]
    );
    $cloudRows = fetch_all(
        "SELECT DATE_FORMAT(c.fecha_consumo, '%Y-%m') k, SUM(c.monto_cargado) v FROM ConsumoFinOps c
         $managerCloud
         WHERE c.fecha_consumo >= ? GROUP BY k",
        $managerId ? [$managerId, $start] : [$start]
    );
    $revenue = array_fill_keys($keys, 0.0);
    $labor = array_fill_keys($keys, 0.0);
    $cloud = array_fill_keys($keys, 0.0);
    foreach ($revenueRows as $row) { $revenue[$row['k']] = (float)$row['v']; }
    foreach ($laborRows as $row) { $labor[$row['k']] = (float)$row['v']; }
    foreach ($cloudRows as $row) { $cloud[$row['k']] = (float)$row['v']; }
    $cost = [];
    $margin = [];
    foreach ($keys as $key) {
        $cost[$key] = $labor[$key] + $cloud[$key];
        $margin[$key] = $revenue[$key] - $cost[$key];
    }
    return [
        'keys' => $keys,
        'labels' => $labels,
        'revenue' => array_values($revenue),
        'labor' => array_values($labor),
        'cloud' => array_values($cloud),
        'cost' => array_values($cost),
        'margin' => array_values($margin),
    ];
}

function project_financials_between(string $from, string $to, ?int $managerId = null): array
{
    $managerSql = $managerId ? ' AND p.id_manager = ?' : '';
    $params = [$from, $to, $from, $to, $from, $to, $from, $to, $from, $to];
    if ($managerId) {
        $params[] = $managerId;
    }
    return fetch_all(
        "SELECT p.id_proyecto, p.codigo_unico, p.nombre_proyecto, p.tecnologia, p.metodologia,
                p.modelo_contratacion, p.bac_presupuesto_inicial, p.estado, c.nombre AS cliente,
                u.nombre_completo AS manager,
                (SELECT COALESCE(SUM(cf.monto_total_usd),0) FROM ComprobanteFacturacion cf
                 WHERE cf.id_proyecto = p.id_proyecto AND cf.estado IN ('Emitida','Cobrada')
                   AND DATE(COALESCE(cf.fecha_emision, cf.created_at)) BETWEEN ? AND ?) AS ingresos,
                (SELECT COALESCE(SUM(r.horas_incurridas * r.fully_loaded_rate_hist),0)
                 FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito
                 WHERE h.id_proyecto = p.id_proyecto AND r.estado_aprobacion IN ('Aprobado','Facturado')
                   AND r.fecha_registro BETWEEN ? AND ?) AS costo_mano_obra,
                (SELECT COALESCE(SUM(r.horas_incurridas),0)
                 FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito
                 WHERE h.id_proyecto = p.id_proyecto AND r.estado_aprobacion IN ('Aprobado','Facturado')
                   AND r.fecha_registro BETWEEN ? AND ?) AS horas,
                (SELECT COALESCE(SUM(CASE WHEN t.categoria_contable = 'Billable' THEN r.horas_incurridas ELSE 0 END),0)
                 FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito
                 WHERE h.id_proyecto = p.id_proyecto AND r.estado_aprobacion IN ('Aprobado','Facturado')
                   AND r.fecha_registro BETWEEN ? AND ?) AS horas_billable,
                (SELECT COALESCE(SUM(c2.monto_cargado),0) FROM ConsumoFinOps c2
                 WHERE c2.id_proyecto = p.id_proyecto AND c2.fecha_consumo BETWEEN ? AND ?) AS costo_cloud,
                (SELECT COALESCE(SUM(t2.peso_horas_estimadas),0) FROM Tarea t2 JOIN Hito h2 ON h2.id_hito = t2.id_hito
                 WHERE h2.id_proyecto = p.id_proyecto) AS horas_estimadas,
                (SELECT COALESCE(SUM(CASE WHEN t3.estado_tarea = 'Closed' THEN t3.peso_horas_estimadas ELSE 0 END),0)
                 FROM Tarea t3 JOIN Hito h3 ON h3.id_hito = t3.id_hito WHERE h3.id_proyecto = p.id_proyecto) AS horas_cerradas
         FROM Proyecto p
         JOIN Cliente c ON c.id_cliente = p.id_cliente
         LEFT JOIN Usuario u ON u.id_usuario = p.id_manager
         WHERE p.activo = 1$managerSql
         ORDER BY p.codigo_unico",
        $params
    );
}


function get_config(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (fetch_all('SELECT clave, valor FROM Configuracion') as $row) {
            $cache[$row['clave']] = $row['valor'];
        }
    }
    return $cache[$key] ?? $default;
}

function utilization_rows(?string $from = null, ?string $to = null, ?int $managerId = null): array
{
    $from ??= date('Y-m-01');
    $to ??= date('Y-m-d');
    $extra = '';
    $params = [$from, $to];
    if ($managerId) {
        $extra = " AND EXISTS (
            SELECT 1 FROM RegistroTiempo rr
            JOIN Tarea tt ON tt.id_tarea = rr.id_tarea
            JOIN Hito hh ON hh.id_hito = tt.id_hito
            JOIN Proyecto pp ON pp.id_proyecto = hh.id_proyecto
            WHERE rr.id_usuario = u.id_usuario AND pp.id_manager = ?
              AND rr.fecha_registro BETWEEN ? AND ?
              AND rr.estado_aprobacion IN ('Aprobado','Facturado')
        )";
        $params[] = $managerId;
        $params[] = $from;
        $params[] = $to;
    }
    return fetch_all(
        "SELECT u.id_usuario, u.nombre_completo, u.nivel, u.rol,
                COALESCE(SUM(r.horas_incurridas),0) horas,
                COALESCE(SUM(CASE WHEN t.categoria_contable = 'Billable' THEN r.horas_incurridas ELSE 0 END),0) billable,
                COALESCE(SUM(CASE WHEN t.categoria_contable = 'Operativa' THEN r.horas_incurridas ELSE 0 END),0) operativa,
                COALESCE(SUM(CASE WHEN t.categoria_contable = 'I+D' THEN r.horas_incurridas ELSE 0 END),0) id,
                COALESCE(SUM(r.horas_incurridas * r.fully_loaded_rate_hist),0) costo,
                COALESCE(SUM(r.horas_incurridas * r.billable_rate_hist),0) valor
         FROM Usuario u
         LEFT JOIN RegistroTiempo r ON r.id_usuario = u.id_usuario AND r.estado_aprobacion IN ('Aprobado','Facturado') AND r.fecha_registro BETWEEN ? AND ?
         LEFT JOIN Tarea t ON t.id_tarea = r.id_tarea
         LEFT JOIN Hito h ON h.id_hito = t.id_hito
         LEFT JOIN Proyecto p ON p.id_proyecto = h.id_proyecto
         WHERE u.estado_operativo = 1 AND u.rol = 'DEV' $extra
         GROUP BY u.id_usuario ORDER BY horas DESC",
        $params
    );
}

function cashflow_forecast(int $months = 3): array
{
    $payroll = (float)scalar('SELECT COALESCE(SUM(salario_base + prestaciones_sociales + impuestos_patronales),0) FROM FichaSalarial');
    $assigned = (float)scalar('SELECT COALESCE(SUM(monto_mensual),0) FROM CostoAsignadoUsuario WHERE activo = 1');
    $cloudBudget = (float)scalar('SELECT COALESCE(SUM(presupuesto_mensual),0) FROM PresupuestoFinOps WHERE activo = 1');
    $expense = $payroll + $assigned + $cloudBudget;
    $invoices = fetch_all(
        "SELECT monto_total_usd, fecha_vencimiento FROM ComprobanteFacturacion
         WHERE estado = 'Emitida' ORDER BY fecha_vencimiento"
    );
    $retainers = (float)scalar('SELECT COALESCE(SUM(precio_mensual),0) FROM ContratoRetainer WHERE activo = 1');
    $result = [];
    $cumulative = (float)get_config('saldo_caja', '0');
    for ($i = 0; $i < $months; $i++) {
        $key = date('Y-m', strtotime("first day of +$i months"));
        $income = 0.0;
        foreach ($invoices as $invoice) {
            if ($invoice['fecha_vencimiento'] && date('Y-m', strtotime($invoice['fecha_vencimiento'])) === $key) {
                $income += (float)$invoice['monto_total_usd'];
            }
        }
        $income += $retainers;
        $net = $income - $expense;
        $cumulative += $net;
        $result[] = [
            'periodo' => $key,
            'label' => month_label($key),
            'ingresos' => $income,
            'egresos' => $expense,
            'neto' => $net,
            'acumulado' => $cumulative,
        ];
    }
    return $result;
}
