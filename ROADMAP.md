# Roadmap de Desarrollo: WP Custom API Creator 🚀

_Actualizado: 28 de Febrero, 2026_

A continuación se detalla el estado real del proyecto basándonos en la arquitectura técnica y los avances realizados.

## Fase 1: Andamiado y Core Architecture ✅

El esqueleto del plugin está totalmente operativo siguiendo los estándares PSR-4.

- [x] Configuración de Composer y PSR-4 Autoloading.
- [x] Entry point del plugin (`wp-api-creator.php`).
- [x] Dependency Injection Container (`src/Core/Container.php`).
- [x] Inicialización del Plugin (`src/Core/Plugin.php`).
- [x] Detección de CPTs nativos y custom (`src/Introspection/CptScanner.php`).
- [x] Escaneo de Meta Fields e integración con ACF (`src/Introspection/MetaScanner.php`).
- [x] Escaneo de Taxonomías (`src/Introspection/TaxonomyScanner.php`).
- [x] Evolución del Config Builder (De Mock a Persistencia Real en DB).

## Fase 2: Enrutamiento y Controladores Base ✅

Los endpoints dinámicos son funcionales y devuelven datos reales filtrados.

- [x] Router Base (`src/Api/Router.php`).
- [x] Abstracción de Repositorio (`src/Domain/Repositories/DynamicQueryBuilder.php`).
- [x] Controlador de Colecciones GET.
- [x] Controlador de Entidad Única GET.
- [x] Controladores de Mutación POST/PATCH/DELETE.
- [x] Mapeador de Salida / Output Serializer.

## Fase 3: Seguridad y Gatekeeper ✅

Barrera de acceso granular implementada.

- [x] Integración con el sistema de roles de WP.
- [x] Clase Gatekeeper (`src/Permissions/Gatekeeper.php`).
- [x] Validaciones de permisos a nivel de Endpoint (GET/POST/DELETE).
- [x] Validaciones granulares a nivel de campo (Implementado hoy en Serializer).
- [x] Validaciones de Ownership.

## Fase 4: Autenticación Multi-Proveedor ✅ (Cerrada en 1.1.0)

La firma HS256 nunca estuvo pendiente de validación: estaba correctamente implementada desde el principio (`hash_equals`, comprobación de `nbf`, `exp` y formato). Lo que faltaba eran los controles alrededor, y eso es lo que cierra 1.1.0.

- [x] Middleware Pipeline Architecture.
- [x] Proveedor de API Keys ligadas a un usuario real, hasheadas con SHA-256, con caducidad y registro de último uso.
- [x] Endpoints de Generación de Tokens (POST /v1/auth/token).
- [x] Proveedor JWT con validación de emisor, versión de token y existencia del usuario.
- [x] Caducidad configurable de verdad (`jwt_expiration`), antes fijada a 24 h en el código.
- [x] Revocación por usuario, manual desde el dashboard y automática al cambiar contraseña o rol.
- [x] Eliminación del secreto de firma por defecto escrito en el código y de la clave maestra de desarrollo.
- [x] Enforcement real de `require_api_key`, con su interruptor en la interfaz.
- [x] Limitación de intentos fallidos por IP y por cuenta.
- [x] Suite de tests unitarios (PHPUnit + Brain Monkey).

## Fase 5: Ingesta de Medios Avanzada ✅

- [x] Endpoint segregado POST /v1/media.
- [x] Módulo de validación MIME y peso (`src/Media/MediaUploader.php`).
- [x] Asociación a Metacampos e Imágenes Destacadas (Bugfix realizado hoy en Serializer).

## Fase 6: Dashboard Administrativo SPA ✅

Interfaz visual reactiva para la gestión total.

