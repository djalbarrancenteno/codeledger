<?php
require_once 'includes/bootstrap.php';
require_once 'includes/layout.php';

$user = require_login(['CFO', 'PM']);
$isCfo = is_cfo($user);

$projectWhere = 'p.activo = 1';
$projectParams = [];
if (is_pm($user)) {
    $projectWhere .= ' AND p.id_manager = ?';
    $projectParams[] = (int)$user['id_usuario'];
}
$projects = fetch_all("SELECT p.*, c.nombre AS cliente FROM Proyecto p JOIN Cliente c ON c.id_cliente = p.id_cliente WHERE $projectWhere ORDER BY p.codigo_unico", $projectParams);
$visibleIds = array_map(fn($p) => (int)$p['id_proyecto'], $projects);
$projectsById = [];
foreach ($projects as $p) {
    $projectsById[(int)$p['id_proyecto']] = $p;
}
$visibleIdsSql = implode(',', array_map('intval', $visibleIds ?: [0]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'generate_preinvoice') {
        $projectId = (int)($_POST['id_proyecto'] ?? 0);
        $from = (string)($_POST['desde'] ?? date('Y-m-01', strtotime('-1 month')));
        $to = (string)($_POST['hasta'] ?? date('Y-m-d'));
        if (!in_array($projectId, $visibleIds, true)) {
            flash('danger', 'Proyecto no autorizado.');
            redirect('billing.php');
        }
        $rows = fetch_all(
            "SELECT r.id_registro, r.horas_incurridas, r.billable_rate_hist, u.nivel,
                    COALESCE(fs.modalidad_contratacion, 'Interno') modalidad
             FROM RegistroTiempo r
             JOIN Tarea t ON t.id_tarea = r.id_tarea
             JOIN Hito h ON h.id_hito = t.id_hito
             JOIN Usuario u ON u.id_usuario = r.id_usuario
             LEFT JOIN FichaSalarial fs ON fs.id_usuario = u.id_usuario
             WHERE h.id_proyecto = ? AND r.estado_aprobacion = 'Aprobado'
               AND r.id_comprobante IS NULL AND r.billable_rate_hist > 0
               AND r.fecha_registro BETWEEN ? AND ?",
            [$projectId, $from, $to]
        );
        if (!$rows) {
            flash('warning', 'No hay horas facturables aprobadas y sin facturar en ese rango.');
            redirect('billing.php?proyecto=' . $projectId);
        }
        $groups = [];
        $ids = [];
        $total = 0.0;
        $hours = 0.0;
        foreach ($rows as $row) {
            $rate = (float)$row['billable_rate_hist'];
            $key = $row['nivel'] . '|' . $row['modalidad'] . '|' . $rate;
            if (!isset($groups[$key])) {
                $groups[$key] = ['nivel' => $row['nivel'], 'modalidad' => $row['modalidad'], 'rate' => $rate, 'hours' => 0.0, 'ids' => []];
            }
            $groups[$key]['hours'] += (float)$row['horas_incurridas'];
            $groups[$key]['ids'][] = (int)$row['id_registro'];
            $ids[] = (int)$row['id_registro'];
            $total += (float)$row['horas_incurridas'] * $rate;
            $hours += (float)$row['horas_incurridas'];
        }
        if ($total <= 0) {
            flash('warning', 'El valor facturable del rango es cero. Verifica las tarifas de venta.');
            redirect('billing.php?proyecto=' . $projectId);
        }
        $number = next_invoice_number('PRE');
        q(
            'INSERT INTO ComprobanteFacturacion (id_proyecto, id_cliente, numero_correlativo, tipo_comprobante, monto_total, estado, moneda, tipo_cambio, monto_total_usd, metodo_cobro, observaciones)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$projectId, (int)$projectsById[$projectId]['id_cliente'], $number, 'Pre-factura', round($total, 4), 'Pre-factura', 'USD', 1, round($total, 4), 'Transferencia',
             "T&M del $from al $to · " . count($ids) . ' registros de tiempo']
        );
        $invoiceId = (int)db()->lastInsertId();
        foreach ($groups as $group) {
            q(
                'INSERT INTO FacturaDetalle (id_comprobante, concepto, cantidad, precio_unitario, monto) VALUES (?,?,?,?,?)',
                [$invoiceId, 'Horas ' . $group['nivel'] . ' (' . $group['modalidad'] . ') · T&M', round($group['hours'], 2), $group['rate'], round($group['hours'] * $group['rate'], 4)]
            );
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        q("UPDATE RegistroTiempo SET id_comprobante = ? WHERE id_registro IN ($placeholders)", array_merge([$invoiceId], $ids));
        audit('crear', 'ComprobanteFacturacion', $invoiceId, "Pre-factura T&M $number por $total USD");
        flash('success', "Pre-factura $number generada con $hours horas facturables por " . fmt_money($total) . '.');
        redirect('billing.php?proyecto=' . $projectId);
    }

    if ($action === 'manual_invoice') {
        $projectId = (int)($_POST['id_proyecto'] ?? 0);
        $currency = in_array($_POST['moneda'] ?? '', ['USD', 'EUR', 'VES', 'COP'], true) ? $_POST['moneda'] : 'USD';
        $method = in_array($_POST['metodo_cobro'] ?? '', ['Transferencia', 'Stripe', 'PayPal', 'Otro'], true) ? $_POST['metodo_cobro'] : 'Transferencia';
        $hitoId = (int)($_POST['id_hito'] ?? 0);
        $concept = trim((string)($_POST['concepto'] ?? ''));
        $amount = (float)($_POST['monto_total'] ?? 0);
        $emission = (string)($_POST['fecha_emision'] ?? date('Y-m-d'));
        $due = (string)($_POST['fecha_vencimiento'] ?? date('Y-m-d', strtotime('+30 days')));
        $hito = null;
        if (!in_array($projectId, $visibleIds, true)) {
            flash('danger', 'Proyecto no autorizado.');
            redirect('billing.php');
        }
        if ($hitoId) {
            $hito = fetch_one('SELECT * FROM Hito WHERE id_hito = ? AND id_proyecto = ?', [$hitoId, $projectId]);
            if (!$hito || $hito['estado'] !== 'Entregado') {
                flash('danger', 'El hito seleccionado no existe o no ha sido entregado.');
                redirect('billing.php?proyecto=' . $projectId);
            }
            $already = (int)scalar(
                "SELECT COUNT(*) FROM FacturaDetalle d JOIN ComprobanteFacturacion cf ON cf.id_comprobante = d.id_comprobante
                 WHERE d.concepto LIKE ? AND cf.estado <> 'Anulada'",
                ['Hito #' . $hitoId . ' %']
            );
            if ($already > 0) {
                flash('danger', 'Ese hito ya fue facturado.');
                redirect('billing.php?proyecto=' . $projectId);
            }
            $amount = (float)$hito['valor_facturable'];
            $concept = 'Hito #' . $hitoId . ' · ' . $hito['nombre_hito'];
        }
        if ($amount <= 0 || $concept === '') {
            flash('danger', 'Monto y concepto son obligatorios.');
            redirect('billing.php?proyecto=' . $projectId);
        }
        $fx = latest_fx($currency);
        $number = next_invoice_number('INV');
        q(
            'INSERT INTO ComprobanteFacturacion (id_proyecto, id_cliente, numero_correlativo, tipo_comprobante, monto_total, estado, moneda, tipo_cambio, monto_total_usd, metodo_cobro, fecha_emision, fecha_vencimiento, observaciones)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$projectId, (int)$projectsById[$projectId]['id_cliente'], $number, 'Factura', round($amount, 4), 'Emitida', $currency, $fx, round($amount * $fx, 4), $method, $emission, $due,
             $hito ? 'Hito #' . $hitoId . ' · ' . $hito['nombre_hito'] : 'Factura manual']
        );
        $invoiceId = (int)db()->lastInsertId();
        q('INSERT INTO FacturaDetalle (id_comprobante, concepto, cantidad, precio_unitario, monto) VALUES (?,?,1,?,?)', [$invoiceId, $concept, round($amount, 4), round($amount, 4)]);
        if ($hito) {
            create_invoice_recognition($invoiceId, 'Entrega', date('Y-m-d', strtotime($emission . ' -1 day')), $emission);
        } else {
            create_invoice_recognition($invoiceId, 'Lineal', $emission, $due);
        }
        audit('crear', 'ComprobanteFacturacion', $invoiceId, "Factura $number por $amount $currency");
        flash('success', "Factura $number emitida por " . fmt_money($amount, $currency) . '.');
        redirect('billing.php?proyecto=' . $projectId);
    }

    if ($action === 'issue_invoice') {
        $invoiceId = (int)($_POST['id_comprobante'] ?? 0);
        $invoice = fetch_one('SELECT * FROM ComprobanteFacturacion WHERE id_comprobante = ?', [$invoiceId]);
        if (!$invoice || !in_array((int)$invoice['id_proyecto'], $visibleIds, true)) {
            flash('danger', 'Comprobante no autorizado.');
        } elseif ($invoice['estado'] !== 'Pre-factura') {
            flash('warning', 'Solo las pre-facturas pueden emitirse.');
        } else {
            $emission = date('Y-m-d');
            $due = date('Y-m-d', strtotime('+30 days'));
            q(
                'UPDATE ComprobanteFacturacion SET estado = "Emitida", fecha_emision = ?, fecha_vencimiento = ?, tipo_cambio = ?, monto_total_usd = ? WHERE id_comprobante = ?',
                [$emission, $due, latest_fx($invoice['moneda']), round((float)$invoice['monto_total'] * latest_fx($invoice['moneda']), 4), $invoiceId]
            );
            mark_time_invoiced($invoiceId);
            create_invoice_recognition($invoiceId, 'Lineal', $emission, $due);
            audit('emitir', 'ComprobanteFacturacion', $invoiceId, "Emisión de {$invoice['numero_correlativo']}");
            flash('success', 'Pre-factura emitida y horas vinculadas marcadas como facturadas.');
        }
        redirect('billing.php?proyecto=' . (int)($invoice['id_proyecto'] ?? 0));
    }

    if ($action === 'collect_invoice') {
        $invoiceId = (int)($_POST['id_comprobante'] ?? 0);
        $invoice = fetch_one('SELECT * FROM ComprobanteFacturacion WHERE id_comprobante = ?', [$invoiceId]);
        $method = in_array($_POST['metodo_cobro'] ?? '', ['Transferencia', 'Stripe', 'PayPal', 'Otro'], true) ? $_POST['metodo_cobro'] : 'Transferencia';
        if (!$invoice || !in_array((int)$invoice['id_proyecto'], $visibleIds, true)) {
            flash('danger', 'Comprobante no autorizado.');
        } elseif ($invoice['estado'] !== 'Emitida') {
            flash('warning', 'Solo las facturas emitidas pueden marcarse como cobradas.');
        } else {
            q('UPDATE ComprobanteFacturacion SET estado = "Cobrada", fecha_cobro = CURDATE(), metodo_cobro = ? WHERE id_comprobante = ?', [$method, $invoiceId]);
            audit('cobrar', 'ComprobanteFacturacion', $invoiceId, "Cobro de {$invoice['numero_correlativo']}");
            flash('success', 'Cobro conciliado correctamente.');
        }
        redirect('billing.php?proyecto=' . (int)($invoice['id_proyecto'] ?? 0));
    }

    if ($action === 'annul_invoice') {
        $invoiceId = (int)($_POST['id_comprobante'] ?? 0);
        $invoice = fetch_one('SELECT * FROM ComprobanteFacturacion WHERE id_comprobante = ?', [$invoiceId]);
        if (!$invoice || !in_array((int)$invoice['id_proyecto'], $visibleIds, true)) {
            flash('danger', 'Comprobante no autorizado.');
        } elseif ($invoice['estado'] === 'Cobrada') {
            flash('danger', 'Una factura cobrada no puede anularse sin nota de crédito.');
        } elseif ($invoice['estado'] === 'Anulada') {
            flash('warning', 'El comprobante ya está anulado.');
        } else {
            q('UPDATE ComprobanteFacturacion SET estado = "Anulada" WHERE id_comprobante = ?', [$invoiceId]);
            q("UPDATE RegistroTiempo SET estado_aprobacion = 'Aprobado', id_comprobante = NULL, facturado_en = NULL WHERE id_comprobante = ? AND estado_aprobacion = 'Facturado'", [$invoiceId]);
            q('UPDATE RegistroTiempo SET id_comprobante = NULL WHERE id_comprobante = ?', [$invoiceId]);
            q('DELETE FROM ReconocimientoIngreso WHERE id_comprobante = ?', [$invoiceId]);
            audit('anular', 'ComprobanteFacturacion', $invoiceId, "Anulación de {$invoice['numero_correlativo']}");
            flash('success', 'Comprobante anulado. Las horas volvieron a estado aprobado y pueden refacturarse.');
        }
        redirect('billing.php?proyecto=' . (int)($invoice['id_proyecto'] ?? 0));
    }

    if ($action === 'save_retainer') {
        $projectId = (int)($_POST['id_proyecto'] ?? 0);
        $hours = (float)($_POST['horas_incluidas_mes'] ?? 0);
        $price = (float)($_POST['precio_mensual'] ?? 0);
        $rollover = (float)($_POST['rollover_maximo'] ?? 0);
        $start = (string)($_POST['fecha_inicio'] ?? date('Y-m-d'));
        if (!in_array($projectId, $visibleIds, true) || $price <= 0) {
            flash('danger', 'Datos de retainer inválidos.');
        } else {
            q(
                'INSERT INTO ContratoRetainer (id_proyecto, horas_incluidas_mes, precio_mensual, rollover_maximo, fecha_inicio, activo)
                 VALUES (?,?,?,?,?,1)
                 ON DUPLICATE KEY UPDATE horas_incluidas_mes = VALUES(horas_incluidas_mes), precio_mensual = VALUES(precio_mensual), rollover_maximo = VALUES(rollover_maximo), fecha_inicio = VALUES(fecha_inicio), activo = 1',
                [$projectId, $hours, $price, $rollover, $start]
            );
            audit('actualizar', 'ContratoRetainer', $projectId, 'Contrato retainer actualizado');
            flash('success', 'Contrato retainer guardado.');
        }
        redirect('billing.php?proyecto=' . $projectId . '#retainers');
    }

    if ($action === 'bill_retainer') {
        $retainerId = (int)($_POST['id_retainer'] ?? 0);
        $contract = fetch_one('SELECT cr.*, p.codigo_unico, p.nombre_proyecto, p.moneda_contrato, p.id_cliente, p.id_proyecto FROM ContratoRetainer cr JOIN Proyecto p ON p.id_proyecto = cr.id_proyecto WHERE cr.id_retainer = ?', [$retainerId]);
        if (!$contract || !in_array((int)$contract['id_proyecto'], $visibleIds, true)) {
            flash('danger', 'Contrato retainer no autorizado.');
            redirect('billing.php');
        }
        $period = date('Y-m');
        $already = fetch_one('SELECT * FROM ConsumoRetainer WHERE id_retainer = ? AND periodo = ? AND facturado = 1', [$retainerId, $period]);
        if ($already) {
            flash('warning', 'El retainer de este período ya fue facturado.');
            redirect('billing.php?proyecto=' . (int)$contract['id_proyecto'] . '#retainers');
        }
        $summary = retainer_summary($retainerId);
        $currency = $contract['moneda_contrato'] ?: 'USD';
        $price = (float)$contract['precio_mensual'];
        $fx = latest_fx($currency);
        $number = next_invoice_number('INV');
        q(
            'INSERT INTO ComprobanteFacturacion (id_proyecto, id_cliente, numero_correlativo, tipo_comprobante, monto_total, estado, moneda, tipo_cambio, monto_total_usd, metodo_cobro, fecha_emision, fecha_vencimiento, observaciones)
             VALUES (?,?,?,?,?,?,?,?,?,?,CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY),?)',
            [(int)$contract['id_proyecto'], (int)$contract['id_cliente'], $number, 'Factura', $price, 'Emitida', $currency, $fx, round($price * $fx, 4), 'Transferencia',
             'Retainer ' . $period . ' · ' . $contract['codigo_unico']]
        );
        $invoiceId = (int)db()->lastInsertId();
        q(
            'INSERT INTO FacturaDetalle (id_comprobante, concepto, cantidad, precio_unitario, monto) VALUES (?,?,1,?,?)',
            [$invoiceId, 'Retainer mensual ' . $period . ' · ' . $contract['nombre_proyecto'], $price, $price]
        );
        q(
            'INSERT INTO ConsumoRetainer (id_retainer, periodo, horas_consumidas, saldo_inicial, saldo_final, facturado) VALUES (?,?,?,?,?,1)
             ON DUPLICATE KEY UPDATE horas_consumidas = VALUES(horas_consumidas), saldo_inicial = VALUES(saldo_inicial), saldo_final = VALUES(saldo_final), facturado = 1',
            [$retainerId, $period, $summary['horas_consumidas'] ?? 0, $summary['saldo_inicial'] ?? 0, $summary['saldo_final'] ?? 0]
        );
        create_invoice_recognition($invoiceId, 'Lineal', date('Y-m-01'), date('Y-m-t'));
        audit('crear', 'ComprobanteFacturacion', $invoiceId, "Factura retainer $number por $price $currency");
        flash('success', "Retainer facturado: $number por " . fmt_money($price, $currency) . '.');
        redirect('billing.php?proyecto=' . (int)$contract['id_proyecto'] . '#retainers');
    }

    if ($action === 'update_recognitions') {
        $count = update_recognitions();
        flash('success', "Reconocimiento de ingresos recalculado en $count registro(s).");
        redirect('billing.php');
    }
}

