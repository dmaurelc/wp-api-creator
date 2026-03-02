# 1. Arquitectura Técnica del Plugin

Este módulo define la columna vertebral del plugin. Se establece un enfoque altamente organizado, testeable y desacoplado, evitando los "God Objects" clásicos en el desarrollo de WordPress y adoptando prácticas de ingeniería de software modernas.

## 1. Patrón Arquitectónico y Diseño

El plugin se basará en **Service Layer Architecture** complementado con **Dependency Injection (DI)** utilizando un contenedor compatible con PSR-11.

- **Controllers/Endpoints:** Solo reciben la petición, confían en los middlewares para validación/seguridad, llaman al Service pertinente y retornan respuestas.
- **Services:** Contienen toda la lógica de negocio pura. No tocan variables globales directamente de manera descontrolada (ej. `$_POST`).
- **Repositories:** Las iteraciones con la base de datos de WordPress (`WP_Query`, `wpdb`) se encasulan aquí.
- **Middlewares / Hooks Decorators:** Lógica interceptora para validación de origen, autenticación y rate limiting.

## 2. Estructura de Carpetas Recomendada

```text
/wp-custom-api-builder
├── wp-custom-api-builder.php # Entry point del plugin (solo Bootstrap)
├── /src                      # Código fuente principal (Autoloaded vía PSR-4)
│   ├── /Admin                # Interfaz de administración (Dashboard UI)
│   │   ├── /Controllers      # Controladores REST internos (Para el dashboard React/Vue)
│   │   ├── /Menu             # Registros de add_menu_page, add_submenu_page
│   │   └── /Settings         # Gestor de opciones (Almacenamiento de config)
│   ├── /Api                  # Capa de exposición (Endpoints externos)
│   │   ├── Router.php        # Gestor central de Rewrite Rules y Endpoints
│   │   ├── /Controllers      # Manejadores de rutas /v1/...
│   │   └── /Middleware       # Seguridad, Auth, Headers (Rate limiting)
│   ├── /Auth                 # Autenticación y credenciales
│   │   ├── /Providers        # JWT, APIKey, AppPasswords, OAuth2
│   │   └── TokenManager.php  # Generación/Invalidación
│   ├── /Core                 # Corazón del plugin
│   │   ├── Container.php     # Dependency Injection Container
│   │   ├── Plugin.php        # Puesta en marcha (Init, Activation, Deactivation)
│   │   └── EventHandler.php  # Mapeador de Hooks/Filters orientados a objetos
│   ├── /Domain               # Entidades de negocio y Repositorios
│   │   ├── ConfigBuilder.php # Constructor del estado activo de la API
│   │   └── /Repositories     # Abstracción sobre WP_Query y metadatas
│   ├── /Introspection        # Field y CPT Scanners
│   │   ├── CptScanner.php    # Lector de tipos de post
│   │   ├── MetaScanner.php   # Detector de register_meta, ACF, JetEngine, etc.
│   │   └── TaxonomyScanner.php
│   ├── /Permissions          # Sistema Gatekeeper (Autorización ACL)
│   │   └── AccessControl.php
│   ├── /Schema               # OpenAPI / Swagger Generator
│   │   └── OpenApiBuilder.php
│   ├── /Media                # Ingesta y gestión de imágenes HTTP upload
│   └── /Utils                # Helpers estáticos, Loggers (PSR-3), Sanitizers
├── /assets                   # CSS, JS, imágenes compiladas (Dashboard)
├── /languages                # .pot, .mo, .po para i18n
├── composer.json             # Gestión de dependencias y PSR-4 autoload
└── package.json              # Dependencias Frontend (Webpack/Vite/Babel)
```

## 3. Separación de Responsabilidades (Principio de Responsabilidad Única)

Cada directorio del `/src` debe ser en su mayoría autónomo.

- **`/Admin`** NO tiene ni idea de cómo extraer datos de WP_Query. Solo consume `/Introspection` para mostrarle listas al usuario y guarda config usando `/Domain`.
- **`/Api`** NO sabe cómo funciona la generación del Token, solo le pregunta a `/Auth` si un Request es válido.
- **`/Schema`** (Swagger) es simplemente un "Observador" de la configuración maestra. Lee la configuración dictada por el admin (CPTs activos, campos expuestos) y mapea esto a sintaxis Swagger 3.0.

## 4. Lifecycle Completo de una Petición Externa

Cuando un request llega desde una aplicación frontend a `/wp-custom-api/v1/propiedades`:

1. **WordPress Core Init**: WP carga normalmente resolviendo el enrutamiento.
2. **Registro de Ruteo**: Nuestro plugin previamente suscribió `/wp-custom-api/v1/...` inyectando Rewrite Rules o usando la `WP_REST_API` interna (extendiéndola de forma limpia separada del namespace estándar `/wp/v2/`).
3. **Punto de Entrada (Router)**:
   - Se intercepta el request en el endpoint dinámico.
4. **Middleware en Cascada**:
   - _SecurityCheck_: Determina si la IP no excedió el Rate Limit.
   - _AuthCheck_: Busca cabeceras (`Authorization: Bearer XXX`). Pasa el token al `TokenManager`. Si falla, retorna `401 Unauthorized`.
   - _PermissionCheck (Gatekeeper)_: ¿El usuario asociado al token tiene permisos de "Leectura" (`GET`) sobre "propiedades"? Si no, retorna `403 Forbidden`.
5. **Controlador Dinámico (`Api/Controllers/DynamicEndpointController.php`)**:
   - Compila los argumentos de origen (`?price_min=100&_limit=10`).
   - Llama al `Domain/Repositories/DynamicQueryBuilder`.
6. **Mutación de Salida (Formateo)**:
   - El resultado crudo de WP es iterado. Se aplica el **Field Filter**: Se remueven las keys (campos) ocultas según la configuración del Dashboard y se inyectan las relaciones.
7. **Respuesta HTTP**:
   - Envío en JSON limpio con cabeceras correctas (`application/json`, CORS, caching controls si es necesario).

## 5. Dependency Injection Container y Event System

Para la extensibilidad, en lugar de invocar `add_action` u `add_filter` en todo rincón del plugin, se usará una clase _ServiceProvider_ que inyecta instancias en un registro central, y un _EventHandler_ estructurado:

```php
// Ejemplo Teórico / Pseudocódigo
$container->singleton(AuthInterface::class, function() {
    return new JwtAuthProvider( get_option('wp_api_secret_key') );
});

$container->singleton(Gatekeeper::class, function($c) {
    return new Gatekeeper( $c->get(RoleRepository::class) );
});
```

Cualquier módulo de terceros podría desenchufar `JwtAuthProvider` e inyectar un proveedor de Firebase OAuth a través de filtros tempranos provistos por el plugin (`apply_filters('wpcapi_auth_provider')`).
