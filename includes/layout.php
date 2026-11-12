<?php
declare(strict_types=1);

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= strtoupper(substr($part, 0, 1));
    }
    return $letters ?: 'CL';
}

function render_header(string $title, array $user, string $active = 'dashboard'): void
{
    $flashes = pull_flashes();
    $roleLabel = ['CFO' => 'Director Financiero', 'PM' => 'Project Manager', 'DEV' => 'Desarrollador'][$user['rol']] ?? $user['rol'];
    $alerts = can_view_financials($user) ? (int)scalar('SELECT COUNT(*) FROM AlertaFinOps WHERE leida = 0') : 0;
    $pending = (int)scalar("SELECT COUNT(*) FROM RegistroTiempo WHERE estado_aprobacion = 'Pendiente'" . (is_dev($user) ? ' AND id_usuario = ' . (int)$user['id_usuario'] : ''));
    $navGroups = [
        'Operación' => [
            ['dashboard.php', 'dashboard', 'bi-speedometer2', 'Dashboard', true],
            ['projects.php', 'projects', 'bi-kanban', 'Proyectos', true],
            ['timesheets.php', 'timesheets', 'bi-clock-history', 'Timesheets', true],
        ],
        'Finanzas' => [
            ['finops.php', 'finops', 'bi-cloud-arrow-down', 'FinOps', can_view_financials($user)],
            ['accounting.php', 'accounting', 'bi-journal-text', 'Contabilidad IAS 38', can_view_financials($user)],
            ['billing.php', 'billing', 'bi-receipt', 'Facturación', can_view_financials($user)],
            ['reports.php', 'reports', 'bi-graph-up-arrow', 'Reportes', can_view_financials($user)],
        ],
        'Administración' => [
            ['rates.php', 'rates', 'bi-cash-stack', 'Tarifas y Equipo', can_view_financials($user)],
            ['integrations.php', 'integrations', 'bi-plug', 'Integraciones', can_view_financials($user)],
        ],
    ];
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> · CodeLedger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon"><i class="bi bi-layers-half"></i></div>
            <div>
                <span class="brand-name">CodeLedger</span>
                <span class="brand-sub">Operative Accounting</span>
            </div>
        </div>
        <div class="sidebar-role">
            <span class="role-chip role-<?= strtolower($user['rol']) ?>"><?= e($user['rol']) ?></span>
            <span><?= e($roleLabel) ?></span>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($navGroups as $group => $items): ?>
                <?php $visible = array_filter($items, fn($i) => $i[4]); ?>
                <?php if ($visible): ?>
                    <div class="nav-section"><?= e($group) ?></div>
                    <?php foreach ($visible as $item): ?>
                        <a href="<?= e($item[0]) ?>" class="nav-link <?= $active === $item[1] ? 'active' : '' ?>">
                            <i class="bi <?= e($item[2]) ?>"></i>
                            <span><?= e($item[3]) ?></span>
                            <?php if ($item[1] === 'timesheets' && $pending > 0): ?>
                                <span class="nav-badge"><?= $pending ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <a href="logout.php" class="nav-link"><i class="bi bi-box-arrow-left"></i><span>Cerrar sesión</span></a>
            <div class="sidebar-version">CodeLedger v2.0 · IFRS / EVM</div>
        </div>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-sm btn-light d-lg-none" type="button" id="sidebarToggle"><i class="bi bi-list"></i></button>
                <div>
                    <h1 class="page-title"><?= e($title) ?></h1>
                    <div class="page-date"><?= e(fecha_larga()) ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <?php if (can_view_financials($user)): ?>
                    <a href="finops.php#alertas" class="topbar-icon" title="Alertas FinOps">
                        <i class="bi bi-bell"></i>
                        <?php if ($alerts > 0): ?><span class="notif-dot"><?= $alerts > 9 ? '9+' : $alerts ?></span><?php endif; ?>
                    </a>
                <?php endif; ?>
                <div class="user-menu">
                    <div class="avatar"><?= e(initials($user['nombre_completo'])) ?></div>
                    <div class="user-meta">
                        <strong><?= e($user['nombre_completo']) ?></strong>
                        <span><?= e($user['correo_electronico']) ?></span>
                    </div>
                    <a href="logout.php" class="btn btn-sm btn-outline-secondary ms-2" title="Cerrar sesión"><i class="bi bi-box-arrow-right"></i></a>
                </div>
            </div>
        </header>

        <main class="content">
            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
    <?php
}

function render_footer(): void
{
    ?>
        </main>
        <footer class="app-footer">
            CodeLedger · Contabilidad operativa y rentabilidad de proyectos TI · <?= date('Y') ?>
        </footer>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="assets/js/main.js"></script>
</body>
</html>
    <?php
}
