# 7. Generación Automática de Swagger y UI Dashboard

Un producto comercial para desarrolladores destaca enormemente si auto-documenta la API al vuelo. Sin documentación en tiempo real sobre qué campos esperan un POST o qué atributos se reciben en un GET, el desarrollo front-end externo es complejo y frustrante.

## 1. Swagger/OpenAPI Creator

Este es un módulo pasivo (`/Schema/OpenApiBuilder.php`) cuya finalidad es crear un documento JSON compatible con la especificación `OpenAPI 3.0.x`.

### 1.1 El Proceso de Construcción

La reconstrucción manual no ocurre en cada petición HTTP.
Cada vez que el Administrador presiona "Guardar y Exportar" en el Dashboard, o cuando surge un evento vital programado, el sistema consolida el fichero (físico `swagger.json` o expuesto vía endpoint virtual `/wp-custom-api/v1/docs/openapi.json`).

**Mapeo:**

- **Rutas (Paths):** El motor lee todos los "Endpoints Activos" registrados por configuración. Recoge rutas `/{namespace_api}/{mi_cpt}`.
- **Acciones (Methods):** Agrupa sub-nodos para GET, POST, PUT, DELETE según permisos.
- **Componentes / Esquemas (Schemas):** La verdadera magia radica en leer la "Introspección" (Punto 4 del sistema). Si la introspección detectó que 'Propiedad' tiene los capos 'precio' (Numérico) y 'comodidades' (Array de Strings extraído de metadata), esta estructura de Data Mapping se inyecta y se define formalmente. Todo bajo el estándar JSON Schema (`type: object`, `properties: { precio: { type: integer } }`).

### 1.2 Endpoint Integrado

Se expone `/wp-custom-api/v1/docs`, el cual empotrará un visor frontal de _Swagger UI HTML_ sirviendo gráficamente al esquema auto-generado.

## 2. Dashboard Administrativo (Panel de Control)

El backend no usará formularios postback de los años noventa de WordPress sino una aplicación modular inyectada en menú de escritorio.

### 2.1 UI Stack

- Contenedor Root empotrado en la hoja `toplevel_page_custom_api`.
- Interfaz interactiva construida en React / Vue (o componentes web nativos) inyectado por Webpack.

### 2.2 Comunicación Admin UI <-> WordPress

El Frontend Administrativo usará su propia REST API privativa y enjaulada expuesta por el propio API Creator mediante los **Controladores Internos**.
`GET /wp-json/wpcapi/internal/config`
`POST /wp-json/wpcapi/internal/save_config`

### 2.3 Seguridad Interna

Toda petición ejecutada por la UI Javascript se hará autenticada contra las APIs internas usando el nonce provisto al bootstrap (`wp.apiFetch`) y corroborando privilegios plenos `current_user_can('manage_options')`. Absoluta sanitización contra CSRF.

### 2.4 Pantallas Maestras Ideales

1. **Overview (Estado de la Red):** Gráficos simples del número de requests si hay logs activos y links rápidos a Auth Keys.
2. **Endpoints (La Matriz):** Pantalla donde se presentan los CPT seleccionables. Al hacer clic en uno, un modal despliega el árbol gigante de 'Campos Nativos' y 'Metas'. El administrador selecciona interactivamente checkboxes "Habilitar Lectura", "Habilitar Escritura".
3. **Configuración Auth/Seguridad:** Definir durabilidad de tokens JWT, manejo del prefijo final de Namespace `/v1/` y revocadores.
4. **Logs y Auditoría del Sistema:** Monitorización sencilla de qué IPs están sufriendo fallos HTTP 401s recurrentes.
