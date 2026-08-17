---
title: "API de lectura: taxonomias, filtros y cache"
description: "Completa la API de lectura: expone taxonomias, conecta los parametros de filtrado que nunca llegaron al repositorio y hace efectivo el ajuste de cache."
status: pending
priority: P1
branch: "main"
tags: [api, taxonomias, filtros, cache, rendimiento]
blockedBy: []
blocks: []
created: "2026-08-17T21:23:46.848Z"
createdBy: "ck:plan"
source: skill
---

# API de lectura: taxonomias, filtros y cache

## Overview

1.1.0 hizo el plugin **seguro**. 1.2.0 lo hace **util**.

**Hallazgo que ordena el plan:** el plugin no puede representar taxonomias. Un CPT de propiedades con `estado` y `ubicacion` devuelve los posts sin ninguna de las dos, y no hay forma de activarlas desde el dashboard. Para un plugin que se vende como "convierte WordPress en headless CMS", eso no es un hueco en la API: es un hueco en la promesa.

La cadena tiene cuatro eslabones y solo existe el primero:

| Eslabon | Estado |
|---|---|
| Descubrir | `TaxonomyScanner` (`src/Introspection/`) escrito, correcto y **jamas instanciado** |
| Seleccionar | `FieldScanner` solo emite grupos `native` y `meta` |
| Emitir | `OutputSerializer` no tiene nocion de terminos |
| Filtrar | `DynamicQueryBuilder` no construye `tax_query` |

**Segundo hallazgo:** `search`, `orderby`, `order`, `meta_key` y `meta_value` estan implementados y correctos en `DynamicQueryBuilder::get_collection()`, pero `Router::get_collection_args()` no los declara y `CollectionController::get_items()` no los pasa. `?search=x` devuelve la coleccion entera **sin error**, que es la peor forma de fallar. `DOCUMENTACION_USUARIO.md` documenta `search` y `orderby`; el `README.md:74` documenta `meta_key`/`meta_value`. Los tres mienten.

Contraste que lo demuestra: `?status=inventado` si devuelve `400 rest_not_in_enum`, porque `status` esta declarado en el Router y WordPress lo valida.

## Evidencia empirica

Verificado con `curl` contra `https://wp-snippets.thisistheweb.cl/wp-json/snippets/v1/snippet` (9 items, CPT `snippet`, taxonomia `categoria`):

| Peticion | Resultado |
|---|---|
| `?limit=2`, `?limit=2&page=2`, `?limit=500` | paginacion y tope correctos |
| `?status=draft` (invitado) | 0 items — correcto |
| `?status=inventado` | `400 rest_not_in_enum` — correcto |
| `?search=variaciones` | **los 9, sin filtrar** |
| `?orderby=title&order=ASC` | **los 9, sin ordenar** |
| `?meta_key=codigos&meta_value=999` | **los 9, sin filtrar** |
| respuesta completa | **ningun termino de `categoria`** |
| `?_fields=id,title` | **`[]`** — ver decision sobre `_fields` |
| `?_fields=meta` | `{"meta":{...}}` |

La paginacion esta completa y no necesita trabajo.

## Decisiones tomadas (confirmadas con el usuario)

| Tema | Decision |
|------|----------|
| Envoltorio de respuesta | **`{data, meta}` se mantiene.** El consumidor es Astro, indiferente a la forma, y el envoltorio tiene punto de extension. **No reabrir.** |
| Ubicacion de las taxonomias | Clave `taxonomies` propia dentro de cada item, agrupada por taxonomia. |
| Semantica de filtros | **AND entre taxonomias, OR dentro.** |
| `_fields` | **Retirado.** WordPress reserva ese nombre: `rest_filter_response_fields` recorta claves de primer nivel y con el envoltorio `{data, meta}` devuelve `[]`. Verificado en vivo. De la fase 4 sobrevive solo el ahorro real: hacer perezoso `native_map`, mas `slug`. |
| `include`/`exclude` | **Fuera.** Reintroducian lo que 1.1.0 retiro y arrastraban `post__in` a la lista blanca de la fase 3. |
| `cache_time` | **Se implementa recortado:** solo coleccion, solo resultados publicados. Con migracion a 0 al actualizar y boton de purga. |
| Infraestructura de tests | Costura minima **dentro de la fase 1**, no fase aparte. Lo que no sea automatizable se marca como verificacion manual. |
| Almacenamiento de la seleccion | `exposed_fields` con claves **cualificadas** para taxonomias (`tax:nombre`). Cambio de esquema con lectura retrocompatible. |

## Phases

