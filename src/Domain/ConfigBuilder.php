<?php

namespace WpApiCreator\Domain;

/**
 * Responsable de armar e interactuar con la configuración global del plugin.
 * Obtiene qué CPTs (Custom post types) y metas serán expuestos.
 *
 * Usa una caché estática in-memory por request y wp_cache_* para persistencia
 * entre requests cuando hay un Object Cache (Redis/Memcached) disponible.
 */
class ConfigBuilder
{
    const OPTION_KEY = 'wp_api_creator_config';
    const CACHE_GROUP = 'creator_config';
    const CACHE_KEY = 'full_config';

    /** @var array|null Caché estática per-request */
    private static ?array $config_cache = null;

    /**
     * Obtiene la configuración completa del plugin con caché multinivel.
     *
     * @return array
     */
    private static function get_config(): array
    {
        // Nivel 1: Caché estática (mismo request)
        if (self::$config_cache !== null) {
            return self::$config_cache;
        }

        // Nivel 2: Object Cache (Redis/Memcached si está disponible)
        $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if (is_array($cached)) {
            self::$config_cache = $cached;
            return $cached;
        }

        // Nivel 3: Base de datos (get_option)
        $config = get_option(self::OPTION_KEY, []);
        if (!is_array($config)) {
            $config = [];
        }

        // Poblar ambos niveles de caché
        self::$config_cache = $config;
        wp_cache_set(self::CACHE_KEY, $config, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS);

        return $config;
    }

    /**
     * Invalida todas las capas de caché.
     *
     * @return void
     */
    public static function invalidate_cache(): void
    {
        self::$config_cache = null;
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }

    /**
     * Retorna la matriz de endpoints activos.
     *
     * @return array
     */
    public static function get_active_endpoints(): array
    {
        $config = self::get_config();
        return $config['endpoints'] ?? [];
    }

    /**
     * Retorna la configuración de visibilidad de rutas globales.
     *
     * @return array
     */
    public static function get_global_routes_config(): array
    {
        $config = self::get_config();
        return $config['global_routes'] ?? [];
    }

    /**
     * Retorna la configuración de ajustes globales.
     *
     * @return array
     */
    public static function get_global_settings(): array
    {
        $config = self::get_config();

        $settings = $config['settings'] ?? [];

        // Defaults
        return [
            'api_namespace'        => $settings['api_namespace'] ?? 'wp-custom-api/v1',
            'cache_time'           => isset($settings['cache_time']) ? (int) $settings['cache_time'] : 0,
            'require_api_key'      => isset($settings['require_api_key']) ? (bool) $settings['require_api_key'] : false,
            'jwt_expiration'       => isset($settings['jwt_expiration']) ? (int) $settings['jwt_expiration'] : 24,
            'api_keys'             => $settings['api_keys'] ?? [],
            'filter_wp_endpoints'  => isset($settings['filter_wp_endpoints']) ? (bool) $settings['filter_wp_endpoints'] : false,
        ];
    }

    /**
     * Retorna todas las configuraciones de endpoints WP core.
     *
     * @return array Mapa de post_type => config
     */
    public static function get_wp_endpoint_configs(): array
    {
        $config = self::get_config();
        return $config['wp_endpoint_configs'] ?? [];
    }

    /**
     * Retorna la configuración de un endpoint WP core por su post_type.
     *
     * @param string $post_type
     * @return array|null
     */
    public static function get_wp_endpoint_config(string $post_type): ?array
    {
        $configs = self::get_wp_endpoint_configs();
        return $configs[$post_type] ?? null;
    }

    /**
     * Guarda la configuración de un endpoint WP core.
     *
     * @param string $post_type
     * @param array  $endpoint_config
     * @return bool
     */
    public static function save_wp_endpoint_config(string $post_type, array $endpoint_config): bool
    {
        $config = self::get_config();
        if (!isset($config['wp_endpoint_configs'])) {
            $config['wp_endpoint_configs'] = [];
        }
        $config['wp_endpoint_configs'][$post_type] = $endpoint_config;
        return self::save_config($config);
    }

    /**
     * Guarda toda la configuración e invalida la caché.
     *
     * @param array $config
     * @return bool
     */
    public static function save_config(array $config): bool
    {
        self::invalidate_cache();
        return update_option(self::OPTION_KEY, $config);
    }
}
