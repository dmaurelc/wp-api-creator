---
phase: 6
title: "Esquema documentacion y release"
status: pending
priority: P2
effort: "6h"
dependencies: [1, 2, 3, 4, 5]
---

# Phase 6: Esquema documentacion y release

## Overview

Reflejar en el esquema OpenAPI, la coleccion de Postman y la documentacion todo lo que las fases anteriores añadieron, y publicar 1.2.0.

Esta fase existe porque el proyecto arrastra un patron: parametros documentados sin efecto (`_include`, retirado en 1.1.0; `search`, `orderby` y `meta_key`, que arregla la fase 3) y ajustes mostrados sin efecto (`require_api_key` y `jwt_expiration`, **ambos ya corregidos en 1.1.0**; `cache_time`, que arregla la fase 5). La documentacion desalineada es la forma en que esos fallos se volvieron invisibles.

Estimacion subida de 4h a 6h tras la revision: la anterior no cubria el refactor del generador de esquema junto con diez archivos de documentacion y el release.

## Requirements

**Funcionales**
- El Swagger declara todos los parametros nuevos, incluidos los de taxonomia por endpoint.
- La coleccion de Postman los incluye.
- La documentacion describe el comportamiento real, con ejemplos ejecutados.
- Version 1.2.0 consistente en los cuatro sitios.

**No funcionales**
- Ningun parametro documentado puede quedar sin efecto.

## Architecture

### El esquema se deriva de `CollectionArgs`

`OpenApiBuilder::get_pagination_params()` devuelve hoy una lista fija (`OpenApiBuilder.php:282`) aplicada igual a todos los endpoints. Con taxonomias por endpoint tiene que recibir el config.

`CollectionArgs` (fase 2) es `public static` y sin dependencias, precisamente para que `OpenApiBuilder` —que es estatico— pueda consumirla sin instanciar un `Router` y arrastrar todo el stack de autenticacion.

Hace falta un **traductor de formatos**: `CollectionArgs` devuelve args de WP REST (`type`, `default`, `enum`, `sanitize_callback` con callables), y OpenAPI espera objetos `parameter` (`name`, `in`, `schema`). El traductor es la pieza que la estimacion anterior no contemplaba.

Precedente que ya funciona: `OpenApiBuilder.php:289` referencia `DynamicQueryBuilder::ALLOWED_STATUSES` para el `enum` de `status`. El patron de fuente unica existe en un caso; esta fase lo generaliza.

### Verificacion del release: un test, no un script

El plan proponia un script que recorriese el Swagger y probase cada parametro contra el sitio real, comprobando que "el resultado cambia o devuelve 400". La revision lo descarto y con razon:

- El oraculo produce falsos negativos garantizados: `?page=2` sobre 9 items con `limit=10` no cambia nada y no da 400; `?order=DESC` es el default; `?exclude=999999` tampoco cambia nada.
- Depende de la red y del estado de datos de un sitio externo que puede cambiar.
- Con la fase 5 activa, un segundo pase leeria de cache y "confirmaria" que un parametro no tiene efecto.
- El destino previsible es añadirle excepciones hasta que pase, y quedarse con un gate que aprueba cualquier cosa: el tercer artefacto decorativo del proyecto.

**Sustituto determinista, sin red:**

```php
// Todo parametro del Swagger de una ruta existe en CollectionArgs de esa ruta, y viceversa
$this->assertSame(
    array_keys(CollectionArgs::for_endpoint($config)),
    array_column(OpenApiBuilder::params_for($config), 'name')
);
```

Cubre exactamente la clase de fallo que motivaba el script —`_include` documentado y sin declarar— y corre en la suite. Lo que un script no puede garantizar, la derivacion de fuente unica lo hace imposible.

### `_fields`: advertencia al consumidor

WordPress reserva `_fields` y recorta las claves de primer nivel, asi que pasarlo a esta API devuelve `[]`. **No es algo que introduzca 1.2.0: ocurre ya en 1.1.0.** Se documenta explicitamente, porque es lo primero que probaria alguien acostumbrado a `/wp/v2`.

## Related Code Files

- Modify: `src/Schema/OpenApiBuilder.php` (parametros derivados de `CollectionArgs` + traductor)
- Modify: `src/Schema/PostmanCollectionBuilder.php`
- Modify: `README.md` (changelog 1.2.0, ejemplos verificados)
- Modify: `DOCUMENTACION_USUARIO.md`, `documentacion-plugin.md`
- Modify: `docs/03_endpoints_dinamicos_y_rewrites.md`, `docs/04_deteccion_cpt_y_campos.md`, `docs/05_permisos_y_autorizacion.md`, `docs/08_seguridad_rendimiento_escalabilidad.md`
- Modify: `ROADMAP.md`
- Modify: `wp-api-creator.php`, `package.json` (1.2.0)
- Create: `tests/Unit/Schema/OpenApiBuilderTest.php`

## Implementation Steps

1. **Traductor y derivacion** — `OpenApiBuilder::params_for($config)` a partir de `CollectionArgs`.
2. **Test de paridad** esquema ↔ argumentos.
3. **Postman** — incluir los parametros nuevos.
4. **Version 1.2.0** en los cuatro sitios. El dashboard la lee de `wpApiCreatorData.version` desde 1.1.0.
5. **Changelog** separando **correcciones de algo que mentia** (`search`, `orderby`, `meta_key`, `cache_time`) de **funcionalidad nueva** (taxonomias, filtrado, `slug`). La distincion importa: lo primero cambia el comportamiento de clientes existentes aunque no sea breaking change formal. Incluir la nota de `_fields`.
6. **Sincronizar documentacion** con ejemplos ejecutados contra la API, no inventados.
7. **`ROADMAP.md`** — cerrar la API de lectura; registrar la deuda viva.
8. **Release** — `npm run build`, commit del build, `npm run zip`, verificacion del ZIP segun `docs/09`.

## Success Criteria

- [ ] Todo parametro del Swagger existe en `CollectionArgs` de esa ruta, y al reves.
- [ ] Los parametros de taxonomia aparecen solo en los endpoints que los exponen.
- [ ] La coleccion de Postman incluye los parametros nuevos.
- [ ] El changelog distingue correcciones de funcionalidad nueva.
- [ ] La documentacion advierte del comportamiento de `_fields`.
- [ ] Version 1.2.0 consistente en los cuatro sitios.
- [ ] El ZIP pasa las comprobaciones de `docs/09`.
- [ ] La suite completa en verde.
- [ ] Ningun ejemplo de la documentacion esta sin ejecutar.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| El Swagger documenta parametros inexistentes | Derivacion de fuente unica + test de paridad |
| El traductor de formatos se subestima otra vez | Estimacion subida a 6h con el traductor contabilizado |
| El ZIP sale con el dashboard viejo | Procedimiento de `docs/09`, validado en 1.1.0 |
| Un cliente cambia de comportamiento al empezar a filtrar `search` | Changelog explicito en la seccion de correcciones |
