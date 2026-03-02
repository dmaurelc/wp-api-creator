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

## Fase 4: Autenticación Multi-Proveedor 🟡 (En Refinamiento)

- [x] Middleware Pipeline Architecture.
- [x] Proveedor de API Keys (Gestión múltiple en UI completa hoy).
- [x] Endpoints de Generación de Tokens (POST /v1/auth/token).
- [ ] Proveedor JWT (Estructura base lista, pendiente pruebas finales de firma).
- [ ] Sistema de caducidad estricta.

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

---

**Próximos objetivos prioritarios:** Validar el sistema JWT completo (Fase 4) y preparar documentación final de usuario.
