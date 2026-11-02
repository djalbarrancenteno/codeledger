# CodeLedger - Proyecto TI

Sistema Integral de Contabilidad Operativa y Rentabilidad de Proyectos TI.

## Instrucciones para el equipo (XAMPP Local)

1. **Clonar/Copiar el proyecto:**
   Asegúrate de que esta carpeta (`codeledger`) esté dentro de tu directorio `C:/xampp/htdocs/`.

2. **Base de Datos:**
   - Abre XAMPP e inicia los módulos **Apache** y **MySQL**.
   - Ve a `http://localhost/phpmyadmin` en tu navegador.
   - Crea una nueva base de datos llamada **`codeledger_db`**.
   - Selecciona la base de datos recién creada, ve a la pestaña **Importar** y selecciona el archivo `sql/database.sql` que está en este proyecto. Haz clic en "Continuar" u "Obtener".

3. **Ejecutar el proyecto:**
   - Ve a `http://localhost/codeledger/` en tu navegador. Deberías ver la pantalla de inicio y un mensaje en verde que dice "¡Conexión a MySQL exitosa!".

## Áreas de Trabajo

*   **Frontend (UI/UX):** Modificar los archivos HTML/PHP dentro de `views/`, `index.php` y los estilos en `assets/css/style.css`.
*   **JavaScript (APIs y Eventos):** Trabajar en `assets/js/main.js` para consumir la API de GitHub y las Mock APIs.
*   **Backend (PHP y SQL):** Crear los endpoints (archivos `.php`) dentro de la carpeta `api/` para que hagan los INSERT/UPDATE en la base de datos usando PDO.
