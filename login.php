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
    <title>Iniciar sesión · CodeLedger</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-page">
    <section class="login-hero">
        <div>
            <div class="login-brand">
                <div class="brand-icon"><i class="bi bi-layers-half"></i></div>
                CodeLedger
            </div>
            <h2>El puente entre tu operación de software y la contabilidad financiera.</h2>
            <p>Calcula el costo real de producir software, el margen de cada proyecto y el valor ganado en tiempo real. Integra GitHub, cloud, ERPs y timesheets en una sola fuente de verdad.</p>
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
        <div class="login-box">
            <div class="mb-4">
                <div class="d-lg-none login-brand mb-4">
                    <div class="brand-icon"><i class="bi bi-layers-half"></i></div>
                    <span class="text-dark">CodeLedger</span>
                </div>
                <h3>Bienvenido de vuelta</h3>
                <p class="text-muted small mb-0">Accede a tu espacio de contabilidad operativa.</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="mb-4">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="correo">Correo electrónico</label>
                    <input type="email" class="form-control form-control-lg" id="correo" name="correo" value="<?= e($_POST['correo'] ?? '') ?>" placeholder="tu@empresa.com" autocomplete="email" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="contrasena">Contraseña</label>
                    <input type="password" class="form-control form-control-lg" id="contrasena" name="contrasena" placeholder="••••••••" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary w-100 py-2" type="submit">Entrar a CodeLedger</button>
            </form>

            <div class="d-flex align-items-center gap-2 mb-3">
                <hr class="flex-grow-1">
                <span class="text-muted small">Accesos demo · contraseña <strong>demo1234</strong></span>
                <hr class="flex-grow-1">
            </div>

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
        </div>
    </section>
</div>
<script src="assets/js/main.js"></script>
</body>
</html>
