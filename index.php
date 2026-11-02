<?php 
// Inclusion de conexión a la BD (database.php) para PRUEBA
require_once 'config/database.php'; 

// Inclusion de cabecera HTML (header.php)
include 'views/header.php'; 
?>

<div class="container">
    <div class="row">
        <div class="col-12">
            <h1 class="display-4">Bienvenido a CodeLedger</h1>
            <p class="lead">Plataforma de Costeo, FinOps y Rentabilidad EVM.</p>
            <hr class="my-4">
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">Prueba de Botón (JS + Mock API)</div>
                <div class="card-body">
                    <p>Haz clic abajo para simular una petición a una API desde JavaScript.</p>
                    <button id="btnSincronizar" class="btn btn-success">Sincronizar Costos FinOps</button>
                    <div id="resultadoFinOps" class="mt-3 p-2 border rounded bg-light" style="display:none;"></div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-secondary text-white">Estado de la Base de Datos</div>
                <div class="card-body">
                    <?php
                    // Prueba rápida de base de datos
                    try {
                        $stmt = $pdo->query("SELECT nombre_completo, rol FROM Usuario LIMIT 3");
                        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        echo "<p class='text-success'><strong>¡Conexión a MySQL exitosa!</strong></p>";
                        echo "<ul>";
                        foreach ($usuarios as $u) {
                            echo "<li>{$u['nombre_completo']} ({$u['rol']})</li>";
                        }
                        echo "</ul>";
                    } catch (Exception $e) {
                        echo "<p class='text-danger'>Aún no se ha importado la base de datos o hay un error: " . $e->getMessage() . "</p>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
// Inclusion de el pie de página HTML (footer.html)
include 'views/footer.php'; 
?>