| Phase | Name | Status |
|-------|------|--------|
| 1 | [Taxonomias en la respuesta](./phase-01-taxonomias-en-la-respuesta.md) | Pending |
| 2 | [Filtrado por taxonomia](./phase-02-filtrado-por-taxonomia.md) | Pending |
| 3 | [Cableado de parametros huerfanos](./phase-03-cableado-de-parametros-huerfanos.md) | Pending |
| 4 | [Resolucion perezosa y filtro por slug](./phase-04-seleccion-de-campos-y-recursos.md) | Pending |
| 5 | [Cache de respuestas configurable](./phase-05-cache-de-respuestas-configurable.md) | Pending |
| 6 | [Esquema documentacion y release](./phase-06-esquema-documentacion-y-release.md) | Pending |

## Orden y paralelismo

```
Fase 1 (taxonomias + costura de tests)  12h
   ├─> Fase 2 (filtrado por taxonomia)   7h ─┐
   └─> Fase 4 (native_map perezoso + slug) 2h│
Fase 3 (cableado de huerfanos)  5h ← independiente
                                             v
                                    Fase 5 (cache)  6h
                                             v
                                    Fase 6 (release)  6h
```

**La fase 3 no depende de nada y repara un fallo activo en produccion.** Puede publicarse sola como 1.1.1 antes de que el resto este listo. Recomendado si el consumidor Astro necesita `search` u `orderby` pronto.

**La fase 5 va la ultima** porque la clave de cache debe incorporar todos los parametros que afectan al resultado, y las fases 2, 3 y 4 los añaden.

Esfuerzo estimado: **~38h**, frente a las 36h del plan previo a la revision. Los recortes (`_fields`, `include`/`exclude`, cache reducida) restaron 3h; el trabajo que la revision destapo —costura de tests, `CollectionArgs`, claves cualificadas, traductor de esquema— sumo 5h. **La revision no abarato el plan: lo hizo realizable.**

## Riesgo global

| Riesgo | Impacto | Mitigacion |
|--------|---------|------------|
| Colision entre una taxonomia y una meta del mismo nombre | Critico | Claves cualificadas `tax:nombre` en `FieldScanner` y `exposed_fields`. La colision deja de ser posible por construccion, no por convencion de orden. Fase 1. |
| La cache sirve contenido de un usuario a otro | Critico | Recortada a coleccion y a resultados publicados: deja de existir el caso personal. Clave a partir de valores normalizados y hasheados. Fase 5. |
| La cache se auto-activa al actualizar en quien ya guardo `cache_time = 300` | Critico | Migracion explicita a 0 en la actualizacion, mas boton de purga. Fase 5. |
| `meta_key` como oraculo sobre metas privadas | Alto | `enum` restringido a las metas de `exposed_fields`. Fase 3. |
| Escaneo de metadatos en toda peticion REST del sitio | Alto | Las taxonomias expuestas se resuelven con `get_object_taxonomies()` — lectura en memoria — nunca con `FieldScanner`. Fase 2. |
| Regresion en endpoints existentes al tocar el serializador | Alto | Costura de tests y caracterizacion de `serialize_post` **antes** de modificarlo. Fase 1. |
| N+1 al serializar terminos | Medio | `get_the_terms()` lee la cache que `WP_Query` ya ceba. **Verificado por el red team contra WP 6.9.4**, incluida la rama `_prime_post_caches`. La ruta de elemento unico no pasa por `WP_Query` y no tiene cebado: es 1 consulta por taxonomia, aceptable para un solo post. |
| Tres consumidores de la lista canonica de parametros | Alto | Se extrae a `Api\CollectionArgs` estatico en la fase 2, antes de que las fases 5 y 6 lo necesiten. |

## Fuera de alcance

- Cambiar el envoltorio de respuesta.
- `_fields` / seleccion de campos por peticion (ver decisiones).
- `include`/`exclude` por IDs.
- Exponer `meta_query` con comparadores arbitrarios desde la URL.
- Escritura de terminos via POST/PATCH. Esta version es de lectura.
- Refactor de inyeccion de dependencias.
- Suite de integracion con `wp-env`. **Consecuencia asumida:** varios criterios de exito son verificacion manual documentada, no test automatizado. Cada fase marca cuales.

## Deuda conocida relevante

`OutputSerializer::$field_mappings` es `static` y nunca se invalida. La fase 1 lo toca de cerca y añade reset entre tests; corregir su naturaleza queda fuera.

**Correccion pendiente de 1.1.0:** `docs/08` afirma que `Gatekeeper::$field_auth_cache` puede compartir veredicto entre dos CPT. Es falso: la propiedad es `private`, no `static` (`Gatekeeper.php:123`), vive por instancia y cada peticion construye un `Gatekeeper` que atiende un solo config. La entrada exagera el riesgo. La fase 1 la corrige.

