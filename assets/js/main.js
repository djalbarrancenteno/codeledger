document.addEventListener('DOMContentLoaded', () => {
    console.log("CodeLedger Frontend Inicializado");

    const btnSincronizar = document.getElementById('btnSincronizar');
    const resultadoDiv = document.getElementById('resultadoFinOps');

    if(btnSincronizar) {
        btnSincronizar.addEventListener('click', async () => {
            resultadoDiv.style.display = 'block';
            resultadoDiv.innerHTML = '<span class="text-muted">Cargando datos desde la API...</span>';

            try {
                // Aquí va la URL de tu Mock API. 
                // Por ahora usamos una API pública de prueba (JSONPlaceholder) para que veas cómo funciona.
                // Luego la cambiarás por la URL de Mocky que crearemos.
                const response = await fetch('https://jsonplaceholder.typicode.com/posts/1');
                const data = await response.json();
                
                resultadoDiv.innerHTML = `
                    <p class="text-success fw-bold">¡Datos recibidos!</p>
                    <pre>${JSON.stringify(data, null, 2)}</pre>
                `;
            } catch (error) {
                resultadoDiv.innerHTML = `<span class="text-danger">Error conectando a la API: ${error.message}</span>`;
            }
        });
    }
});
