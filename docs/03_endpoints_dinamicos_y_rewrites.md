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
- **Filtros Flexibles:** Soporte natural para meta-filtros: `?_filter[precio_min]=400000`, `?_filter[status]=active`.
- **Relaciones Expandibles:** Parámetro `?_include=agente_inmobiliario`. En lugar de devolver el simple ID del autor/relación, incrusta el objeto relacional.

## 3. Control Granular de Retorno (Data Mapping)

Al devolver una entidad en JSON, se pasan todos los elementos de base y de metas por un transformador (`DataMapper`).
El administrador de UI debió haber definido previamente para el CPT "Propiedad" qué campos son expuestos. Si el campo `precio_compra_interno` de ACF no fue tildado, este serializador de salida (Output Schema) lo destruye de la respuesta.

El esquema base es agnóstico del core:

```json
{
  "id": 105,
  "title": "Apartamento Centro",
  "slug": "apartamento-centro",
  "dates": {
    "created_at": "2023-01-01T14:00:00Z",
    "modified_at": "2023-01-02T10:00:00Z"
  },
  "content": "<p>Hermoso...</p>",
  "fields": {
    "precio": 500000,
    "habitaciones": 3,
    "caracteristicas": ["WIFI", "Piscina"]
  },
  "taxonomies": {
    "city": "Madrid",
    "type": "Duplex"
  }
}
```

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