## Red Team Review

### Session — 2026-08-17
**Findings:** 40 brutos, 22 tras deduplicar (20 aceptados, 2 elevados a decision del usuario)
**Severity breakdown:** 6 Critical, 9 High, 7 Medium
**Revisores:** Security Adversary (Fact Checker), Failure Mode Analyst (Flow Tracer), Assumption Destroyer (Scope Auditor), Scope & Complexity Critic (Contract Verifier)

Ningun hallazgo cayo por el filtro de evidencia. Ocho fueron encontrados por dos o mas revisores de forma independiente.

| # | Finding | Severity | Disposition | Applied To |
|---|---------|----------|-------------|------------|
| 1 | `_fields` es reservado por WP core; con el envoltorio devuelve `[]` | Critical | Accept | Fase 4 (retirado) |
| 2 | Clave de cache de la ruta unica sin `id`: dos recursos comparten entrada | Critical | Accept | Fase 5 (ruta unica excluida) |
| 3 | `current_user_can('edit_post')` es capacidad por post; los roles no bastan como identidad | Critical | Accept | Fase 5 (solo publish) |
| 4 | Colision taxonomia/meta en `$source_mapping`: sobrescritura silenciosa | Critical | Accept | Fase 1 (claves cualificadas) |
| 5 | `_fields` con interseccion vacia activa el centinela y devuelve todo | Critical | Accept | Fase 4 (retirado) |
| 6 | `cache_time` se auto-activa al actualizar en quien puso 300 | Critical | Accept | Fase 5 (migracion + purga) |
| 7 | Las taxonomias se emitirian tambien como meta vacia | High | Accept | Fase 1 |
| 8 | `get_collection_args()` es `protected` de instancia; 3 consumidores previstos | High | Accept | Fase 2 (`CollectionArgs`) |
| 9 | El arnes de tests no soporta los tests prometidos | High | Accept | Fase 1 (costura) + alcance |
| 10 | `native_map` ansioso: `the_content` ya se ejecuta siempre | High | Accept | Fase 4 |
| 11 | Escaneo de `FieldScanner` en `rest_api_init` | High | Accept | Fase 2 |
| 12 | `meta_key` como oraculo sobre metas privadas | High | Accept | Fase 3 |
| 13 | El test de invariante de clave de cache es tautologico | High | Accept | Fase 5 |
| 14 | Espacio de claves de cache ilimitado y controlado por anonimos | High | Accept | Fase 5 |
| 15 | Invalidacion incompleta: PATCH solo-metas, `delete_term`, `edited_term` | High | Accept | Fase 5 |
| 16 | Justificacion 2 del Gatekeeper es falsa; `docs/08` exagera la deuda | Medium | Accept | Fase 1 |
| 17 | Script de verificacion del release: heuristico y no determinista | Medium | Accept | Fase 6 (sustituido) |
| 18 | Reservados de la fase 2 incompletos; una taxonomia `status` pisa el `enum` | Medium | Accept | Fase 2 |
| 19 | `EndpointEditor` sirve tambien a endpoints WP core: taxonomias inertes ahi | Medium | Accept | Fase 1 |
| 20 | `collapsedSections` sin `taxonomy` y auto-seleccion de todo en endpoints nuevos | Medium | Accept | Fase 1 |
| 21 | Fase 5 (cache) no se justifica frente a retirar el ajuste | High | Usuario | Recorte: coleccion + publish |
| 22 | `include`/`exclude` reintroducen lo que 1.1.0 retiro | Medium | Usuario | Cortados |

### Whole-Plan Consistency Sweep
- Files reread: plan.md, phase-01 a phase-06 (reescritos por completo)
- Decision deltas checked: 9 (`_fields` retirado, `include`/`exclude` cortados, cache recortada, claves de taxonomia cualificadas, `CollectionArgs` extraido en fase 2, `get_object_taxonomies` en vez de `FieldScanner`, costura de tests en fase 1, `meta_key` con `enum`, script de release sustituido)
- Reconciled stale references: 13 (namespace de `TaxonomyScanner`, `branch` del frontmatter, atribucion de `search`/`orderby` al README, afirmacion de que `require_api_key` y `jwt_expiration` siguen rotos, riesgo de colision de Bajo a Critico, estimacion 36h -> 38h, titulo de la fase 4, grafo de dependencias, criterios de exito de `_fields`, deuda de `$field_auth_cache`, arista 3->4 por `post__in`, tabla de riesgo global)
- Unresolved contradictions: 0
