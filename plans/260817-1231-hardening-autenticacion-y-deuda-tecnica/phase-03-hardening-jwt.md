---
phase: 3
title: "Hardening JWT"
status: completed
priority: P1
effort: "8h"
dependencies: [1, 2]
---

# Phase 3: Hardening JWT

## Overview

La firma HS256 ya esta bien implementada (`hash_equals`, validacion de `nbf`/`exp`/formato). Esta fase cierra lo que falta alrededor: expiracion configurable de verdad, revocacion, validacion de emisor, eliminacion del secreto de fallback y errores que digan que fallo.

Cuatro correcciones sobre el plan original vienen de la revision adversarial: el tipo de retorno de `generate_token`, el fail-open de `token_version`, donde se guarda el secreto, y que la revocacion necesita superficie de producto.

## Requirements

**Funcionales**
- La expiracion la dicta `jwt_expiration`, no una constante.
- Los tokens emitidos antes de 1.1.0 dejan de validar, sin excepcion.
- Un administrador puede invalidar los tokens de un usuario desde el dashboard.
- Un token de otra instalacion se rechaza.
- El cliente recibe un codigo que distingue expirado / firma invalida / revocado / malformado / emisor ajeno.

**No funcionales**
- Sin dependencias externas; se mantiene HS256 standalone.
- El secreto de firma **no** vive dentro de `config['settings']` (seria destruido — ver fase 1).

## Architecture

**Expiracion.** `AuthController` lee `ConfigBuilder::get_global_settings()['jwt_expiration']` con default 24 y minimo 1. Nota: el control de este ajuste vive en `AuthManager.js:20-22`, pestana JWT — no en la pantalla de Settings.

**Secreto.** Cadena de resolucion: secreto inyectado (tests) -> constante `WP_API_CREATOR_JWT_SECRET` -> `SECURE_AUTH_KEY` -> secreto autogenerado. Se elimina el literal `'fallback-secret-creator-api-key-2026'`.

El autogenerado va a **su propia option** `wp_api_creator_jwt_secret` con `autoload = no`. No puede ir en `config['settings']`: el dashboard sobrescribe ese subarbol y el secreto se perderia en el siguiente Guardar, regenerandose y **invalidando todos los tokens vivos del sitio sin causa aparente**.

**Tipo de retorno.** `generate_token()` declara `: string`. Devolver `WP_Error` desde ahi es un `TypeError` fatal, no un error manejable. Se cambia la firma a `: string|WP_Error` (union type no existe en PHP 7.4 -> se retira el tipo de retorno y se documenta en el docblock) y `AuthController` comprueba `is_wp_error()` antes de construir la respuesta. Consumidor unico: `AuthController.php:52`.

**Revocacion — sin fail-open.** El payload lleva `tv`:

```php
'data' => ['user_id' => $user_id, 'tv' => $this->get_token_version($user_id)]
```

Dos reglas que el plan original no tenia:

1. `tv` es **obligatorio**: `!isset($payload['data']['tv'])` -> `jwt_revoked`. Sin esto, los tokens de 1.0.0 (sin `tv`) compararian `0 === 0` contra un meta ausente y **seguirian siendo validos**, justo lo contrario de lo que promete el changelog.
2. `_wpac_token_version` se inicializa a `1` en la activacion y al emitir el primer token. La ausencia de meta nunca debe equivaler a una version valida: una migracion de usuarios o un plugin de limpieza de `usermeta` resucitaria tokens ya revocados.

Se comprueba ademas que el usuario del token siga existiendo — hoy `validate_token` devuelve el `user_id` sin verificarlo.

**Superficie de revocacion.** El requisito "un admin puede invalidar tokens" no tiene UI en ninguna fase del plan original. Se anade `DELETE /admin/users/{id}/tokens` aqui, y el boton en la fase 5.

**Hooks de invalidacion.** `after_password_reset`, `profile_update` (solo si cambio el hash de contrasena), `set_user_role`, `wp_set_password` y `delete_user`.

**Emisor.** Se compara `iss` con `get_bloginfo('url')`.

**Errores.** `AuthMiddleware` deja de tragarse el `WP_Error`. Se adjunta con `WP_REST_Request::set_attributes()`, **no** con `set_param()`: el bag de params tambien lee query y body, asi que un cliente podria inyectar la clave.

**Exencion de rutas.** La lista de rutas que nunca deben recibir un 401 por credencial previa se define **aqui** (no en la fase 6) y la consumen ambos mecanismos. Contiene exactamente `/auth/token`: un cliente movil que renueva su token caducado envia la peticion con el Bearer viejo todavia adjunto; sin la exencion queda bloqueado en el unico endpoint que podria desbloquearlo.

## Related Code Files

