// Inicialización del entorno de cliente para CodeLedger
document.addEventListener('DOMContentLoaded', () => {
    console.log("CodeLedger Frontend Inicializado");

    const btnSincronizar = document.getElementById('btnSincronizar');
    const resultadoDiv = document.getElementById('resultadoFinOps');

    if (btnSincronizar) {
        btnSincronizar.addEventListener('click', async () => {
            resultadoDiv.style.display = 'block';
            resultadoDiv.innerHTML = '<span class="text-muted">Cargando datos desde las Mock APIs de Cloud...</span>';

            try {
                // Consulta concurrente a las Mock APIs de Cloud alojadas en GitHub Gists
                const [resAws, resAzure] = await Promise.all([
                    fetch('https://gist.githubusercontent.com/djalbarrancenteno/41689ad63b17e03f584460efbcd6b2dc/raw/9091b7e1b9a7c6840b4ad6390f7ca089ece31bda/mock_aws_costs.json'),
                    fetch('https://gist.githubusercontent.com/djalbarrancenteno/e6d5d6b26e7e019a4992ae279b80a21d/raw/53f574eda0489ff08e18064895938ba74ba4246d/mock_azure_costs.json')
                ]);

                if (!resAws.ok || !resAzure.ok) {
                    throw new Error('Fallo al obtener respuesta de los proveedores Cloud');
                }

                const dataAws = await resAws.json();
                const dataAzure = await resAzure.json();

                // Consolidación de registros en un único objeto de respuesta
                const data = {
                    proveedor_aws: dataAws,
                    proveedor_azure: dataAzure
                };
                
                // Renderizado de carga útil JSON en el contenedor visual
                resultadoDiv.innerHTML = `
                    <p class="text-success fw-bold">¡Datos de Cloud recibidos exitosamente!</p>
                    <pre>${JSON.stringify(data, null, 2)}</pre>
                `;
            } catch (error) {
                // Captura y despliegue de excepciones durante la petición HTTP
                resultadoDiv.innerHTML = `<span class="text-danger">Error conectando a la API: ${error.message}</span>`;
            }
        });
    }
});
