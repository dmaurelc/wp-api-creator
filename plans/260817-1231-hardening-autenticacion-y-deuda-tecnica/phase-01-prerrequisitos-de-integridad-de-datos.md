---
phase: 1
title: "Prerrequisitos de integridad de datos"
status: completed
priority: P1
effort: "8h"
dependencies: []
---

# Phase 1: Prerrequisitos de integridad de datos

## Overview

Fase creada tras la revision adversarial. El plan original construia el rediseno de API keys y el enforcement sobre cuatro defectos de la capa de persistencia que nadie habia detectado. Sin esta fase, las fases 4 y 6 fallan de forma silenciosa.

Los cuatro defectos son independientes entre si y todos viven en el mismo punto: como el plugin escribe y lee su option de configuracion.

## Requirements

**Funcionales**
- Existe una unica ubicacion canonica para las API keys, y escritor y lector apuntan a ella.
- Guardar ajustes desde el dashboard no destruye campos que el dashboard no conoce.
- `api_namespace` no puede colisionar con namespaces REST ya registrados.
- Borrar una API key no corrompe la forma JSON del listado.
- Existe una copia de seguridad del option antes de cualquier migracion destructiva.

**No funcionales**
- Ninguna ruta del path de request puede llamar a `ConfigBuilder::save_config()`. Regla dura para todo el plan.
- La migracion debe poder re-ejecutarse sin efectos adicionales.

## Architecture

### 1. Ubicacion canonica de `api_keys`

Hoy hay dos arrays disjuntos:

```
AdminApi::create_api_key()  ->  $config['api_keys']              (raiz)
ApiKeyProvider::validate_key() -> $config['settings']['api_keys'] (dentro de settings)
```

Nadie los puentea. **Consecuencia real: ninguna key creada desde el dashboard ha autenticado nunca.** Lo unico que funcionaba era la master key hardcodeada de `WP_DEBUG`. Esto corrige la premisa del plan original, que describia un problema de privilegio excesivo alcanzable.

**Canonica: `$config['api_keys']` (raiz).** Razon: `settings` es el subarbol que el dashboard sobrescribe entero (defecto 2), asi que las keys no pueden vivir ahi. Se retira `api_keys` del retorno de `get_global_settings()` y se expone un accesor propio `ConfigBuilder::get_api_keys()`.

La migracion lee **ambas** ubicaciones y consolida. La idempotencia se basa en "no queda ninguna entrada con la clave `key` en ninguna de las dos ubicaciones", no en un contador de version — un `schema_version` guardado dentro de `settings` seria borrado por el defecto 2.

### 2. `save_settings` destruye campos del servidor

`save_settings` hace `$config['settings'] = $data['settings']` sin merge, y `get_global_settings()` devuelve una whitelist fija de 6 claves. El dashboard hace round-trip de esas 6 claves. Cualquier campo que el servidor guarde en `settings` es invisible al leer y se borra al guardar.

Agravante: `Settings.js` inicializa su estado con 2 claves y solo lo sobrescribe si el GET tiene exito. Si el GET falla y el admin pulsa Guardar, el POST lleva 2 claves y borra `api_namespace`, `jwt_expiration` y `filter_wp_endpoints` de un golpe.

Fix: `save_settings` fusiona sobre lo existente y filtra por una whitelist explicita de claves escribibles por el cliente. Los secretos del servidor van a options propias, nunca dentro de `settings`.

### 3. `api_namespace` sin validar

`AdminApi` registra sus rutas en el namespace hardcodeado `creator/v1`. El namespace del Router es configurable y la UI **sugiere literalmente `creator/v1`** como valor del campo. Si un admin lo acepta, `run_auth_middleware` pasa a interceptar tambien las rutas de administracion: `rest_pre_dispatch` corre antes del `permission_callback`, asi que `wp_set_current_user()` se ejecuta desde una cabecera `X-API-Key` y `check_admin_permissions()` evalua las capacidades del usuario suplantado. Escalada a `/admin/*` completa.

Variante igual de grave: fijar el namespace a `wp/v2` hace que el middleware y el enforcement se apliquen a las rutas REST de WordPress core.

Fix: sanear en `save_settings` con lista negra de namespaces reservados (`creator/v1`, `wp/v2`, `wp-site-health/v1`, `oembed/1.0`) y validacion de formato. Ademas, la guarda del middleware compara el segmento de namespace exacto, no por prefijo `strpos`.

### 4. `delete_api_key` corrompe el array

