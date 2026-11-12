<?php
/**
 * CodeLedger - Generador de datos demo.
 * Uso: php scripts/seed_demo.php
 * Requiere haber importado sql/database.sql previamente.
 */
declare(strict_types=1);

require __DIR__ . '/../config/database.php';

date_default_timezone_set('UTC');

function d(string $modifier = ''): string {
    $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $modifier === '' ? $dt->format('Y-m-d') : $dt->modify($modifier)->format('Y-m-d');
}

function dt(string $modifier = ''): string {
    $d = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $modifier === '' ? $d->format('Y-m-d H:i:s') : $d->modify($modifier)->format('Y-m-d H:i:s');
}

function money(float $n): float {
    return round($n, 4);
}

echo "Sembrando datos demo de CodeLedger...\n";

$pdo->beginTransaction();
try {
    // ---------------------------------------------------------
    // Limpieza (permite re-ejecutar el seeder)
    // ---------------------------------------------------------
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'EventoAuditoria', 'SyncLog', 'Integracion', 'ConsumoRetainer', 'ContratoRetainer',
        'ActivoIntangible', 'ReglaContable', 'AlertaFinOps', 'PresupuestoFinOps', 'ConsumoFinOps',
        'ReconocimientoIngreso', 'FacturaDetalle', 'ComprobanteFacturacion', 'RegistroTiempo',
        'TipoCambio', 'Tarea', 'Hito', 'TarifaVenta', 'Proyecto', 'Cliente', 'CostoAsignadoUsuario',
        'FichaSalarial', 'Usuario', 'Configuracion',
    ] as $table) {
        $pdo->exec("DELETE FROM $table");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // ---------------------------------------------------------
    // Usuarios
    // ---------------------------------------------------------
    $password = password_hash('demo1234', PASSWORD_DEFAULT);
    $users = [
        [1, 'Valentina Ríos', 'cfo@codeledger.com', 'CFO', 'Senior', 1],
        [2, 'Carlos Méndez', 'pm@codeledger.com', 'PM', 'Senior', 1],
        [3, 'María Fernanda López', 'dev@codeledger.com', 'DEV', 'Senior', 1],
        [4, 'Luis Ramírez', 'junior@codeledger.com', 'DEV', 'Junior', 1],
        [5, 'Sofía Herrera', 'architect@codeledger.com', 'DEV', 'Architect', 1],
        [6, 'Diego Torres', 'pm2@codeledger.com', 'PM', 'Senior', 1],
    ];
    $u = $pdo->prepare('INSERT INTO Usuario (id_usuario, nombre_completo, correo_electronico, contrasena_hash, rol, nivel, estado_operativo) VALUES (?,?,?,?,?,?,?)');
    foreach ($users as $row) {
        $u->execute([$row[0], $row[1], $row[2], $password, $row[3], $row[4], $row[5]]);
    }

    // ---------------------------------------------------------
    // Fichas salariales y costos asignados
    // ---------------------------------------------------------
    $fichas = [
        // id, modalidad, base, prestaciones, impuestos, horas
        [1, 'Interno', 7000, 1400, 700, 160],
        [2, 'Interno', 5200, 1040, 520, 160],
        [3, 'Interno', 4500, 900, 450, 160],
        [4, 'Interno', 2400, 480, 240, 160],
        [5, 'Interno', 7000, 1400, 700, 160],
        [6, 'Interno', 4800, 960, 480, 160],
    ];
    $f = $pdo->prepare('INSERT INTO FichaSalarial (id_usuario, modalidad_contratacion, salario_base, prestaciones_sociales, impuestos_patronales, horas_laborales_mes, moneda, vigente_desde) VALUES (?,?,?,?,?,?,"USD",?)');
    foreach ($fichas as $row) {
        $f->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], d('-12 months')]);
    }

    $costos = [
        [1, 'Laptop corporativa (amortización)', 'Hardware', 83.33, 36],
        [2, 'Notion + herramientas PM', 'Licencia', 15.00, null],
        [2, 'Laptop corporativa (amortización)', 'Hardware', 55.56, 36],
        [3, 'GitHub Copilot', 'Licencia', 19.00, null],
        [3, 'JetBrains All Products', 'Licencia', 25.00, null],
        [3, 'Figma Professional', 'Licencia', 15.00, null],
        [3, 'MacBook Pro (amortización)', 'Hardware', 66.67, 36],
        [4, 'GitHub Copilot', 'Licencia', 19.00, null],
        [4, 'Laptop corporativa (amortización)', 'Hardware', 50.00, 36],
        [4, 'Formación técnica', 'Beneficio', 10.00, null],
        [5, 'GitHub Copilot', 'Licencia', 19.00, null],
        [5, 'JetBrains All Products', 'Licencia', 25.00, null],
        [5, 'Figma Professional', 'Licencia', 15.00, null],
        [5, 'MacBook Pro (amortización)', 'Hardware', 100.00, 36],
        [6, 'Laptop corporativa (amortización)', 'Hardware', 50.00, 36],
    ];
    $cs = $pdo->prepare('INSERT INTO CostoAsignadoUsuario (id_usuario, concepto, categoria, monto_mensual, vida_util_meses, activo) VALUES (?,?,?,?,?,1)');
    foreach ($costos as $row) {
        $cs->execute($row);
    }

    // ---------------------------------------------------------
    // Matriz de tarifas de venta
    // ---------------------------------------------------------
    $tarifas = [
        ['Junior', 'Interno', 55], ['Junior', 'Contractor', 65], ['Junior', 'Agencia', 75],
        ['Senior', 'Interno', 85], ['Senior', 'Contractor', 95], ['Senior', 'Agencia', 110],
        ['Architect', 'Interno', 120], ['Architect', 'Contractor', 135], ['Architect', 'Agencia', 150],
    ];
    $t = $pdo->prepare('INSERT INTO TarifaVenta (rol, modalidad_contratacion, monto_hora, moneda, vigente_desde) VALUES (?,?,?,"USD",?)');
    foreach ($tarifas as $row) {
        $t->execute([$row[0], $row[1], $row[2], d('-12 months')]);
    }

    // ---------------------------------------------------------
    // Clientes y proyectos
    // ---------------------------------------------------------
    $clientes = [
        [1, 'Acme Retail', 'Retail', 'USD', 'Laura Gómez'],
        [2, 'Banco Nova', 'Banca', 'USD', 'Andrés Paredes'],
        [3, 'HealthTech LatAm', 'Salud', 'USD', 'Dra. Camila Ruiz'],
        [4, 'CodeLedger Labs', 'SaaS interno', 'USD', 'Valentina Ríos'],
        [5, 'Andes Data', 'Energía', 'USD', 'Mateo Silva'],
    ];
    $c = $pdo->prepare('INSERT INTO Cliente (id_cliente, nombre, industria, moneda, contacto) VALUES (?,?,?,?,?)');
    foreach ($clientes as $row) {
        $c->execute($row);
    }

    $proyectos = [
        // id, cliente, manager, código, nombre, modelo, bac, estado, moneda, tecnología, metodología, inicio, fin, tarifa fija, horas
        [1, 1, 2, 'PRJ-001', 'Portal E-commerce Acme', 'Time & Materials', 180000, 'En Desarrollo', 'USD', 'React / Next.js', 'Scrum', d('-5 months'), d('+3 months'), 0, 1800],
        [2, 2, 2, 'PRJ-002', 'Core Bancario Nova', 'Fixed Price', 320000, 'En Desarrollo', 'USD', 'Java / Spring Boot', 'Scrum', d('-8 months'), d('+7 months'), 320000, 2600],
        [3, 3, 6, 'PRJ-003', 'App Salud Móvil', 'Retainer', 96000, 'En Produccion', 'USD', 'Flutter / Firebase', 'Kanban', d('-4 months'), d('+8 months'), 0, 1400],
        [4, 4, 2, 'PRJ-004', 'CodeLedger SaaS (interno)', 'Fixed Price', 120000, 'En Desarrollo', 'USD', 'Python / React', 'Shape Up', d('-6 months'), d('+4 months'), 0, 1100],
        [5, 5, 6, 'PRJ-005', 'Data Platform Andes', 'Time & Materials', 140000, 'En Desarrollo', 'USD', 'Python / Airflow', 'Scrum', d('-3 months'), d('+5 months'), 0, 1200],
    ];
    $p = $pdo->prepare('INSERT INTO Proyecto (id_proyecto, id_cliente, id_manager, codigo_unico, nombre_proyecto, modelo_contratacion, bac_presupuesto_inicial, estado, moneda_contrato, tecnologia, metodologia, fecha_inicio, fecha_fin_estimada, tarifa_fija_total, horas_estimadas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($proyectos as $row) {
        $p->execute($row);
    }

    // ---------------------------------------------------------
    // Hitos
    // ---------------------------------------------------------
    $hitos = [
        // key, id, proyecto, nombre, fase, opex, capex, valor facturable, estado, inicio, entrega
        ['p1h1', 1, 1, 'Descubrimiento y arquitectura', 'Investigacion', 18000, 0, 0, 'Entregado', d('-5 months'), d('-4 months')],
        ['p1h2', 2, 1, 'Checkout y pagos', 'Desarrollo', 40000, 42000, 90000, 'En Curso', d('-4 months'), d('+1 month')],
        ['p1h3', 3, 1, 'Backoffice y reportes', 'Desarrollo', 30000, 50000, 90000, 'Pendiente', d('+1 month'), d('+3 months')],
        ['p2h1', 4, 2, 'Core de cuentas', 'Desarrollo', 50000, 50000, 100000, 'Entregado', d('-8 months'), d('-5 months')],
        ['p2h2', 5, 2, 'Motor de pagos', 'Desarrollo', 60000, 50000, 120000, 'En Curso', d('-5 months'), d('+2 months')],
        ['p2h3', 6, 2, 'Cumplimiento y auditoría', 'Desarrollo', 60000, 50000, 100000, 'Pendiente', d('+2 months'), d('+7 months')],
        ['p3h1', 7, 3, 'Lanzamiento MVP', 'Desarrollo', 20000, 25000, 48000, 'Entregado', d('-4 months'), d('-1 month')],
        ['p3h2', 8, 3, 'Soporte y evolución', 'Desarrollo', 25000, 26000, 48000, 'En Curso', d('-1 month'), d('+8 months')],
        ['p4h1', 9, 4, 'Investigación de mercado', 'Investigacion', 30000, 0, 0, 'Entregado', d('-6 months'), d('-4 months')],
        ['p4h2', 10, 4, 'Plataforma MVP', 'Desarrollo', 30000, 60000, 0, 'En Curso', d('-4 months'), d('+4 months')],
        ['p5h1', 11, 5, 'Ingesta y gobierno de datos', 'Desarrollo', 35000, 40000, 70000, 'En Curso', d('-3 months'), d('+1 month')],
        ['p5h2', 12, 5, 'Analítica avanzada', 'Desarrollo', 30000, 35000, 70000, 'Pendiente', d('+1 month'), d('+5 months')],
    ];
    $h = $pdo->prepare('INSERT INTO Hito (id_hito, id_proyecto, nombre_hito, fase_contable, presupuesto_opex, presupuesto_capex, valor_facturable, estado, fecha_inicio, fecha_entrega) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ($hitos as $row) {
        $h->execute([$row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $row[8], $row[9], $row[10]]);
    }
    $hitoKeyToId = [];
    foreach ($hitos as $row) { $hitoKeyToId[$row[0]] = $row[1]; }

    // ---------------------------------------------------------
    // Tareas / historias de usuario
    // ---------------------------------------------------------
    $taskDefs = [
        'p1h1' => [
            ['GH-101', 'Entrevistas con stakeholders', 'US-01 Descubrimiento', 16, 'Closed', 'I+D'],
            ['GH-102', 'Mapa de arquitectura objetivo', 'US-01 Descubrimiento', 24, 'Closed', 'I+D'],
            ['GH-103', 'Definición de alcance y riesgos', 'US-02 Alcance', 12, 'Closed', 'Operativa'],
        ],
        'p1h2' => [
            ['GH-110', 'Carrito de compras', 'US-10 Carrito', 40, 'Closed', 'Billable'],
            ['GH-111', 'Checkout con tarjeta', 'US-11 Checkout', 48, 'Closed', 'Billable'],
            ['GH-112', 'Integración con Stripe', 'US-11 Checkout', 32, 'Closed', 'Billable'],
            ['GH-113', 'Refactor del módulo de pagos', 'US-12 Deuda técnica', 24, 'Closed', 'Operativa'],
            ['GH-114', 'Optimización de imágenes', 'US-13 Performance', 20, 'Open', 'Billable'],
            ['GH-115', 'Daily, planning y refinamiento', 'US-14 Gestión', 30, 'Closed', 'Operativa'],
        ],
        'p1h3' => [
            ['GH-120', 'Panel de administración', 'US-20 Admin', 60, 'Open', 'Billable'],
            ['GH-121', 'Reportes de ventas', 'US-21 Reportes', 50, 'Open', 'Billable'],
            ['GH-122', 'Hardening de seguridad', 'US-22 Seguridad', 30, 'Open', 'Operativa'],
        ],
        'p2h1' => [
            ['GH-201', 'Modelo de cuentas contables', 'US-30 Core bancario', 80, 'Closed', 'Billable'],
            ['GH-202', 'API de consulta de saldos', 'US-31 Core', 60, 'Closed', 'Billable'],
            ['GH-203', 'Pruebas de carga del core', 'US-32 Calidad', 24, 'Closed', 'Operativa'],
        ],
        'p2h2' => [
            ['GH-210', 'Motor de transferencias', 'US-40 Pagos', 90, 'Closed', 'Billable'],
            ['GH-211', 'Conciliación SWIFT', 'US-41 Pagos', 70, 'Open', 'Billable'],
            ['GH-212', 'Auditoría de transacciones', 'US-42 Compliance', 40, 'Closed', 'Operativa'],
            ['GH-213', 'Migración de datos históricos', 'US-43 Datos', 50, 'Open', 'Billable'],
        ],
        'p2h3' => [
            ['GH-220', 'Reporting regulatorio', 'US-50 Compliance', 60, 'Open', 'Billable'],
            ['GH-221', 'Pentesting externo', 'US-51 Seguridad', 40, 'Open', 'Operativa'],
        ],
        'p3h1' => [
            ['GH-301', 'Onboarding de pacientes', 'US-60 MVP', 50, 'Closed', 'Billable'],
            ['GH-302', 'Agenda de citas', 'US-61 MVP', 45, 'Closed', 'Billable'],
            ['GH-303', 'Notificaciones push', 'US-62 MVP', 30, 'Closed', 'Billable'],
        ],
        'p3h2' => [
            ['GH-310', 'Módulo de telemedicina', 'US-70 Evolución', 60, 'Open', 'Billable'],
            ['GH-311', 'Integración con wearables', 'US-71 Evolución', 40, 'Open', 'I+D'],
            ['GH-312', 'Soporte correctivo y bugs', 'US-72 Soporte', 30, 'Open', 'Operativa'],
        ],
        'p4h1' => [
            ['GH-401', 'Research de competencia', 'US-80 Descubrimiento', 30, 'Closed', 'I+D'],
            ['GH-402', 'Entrevistas con CFOs', 'US-81 Descubrimiento', 24, 'Closed', 'I+D'],
        ],
        'p4h2' => [
            ['GH-410', 'Motor de cálculo EVM', 'US-90 Plataforma', 70, 'Closed', 'Billable'],
            ['GH-411', 'Dashboard financiero', 'US-91 Plataforma', 60, 'Open', 'Billable'],
            ['GH-412', 'Modelo contable IAS 38', 'US-92 Plataforma', 45, 'Closed', 'Billable'],
            ['GH-413', 'Refactor API de tarifas', 'US-93 Deuda técnica', 20, 'Open', 'Operativa'],
            ['GH-414', 'Investigación de modelos LLM', 'US-94 I+D', 30, 'Open', 'I+D'],
        ],
        'p5h1' => [
            ['GH-501', 'Pipeline de ingesta', 'US-100 Data', 70, 'Closed', 'Billable'],
            ['GH-502', 'Gobierno y catálogo de datos', 'US-101 Data', 40, 'Open', 'Billable'],
            ['GH-503', 'Monitoreo de Airflow', 'US-102 Operación', 24, 'Closed', 'Operativa'],
        ],
        'p5h2' => [
            ['GH-510', 'Modelo predictivo de demanda', 'US-110 Analítica', 55, 'Open', 'Billable'],
            ['GH-511', 'Calidad de datos', 'US-111 Analítica', 35, 'Open', 'Operativa'],
        ],
    ];

    $tasks = [];
    $tasksByHitoCategory = [];
    $taskStmt = $pdo->prepare('INSERT INTO Tarea (id_hito, issue_id_github, titulo, historia_usuario, peso_horas_estimadas, estado_tarea, categoria_contable, fecha_creacion) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($taskDefs as $hitoKey => $defs) {
        $hitoId = $hitoKeyToId[$hitoKey];
        foreach ($defs as $def) {
            $taskStmt->execute([$hitoId, $def[0], $def[1], $def[2], $def[3], $def[4], $def[5], d('-4 months')]);
            $id = (int)$pdo->lastInsertId();
            $task = ['id' => $id, 'hito' => $hitoId, 'key' => $hitoKey, 'issue' => $def[0], 'titulo' => $def[1], 'categoria' => $def[5]];
            $tasks[] = $task;
            $tasksByHitoCategory[$hitoId][$def[5]][] = $task;
        }
    }

    // ---------------------------------------------------------
    // Cálculo de Fully Loaded Rate para snapshots
    // ---------------------------------------------------------
    $rates = [];
    foreach ($users as $userRow) {
        $id = (int)$userRow[0];
        $stmt = $pdo->prepare('SELECT * FROM FichaSalarial WHERE id_usuario = ?');
        $stmt->execute([$id]);
        $ficha = $stmt->fetch();
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(monto_mensual),0) FROM CostoAsignadoUsuario WHERE id_usuario = ? AND activo = 1');
        $stmt->execute([$id]);
        $asignados = (float)$stmt->fetchColumn();
        $monthly = (float)$ficha['salario_base'] + (float)$ficha['prestaciones_sociales'] + (float)$ficha['impuestos_patronales'] + $asignados;
        $hourly = $monthly / max(1, (float)$ficha['horas_laborales_mes']);
        $stmt = $pdo->prepare('SELECT monto_hora FROM TarifaVenta WHERE rol = ? AND modalidad_contratacion = ? ORDER BY vigente_desde DESC LIMIT 1');
        $stmt->execute([$userRow[4], $ficha['modalidad_contratacion']]);
        $billable = (float)$stmt->fetchColumn();
        $rates[$id] = [
            'hourly' => round($hourly, 4),
            'billable' => $billable,
            'nivel' => $userRow[4],
            'modalidad' => $ficha['modalidad_contratacion'],
        ];
    }

    // ---------------------------------------------------------
    // Timesheets (últimos 75 días, días hábiles)
    // ---------------------------------------------------------
    $plans = [
        3 => [
            ['hito' => $hitoKeyToId['p1h2'], 'share' => 0.65, 'cat' => 'Billable'],
            ['hito' => $hitoKeyToId['p4h2'], 'share' => 0.25, 'cat' => 'Billable'],
            ['hito' => $hitoKeyToId['p1h2'], 'share' => 0.10, 'cat' => 'Operativa'],
        ],
        4 => [
            ['hito' => $hitoKeyToId['p1h2'], 'share' => 0.45, 'cat' => 'Billable'],
            ['hito' => $hitoKeyToId['p3h2'], 'share' => 0.30, 'cat' => 'Billable'],
            ['hito' => $hitoKeyToId['p5h1'], 'share' => 0.25, 'cat' => 'Billable'],
        ],
        5 => [
            ['hito' => $hitoKeyToId['p2h2'], 'share' => 0.55, 'cat' => 'Billable'],
            ['hito' => $hitoKeyToId['p4h2'], 'share' => 0.25, 'cat' => 'Billable'],
            ['hito' => $hitoKeyToId['p4h1'], 'share' => 0.20, 'cat' => 'I+D'],
        ],
    ];

    $regStmt = $pdo->prepare('INSERT INTO RegistroTiempo (id_tarea, id_usuario, horas_incurridas, estado_aprobacion, fully_loaded_rate_hist, billable_rate_hist, fecha_registro, descripcion) VALUES (?,?,?,?,?,?,?,?)');
    mt_srand(20261112);
    $start = new DateTimeImmutable(d('-75 days'));
    $end = new DateTimeImmutable(d());
    $entries = 0;
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        if ((int)$day->format('N') >= 6) { continue; }
        $dayStr = $day->format('Y-m-d');
        foreach ($plans as $userId => $dayPlan) {
            $baseHours = 7 + (mt_rand(-2, 2) * 0.5);
            foreach ($dayPlan as $plan) {
                $pool = $tasksByHitoCategory[$plan['hito']][$plan['cat']] ?? $tasksByHitoCategory[$plan['hito']]['Billable'] ?? [];
                if (!$pool) { continue; }
                $task = $pool[array_rand($pool)];
                $hours = round($baseHours * $plan['share'] * 2) / 2;
                if ($hours < 0.5) { continue; }
                $status = 'Aprobado';
                if ($dayStr > d('-20 days') && mt_rand(0, 100) < 38) {
                    $status = 'Pendiente';
                }
                $billable = $plan['cat'] === 'Billable' ? $rates[$userId]['billable'] : 0;
                $regStmt->execute([
                    $task['id'], $userId, $hours, $status,
                    $rates[$userId]['hourly'], $billable, $dayStr,
                    $task['titulo'],
                ]);
                $entries++;
            }
        }
    }

    // ---------------------------------------------------------
    // Tipo de cambio
    // ---------------------------------------------------------
    $fx = $pdo->prepare('INSERT INTO TipoCambio (moneda_origen, moneda_destino, tasa, fecha) VALUES (?,?,?,?)');
    $fx->execute(['EUR', 'USD', 1.08, d()]);
    $fx->execute(['VES', 'USD', 0.0027, d()]);
    $fx->execute(['COP', 'USD', 0.00025, d()]);

    // ---------------------------------------------------------
    // Facturación
    // ---------------------------------------------------------
    $invStmt = $pdo->prepare('INSERT INTO ComprobanteFacturacion (id_proyecto, id_cliente, numero_correlativo, tipo_comprobante, monto_total, estado, moneda, tipo_cambio, monto_total_usd, metodo_cobro, fecha_emision, fecha_vencimiento, fecha_cobro, observaciones, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $inv = [];
    $inv['i1'] = 1; // placeholder
    $invStmt->execute([1, 1, 'INV-2026-0001', 'Factura', 42000, 'Emitida', 'USD', 1, 42000, 'Transferencia', d('-30 days'), d('+1 day'), null, 'Sprint 8-11 Portal Acme (T&M)', dt('-30 days')]);
    $inv['i1'] = (int)$pdo->lastInsertId();
    $invStmt->execute([2, 2, 'INV-2026-0002', 'Factura', 100000, 'Cobrada', 'USD', 1, 100000, 'Transferencia', d('-65 days'), d('-35 days'), d('-33 days'), 'Hito 1: Core de cuentas', dt('-65 days')]);
    $inv['i2'] = (int)$pdo->lastInsertId();
    $invStmt->execute([3, 3, 'INV-2026-0003', 'Factura', 4500, 'Emitida', 'EUR', 1.08, 4860, 'Stripe', d('-10 days'), d('+5 days'), null, 'Retainer mensual App Salud', dt('-10 days')]);
    $inv['i3'] = (int)$pdo->lastInsertId();
    $invStmt->execute([5, 5, 'INV-2026-0004', 'Factura', 18500, 'Emitida', 'USD', 1, 18500, 'Transferencia', d('-12 days'), d('+18 days'), null, 'Data Platform - sprint 5 a 7', dt('-12 days')]);
    $inv['i4'] = (int)$pdo->lastInsertId();
    $invStmt->execute([1, 1, 'PRE-2026-0001', 'Pre-factura', 12000, 'Pre-factura', 'USD', 1, 12000, 'Transferencia', null, null, null, 'Pre-factura borrador checkout', dt('-2 days')]);
    $inv['i5'] = (int)$pdo->lastInsertId();

    $det = $pdo->prepare('INSERT INTO FacturaDetalle (id_comprobante, concepto, cantidad, precio_unitario, monto, id_registro) VALUES (?,?,?,?,?,NULL)');
    $det->execute([$inv['i1'], 'Horas Senior - desarrollo checkout', 360, 85, 30600]);
    $det->execute([$inv['i1'], 'Horas Junior - soporte desarrollo', 120, 55, 6600]);
    $det->execute([$inv['i1'], 'Horas Architect - arquitectura de pagos', 40, 120, 4800]);
    $det->execute([$inv['i2'], 'Hito 1: Core de cuentas (Fixed Price)', 1, 100000, 100000]);
    $det->execute([$inv['i3'], 'Retainer App Salud - mes en curso', 1, 4500, 4500]);
    $det->execute([$inv['i4'], 'Data Platform - bolsa de horas', 1, 18500, 18500]);
    $det->execute([$inv['i5'], 'Pre-factura borrador Portal Acme', 1, 12000, 12000]);

    // Reconocimiento diferido de ingresos (ASC 606 / NIIF 15)
    $recStmt = $pdo->prepare('INSERT INTO ReconocimientoIngreso (id_comprobante, monto_total, monto_reconocido, fecha_inicio, fecha_fin, metodo, estado) VALUES (?,?,?,?,?,?,?)');
    $recStmt->execute([$inv['i1'], 42000, 21000, d('-30 days'), d('+30 days'), 'Lineal', 'Reconociendo']);
    $recStmt->execute([$inv['i3'], 4860, 1620, d('-10 days'), d('+20 days'), 'Lineal', 'Reconociendo']);
    $recStmt->execute([$inv['i4'], 18500, 0, d('-12 days'), d('+30 days'), 'Lineal', 'Diferido']);

    // ---------------------------------------------------------
    // FinOps: consumos cloud / APIs
    // ---------------------------------------------------------
    $cloudDefs = [
        [1, 2, 'AWS', 'Cloud', 'Amazon EC2 (Compute)', 145.50, 400],
        [1, 2, 'AWS', 'Cloud', 'Amazon RDS (Database)', 89.25, 400],
        [1, 2, 'Vercel', 'Cloud', 'Vercel Pro (Frontend)', 45.00, 50],
        [1, 2, 'OpenAI', 'API', 'OpenAI API - recomendador', 110.00, 120],
        [2, 5, 'AWS', 'Cloud', 'Amazon EKS', 420.00, 350],
        [2, 5, 'Azure', 'Cloud', 'Azure App Service', 210.00, 200],
        [2, 5, 'Azure', 'Cloud', 'Azure Blob Storage', 15.75, 40],
        [2, 5, 'Datadog', 'Cloud', 'Datadog APM', 95.00, 120],
        [3, 8, 'GCP', 'Cloud', 'Cloud Run', 280.00, 300],
        [3, 8, 'GCP', 'Cloud', 'Firebase Hosting', 90.00, 120],
        [3, 8, 'Twilio', 'API', 'Twilio SMS / Video', 65.00, 50],
        [3, 8, 'SendGrid', 'API', 'SendGrid Email API', 35.00, 50],
        [4, 10, 'OpenAI', 'API', 'OpenAI API - asistentes', 180.00, 150],
        [4, 10, 'Vercel', 'Cloud', 'Vercel Pro', 20.00, 40],
        [4, 10, 'GitHub', 'Cloud', 'GitHub Actions', 25.00, 50],
        [5, 11, 'AWS', 'Cloud', 'Amazon S3 / Glue', 65.00, 100],
        [5, 11, 'Snowflake', 'Cloud', 'Snowflake Warehouse', 300.00, 250],
        [5, 11, 'Stripe', 'API', 'Stripe Fees', 85.00, 100],
    ];
    $finStmt = $pdo->prepare('INSERT INTO ConsumoFinOps (id_proyecto, id_hito, proveedor, categoria, servicio, monto_cargado, moneda, fecha_consumo, referencia_externa, etiqueta_proyecto) VALUES (?,?,?,?,?,?,"USD",?,?,?)');
    $monthStart = new DateTimeImmutable('first day of this month');
    for ($m = -3; $m <= 0; $m++) {
        $period = $monthStart->modify("$m months");
        foreach ($cloudDefs as $index => $def) {
            $variation = 1 + (($m + 3) * 0.04) + ((($index % 4) - 1.5) * 0.01);
            $amount = round($def[5] * $variation, 2);
            $date = $period->modify('+' . (2 + ($index % 20)) . ' days');
            if ($date > new DateTimeImmutable(d())) { $date = new DateTimeImmutable(d('-1 day')); }
            $ref = 'seed-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $def[4])) . '-' . $period->format('Y-m');
            $finStmt->execute([$def[0], $def[1], $def[2], $def[3], $def[4], $amount, $date->format('Y-m-d'), $ref, 'PRJ-00' . $def[0]]);
        }
    }

    // Presupuestos FinOps
    $budgetDefs = [
        [1, 2, 'AWS', null, 400, 80],
        [1, 2, 'Vercel', null, 50, 80],
        [1, 2, 'OpenAI', null, 120, 85],
        [2, 5, 'AWS', null, 350, 80],
        [2, 5, 'Azure', null, 200, 80],
        [3, 8, 'GCP', null, 300, 85],
        [3, 8, 'Twilio', null, 50, 80],
        [4, 10, 'OpenAI', null, 150, 80],
        [5, 11, 'Snowflake', null, 250, 80],
    ];
    $bStmt = $pdo->prepare('INSERT INTO PresupuestoFinOps (id_proyecto, id_hito, proveedor, servicio, presupuesto_mensual, umbral_alerta) VALUES (?,?,?,?,?,?)');
    foreach ($budgetDefs as $b) { $bStmt->execute($b); }

    // Alertas de desviación
    $alertStmt = $pdo->prepare('INSERT INTO AlertaFinOps (id_presupuesto, id_proyecto, id_hito, tipo, mensaje, nivel, monto_actual, monto_limite, leida, fecha) VALUES (?,?,?,?,?,?,?,?,0,?)');
    $budgets = $pdo->query('SELECT * FROM PresupuestoFinOps WHERE activo = 1')->fetchAll();
    foreach ($budgets as $budget) {
        $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(monto_cargado),0) FROM ConsumoFinOps WHERE id_proyecto = ? AND proveedor = ? AND DATE_FORMAT(fecha_consumo, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')");
        $sumStmt->execute([$budget['id_proyecto'], $budget['proveedor']]);
        $actual = (float)$sumStmt->fetchColumn();
        $limit = (float)$budget['presupuesto_mensual'];
        if ($limit > 0 && $actual >= $limit * ((float)$budget['umbral_alerta'] / 100)) {
            $pct = round(($actual / $limit) * 100);
            $level = $actual > $limit ? 'Crítica' : 'Advertencia';
            $alertStmt->execute([
                $budget['id_presupuesto'], $budget['id_proyecto'], $budget['id_hito'],
                'Desviación OpEx',
                $budget['proveedor'] . ': consumo de $' . number_format($actual, 2) . ' vs presupuesto $' . number_format($limit, 2) . " ($pct%).",
                $level, $actual, $limit, dt('-2 hours'),
            ]);
        }
    }

    // ---------------------------------------------------------
    // Contabilidad / IAS 38
    // ---------------------------------------------------------
    $ruleStmt = $pdo->prepare('INSERT INTO ReglaContable (id_proyecto, fase, tratamiento, vida_util_meses) VALUES (?,?,?,?)');
    $ruleStmt->execute([1, 'Investigacion', 'OpEx', 36]);
    $ruleStmt->execute([1, 'Desarrollo', 'OpEx', 36]);
    $ruleStmt->execute([2, 'Investigacion', 'OpEx', 48]);
    $ruleStmt->execute([2, 'Desarrollo', 'OpEx', 48]);
    $ruleStmt->execute([3, 'Desarrollo', 'OpEx', 36]);
    $ruleStmt->execute([4, 'Investigacion', 'OpEx', 36]);
    $ruleStmt->execute([4, 'Desarrollo', 'CapEx', 36]);
    $ruleStmt->execute([5, 'Desarrollo', 'OpEx', 36]);

    // Costo capitalizable del proyecto interno PRJ-004 (fase Desarrollo)
    $capexStmt = $pdo->prepare("SELECT COALESCE(SUM(r.horas_incurridas * r.fully_loaded_rate_hist),0) FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito WHERE h.id_proyecto = 4 AND h.fase_contable = 'Desarrollo' AND r.estado_aprobacion IN ('Aprobado','Facturado')");
    $capexStmt->execute();
    $laborCapex = (float)$capexStmt->fetchColumn();
    $cloudCapexStmt = $pdo->prepare("SELECT COALESCE(SUM(c.monto_cargado),0) FROM ConsumoFinOps c JOIN Hito h ON h.id_hito = c.id_hito WHERE c.id_proyecto = 4 AND h.fase_contable = 'Desarrollo'");
    $cloudCapexStmt->execute();
    $capex = round($laborCapex + (float)$cloudCapexStmt->fetchColumn(), 2);
    $capDate = (new DateTimeImmutable('first day of this month'))->modify('-3 months');
    $monthlyAmort = round($capex / 36, 4);
    $monthsElapsed = 3;
    $accumulated = min($capex, round($monthlyAmort * $monthsElapsed, 4));
    $assetStmt = $pdo->prepare('INSERT INTO ActivoIntangible (id_proyecto, nombre, fecha_capitalizacion, monto_capitalizado, vida_util_meses, amortizacion_mensual, amortizacion_acumulada, estado) VALUES (?,?,?,?,36,?,?,?)');
    $assetStmt->execute([4, 'Plataforma CodeLedger SaaS', $capDate->format('Y-m-d'), $capex, $monthlyAmort, $accumulated, 'Activo']);

    // ---------------------------------------------------------
    // Retainer
    // ---------------------------------------------------------
    $retStmt = $pdo->prepare('INSERT INTO ContratoRetainer (id_proyecto, horas_incluidas_mes, precio_mensual, rollover_maximo, fecha_inicio) VALUES (?,?,?,?,?)');
    $retStmt->execute([3, 60, 4800, 12, d('-4 months')]);
    $retainerId = (int)$pdo->lastInsertId();
    $consumoStmt = $pdo->prepare("SELECT DATE_FORMAT(r.fecha_registro, '%Y-%m') periodo, SUM(r.horas_incurridas) horas FROM RegistroTiempo r JOIN Tarea t ON t.id_tarea = r.id_tarea JOIN Hito h ON h.id_hito = t.id_hito WHERE h.id_proyecto = 3 AND r.estado_aprobacion IN ('Aprobado','Facturado') GROUP BY periodo ORDER BY periodo");
    $consumoStmt->execute();
    $consumos = $consumoStmt->fetchAll();
    $saldo = 0;
    $retConsumoStmt = $pdo->prepare('INSERT INTO ConsumoRetainer (id_retainer, periodo, horas_consumidas, saldo_inicial, saldo_final, facturado) VALUES (?,?,?,?,?,?)');
    foreach ($consumos as $consumo) {
        $saldoInicial = $saldo;
        $disponible = 60 + $saldoInicial;
        $saldoFinal = min(12, max(0, $disponible - (float)$consumo['horas']));
        $retConsumoStmt->execute([$retainerId, $consumo['periodo'], $consumo['horas'], $saldoInicial, $saldoFinal, $consumo['periodo'] < date('Y-m') ? 1 : 0]);
        $saldo = $saldoFinal;
    }

    // ---------------------------------------------------------
    // Integraciones / logs / configuración
    // ---------------------------------------------------------
    $integraciones = [
        [1, 'GitHub Issues', 'Tareas', 'GitHub', 'Conectado', 'Repositorio djalbarrancenteno/codeledger', dt('-1 hour')],
        [2, 'Jira Cloud', 'Tareas', 'Atlassian', 'Pendiente', 'Requiere API token y dominio', null],
        [3, 'ClickUp', 'Tareas', 'ClickUp', 'Pendiente', 'Requiere API token', null],
        [4, 'AWS Cost Explorer', 'Cloud', 'Amazon Web Services', 'Conectado', 'Cost allocation tags activos', dt('-30 minutes')],
        [5, 'Azure Cost Management', 'Cloud', 'Microsoft Azure', 'Conectado', 'Suscripción producción', dt('-30 minutes')],
        [6, 'Google Cloud Billing', 'Cloud', 'Google Cloud', 'Pendiente', 'Requiere service account', null],
        [7, 'Vercel', 'Cloud', 'Vercel', 'Conectado', 'Team CodeLedger', dt('-2 hours')],
        [8, 'SAP Business One', 'ERP', 'SAP', 'Pendiente', 'Requiere endpoint OData', null],
        [9, 'QuickBooks Online', 'ERP', 'Intuit', 'Conectado', 'Sync de facturas emitidas', dt('-4 hours')],
        [10, 'Odoo', 'ERP', 'Odoo', 'Pendiente', 'Evaluación', null],
        [11, 'Stripe', 'Pagos', 'Stripe', 'Conectado', 'Webhook de cobros', dt('-15 minutes')],
    ];
    $intStmt = $pdo->prepare('INSERT INTO Integracion (id_integracion, nombre, tipo, proveedor, estado, configuracion, ultima_sincronizacion) VALUES (?,?,?,?,?,?,?)');
    foreach ($integraciones as $row) { $intStmt->execute($row); }

    $logStmt = $pdo->prepare('INSERT INTO SyncLog (id_integracion, tipo, referencia, mensaje, registros, estado, fecha) VALUES (?,?,?,?,?,?,?)');
    $logStmt->execute([1, 'GitHub Issues', 'djalbarrancenteno/codeledger', 'Sincronización de issues completada', 12, 'OK', dt('-1 hour')]);
    $logStmt->execute([4, 'AWS Cost Explorer', 'PRJ-001', 'Costos de infraestructura importados', 18, 'OK', dt('-30 minutes')]);
    $logStmt->execute([5, 'Azure Cost Management', 'PRJ-002', 'Costos de infraestructura importados', 8, 'OK', dt('-30 minutes')]);
    $logStmt->execute([11, 'Stripe', 'INV-2026-0003', 'Cobro pendiente de conciliación', 0, 'OK', dt('-15 minutes')]);

    $config = [
        ['empresa', 'CodeLedger', 'Nombre de la organización'],
        ['moneda_base', 'USD', 'Moneda de consolidación'],
        ['saldo_caja', '250000', 'Caja disponible para cálculo de runway'],
        ['meta_margen', '35', 'Margen bruto objetivo (%)'],
        ['dias_laborales_mes', '21', 'Días hábiles promedio'],
        ['alerta_umbral_default', '80', 'Umbral por defecto de alertas FinOps (%)'],
    ];
    $cfgStmt = $pdo->prepare('INSERT INTO Configuracion (clave, valor, descripcion) VALUES (?,?,?)');
    foreach ($config as $row) { $cfgStmt->execute($row); }

    $auditStmt = $pdo->prepare('INSERT INTO EventoAuditoria (id_usuario, accion, entidad, id_entidad, detalle, ip, fecha) VALUES (?,?,?,?,?,?,?)');
    $auditStmt->execute([1, 'seed', 'Sistema', null, 'Datos demo inicializados', '127.0.0.1', dt()]);

    $pdo->commit();

    echo "OK. Datos demo creados:\n";
    echo "  Usuarios: " . count($users) . "\n";
    echo "  Proyectos: " . count($proyectos) . "\n";
    echo "  Hitos: " . count($hitos) . "\n";
    echo "  Tareas: " . count($tasks) . "\n";
    echo "  Registros de tiempo: $entries\n";
    echo "  Consumos FinOps: " . (count($cloudDefs) * 4) . "\n";
    echo "  Activo capitalizado PRJ-004: $" . number_format($capex, 2) . "\n";
    echo "\nAcceso demo:\n";
    echo "  CFO:       cfo@codeledger.com / demo1234\n";
    echo "  PM:        pm@codeledger.com / demo1234\n";
    echo "  DEV:       dev@codeledger.com / demo1234\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
