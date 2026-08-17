# 3. Endpoints Dinámicos y Rewrite Rules

El corazón de este plugin es eliminar la rigidez de la WP REST API estándar, generando rutas semánticas que expongan sólo los datos configurados, inyectando meta datos y manejando CRUD completo sobre Post Types elegidos.

## 1. El concepto de "API Personalizada"

En lugar de consumir `/wp/v2/propiedades` e infectar la respuesta con datos basura de `_links`, `_embedded`, `guid`, `ping_status` u otros metacampos polucionados, el sistema dispondrá de un Namespace Dedicado: `/wp-custom-api/v1/`.

En el dashboard, el usuario habilita qué CPTs (Custom Post Types) quiere convertir en "Endpoints".
Por cada uno de ellos, se genera a nivel de `WP_REST_Server` o base `Rewrite_Rules` el CRUD estándar.

## 2. Abstracción del CRUD

Asumamos el slug activado `{resource}` (e.g. `properties`):

| Método      | Endpoint              | Acción        | Contexto                         |
| ----------- | --------------------- | ------------- | -------------------------------- |
| `GET`       | `/v1/{resource}`      | Colección     | Lista filtrable, paginable       |
| `GET`       | `/v1/{resource}/{id}` | Único         | Elemento específico              |
| `POST`      | `/v1/{resource}`      | Creación      | Insera un post y metas asociadas |
| `PATCH/PUT` | `/v1/{resource}/{id}` | Actualización | Edición parcial o total          |
| `DELETE`    | `/v1/{resource}/{id}` | Borrado       | Envía a papelera o Force Delete  |

### 2.1 Peticiones GET: Colecciones y Consultas Dinámicas

Los endpoints de GET implementan mapeo avanzado hacia `WP_Query`:

- **Paginación Mágica:** Parámetros como `?page=2&limit=20`. Retorna estructura estricta:
  ```json
  {
    "data": [ ... ],
    "meta": {
      "total_items": 150,
      "total_pages": 8,
      "current_page": 2
    }
  }
  ```
- **Parámetros aceptados.** La lista canónica vive en `Api\CollectionArgs::for_endpoint()`, y de ahí la consumen el Router al registrar la ruta, la caché de respuestas al construir su clave y el generador de OpenAPI al documentarla. Duplicarla es lo que dejó cinco parámetros documentados sin efecto hasta 1.2.0.

  | Parámetro | Traducción a `WP_Query` |
  |---|---|
  | `page`, `limit` | `paged`, `posts_per_page` (tope 100) |
  | `status` | `post_status`, contrastado contra capacidades |
  | `slug` | `name` |
  | `search` | `s` |
  | `orderby`, `order` | `orderby`, `order`, acotados por `DynamicQueryBuilder::ALLOWED_ORDERBY` |
  | `meta_key` + `meta_value` | `meta_query` con `compare = '='`; el `enum` solo admite metas expuestas |
  | Nombre de cada taxonomía expuesta | `tax_query` por `slug`, OR dentro y AND entre taxonomías |

  Una taxonomía cuyo nombre coincida con cualquiera de los parámetros anteriores **no** se convierte en filtro. La exclusión vive en `CollectionArgs::filterable_taxonomies()` y no en cada consumidor: declarar el argumento y traducirlo a la consulta tienen que decidirse con el mismo criterio. Separarlos fallaba en silencio —una taxonomía `status` no llegaba a declarar su parámetro, pero sí entraba en la `tax_query` con el valor por defecto `publish`, de modo que el listado exigía ese término y salía vacío sin que el cliente hubiese enviado nada.

- **Nada se ignora en silencio.** Un valor fuera del `enum` devuelve `400 rest_not_in_enum` antes de llegar al controlador, y `meta_key` sin `meta_value` devuelve `400`.

- **`_filter[]` y `_include` no existen.** Aparecían en el diseño original; `_include` llegó a declararse sin hacer nada y se retiró en 1.1.0. Exponer comparadores arbitrarios desde la URL sigue fuera de alcance.

