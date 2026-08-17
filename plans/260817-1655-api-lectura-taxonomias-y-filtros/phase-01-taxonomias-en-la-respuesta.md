---
phase: 1
title: "Taxonomias en la respuesta"
status: pending
priority: P1
effort: "12h"
dependencies: []
---

# Phase 1: Taxonomias en la respuesta

## Overview

Conectar los tres eslabones que faltan para que las taxonomias de un CPT se seleccionen en el dashboard y aparezcan en la respuesta. Incluye la costura minima de tests sin la cual el resto de la fase se hace a ciegas.

## Requirements

**Funcionales**
- El editor muestra un grupo de taxonomias junto a Core, ACF y el resto.
- Las taxonomias seleccionadas aparecen bajo `taxonomies`, agrupadas por taxonomia.
- Una taxonomia y una meta con el mismo nombre coexisten sin pisarse.
- Las taxonomias no publicas nunca se ofrecen ni se exponen.
- Un endpoint sin taxonomias seleccionadas no incluye la clave `taxonomies`.
- Ninguna clave de taxonomia aparece bajo `fields`.

**No funcionales**
- Serializar N posts con T taxonomias no puede costar N×T consultas.
- Los endpoints existentes siguen devolviendo exactamente lo mismo.

## Architecture

### Claves cualificadas: el cambio de esquema que si hace falta

El plan original afirmaba que las taxonomias cabian en `exposed_fields` sin migracion. **Es falso.** `$source_mapping` (`OutputSerializer.php:74-82`) es un mapa de un valor por clave construido con ultima-escritura-gana:

```php
foreach ($available_fields as $f) { $mapping[$f['key']] = $f['source'] ?? $f['group'] ?? 'other'; }
```

Una taxonomia `ubicacion` emitida despues de la meta ACF `ubicacion` reescribe su `source`. El CPT de ejemplo del propio plan —propiedades con ACF `ubicacion` y taxonomia `ubicacion`— es exactamente ese caso. Un endpoint ya guardado dejaria de devolver `fields.acf.ubicacion` en silencio.

Peor: los dos guardias de seguridad del serializador estan condicionados a `$source === 'meta'` y `'database_sample'` (`OutputSerializer.php:95-106`). Una clave resuelta a `taxonomy` los esquiva, de modo que una meta interna con el mismo nombre que una taxonomia se publicaria pese al bloqueo explicito de metas con prefijo `_`.

**Solucion: claves cualificadas.** `FieldScanner` emite las taxonomias como `tax:{nombre}`:

```php
$fields[] = [
    'key'      => 'tax:' . $tax['name'],
    'taxonomy' => $tax['name'],
    'label'    => $tax['label'],
    'group'    => 'taxonomy',
    'source'   => 'taxonomy',
];
```

La colision deja de ser posible por construccion, no por convencion de orden. `exposed_fields` guarda `tax:ubicacion`, distinto de `ubicacion`, y ambos pueden seleccionarse por separado.

**Retrocompatibilidad:** ningun `exposed_fields` existente contiene claves `tax:`, asi que los endpoints guardados no cambian de comportamiento. No hace falta migracion de datos; si un admin quiere taxonomias, las selecciona.

### Serializacion

Bloque nuevo tras los nativos, **y guarda en el bucle de metas**:

```php
// Bloque nuevo
$res['taxonomies'] = [];
foreach ($allowed as $key) {
    if (strpos($key, 'tax:') !== 0) continue;
    $taxonomy = substr($key, 4);

    $terms = get_the_terms($post, $taxonomy);
    $res['taxonomies'][$taxonomy] = (is_wp_error($terms) || !$terms) ? [] : array_map(
        fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug],
        $terms
    );
}
if (empty($res['taxonomies'])) unset($res['taxonomies']);
```

```php
// Guarda en el bucle de metas existente (OutputSerializer.php:87-116)
foreach ($allowed as $field_key) {
    if (strpos($field_key, 'tax:') === 0) continue;   // <- imprescindible
    ...
}
```

