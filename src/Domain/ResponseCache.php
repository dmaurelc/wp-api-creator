<?php

namespace WpApiCreator\Domain;

/**
 * Caché de respuestas de colección, gobernada por el ajuste `cache_time`.
 *
 * Alcance deliberadamente estrecho: solo colecciones y solo resultados publicados.
 *
 * - La ruta de elemento único no declara argumentos —el `id` viene del patrón de la
 *   ruta, no de la lista de parámetros— así que una clave derivada de esa lista haría
 *   que dos recursos distintos compartieran entrada.
 * - El acceso a contenido no publicado se decide por post (`current_user_can('edit_post')`)
 *   y por autor (`author__in`). Ninguna clave basada en roles puede representar eso, así
 *   que forzando `status = publish` el caso personal deja de existir.
 * - `search`, `slug` y `meta_value` son texto libre: un anónimo generaría claves
 *   ilimitadas, desalojaría las entradas legítimas y convertiría la caché en un
 *   amplificador de denegación de servicio.
 */
final class ResponseCache
{
    /** Grupo de object cache donde viven las respuestas. */
    const CACHE_GROUP = 'creator_responses';

    /**
     * Contadores que invalidan la caché sin recorrer entradas.
     *
     * Viven en una option propia con `autoload = no`, nunca dentro de la configuración:
     * escribir el documento de configuración desde el camino de una petición podría
     * revertir cambios que un administrador acaba de guardar.
     */
    const OPTION_VERSIONS = 'wp_api_creator_cache_versions';

    /** Cambio en la configuración de endpoints. */
    const VERSION_CONFIG = 'config';

    /** Alta, cambio o borrado de metadatos de una entrada. */
    const VERSION_META = 'meta';

    /** Purga manual desde el dashboard. */
    const VERSION_PURGE = 'purge';

    /**
     * Una petición que toca cien metas no debe escribir cien veces la option.
     *
     * @var bool
     */
    private static bool $meta_bumped = false;

    /**
     * Segundos de vida configurados. Cero desactiva la caché por completo.
     *
     * @return int
     */
    public static function ttl(): int
    {
        return max(0, (int) (ConfigBuilder::get_global_settings()['cache_time'] ?? 0));
    }

    /**
     * Parámetros de valor libre que expulsan la petición de la caché.
     *
     * `page` también es libre, pero se autolimita: más allá de los resultados que existen
     * no hay nada que consultar. Estos tres no tienen ese techo.
     */
    const UNBOUNDED_PARAMS = ['search', 'slug', 'meta_value'];

