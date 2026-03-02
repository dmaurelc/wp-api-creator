# CONTEXTO

Vamos a diseñar la arquitectura completa de un plugin profesional de WordPress.

El objetivo NO es generar código todavía.

El objetivo es generar DOCUMENTACIÓN TÉCNICA COMPLETA EN MARKDOWN para un plugin que convierta WordPress en una API personalizada configurable desde un dashboard propio.

Debe ser un documento técnico listo para usar como base de desarrollo real.

El documento debe estar estructurado profesionalmente como si fuera arquitectura de software de un producto comercial.

---

# OBJETIVO DEL PLUGIN

Crear un plugin que:

1. Permita exponer WordPress como API personalizada.
2. Tenga un dashboard administrativo propio.
3. Permita configurar:
   - Tipo de autenticación
   - Endpoints disponibles
   - Campos expuestos
   - Permisos por rol
   - Permisos de subida de imágenes
4. Detecte automáticamente:
   - CPTs creados por código
   - CPTs creados por plugins
   - Campos personalizados creados por:
     - ACF
     - JetEngine
     - Metabox
     - Campos nativos
     - register_meta
5. Genere automáticamente:
   - Documentación Swagger/OpenAPI
   - Rewrite rules dinámicos
6. Sea extensible y modular.

---

# REQUISITOS FUNCIONALES

El documento debe incluir:

## 1. Sistema de Autenticación Configurable

Debe soportar:

- JWT
- Application Passwords
- API Key personalizada
- OAuth2 (preparado para futuro)
- Token por usuario

Debe explicarse:

- Flujo de autenticación
- Middleware
- Seguridad
- Expiración
- Refresh tokens
- Revocación

---

## 2. Sistema de Endpoints Dinámicos

- CRUD por cada post type seleccionado
- Selección granular de campos
- Inclusión/exclusión de metacampos
- Filtros
- Ordenación
- Paginación
- Soporte para relaciones

Debe explicar:

- Cómo se registran los endpoints
- Cómo se interceptan requests
- Cómo se construyen dinámicamente
- Cómo se cachean

---

## 3. Detección Automática de CPT y Campos

Explicar cómo detectar:

- get_post_types()
- get_post_type_object()
- register_post_type global
- get_post_meta()
- register_meta()
- REST fields personalizados
- ACF fields
- JetEngine meta
- Taxonomías asociadas

Debe explicar:

- Estrategia de introspección
- Sincronización
- Cache de esquema

---

## 4. Sistema de Permisos por Rol

Debe permitir:

- Permitir solo lectura
- Permitir escritura
- Permitir subida de medios
- Permitir acceso por endpoint
- Permitir acceso por campo

Explicar:

- Integración con WP_Roles
- Filtros personalizados
- Matriz de permisos

---

## 5. Sistema de Subida de Imágenes

- Endpoint específico
- Validación MIME
- Límite de tamaño
- Asociación automática a post
- Control por rol
- Protección contra abuso

---

## 6. Generación Automática de Swagger

- Generación dinámica OpenAPI 3
- Basado en CPTs activos
- Basado en campos expuestos
- Actualización automática al cambiar configuración
- Endpoint /docs
- UI Swagger embebida en admin

Explicar:

- Cómo se genera el schema
- Cómo se cachea
- Cómo se invalida

---

## 7. Rewrite Rules Automáticas

Debe explicar:

- Registro dinámico de rewrite rules
- flush_rewrite_rules() controlado
- Prevención de conflictos
- Namespacing
- Estructura recomendada de rutas:
  /wp-custom-api/v1/{post_type}
  /wp-custom-api/v1/{post_type}/{id}

---

## 8. Dashboard Administrativo

Debe incluir:

- Página principal
- Gestión de autenticación
- Gestión de endpoints
- Gestión de permisos
- Estado del sistema
- Logs
- Rebuild schema

Explicar:

- Arquitectura UI
- Uso de Settings API
- Uso de REST interno
- Nonces
- Seguridad

---

## 9. Arquitectura Técnica del Plugin

Debe incluir:

- Estructura de carpetas recomendada
- Patrón arquitectónico (Modular / Service Layer)
- Separación de responsabilidades
- Dependency container
- Hooks y filtros
- Sistema de eventos
- Logging
- Sistema de cache

Ejemplo esperado:

/plugin-root
/core
/modules
/admin
/api
/auth
/schema
/permissions
/docs
/utils

Explicar responsabilidad de cada carpeta.

---

## 10. Seguridad

Debe incluir:

- Rate limiting
- Nonces
- Sanitización
- Validación
- Protección contra:
  - SQL injection
  - XSS
  - File abuse
  - Privilege escalation

---

## 11. Rendimiento

- Object cache
- Transients
- Lazy loading
- Indexación
- Minimizar consultas meta

---

## 12. Escalabilidad futura

Debe prever:

- Multi-site
- SaaS externo
- Exportación de configuración
- Versionado de API
- Compatibilidad con headless

---

# FORMATO DE RESPUESTA

Debe generar:

- Documento en Markdown
- Con títulos H1, H2, H3
- Diagramas explicativos en texto
- Flujo de arquitectura
- Ejemplos de endpoints
- Ejemplos de configuración
- Tabla de permisos
- Tabla de flujos

No debe generar código completo.
Puede incluir pequeños fragmentos ilustrativos si ayudan a explicar arquitectura.

Debe ser detallado, profesional y listo para producción.

---

# GUÍA DE USO

## 1. Métodos de Autenticación

El plugin soporta tres métodos principales para interactuar con la API:

### A. API Keys (Recomendado para Integraciones Externas)

1. Ve a **Ajustes > Gestión de API Keys**.
2. Genera una nueva clave y dale un nombre.
3. Incluye la clave en tus peticiones HTTP usando el header:
   `X-API-Key: tu_clave_generada`
4. **Seguridad**: Estas claves mapean permisos al primer usuario administrador del sitio por defecto.

### B. Application Passwords (Nativo de WordPress)

1. Crea una contraseña de aplicación en el perfil de tu usuario en WordPress.
2. Usa **Basic Auth** enviando `Usuario:Contraseña_de_aplicación` codificado en Base64.
3. Header: `Authorization: Basic [Base64]`

### C. JWT (JSON Web Tokens)

- Compatible con plugins estándar de JWT para WordPress.
- Ideal para aplicaciones móviles o SPAs donde el usuario debe loguearse.
- Header: `Authorization: Bearer [token]`

---

## 2. Herramientas para Desarrolladores

### Exportación a Postman

Para facilitar el desarrollo, el plugin genera automáticamente una colección de Postman basada en tu configuración actual.

1. Ve a **Ajustes > Herramientas de Desarrollador**.
2. Haz clic en **Descargar Colección**.
3. Importa el archivo JSON en Postman.
4. La colección incluirá:
   - Todas las rutas activas (Endpoints configurados y Rutas Globales).
   - Variables de entorno preconfiguradas (`base_url`, `api_key`).
   - Ejemplo de headers requeridos.

---

## 3. Preguntas Frecuentes

**¿Hacen falta otros tipos de autentificación?**
Actualmente, los métodos soportados cubren el 99% de los casos de uso (Server-to-Server, Apps, Integraciones Legacy). El plugin está preparado para extenderse a OAuth2 si fuera necesario en el futuro, pero para la mayoría de los proyectos, las API Keys y JWT son suficientes y más sencillos de implementar.

---

# NIVEL DE DETALLE

Alta profundidad técnica.
Orientado a desarrollador senior.
Nada superficial.
Nada genérico.
Nada resumido.

---

# OUTPUT

Generar el documento completo.
