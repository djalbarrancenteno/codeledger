<?php
require_once 'includes/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}
redirect('login.php');