    /**
     * Decide si una petición de colección puede servirse desde caché.
     *
     * @param array $params Parámetros ya recogidos y saneados.
     * @param int   $ttl
     * @return bool
     */
    public static function is_cacheable(array $params, int $ttl): bool
    {
        if ($ttl <= 0) {
            return false;
        }

        if (($params['status'] ?? 'publish') !== 'publish') {
            return false;
        }

        foreach (self::UNBOUNDED_PARAMS as $param) {
            if (isset($params[$param])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Construye la clave de una respuesta de colección.
     *
     * Los parámetros entran como un único hash del array que el controlador va a usar
     * para consultar: no pueden divergir porque son el mismo array.
     *
     * Los roles forman parte de la clave porque el Gatekeeper filtra metacampos por rol
     * incluso sobre contenido publicado. No hace falta el ID de usuario: con `publish`
     * forzado no hay capacidades por entrada ni filtro por autor en juego.
     *
     * @param array $config Configuración del endpoint.
     * @param array $params Parámetros recogidos.
     * @return string
     */
    public static function key(array $config, array $params): string
    {
        $versions = self::versions();

        $parts = [
            'blog'    => (int) get_current_blog_id(),
            'slug'    => (string) ($config['slug'] ?? $config['post_type'] ?? ''),
            'params'  => self::hash($params),
            'roles'   => implode(',', self::current_roles()),
            'posts'   => (string) wp_cache_get_last_changed('posts'),
            'terms'   => (string) wp_cache_get_last_changed('terms'),
            'config'  => (int) ($versions[self::VERSION_CONFIG] ?? 0),
            'meta'    => (int) ($versions[self::VERSION_META] ?? 0),
            'purge'   => (int) ($versions[self::VERSION_PURGE] ?? 0),
        ];

        return 'collection_' . self::hash($parts);
    }

    /**
     * Lee una respuesta cacheada.
     *
     * @param string $key
     * @return array|null
     */
    public static function get(string $key): ?array
    {
        $cached = wp_cache_get($key, self::CACHE_GROUP);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Guarda una respuesta.
     *
     * @param string $key
     * @param array  $payload
     * @param int    $ttl
     * @return void
     */
    public static function set(string $key, array $payload, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }

        wp_cache_set($key, $payload, self::CACHE_GROUP, $ttl);
    }

    /**
     * Invalida toda la caché de respuestas de golpe.
     *
     * No recorre entradas: incrementa un contador que forma parte de cada clave, de modo
     * que las anteriores dejan de ser alcanzables y caducan solas.
     *
     * @return void
     */
    public static function purge(): void
    {
        self::bump(self::VERSION_PURGE);
    }

    /**
     * Invalida al guardar configuración.
     *
     * @return void
     */
    public static function bump_config_version(): void
    {
        self::bump(self::VERSION_CONFIG);
    }

    /**
     * Invalida al tocar metadatos de una entrada.
     *
     * `wp_cache_get_last_changed('posts')` no cubre este caso: `update_metadata()` borra
     * la caché de `post_meta` pero no llama a `clean_post_cache()`, así que un PATCH que
     * solo cambia metas no movería ningún marcador de WordPress.
     *
     * @return void
     */
    public static function bump_meta_version(): void
    {
        if (self::$meta_bumped) {
            return;
        }

        self::$meta_bumped = true;
        self::bump(self::VERSION_META);
    }

    /**
     * Invalida solo si la entrada cuyas metas cambiaron pertenece a un endpoint expuesto.
     *
     * Los hooks de metadatos se disparan en todo el sitio: contadores de visitas por meta
     * —un patrón muy extendido— escribirían en `wp_options` en cada visita del front-end
     * y dejarían la caché permanentemente invalidada; `_edit_lock` haría lo propio cada
     * vez que alguien abre el editor. Las dos guardas acotan eso: sin caché activa no se
     * escribe nada en absoluto, y un tipo de contenido que la API no publica no puede
     * invalidar respuestas que no existen.
     *
     * @param int $post_id
     * @return void
     */
    public static function bump_meta_version_for_post(int $post_id): void
    {
        if (self::$meta_bumped || self::ttl() <= 0) {
            return;
        }

        $post_type = get_post_type($post_id);
        if (!is_string($post_type) || !self::post_type_is_exposed($post_type)) {
            return;
        }

        self::bump_meta_version();
    }

    /**
     * Indica si algún endpoint activo publica ese tipo de contenido.
     *
     * @param string $post_type
     * @return bool
     */
    private static function post_type_is_exposed(string $post_type): bool
    {
        foreach (ConfigBuilder::get_active_endpoints() as $endpoint) {
            if (($endpoint['post_type'] ?? null) === $post_type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si hay un object cache persistente detrás.
     *
     * Sin él, `wp_cache_set()` solo vive dentro de la petición y la caché no ahorra nada
     * entre peticiones. El dashboard lo advierte en lugar de dejar el ajuste mintiendo.
     *
     * @return bool
     */
    public static function is_persistent(): bool
    {
        return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
    }

    /**
     * Incrementa uno de los contadores de invalidación.
     *
     * @param string $component
     * @return void
     */
    private static function bump(string $component): void
    {
        $versions = self::versions();
        $versions[$component] = (int) ($versions[$component] ?? 0) + 1;

        update_option(self::OPTION_VERSIONS, $versions, false);
    }

    /**
     * @return array<string, int>
     */
    private static function versions(): array
    {
        $versions = get_option(self::OPTION_VERSIONS, []);

        return is_array($versions) ? $versions : [];
    }

    /**
     * Roles del usuario actual, ordenados para que dos peticiones equivalentes coincidan.
     *
     * @return string[]
     */
    private static function current_roles(): array
    {
        if (!is_user_logged_in()) {
            return ['guest'];
        }

        $user = wp_get_current_user();
        $roles = (is_object($user) && isset($user->roles) && is_array($user->roles)) ? $user->roles : [];

        if (empty($roles)) {
            return ['none'];
        }

        sort($roles);

        return array_map('strval', $roles);
    }

    /**
     * Resumen acotado de una estructura.
     *
     * `sha256` y no un hash rápido moderno porque el plugin declara PHP 7.4 como mínimo
     * y los algoritmos xxHash solo existen desde 8.1. Aquí el hash no protege nada: solo
     * acota la longitud de la clave y colapsa variantes de codificación del mismo valor.
     *
     * @param array $data
     * @return string
     */
    private static function hash(array $data): string
    {
        return hash('sha256', serialize($data));
    }
}
