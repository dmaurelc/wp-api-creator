<?php

namespace WpApiCreator\Api;

use WpApiCreator\Domain\Repositories\DynamicQueryBuilder;
use WpApiCreator\Schema\FieldScanner;

/**
 * Fuente canónica de los parámetros que acepta el GET de una colección.
 *
 * La lista vivía dentro de un método `protected` del Router con un solo llamador. Tres
 * consumidores la necesitan —el propio Router al registrar la ruta, la caché de
 * respuestas para construir su clave y el generador de OpenAPI para documentarla— y dos
 * de ellos son estáticos: instanciar un `Router` desde ahí arrastraría el Gatekeeper, el
 * middleware de autenticación y una lectura de opciones solo para leer una lista.
 *
 * Duplicar la lista es lo que dejó cinco parámetros documentados sin efecto, así que esta
 * clase es `static` y sin dependencias a propósito.
 */
final class CollectionArgs
{
    /**
     * Argumentos que acepta la ruta de colección de un endpoint.
     *
     * @param array $config Configuración del endpoint.
     * @return array Mapa nombre => especificación en formato de argumento WP REST.
     */
    public static function for_endpoint(array $config): array
    {
        $args = self::plugin_args($config);

        foreach (self::filterable_taxonomies($config) as $taxonomy) {
            $args[$taxonomy] = [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => sprintf(
                    'Filtra por términos de %s. Varios separados por coma (OR).',
                    $taxonomy
                ),
            ];
        }

        return $args;
    }

