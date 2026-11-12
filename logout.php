<?php
require_once 'includes/bootstrap.php';

if (current_user()) {
    audit('logout', 'Usuario', (int)current_user()['id_usuario'], 'Cierre de sesión');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
redirect('login.php');
