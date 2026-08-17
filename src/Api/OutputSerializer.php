<?php

namespace WpApiCreator\Api;

use WpApiCreator\Permissions\Gatekeeper;
use WpApiCreator\Schema\FieldScanner;
use WpApiCreator\Api\CollectionArgs;

/**
 * Responsable de filtrar y serializar los datos puros de WordPress (WP_Post, Metas)
 * hacia la estructura Json Restrictiva limpia definida en la API.
 */
class OutputSerializer
{

    protected Gatekeeper $gatekeeper;

    /**
     * Proveedor de los campos disponibles de un post_type.
     *
     * `FieldScanner::get_available_fields()` es estatico y no puede sustituirse desde un
     * test. Se inyecta como dependencia opcional, igual que el secreto de JwtProvider.
     *
     * @var callable|null
     */
    protected $field_provider;

    public function __construct(?Gatekeeper $gatekeeper = null, ?callable $field_provider = null)
    {
        $this->gatekeeper = $gatekeeper ?? new Gatekeeper();
        $this->field_provider = $field_provider;
    }

    /**
     * Cache estática para el mapeo de campos por post_type.
     */
    protected static $field_mappings = [];

    /**
     * Transforma un objeto WP_Post nativo polucionado a la limpieza API deseada.
     * 
     * @param object $post WP_Post object
     * @param array $config Endpoint Config para leer `exposed_fields`
     * @return array
     */
    public function serialize_post($post, array $config): array
    {
        $allowed = $config['exposed_fields'] ?? [];
        $res = [];

        // Mapeo de campos nativos permitidos.
        //
        // Cada campo se resuelve solo si el endpoint lo expone. Construir el mapa entero
        // por adelantado hacía que un endpoint configurado para devolver únicamente
        // `title` pagase igualmente `apply_filters('the_content')` —bloques, shortcodes y
        // oEmbed— por cada entrada de la colección.
        $native_resolvers = [
            'id'             => function () use ($post) { return $post->ID; },
            'title'          => function () use ($post) { return $post->post_title; },
            'content'        => function () use ($post) { return apply_filters('the_content', $post->post_content); },
            'excerpt'        => function () use ($post) { return $post->post_excerpt; },
            'slug'           => function () use ($post) { return $post->post_name; },
            'status'         => function () use ($post) { return $post->post_status; },
            'author'         => function () use ($post) { return $post->post_author; },
            'date'           => function () use ($post) { return get_post_datetime($post->ID, 'date', 'gmt')->format('c'); },
            'modified'       => function () use ($post) { return get_post_datetime($post->ID, 'modified', 'gmt')->format('c'); },
        ];

        // Si no hay lista blanca, permitimos ráfaga base. Si hay, filtramos.
        foreach ($native_resolvers as $key => $resolve) {
            if (empty($allowed) || in_array($key, $allowed)) {
                $res[$key] = $resolve();
            }
        }

        // Imagen Destacada expuesta si se permite
        if (empty($allowed) || in_array('featured_media', $allowed)) {
            $thumbnail_id = get_post_thumbnail_id($post->ID);
            if ($thumbnail_id) {
                $thumbnail_url = wp_get_attachment_image_url($thumbnail_id, 'full');
                if ($thumbnail_url) {
                    $res['featured_media'] = [
                        'id' => $thumbnail_id,
                        'url' => $thumbnail_url,
                    ];
                }
            }
        }

        // Términos de las taxonomías seleccionadas, agrupados por taxonomía.
        //
        // `get_the_terms()` lee la caché de términos que WP_Query ya cebó para toda la
        // colección (`update_post_term_cache`), a diferencia de `wp_get_object_terms()`,
        // que consulta la base de datos una vez por post y taxonomía.
        $taxonomies = [];
        foreach ($allowed as $field_key) {
            if (strpos((string) $field_key, FieldScanner::TAXONOMY_PREFIX) !== 0) {
                continue;
            }

            $taxonomy = substr((string) $field_key, strlen(FieldScanner::TAXONOMY_PREFIX));

            // Una taxonomía marcada cuando era pública sigue en `exposed_fields` si el
            // plugin que la registra la cierra después. El editor no la ofrecería hoy;
            // la configuración guardada no se entera sola.
            if (!CollectionArgs::taxonomy_is_public($taxonomy)) {
                continue;
            }

            $terms = get_the_terms($post, $taxonomy);

            $taxonomies[$taxonomy] = (is_wp_error($terms) || empty($terms))
                ? []
                : array_values(array_map(function ($term) {
                    return [
                        'id'   => (int) $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                    ];
                }, $terms));
        }

        if (!empty($taxonomies)) {
            $res['taxonomies'] = $taxonomies;
        }

        // Obtener mapeo de fuentes para este post_type
        $post_type = $post->post_type;
        if (!isset(self::$field_mappings[$post_type])) {
            $available_fields = $this->get_available_fields($post_type);
            $mapping = [];
            foreach ($available_fields as $f) {
                $mapping[$f['key']] = $f['source'] ?? $f['group'] ?? 'other';
            }
            self::$field_mappings[$post_type] = $mapping;
        }
        $source_mapping = self::$field_mappings[$post_type];

        // Metadatos (Campos custom agrupados por categoría)
        $res['fields'] = [];

        if (!empty($allowed)) {
            foreach ($allowed as $field_key) {
                // Las taxonomías ya se emitieron arriba. Sin esta guarda saldrían además
                // como `fields.taxonomy.{nombre}: ""`, porque no hay meta con ese nombre.
                if (strpos((string) $field_key, FieldScanner::TAXONOMY_PREFIX) === 0) {
                    continue;
                }

                // Si el campo no es nativo ni featured_media, lo tratamos como meta especializado
                if (!isset($native_resolvers[$field_key]) && $field_key !== 'featured_media') {
                    if ($this->gatekeeper->can_interact_with_field($field_key, $config, 'read')) {
                        $source = $source_mapping[$field_key] ?? 'meta';
                        
                        // 1. Evitar exponer campos de sampleo de base de datos que son internos/huérfanos
                        if ($source === 'database_sample') {
                            continue;
                        }

                        // 2. Filtro de seguridad: No exponer NUNCA campos que empiecen con prefijos internos conocidos,
                        // a menos que hayan sido mapeados explícitamente a algo que no sea 'meta' (como ACF).
                        // Esto previene que campos como _acf_changed o _flavor_re_seed se filtren si no tienen mapeo.
                        if ($source === 'meta' && (strpos($field_key, '_') === 0)) {
                            // Excepción: Permitir si es una meta registrada que queremos (ej. de otros plugins)
                            // Pero para ser agresivos con la petición del usuario, bloqueamos todo lo que empiece con _ y caiga en 'meta'
                            continue;
                        }

                        if (!isset($res['fields'][$source])) {
                            $res['fields'][$source] = [];
                        }
                        
                        $res['fields'][$source][$field_key] = get_post_meta($post->ID, $field_key, true);
                    }
                }
            }
        }

        return $res;
    }

    /**
     * Resuelve los campos disponibles del post_type por el proveedor inyectado o el escáner.
     *
     * @param string $post_type
     * @return array
     */
    protected function get_available_fields(string $post_type): array
    {
        if ($this->field_provider !== null) {
            return (array) call_user_func($this->field_provider, $post_type);
        }

        return FieldScanner::get_available_fields($post_type);
    }
}
