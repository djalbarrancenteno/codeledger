# CodeLedger

Sistema integral de contabilidad operativa para empresas de servicios TI. Cubre el ciclo completo desde el registro de horas hasta la facturación, el reconocimiento de ingresos, la capitalización bajo IAS 38 y el análisis de rentabilidad (EVM / FinOps).

Stack: **PHP 8.3 + MySQL 8 + Bootstrap 5**, sin Composer ni Node. Todas las consultas usan PDO con sentencias preparadas.

## Funcionalidades

- **Costos reales y tarifas**
  - Fully Loaded Rate: salario base, prestaciones, impuestos patronales, licencias, hardware amortizado y costos asignados.
  - Matriz de tarifas de venta por rol (Junior / Senior / Architect) y modalidad (Interno / Contractor / Agencia).
  - Clasificación de horas: Billable, Operativa e I+D.
- **FinOps**
  - Consumos AWS, Azure, GCP, Vercel y APIs de terceros.
  - Imputación a proyecto e hito, presupuestos mensuales y alertas por desviación.
  - Sincronización con mocks locales (sin credenciales externas).
- **Contabilidad IAS 38**
  - Separación automática de costos de investigación (OpEx) y desarrollo (CapEx).
  - Reglas contables por proyecto, capitalización como activo intangible y amortización lineal.
- **EVM y rentabilidad**
  - Margen bruto, CPI, EAC, VAC y avance por proyecto.
  - Rentabilidad por cliente, tecnología, metodología y proyecto.
  - Burn rate, runway, costo por historia de usuario, matriz de utilización y proyección de flujo de caja.
- **Facturación**
  - Pre-facturas T&M desde horas aprobadas y no facturadas.
  - Facturas manuales y facturación de hitos entregados (Fixed Price).
  - Retainer mensual con bolsa de horas, rollover y facturación del período.
  - Ingreso diferido bajo ASC 606 / NIIF 15 y multi-moneda con tipo de cambio.
- **Integraciones**
  - Jira, GitHub, ClickUp, AWS, Azure, GCP, Vercel, SAP, QuickBooks, Odoo, Stripe.
  - Conexión/desconexión, ejecución de sincronizaciones y bitácora de logs.
  - Sync local de GitHub Issues y costos cloud mediante archivos mock.
- **Seguridad y RBAC**
  - CFO: acceso total, incluida nómina, costos, capitalización y configuraciones.
  - PM: solo proyectos que gestiona, sin detalle de nómina individual; puede operar hitos, tareas, horas y facturación de sus proyectos.
  - DEV: timesheets y tareas propias; sin acceso a métricas financieras, tarifas ni integraciones.
  - CSRF en todos los formularios, sesiones con cookies `HttpOnly` y auditoría de eventos.

## Requisitos

- PHP 8.1 o superior con `pdo_mysql` habilitado.
- MySQL 8+ (o MariaDB compatible con las consultas usadas).
- Servidor web Apache/Nginx o el servidor embebido de PHP.

## Instalación

1. Crear e importar la base de datos:

   ```bash
   mysql -u root -p < sql/database.sql
   ```

2. Configurar la conexión. Por defecto se usa `localhost:3306`, base `codeledger_db`, usuario `root` sin contraseña. Se puede sobreescribir con variables de entorno:

   ```bash
   export DB_HOST=127.0.0.1
   export DB_PORT=3306
   export DB_NAME=codeledger_db
   export DB_USER=root
   export DB_PASSWORD=
   ```

3. Cargar los datos demo:

   ```bash
   php scripts/seed_demo.php
   ```

4. Iniciar el servidor:

   ```bash
   php -S 127.0.0.1:8090 -t .
   ```

   Abrir <http://127.0.0.1:8090>.

## Usuarios demo

| Rol | Correo | Contraseña |
| --- | --- | --- |
| CFO | `cfo@codeledger.com` | `demo1234` |
| PM | `pm@codeledger.com` | `demo1234` |
| DEV Senior | `dev@codeledger.com` | `demo1234` |
| DEV Architect | `architect@codeledger.com` | `demo1234` |
| DEV Junior | `junior@codeledger.com` | `demo1234` |
| PM secundario | `pm2@codeledger.com` | `demo1234` |

## Estructura

```
api/                 Endpoints JSON (proyectos, sync GitHub, sync FinOps)
assets/              CSS y JavaScript propios
config/              Conexión PDO por variables de entorno
includes/            Bootstrap, funciones de negocio, layout y RBAC
mocks/               Respuestas simuladas de GitHub, AWS y Azure
scripts/             Generador de datos demo
sql/database.sql     Esquema completo MySQL 8
*.php                Páginas del sistema (dashboard, proyectos, finanzas, reportes...)
```

## Sincronizaciones locales

- `finops.php` → **Sincronizar** importa `mocks/mock_aws_costs.json` y `mocks/mock_azure_costs.json`.
- `integrations.php` → **Sincronizar** en GitHub importa `mocks/github_issues.json`.
- `POST api/sync_github.php` y `POST api/sync_finops.php` permiten disparar las mismas sincronizaciones por API. Requieren sesión con rol CFO/PM y el token CSRF en la cabecera `X-CSRF-Token`.

Para probar la API desde el navegador con sesión iniciada:

```bash
curl -b cookies.txt -H "X-CSRF-Token: <token>" -X POST \
  http://127.0.0.1:8090/api/sync_finops.php
```

## Notas de negocio

- Una hora solo se factura cuando está **aprobada**, es **Billable**, tiene tarifa de venta congelada y no está vinculada a otro comprobante.
- Los hitos entregados se reconocen al 100% en la fecha de entrega; las facturas manuales se reconocen linealmente hasta el vencimiento.
- Los ingresos cobrados por adelantado permanecen como ingreso diferido hasta que se consume el servicio.
- El runway usa la caja configurada (`saldo_caja`) y el costo promedio de los últimos tres meses.
