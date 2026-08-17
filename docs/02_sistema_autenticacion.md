# 2. Sistema de Autenticación Configurable

Convertir WordPress en una API externalizada requiere sistemas de autenticación agnósticos de estado (Stateless Auth) modernos. La API nativa usa cookies, lo cual es inútil para aplicaciones SPA desacopladas, móviles o servicios de servidor a servidor.

El plugin provee un sistema multi-capa y delegable (Provider-based).

> Este documento describe el comportamiento implementado a partir de la versión 1.1.0.

## 1. Patrón Provider

`AuthMiddleware` (`src/Auth/AuthMiddleware.php`) intenta resolver la identidad del solicitante recorriendo los proveedores en orden fijo. El primero que resuelva un usuario gana; si ninguno lo hace, la petición sigue como invitada y el Gatekeeper decide.

1. **API Keys** (`X-API-Key`): comunicaciones de servidor a servidor.
2. **Application Passwords** (`Authorization: Basic`): credenciales nativas de WordPress.
3. **JSON Web Tokens** (`Authorization: Bearer`): SPA y aplicaciones móviles.

> **Cuidado con `wp_authenticate_application_password()`.** No devuelve un `WP_Error` en todas sus salidas de fallo: en varias devuelve su primer argumento sin tocarlo (aquí `null`), en particular cuando `WP_Application_Passwords::is_in_use()` es `false` — el estado de un sitio en el que nadie ha creado todavía una contraseña de aplicación — y cuando el filtro `application_password_is_api_request` descarta la petición. Comprobar solo `is_wp_error()` trataba esas salidas como éxito y permitía autenticarse con cualquier contraseña conociendo únicamente un nombre de usuario. `ApplicationPasswordProvider` exige ahora una instancia de `WP_User`, que es lo único que acredita credenciales válidas, y devuelve el ID que resuelve core en lugar del de una búsqueda propia por `user_login` (core también acepta el correo como identificador).

Cuando una credencial llega y falla, el motivo se adjunta a la petición con `WP_REST_Request::set_attributes()` y el Router lo traduce en la respuesta. Se usa el bag de atributos y no el de parámetros porque este último también lee query string y cuerpo, de modo que un cliente podría inyectar la clave y falsear el motivo.

## 2. Flujo JWT

### Endpoint de emisión: `POST /{namespace}/auth/token`

- **Body**: `{ "username": "xxx", "password": "yyy" }`
- **Acción**: valida con `wp_authenticate()`.
- **Emisión**: token firmado con `HS256`, implementación standalone sin dependencias externas.
- **Payload**:

  ```json
  {
    "iss": "https://midominio.com",
    "iat": 1670000000,
    "nbf": 1670000000,
    "exp": 1670003600,
    "jti": "uuid-v4",
    "data": { "user_id": 12, "tv": 1 }
  }
  ```

### Resolución de la llave de firma

Por orden:

1. Secreto inyectado por constructor (`new JwtProvider($secreto)`), usado solo por los tests.
2. Constante `WP_API_CREATOR_JWT_SECRET` de `wp-config.php`.
3. Constante `SECURE_AUTH_KEY`.
4. Secreto autogenerado en la option `wp_api_creator_jwt_secret`, con `autoload = no`.

El secreto autogenerado **no** puede vivir dentro de `config['settings']`: el dashboard reemplaza ese subárbol al guardar, y perder la llave regeneraría otra e invalidaría todos los tokens vivos del sitio sin causa aparente.

Si no hay ninguna llave utilizable, `generate_token()` devuelve un `WP_Error`. Por eso el método no declara tipo de retorno: PHP 7.4 no admite tipos union y devolver un `WP_Error` desde una firma `: string` sería un error fatal en lugar de un error manejable.

### Expiración

La dicta el ajuste `jwt_expiration` (pestaña JWT del dashboard), con 24 horas por defecto y 1 hora como mínimo.

### Validación

