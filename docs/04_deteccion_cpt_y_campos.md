# 4. Detección Automática de CPT y Campos (Engine de Introspección)

Para que el Dashboard funcione intuitivamente y el usuario sólo tenga que "marcar casillas", la plataforma necesita comprender qué estructuras de datos existen en la instalación de WordPress.

Dada la heterogeneidad del ecosistema WP (CPTs creados por código manuales, Metaboxes, plugins como ACF, JetEngine, etc.), el módulo `/Introspection` es la base del entendimiento.

## 1. Detección de Post Types y Taxonomías

El **`CptScanner`** corre durante configuraciones en el Backend o en procesos de pre-caché. Requiere hookearse en pasajes tardíos (ej. `init` con prioridad altísima o `wp_loaded`) garantizando que otros themes y plugins ya hayan registrado sus entidades.

- Intercepta `get_post_types(['public' => true], 'objects')`.
- Determina si son tipos nativos (posts, pages) o extendidos.
- Filtra entidades del core no relevantes (ej. `nav_menu_item`, `acf-field-group`).
- Paralelamente el **`TaxonomyScanner`** mapea mediante `get_object_taxonomies()` qué categorías/etiquetas/custom_tax pertenecen al CPT iterado.

## 2. Descubrimiento Profundo de Campos (Field Scanner)

Conocer los atributos nativos (title, date, content) es fácil, pero los meta-datos (`wp_postmeta`) son un abismo. El sistema despliega un "Multi-Strategy Scanner":

### Estrategia A: Integraciones Especializadas Duras (Hard Hooks)

Se aplican drivers (clases Adapters) específicos si detectan que el plugin correspondiente está activo:

- **Adaptador ACF (Advanced Custom Fields):**
  Consulta directamente la función local nativa de registro de grupos `acf_get_field_groups([ 'post_type' => 'xyz' ])`, y luego desglosa `acf_get_fields()`. Esto permite no solo descubrir el "nombre de la key", sino su **tipo estructurado** (ej. Image, Repeating Matrix, Repeater, Relationship), clave principal para construir un esquema Swagger OpenAPI fidedigno en el futuro.

- **Adaptador JetEngine / Metabox:**
  Misma filososofía, llamando a los registros internos estáticos de dichos plugins para extraer sus metakey registradas.

### Estrategia B: Lectura de `register_meta` nativa

Revisa la REST API fields table interna, detectando los campos añadidos por programadores que hicieron las cosas bajo estándares nativos con `register_post_meta()`.

### Estrategia C: Sampleo Heurístico (Base de Datos Directa)

No todos los desarrolladores registran metas formalmente. Algunos solo inyectan a la BD usando `update_post_meta($id, 'my_secret_key', 'val')` de forma "silenciosa".
Para atrapar estos campos huérfanos, cuando el usuario fuerza el escáner manual, la estrategia aplica un sampleo SQL crudo:

```sql
-- Pseudocódigo de sampleo
SELECT DISTINCT meta_key
FROM wp_postmeta
WHERE post_id IN (
    SELECT ID FROM wp_posts WHERE post_type = %s ORDER BY ID DESC LIMIT 50
)
AND meta_key NOT LIKE '\_%' -- (oculta prefijos)
```

Muestra al admin como "Campos Húerfanos Detectados" para que el usuario documente su tipado (String, Number, Bool) de forma manual.

## 3. Sincronización y Caché de Esquema

Hacer escaneos SQL complejos o llamadas a funciones de 3ros pesadas por cada carga es letal.

- **Estado Inmutable Relativo:** El schema del WordPress descubierto se consolida en un Transient a largo plazo o en un registro de DB propio (`wp_options` clave `wpcapi_schema_blueprint`).
- **Invalidación Smart:** Si se dispara la acción nativa `registered_post_type` y el hash resultante mutó, o si un admin presiona el botón "Refrescar Schema" en el Panel (Rebuild Schema), la capa de validación entra en accion para actualizar la "Biblia" de mapeo que usará el _EndpointBuilder_ e iterado por el _SwaggerGenerator_.
