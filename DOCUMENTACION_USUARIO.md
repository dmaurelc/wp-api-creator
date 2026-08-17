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

### Tiempo de caché

En **Ajustes** puedes fijar cuántos segundos se guarda la respuesta de un listado. `0` lo desactiva, y es el valor con el que arranca la versión 1.2.0 **aunque tuvieras otro guardado antes**: hasta ahora el ajuste no hacía nada, y activarlo debe ser una decisión consciente.

Qué se cachea y qué no:

- **Sí:** los listados de contenido publicado, incluidos los que llevan filtros por taxonomía, ordenación o paginación.
- **No:** la ruta de un elemento concreto (`/recurso/123`), las que piden un estado distinto de publicado y las que llevan `search`, `slug` o `meta_value`. Estos tres admiten cualquier texto, así que cachearlos permitiría a un visitante anónimo llenar la caché de entradas inútiles y expulsar las que sí se reutilizan.

La caché distingue por rol, así que un editor y un visitante anónimo nunca comparten respuesta. Se vacía sola al publicar, editar o borrar contenido, al cambiar términos o metadatos y al guardar la configuración; además tienes el botón **Purgar caché de respuestas** en Ajustes.

> Para que la caché ahorre trabajo entre visitas, el sitio necesita un object cache persistente (Redis o Memcached). Si no lo hay, el dashboard te avisa: el ajuste no romperá nada, pero tampoco servirá de mucho.

---

## 2. Rutas Dinámicas (Endpoints de CPTs)

El plugin inspecciona dinámicamente tu base de datos y plugins activos (como ACF, JetEngine, MetaBox y Flavor Real Estate) instalando rutas de API REST correspondientes a tus endpoints configurados. Las características incluyen:

- **Listar Recursos (GET):** `/wp-json/<namespace>/<resource>`
- **Obtener un Recurso (GET):** `/wp-json/<namespace>/<resource>/<id>`
- **Crear (POST), Modificar (PATCH/PUT) y Eliminar (DELETE):** Siguen la convención estándar REST apuntando al recurso o ID.

### Parámetros Avanzados de Consulta (Query Builder)

Al solicitar un CPT (Ej: propiedades), tienes acceso a estos parámetros. Un valor no admitido devuelve `400`: ningún parámetro se ignora en silencio.

| Parámetro | Ejemplo | Notas |
|---|---|---|
| `page`, `limit` | `?limit=10&page=2` | El máximo efectivo de `limit` es 100. |
| `status` | `?status=draft` | Los estados no públicos exigen capacidades sobre el tipo de contenido. |
| `orderby`, `order` | `?orderby=title&order=ASC` | `orderby` acepta `date`, `modified`, `title`, `menu_order` e `ID`; `order`, `ASC` y `DESC`. Distinguen mayúsculas: `?order=asc` devuelve `400`. |
| `search` | `?search=casa` | Búsqueda de texto sobre título y contenido. Es un `LIKE`: en catálogos grandes conviene un buscador dedicado. |
| `slug` | `?slug=duplex-en-providencia` | Devuelve la entrada con ese slug. |
| `meta_key`, `meta_value` | `?meta_key=precio&meta_value=500000` | Solo sobre metas **seleccionadas en el endpoint**. Van siempre juntos. `meta_value=0` es un valor válido. |
| Nombre de una taxonomía | `?ubicacion=providencia,las-condes` | Solo para las taxonomías marcadas en el endpoint. |

**Filtrado por taxonomía.** Cada taxonomía que marques en el editor del endpoint añade un parámetro con su nombre. Varios términos separados por coma se combinan con OR; varias taxonomías, con AND:

```
?ubicacion=providencia,las-condes&estado=en-venta
```

Devuelve las propiedades que estén en Providencia **o** Las Condes **y**, además, en venta. Un término inexistente devuelve una lista vacía, no un error.

**Términos en la respuesta.** Las taxonomías marcadas se devuelven dentro de cada elemento, bajo la clave `taxonomies`:

```json
{
  "id": 42,
  "title": "Dúplex en Providencia",
  "taxonomies": {
    "ubicacion": [{ "id": 7, "name": "Providencia", "slug": "providencia" }],
    "estado": [{ "id": 3, "name": "En venta", "slug": "en-venta" }]
  },
  "fields": { "acf": { "precio": "500000" } }
}
```

Un endpoint sin taxonomías marcadas no incluye la clave. Una entrada sin términos en una taxonomía marcada devuelve una lista vacía.

> **`_fields` no funciona en esta API.** WordPress reserva ese nombre y recorta las claves de primer nivel de la respuesta. Como estas rutas devuelven `{data, meta}`, `?_fields=id,title` devuelve `[]` y `?_fields=meta` devuelve solo la paginación. No es algo que introduzca 1.2.0: ocurre desde siempre. Los campos se eligen en el editor de endpoints.

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