`validate_token()` comprueba, en este orden: formato de tres segmentos, firma con `hash_equals`, integridad del payload, `nbf`, `exp`, emisor (`iss` frente a `get_bloginfo('url')`), versión de token y existencia del usuario. Cada fallo devuelve un código distinto: `jwt_invalid_format`, `jwt_invalid_signature`, `jwt_invalid_payload`, `jwt_not_active`, `jwt_expired`, `jwt_invalid_issuer`, `jwt_revoked`, `jwt_user_not_found`.

### Revocación

El payload incluye `tv`, la versión de token del usuario, almacenada en el meta `_wpac_token_version`. Incrementarla invalida de golpe todos los tokens vivos de esa cuenta, sin lista negra de identificadores individuales.

Dos reglas evitan un fallo abierto:

- `tv` es **obligatorio**. Un token sin ese campo — los emitidos hasta 1.0.0 — se rechaza como revocado. Aceptarlo por ausencia lo mantendría válido indefinidamente.
- La **ausencia del meta nunca equivale a una versión válida**. Si una migración o un plugin de limpieza de `usermeta` la borrase, comparar contra un cero implícito resucitaría tokens ya revocados.

La versión se siembra a `1` en la activación del plugin, mediante una única consulta, y al emitir el primer token de cada usuario.

Se revoca automáticamente en `after_password_reset`, `wp_set_password`, `set_user_role`, `delete_user` y en `profile_update` **solo si cambió el hash de contraseña**: sin ese guard, guardar el perfil cerraría todas las sesiones del usuario.

Manualmente: `DELETE /creator/v1/admin/users/{id}/tokens`, expuesto en el dashboard como botón por usuario en la pestaña JWT.

### Rutas exentas

`auth/token` nunca recibe un 401 por una credencial previa fallida. Un cliente móvil que renueva su token caducado envía la petición con el Bearer viejo todavía adjunto; sin la exención quedaría bloqueado justo en el único endpoint que podría desbloquearlo.

## 3. Flujo por API Keys

### Modelo

Cada key vive en `$config['api_keys']` — la raíz del option, no dentro de `settings` — con esta forma:

```php
[
  'id'         => 'uniqid',
  'name'       => 'Integracion CRM',
  'hash'       => hash('sha256', $clave_en_claro),
  'prefix'     => 'ak_7f3a',   // fragmento visible en la interfaz
  'user_id'    => 42,
  'expires_at' => null,        // timestamp UNIX o null
  'created_at' => '2026-08-17 12:31:00',
  'legacy'     => false,
]
```

**Cada key está ligada a un usuario real de WordPress y hereda su rol.** No existe clave maestra ni mapeo automático al primer administrador.

El último uso se guarda aparte, en la option `wp_api_creator_key_usage` (`autoload = no`), con una guarda de 5 minutos entre escrituras. Anotarlo dentro del blob de configuración obligaría a reescribir el documento entero desde el path de lectura, partiendo de una lectura cacheada hasta 5 minutos, y podría revertir en silencio cambios recién guardados por un administrador.

### Validación

1. Hashear la clave entrante con SHA-256.
2. Comparar con `hash_equals` contra los hashes almacenados.
3. Descartar las marcadas `legacy`.
4. Comprobar `expires_at` y la existencia del usuario.
5. Anotar el uso en la option dedicada.
6. Devolver el `user_id` real.

**Por qué SHA-256 y no `password_hash`**: una API Key es un secreto de 128 bits generado por el plugin, no una contraseña humana. No necesita el coste de bcrypt, que además obligaría a un `password_verify` contra cada key almacenada en el path de cada petición.

### Keys del esquema anterior

Hasta 1.0.0 el dashboard escribía en `$config['api_keys']` y el validador leía en `$config['settings']['api_keys']`: dos arrays disjuntos que nadie puenteaba. Ninguna key creada desde la interfaz llegó a autenticar. La migración de 1.1.0 las consolida, las marca `legacy` y borra el valor en claro. No se rehidratan: no consta a qué usuario pertenecían y asignarlas al administrador perpetuaría el privilegio excesivo.

