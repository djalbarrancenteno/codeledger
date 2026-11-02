<?php
// Configuración básica para XAMPP local
$host = 'localhost';
$dbname = 'codeledger_db';
$username = 'root';
$password = ''; // Por defecto en XAMPP no hay contraseña

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    // Configurar PDO para que lance excepciones en caso de error
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión a la Base de Datos: " . $e->getMessage());
}
?>
