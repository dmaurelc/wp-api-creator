<?php

namespace WpApiCreator\Api;

use WpApiCreator\Permissions\Gatekeeper;

/**
 * Responsable de filtrar y serializar los datos puros de WordPress (WP_Post, Metas)
 * hacia la estructura Json Restrictiva limpia definida en la API.
 */
class OutputSerializer
{

    protected Gatekeeper $gatekeeper;

    public function __construct(?Gatekeeper $gatekeeper = null)
    {
        $this->gatekeeper = $gatekeeper ?? new Gatekeeper();
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

        // Mapeo de campos nativos permitidos
        $native_map = [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'content'        => apply_filters('the_content', $post->post_content),
            'excerpt'        => $post->post_excerpt,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'author'         => $post->post_author,
            'date'           => get_post_datetime($post->ID, 'date', 'gmt')->format('c'),
            'modified'       => get_post_datetime($post->ID, 'modified', 'gmt')->format('c'),
        ];

        // Si no hay lista blanca, permitimos ráfaga base. Si hay, filtramos.
        foreach ($native_map as $key => $val) {
            if (empty($allowed) || in_array($key, $allowed)) {
                $res[$key] = $val;
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

        // Obtener mapeo de fuentes para este post_type
        $post_type = $post->post_type;
        if (!isset(self::$field_mappings[$post_type])) {
            $available_fields = \WpApiCreator\Schema\FieldScanner::get_available_fields($post_type);
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
                // Si el campo no es nativo ni featured_media, lo tratamos como meta especializado
                if (!isset($native_map[$field_key]) && $field_key !== 'featured_media') {
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
}
