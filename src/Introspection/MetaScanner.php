<?php

namespace WpApiCreator\Introspection;

/**
 * Escáner profundo de Meta Campos para CPTs.
 * Descubre campos definidos por ACF, JetEngine, MetaBox, o huerfanos en la DB.
 */
class MetaScanner
{

    /**
     * Extrae todos los metacampos descubiertos para un Custom Post Type.
     * 
     * @param string $post_type Valid CPT name
     * @return array
     */
    public function get_fields_for_post_type(string $post_type): array {
        $cache_key = 'creator_fields_v4_' . $post_type;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $fields = [];

        // 1. Estrategia: Metas registrados nativamente en WordPress
        $registered_fields = $this->get_registered_metas($post_type);
        $fields = array_merge($fields, $registered_fields);

        // 2. Estrategia: ACF (Advanced Custom Fields)
        if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
            $acf_fields = $this->get_acf_fields($post_type);
            $fields = array_merge($fields, $acf_fields);
        }

        // 3. Estrategia: JetEngine
        if (function_exists('jet_engine') && jet_engine()->meta_boxes) {
            $jet_fields = $this->get_jetengine_fields($post_type);
            $fields = array_merge($fields, $jet_fields);
        }

        // 4. Estrategia: Meta Box
        if (function_exists('rwmb_get_object_fields')) {
            $mb_fields = $this->get_metabox_fields($post_type);
            $fields = array_merge($fields, $mb_fields);
        }

        // 5. Estrategia: Flavor Real Estate (Plugin específico detectado)
        $flavor_fields = $this->get_flavor_re_fields($post_type);
        $fields = array_merge($fields, $flavor_fields);

        // 6. Estrategia: Sampleo Silencioso de DB (Huerfanos y otros Plugins Custom)
        // Desactivado a petición para evitar exposición de metadatos internos innecesarios.
        /*
        $orphan_fields = $this->sample_database_metas($post_type);
        foreach ($orphan_fields as $meta_key) {
            // No sobreescribir si ACF/JetEngine ya lo había descubierto formalmente
            if (!isset($fields[$meta_key])) {
                $fields[$meta_key] = [
                    'key'    => $meta_key,
                    'label'  => ucfirst(str_replace('_', ' ', ltrim($meta_key, '_'))),
                    'type'   => 'string',
                    'source' => 'database_sample'
                ];
            }
        }
        */