`array_filter` conserva los indices. Borrar la primera key deja `[1 => {...}]`, que `json_encode` serializa como objeto. El front hace `apiKeys.length` y `apiKeys.map()` -> `map is not a function` -> pestana Auth en blanco. Es exactamente el flujo que la fase 5 le pide al usuario tras actualizar.

Fix: `array_values()` en `delete_api_key` y normalizacion de la salida de `get_api_keys`.

### 5. Backup previo

Antes de cualquier migracion, copiar el option integro a `wp_api_creator_config_backup_1_0_0` con `autoload = no`. Es la unica red para el rollback (ver fase 7).

## Related Code Files

- Modify: `src/Domain/ConfigBuilder.php` (accesor `get_api_keys()`, retirar `api_keys` del whitelist, migracion, backup)
- Modify: `src/Admin/AdminApi.php` (`save_settings` merge + whitelist + validacion de namespace; `delete_api_key` con `array_values`; `get_api_keys` normalizado)
- Modify: `src/Auth/ApiKeyProvider.php` (leer de la ubicacion canonica)
- Modify: `src/Api/Router.php` (guarda de namespace por segmento exacto)
- Modify: `src/frontend/components/views/Settings.js` (no permitir Guardar si la carga inicial fallo)

## Implementation Steps

1. **Backup** — En la rutina de actualizacion, si existe `wp_api_creator_config` y no existe el backup, copiarlo a `wp_api_creator_config_backup_1_0_0` con `autoload = no`.
2. **Consolidar `api_keys`** — Migracion que lee `$config['api_keys']` y `$config['settings']['api_keys']`, fusiona por `id`, deja el resultado en la raiz y elimina el subarray de `settings`. Re-ejecutable.
3. **Accesor canonico** — Anadir `ConfigBuilder::get_api_keys(): array` y retirar `api_keys` del retorno de `get_global_settings()`. Actualizar `ApiKeyProvider` para usar el accesor.
4. **`save_settings` seguro** — Fusionar con los ajustes existentes y aceptar solo la whitelist de claves escribibles (`api_namespace`, `cache_time`, `require_api_key`, `jwt_expiration`, `filter_wp_endpoints`). Ignorar todo lo demas que llegue del cliente.
5. **Validar `api_namespace`** — Rechazar con 400 si esta vacio, si no cumple el formato `slug/vN`, o si colisiona con la lista de namespaces reservados. Mensaje de error explicito en la UI.
6. **Guarda del middleware por segmento** — En `Router::run_auth_middleware()`, comparar el primer segmento de la ruta con el namespace en lugar de `strpos(...) === 0`.
7. **`delete_api_key`** — Anadir `array_values()`. Normalizar tambien la salida de `get_api_keys` para garantizar array JSON siempre.
8. **Blindar `Settings.js`** — Marcar el estado como "no cargado" hasta que el GET tenga exito y deshabilitar Guardar mientras tanto.
9. **Regla del proyecto** — Documentar en `docs/01_arquitectura_tecnica.md`: `ConfigBuilder::save_config()` es exclusivo del contexto de administracion. Ninguna ruta de request puede invocarlo.

## Success Criteria

- [x] Una key creada desde el dashboard autentica correctamente via `X-API-Key` (hoy no lo hace).
- [x] Guardar ajustes con el dashboard no borra ningun campo ausente en el payload.
- [x] Un POST de ajustes con claves desconocidas las ignora en lugar de persistirlas.
- [x] Fijar `api_namespace` a `creator/v1` o `wp/v2` se rechaza con 400.
- [x] Borrar la primera de tres keys deja un array JSON, no un objeto, y el dashboard sigue renderizando.
- [x] Existe `wp_api_creator_config_backup_1_0_0` tras la actualizacion, con `autoload = no`.
- [x] La migracion ejecutada dos veces produce el mismo resultado.
- [x] `grep -rn "save_config" src/` no devuelve ninguna llamada desde `src/Api/` ni `src/Auth/`.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| La consolidacion de keys pierde entradas si un sitio tiene datos en ambas ubicaciones | Fusion por `id` conservando ambas; el backup permite recuperar el estado previo |
| Cambiar la ubicacion de `api_keys` rompe codigo que aun lea el sitio viejo | Solo hay 2 lectores (`AdminApi`, `ApiKeyProvider`); ambos se actualizan en esta fase |
| Validar `api_namespace` bloquea a un sitio que ya usa un valor ahora prohibido | La validacion actua solo al guardar; un sitio con `creator/v1` ya guardado sigue funcionando pero el middleware por segmento exacto evita la escalada. Anadir aviso en el dashboard. |
| El blindaje de `Settings.js` deja el formulario inutilizable si el endpoint falla siempre | Mostrar el error real y un boton de reintento, no un formulario mudo |
