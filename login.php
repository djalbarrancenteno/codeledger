<?php
require_once 'includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string)($_POST['correo'] ?? ''));
    $password = (string)($_POST['contrasena'] ?? '');
    if ($email === '' || $password === '') {
        $error = 'Ingresa tu correo y contraseña.';
    } else {
        $user = fetch_one('SELECT * FROM Usuario WHERE correo_electronico = ? AND estado_operativo = 1', [$email]);
        if ($user && password_verify($password, $user['contrasena_hash'])) {
            session_regenerate_id(true);
            $_SESSION['id_usuario'] = (int)$user['id_usuario'];
            audit('login', 'Usuario', (int)$user['id_usuario'], 'Inicio de sesión correcto');
            redirect('dashboard.php');
        }
        $error = 'Credenciales inválidas. Verifica el correo y la contraseña.';
    }
}

$demos = [
    ['cfo@codeledger.com', 'Valentina Ríos', 'CFO', 'cfo', 'Acceso total, nómina y márgenes'],
    ['pm@codeledger.com', 'Carlos Méndez', 'PM', 'pm', 'Presupuesto y desviaciones de sus proyectos'],
    ['dev@codeledger.com', 'María Fernanda López', 'DEV', 'dev', 'Timesheets y tareas, sin métricas financieras'],
    ['architect@codeledger.com', 'Sofía Herrera', 'DEV · Architect', 'dev', 'Perfil técnico con tarifa senior'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Iniciar sesión · CodeLedger</title>
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg">
    <script>(function(){var p='system';try{p=localStorage.getItem('cl.theme')||'system';}catch(e){}if(p!=='light'&&p!=='dark')p='system';var d=p==='dark'||(p==='system'&&window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches);var r=document.documentElement;r.setAttribute('data-bs-theme',d?'dark':'light');r.setAttribute('data-theme-pref',p);})();</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-page">
    <section class="login-hero">
        <div class="login-brand">
            <div class="brand-icon"><i class="bi bi-layers-half"></i></div>
            <span class="login-brand-text">CodeLedger</span>
        </div>

        <div>
            <span class="login-eyebrow"><i class="bi bi-stars"></i> Contabilidad operativa para equipos de software</span>
            <h2>El puente entre tu operación de software y la contabilidad financiera.</h2>
            <p>Calcula el costo real de producir software, el margen de cada proyecto y el valor ganado en tiempo real. Integra GitHub, cloud, ERPs y timesheets en una sola fuente de verdad.</p>

            <figure class="hero-figure">
                <img class="hero-shot" src="assets/img/login-dashboard.svg" width="720" height="440" alt="Vista previa ilustrativa del dashboard de CodeLedger: indicadores de ingresos, costo y margen, gráfico mensual, gasto cloud y rentabilidad por proyecto.">
                <div class="float-card fc-1" aria-hidden="true">
                    <div class="fc-ico"><i class="bi bi-graph-up-arrow"></i></div>
                    <div><span>Margen bruto</span><b>+39,1%</b></div>
                </div>
                <div class="float-card fc-2" aria-hidden="true">
                    <div class="fc-ico"><i class="bi bi-activity"></i></div>
                    <div><span>CPI consolidado</span><b>1,08</b></div>
                </div>
                <div class="float-card fc-3" aria-hidden="true">
                    <div class="fc-ico"><i class="bi bi-cloud-check"></i></div>
                    <div><span>FinOps</span><b>Presupuesto cloud en rango</b></div>
                </div>
            </figure>

            <div class="login-features">
                <div class="login-feature"><i class="bi bi-calculator"></i>Fully Loaded Rate real por desarrollador</div>
                <div class="login-feature"><i class="bi bi-cloud-check"></i>FinOps y alertas de OpEx</div>
                <div class="login-feature"><i class="bi bi-journal-check"></i>IAS 38 / CapEx vs OpEx</div>
                <div class="login-feature"><i class="bi bi-graph-up"></i>EVM: CPI, EAC y márgenes</div>
            </div>
        </div>

        <div class="small text-secondary">CodeLedger © <?= date('Y') ?> · Sistema Integral de Contabilidad Operativa TI</div>
    </section>

    <section class="login-panel">
        <div class="login-theme theme-seg" role="group" aria-label="Apariencia">
            <button type="button" data-theme-choice="light" title="Claro" aria-label="Tema claro"><i class="bi bi-sun"></i></button>
            <button type="button" data-theme-choice="dark" title="Oscuro" aria-label="Tema oscuro"><i class="bi bi-moon-stars"></i></button>
            <button type="button" data-theme-choice="system" title="Sistema" aria-label="Usar el tema del sistema"><i class="bi bi-circle-half"></i></button>
        </div>

        <div class="login-box">
            <div class="login-card">
                <div class="mb-4">
                    <div class="d-lg-none login-brand mb-4">
                        <div class="brand-icon"><i class="bi bi-layers-half"></i></div>
                        <span class="login-brand-text">CodeLedger</span>
                    </div>
                    <h3>Bienvenido de vuelta</h3>
                    <p class="text-muted small mb-0">Accede a tu espacio de contabilidad operativa.</p>
                </div>

                <?php if ($error): ?>
                    <div class="login-alert" role="alert"><i class="bi bi-exclamation-circle-fill"></i><span><?= e($error) ?></span></div>
                <?php endif; ?>

                <form method="post" class="mb-4">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="correo">Correo electrónico</label>
                        <div class="input-affix has-lead">
                            <i class="bi bi-envelope affix-lead" aria-hidden="true"></i>
                            <input type="email" class="form-control form-control-lg" id="correo" name="correo" value="<?= e($_POST['correo'] ?? '') ?>" placeholder="tu@empresa.com" autocomplete="email" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="contrasena">Contraseña</label>
                        <div class="input-affix has-lead">
                            <i class="bi bi-lock affix-lead" aria-hidden="true"></i>
                            <input type="password" class="form-control form-control-lg" id="contrasena" name="contrasena" placeholder="••••••••" autocomplete="current-password" required>
                            <button type="button" class="affix-btn" data-toggle-password="#contrasena" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <button class="btn btn-primary w-100 login-submit" type="submit" data-loading-text="Ingresando…">Entrar a CodeLedger <i class="bi bi-arrow-right"></i></button>
                </form>

                <details class="demo-box">
                    <summary>Accesos demo · contraseña <strong class="ms-1">demo1234</strong></summary>

                <?php foreach ($demos as $demo): ?>
                    <button type="button" class="demo-user" data-demo-email="<?= e($demo[0]) ?>">
                        <div class="avatar avatar-sm"><?= e(strtoupper(substr($demo[1], 0, 1))) ?></div>
                        <div>
                            <strong><?= e($demo[1]) ?></strong>
                            <span><?= e($demo[0]) ?> · <?= e($demo[4]) ?></span>
                        </div>
                        <span class="demo-role <?= e($demo[3]) ?>"><?= e($demo[2]) ?></span>
                    </button>
                <?php endforeach; ?>
                </details>
            </div>

            <div class="login-trust">
                <span><i class="bi bi-shield-lock"></i> Acceso por roles</span>
                <span><i class="bi bi-shield-check"></i> Protección CSRF</span>
                <span><i class="bi bi-journal-text"></i> Auditoría de eventos</span>
            </div>
        </div>
    </section>
</div>
<script src="assets/js/main.js"></script>
</body>
</html>
