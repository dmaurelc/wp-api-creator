---
phase: 6
title: "Enforcement y cierre de superficie"
status: completed
priority: P1
effort: "8h"
dependencies: [3, 4, 5]
---

# Phase 6: Enforcement y cierre de superficie

## Overview

`require_api_key` deja de ser decorativo. Ademas se cierran las dos rutas que la revision encontro abiertas y que el plan original no solo ignoraba sino que eximia explicitamente: `/docs*` y `/media`.

## Requirements

**Funcionales**
- Con `require_api_key` activo, toda peticion al namespace publico sin credencial valida recibe 401, incluso en endpoints marcados `public`.
- `/docs` y `/docs/openapi.json` quedan **dentro** del enforcement.
- `/media` exige capacidad de subida, no solo sesion iniciada.
- `POST /auth/token` limita los intentos fallidos.

**No funcionales**
- El enforcement no puede romper el preflight CORS ni pisar a otros plugins.
- El rate limiting usa transients: sin tablas nuevas.

## Architecture

**Punto de enganche.** `Router::run_auth_middleware()` (`rest_pre_dispatch`), que ya intercepta el namespace y corre antes de los `permission_callback`. Correcto — verificado en la revision.

**Tres guardas que el plan original no tenia:**

```php
public function run_auth_middleware($result, $server, $request) {
    if (null !== $result) { return $result; }              // (1) no pisar a otros
    if ('OPTIONS' === $request->get_method()) { return $result; }  // (2) preflight CORS
    ...
}
```

1. El handler actual devuelve `$result` sin comprobarlo. Anadir el cortocircuito sin esta guarda descarta la respuesta de cualquier plugin que ya hubiera resuelto la peticion.
2. WordPress core engancha `rest_handle_options_request` al mismo filtro y la misma prioridad. Sin excluir `OPTIONS`, el enforcement responde 401 al preflight — y los navegadores **nunca** adjuntan cabeceras personalizadas como `X-API-Key` en un preflight. Toda integracion desde navegador dejaria de funcionar, y el desarrollador veria un error de CORS generico, no un 401 legible.
3. Core evalua `! empty($result)`, no `!== null`: devolver `false`, `[]` o `0` no cortocircuita. Hay que devolver `WP_Error` o `WP_REST_Response`.

**Exenciones.** La lista se define en la fase 3 y contiene **solo** `/auth/token`, comparado como coincidencia exacta tras retirar el prefijo de namespace.

El plan original eximia tambien `/admin/*` y `/docs`. Ambas exenciones eran erroneas por dos motivos independientes:

- Viven en el namespace hardcodeado `creator/v1` (`AdminApi.php:18`), distinto del namespace configurable del Router, asi que **nunca llegan a este filtro**: era codigo muerto.
- La justificacion ("tienen su propia proteccion por capacidades") es falsa para `/docs`: ambas rutas son `permission_callback => '__return_true'`.

Comparar por substring — el idioma que ya usa el proyecto en `AdminApi.php:395` — seria peor: los slugs de endpoint no se sanean, asi que un CPT con slug `docs` o `admin-notes` quedaria exento de por vida mientras la UI afirma que la API esta cerrada.

**Cierre de `/docs`.** Decision confirmada: cuando `require_api_key` este activo, `/docs` y `/docs/openapi.json` exigen credencial. Con la opcion desactivada siguen publicos, como hoy. Sin esto, un atacante obtiene el esquema completo — CPTs expuestos, campos, parametros y el propio `securityScheme` — de una API que el admin cree cerrada.

Nota adicional detectada en la revision: `get_swagger_ui` carga tres scripts desde `unpkg.com` sin SRI en una respuesta anonima. Fuera del alcance de esta fase; se registra en la fase 7 como deuda conocida.

**Cierre de `/media`.** `MediaController::upload_media()` solo comprueba `is_user_logged_in()` (`MediaController.php:41`) pese a recibir un `Gatekeeper` inyectado que nunca usa. Tras la fase 4, una key de un `subscriber` podria escribir archivos en `wp-content/uploads` — alguien que no puede subir nada desde wp-admin. Se anade `current_user_can('upload_files')`.

**Rate limiting.** Contador en transients con dos claves, no una:

```
wpac_fail_ip_{hash(ip)}          -> 5 fallos / 15 min
wpac_fail_user_{hash(username)}  -> 10 fallos / 60 min
```

Solo cuentan los intentos fallidos; un exito limpia ambos. La clave por IP sola es un arma de bloqueo: cinco peticiones basura dejan sin servicio a toda una oficina, un CGNAT movil o cualquier sitio tras proxy donde `REMOTE_ADDR` es el mismo para todo el trafico — mas barato que el ataque que pretende frenar. Y sin clave por cuenta, el credential stuffing distribuido pasa intacto, que es justo lo que esta seccion dice detener.

