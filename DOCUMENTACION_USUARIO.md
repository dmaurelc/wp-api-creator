# Manual de Usuario - WP Custom API Creator

Bienvenido al manual de uso de **WP Custom API Creator**, un plugin diseñado para transformar tu instalación de WordPress en un headless CMS altamente configurable mediante el uso del Dashboard de administración.

Este documento explica cómo configurar el plugin y cómo consumir la API correctamente de acuerdo a todas las características implementadas.

---

## 1. Configuración Inicial y Namespace Personalizado

Al instalar y activar el plugin, dirígete al panel de configuración en el menú de WordPress.
Encontrarás la opción para establecer un **Namespace Global**.

Este namespace es la base de todas tus rutas de la API, reemplazando la base estática de WordPress.

- **Valor por Defecto:** `wp-custom-api/v1`
- Si defines el namespace como `miapp/v1`, todas tus llamadas a la API deberán dirigirse a:
  `https://tudominio.com/wp-json/miapp/v1/`

---

## 2. Rutas Dinámicas (Endpoints de CPTs)

El plugin inspecciona dinámicamente tu base de datos y plugins activos (como ACF, JetEngine, MetaBox y Flavor Real Estate) instalando rutas de API REST correspondientes a tus endpoints configurados. Las características incluyen:

- **Listar Recursos (GET):** `/wp-json/<namespace>/<resource>`
- **Obtener un Recurso (GET):** `/wp-json/<namespace>/<resource>/<id>`
- **Crear (POST), Modificar (PATCH/PUT) y Eliminar (DELETE):** Siguen la convención estándar REST apuntando al recurso o ID.

### Parámetros Avanzados de Consulta (Query Builder)

Al solicitar un CPT (Ej: propiedades), tienes acceso al sistema sofisticado de query:

- `?limit=10&page=2` : Paginación tradicional.
- `?orderby=title&order=ASC`: Ordenamiento por campo particular.
- `?search=casa`: Agrega una funcionalidad de búsqueda full-text a los títulos de los post o su contenido.
- `?meta_key=precio&meta_value=500000`: Búsqueda de valores meta dinámicos.

---

## 3. Autenticación y Seguridad

Debido al profundo enfoque en flexibilidad y robustez en entornos React/Vue y aplicaciones móviles, el plugin expone una autenticación tipo Pipeline, permitiendo autenticarse en los endpoints usando distintas opciones en prioridad descendente.

Debes pasar las credenciales en la cabecera (Header) de tu petición HTTP:

### A) Application Passwords (Basic Auth Nativo de WordPress)

Si usas _Application Passwords_ provisto por WordPress nativamente.

- **Header**: `Authorization: Basic <base64_encode(username:app_password)>`

### B) JSON Web Tokens (JWT)

Ideal para implementaciones de UI de Front-end en SPA donde deseas un ciclo de vid controlada. Los JWT implementan directivas fuertes de seguridad `nbf` (No usar antes) y expiración en base al entorno de firma.

- **Header**: `Authorization: Bearer <tu_jwt_token>`

### C) API Keys

Pensadas para servidores B2B fijos que solo necesitan enviar y recibir datos de un CPT.

- **Header**: `X-API-Key: <tu_api_key>`

Cada clave se crea desde **Autenticación > API Keys** asociada a un usuario de WordPress, del que **hereda los permisos**: asigna una cuenta con el rol mínimo que necesite la integración. Admite fecha de caducidad y el listado muestra su último uso, lo que permite detectar claves muertas.

La clave en claro se muestra **una única vez**, al crearla; el plugin solo almacena su hash. Si se pierde, hay que revocarla y generar otra.

> Las claves creadas antes de la versión 1.1.0 aparecen marcadas como obsoletas y no autentican.

### D) Cerrar tu API

En **Ajustes > Seguridad** puedes activar *Exigir credencial en tu namespace*. Con esa opción, cualquier petición a tus rutas sin credencial válida recibe un 401 aunque el endpoint esté configurado como público, y la documentación en `/docs` deja de ser accesible sin credencial. Está desactivada por defecto.

**Qué no cubre**: los endpoints nativos de WordPress (`/wp-json/wp/v2/...`). Los registra WordPress, no este plugin, y se rigen por sus propias reglas de acceso. Marcar una ruta como visible en «Rutas globales» solo la incluye en la documentación generada; no la expone ni la protege. Si necesitas cerrar también la API nativa, hace falta una solución específica para eso.

---

## 4. Introspección Automática (Compatibilidad Extrema)

Alineado con el ecosistema global de WordPress, los schemas de cada tabla y campo están soportados a nivel Meta de base de datos a través de una función de Scaneo Interno, dándole soporte a los siguientes:

- Campos Nativos de WP
- Advanced Custom Fields (ACF)
- **JetEngine:** Totalmente compatible. Los metacampos y opciones registrados con JetEngine serán descubiertos automáticamente y se generarán endpoints de API listos para su uso.
- **Meta Box:** Igual soporte total para los campos nativamente registrados mediante los hooks y arquitectura de MetaBox.io.

_Nota: La re-lectura del esquema de datos cuenta con una estrategia de Memoria Caché que agiliza el rendimiento para sitios con muchos metadatos y sub-niveles._

---

## 5. El Gatekeeper: Permisos a Nivel Granular

Nunca antes en un plugin se ha podido limitar operaciones de lectura, escritura o visión por _campo individual_.
El sistema "Gatekeeper" provee validaciones basadas en los Roles de Usuarios o visibilidad Pública.

Si tienes un CPT llamado `Empleado` y un JWT activo para el rol `Editor`:

- **¿Puede Ver y Modificar Empleados?:** Sí, el Gatekeeper valida la opción configurada frente a su JWT.
- **¿Puede ver el campo `sueldo_base`?:** Dependerá de las validaciones individuales. Puedes ocultar campos a roles enteros o usuarios no logueados con un rendimiento exquisito gracias a su optimización con memoria caché rápida (`fast-runtime cache`) la cuál evita consumir recursos SQL mientras la entidad valida la estructura JSON de la solicitud.