- **`_fields` es de WordPress, no de este plugin**, y recorta las claves de primer nivel: con el envoltorio `{data, meta}` deja la respuesta inservible. No se usa.

## 3. Control Granular de Retorno (Data Mapping)

Al devolver una entidad en JSON, se pasan todos los elementos de base y de metas por un transformador (`DataMapper`).
El administrador de UI debió haber definido previamente para el CPT "Propiedad" qué campos son expuestos. Si el campo `precio_compra_interno` de ACF no fue tildado, este serializador de salida (Output Schema) lo destruye de la respuesta.

El esquema base es agnóstico del core:

```json
{
  "id": 105,
  "title": "Apartamento Centro",
  "content": "<p>Hermoso...</p>",
  "slug": "apartamento-centro",
  "date": "2023-01-01T14:00:00+00:00",
  "modified": "2023-01-02T10:00:00+00:00",
  "featured_media": { "id": 87, "url": "https://ejemplo.test/foto.jpg" },
  "taxonomies": {
    "ciudad": [{ "id": 12, "name": "Madrid", "slug": "madrid" }],
    "tipo":   [{ "id": 4,  "name": "Dúplex", "slug": "duplex" }]
  },
  "fields": {
    "acf": { "precio": 500000, "habitaciones": 3 }
  }
}
```

Notas sobre la forma real:

- Las claves nativas van en el primer nivel, con los nombres de la configuración (`date`, `modified`), y **solo aparecen las expuestas**. Cada una se resuelve únicamente si el endpoint la incluye: un endpoint que solo devuelve `title` no paga el renderizado del contenido.
- `taxonomies` agrupa por taxonomía y devuelve una lista de términos con `id`, `name` y `slug`. La clave se omite si el endpoint no marcó ninguna; una taxonomía sin términos en esa entrada devuelve lista vacía.
- `fields` agrupa las metas por su origen (`acf`, `metabox`, `jetengine`, `registered_meta`…), no en plano.
- Las taxonomías se guardan en `exposed_fields` con la clave cualificada `tax:{nombre}`. El prefijo evita que una taxonomía y una meta con el mismo nombre compartan entrada en el mapa de orígenes, donde la última escritura ganaba y dejaba a la otra sin emitir.

## 4. Gestión de Rewrite Rules Automáticas (Routing Interno)

Sobrecargar WordPress con llamadas constantes a `flush_rewrite_rules()` es infame para el rendimiento y causa caídas (502/504) o DB locks.

Nuestra arquitectura lo maneja así:

1. **Delegación a REST Server:** Se prefiere el uso nativo de `register_rest_route()` dentro del handler maestro `rest_api_init`. Este acercamiento previene reescribir `.htaccess` o ensuciar las reglas internas (donde colapsan con plugins de terceros).
2. **Dynamic Route Registration:**
   ```php
   // Pseudo-códgio de Api/Router.php
   add_action('rest_api_init', function() {
       $active_endpoints = ConfigBuilder::getActiveEndpoints();
       foreach($active_endpoints as $ept) {
           register_rest_route('wp-custom-api/v1', '/' . $ept->slug, [
               // Mapeos GET, POST, con sus respectivos ACL middlewares
           ]);
       }
   });
   ```
3. **Control de Conflictos:** Si el slug asignado en nuestro plugin hace colisión con el de una página, prevalece nuestro namespace por jerarquía restrictiva de expresión regular.

### Opcional: Rewrite Puro para Enrutamiento Extremo

Si se requiere abandonar `WP_REST_Server`, se inyectarán reglas empleando `add_rewrite_rule()` hacia una variable query (e.g. `index.php?wpcapi_entrypoint=1&wpcapi_route=$matches[1]`). Un interceptor temprano en `template_redirect` tomará control del stream completo, enviando buffers JSON directos e invocando `exit;`. Esto se evalúa si se busca rendimiento ultra-raw (`~30ms` TTFB frente a los `~150ms` de `WP_REST`), pero pierde integraciones nativas. Se recomienda la fase nativa sobre `rest_api_init` para versionados estables.
