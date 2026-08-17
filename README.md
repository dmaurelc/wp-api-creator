# WP Custom API Creator

Contributors: danielmaurel
Tags: api, rest api, headless cms, json, wordpress api, custom endpoints, dashboard, api creator
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
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

# Filtrar con meta campos (la meta debe estar seleccionada en el endpoint)
GET /wp-json/mi-namespace/v1/propiedades?meta_key=precio&meta_value=100000

# Paginación
GET /wp-json/mi-namespace/v1/propiedades?limit=10&page=2

# Buscar y ordenar
GET /wp-json/mi-namespace/v1/propiedades?search=duplex&orderby=title&order=ASC

# Obtener una entrada por su slug
GET /wp-json/mi-namespace/v1/propiedades?slug=duplex-en-providencia

# Filtrar por taxonomía (varios términos = OR, varias taxonomías = AND)
GET /wp-json/mi-namespace/v1/propiedades?ubicacion=providencia,las-condes&estado=en-venta
```

Los parámetros de taxonomía existen solo para las taxonomías marcadas en el editor del endpoint, y toman el nombre de la taxonomía.

> **`_fields` no funciona en esta API.** WordPress lo reserva y recorta las claves de primer nivel de la respuesta; como estas rutas devuelven `{data, meta}`, `?_fields=id,title` devuelve `[]`. Los campos se eligen en el editor de endpoints, no por petición.

### Autenticación

El plugin soporta múltiples métodos de autenticación en orden de prioridad:

1. **API Key**: Header `X-API-Key: tu-clave`
2. **JWT Bearer**: Header `Authorization: Bearer tu-token`
3. **Application Password**: Header Basic Auth estándar de WordPress

Configura tus credenciales desde el dashboard en **Autenticación**.

Cada API Key se crea asociada a un usuario de WordPress y hereda sus permisos, por lo que conviene usar una cuenta con el rol mínimo que necesite la integración. La clave en claro se muestra **una sola vez** al crearla: el plugin solo guarda su hash. Las claves admiten fecha de caducidad y el listado registra su último uso.

Desde **Ajustes** puedes activar *Exigir credencial en tu namespace*, que hace que cualquier petición a tus rutas sin credencial válida reciba un 401 aunque el endpoint esté marcado como público. Los endpoints nativos de WordPress quedan fuera: los registra WordPress y se rigen por sus propias reglas.

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

### 1.2.0

Versión de utilidad: la API de lectura pasa a poder representar taxonomías y los parámetros que se documentaban sin efecto empiezan a tenerlo.

#### Correcciones de comportamiento

Estos cuatro parámetros y ajustes estaban documentados o visibles en el dashboard y no hacían nada. **Ahora sí lo hacen, así que un cliente que los enviara sin notarlo verá otra respuesta.**

* **`search` filtra.** Hasta ahora `?search=loquesea` devolvía la colección entera, sin error. Si tu integración lo enviaba y esperaba la lista completa, retíralo.
* **`orderby` y `order` ordenan.** `orderby` acepta `date`, `modified`, `title`, `menu_order` e `ID`; `order` acepta `ASC` y `DESC`. **Ambos distinguen mayúsculas y minúsculas, y cualquier otro valor devuelve `400`** donde antes se ignoraba en silencio: si tu cliente venía enviando `?order=asc` u `?orderby=id` —la caja que usa la API nativa de WordPress— tendrás que ajustarlo. Quedan fuera `meta_value`, `meta_value_num` y `rand`, que obligan a consultas sin índice.
* **`meta_key` y `meta_value` filtran**, y solo se aceptan sobre las metas que el endpoint expone: pedir una clave no expuesta devuelve `400`. Un endpoint sin metas seleccionadas no declara los dos parámetros. Los dos van siempre juntos —enviar uno solo devuelve `400` en lugar de ignorarse— y `meta_value=0` filtra en lugar de descartarse.
* **`cache_time` cachea de verdad.** Al actualizar **se fuerza a 0** aunque tuvieras un valor guardado: el ajuste llevaba versiones siendo decorativo y el dashboard recomendaba 300, así que activarlo solo debe ocurrir a conciencia. Actívalo cuando quieras desde **Ajustes**, donde también hay un botón para purgar la caché.

#### Novedades

* **Taxonomías en la respuesta.** El editor de endpoints muestra un grupo «Taxonomías» con las taxonomías públicas del tipo de contenido. Las marcadas se devuelven bajo la clave `taxonomies`, agrupadas por taxonomía, con `id`, `name` y `slug` de cada término. Una taxonomía y un campo personalizado con el mismo nombre conviven sin pisarse.
* **Filtrado por taxonomía.** Cada taxonomía marcada acepta un parámetro de consulta con su nombre: `?categoria=guias`. Varios términos separados por coma se combinan con OR (`?categoria=guias,php`); varias taxonomías, con AND. Un término inexistente devuelve cero resultados.
* **Filtro por `slug`**: `?slug=mi-articulo` devuelve la entrada correspondiente sin necesidad de conocer su ID.
* **Caché de respuestas configurable**, limitada a los listados públicos. No se cachean la ruta de un elemento concreto, las peticiones que piden un estado distinto de publicado ni las que llevan un parámetro de valor libre (`search`, `slug` o `meta_value`), y la clave incorpora los roles de quien pregunta. Requiere un object cache persistente (Redis o Memcached) para ahorrar trabajo entre peticiones; el dashboard avisa si no lo hay.
* **La documentación OpenAPI y la colección de Postman se derivan de la misma lista de parámetros que registra cada ruta**, incluidos los de taxonomía, que aparecen solo en los endpoints que las exponen.

#### Rendimiento

* Los campos nativos se resuelven solo si el endpoint los expone. Antes, un endpoint configurado para devolver únicamente el título ejecutaba igualmente el renderizado completo del contenido —bloques, shortcodes y oEmbed— por cada entrada de la colección.

#### Notas para quien integra

* **`_fields` no funciona con esta API y nunca lo hizo.** WordPress reserva ese nombre y recorta las claves de primer nivel de la respuesta; como estas rutas devuelven `{data, meta}`, `?_fields=id,title` devuelve `[]` y `?_fields=meta` devuelve solo la paginación. No lo uses: selecciona los campos en el editor de endpoints.
* Los permisos por campo no gobiernan las taxonomías. Una taxonomía que no deba salir se deja sin marcar en el endpoint.

### 1.1.0

Actualización de seguridad. **Contiene cambios incompatibles**: revisa esta lista antes de actualizar.

#### Cambios incompatibles

* **Las API Keys existentes dejan de funcionar.** El modelo anterior guardaba la clave en claro y no llegaba a validar en ningún caso: las claves creadas desde el dashboard se escribían en una ubicación distinta de la que consultaba el validador, así que en la práctica ninguna autenticó nunca. Las claves antiguas quedan marcadas como obsoletas y hay que crear una nueva por integración.
* **Cada API Key se asocia ahora a un usuario de WordPress** y hereda sus permisos. Ya no existe el mapeo automático al primer administrador ni la clave maestra de desarrollo.
* **Los tokens JWT emitidos antes de esta versión dejan de ser válidos.** Los clientes deben volver a autenticarse en `POST /auth/token`.
* **`require_api_key` se aplica de verdad.** Hasta ahora la opción se guardaba pero ninguna ruta la exigía. Cubre las rutas de tu namespace y la documentación; no afecta a los endpoints nativos de WordPress (`/wp-json/wp/v2/...`), que WordPress registra por su cuenta. Está desactivada por defecto y solo puede activarse desde el dashboard, con confirmación explícita.
* **`/docs` y `/docs/openapi.json` quedan cerrados** cuando `require_api_key` está activo. Con la opción desactivada siguen siendo públicos.
* **`POST /media` exige la capacidad `upload_files`**, no solo una sesión iniciada.
* **Una credencial inválida devuelve 401 aunque el endpoint sea público.** Antes se ignoraba en silencio y la petición seguía como anónima. Ahora la respuesta indica el motivo (`jwt_expired`, `jwt_revoked`, `jwt_invalid_signature`, `jwt_invalid_issuer`, `api_key_invalid`). La única excepción es `POST /auth/token`, que sigue aceptando peticiones con un Bearer caducado adjunto para permitir la renovación.
* **Se retira el parámetro `_include`** de las colecciones. Se aceptaba y documentaba, pero no hacía nada.

#### Seguridad

* **Corregido un bypass de autenticación en la vía Basic Auth.** `wp_authenticate_application_password()` devuelve su primer argumento sin modificar —no un error— cuando el sitio no tiene ninguna Application Password creada, que es el estado por defecto. El plugin lo interpretaba como validación correcta, de modo que bastaba conocer un nombre de usuario para autenticarse con cualquier contraseña y operar con los permisos de esa cuenta. Afecta a 1.0.0. **Si usas este plugin en producción, actualiza.**
* Revocación de tokens por usuario desde el dashboard, y automática al cambiar la contraseña, al cambiar de rol y al borrar la cuenta.
* Validación del emisor (`iss`) del token: un token de otra instalación se rechaza.
* Se elimina el secreto de firma por defecto que venía escrito en el código. La clave se resuelve desde `WP_API_CREATOR_JWT_SECRET`, `SECURE_AUTH_KEY` o una clave generada y almacenada por el plugin.
* Las API Keys se almacenan hasheadas con SHA-256 y admiten fecha de caducidad. La clave en claro se muestra una sola vez.
* Limitación de intentos fallidos en `POST /auth/token` y en Basic Auth, por IP y por cuenta, con un contador independiente para las API Keys inválidas.
* El namespace de la API se valida al guardarlo: se rechazan los namespaces reservados por WordPress y el de administración del plugin.
* El estado de las entradas en las colecciones se resuelve por capacidades. Pedir `?status=draft` sin `edit_others_posts` devuelve solo las entradas propias.

#### Correcciones

* Guardar los ajustes ya no borra los campos ausentes del formulario.
* Revocar la primera API Key ya no corrompe el listado del dashboard.
* La expiración configurable de los tokens JWT se aplica realmente; antes estaba fijada a 24 horas en el código.
* El middleware de autenticación respeta las respuestas ya resueltas por otros plugins y no interfiere con las peticiones `OPTIONS` de preflight CORS.
* Los errores de credencial indican el motivo (expirado, revocado, firma inválida, emisor ajeno) en lugar de un 401 genérico.

#### Interno

* Suite de tests unitarios con PHPUnit y Brain Monkey (`composer test`).
* Corregidas las reglas de empaquetado: los patrones de directorio de `.gitattributes` terminaban en barra y no excluían nada, de modo que el ZIP de distribución incluía `docs/` y el código fuente del dashboard sin compilar.
* El dashboard muestra la versión real del plugin en lugar de un valor escrito a mano que se había quedado anclado en 1.0.0.
* Corregida la vista previa de la URL en el editor de endpoints, que leía mal la respuesta de ajustes y mostraba siempre el namespace de reserva en lugar del configurado.
* Copia de seguridad automática de la configuración en `wp_api_creator_config_backup_1_0_0` antes de migrar. Consulta el procedimiento de reversión en `docs/09_gestion_de_lanzamientos.md`.

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