`REMOTE_ADDR` es el default duro. Confiar en `X-Forwarded-For` requiere una constante explicita con lista de proxies; sin ella, un atacante falsea la cabecera y bloquea a la victima que elija. Nunca colapsar IP desconocida en un bucket compartido.

## Related Code Files

- Modify: `src/Api/Router.php` (guardas, enforcement, cortocircuito)
- Modify: `src/Api/Controllers/AuthController.php` (rate limiting)
- Modify: `src/Api/Controllers/MediaController.php` (`upload_files`)
- Modify: `src/Admin/AdminApi.php` (`/docs*` bajo enforcement)
- Create: `src/Auth/RateLimiter.php` (~80 lineas)
- Create: `tests/Unit/Auth/RateLimiterTest.php`

## Implementation Steps

1. **`RateLimiter`** — `is_blocked(string $key)`, `register_failure(string $key)`, `clear(string $key)`. Umbral y ventana como parametros de metodo, no fijados en el constructor: hacen falta dos politicas distintas. Resolucion de IP con `REMOTE_ADDR` por defecto y confianza en proxy solo bajo constante explicita.
2. **Rate limiting en `/auth/token`** — Comprobar bloqueo por IP y por username antes de `wp_authenticate()`; registrar fallo en ambas claves si las credenciales fallan; limpiar al autenticar. Responder 429 con `Retry-After`.
3. **Guardas del handler** — Anadir el `if (null !== $result)` y la exclusion de `OPTIONS` como primeras lineas de `run_auth_middleware()`.
4. **Enforcement** — Con `require_api_key` activo, sin usuario autenticado y ruta no exenta, devolver `WP_Error('api_key_required', ..., ['status' => 401])`.
5. **Rate limiting de API keys** — Registrar fallo cuando llegue una `X-API-Key` que no valide, solo con la clave por IP.
6. **Cerrar `/docs*`** — Sustituir `__return_true` por un `permission_callback` que devuelva true si `require_api_key` esta desactivado y exija credencial valida si esta activo.
7. **Cerrar `/media`** — Anadir `current_user_can('upload_files')` en `MediaController::upload_media()` y cubrir la ruta con el enforcement.
8. **Verificar el Swagger** — `OpenApiBuilder.php:219` genera el `securityScheme`; ahora describe comportamiento real.
9. **Tests** — Bloqueo tras N fallos y desbloqueo al expirar; limpieza tras exito; con enforcement activo un endpoint `public` sin credencial da 401 y con key valida da 200; `OPTIONS` nunca recibe 401; `$result` no nulo se respeta; una key de `subscriber` no puede subir a `/media`.

## Success Criteria

- [ ] Con `require_api_key` activo, `GET /{ns}/v1/{recurso}` sin credencial devuelve 401 aunque el endpoint sea `public`.
- [ ] La misma peticion con `X-API-Key` valida devuelve 200.
- [ ] Con la opcion desactivada, el comportamiento actual no cambia.
- [ ] `OPTIONS` sobre cualquier ruta del namespace nunca devuelve 401.
- [ ] Con enforcement activo, `/docs/openapi.json` sin credencial devuelve 401.
- [ ] `/admin/*` sigue accesible con cookie de administrador y enforcement activo.
- [ ] Un cliente con Bearer caducado puede renovar en `/auth/token`.
- [ ] 6 intentos fallidos contra `/auth/token` desde una IP devuelven 429 con `Retry-After`.
- [ ] 11 intentos fallidos contra el mismo username desde IPs distintas devuelven 429.
- [ ] Una key de `subscriber` recibe 403 en `POST /media`.
- [x] Un `$result` no nulo entrante se devuelve sin modificar.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| Activar el enforcement tumba integraciones | Interruptor con confirmacion (fase 5), desactivado por defecto, changelog explicito |
| Falsos positivos del limitador tras NAT | Doble clave IP + cuenta; solo cuentan fallos; umbrales holgados |
| `X-Forwarded-For` falseado para bloquear a una victima | Confianza en proxy solo bajo constante explicita con lista de IPs |
| Transients inflan `wp_options` sin object cache | Ventanas cortas y TTL; documentar la recomendacion de object cache persistente |
| Cerrar `/docs` rompe un portal de desarrollador existente | Solo se cierra con `require_api_key` activo, que es una decision consciente del admin |

> **Estado de verificación.** Las casillas marcadas están verificadas por test unitario (`composer test`) o por comando (`grep`, `npm run build`). Las casillas sin marcar están **implementadas pero pendientes de verificación funcional** contra una instalación real de WordPress: el entorno de desarrollo no tiene una disponible y la suite es unitaria, sin `wp-env`. No representan trabajo pendiente de código.
