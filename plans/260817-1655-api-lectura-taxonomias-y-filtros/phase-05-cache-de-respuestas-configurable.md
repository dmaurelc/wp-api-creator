---
phase: 5
title: "Cache de respuestas configurable"
status: pending
priority: P2
effort: "6h"
dependencies: [2, 3, 4]
---

# Phase 5: Cache de respuestas configurable

## Overview

Hacer efectivo `cache_time`, el ultimo ajuste decorativo del plugin. **Recortada tras la revision adversarial**, que encontro tres criticos independientes en el diseño original.

## Alcance recortado, y por que

| Recorte | Critico que elimina |
|---|---|
| **Solo la ruta de coleccion** | La ruta de elemento unico no declara `args`: el `id` viene del regex, no de la lista de parametros. Una clave derivada de esa lista habria hecho que `/snippet/42` y `/snippet/99` compartieran entrada. |
| **Solo resultados publicados** | El control de acceso de la ruta unica y de `?status=draft` es por post (`current_user_can('edit_post', $id)`) y por autor (`author__in`). Ninguna clave basada en roles puede representarlo. Sin caso personal, el problema desaparece. |
| **Sin `search` en cache** | `search` es texto libre: un anonimo genera claves infinitas, desaloja las entradas legitimas y convierte la cache en un amplificador de DoS. |

Lo que queda es el caso que de verdad se repite en un build de Astro: listados publicos con filtros acotados.

## Requirements

**Funcionales**
- Con `cache_time > 0`, dos peticiones identicas de coleccion publicada no repiten el trabajo.
- Con `cache_time = 0`, comportamiento actual sin cambios.
- Existe un boton de purga en el dashboard.
- La actualizacion a 1.2.0 deja `cache_time` en 0 aunque hubiera un valor guardado.

**No funcionales**
- **Ninguna respuesta cacheada puede servirse a quien no tenia derecho a verla.**
- El espacio de claves esta acotado.

## Architecture

### Una sola funcion lee los parametros y construye la clave

El plan original proponia derivar la clave iterando `get_collection_args()` y protegerla con un test que "falla si se añade un argumento sin incluirlo en la clave". Ese test es **tautologico**: la clave se construye iterando esa misma lista, asi que no puede fallar. Verificaba la unica direccion que es imposible romper.

La direccion que si se rompe es la historica: un parametro que **afecta al resultado** y que **no** esta declarado. Es literalmente lo que hoy hace `CollectionController`, leyendo `get_param()` de una lista propia.

**Solucion estructural, no un test:** una sola funcion recoge los parametros, y esa misma estructura es la que se hashea.

```php
// CollectionArgs
public static function collect(WP_REST_Request $request, array $config): array
{
    $values = [];
    foreach (self::for_endpoint($config) as $name => $spec) {
        $value = $request->get_param($name);
        if ($value !== null && $value !== '') $values[$name] = $value;
    }
    ksort($values);
    return $values;   // ya saneado por WordPress via sanitize_callback
}
```

El controlador deja de llamar a `get_param()` directamente y `ResponseCache` hashea el retorno de `collect()`. **No pueden divergir porque son el mismo array.** Un parametro nuevo entra en ambos sitios a la vez o en ninguno.

Test de convencion: ninguna llamada a `$request->get_param()` fuera de `CollectionArgs` en `src/Api/Controllers/CollectionController.php`.

### La clave

| Componente | Por que |
|---|---|
| `blog_id` | Multisitio |
| slug del endpoint | Aislar endpoints |
| `hash('xxh128', serialize(CollectionArgs::collect(...)))` | Valores ya saneados y ordenados; el hash acota la longitud y colapsa variantes de codificacion |
| roles del usuario, ordenados | El Gatekeeper filtra metas por rol (`Gatekeeper.php:159-166`), incluso sobre contenido publicado |
| `wp_cache_get_last_changed('posts')` y `('terms')` | Invalidacion automatica |
| version de config | Invalidacion al guardar |

**No hace falta `user_id`**: con `status = publish` forzado, no hay `author__in` ni capacidades por post en juego. Los roles cubren lo unico que varia.

### Condiciones para cachear

```php
$params = CollectionArgs::collect($request, $config);

$cacheable = $ttl > 0
    && ($params['status'] ?? 'publish') === 'publish'
    && !isset($params['search']);
```

### Invalidacion

`wp_cache_get_last_changed('posts')` y `('terms')` forman parte de la clave, y WordPress ya los incrementa por su cuenta: `clean_post_cache()` en guardado, papelera y borrado; `clean_term_cache()` en `wp_delete_term()` y `wp_update_term()`. Eso cubre de golpe los tres huecos que la revision encontro en la lista de hooks manual: borrado de termino, renombrado de termino y publicacion programada.

