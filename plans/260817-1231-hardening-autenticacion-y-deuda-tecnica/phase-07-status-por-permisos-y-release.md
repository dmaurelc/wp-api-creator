---
phase: 7
title: "Status por permisos y release"
status: completed
priority: P2
effort: "8h"
dependencies: [5, 6]
---

# Phase 7: Status por permisos y release

## Overview

Cerrar el ultimo pendiente funcional (el estado de los posts en las colecciones), limpiar el repositorio y publicar 1.1.0 con la documentacion sincronizada y un procedimiento de rollback real.

Dos piezas del plan original salen de esta fase por decision confirmada: el refactor de DI y la implementacion de `_include`.

## Requirements

**Funcionales**
- El estado de los posts en colecciones se resuelve segun capacidades, no fijo a `publish`.
- `_include` se retira por completo: codigo, argumentos y documentacion.
- El repositorio no contiene archivos temporales.
- Existe un procedimiento de rollback documentado.

**No funcionales**
- Documentacion coherente con el codigo tras las fases 1-6.
- Version 1.1.0 consistente en los cuatro sitios donde vive.
- El ZIP publicado contiene el dashboard compilado de 1.1.0.

## Architecture

**Estado de posts — con la cita corregida.** El plan original apuntaba a `DynamicQueryBuilder.php:91`. Es incorrecto: esa linea esta en `get_single()`, que **ya** valida con `current_user_can('edit_post', $post->ID)` (`:93`). El hardcode real esta en la consulta de coleccion, `DynamicQueryBuilder.php:31`.

La capacidad propuesta tambien era erronea. `edit_posts` la tiene el rol `author` de serie, y WP_Query no aplica ningun filtro de propiedad: `?status=draft` con esa comprobacion devolveria **los borradores de todos los autores**. Regla correcta:

| Estado pedido | Requisito |
|---|---|
| `publish` | ninguno (default) |
| `draft`, `pending` | `edit_others_posts` mapeada al CPT, o forzar `author__in = [usuario actual]` |
| `private` | `read_private_posts` mapeada al CPT |

El mapeo se hace contra el objeto `cap` del CPT, como ya hace `Gatekeeper::verify_ownership()` (`:107-110`). Nunca se acepta el `status` del cliente sin comprobar.

**`_include` se retira.** Hoy se declara en los args (`Router.php:373`), se documenta en el Swagger (`OpenApiBuilder.php:286` — omitido en el plan original) y no hace nada (`CollectionController.php:47`). Aceptar un parametro que no funciona es peor que no ofrecerlo, y una feature nueva no pinta en un release de seguridad. Se elimina de los tres sitios.

**El refactor de DI sale del release.** Su justificacion era habilitar dobles en tests de integracion, que estan fuera de alcance. La justificacion de rendimiento tampoco se sostiene: WordPress sirve una peticion REST por proceso, y de los cinco controladores solo se instancia el que corresponde. Ademas `Container::get()` cachea toda resolucion (`Container.php:74`), asi que `bind()` ya devuelve singletons — registrar los controladores los convertiria en compartidos sin que nadie lo pretendiera. Hacerlo en el ultimo commit antes de etiquetar un release de seguridad, con los 6 handlers del Router en juego y una excepcion no capturada dentro de un `permission_callback` como modo de fallo, es riesgo sin retorno.

**Deuda conocida que se documenta, no se arregla aqui:**

- `Gatekeeper::$field_auth_cache` usa la clave `{user_id}_{field_key}_{action}` sin incluir el config (`:138`). Dos CPT que expongan un mismo meta con permisos distintos pueden compartir entrada de cache dentro de un mismo proceso. Hoy queda parcialmente enmascarado porque `OutputSerializer` construye su propia instancia de `Gatekeeper` (`:18`). **Quien retome el refactor de DI debe arreglar la clave de cache antes de colapsar las instancias.**
- `get_swagger_ui` carga tres scripts de `unpkg.com` sin SRI en una respuesta anonima.
- `OutputSerializer::$field_mappings` es `static` y nunca se invalida.

**Release.** `npm run zip` es `npm run build && git archive ... HEAD`. `git archive` lee **el commit**, no el arbol de trabajo, y `build/` esta versionado. Sin commitear el build, el ZIP sale con el dashboard de 1.0.0: la UI vieja leeria `key.key`, campo que la fase 4 elimino, y la key generada se perderia en el mismo instante de crearla. El criterio "carga sin errores de consola" no lo detectaria.

## Related Code Files

- Modify: `src/Domain/Repositories/DynamicQueryBuilder.php` (status por capacidades, linea 31)
- Modify: `src/Api/Router.php` (retirar `_include` de `get_collection_args`, documentar `status`)
- Modify: `src/Api/Controllers/CollectionController.php` (retirar el TODO de `_include`)
- Modify: `src/Schema/OpenApiBuilder.php` (retirar `_include`, documentar `status`)
- Modify: `wp-api-creator.php`, `package.json`, `README.md` (version 1.1.0)
- Modify: `ROADMAP.md`, `docs/01_arquitectura_tecnica.md`, `docs/02_sistema_autenticacion.md`, `docs/05_permisos_y_autorizacion.md`, `docs/09_gestion_de_lanzamientos.md`
- Modify: `DOCUMENTACION_USUARIO.md` (linea 63), `documentacion-plugin.md` (lineas 290, 318) — ambos documentan el flujo de `X-API-Key` que cambia
- Delete: `test-cpt-meta.php`, `tmp-inspect-config.php`