- Modify: `src/Auth/JwtProvider.php` (secreto, `tv`, `iss`, existencia de usuario, tipo de retorno, `revoke_all_for_user`)
- Modify: `src/Api/Controllers/AuthController.php` (leer `jwt_expiration`, guard `is_wp_error`)
- Modify: `src/Auth/AuthMiddleware.php` (propagar el motivo via `set_attributes`)
- Modify: `src/Api/Router.php` (traducir el error adjunto, lista de rutas exentas)
- Modify: `src/Core/Plugin.php` (hooks de invalidacion, init de `token_version`)
- Modify: `src/Admin/AdminApi.php` (`DELETE /admin/users/{id}/tokens`)
- Modify: `src/frontend/components/views/AuthManager.js` (lineas 218-231 afirman "Utilizamos SECURE_AUTH_KEY"; tras esta fase la cadena de resolucion cambia y el texto miente)
- Create: option `wp_api_creator_jwt_secret` (`autoload = no`)

## Implementation Steps

1. **Expiracion configurable** — Sustituir `$expiration_hours = 24;` (`AuthController.php:49`) por la lectura de ajustes con default 24 y minimo 1.
2. **Secreto** — Reescribir `get_secret_key()` con la cadena de resolucion. Anadir `ConfigBuilder::get_or_create_jwt_secret()` que persista en su propia option con `autoload = no`. Borrar el literal hardcodeado.
3. **Tipo de retorno** — Retirar `: string` de `generate_token()`, documentar `@return string|WP_Error`, y anadir el guard `is_wp_error($token)` en `AuthController` antes de la respuesta.
4. **`token_version`** — Anadir `tv` obligatorio al payload y su comprobacion estricta en `validate_token()`. Inicializar el meta a `1` en la activacion del plugin y en la primera emision.
5. **Existencia del usuario** — En `validate_token()`, comprobar `get_userdata($user_id)` antes de devolverlo.
6. **Hooks de invalidacion** — Registrar los cinco hooks en `Plugin::init()`. En `profile_update`, comparar el hash de contrasena antiguo y nuevo para no cortar sesiones en cada guardado de perfil.
7. **Endpoint de revocacion** — `DELETE /admin/users/{id}/tokens` protegido por `check_admin_permissions`, incrementa el meta.
8. **Validacion de `iss`** — Comparar contra `get_bloginfo('url')`; si no coincide -> `jwt_invalid_issuer`.
9. **Propagacion de errores** — Adjuntar el `WP_Error` con `set_attributes()` y traducirlo en `Router::run_auth_middleware()`, respetando la lista de rutas exentas.
10. **Corregir el texto del dashboard** — Actualizar `AuthManager.js:218-231` para reflejar la cadena real de resolucion del secreto.
11. **Tests** — `tv` ausente -> `jwt_revoked`; `tv` desincronizado -> `jwt_revoked`; `iss` ajeno -> `jwt_invalid_issuer`; usuario borrado -> error; expiracion respeta el ajuste; sin ningun secreto -> `WP_Error`, nunca firma.

## Success Criteria

- [x] Cambiar `jwt_expiration` a 2 produce tokens con `exp` a 2 horas.
- [x] Un token de 1.0.0 (sin `tv`) es rechazado con `jwt_revoked`.
- [x] Guardar ajustes en el dashboard **no** cambia el secreto de firma ni invalida tokens.
- [ ] El boton de revocacion invalida los tokens vivos de ese usuario.
- [ ] Cambiar la contrasena invalida los tokens; guardar el perfil sin cambiarla, no.
- [x] Un token con `iss` de otro dominio devuelve `jwt_invalid_issuer`.
- [x] `grep -rn "fallback-secret" src/` no devuelve resultados.
- [ ] Un cliente con Bearer caducado puede renovar en `POST /auth/token` sin quitar la cabecera.
- [x] Sin ningun secreto disponible, `/auth/token` devuelve un error controlado, no un fatal.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| Todos los tokens vivos se invalidan al desplegar | Intencional y ahora real (antes el plan lo prometia sin lograrlo). Documentar en el changelog. |
| Validar `iss` rompe sitios que cambiaron de dominio | 401 explicito con `jwt_invalid_issuer`; basta reautenticar |
| La option del secreto se borra por un plugin de limpieza | `autoload = no` no la hace fragil; ademas se regenera y el efecto es equivalente a una revocacion masiva, no a un fallo abierto |
| `profile_update` invalida sesiones sin necesidad | Guard de comparacion de hash de contrasena |

> **Estado de verificación.** Las casillas marcadas están verificadas por test unitario (`composer test`) o por comando (`grep`, `npm run build`). Las casillas sin marcar están **implementadas pero pendientes de verificación funcional** contra una instalación real de WordPress: el entorno de desarrollo no tiene una disponible y la suite es unitaria, sin `wp-env`. No representan trabajo pendiente de código.
