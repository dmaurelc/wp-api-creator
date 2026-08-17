---
phase: 4
title: "Rediseno de API Keys (backend)"
status: completed
priority: P1
effort: "8h"
dependencies: [1, 2]
---

# Phase 4: Rediseno de API Keys (backend)

## Overview

Cada key pasa a estar ligada a un usuario de WordPress real y se guarda hasheada. Desaparecen la master key hardcodeada y el mapeo automatico al primer administrador.

**Premisa corregida.** El plan original afirmaba que "cualquier key concede privilegios de administrador". La revision demostro que las keys del dashboard nunca han autenticado (ver fase 1): el unico camino que funcionaba era la master key de `WP_DEBUG`. El privilegio excesivo existe en el codigo pero es inalcanzable; el fallo real es que la funcionalidad esta rota. Esta fase la construye bien de una vez.

Depende de la fase 1: sin la ubicacion canonica unificada, todo lo que se escriba aqui vuelve a caer en el vacio.

## Requirements

**Funcionales**
- Cada key se crea asociada a un `user_id` y autentica con el rol de ese usuario.
- La key en claro se muestra una sola vez, al crearla.
- Una key puede caducar; una key caducada no autentica.
- Se registra el ultimo uso para auditar keys muertas.
- La master key de desarrollo desaparece.

**No funcionales**
- Comparacion de hashes en tiempo constante.
- **Ninguna escritura de configuracion desde el path de request** (regla de la fase 1).

## Architecture

**Modelo** en `$config['api_keys']` (raiz, canonica desde la fase 1):

```php
[
  'id'         => uniqid(),
  'name'       => 'Integracion CRM',
  'hash'       => hash('sha256', $plain_key),   // sustituye a 'key'
  'prefix'     => 'ak_7f3a',                     // para identificarla en la UI
  'user_id'    => 42,
  'expires_at' => null,                          // timestamp o null
  'created_at' => '2026-08-17 12:31:00',
  'legacy'     => false,
]
```

**`last_used_at` va fuera.** Decision confirmada tras la revision: se guarda en su propia option `wp_api_creator_key_usage` (mapa `id => timestamp`, `autoload = no`). Escribirlo dentro del blob de configuracion obligaria a un read-modify-write del documento entero — endpoints, permisos y ajustes incluidos — desde el path de lectura. Con la cache de 5 minutos de `ConfigBuilder`, una peticion s2s podria reescribir configuracion obsoleta y **revertir en silencio cambios que un admin acaba de guardar**.

**Validacion** en `ApiKeyProvider::validate_key()`:

1. Hashear la key entrante con SHA-256.
2. Comparar con `hash_equals` contra los hashes almacenados.
3. Si hay match: comprobar `expires_at`, que `user_id` exista, y que no sea `legacy`.
4. Registrar el uso en la option dedicada, con guarda temporal de 5 minutos.
5. Devolver el `user_id` real.

**Por que SHA-256 y no `password_hash`.** Una API key es un secreto de 128 bits generado por nosotros, no una contrasena humana. No necesita el coste de bcrypt, que ademas obligaria a un `password_verify` contra cada key almacenada en el path de cada peticion.

**Migracion de keys existentes.** Quedan marcadas `legacy => true` y dejan de autenticar. No se rehidratan: no se sabe a que usuario debian pertenecer y asignarlas al admin perpetuaria el fallo que esta fase corrige. Dado que en la practica nunca autenticaron, el impacto real es menor que el estimado inicialmente.

**Sin validacion de jerarquia de roles.** El plan original pedia impedir que "un editor emita una key de administrador". Es imposible: las tres rutas de keys estan tras `check_admin_permissions`, que es `current_user_can('manage_options')`. Quien llega ya es administrador. Se retira ese paso — implementar comparacion de capacidades entre roles de WP no es trivial y aqui no protege de nada.

## Related Code Files

- Modify: `src/Auth/ApiKeyProvider.php` (reescritura completa)
- Modify: `src/Admin/AdminApi.php` (`create_api_key`, `get_api_keys`)
- Modify: `src/Domain/ConfigBuilder.php` (marcado `legacy` en la migracion de la fase 1)
- Create: option `wp_api_creator_key_usage` (`autoload = no`)
- Create: `tests/Unit/Auth/ApiKeyProviderTest.php`

## Implementation Steps

1. **Eliminar la master key** — Borrar el bloque `ApiKeyProvider.php:24-27`. Para desarrollo se crea una key normal desde el dashboard.
2. **Eliminar `get_fallback_admin_user()`** — Es la raiz del privilegio excesivo. Ninguna ruta debe resolver un usuario por defecto.
3. **Reescribir `validate_key()`** segun el flujo anterior. Firma `validate_key(string $api_key): ?int` sin cambios, para no tocar `AuthMiddleware` (consumidor unico: `AuthMiddleware.php:42`).
4. **Registro de uso** — Helper que lee y escribe `wp_api_creator_key_usage`, con guarda de 5 minutos. Nunca toca `save_config()`.
5. **`create_api_key()`** — Aceptar `user_id` obligatorio (validado con `get_userdata()`, 400 si no existe) y `expires_at` opcional. Guardar `hash` + `prefix`. Devolver la key en claro solo en esta respuesta.
6. **`get_api_keys()`** — Dejar de devolver el secreto. Exponer `id`, `name`, `prefix`, `user_id`, nombre del usuario, `expires_at`, `last_used_at` (desde la option dedicada), `created_at`, `legacy`. Hoy `AdminApi.php:331` devuelve el array crudo con la key en claro.
7. **Marcar legacy** — Las entradas que aun tengan la clave `key` se marcan `legacy` y se les borra el campo en claro, en la migracion consolidada de la fase 1.
8. **Tests** — Key valida devuelve su `user_id`, no el del admin; key inexistente, caducada, `legacy` o de usuario borrado devuelven `null`; la master key vieja no funciona ni con `WP_DEBUG` activo; el registro de uso no invoca `save_config()`.

## Success Criteria

- [x] `grep -rn "wp_api_creator_development_master_key" src/` sin resultados.
- [x] `grep -rn "get_fallback_admin_user" src/` sin resultados.
- [x] Una key de un usuario `subscriber` recibe 403 en un endpoint restringido a `editor`.
- [x] `GET /admin/api-keys` no devuelve ningun secreto ni hash completo.
- [x] Una key con `expires_at` pasado no autentica.
- [x] Las keys del esquema viejo quedan `legacy` y no autentican.
- [x] El registro de uso no escribe en `wp_api_creator_config` (verificable en test).
- [x] Una key creada extremo a extremo (crear -> usar) autentica correctamente.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| Integraciones s2s existentes dejan de funcionar | En la practica ninguna funcionaba (fase 1). Aun asi, aviso en el dashboard y changelog. |
| Perdida de la key al no copiarla | La UI (fase 5) obliga a confirmar antes de cerrar el modal |
| Iterar sobre las keys por hash no escala | Volumen realista de decenas; `hash_equals` sobre un array pequeno es irrelevante frente al coste de la peticion |
| El test extremo a extremo mockea ambos lados y no detecta un desajuste de ubicacion | El test debe pasar por `AdminApi::create_api_key()` y luego por `ApiKeyProvider::validate_key()`, sin mockear la capa de config entre medias |
