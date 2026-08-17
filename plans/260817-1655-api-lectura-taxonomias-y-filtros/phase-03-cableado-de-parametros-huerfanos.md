---
phase: 3
title: "Cableado de parametros huerfanos"
status: pending
priority: P1
effort: "5h"
dependencies: []
---

# Phase 3: Cableado de parametros huerfanos

# Overview

`search`, `orderby`, `order`, `meta_key` y `meta_value` estan implementados en `DynamicQueryBuilder::get_collection()` y nunca llegan a ejecutarse. Hoy `?search=x` devuelve la coleccion entera **sin error**, y la documentacion los da por funcionales.

No es una feature nueva: son cinco parametros que mienten.

**Sin dependencias. Puede publicarse solo como 1.1.1**, antes que el resto del plan, si el consumidor necesita `search` u `orderby` pronto.

## Requirements

**Funcionales**
- Los cinco parametros surten efecto.
- Un valor invalido devuelve `400`, nunca la coleccion sin filtrar.
- `orderby` acotado a lista blanca.
- **`meta_key` acotado a las metas que el endpoint expone.**
- La documentacion deja de describir comportamiento inexistente.

**No funcionales**
- Ninguna ordenacion puede provocar una consulta sin indice sobre una tabla grande.
- Ningun parametro se ignora en silencio.

## Architecture

### `meta_key` sin restringir es un oraculo sobre metas privadas

Declarar `meta_key` como string libre abre una via de lectura indirecta que el propio proyecto se niega a abrir directamente: `OutputSerializer.php:102-106` bloquea explicitamente la **emision** de metas con prefijo `_`, con el comentario "bloqueamos todo lo que empiece con `_`". Sin restriccion, la fase 3 permitiria **consultarlas**:

```
?meta_key=_stripe_customer_id&meta_value=cus_XXXX   -> 0 o N items
?meta_key=_secret_token&meta_value=<candidato>      -> un bit por peticion
?meta_key=_edit_last&meta_value=1                   -> quien edito que
```

`sanitize_key` conserva el guion bajo inicial, asi que no hay barrera accidental. El `RateLimiter` de 1.1.0 solo cuenta credenciales fallidas: una GET publica no lo toca.

Funcionaria ademas sobre metas **no seleccionadas** en `exposed_fields`, porque el repositorio ni siquiera recibe el config.

**Fix:** `meta_key` se declara con `enum` generado desde `exposed_fields`, igual que las taxonomias en la fase 2:

```php
$meta_keys = CollectionArgs::exposed_meta_keys($config);   // claves sin prefijo `tax:` ni nativas
if ($meta_keys) {
    $args['meta_key']   = ['type' => 'string', 'enum' => $meta_keys];
    $args['meta_value'] = ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'];
}
```

Si el endpoint no expone ninguna meta, los dos parametros **no se declaran**. Cualquier otra clave -> `400 rest_not_in_enum`.

### Validacion: `enum`, no guarda silenciosa

El plan anterior se contradecia: pedia `400` en los criterios y "cae al default" en los pasos. Se resuelve a favor del `enum`: WordPress rechaza antes de llegar al controlador, la guarda del repositorio nunca se alcanza y el cliente sabe que se equivoco. Es el patron de `status`, ya verificado en produccion.

```php
'search'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
'orderby' => ['type' => 'string', 'default' => 'date', 'enum' => DynamicQueryBuilder::ALLOWED_ORDERBY],
'order'   => ['type' => 'string', 'default' => 'DESC', 'enum' => ['ASC', 'DESC']],
```

```php
const ALLOWED_ORDERBY = ['date', 'modified', 'title', 'menu_order', 'ID'];
```

`meta_value` y `meta_value_num` quedan fuera: obligan a un JOIN sobre `wp_postmeta` sin indice util para ordenacion.

**`rand` tambien queda fuera**, corrigiendo el plan anterior. Es `ORDER BY RAND()`, una consulta sin indice por definicion, lo que contradice el requisito no funcional de esta misma fase. Y obligaria a la fase 5 a una excepcion de "no cachear". Si hace falta contenido aleatorio, el cliente pide N y baraja.

### `meta_key` sin `meta_value`, y `meta_value=0`

`DynamicQueryBuilder.php:69` exige ambos con `!empty()`. Eso descarta en silencio `meta_value=0`, un valor legitimo. Se corrige a comprobacion de presencia:

```php
if (isset($args['meta_key'], $args['meta_value']) && $args['meta_key'] !== '') {
```

`meta_key` a solas seguiria ignorandose. Como esta fase exige que nada se ignore en silencio, se declara `meta_value` como **requerido cuando `meta_key` esta presente** mediante `validate_callback`, de modo que la combinacion incompleta devuelve 400.

### Una sola lista de parametros

`CollectionArgs` (fase 2) es la fuente. Si la fase 3 se publica antes como 1.1.1, se extrae `CollectionArgs` aqui y la fase 2 la encuentra hecha.

## Related Code Files

- Modify: `src/Api/CollectionArgs.php` (o `src/Api/Router.php` si esta fase va primero)
- Modify: `src/Api/Controllers/CollectionController.php`
- Modify: `src/Domain/Repositories/DynamicQueryBuilder.php` (`ALLOWED_ORDERBY`, guardas, `meta_value=0`)
- Modify: `README.md`, `DOCUMENTACION_USUARIO.md`
- Modify: `tests/Unit/Domain/DynamicQueryBuilderTest.php`

## Implementation Steps

1. **`ALLOWED_ORDERBY`** con comentario de las exclusiones (`meta_value*`, `rand`).
2. **`exposed_meta_keys()`** en `CollectionArgs`.
3. **Declarar los cinco** con `enum` y `validate_callback` para la pareja `meta_key`/`meta_value`.
4. **Pasarlos** desde el controlador.
5. **`meta_value=0`** — sustituir `!empty()` por comprobacion de presencia.
6. **Tests** — cada parametro surte efecto; `?orderby=meta_value` -> 400; `?order=X` -> 400; `?meta_key=_secret` -> 400 porque no esta en `exposed_fields`; `meta_key` sin `meta_value` -> 400; `meta_value=0` filtra; `search` con `%` y comillas no rompe.
7. **Documentacion** — verificar cada ejemplo ejecutandolo.

## Success Criteria

- [ ] `?search=variaciones` devuelve menos items que la coleccion.
- [ ] `?orderby=title&order=ASC` cambia el primer item.
- [ ] `?meta_key=k&meta_value=v` filtra, con `k` expuesta.
- [ ] `?meta_key=_edit_last&meta_value=1` devuelve `400 rest_not_in_enum`.
- [ ] Un endpoint sin metas expuestas no declara `meta_key` ni `meta_value`.
- [ ] `?orderby=meta_value` y `?order=CUALQUIERA` devuelven 400.
- [ ] `?meta_key=k` sin `meta_value` devuelve 400.
- [ ] `?meta_value=0` filtra en lugar de ignorarse.
- [ ] Los ejemplos de `README.md` y `DOCUMENTACION_USUARIO.md` funcionan tal cual.
- [ ] Ningun parametro documentado se ignora en silencio.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| Lectura indirecta de metas privadas | `enum` desde `exposed_fields`; sin metas expuestas no se declara el parametro |
| `orderby` abre una consulta sin indice | Lista blanca sin `meta_value*` ni `rand` |
| `search` lento sobre CPT grandes | `s` de `WP_Query` hace `LIKE`; se documenta el limite y la recomendacion de un buscador dedicado |
| Un cliente dependia de que `search` se ignorase | Hoy no filtra nada; se documenta como correccion en el changelog |
