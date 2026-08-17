---
phase: 4
title: "Resolucion perezosa y filtro por slug"
status: pending
priority: P2
effort: "2h"
dependencies: [1]
---

# Phase 4: Resolucion perezosa y filtro por slug

## Overview

Fase recortada tras la revision adversarial. Entrega el ahorro de rendimiento real —que no necesitaba `_fields`— y el unico parametro del grupo original que sobrevive: `slug`.

## Por que se retiro `_fields`

**WordPress reserva ese nombre.** Core engancha `rest_filter_response_fields()` a `rest_post_dispatch` para todas las respuestas REST y recorta las claves de **primer nivel**. Con el envoltorio `{data, meta}`, las claves de primer nivel son `data` y `meta`, no `id` ni `title`.

Verificado en vivo contra la API real:

```
?_fields=meta      -> {"meta":{"total_items":9,...}}
?_fields=id,title  -> []
?_fields=data      -> {"data":[...]}
```

El criterio de exito que el plan proponia —"`?_fields=id,title` devuelve items con exactamente esas dos claves"— devuelve un array vacio hoy mismo, sin escribir una linea.

Segundo motivo, independiente: el snippet de interseccion propuesto producia `[]` cuando el cliente pedia campos inexistentes, y `[]` es el **centinela de "expon todo"** del serializador (`OutputSerializer.php:35,52,59`). `?_fields=zzz` habria desactivado la lista blanca del endpoint y devuelto la respuesta completa, incluido `content`. Lo contrario de lo prometido.

Tercer motivo: el ahorro que justificaba la fase no venia de `_fields`.

**Nota para el consumidor:** como core aplica el filtro globalmente, un cliente que pase `_fields` a esta API recibe respuestas mutiladas **ya en 1.1.0**. Se documenta en la fase 6.

## Por que se retiraron `include`/`exclude`

1.1.0 retiro `_include` anunciandolo como cambio incompatible. Reintroducirlos un release despues, sin demanda registrada, arrastraba ademas `post__in` a `ALLOWED_ORDERBY` —creando una dependencia entre las fases 3 y 4 que el grafo no reflejaba— y un tope de 100 IDs con su semantica de precedencia. Para tres destacados, el cliente pide por `slug` o hace tres peticiones al endpoint de elemento unico.

## Architecture

### El ahorro real: `native_map` perezoso

`serialize_post()` construye `$native_map` **entero y de forma ansiosa** (`OutputSerializer.php:39-49`) y filtra despues (`:52-56`). Es decir: `apply_filters('the_content', ...)` y dos `get_post_datetime()` se ejecutan **para todos los campos aunque el endpoint no los exponga**.

Un endpoint configurado para devolver solo `title` ya paga hoy el renderizado completo de bloques, shortcodes y oEmbed de cada post de la coleccion. **Es un fallo existente, no una optimizacion nueva.**

```php
$native_resolvers = [
    'id'       => fn() => $post->ID,
    'title'    => fn() => $post->post_title,
    'content'  => fn() => apply_filters('the_content', $post->post_content),
    'excerpt'  => fn() => $post->post_excerpt,
    'slug'     => fn() => $post->post_name,
    'status'   => fn() => $post->post_status,
    'author'   => fn() => $post->post_author,
    'date'     => fn() => get_post_datetime($post->ID, 'date', 'gmt')->format('c'),
    'modified' => fn() => get_post_datetime($post->ID, 'modified', 'gmt')->format('c'),
];

foreach ($native_resolvers as $key => $resolve) {
    if (empty($allowed) || in_array($key, $allowed, true)) {
        $res[$key] = $resolve();
    }
}
```

El centinela `empty($allowed)` se conserva tal cual: esta fase **no** cambia el contrato, solo mueve cuando se paga el coste. `featured_media` ya comprueba antes de resolver (`:59`) y no necesita cambio.

`isset($native_map[$field_key])` en el bucle de metas (`:90`) pasa a `isset($native_resolvers[$field_key])`.

### `slug`

```php
'slug' => ['type' => 'string', 'sanitize_callback' => 'sanitize_title'],
```

Traducido a `$query_args['name']`. Es lo que necesita una ruta `[slug].astro`.

## Related Code Files

- Modify: `src/Api/OutputSerializer.php` (resolucion perezosa)
- Modify: `src/Api/CollectionArgs.php` (`slug`)
- Modify: `src/Domain/Repositories/DynamicQueryBuilder.php` (`name`)
- Modify: `tests/Unit/Api/OutputSerializerTest.php`

## Implementation Steps

1. **Resolucion perezosa** — convertir `$native_map` en `$native_resolvers`. Los tests de caracterizacion de la fase 1 deben seguir en verde **sin modificarse**: es la prueba de que el contrato no cambia.
2. **Test del ahorro** — registrar un filtro `the_content` contador y afirmar 0 invocaciones cuando `content` no esta en `exposed_fields`. Es el unico criterio de esta fase que puede automatizarse.
3. **`slug`** — declarar y traducir a `name`.
4. **Medicion** — tiempo de respuesta de un endpoint que expone solo `title` sobre una coleccion con contenido rico, antes y despues. El numero va al changelog.

## Success Criteria

- [ ] `the_content` no se ejecuta cuando `content` no esta en `exposed_fields`.
- [ ] Los tests de caracterizacion de la fase 1 pasan sin modificarse.
- [ ] La respuesta es byte a byte identica a la anterior para cualquier configuracion.
- [ ] `?slug=x` devuelve el recurso correcto.
- [ ] Un endpoint que expone solo `title` responde medibliemente mas rapido.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| La resolucion perezosa cambia la salida | Tests de caracterizacion de la fase 1 sin modificar; criterio de identidad byte a byte |
| Un resolver captura `$post` por valor y se desincroniza | Closures sobre `$post` en el mismo ambito, sin mutacion posterior |
| El ahorro no se materializa | Test con contador sobre `the_content`, no medicion a ojo |