## Implementation Steps

1. **Status por capacidades** — En `DynamicQueryBuilder::get_collection()` (linea 31), aceptar `status` del request contra lista blanca y aplicar la tabla de capacidades. Para `draft`/`pending` sin `edit_others_posts`, forzar `author__in`. Declarar el parametro en `get_collection_args()`.
2. **Retirar `_include`** — Eliminarlo de `Router.php:373`, del TODO de `CollectionController.php:47` y de `OpenApiBuilder.php:286`.
3. **Limpieza** — Borrar `test-cpt-meta.php` y `tmp-inspect-config.php`. Retirar sus entradas de `.gitattributes` (lineas 44-45). **No** tocar `.distignore`: no contiene esas entradas y ademas ninguna herramienta del proyecto lo consume — `npm run zip` usa `git archive --worktree-attributes`, que solo lee `.gitattributes`. Evaluar borrar `.distignore` por muerto.
4. **Version 1.1.0** — Cabecera de `wp-api-creator.php`, constante `WP_API_CREATOR_VERSION`, `package.json` y `Stable tag` del `README.md`.
5. **Changelog** — Seccion 1.1.0 separando **breaking changes** (API keys invalidadas y con modelo nuevo, tokens JWT previos invalidados, `require_api_key` ahora se aplica, `/docs` cerrado con enforcement activo, `_include` retirado) de correcciones y mejoras.
6. **Runbook de rollback** — Documentar en `docs/09_gestion_de_lanzamientos.md`: restaurar `wp_api_creator_config` desde `wp_api_creator_config_backup_1_0_0` (fase 1), revertir el plugin a 1.0.0, regenerar keys. Incluir el comando WP-CLI.
7. **Sincronizar `docs/`** — `01` (regla de `save_config`), `02` (modelo de keys, `token_version`, rate limiting, cierre de `/docs`), `05` (herencia de rol via key, status por capacidades), `09` (rollback).
8. **Sincronizar docs de usuario** — `DOCUMENTACION_USUARIO.md` y `documentacion-plugin.md` describen el flujo viejo de `X-API-Key`.
9. **Registrar deuda conocida** — Anadir a `docs/08_seguridad_rendimiento_escalabilidad.md` las tres piezas listadas en Architecture, con su ubicacion exacta.
10. **Actualizar `ROADMAP.md`** — Fase 4 completa. Corregir la descripcion: el JWT nunca estuvo pendiente de "pruebas finales de firma"; lo que faltaba eran estos controles.
11. **Release** — `npm run build` -> `git add build/ && git commit` -> `npm run zip`. Verificar con `unzip -l` (sin `tests/`, sin `src/frontend/`, sin temporales) y confirmar que el `build/index.js` del ZIP contiene un marcador de 1.1.0 (por ejemplo el texto del modal de key unica). Instalar limpio en un WP 6.7 **y** probar una actualizacion sobre una instalacion 1.0.0 con datos.

## Success Criteria

- [ ] Un invitado no obtiene borradores ni pidiendolos con `?status=draft`.
- [ ] Un `author` con `?status=draft` recibe solo sus propios borradores.
- [ ] Un `editor` con `edit_others_posts` recibe los borradores del CPT.
- [ ] `?status=private` sin `read_private_posts` no devuelve nada.
- [x] `grep -rn "_include" src/` sin resultados.
- [x] `test-cpt-meta.php` y `tmp-inspect-config.php` ya no existen.
- [x] Version 1.1.0 consistente en los 4 sitios.
- [x] El changelog separa breaking changes de mejoras.
- [ ] El runbook de rollback esta documentado y probado sobre una copia.
- [x] El `build/index.js` dentro del ZIP contiene el marcador de 1.1.0.
- [ ] La actualizacion sobre una instalacion 1.0.0 con datos deja el sitio operativo, con el backup creado y las keys marcadas legacy.
- [x] La suite completa de tests en verde.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| Exponer `status` abre lectura de borradores ajenos | Tabla de capacidades + `author__in` forzado; nunca confiar en el parametro |
| El ZIP sale con el dashboard viejo | Commit del build antes del zip + verificacion del marcador dentro del ZIP |
| Un breaking change sorprende al usuario | Changelog explicito, avisos en el dashboard, runbook de rollback |
| La deuda documentada se olvida | Queda en `docs/08` con ubicacion exacta y la condicion que la haria peligrosa |

> **Estado de verificación.** Las casillas marcadas están verificadas por test unitario (`composer test`) o por comando (`grep`, `npm run build`). Las casillas sin marcar están **implementadas pero pendientes de verificación funcional** contra una instalación real de WordPress: el entorno de desarrollo no tiene una disponible y la suite es unitaria, sin `wp-env`. No representan trabajo pendiente de código.