Sin ese `continue`, cada taxonomia se emitiria **ademas** como `fields.taxonomy.{nombre}: ""` y pasaria por `can_interact_with_field()`, justo lo que esta fase decide evitar. Con claves cualificadas el riesgo se reduce, pero la guarda debe existir igualmente y tener test.

**`get_the_terms()` y no `wp_get_object_terms()`.** `WP_Query` ceba la cache de terminos de la coleccion mediante `update_post_term_cache`, activo por defecto — verificado contra WP 6.9.4, incluida la rama `_prime_post_caches()`. `get_the_terms()` lee esa cache; `wp_get_object_terms()` consulta la base de datos.

**Salvedad honesta:** la ruta de elemento unico no pasa por `WP_Query` (`get_single()` usa `get_post()`), asi que ahi no hay cebado y cada taxonomia cuesta una consulta. Para un solo post es aceptable y no se optimiza.

### Permisos de campo: decision

Las taxonomias **no** pasan por `Gatekeeper::can_interact_with_field()`. Razon unica: **YAGNI**. Nadie ha pedido permisos por taxonomia y `TaxonomyScanner` ya filtra las no publicas.

**Se retira la justificacion anterior**, que era falsa: `$field_auth_cache` es `private`, no `static` (`Gatekeeper.php:123`), vive por instancia y cada peticion construye un `Gatekeeper` que atiende un solo config. El escenario de dos CPT compartiendo veredicto no es alcanzable.

Consecuencia que hay que hacer visible: `field_permissions` nunca podra gobernar una taxonomia. Se documenta en `docs/05` y se indica en el propio grupo del editor.

### Efecto colateral en endpoints de WP core

`EndpointEditor.js` sirve tambien la configuracion de endpoints nativos (`admin/wp-endpoint-config`). Al añadir taxonomias a `FieldScanner` apareceran tambien ahi, donde el consumidor es `Router::filter_wp_response()`, que las trataria como meta y las descartaria. Seria una casilla marcable que no produce nada — el patron que este release existe para erradicar.

**Fix:** `filter_wp_response()` ignora las claves `tax:`, y el editor no ofrece el grupo cuando edita un endpoint WP core.

### Costura minima de tests

Sin esto, los tests de caracterizacion que esta fase exige no son escribibles. Es el minimo, no una suite de integracion:

| Pieza | Por que |
|---|---|
| Doble de `WP_Post` en `tests/bootstrap.php` | `serialize_post()` recibe uno |
| Doble de `WP_Term` | Los terminos que devuelve `get_the_terms` |
| Doble espia de `WP_Query` que capture `$query_args` | Fases 2 y 3 necesitan afirmar sobre `tax_query` y `meta_query` |
| Costura para `FieldScanner::get_available_fields()` | Es estatico y Brain Monkey no puede stubearlo. Se inyecta como dependencia opcional del serializador, igual que ya se hizo con el secreto de `JwtProvider` en 1.1.0 |
| Reset de `OutputSerializer::$field_mappings` en `TestCase::setUp()` | Es `static`: el primer test fija el mapeo para todo el proceso |

**Lo que sigue sin ser automatizable** y se marca como verificacion manual: el conteo real de consultas (`$wpdb->num_queries` no existe en el arnes). El doble espia permite contar invocaciones de `get_the_terms`, que detecta un bucle por post pero no distingue acierto de fallo de cache.

## Related Code Files