**Lo que `last_changed` de posts no cubre son las metas.** `update_metadata()` borra la cache de `post_meta` pero no llama a `clean_post_cache()`, asi que un `PATCH` que solo cambia metas —camino real de `MutationController.php:177`, que solo invoca `wp_update_post()` si llegaron `title` o `content`— no invalidaria nada. Se añaden `updated_post_meta`, `added_post_meta` y `deleted_post_meta` incrementando una version propia.

La version de config se incrementa dentro de **`ConfigBuilder::save_config()`**, no en `AdminApi`: es el unico punto por el que pasan los cuatro caminos de guardado del dashboard **y** `ConfigMigrator`. Engancharlo en `AdminApi` habria dejado la migracion sin invalidar.

**Regla dura de 1.1.0:** nada de esto llama a `ConfigBuilder::save_config()` desde el path de request. Las versiones viven en options propias con `autoload = no`.

### Activacion segura

`cache_time` lleva versiones guardandose y el dashboard **recomienda 300**. Sin migracion, quien siguio la recomendacion pasaria de "ajuste sin efecto" a "cache activa en produccion" en el primer request tras actualizar, sin haberlo pedido.

`ConfigMigrator` fija `cache_time = 0` al migrar a 1.2.0, con nota en el changelog. Mas un boton **"Purgar caché de respuestas"** en Ajustes: sin el, el unico remedio ante un fallo seria guardar los ajustes, y desactivar el plugin no vacia Redis.

### Almacenamiento

`wp_cache_set()` con TTL. Sin object cache persistente no cachea entre peticiones: el dashboard lo detecta con `wp_using_ext_object_cache()` y lo advierte. **No hay respaldo en transients**: con filtros por taxonomia la combinatoria inflaria `wp_options`.

## Related Code Files

- Create: `src/Domain/ResponseCache.php`
- Modify: `src/Api/CollectionArgs.php` (`collect()`)
- Modify: `src/Api/Controllers/CollectionController.php` (consultar/poblar; dejar de llamar a `get_param()`)
- Modify: `src/Domain/ConfigBuilder.php` (version en `save_config()`)
- Modify: `src/Domain/ConfigMigrator.php` (`cache_time = 0`)
- Modify: `src/Core/Plugin.php` (hooks de meta)
- Modify: `src/Admin/AdminApi.php` (endpoint de purga)
- Modify: `src/frontend/components/views/Settings.js` (boton de purga, aviso de object cache)
- Create: `tests/Unit/Domain/ResponseCacheTest.php`

## Implementation Steps

1. **`CollectionArgs::collect()`** y migrar el controlador a usarla. Test de convencion sobre `get_param()`.
2. **`ResponseCache`** — clave, lectura, escritura, condiciones de cacheabilidad.
3. **Tests de aislamiento, antes de integrar** — dos roles distintos no comparten entrada; `?status=draft` no se cachea; `?search=x` no se cachea; dos endpoints no comparten entrada; parametros equivalentes con distinta codificacion comparten entrada (prueba de la normalizacion).
4. **Integracion en `CollectionController`**.
5. **Invalidacion** — `last_changed` en la clave, hooks de meta, version en `save_config()`. Test: `save_config()` desde cualquier ruta cambia la clave.
6. **Migracion y purga** — `cache_time = 0` en `ConfigMigrator`; endpoint y boton de purga; aviso de object cache.
7. **Medicion** — consultas y tiempo con `cache_time` a 0 y a 300, con object cache activo.

## Success Criteria

- [ ] Con `cache_time = 300`, la segunda peticion identica de coleccion publicada no repite consultas.
- [ ] Con `cache_time = 0`, comportamiento identico al actual.
- [ ] La ruta de elemento unico nunca se cachea.
- [ ] `?status=draft` nunca se cachea.
- [ ] `?search=x` nunca se cachea.
- [ ] Dos roles distintos no comparten entrada.
- [ ] Editar solo metas de un post invalida las colecciones que lo contienen.
- [ ] Borrar o renombrar un termino invalida las colecciones que lo filtran.
- [ ] `ConfigMigrator` deja `cache_time = 0` al actualizar desde 1.1.0.
- [ ] El boton de purga vacia la cache.
- [ ] El dashboard avisa si no hay object cache persistente.
- [ ] `CollectionController` no llama a `$request->get_param()` directamente.
- [ ] `grep -rn "save_config" src/Api/ src/Auth/ src/Domain/ResponseCache.php` sin resultados.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| Fuga de datos entre usuarios | Alcance recortado a coleccion publicada + roles en la clave + tests de aislamiento antes de integrar |
| La clave omite un parametro | `collect()` es la unica lectura y la unica fuente de la clave: no pueden divergir |
| Inundacion de claves por un anonimo | Valores saneados, hash acotado, `search` excluido |
| Contenido obsoleto | `last_changed` de posts y terminos + hooks de meta |
| Activacion no deseada al actualizar | `cache_time = 0` en la migracion + boton de purga |
| La cache enmascara regresiones en desarrollo | 0 por defecto; el sitio de pruebas se deja sin cache salvo al medir |