$statusFilter = (string)($_GET['estado'] ?? '');
$typeFilter = (string)($_GET['tipo'] ?? '');
$projectFilter = (int)($_GET['proyecto'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));

$where = ["cf.id_proyecto IN ($visibleIdsSql)"];
$params = [];
if (in_array($statusFilter, ['Pre-factura', 'Emitida', 'Cobrada', 'Anulada'], true)) {
    $where[] = 'cf.estado = ?';
    $params[] = $statusFilter;
}
if (in_array($typeFilter, ['Factura', 'Nota de Credito', 'Pre-factura'], true)) {
    $where[] = 'cf.tipo_comprobante = ?';
    $params[] = $typeFilter;
}
if ($projectFilter > 0 && in_array($projectFilter, $visibleIds, true)) {
    $where[] = 'cf.id_proyecto = ?';
    $params[] = $projectFilter;
}
if ($search !== '') {
    $where[] = '(cf.numero_correlativo LIKE ? OR cf.observaciones LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sqlWhere = implode(' AND ', $where);
$invoices = fetch_all(
    "SELECT cf.*, p.codigo_unico, p.nombre_proyecto, c.nombre AS cliente
     FROM ComprobanteFacturacion cf
     JOIN Proyecto p ON p.id_proyecto = cf.id_proyecto
     JOIN Cliente c ON c.id_cliente = cf.id_cliente
     WHERE $sqlWhere ORDER BY COALESCE(cf.fecha_emision, DATE(cf.created_at)) DESC, cf.id_comprobante DESC LIMIT 250",
    $params
);
$invoiceIds = array_map(fn($i) => (int)$i['id_comprobante'], $invoices);
$detailsByInvoice = [];
$recognitionByInvoice = [];
if ($invoiceIds) {
    $idList = implode(',', $invoiceIds);
    foreach (fetch_all("SELECT * FROM FacturaDetalle WHERE id_comprobante IN ($idList) ORDER BY id_detalle") as $detail) {
        $detailsByInvoice[(int)$detail['id_comprobante']][] = $detail;
    }
    foreach (fetch_all("SELECT * FROM ReconocimientoIngreso WHERE id_comprobante IN ($idList)") as $rec) {
        $recognitionByInvoice[(int)$rec['id_comprobante']] = $rec;
    }
}

$kpiRevenue = (float)scalar(
    "SELECT COALESCE(SUM(monto_total_usd),0) FROM ComprobanteFacturacion WHERE id_proyecto IN ($visibleIdsSql) AND estado IN ('Emitida','Cobrada') AND YEAR(COALESCE(fecha_emision, created_at)) = YEAR(CURDATE())"
);
$kpiCollected = (float)scalar(
    "SELECT COALESCE(SUM(monto_total_usd),0) FROM ComprobanteFacturacion WHERE id_proyecto IN ($visibleIdsSql) AND estado = 'Cobrada' AND YEAR(COALESCE(fecha_cobro, fecha_emision, created_at)) = YEAR(CURDATE())"
);
$kpiAr = (float)scalar(
    "SELECT COALESCE(SUM(monto_total_usd),0) FROM ComprobanteFacturacion WHERE id_proyecto IN ($visibleIdsSql) AND estado = 'Emitida'"
);
$kpiDeferred = (float)scalar(
    "SELECT COALESCE(SUM(ri.monto_total - ri.monto_reconocido),0)
     FROM ReconocimientoIngreso ri JOIN ComprobanteFacturacion cf ON cf.id_comprobante = ri.id_comprobante
     WHERE cf.id_proyecto IN ($visibleIdsSql) AND cf.estado IN ('Emitida','Cobrada') AND ri.monto_reconocido < ri.monto_total"
);

$deferredRows = fetch_all(
    "SELECT ri.*, cf.numero_correlativo, cf.moneda, p.codigo_unico, p.nombre_proyecto
     FROM ReconocimientoIngreso ri JOIN ComprobanteFacturacion cf ON cf.id_comprobante = ri.id_comprobante
     JOIN Proyecto p ON p.id_proyecto = cf.id_proyecto
     WHERE cf.id_proyecto IN ($visibleIdsSql) AND cf.estado IN ('Emitida','Cobrada') AND ri.monto_reconocido < ri.monto_total
     ORDER BY ri.fecha_fin"
);

$retainers = fetch_all(
    "SELECT cr.*, p.codigo_unico, p.nombre_proyecto, p.moneda_contrato
     FROM ContratoRetainer cr JOIN Proyecto p ON p.id_proyecto = cr.id_proyecto
     WHERE cr.activo = 1 AND cr.id_proyecto IN ($visibleIdsSql) ORDER BY p.codigo_unico"
);
$retainerSummaries = [];
foreach ($retainers as $contract) {
    $summary = retainer_summary((int)$contract['id_retainer']);
    $summary['facturado_periodo'] = (bool)fetch_one('SELECT id_consumo_ret FROM ConsumoRetainer WHERE id_retainer = ? AND periodo = ? AND facturado = 1', [(int)$contract['id_retainer'], date('Y-m')]);
    $retainerSummaries[(int)$contract['id_retainer']] = $summary;
}

$milestones = fetch_all(
    "SELECT h.*, p.codigo_unico, p.moneda_contrato,
        (SELECT COUNT(*) FROM FacturaDetalle d JOIN ComprobanteFacturacion cf ON cf.id_comprobante = d.id_comprobante
         WHERE d.concepto LIKE CONCAT('Hito #', h.id_hito, ' %') AND cf.estado <> 'Anulada') AS facturado
     FROM Hito h JOIN Proyecto p ON p.id_proyecto = h.id_proyecto
     WHERE h.estado = 'Entregado' AND h.valor_facturable > 0 AND p.id_proyecto IN ($visibleIdsSql)
     ORDER BY p.codigo_unico, h.id_hito"
);

$chartKeys = [];
for ($i = 5; $i >= 0; $i--) {
    $chartKeys[] = date('Y-m', strtotime("first day of -$i months"));
}
$chartInvoiced = array_fill_keys($chartKeys, 0.0);
$chartCollected = array_fill_keys($chartKeys, 0.0);
$rows = fetch_all(
    "SELECT DATE_FORMAT(fecha_emision,'%Y-%m') k, SUM(monto_total_usd) v FROM ComprobanteFacturacion
     WHERE id_proyecto IN ($visibleIdsSql) AND estado IN ('Emitida','Cobrada') AND fecha_emision >= ? GROUP BY k",
    [date('Y-m-01', strtotime('first day of -5 months'))]
);
foreach ($rows as $row) {
    if (isset($chartInvoiced[$row['k']])) {
        $chartInvoiced[$row['k']] = (float)$row['v'];
    }
}
$rows = fetch_all(
    "SELECT DATE_FORMAT(fecha_cobro,'%Y-%m') k, SUM(monto_total_usd) v FROM ComprobanteFacturacion
     WHERE id_proyecto IN ($visibleIdsSql) AND estado = 'Cobrada' AND fecha_cobro >= ? GROUP BY k",
    [date('Y-m-01', strtotime('first day of -5 months'))]
);
foreach ($rows as $row) {
    if (isset($chartCollected[$row['k']])) {
        $chartCollected[$row['k']] = (float)$row['v'];
    }
}

render_header('Facturación y reconocimiento de ingresos', $user, 'billing');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h2 class="section-title">Cobros, pre-facturas y ASC 606 / NIIF 15</h2>
        <div class="section-sub">T&M, Fixed Price, Retainer, multiplataforma de cobro y reconocimiento diferido</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="update_recognitions">
            <button class="btn btn-ghost btn-sm" type="submit"><i class="bi bi-arrow-repeat"></i> Recalcular reconocimiento</button>
        </form>
        <button class="btn btn-ghost btn-sm" data-bs-toggle="modal" data-bs-target="#preinvoiceModal"><i class="bi bi-clock-history"></i> Pre-factura T&M</button>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#manualInvoiceModal"><i class="bi bi-plus-lg"></i> Nueva factura</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#4f46e5;--accent-soft:#eef2ff">
            <div class="kpi-label">Ingresos facturados <?= date('Y') ?></div>
            <div class="kpi-value"><?= fmt_money_short($kpiRevenue) ?></div>
            <div class="kpi-foot"><i class="bi bi-receipt"></i> Emitidas y cobradas</div>
            <div class="kpi-icon"><i class="bi bi-receipt"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#16a34a;--accent-soft:#dcfce7">
            <div class="kpi-label">Cobrado <?= date('Y') ?></div>
            <div class="kpi-value"><?= fmt_money_short($kpiCollected) ?></div>
            <div class="kpi-foot"><i class="bi bi-check2-circle"></i> Conciliado en bancos</div>
            <div class="kpi-icon"><i class="bi bi-check2-circle"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#d97706;--accent-soft:#fef3c7">
            <div class="kpi-label">Cuentas por cobrar</div>
            <div class="kpi-value"><?= fmt_money_short($kpiAr) ?></div>
            <div class="kpi-foot"><i class="bi bi-hourglass-split"></i> Facturas emitidas pendientes</div>
            <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="kpi-card" style="--accent:#0891b2;--accent-soft:#cffafe">
            <div class="kpi-label">Ingreso diferido</div>
            <div class="kpi-value"><?= fmt_money_short($kpiDeferred) ?></div>
            <div class="kpi-foot"><i class="bi bi-calendar-check"></i> Pendiente de reconocer</div>
            <div class="kpi-icon"><i class="bi bi-calendar-check"></i></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><span>Facturación vs. cobranza</span><span class="section-sub">Últimos 6 meses · USD</span></div>
            <div class="card-body"><div class="chart-box"><canvas id="billingChart"></canvas></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Estado de la cartera</div>
            <div class="card-body">
                <div class="stat-row"><span>Facturado <?= date('Y') ?></span><strong><?= fmt_money($kpiRevenue) ?></strong></div>
                <div class="stat-row"><span>Cobrado <?= date('Y') ?></span><strong class="text-positive"><?= fmt_money($kpiCollected) ?></strong></div>
                <div class="stat-row"><span>Por cobrar</span><strong class="text-negative"><?= fmt_money($kpiAr) ?></strong></div>
                <div class="stat-row"><span>Diferido (ASC 606)</span><strong><?= fmt_money($kpiDeferred) ?></strong></div>
                <div class="stat-row"><span>Tasa de cobro</span><strong><?= fmt_ratio_pct($kpiRevenue > 0 ? $kpiCollected / $kpiRevenue : 0) ?></strong></div>
                <div class="alert alert-info mt-3 mb-0 small"><i class="bi bi-info-circle"></i> Los ingresos por adelantado permanecen en pasivo hasta que la funcionalidad se entrega o el servicio se consume.</div>
            </div>
        </div>
    </div>
</div>

<form class="filter-bar mb-4" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">Buscar</label><input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Número u observación..."></div>
        <div class="col-md-2"><label class="form-label">Proyecto</label><select class="form-select form-select-sm" name="proyecto"><option value="">Todos</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id_proyecto'] ?>" <?= $projectFilter === (int)$p['id_proyecto'] ? 'selected' : '' ?>><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Estado</label><select class="form-select form-select-sm" name="estado"><option value="">Todos</option><?php foreach (['Pre-factura','Emitida','Cobrada','Anulada'] as $s): ?><option <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Tipo</label><select class="form-select form-select-sm" name="tipo"><option value="">Todos</option><?php foreach (['Factura','Pre-factura','Nota de Credito'] as $t): ?><option <?= $typeFilter === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary btn-sm flex-grow-1" type="submit">Filtrar</button><a href="billing.php" class="btn btn-ghost btn-sm"><i class="bi bi-arrow-counterclockwise"></i></a></div>
    </div>
</form>

<div class="card mb-4">
    <div class="card-header"><span>Comprobantes</span><span class="section-sub"><?= count($invoices) ?> resultados</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Número</th><th>Proyecto / Cliente</th><th>Tipo</th><th>Estado</th><th>Emisión</th><th>Vencimiento</th><th class="num">Monto</th><th class="num">USD</th><th class="num">Reconocido</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $invoice): ?>
                <?php
                $rec = $recognitionByInvoice[(int)$invoice['id_comprobante']] ?? null;
                $details = $detailsByInvoice[(int)$invoice['id_comprobante']] ?? [];
                $recPct = $rec && (float)$rec['monto_total'] > 0 ? (float)$rec['monto_reconocido'] / (float)$rec['monto_total'] : 0;
                ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= e($invoice['numero_correlativo']) ?></div>
                        <div class="text-muted small"><?= $details ? count($details) . ' línea(s)' : 'Sin detalle' ?></div>
                    </td>
                    <td><div class="fw-semibold"><?= e($invoice['codigo_unico']) ?></div><div class="text-muted small"><?= e($invoice['cliente']) ?></div></td>
                    <td><span class="tag"><?= e($invoice['tipo_comprobante']) ?></span></td>
                    <td><?= status_badge($invoice['estado']) ?></td>
                    <td class="text-muted small"><?= fmt_date($invoice['fecha_emision']) ?></td>
                    <td class="text-muted small"><?= fmt_date($invoice['fecha_vencimiento']) ?></td>
                    <td class="num fw-semibold"><?= fmt_money($invoice['monto_total'], $invoice['moneda']) ?></td>
                    <td class="num"><?= fmt_money($invoice['monto_total_usd']) ?></td>
                    <td class="num" style="min-width:120px">
                        <?php if ($rec): ?>
                            <?= fmt_ratio_pct($recPct, 0) ?>
                            <div class="progress mt-1"><div class="progress-bar bg-info" style="width:<?= min(100, $recPct * 100) ?>%"></div></div>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="d-flex flex-wrap gap-1 justify-content-end">
                            <?php if ($invoice['estado'] === 'Pre-factura'): ?>
                                <form method="post" data-confirm="¿Emitir esta pre-factura? Las horas quedarán marcadas como facturadas.">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="issue_invoice"><input type="hidden" name="id_comprobante" value="<?= (int)$invoice['id_comprobante'] ?>">
                                    <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-send"></i> Emitir</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($invoice['estado'] === 'Emitida'): ?>
                                <form method="post" data-confirm="¿Registrar el cobro de esta factura?">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="collect_invoice"><input type="hidden" name="id_comprobante" value="<?= (int)$invoice['id_comprobante'] ?>">
                                    <button class="btn btn-sm btn-outline-success" type="submit"><i class="bi bi-cash-coin"></i> Cobrar</button>
                                </form>
                            <?php endif; ?>
                            <?php if (in_array($invoice['estado'], ['Pre-factura', 'Emitida'], true)): ?>
                                <form method="post" data-confirm="¿Anular este comprobante?">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="annul_invoice"><input type="hidden" name="id_comprobante" value="<?= (int)$invoice['id_comprobante'] ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit" title="Anular"><i class="bi bi-x-circle"></i></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($details): ?>
                                <button class="btn btn-sm btn-ghost" type="button" data-bs-toggle="collapse" data-bs-target="#det-<?= (int)$invoice['id_comprobante'] ?>" title="Ver detalle"><i class="bi bi-list-ul"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php if ($details): ?>
                <tr class="collapse" id="det-<?= (int)$invoice['id_comprobante'] ?>">
                    <td colspan="10" class="bg-light">
                        <div class="p-2">
                            <div class="fw-semibold small mb-2">Detalle de <?= e($invoice['numero_correlativo']) ?></div>
                            <table class="table table-sm mb-0">
                                <thead><tr><th>Concepto</th><th class="num">Cantidad</th><th class="num">Precio unitario</th><th class="num">Monto</th></tr></thead>
                                <tbody>
                                <?php foreach ($details as $detail): ?>
                                    <tr>
                                        <td><?= e($detail['concepto']) ?></td>
                                        <td class="num"><?= fmt_number($detail['cantidad'], 2) ?></td>
                                        <td class="num"><?= fmt_money($detail['precio_unitario'], $invoice['moneda']) ?></td>
                                        <td class="num"><?= fmt_money($detail['monto'], $invoice['moneda']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if ($rec): ?>
                                <div class="text-muted small mt-2"><i class="bi bi-calendar3"></i> Reconocimiento <?= e($rec['metodo']) ?> del <?= fmt_date($rec['fecha_inicio']) ?> al <?= fmt_date($rec['fecha_fin']) ?> · <?= e($rec['estado']) ?> · reconocido <?= fmt_money($rec['monto_reconocido'], $invoice['moneda']) ?> de <?= fmt_money($rec['monto_total'], $invoice['moneda']) ?></div>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$invoices): ?><tr><td colspan="10"><div class="empty-state"><i class="bi bi-receipt-cutoff"></i>No hay comprobantes para los filtros seleccionados.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mb-4" id="retainers">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <span>Contratos Retainer y consumo de bolsas de horas</span>
                <button class="btn btn-sm btn-ghost" data-bs-toggle="modal" data-bs-target="#retainerModal"><i class="bi bi-plus-lg"></i> Configurar retainer</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead><tr><th>Proyecto</th><th class="num">Horas incluidas</th><th class="num">Precio mensual</th><th class="num">Consumidas</th><th class="num">Saldo inicial</th><th class="num">Saldo final</th><th class="num">Rollover máx.</th><th>Consumo</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($retainers as $contract): ?>
                        <?php $summary = $retainerSummaries[(int)$contract['id_retainer']]; ?>
                        <tr>
                            <td><div class="fw-semibold"><?= e($contract['codigo_unico']) ?></div><div class="text-muted small"><?= e($contract['nombre_proyecto']) ?></div></td>
                            <td class="num"><?= fmt_number($contract['horas_incluidas_mes'], 1) ?> h</td>
                            <td class="num"><?= fmt_money($contract['precio_mensual'], $contract['moneda_contrato']) ?></td>
                            <td class="num fw-semibold"><?= fmt_number($summary['horas_consumidas'] ?? 0, 1) ?> h</td>
                            <td class="num"><?= fmt_number($summary['saldo_inicial'] ?? 0, 1) ?> h</td>
                            <td class="num"><?= fmt_number($summary['saldo_final'] ?? 0, 1) ?> h</td>
                            <td class="num"><?= fmt_number($contract['rollover_maximo'], 1) ?> h</td>
                            <td style="min-width:130px">
                                <?php $pct = $summary['consumo_pct'] ?? 0; ?>
                                <div class="progress-label"><span><?= fmt_ratio_pct($pct, 0) ?></span><span><?= ($summary['horas_excedidas'] ?? 0) > 0 ? 'Excedido' : 'En bolsa' ?></span></div>
                                <div class="progress"><div class="progress-bar <?= $pct > 1 ? 'bg-danger' : 'bg-info' ?>" style="width:<?= min(100, $pct * 100) ?>%"></div></div>
                            </td>
                            <td class="text-end">
                                <?php if ($summary['facturado_periodo']): ?>
                                    <span class="tag"><i class="bi bi-check2"></i> Facturado</span>
                                <?php else: ?>
                                    <form method="post" data-confirm="¿Generar la factura del retainer del mes actual?">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="bill_retainer"><input type="hidden" name="id_retainer" value="<?= (int)$contract['id_retainer'] ?>">
                                        <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-receipt"></i> Facturar mes</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$retainers): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-arrow-repeat"></i>No hay contratos retainer activos.</div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><span>Ingresos diferidos pendientes de reconocimiento</span><span class="section-sub">ASC 606 / NIIF 15</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead><tr><th>Comprobante</th><th>Proyecto</th><th>Método</th><th>Período</th><th class="num">Total</th><th class="num">Reconocido</th><th class="num">Diferido</th><th>Estado</th></tr></thead>
            <tbody>
            <?php foreach ($deferredRows as $row): ?>
                <tr>
                    <td class="fw-semibold"><?= e($row['numero_correlativo']) ?></td>
                    <td><div class="fw-semibold"><?= e($row['codigo_unico']) ?></div><div class="text-muted small"><?= e($row['nombre_proyecto']) ?></div></td>
                    <td><span class="tag"><?= e($row['metodo']) ?></span></td>
                    <td class="text-muted small"><?= fmt_date($row['fecha_inicio']) ?> → <?= fmt_date($row['fecha_fin']) ?></td>
                    <td class="num"><?= fmt_money($row['monto_total'], $row['moneda']) ?></td>
                    <td class="num"><?= fmt_money($row['monto_reconocido'], $row['moneda']) ?></td>
                    <td class="num fw-semibold"><?= fmt_money((float)$row['monto_total'] - (float)$row['monto_reconocido'], $row['moneda']) ?></td>
                    <td><?= status_badge($row['estado']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$deferredRows): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-check2-all"></i>No hay ingresos diferidos pendientes.</div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="preinvoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="generate_preinvoice">
            <div class="modal-header"><h5 class="modal-title">Generar pre-factura T&M</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Proyecto</label><select class="form-select" name="id_proyecto" required><option value="">Selecciona...</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id_proyecto'] ?>"><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?> · <?= e($p['modelo_contratacion']) ?></option><?php endforeach; ?></select></div>
                <div class="row g-3">
                    <div class="col-6"><label class="form-label">Desde</label><input type="date" class="form-control" name="desde" value="<?= date('Y-m-01') ?>"></div>
                    <div class="col-6"><label class="form-label">Hasta</label><input type="date" class="form-control" name="hasta" value="<?= date('Y-m-d') ?>"></div>
                </div>
                <div class="alert alert-info mt-3 mb-0 py-2 small"><i class="bi bi-info-circle"></i> Se incluirán solo horas aprobadas, facturables, con tarifa congelada y sin comprobante asignado. Se agrupan por nivel y modalidad.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Generar pre-factura</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="manualInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="manual_invoice">
            <div class="modal-header"><h5 class="modal-title">Nueva factura / hito</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Proyecto</label><select class="form-select" name="id_proyecto" id="invoiceProject" required><option value="">Selecciona...</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id_proyecto'] ?>"><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Hito entregado (Fixed Price, opcional)</label><select class="form-select" name="id_hito" id="invoiceMilestone"><option value="">Factura manual sin hito</option><?php foreach ($milestones as $h): ?><option value="<?= (int)$h['id_hito'] ?>" data-project="<?= (int)$h['id_proyecto'] ?>" data-amount="<?= e($h['valor_facturable']) ?>" <?= (int)$h['facturado'] > 0 ? 'disabled' : '' ?>><?= e($h['codigo_unico']) ?> · Hito #<?= (int)$h['id_hito'] ?> · <?= e($h['nombre_hito']) ?> · <?= fmt_money($h['valor_facturable']) ?><?= (int)$h['facturado'] > 0 ? ' (facturado)' : '' ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-8"><label class="form-label">Concepto</label><input class="form-control" name="concepto" placeholder="Descripción de la factura"></div>
                    <div class="col-md-4"><label class="form-label">Monto</label><input type="number" step="0.01" min="0" class="form-control" name="monto_total" id="invoiceAmount" value="0"></div>
                    <div class="col-md-4"><label class="form-label">Moneda</label><select class="form-select" name="moneda"><option>USD</option><option>EUR</option><option>VES</option><option>COP</option></select></div>
                    <div class="col-md-4"><label class="form-label">Método de cobro</label><select class="form-select" name="metodo_cobro"><option>Transferencia</option><option>Stripe</option><option>PayPal</option><option>Otro</option></select></div>
                    <div class="col-md-4"><label class="form-label">Emisión</label><input type="date" class="form-control" name="fecha_emision" value="<?= date('Y-m-d') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Vencimiento</label><input type="date" class="form-control" name="fecha_vencimiento" value="<?= date('Y-m-d', strtotime('+30 days')) ?>"></div>
                </div>
                <div class="alert alert-info mt-3 mb-0 py-2 small"><i class="bi bi-info-circle"></i> Los hitos entregados se reconocen al 100% en la fecha de entrega. Las facturas manuales se reconocen linealmente hasta su vencimiento.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Emitir factura</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="retainerModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="save_retainer">
            <div class="modal-header"><h5 class="modal-title">Configurar contrato retainer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label">Proyecto</label><select class="form-select" name="id_proyecto" required><option value="">Selecciona...</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id_proyecto'] ?>"><?= e($p['codigo_unico']) ?> · <?= e($p['nombre_proyecto']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label">Horas incluidas / mes</label><input type="number" step="0.5" min="0" class="form-control" name="horas_incluidas_mes" value="60" required></div>
                    <div class="col-6"><label class="form-label">Precio mensual</label><input type="number" step="0.01" min="1" class="form-control" name="precio_mensual" required></div>
                    <div class="col-6"><label class="form-label">Rollover máximo (h)</label><input type="number" step="0.5" min="0" class="form-control" name="rollover_maximo" value="0"></div>
                    <div class="col-6"><label class="form-label">Inicio del contrato</label><input type="date" class="form-control" name="fecha_inicio" value="<?= date('Y-m-d') ?>"></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar contrato</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const chart = document.getElementById('billingChart');
    if (chart) {
        new Chart(chart, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($k) => month_label($k), $chartKeys)) ?>,
                datasets: [
                    { label: 'Facturado', data: <?= json_encode(array_values($chartInvoiced)) ?>, backgroundColor: 'rgba(79,70,229,.82)', borderRadius: 5 },
                    { label: 'Cobrado', data: <?= json_encode(array_values($chartCollected)) ?>, backgroundColor: 'rgba(22,163,74,.72)', borderRadius: 5 }
                ]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { ticks: { callback: v => 'USD ' + v } } } }
        });
    }

    const project = document.getElementById('invoiceProject');
    const milestone = document.getElementById('invoiceMilestone');
    const amount = document.getElementById('invoiceAmount');
    if (project && milestone) {
        const filter = () => {
            const selected = project.value;
            Array.from(milestone.options).forEach((option, index) => {
                if (index === 0) return;
                const match = !selected || option.dataset.project === selected;
                option.hidden = !match;
                option.disabled = option.dataset.disabled === '1' || !match;
                if (!match) option.selected = false;
            });
            if (milestone.selectedOptions[0]?.hidden) milestone.value = '';
        };
        milestone.querySelectorAll('option[disabled]').forEach(o => o.dataset.disabled = '1');
        project.addEventListener('change', filter);
        milestone.addEventListener('change', () => {
            const option = milestone.selectedOptions[0];
            if (option && option.dataset.amount) amount.value = option.dataset.amount;
        });
        filter();
    }
});
</script>
<?php
render_footer();
