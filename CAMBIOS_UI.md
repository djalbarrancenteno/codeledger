# CodeLedger · Rediseño UI/UX — resumen de cambios

Integridad funcional: no se modificó ninguna consulta SQL, variable de sesión, atributo `name="..."`,
acción de formulario ni endpoint (`api/get_proyectos.php`, `api/sync_finops.php`, `api/sync_github.php`).

## Archivos modificados
| Archivo | Qué cambió |
|---|---|
| `assets/css/style.css` | Reescrito como sistema de diseño: tokens `--cl-*` y override de `--bs-*` (un solo primario índigo), Inter, `tabular-nums` global, sidebar colapsable, topbar, tabs, toasts, diálogo de confirmación, drawers, spinners, badges, empty states, login. Se conservaron todos los nombres de clase existentes. |
| `includes/layout.php` | Sidebar con iconos SVG (Lucide) agrupado en Operación / Finanzas / Cloud & Datos, colapsable y responsivo; topbar con breadcrumbs, botón de acción principal por pantalla y menú de usuario; flashes entregados como toasts. Misma firma `render_header()` / `render_footer()` y mismas consultas. Nuevo helper `cl_icon()`. |
| `assets/js/main.js` | Toasts, diálogo de confirmación (reemplaza `window.confirm`), spinner y bloqueo de doble envío, confirmación de ediciones en línea, persistencia del sidebar, `?tab=` sincronizado, drawers que se abren solos, mostrar/ocultar contraseña. |
| `login.php` | Error estilizado, mostrar/ocultar contraseña, estado de carga, accesos demo en un bloque plegable, Inter. |
| `project.php` | Pestañas con el estilo de marca (Resumen, Tareas / GitHub, Horas, Costos Cloud, Facturación, Contabilidad); botón de tarea con texto (Cerrar / Reabrir). |
| `rates.php` | Tarifas ya no se guardan al cambiar el valor: piden confirmación. Detalle del colaborador (ficha salarial + costos indirectos) pasa de bloque en la página a drawer lateral. |
| `accounting.php` | Reglas OpEx/CapEx y vida útil piden confirmación antes de guardarse. |
| `finops.php`, `integrations.php` | Botones de sincronización con spinner y texto "Sincronizando…". |
| `includes/functions.php` | Solo `badge_for_status()`: `Open` pasa a azul (antes rojo), `Amortizado` a neutro, y se añaden `Vencida`/`Vencido`/`Excedido` como rojo. |
| `project.php`, `rates.php`, `reports.php`, `timesheets.php` | 7 filas de "Sin registros…" convertidas al componente `.empty-state`. |

## Decisiones tomadas
- Se mantuvo Bootstrap 5.3 como base (modales, offcanvas, dropdown y tabs nativos).
- Sincronización: se conservan los formularios POST actuales (`finops.php` acción `sync`, `integrations.php` acción `run_sync`) con spinner; no se cambió a `fetch` contra `api/` porque ese camino registra distinto (no llama a `audit()`).
- Iconos: sidebar/topbar usan SVG inline; dentro de las páginas se mantiene `bootstrap-icons`.

## No incluido / pendiente
- Tendencias (variación vs. periodo anterior) en los KPIs: requieren consultas nuevas.
- Archivos huérfanos sin tocar y sin referencias: `views/*` y `assets/js/finops_api.js`. Se pueden borrar.
- Hay colores hex literales en las vistas (acentos de KPI y configuración de Chart.js); coinciden con la paleta, pero no son variables.
- No se pudo ejecutar PHP en el entorno de generación: la sintaxis PHP no fue lintada automáticamente (el JS sí). Ejecuta `for f in $(find . -name '*.php'); do php -l $f; done` antes de desplegar.