        // Cachear por 5 minutos (suficiente para performance, sin bloquear dev)
        set_transient($cache_key, $fields, 5 * MINUTE_IN_SECONDS);
        return $fields;
    }

    /**
     * Obtiene el listado de campos definidos por ACF apuntando al CPT dado.
     * 
     * @param string $post_type
     * @return array
     */
    protected function get_acf_fields(string $post_type): array {
        $processed = [];

        try {
            // Filtrar grupos válidos para el post_type destino
            $groups = acf_get_field_groups(['post_type' => $post_type]);
            
            if (empty($groups)) {
                return [];
            }

            foreach ($groups as $group) {
                $fields = acf_get_fields($group['key']);
                if (!$fields) continue;

                foreach ($fields as $field) {
                    if (empty($field['name'])) continue;
                    
                    $processed[$field['name']] = [
                        'key'       => $field['name'],
                        'label'     => $field['label'] ?? $field['name'],
                        'type'      => $field['type'] ?? 'text',
                        'source'    => 'acf'
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silently fail if ACF API has issues
        }

        return $processed;
    }

    /**
     * Extrae metas registradas formalmente mediante register_post_meta() o register_meta()
     */
    protected function get_registered_metas(string $post_type): array {
        $fields = [];
        $registered = get_registered_meta_keys('post', $post_type);
        if (is_array($registered)) {
            $exclude_keys = ['_edit_lock', '_edit_last', '_thumbnail_id', '_wp_page_template', '_acf_changed', '_flavor_re_seed'];
            foreach ($registered as $key => $args) {
                if (in_array($key, $exclude_keys)) continue;
                if (strpos($key, '_') === 0 && !isset($args['show_in_rest'])) continue; // Filtrar internos si no están explícitamente en REST

                $fields[$key] = [
                    'key'    => $key,
                    'label'  => ucfirst(str_replace('_', ' ', $key)) . ' (Código)',
                    'type'   => $args['type'] ?? 'string',
                    'source' => 'registered_meta'
                ];
            }
        }
        return $fields;
    }

    /**
     * Obtiene campos definidos por JetEngine.
     * Usa get_meta_fields_for_object() y get_fields_for_context() como APIs oficiales.
     */
    protected function get_jetengine_fields(string $post_type): array {
        $processed = [];
        
        try {
            $meta_boxes = jet_engine()->meta_boxes;

            // Método 1: get_meta_fields_for_object (busca por post_type slug)
            if (method_exists($meta_boxes, 'get_meta_fields_for_object')) {
                $jet_fields = $meta_boxes->get_meta_fields_for_object($post_type);
                
                if (!empty($jet_fields) && is_array($jet_fields)) {
                    foreach ($jet_fields as $field) {
                        if (empty($field['name'])) continue;
                        // Filtrar no-campos (tabs, headings, HTML)
                        if (!empty($field['object_type']) && $field['object_type'] !== 'field') continue;
                        
                        $processed[$field['name']] = [
                            'key'       => $field['name'],
                            'label'     => $field['title'] ?? $field['name'],
                            'type'      => $field['type'] ?? 'text',
                            'source'    => 'jetengine'
                        ];
                    }
                }
            }
            
            // Método 2 (fallback): get_fields_for_context si el método 1 no encontró campos
            if (empty($processed) && method_exists($meta_boxes, 'get_fields_for_context')) {
                $context_fields = $meta_boxes->get_fields_for_context('post_type', $post_type);
                
                if (!empty($context_fields) && is_array($context_fields)) {
                    foreach ($context_fields as $field) {
                        if (empty($field['name'])) continue;
                        if (!empty($field['object_type']) && $field['object_type'] !== 'field') continue;
                        
                        $processed[$field['name']] = [
                            'key'       => $field['name'],
                            'label'     => $field['title'] ?? $field['name'],
                            'type'      => $field['type'] ?? 'text',
                            'source'    => 'jetengine'
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently fail si JetEngine tiene problemas internos
        }

        return $processed;
    }

    /**
     * Obtiene campos definidos por Meta Box
     */
    protected function get_metabox_fields(string $post_type): array {
        $processed = [];
        try {
            $fields = rwmb_get_object_fields($post_type, 'post');
            if (is_array($fields)) {
                foreach ($fields as $field) {
                    $id = $field['id'] ?? '';
                    if (empty($id)) continue;
                    
                    $processed[$id] = [
                        'key'       => $id,
                        'label'     => $field['name'] ?? $id,
                        'type'      => $field['type'] ?? 'string',
                        'source'    => 'metabox'
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silently fail
        }
        return $processed;
    }

    /**
     * Obtiene campos del plugin Flavor Real Estate mediante introspección de sus clases.
     */
    protected function get_flavor_re_fields(string $post_type): array {
        $processed = [];
        $class_map = [
            'property'     => '\Flavor\RealEstate\MetaBoxes\PropertyMetaBox',
            'flavor_agent' => '\Flavor\RealEstate\MetaBoxes\AgentMetaBox',
            'agent'        => '\Flavor\RealEstate\MetaBoxes\AgentMetaBox',
            'agente'       => '\Flavor\RealEstate\MetaBoxes\AgentMetaBox',
        ];

        if (!isset($class_map[$post_type]) || !class_exists($class_map[$post_type])) {
            return [];
        }

        try {
            $instance = new $class_map[$post_type]();
            if (method_exists($instance, 'getFields')) {
                $fields = $instance->getFields();
                foreach ($fields as $key => $config) {
                    $processed[$key] = [
                        'key'    => $key,
                        'label'  => $config['label'] ?? ucfirst(str_replace(['_', 'property ', 'agent '], '', $key)),
                        'type'   => $config['type'] ?? 'string',
                        'source' => 'flavor_re'
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silently fail if plugin classes are not perfectly compatible
        }

        return $processed;
    }

    /**
     * Sampleo directo de la Base de Datos para encontrar metadata no registrada.
     * Incluye metas con prefijo _ (filtrando las internas de WP).
     * 
     * @param string $post_type
     * @return array
     */
    protected function sample_database_metas(string $post_type): array {
        global $wpdb;

        // Claves internas de WordPress que siempre se deben excluir
        $wp_internal_keys = [
            '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_page_template',
            '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time',
            '_wp_desired_post_slug', '_wp_attached_file', '_wp_attachment_metadata',
            '_encloseme', '_pingme', '_wp_old_date',
        ];

        $exclude_sql = "'" . implode("', '", array_map('esc_sql', $wp_internal_keys)) . "'";

        // Buscar posts del CPT
        $post_ids = $wpdb->get_col($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = %s 
            AND post_status IN ('publish', 'draft', 'private', 'future', 'pending')
            ORDER BY ID DESC LIMIT 200
        ", $post_type));

        if (empty($post_ids)) {
            // Fallback: buscar meta_keys vía JOIN directo
            $query_fallback = $wpdb->prepare("
                SELECT DISTINCT pm.meta_key 
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                WHERE p.post_type = %s 
                AND pm.meta_key NOT IN ($exclude_sql)
                LIMIT 200
            ", $post_type);
            $results = $wpdb->get_col($query_fallback);
            return is_array($results) ? $results : [];
        }

        $ids_string = implode(',', array_map('intval', $post_ids));

        // Buscar metakeys asociadas, excluyendo solo claves internas de WP
        $query = "
            SELECT DISTINCT meta_key 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN ($ids_string)
            AND meta_key NOT IN ($exclude_sql)
        ";

        $results = $wpdb->get_col($query);

        return is_array($results) ? $results : [];
    }

    /**
     * Limpia la caché de introspección para un CPT específico.
     * 
     * @param string $post_type
     */
    public static function clear_cpt_cache(string $post_type)
    {
        // Limpiar ambas versiones del transient (v3 legado y v4 actual)
        delete_transient('creator_fields_v3_' . $post_type);
        delete_transient('creator_fields_v4_' . $post_type);
        // También invalidamos el esquema OpenAPI
        delete_option(\WpApiCreator\Schema\OpenApiBuilder::CACHE_OPTION_KEY);
    }

    /**
     * Limpia todas las caches de introspección.
     */
    public static function clear_all_caches()
    {
        $post_types = get_post_types(['public' => true], 'names');
        if (is_array($post_types)) {
            foreach ($post_types as $post_type) {
                self::clear_cpt_cache($post_type);
            }
        }
    }
}