- Modify: `src/Schema/FieldScanner.php` (invocar `TaxonomyScanner`, claves `tax:`)
- Modify: `src/Api/OutputSerializer.php` (bloque de taxonomias, guarda en metas, costura de `FieldScanner`)
- Modify: `src/Api/Router.php` (`filter_wp_response()` ignora `tax:`)
- Modify: `src/frontend/components/views/EndpointEditor.js` (constantes, `collapsedSections`, auto-seleccion, ocultar grupo en endpoints WP core)
- Modify: `tests/bootstrap.php` (dobles), `tests/TestCase.php` (reset de statics)
- Modify: `docs/05_permisos_y_autorizacion.md`, `docs/08_seguridad_rendimiento_escalabilidad.md` (correccion de la deuda)
- Create: `tests/Unit/Api/OutputSerializerTest.php`, `tests/Unit/Schema/FieldScannerTest.php`

## Implementation Steps

1. **Costura de tests** — dobles de `WP_Post`, `WP_Term` y espia de `WP_Query`; inyeccion opcional de `FieldScanner` en el serializador; reset del static en `TestCase`.
2. **Tests de caracterizacion** — fijar el comportamiento actual de `serialize_post()`: whitelist vacia devuelve todos los nativos, whitelist filtra, `featured_media`, metas agrupadas por `source`, metas con prefijo `_` bloqueadas, `database_sample` bloqueado.
3. **`FieldScanner`** — instanciar `TaxonomyScanner` (namespace `WpApiCreator\Introspection`) y emitir claves `tax:`. Test: taxonomia publica se ofrece, privada no, y no colisiona con una meta homonima.
4. **`OutputSerializer`** — bloque de taxonomias + `continue` en el bucle de metas. Tests: la taxonomia sale en `taxonomies`, **no** sale en `fields`, y una meta homonima sigue saliendo en `fields`.
5. **`filter_wp_response()`** — ignorar claves `tax:`.
6. **Editor** — constantes de presentacion, `taxonomy` en `collapsedSections`, excluir taxonomias de la auto-seleccion de endpoints nuevos, ocultar el grupo en endpoints WP core, nota de que no admiten `field_permissions`. `npm run build`.
7. **Corregir `docs/08`** — la deuda de `$field_auth_cache` esta contenida por el ciclo de vida por instancia; reescribir la entrada para que refleje el riesgo real (solo se materializaria si alguien la hace `static` o reutiliza el `Gatekeeper` entre configs).
8. **Verificacion manual** — activar `categoria` en el CPT `snippet` y confirmar la respuesta.

## Success Criteria

- [ ] El editor muestra un grupo "Taxonomías" con las taxonomias publicas del CPT.
- [ ] Una taxonomia seleccionada aparece en `taxonomies.{nombre}` con `id`, `name` y `slug`.
- [ ] Ninguna clave de taxonomia aparece bajo `fields`.
- [ ] Un CPT con taxonomia y meta homonimas expone ambas por separado sin pisarse.
- [ ] Un post sin terminos devuelve array vacio, no `null`.
- [ ] Un endpoint sin taxonomias seleccionadas no incluye la clave `taxonomies`.
- [ ] Las taxonomias privadas no se ofrecen ni se exponen.
- [ ] Los endpoints ya guardados devuelven exactamente lo mismo que antes.
- [ ] El grupo no aparece al configurar endpoints de WP core.
- [ ] Los tests de caracterizacion siguen en verde tras el cambio.
- [ ] `grep -rn "TaxonomyScanner" src/` devuelve al menos un uso real.
- [ ] `docs/08` describe la deuda de `$field_auth_cache` con su alcance real.
- [ ] *(manual)* Serializar una coleccion no dispara una consulta de terminos por post.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| El cambio rompe endpoints existentes | Costura + caracterizacion **antes** de tocar el serializador (pasos 1-2) |
| Colision taxonomia/meta | Claves cualificadas `tax:`: imposible por construccion |
| Taxonomias emitidas dos veces | `continue` explicito en el bucle de metas, con test |
| N+1 en la coleccion | `get_the_terms()`; verificacion manual porque el arnes no cuenta consultas |
| Casillas inertes en endpoints WP core | El grupo no se ofrece ahi |
| El static de `$field_mappings` contamina tests | Reset en `TestCase::setUp()` |
