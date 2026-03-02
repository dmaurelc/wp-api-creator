# Arquitectura: WP Custom API Creator

Esta es la documentación técnica completa para el desarrollo del plugin **WP Custom API Creator**, un producto diseñado para convertir WordPress en una API personalizada, configurable, escalable y segura desde un dashboard administrativo.

Este documento está estructurado de manera profesional, sirviendo como guía definitiva (Software Architecture Document) para un equipo de desarrolladores senior. Su objetivo es delimitar responsabilidades, establecer convenciones técnicas y guiar el desarrollo de los diferentes módulos del producto.

---

## Tabla de Contenidos

1. [Arquitectura Técnica General](./01_arquitectura_tecnica.md)

   - Patrones de diseño (`Service Layer`, `Dependency Injection`).
   - Estructura de carpetas recomendada.
   - Ciclo de vida de una petición (Request Lifecycle).

2. [Sistema de Autenticación](./02_sistema_autenticacion.md)

   - Proveedores soportados (JWT, Application Passwords, API Keys, OAuth2).
   - Middleware de autenticación y flujo de tokens.

3. [Endpoints Dinámicos y Rewrite Rules](./03_endpoints_dinamicos_y_rewrites.md)

   - Motor de registro dinámico de endpoints custom (ej. `/wp-custom-api/v1/{post_type}`).
   - CRUD configurable y consultas avanzadas (Filtros, paginación, relaciones).
   - Gestión inteligente del flujo `flush_rewrite_rules()`.

4. [Detección de CPTs y Campos (Introspección)](./04_deteccion_cpt_y_campos.md)

   - Engine de escaneo de taxonomías y CPTs (nativos y plugins de terceros).
   - Intercepción y mapeo de Custom Fields (ACF, JetEngine, Metabox, nativos).

5. [Sistema de Permisos (Gatekeeper)](./05_permisos_y_autorizacion.md)

   - Integración con base `WP_Roles`.
   - Control de acceso granular (ACL) a nivel de endpoint, método (Leectura/Escritura) y por campo.

6. [Sistema de Subida de Imágenes](./06_subida_imagenes.md)

   - Endpoints especializados para ingesta de medios.
   - Validaciones estrictas de MIME-types, peso y control por rol.

7. [Generación de Swagger/OpenAPI y Dashboard](./07_swagger_y_dashboard.md)

   - Construcción al vuelo de la especificación OpenAPI 3.0 basada en endpoints activos.
   - Arquitectura de la interfaz administrativa (Dashboard UI).

8. [Seguridad, Rendimiento y Escalabilidad](./08_seguridad_rendimiento_escalabilidad.md)
   - Protección contra inyecciones y escalada de privilegios.
   - Estrategias de caché (Object Cache, Transients).
   - Preparación para entornos Headless, Multi-site y SaaS.

---

_Nota para el equipo de desarrollo:_ Lee [Arquitectura Técnica](./01_arquitectura_tecnica.md) antes de comenzar la implementación de cualquier servicio periférico. El respeto a los límites de abstracción definidos es mandatario para el éxito del producto a largo plazo.
