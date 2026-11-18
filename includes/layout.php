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

/**
 * Icono SVG inline (set Lucide, MIT). Se usa en sidebar, topbar y acciones principales.
 * Los iconos dentro de cada página siguen usando bootstrap-icons (clases "bi bi-*").
 */
function cl_icon(string $name, int $size = 20): string
{
    static $paths = [
        'layers' => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'projects' => '<path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/><path d="M8 10v4"/><path d="M12 10v2"/><path d="M16 10v6"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'rates' => '<circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/>',
        'billing' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        'accounting' => '<line x1="3" x2="21" y1="22" y2="22"/><line x1="6" x2="6" y1="18" y2="11"/><line x1="10" x2="10" y1="18" y2="11"/><line x1="14" x2="14" y1="18" y2="11"/><line x1="18" x2="18" y1="18" y2="11"/><polygon points="12 2 20 7 4 7"/>',
        'finops' => '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>',
        'reports' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'integrations' => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"/>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'panel' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/>',
        'menu' => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'plus' => '<path d="M5 12h14"/><path d="M12 5v14"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
    ];
    $inner = $paths[$name] ?? '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
}

function render_header(string $title, array $user, string $active = 'dashboard'): void
{
    $flashes = pull_flashes();
    $roleLabel = ['CFO' => 'Director Financiero', 'PM' => 'Project Manager', 'DEV' => 'Desarrollador'][$user['rol']] ?? $user['rol'];
    $alerts = can_view_financials($user) ? (int)scalar('SELECT COUNT(*) FROM AlertaFinOps WHERE leida = 0') : 0;
    $pending = (int)scalar("SELECT COUNT(*) FROM RegistroTiempo WHERE estado_aprobacion = 'Pendiente'" . (is_dev($user) ? ' AND id_usuario = ' . (int)$user['id_usuario'] : ''));
    $canFin = can_view_financials($user);

    // [archivo, clave activa, icono, etiqueta, visible]
    $navGroups = [
        'Operación' => [
            ['dashboard.php', 'dashboard', 'dashboard', 'Dashboard', true],
            ['projects.php', 'projects', 'projects', 'Proyectos', true],
            ['timesheets.php', 'timesheets', 'clock', 'Timesheets', true],
        ],
        'Finanzas' => [
            ['rates.php', 'rates', 'rates', 'Tarifas y Equipo', $canFin],
            ['billing.php', 'billing', 'billing', 'Facturación', $canFin],
            ['accounting.php', 'accounting', 'accounting', 'Contabilidad IAS 38', $canFin],
        ],
        'Cloud & Datos' => [
            ['finops.php', 'finops', 'finops', 'FinOps', $canFin],
            ['reports.php', 'reports', 'reports', 'Reportes', $canFin],
            ['integrations.php', 'integrations', 'integrations', 'Integraciones', $canFin],
        ],
    ];

    // Breadcrumbs: Grupo / Sección [/ detalle]
    $crumbGroup = '';
    $crumbLabel = $title;
    $crumbHref = '';
    foreach ($navGroups as $group => $items) {
        foreach ($items as $item) {
            if ($item[1] === $active) {
                $crumbGroup = $group;
                $crumbLabel = $item[3];
                $crumbHref = $item[0];
            }
        }
    }
    $isDetail = $active === 'projects' && $title !== 'Proyectos';

    // Acción principal de la topbar: abre el modal que ya existe en cada página.
    // Se muestra solo si el modal está en el DOM (respeta permisos por rol).
    $primaryActions = [
        'projects' => ['newProjectModal', 'Nuevo Proyecto'],
        'timesheets' => ['newTimeModal', 'Registrar Horas'],
        'billing' => ['manualInvoiceModal', 'Nueva Factura'],
        'finops' => ['newCostModal', 'Registrar consumo'],
        'integrations' => ['integrationModal', 'Nueva integración'],
    ];
    $primary = $primaryActions[$active] ?? null;
    if ($isDetail) {
        $primary = ['newTaskModal', 'Nueva tarea'];
    }
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="color-scheme" content="light dark">
    <title><?= e($title) ?> · CodeLedger</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <script>(function(){var p='system';try{p=localStorage.getItem('cl.theme')||'system';}catch(e){}if(p!=='light'&&p!=='dark')p='system';var d=p==='dark'||(p==='system'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);var r=document.documentElement;r.setAttribute('data-bs-theme',d?'dark':'light');r.setAttribute('data-theme-pref',p);})();</script>
    <script>try{if(localStorage.getItem('cl.sidebar')==='collapsed'&&window.innerWidth>=1200){document.documentElement.classList.add('sidebar-collapsed');}}catch(e){}</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Navegación principal">
        <div class="sidebar-brand">
            <div class="brand-icon"><?= cl_icon('layers', 22) ?></div>
            <div class="brand-text">
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
                        <a href="<?= e($item[0]) ?>" class="nav-link <?= $active === $item[1] ? 'active' : '' ?>" title="<?= e($item[3]) ?>"<?= $active === $item[1] ? ' aria-current="page"' : '' ?>>
                            <span class="nav-ico"><?= cl_icon($item[2]) ?></span>
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
            <button type="button" class="sidebar-collapse-btn" id="sidebarCollapse" title="Contraer o expandir menú">
                <?= cl_icon('panel') ?><span>Contraer menú</span>
            </button>
            <div class="sidebar-version">CodeLedger v2.0 · IFRS / EVM</div>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="app-main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="icon-btn" type="button" id="sidebarToggle" aria-label="Abrir o contraer menú" aria-controls="sidebar"><?= cl_icon('menu', 18) ?></button>
                <div class="topbar-titles">
                    <nav class="breadcrumbs" aria-label="Ruta de navegación">
                        <?php if ($crumbGroup !== ''): ?>
                            <span><?= e($crumbGroup) ?></span><?= cl_icon('chevron-right', 12) ?>
                            <?php if ($isDetail): ?>
                                <a href="<?= e($crumbHref) ?>"><?= e($crumbLabel) ?></a><?= cl_icon('chevron-right', 12) ?>
                                <span class="current"><?= e($title) ?></span>
                            <?php else: ?>
                                <span class="current"><?= e($crumbLabel) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="current"><?= e($title) ?></span>
                        <?php endif; ?>
                    </nav>
                    <h1 class="page-title"><?= e($title) ?></h1>
                </div>
            </div>
            <div class="topbar-actions">
                <?php if ($primary): ?>
                    <button type="button" class="btn btn-primary btn-sm cl-primary-action" data-bs-toggle="modal" data-bs-target="#<?= e($primary[0]) ?>" data-primary-action hidden>
                        <?= cl_icon('plus', 16) ?><span class="lbl"><?= e($primary[1]) ?></span>
                    </button>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="icon-btn theme-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Cambiar tema" title="Tema">
                        <span class="ti ti-light"><?= cl_icon('sun', 18) ?></span>
                        <span class="ti ti-dark"><?= cl_icon('moon', 18) ?></span>
                        <span class="ti ti-system"><?= cl_icon('monitor', 18) ?></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end theme-menu">
                        <h6 class="dropdown-header">Apariencia</h6>
                        <button type="button" class="dropdown-item" data-theme-choice="light"><?= cl_icon('sun', 16) ?> Claro <span class="tick"><?= cl_icon('check', 16) ?></span></button>
                        <button type="button" class="dropdown-item" data-theme-choice="dark"><?= cl_icon('moon', 16) ?> Oscuro <span class="tick"><?= cl_icon('check', 16) ?></span></button>
                        <button type="button" class="dropdown-item" data-theme-choice="system"><?= cl_icon('monitor', 16) ?> Sistema <span class="tick"><?= cl_icon('check', 16) ?></span></button>
                    </div>
                </div>
                <?php if ($canFin): ?>
                    <a href="finops.php#alertas" class="icon-btn topbar-icon" title="Alertas FinOps" aria-label="Alertas FinOps">
                        <?= cl_icon('bell', 18) ?>
                        <?php if ($alerts > 0): ?><span class="notif-dot"><?= $alerts > 9 ? '9+' : $alerts ?></span><?php endif; ?>
                    </a>
                <?php endif; ?>
                <div class="dropdown">
                    <button class="user-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menú de usuario">
                        <div class="avatar"><?= e(initials($user['nombre_completo'])) ?></div>
                        <div class="user-meta">
                            <strong><?= e($user['nombre_completo']) ?></strong>
                            <span><?= e($roleLabel) ?></span>
                        </div>
                        <?= cl_icon('chevron-down', 14) ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end">
                        <div class="dropdown-header-user">
                            <strong><?= e($user['nombre_completo']) ?></strong>
                            <span><?= e($user['correo_electronico']) ?></span>
                        </div>
                        <a class="dropdown-item" href="logout.php"><?= cl_icon('logout', 16) ?> Cerrar sesión</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            <?php if ($flashes): ?>
                <div id="clFlash" hidden data-flashes="<?= e(json_encode($flashes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"></div>
                <noscript>
                    <?php foreach ($flashes as $flash): ?>
                        <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['message']) ?></div>
                    <?php endforeach; ?>
                </noscript>
            <?php endif; ?>
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
