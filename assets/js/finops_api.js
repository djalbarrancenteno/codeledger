// URLs públicas de los Mock APIs alojados en GitHub Gists
const MOCK_API_AWS = 'https://gist.githubusercontent.com/djalbarrancenteno/41689ad63b17e03f584460efbcd6b2dc/raw/9091b7e1b9a7c6840b4ad6390f7ca089ece31bda/mock_aws_costs.json';
const MOCK_API_AZURE = 'https://gist.githubusercontent.com/djalbarrancenteno/e6d5d6b26e7e019a4992ae279b80a21d/raw/53f574eda0489ff08e18064895938ba74ba4246d/mock_azure_costs.json';

/**
 * Consulta y unifica los consumos de proveedores Cloud simulados
 * @returns {Promise<Array>} Lista de cargos normalizados para la tabla ConsumoFinOps
 */
async function obtenerCostosFinOps() {
    try {
        // Ejecución concurrente de ambas peticiones
        const [resAws, resAzure] = await Promise.all([
            fetch(MOCK_API_AWS),
            fetch(MOCK_API_AZURE)
        ]);

        if (!resAws.ok || !resAzure.ok) {
            throw new Error('Fallo al obtener respuesta de uno de los proveedores Cloud');
        }

        const datosAws = await resAws.json();
        const datosAzure = await resAzure.json();

        // Extracción y unificación de los registros de cargos
        const cargosTotales = [
            ...datosAws.cargos.map(item => ({ ...item, proveedor: 'AWS' })),
            ...datosAzure.cargos.map(item => ({ ...item, proveedor: 'Azure' }))
        ];

        return cargosTotales;
    } catch (error) {
        console.error('Error al sincronizar datos FinOps:', error);
        throw error;
    }
}