- [x] Registro del menú en WP Admin.
- [x] Entorno de build React activo.
- [x] Endpoints REST Internos exclusivos (`/admin/*`).
- [x] UI: Pantalla General (Overview renovado con tarjetas).
- [x] UI: Editor visual de Endpoints (Filtros globales y labels amigables).
- [x] UI: Gestor de API Keys dedicado (Completo en pestaña propia).
- [x] Conexión real Admin UI -> Data Persistente.

## Fase 7: Auto-documentación (OpenAPI) y Optimización ✅

- [x] Generador de JSON Swagger 3.0 dinámico (`src/Schema/OpenApiBuilder.php`).
- [x] Refinamiento de Swagger (Caché y filtrado de rutas inactivas).
- [x] Endpoint `/v1/docs` y visor de Swagger UI integrado.
- [x] Pulido de errores de logs en el frontend (Real data en Logs view arreglada).
- [x] Object Cache multinivel (`wp_cache_*`) en `ConfigBuilder` y `FieldScanner`.
- [x] Limpieza de código PSR-12 en 26 archivos PHP.

## Fase 8: API de lectura completa ✅ (Cerrada en 1.2.0)

La cadena de las taxonomías tenía cuatro eslabones y solo existía el primero: `TaxonomyScanner` estaba escrito y jamás se instanciaba. Además, cinco parámetros de consulta estaban implementados en el repositorio y nunca llegaban a ejecutarse, de modo que `?search=x` devolvía la colección entera sin error.

- [x] Taxonomías en el catálogo de campos con clave cualificada `tax:{nombre}`, imposibilitando la colisión con una meta homónima.
- [x] Grupo «Taxonomías» en el editor de endpoints, oculto en los endpoints nativos de WordPress.
- [x] Emisión de términos bajo la clave `taxonomies`, leyendo la caché que `WP_Query` ya ceba.
- [x] Filtrado por taxonomía: OR dentro de una taxonomía, AND entre taxonomías.
- [x] `Api\CollectionArgs` como fuente única de los parámetros, consumida por Router, caché y generador de OpenAPI.
- [x] Cableado de `search`, `orderby`, `order`, `meta_key` y `meta_value`, con `enum` de metas expuestas y `400` ante cualquier valor no admitido.
- [x] Filtro por `slug`.
- [x] Resolución perezosa de los campos nativos: `the_content` deja de ejecutarse en endpoints que no exponen el contenido.
- [x] Caché de respuestas efectiva (`cache_time`), limitada a listados publicados, con migración a 0 y botón de purga.
- [x] Esquema OpenAPI y colección de Postman derivados de `CollectionArgs`, con test de paridad.

---

## Deuda conocida

Registrada con ubicación exacta en `docs/08_seguridad_rendimiento_escalabilidad.md`:

- [ ] Clave de caché de `Gatekeeper::$field_auth_cache` sin la configuración del endpoint. Hoy **no es alcanzable** —la propiedad es de instancia— pero **sigue siendo bloqueante para el refactor de inyección de dependencias**: un singleton la convertiría en fuga real.
- [ ] Swagger UI carga scripts de `unpkg.com` sin SRI.
- [ ] `OutputSerializer::$field_mappings` es estático y nunca se invalida.
- [ ] La ruta de elemento único resuelve los términos sin caché cebada: una consulta por taxonomía. Aceptable para un solo post, sin optimizar.
- [ ] Los permisos por campo no gobiernan las taxonomías (decisión, no omisión: `field_permissions` sobre una clave `tax:` no tiene efecto).

Fuera de alcance por decisión explícita: refresh tokens y `/auth/refresh`, lista negra de `jti`, scopes por API Key, suite de integración con `wp-env`, el refactor de inyección de dependencias, `_fields` por petición, `include`/`exclude` por IDs, `meta_query` con comparadores arbitrarios desde la URL y la escritura de términos vía POST/PATCH.

---

**Próximos objetivos prioritarios:** Suite de integración sobre un WordPress real —varios criterios de 1.2.0 quedaron como verificación manual por no tenerla— y saldar la deuda conocida antes de abordar el refactor de inyección de dependencias.
