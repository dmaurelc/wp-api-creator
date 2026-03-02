# WP Custom API Creator

Contributors: danielmaurel
Tags: api, rest api, headless cms, json, wordpress api, custom endpoints, dashboard, api creator
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Transforma WordPress en una API personalizada configurable desde un dashboard propio. Crea endpoints dinámicos para tus Custom Post Types, configura autenticación flexible y genera documentación OpenAPI automáticamente.

## Description

**WP Custom API Creator** es un plugin que convierte tu instalación de WordPress en un headless CMS altamente configurable mediante un dashboard de administración moderno.

### Características Principales

- **Endpoints Dinámicos**: Detecta automáticamente tus Custom Post Types y crea rutas REST API sin necesidad de programar
- **Namespace Personalizable**: Define tu propio namespace para todas las rutas de la API
- **Autenticación Flexible**: Sistema pipeline que soporta Application Passwords, API Keys y JWT
- **Sistema de Permisos**: Control granular de acceso a recursos con Gatekeeper
- **Query Builder Avanzado**: Filtrado, ordenamiento, paginación y búsqueda full-text
- **Subida de Imágenes**: Endpoint dedicado para gestión de media
- **Documentación OpenAPI**: Generación automática de especificaciones Swagger y colecciones Postman
- **Dashboard SPA**: Interfaz moderna construida con React y Tailwind CSS
- **Logs de Actividad**: Registro de peticiones para depuración

### Integraciones Compatibles

Detecta automáticamente campos de:
- ACF (Advanced Custom Fields)
- JetEngine
- MetaBox
- Flavor Real Estate
- Y cualquier campo nativo de WordPress

## Installation

### Instalación Automática

1. En tu panel de WordPress, ve a **Plugins > Añadir nuevo**
2. Sube el archivo `wp-api-creator.zip`
3. Activa el plugin desde el menú **Plugins**
4. Accede al dashboard desde **WP API Creator** en el menú principal

### Instalación Manual

1. Sube la carpeta `wp-api-creator` al directorio `/wp-content/plugins/`
2. Activa el plugin desde el menú **Plugins** en WordPress
3. Configura tu namespace preferido en **Ajustes**

## Usage

### Configuración Inicial

1. Tras activar el plugin, define tu **Namespace Global** en los ajustes
2. Los endpoints estarán disponibles en: `https://tudominio.com/wp-json/tu-namespace/v1/`

### Ejemplo de Uso

```bash
# Listar recursos (ej: propiedades)
GET /wp-json/mi-namespace/v1/propiedades

# Obtener un recurso específico
GET /wp-json/mi-namespace/v1/propiedades/123

# Crear un nuevo recurso (requiere autenticación)
POST /wp-json/mi-namespace/v1/propiedades

# Filtrar con meta campos
GET /wp-json/mi-namespace/v1/propiedades?meta_key=precio&meta_value=100000

# Paginación
GET /wp-json/mi-namespace/v1/propiedades?limit=10&page=2
```

### Autenticación

El plugin soporta múltiples métodos de autenticación en orden de prioridad:

1. **API Key**: Header `X-API-Key: tu-clave`
2. **JWT Bearer**: Header `Authorization: Bearer tu-token`
3. **Application Password**: Header Basic Auth estándar de WordPress

Configura tus credenciales desde el dashboard en **Autenticación**.

## Frequently Asked Questions

### ¿Necesito saber programar para usar este plugin?

No. El plugin detecta automáticamente tus Custom Post Types y crea los endpoints. Solo necesitas configurar el namespace y la autenticación desde el dashboard.

### ¿Funciona con cualquier tema de WordPress?

Sí. El plugin es independiente del tema y funciona con cualquier instalación de WordPress 6.0+.

### ¿Puedo limitar el acceso a ciertos endpoints?

Sí. Desde el dashboard puedes configurar permisos por recurso y por método HTTP.

### ¿Los endpoints incluyen mis campos personalizados de ACF?

Sí. El plugin detecta automáticamente campos de ACF, JetEngine, MetaBox y otros plugins similares.

## Changelog

### 1.0.0
* Primer lanzamiento estable
* Endpoints dinámicos para Custom Post Types
* Sistema de autenticación con API Keys, JWT y Application Passwords
* Dashboard SPA con React y Tailwind CSS
* Generación de documentación OpenAPI y colecciones Postman
* Sistema de permisos Gatekeeper
* Query Builder avanzado
* Logs de actividad
* Subida de imágenes mediante API