## 4. Enforcement global (`require_api_key`)

Con la opción activa, toda petición al namespace configurable sin credencial válida recibe un 401, incluso en endpoints marcados como `public`. `/docs` y `/docs/openapi.json` entran en el enforcement.

**Alcance.** El enforcement cubre exclusivamente el namespace del plugin. Los endpoints nativos de WordPress (`/wp/v2/*`) los registra core y quedan fuera: interceptarlos rompería el editor de bloques, oEmbed, Site Health y cualquier otro plugin que dependa de rutas core públicas. La configuración de «Rutas globales» (`config['global_routes']`) no cambia nada de esto: solo la consumen `OpenApiBuilder` — para incluir esas rutas en el esquema — y el listado del dashboard. No expone ni protege ninguna ruta.

El punto de enganche es `Router::run_auth_middleware()` sobre `rest_pre_dispatch`, que corre antes de los `permission_callback`. Tres guardas condicionan su funcionamiento:

1. Si `$result` no es nulo, otro plugin ya resolvió la petición y se devuelve intacta.
2. Las peticiones `OPTIONS` se excluyen. WordPress engancha `rest_handle_options_request` al mismo filtro, y los navegadores nunca adjuntan cabeceras personalizadas en un preflight: un 401 ahí rompería toda integración desde navegador con un error de CORS opaco.
3. El cortocircuito devuelve un `WP_Error`. Core evalúa `! empty($result)`, de modo que `false`, `[]` o `0` no cortarían el flujo.

Las rutas de administración viven en el namespace fijo `creator/v1` y quedan fuera del middleware, de modo que siguen accesibles con cookie de administrador aunque el enforcement esté activo.

## 5. Limitación de intentos

Los intentos fallidos se cuentan con claves independientes, respaldadas por transients:

| Clave | Umbral | Ventana | Alimentada por |
|---|---|---|---|
| `wpac_fail_ip_{hash}` | 5 | 15 min | `POST /auth/token` y Basic Auth |
| `wpac_fail_user_{hash}` | 10 | 60 min | `POST /auth/token` y Basic Auth |
| `wpac_fail_key_{hash}` | 20 | 15 min | API Keys inválidas |

Solo cuentan los fallos; una credencial correcta limpia las claves implicadas.

**Por qué tres contadores y no dos.** Las API Keys tienen su propio espacio porque comparten poco con la adivinación de contraseñas: son secretos de 128 bits generados por el plugin, inviables por fuerza bruta, y el contador solo frena el escaneo ruidoso. Si compartieran contador con el login, una integración de servidor a servidor con una key caducada reintentando en bucle dejaría sin acceso a `/auth/token` a toda su IP de salida.

La clave por IP en solitario sería un arma de bloqueo: unas pocas peticiones basura dejarían sin servicio a una oficina entera o a cualquier sitio tras proxy. Y sin clave por cuenta, el credential stuffing distribuido pasaría intacto.

**La ventana es fija, no deslizante.** El contador guarda su instante de caducidad y los incrementos posteriores no lo desplazan. Con una ventana deslizante, quien reintentase en bucle prolongaría su propio bloqueo indefinidamente — y, en la clave por cuenta, el de la víctima que eligiese: la protección se convertiría en el vector de denegación de servicio.

Un Bearer inválido **no** cuenta para ningún contador. La firma HS256 no es adivinable, así que no hay ataque que frenar, y una aplicación con el token caducado se bloquearía a sí misma el acceso al endpoint de renovación.

`REMOTE_ADDR` es el valor por defecto. `X-Forwarded-For` solo se lee cuando la instalación declara sus proxies de confianza en la constante `WP_API_CREATOR_TRUSTED_PROXIES`; sin ella, un atacante podría falsear la cabecera y bloquear a la víctima que eligiese.

> Recomendación: con un object cache persistente (Redis/Memcached) los contadores no tocan `wp_options`.
