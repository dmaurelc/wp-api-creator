<?php

namespace WpApiCreator\Schema;

use WpApiCreator\Introspection\MetaScanner;

/**
 * Clase encargada de consolidar todos los campos (nativos y meta)
 * disponibles para un tipo de contenido.
 *
 * Implementa caché multinivel para evitar escaneos redundantes:
 * - Nivel 1: Caché estática in-memory (per-request).
 * - Nivel 2: wp_cache_* (persistente si hay Object Cache como Redis/Memcached).
 */
class FieldScanner
{
    const CACHE_GROUP = 'creator_fields';

    /** @var array Caché estática per-request indexada por post_type */
    private static array $fields_cache = [];

    /**
     * Retorna un listado plano de campos disponibles para un post_type.
     *
     * @param string $post_type
     * @return array
     */
    public static function get_available_fields(string $post_type): array
    {
        // Nivel 1: Caché estática (mismo request)
        if (isset(self::$fields_cache[$post_type])) {
            return self::$fields_cache[$post_type];
        }

        // Nivel 2: Object Cache
        $cache_key = 'scanner_' . $post_type;
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached) && !empty($cached)) {
            self::$fields_cache[$post_type] = $cached;
            return $cached;
        }

        // Nivel 3: Cálculo completo
        $fields = self::scan_fields($post_type);

        // Poblar ambas capas de caché
        self::$fields_cache[$post_type] = $fields;
        wp_cache_set($cache_key, $fields, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS);

        return $fields;
    }

    /**
     * Ejecuta el escaneo real de campos nativos y meta.
     *
     * @param string $post_type
     * @return array
     */
    private static function scan_fields(string $post_type): array
    {
        $fields = [];

        // 1. Campos Nativos (Estructurados)
        $native_config = [
            'id'             => 'ID de publicación',
            'title'          => 'Título',
            'content'        => 'Contenido (Full HTML)',
            'excerpt'        => 'Extracto',
            'date'           => 'Fecha de creación',
            'modified'       => 'Fecha de modificación',
            'slug'           => 'URL Slug',
            'status'         => 'Estado (Publish/Draft)',
            'author'         => 'ID del Autor',
            'featured_media' => 'Imagen Destacada (URL + ID)',
        ];

        foreach ($native_config as $key => $label) {
            $fields[] = [
                'key'   => (string) $key,
                'label' => (string) $label,
                'group' => 'native',
            ];
        }

        // 2. Metacampos (ACF, JetEngine, Meta Box, etc.)
        $meta_scanner = new MetaScanner();
        $custom_fields = $meta_scanner->get_fields_for_post_type($post_type);

        foreach ($custom_fields as $key => $info) {
            $actual_key = is_array($info) ? ($info['key'] ?? $key) : $key;
            $source = is_array($info) ? ($info['source'] ?? 'meta') : 'meta';

            $fields[] = [
                'key'    => (string) $actual_key,
                'label'  => (is_array($info) ? ($info['label'] ?? $actual_key) : $info) ?: (string) $actual_key,
                'group'  => 'meta',
                'source' => $source,
            ];
        }

        // Asegurar que label sea string simple
        foreach ($fields as &$f) {
            $f['label'] = is_scalar($f['label']) ? (string) $f['label'] : $f['key'];
        }

        return array_values($fields);
    }

    /**
     * Invalida la caché de campos para un post_type específico o todos.
     *
     * @param string|null $post_type Si es null, limpia todo.
     * @return void
     */
    public static function invalidate_cache(?string $post_type = null): void
    {
        if ($post_type !== null) {
            unset(self::$fields_cache[$post_type]);
            wp_cache_delete('scanner_' . $post_type, self::CACHE_GROUP);
        } else {
            self::$fields_cache = [];
            // No existe wp_cache_flush_group en todas las implementaciones,
            // así que limpiamos los post_types conocidos
            $post_types = get_post_types(['public' => true]);
            foreach ($post_types as $pt) {
                wp_cache_delete('scanner_' . $pt, self::CACHE_GROUP);
            }
        }
    }
}