    /**
     * Parámetros propios del plugin, sin las taxonomías.
     *
     * Sus nombres son los reservados: una taxonomía que coincida con cualquiera de ellos
     * no puede convertirse en filtro. La lista se deriva de aquí en los tres sitios que
     * la necesitan, en lugar de escribirse a mano en cada uno.
     *
     * @param array $config
     * @return array
     */
    private static function plugin_args(array $config): array
    {
        $args = [
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'sanitize_callback' => 'absint',
            ],
            'limit' => [
                'type' => 'integer',
                'default' => 10,
                'sanitize_callback' => 'absint',
            ],
            'status' => [
                'type' => 'string',
                'default' => 'publish',
                'enum' => DynamicQueryBuilder::ALLOWED_STATUSES,
                'description' => 'Estado de las entradas. Los estados no públicos exigen capacidades sobre el tipo de contenido.',
            ],
            'slug' => [
                'type' => 'string',
                'sanitize_callback' => [self::class, 'sanitize_slug'],
                'description' => 'Devuelve la entrada cuyo slug coincide.',
            ],
            'search' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'description' => 'Búsqueda de texto sobre título y contenido.',
            ],
            'orderby' => [
                'type' => 'string',
                'default' => 'date',
                'enum' => DynamicQueryBuilder::ALLOWED_ORDERBY,
                'description' => 'Criterio de ordenación.',
            ],
            'order' => [
                'type' => 'string',
                'default' => 'DESC',
                'enum' => ['ASC', 'DESC'],
                'description' => 'Sentido de la ordenación.',
            ],
        ];

        // `meta_key` solo acepta las metas que el endpoint expone. Declararla como string
        // libre convertiría la colección en un oráculo sobre metadatos internos: el
        // serializador se niega a *emitir* las metas con prefijo `_`, pero sin esta
        // restricción cualquiera podría *consultarlas* un valor por petición.
        $meta_keys = self::exposed_meta_keys($config);
        if (!empty($meta_keys)) {
            $args['meta_key'] = [
                'type' => 'string',
                'enum' => $meta_keys,
                'validate_callback' => [self::class, 'validate_meta_key'],
                'description' => 'Meta por la que filtrar. Exige `meta_value`.',
            ];
            $args['meta_value'] = [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [self::class, 'validate_meta_value'],
                'description' => 'Valor exacto que debe tener `meta_key`.',
            ];
        }

        return $args;
    }

    /**
     * Taxonomías expuestas que además pueden usarse como filtro.
     *
     * Una taxonomía cuyo nombre coincida con un parámetro del plugin queda fuera. La
     * comprobación se hace **aquí y no en cada consumidor**: declarar el argumento y
     * traducirlo a la consulta son dos decisiones que deben tomarse con el mismo
     * criterio.
     *
     * Separarlas tenía consecuencias silenciosas. Una taxonomía llamada `status` no
     * llegaba a declarar su parámetro —lo impedía el `enum` de estado— pero sí entraba en
     * la traducción a `tax_query`, tomando el valor por defecto `publish` que WordPress
     * rellena. Resultado: **todas** las peticiones al listado exigían el término `publish`
     * en esa taxonomía y la colección salía vacía, sin error y sin que el cliente hubiese
     * enviado nada. Lo mismo con `orderby`, `order`, `page` y `limit`, que también
     * declaran valor por defecto.
     *
     * @param array $config
     * @return string[]
     */
    public static function filterable_taxonomies(array $config): array
    {
        $reserved = array_keys(self::plugin_args($config));

        return array_values(array_filter(
            self::exposed_taxonomies($config),
            function ($taxonomy) use ($reserved) {
                return !in_array($taxonomy, $reserved, true);
            }
        ));
    }

    /**
     * Recoge de la petición el valor de cada parámetro declarado.
     *
     * Es la **única** lectura de parámetros del camino de colección, y también la única
     * fuente de la clave de caché. Esa unicidad es estructural, no una convención: un
     * parámetro que afecte al resultado entra en los dos sitios a la vez o en ninguno.
     * El fallo histórico del plugin fue el contrario —parámetros que llegaban al
     * repositorio sin estar declarados— y ningún test sobre la clave lo habría detectado.
     *
     * Los valores llegan ya saneados por WordPress a través de `sanitize_callback`, y
     * ordenados por nombre para que dos peticiones equivalentes produzcan el mismo array.
     *
     * @param \WP_REST_Request $request
     * @param array            $config
     * @return array
     */
    public static function collect($request, array $config): array
    {
        $values = [];

        foreach (self::for_endpoint($config) as $name => $spec) {
            $value = $request->get_param($name);

            if ($value === null || $value === '') {
                continue;
            }

            $values[$name] = $value;
        }

        ksort($values);

        return $values;
    }

    /**
     * Traduce los valores recogidos a los argumentos que espera el repositorio.
     *
     * Los filtros de taxonomía se agrupan bajo `taxonomies` porque sus nombres son
     * dinámicos y el repositorio no puede distinguirlos de los parámetros del plugin.
     *
     * @param array $values Salida de `collect()`.
     * @param array $config
     * @return array
     */
    public static function to_query_args(array $values, array $config): array
    {
        $reserved = array_keys(self::plugin_args($config));

        $args = array_intersect_key($values, array_flip($reserved));

        $taxonomies = [];
        foreach (self::filterable_taxonomies($config) as $taxonomy) {
            if (isset($values[$taxonomy])) {
                $taxonomies[$taxonomy] = $values[$taxonomy];
            }
        }
        $args['taxonomies'] = $taxonomies;

        return $args;
    }

    /**
     * Taxonomías que el endpoint expone y que siguen registradas en su tipo de contenido.
     *
     * Se resuelve con `get_object_taxonomies()`, que es una lectura del registro en
     * memoria. Usar `FieldScanner` aquí costaría un `SELECT DISTINCT meta_key` sobre
     * `wp_postmeta` y un escaneo de grupos ACF en cada petición REST del sitio, porque
     * los argumentos se declaran en `rest_api_init`.
     *
     * Contrastar contra el registro cierra además la ventana de la caché de cinco minutos
     * del escáner. `get_object_taxonomies()` devuelve las registradas sean o no públicas,
     * así que la visibilidad se comprueba aparte: el editor nunca ofrece las privadas,
     * pero una taxonomía marcada cuando era pública sigue guardada en `exposed_fields`
     * si el plugin que la registra la cierra después. Sin esta comprobación, un anónimo
     * podría seguir enumerando qué entradas llevan qué término interno.
     *
     * @param array $config
     * @return string[]
     */
    public static function exposed_taxonomies(array $config): array
    {
        $exposed_fields = $config['exposed_fields'] ?? [];
        if (empty($exposed_fields) || !is_array($exposed_fields)) {
            return [];
        }

        $registered = get_object_taxonomies((string) ($config['post_type'] ?? ''), 'names');
        if (!is_array($registered)) {
            $registered = [];
        }

        $exposed = [];
        foreach ($exposed_fields as $key) {
            $key = (string) $key;
            if (strpos($key, FieldScanner::TAXONOMY_PREFIX) !== 0) {
                continue;
            }

            $name = substr($key, strlen(FieldScanner::TAXONOMY_PREFIX));
            if (in_array($name, $registered, true) && self::taxonomy_is_public($name)) {
                $exposed[] = $name;
            }
        }

        return array_values(array_unique($exposed));
    }

    /**
     * Comprueba que la taxonomía siga siendo pública.
     *
     * Otra lectura del registro en memoria: mismo coste que `get_object_taxonomies()`.
     *
     * @param string $taxonomy
     * @return bool
     */
    public static function taxonomy_is_public(string $taxonomy): bool
    {
        $object = get_taxonomy($taxonomy);

        return is_object($object) && !empty($object->public);
    }

    /**
     * Metas que el endpoint expone y por las que, por tanto, se puede filtrar.
     *
     * Quedan fuera los campos nativos —que no viven en la tabla de metadatos— las claves
     * de taxonomía y cualquier meta con prefijo `_`: el serializador bloquea su emisión,
     * así que permitir consultarlas abriría por la puerta de atrás lo que la puerta
     * principal cierra.
     *
     * @param array $config
     * @return string[]
     */
    public static function exposed_meta_keys(array $config): array
    {
        $exposed_fields = $config['exposed_fields'] ?? [];
        if (empty($exposed_fields) || !is_array($exposed_fields)) {
            return [];
        }

        $keys = [];
        foreach ($exposed_fields as $key) {
            $key = (string) $key;

            if ($key === '' || strpos($key, '_') === 0) {
                continue;
            }
            if (strpos($key, FieldScanner::TAXONOMY_PREFIX) === 0) {
                continue;
            }
            if (isset(FieldScanner::NATIVE_FIELDS[$key])) {
                continue;
            }

            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    /**
     * Sanea el filtro por slug.
     *
     * No se pasa `'sanitize_title'` directamente: WordPress invoca el callback con
     * `($value, $request, $param)` y esa función declara tres parámetros, de modo que la
     * petición entraría como texto de reserva —devolviendo un objeto si el slug queda
     * vacío— y el contexto dejaría de ser `'save'`, saltándose `remove_accents()`. Los
     * slugs reales de WordPress sí pasan por ahí, así que `?slug=diseño-web` no
     * encontraría la entrada guardada como `diseno-web`.
     *
     * @param mixed $value
     * @return string
     */
    public static function sanitize_slug($value): string
    {
        return (string) sanitize_title((string) $value);
    }

    /**
     * Valida `meta_key` y exige que venga acompañada de `meta_value`.
     *
     * Declarar un `validate_callback` propio sustituye al validador por defecto de
     * WordPress, así que hay que invocarlo explícitamente para que el `enum` siga
     * rechazando las claves no expuestas.
     *
     * Sin esta comprobación, `?meta_key=precio` a solas se ignoraría en silencio: el
     * cliente recibiría la colección entera creyendo que filtró.
     *
     * @param mixed            $value
     * @param \WP_REST_Request $request
     * @param string           $param
     * @return true|\WP_Error
     */
    public static function validate_meta_key($value, $request, $param)
    {
        $validated = rest_validate_request_arg($value, $request, $param);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $meta_value = $request->get_param('meta_value');
        if ($meta_value === null || $meta_value === '') {
            return new \WP_Error(
                'rest_missing_meta_value',
                'El parámetro meta_key exige un meta_value con el que comparar.',
                ['status' => 400]
            );
        }

        return true;
    }

    /**
     * Exige que `meta_value` venga acompañado de `meta_key`.
     *
     * El caso contrario ya devuelve 400. Sin esta simetría, quien se equivoque al
     * escribir el nombre de `meta_key` recibiría la colección entera creyendo que filtró
     * —exactamente el fallo que esta versión corrige.
     *
     * @param mixed            $value
     * @param \WP_REST_Request $request
     * @param string           $param
     * @return true|\WP_Error
     */
    public static function validate_meta_value($value, $request, $param)
    {
        $validated = rest_validate_request_arg($value, $request, $param);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $meta_key = $request->get_param('meta_key');
        if ($meta_key === null || $meta_key === '') {
            return new \WP_Error(
                'rest_missing_meta_key',
                'El parámetro meta_value exige un meta_key sobre el que comparar.',
                ['status' => 400]
            );
        }

        return true;
    }
}