## Cómo probarlo
1. Descomprime, configura `config/database.php`, importa `sql/database.sql` (y opcional `php scripts/seed_demo.php`).
2. `php -S localhost:8000` en la carpeta del proyecto → `http://localhost:8000`.
3. Entra con `cfo@codeledger.com` / `demo1234` (abre "Accesos demo").
4. Comprueba:
   - Sidebar: botón de la topbar o "Contraer menú" lo colapsa y recuerda el estado; en móvil abre como panel.
   - Topbar: breadcrumbs y botón de acción (Nuevo Proyecto, Registrar Horas, Nueva Factura, Registrar consumo).
   - Cualquier acción con confirmación (anular factura, eliminar integración) muestra el diálogo nuevo.
   - FinOps → Sincronizar cloud: diálogo, spinner y toast de resultado.
   - Tarifas: cambia un valor de la matriz → confirmación; el icono de lápiz abre el drawer del colaborador.
   - Contabilidad: cambia OpEx/CapEx → confirmación (Cancelar restaura el valor).
   - Proyecto → pestañas; recargar mantiene la pestaña (`?tab=`).
   - Cierra sesión desde el menú de usuario y prueba un login con credenciales erróneas.

---

# Rediseño v3 · Modo claro/oscuro/sistema, dashboards y login

Alcance: solo capa visual. No se tocó ninguna consulta SQL, sesión, `name="..."`, acción de formulario,
endpoint ni función de `includes/functions.php`. No se eliminó ningún archivo.

## Archivos modificados
| Archivo | Qué cambió |
|---|---|
| `assets/css/style.css` | Tokens `--cl-*` con dos temas (`:root` claro y `[data-bs-theme="dark"]` oscuro, paleta "Midnight"), reemplazo de colores fijos por tokens, KPIs con halo e icono en degradado, cabeceras de tarjeta con acento, barras de progreso en degradado, hero del dashboard, selector de tema (menú y control segmentado), login nuevo (hero con ilustración, tarjetas flotantes, tarjeta del formulario). |
| `assets/js/main.js` | Gestor de tema: preferencia Claro / Oscuro / Sistema en `localStorage` (`cl.theme`), seguimiento en vivo del sistema operativo, sincronización entre pestañas, y Chart.js que cambia colores de texto, rejilla y tooltips con el tema (`CL.gradient()` para degradados). Los valores por defecto de Chart.js ahora se aplican antes de crear los gráficos. |
| `includes/layout.php` | Script anti-parpadeo en `<head>`, `meta color-scheme`, favicon, iconos `sun/moon/monitor/check` en `cl_icon()` y selector de tema en la topbar. Sin cambios de consultas. |
| `login.php` | Bloque PHP idéntico. HTML nuevo: ilustración del producto, tarjetas flotantes, iconos en los campos, botón con flecha, sello de seguridad (roles, CSRF, auditoría) y selector de tema. Mismos `id`, `name`, CSRF y flujo. |
| `dashboard.php` | Cabecera "hero" con saludo y 4 indicadores (usa variables ya calculadas) y gráficos con degradados, leyenda alineada y tooltip por índice. Mismos datos y consultas. |

## Archivos nuevos
| Archivo | Uso |
|---|---|
| `assets/img/login-dashboard.svg` | Ilustración del dashboard para el hero del login. |
| `assets/img/login-network.svg` | Fondo decorativo (nodos, flujo contable, líneas de libro mayor). |
| `assets/img/favicon.svg` | Favicon de la aplicación. |

## Cómo funciona el tema
- `<html data-bs-theme="light|dark">` (Bootstrap 5.3 nativo) + `<html data-theme-pref="light|dark|system">`.
- Por defecto: **Sistema** (sigue `prefers-color-scheme`). El botón de la topbar (sol/luna/monitor) y el control del login cambian la preferencia.
- Páginas existentes se adaptan sin editarlas: los acentos de KPI en línea (`--accent`) y las cajas con `background:#f8fafc` se resuelven desde el CSS.

## Paleta
Marca: índigo `#4F46E5` (acción) + teal `#0D9488` (apoyo) sobre navy `#0B1220`.
Claro: fondo `#F5F6FB`, superficie `#FFFFFF`, texto `#0F1424`.
Oscuro: fondo `#090D18`, superficie `#111729`, texto `#E9EDF8`, acento `#8B93FF`.
Semánticos (verde/ámbar/rosa/azul) con variantes suaves para ambos temas.
