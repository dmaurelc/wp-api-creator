---
phase: 2
title: "Filtrado por taxonomia"
status: pending
priority: P1
effort: "7h"
dependencies: [1]
---

# Phase 2: Filtrado por taxonomia

## Overview

Permitir `?ubicacion=providencia,las-condes&estado=en-venta`. Incluye extraer la lista canonica de parametros a una clase propia, porque tres consumidores distintos la necesitan y hoy vive en un metodo `protected`.

## Requirements

**Funcionales**
- Cada taxonomia expuesta acepta un parametro de query con su nombre.
- Varios terminos dentro de una taxonomia: OR. Varias taxonomias: AND.
- Un termino inexistente devuelve 0 resultados.
- Una taxonomia no expuesta no es filtrable.
- Una taxonomia cuyo nombre coincida con un parametro reservado no lo pisa.

**No funcionales**
- **Resolver las taxonomias expuestas no puede costar consultas.** Se ejecuta en `rest_api_init`, que corre en toda peticion REST del sitio.
- La lista de parametros tiene una sola fuente de verdad, accesible a Router, cache y generador de esquema.

## Architecture

### `CollectionArgs`: la fuente canonica

`Router::get_collection_args()` es `protected` y de instancia (`Router.php:459`), con un solo llamador. Pero la fase 5 la necesita desde `ResponseCache` y la fase 6 desde `OpenApiBuilder`, que es **completamente estatico**. Instanciar un `Router` desde ahi arrastraria `Gatekeeper`, `AuthMiddleware`, `JwtProvider`, `ApiKeyProvider` y una lectura de options solo para leer una lista.

Si no se resuelve aqui, el atajo bajo presion sera copiar la lista — que es exactamente la duplicacion que causo los cinco parametros huerfanos.

```php
// src/Api/CollectionArgs.php
final class CollectionArgs
{
    public static function for_endpoint(array $config): array { /* ... */ }
    public static function exposed_taxonomies(array $config): array { /* ... */ }
}
```

`Router::get_collection_args()` pasa a delegar. Consumidores: `Router` (fase 2), `ResponseCache` (fase 5), `OpenApiBuilder` (fase 6).

### Resolver taxonomias sin consultas

`exposed_taxonomies()` **no** usa `FieldScanner`. `FieldScanner` cae en `MetaScanner`, que hace `SELECT DISTINCT meta_key FROM wp_postmeta` y escanea grupos ACF, con cache de 5 minutos que sin object cache persistente no sobrevive entre peticiones. Ejecutar eso en `rest_api_init` significa que **cada guardado del editor de bloques** paga el escaneo, una vez por endpoint activo.

`get_object_taxonomies()` es lectura del registro en memoria, coste cero:

```php
public static function exposed_taxonomies(array $config): array
{
    $registered = get_object_taxonomies($config['post_type'] ?? '', 'names');

    $exposed = [];
    foreach (($config['exposed_fields'] ?? []) as $key) {
        if (strpos($key, 'tax:') !== 0) continue;
        $name = substr($key, 4);
        if (in_array($name, $registered, true)) $exposed[] = $name;
    }

    return $exposed;
}
```

Contrastar contra `$registered` cierra ademas la ventana que dejaba la cache de 5 minutos de `FieldScanner`: una taxonomia que pase a privada deja de filtrarse de inmediato.

### Declaracion de los argumentos

```php
foreach (self::exposed_taxonomies($config) as $taxonomy) {
    if (isset($args[$taxonomy])) continue;   // guarda estructural
    $args[$taxonomy] = [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'description'       => sprintf('Filtra por terminos de %s. Varios separados por coma (OR).', $taxonomy),
    ];
}
```

**El `continue` es una guarda estructural, no una lista literal de reservados.** Una lista escrita a mano (`page`, `limit`, `status`…) habria que sincronizarla con cada parametro que añadan las fases 3 y 4 — el mismo error de dos fuentes de verdad que motiva todo el plan. Sin ella, una taxonomia llamada `status` borraria el `enum` de `Router.php:471-476` y con el la validacion que devuelve `400 rest_not_in_enum`.

El `sanitize_callback` importa para la fase 5: la clave de cache se construye con valores ya saneados.

### `tax_query`

```php
$tax_query = [];
foreach ($args['taxonomies'] ?? [] as $taxonomy => $raw) {
    $slugs = array_values(array_filter(array_map('sanitize_title', explode(',', (string) $raw))));
    if (!$slugs) continue;
    $tax_query[] = ['taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => $slugs, 'operator' => 'IN'];
}
if (count($tax_query) > 1) $tax_query['relation'] = 'AND';
if ($tax_query) $query_args['tax_query'] = $tax_query;
```

`sanitize_title` sobre cada termino impide inyectar estructura. Un termino inexistente da 0 resultados, que es correcto; no se valida contra la lista de terminos porque seria una consulta extra por peticion para convertir un 200 vacio en un 400.

## Related Code Files

- Create: `src/Api/CollectionArgs.php`
- Modify: `src/Api/Router.php` (delegar en `CollectionArgs`)
- Modify: `src/Api/Controllers/CollectionController.php` (recoger taxonomias expuestas)
- Modify: `src/Domain/Repositories/DynamicQueryBuilder.php` (`tax_query`)
- Create: `tests/Unit/Api/CollectionArgsTest.php`, `tests/Unit/Domain/DynamicQueryBuilderTest.php`

## Implementation Steps

1. **`CollectionArgs`** — extraer la lista actual tal cual, sin cambios de comportamiento. `Router` delega. Test: los args de un endpoint sin taxonomias son identicos a los de antes.
2. **`exposed_taxonomies()`** con `get_object_taxonomies()`. Test: solo devuelve las que estan en `exposed_fields` **y** registradas en el CPT.
3. **Argumentos dinamicos** con la guarda `isset`. Test: una taxonomia llamada `status` no pisa el `enum`.
4. **Recogida en el controlador** — solo taxonomias expuestas.
5. **`tax_query`** con el espia de `WP_Query` de la fase 1. Tests: un termino; varios (OR); dos taxonomias (AND); entrada con caracteres raros saneada; taxonomia no expuesta ignorada.
6. **Verificacion manual** — contra `snippet` y `categoria`.

## Success Criteria

- [ ] `?categoria=x` filtra; `?categoria=x,y` da OR; dos taxonomias dan AND.
- [ ] `?categoria=no-existe` devuelve 0 items.
- [ ] Filtrar por una taxonomia no expuesta no tiene efecto.
- [ ] Una taxonomia llamada `status` no elimina la validacion por `enum`.
- [ ] `exposed_taxonomies()` no dispara ninguna consulta.
- [ ] `grep -rn "FieldScanner" src/Api/` no aparece en el camino de registro de rutas.
- [ ] Un endpoint sin taxonomias registra exactamente los mismos argumentos que antes.
- [ ] `Router`, `ResponseCache` y `OpenApiBuilder` consumen la misma fuente.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| Inyeccion de estructura en `tax_query` | `sanitize_title` por termino |
| Coste en `rest_api_init` | `get_object_taxonomies()`, lectura en memoria; criterio de exito explicito |
| Una taxonomia pisa un parametro del plugin | Guarda `isset` estructural, no lista literal |
| Descubrir taxonomias privadas por diferencia de resultados | Solo se leen las expuestas y registradas |
| La extraccion a `CollectionArgs` cambia comportamiento | Paso 1 sin cambios funcionales, con test de equivalencia |
