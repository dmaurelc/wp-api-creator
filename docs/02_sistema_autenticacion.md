# 2. Sistema de Autenticación Configurable

Convertir WordPress en una API externalizada requiere sistemas de autenticación agnósticos de estado (Stateless Auth) modernos. La API nativa usa cookies, lo cual es inútil para aplicaciones SPA desacopladas, móviles o servicios de servidor a servidor.

El plugin proveerá un sistema multi-capa y delegable (Provider-based).

## 1. Patrón Provider (Factory)

El flujo de validación se maneja mediante un `AuthenticatorService` que iterará sobre el proveedor de autenticación que haya sido seleccionado en el Dashboard administrativo.

Proveedores soportados inicialmente:

1. **JSON Web Tokens (JWT)**: Para Single Page Apps (React/Vue/Angular) y Mobile Apps.
2. **API Keys (Key-based)**: Para comunicaciones Service-to-Service (ej. un cronjob externo, Netlify functions).
3. **Application Passwords (WP Core >= 5.6)**: Integración con credenciales nativas.
4. **OAuth2** (Modular, preparado para plugins de expansión futuros).

## 2. Flujo JWT (Autenticación Basada en Tokens)

El módulo JWT es el pilar para aplicaciones de usuario cliente:

### Endpoint de Emisión de Token: `POST /wp-custom-api/v1/auth/token`

- **Body**: `{ "username": "xxx", "password": "yyy" }`
- **Acción**: Valida con `wp_authenticate()`.
- **Emisión**: Crea un token JWT firmado mediante algoritmos asimétricos o simétricos (`HS256` como estándar empleando un secret único generado post-instalación).
- **Payload Base**:
  ```json
  {
    "iss": "https://midominio.com",
    "iat": 1670000000,
    "exp": 1670003600,
    "data": {
      "user_id": 12,
      "roles": ["customer"]
    }
  }
  ```

### Expiración y Refresh Tokens

Para la seguridad, el Token principal tiene vida corta (ej. 15 minutos configurables). Se implementa un **Refresh Token** persistente (guardado fuertemente hasheado en la tabla `wp_usermeta`).

- **Endpoint**: `POST /wp-custom-api/v1/auth/refresh`
- Permite extender sesiones sin forzar un relogin. Si el Refresh Token es revocado desde el administrador, se invalida toda la cadena.

## 3. Flujo por API Keys (Identificación de Servicios)

Para endpoints que necesitan consumir datos sin asociarse a un "usuario" biológico (ej. un sitio web SSG estático solicitando datos públicos en el build time):

1. **Dashboard UI**: Pestaña "API Keys".
2. **Generación**: Se crea un binomio `Key ID` / `Secret`. El Secret es mostrado **solo una vez** (almacenado con `password_hash()` en DB por seguridad).
3. **Roles Asociados**: La API Key no asume el puesto del administrador maestro. Cada Key está mapeada lógicamente a un "Rol Ficticio" o Perfil de Autorización (Ej: Rol "Lector API").
4. **Pasaje**:
   - Cabecera: `x-api-key: d81a-4d2c-91bb-xxxx`
   - Opcionalmente por Bearer: `Authorization: Bearer <API-KEY>`

## 4. Revocación Universal y Seguridad

- **Botón de Pánico ("Revoke All Tokens")**: Se implementará modificando la semilla criptográfica o incrementando un valor tipo `auth_timestamp` en los usermeta. El JWT valida el payload contra esta base; si la base rota, todos los JWT emitidos previos caducan inmediatamente.
- **Revocación Aislada**: Destruir sesiones forzadamente de un usuario particular directamente desde el listado clásico de Usuarios en WP.

## 5. Implementación del Middleware de Autenticación

En el clúster de intercepción (fase Middleware), el pipeline se ve así:

```text
[HTTP Request] -> Middleware Pipeline

 1. ¿Ruta Pública? -> Dejar pasar.
 2. Parse Auth Headers (`Authorization: Bearer XXX`).
 3. Dependiendo del tipo de string, se invoca al Abstract Provider.
 4. Si es API Key -> Validar hash BD -> Establecer WP_User en memoria como el autor asociado a la key (si aplica) o rol en memoria.
 5. Si es JWT -> Verificar firma criptográfica -> Verificar vigencia (`exp`) -> Establecer User ID.
 6. Mapear identidad validada globalmente mediante `wp_set_current_user()` temporal para que las funciones dependientes de Core (`current_user_can()`) sigan respondiendo nativamente a lo largo de la ejecución.
```

El respeto por la abstracción permite delegar las auditorías al WP nativo sin acoplar funciones que dependan explícitamente de métodos propietarios.
