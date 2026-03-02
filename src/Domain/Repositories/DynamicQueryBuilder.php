<?php

namespace WpApiCreator\Domain\Repositories;

use WP_Query;

/**
 * Encapsula la interacción real con WP_Query para desacoplarlo de los controladores REST.
 * Soporta parámetros de paginación y filtrado condicional extraídos de la URI HTTP de manera segura.
 */
class DynamicQueryBuilder
{

    /**
     * Construye y ejecuta un WP_Query basándose en los argumentos de la solicitud y el config del CPT.
     * 
     * @param string $post_type
     * @param array $args Parametros HTTP Parseados ($_GET)
     * @return array [ 'posts' => array, 'meta' => array ]
     */
    public function get_collection(string $post_type, array $args): array {
        
        $page = isset($args['page']) ? absint($args['page']) : 1;
        $limit = isset($args['limit']) ? absint($args['limit']) : 10;
        
        // Bloqueo de seguridad preventivo sobre grandes peticiones para evitar memory limits
        if ($limit > 100) $limit = 100;

        $query_args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish', // Provisional. Iteraremos states más adelante basados en auth
            'posts_per_page' => $limit,
            'paged'          => $page,
        ];

        // Mapeo dinámico de orderby
        if (!empty($args['orderby'])) {
            $query_args['orderby'] = sanitize_text_field($args['orderby']);
        }
        if (!empty($args['order']) && in_array(strtoupper($args['order']), ['ASC', 'DESC'])) {
            $query_args['order'] = strtoupper($args['order']);
        }

        // Mapeo dinámico de Filtros Básicos (Meta Query pre-construída para strings estáticos)
        if (!empty($args['meta_key']) && !empty($args['meta_value'])) {
             $query_args['meta_query'] = [
                [
                    'key'     => sanitize_text_field($args['meta_key']),
                    'value'   => sanitize_text_field($args['meta_value']),
                    'compare' => '='
                ]
             ];
        }

        // Integración de Búsqueda de texto (S)
        if (!empty($args['search'])) {
            $query_args['s'] = sanitize_text_field($args['search']);
        }

        // Se usa `wp_cache_set` a niveles superiores. WP_Query internamente ya cachea post_objects 
        // pero la métrica 'found_posts' sobre gran BD sin limitaciones revienta servidores.
        $query = new WP_Query($query_args);

        return [
            'posts' => $query->posts,
            'meta'  => [
                'total_items'  => $query->found_posts,
                'total_pages'  => $query->max_num_pages,
                'current_page' => $page,
                'limit'        => $limit
            ]
        ];
    }

    /**
     * Obtiene la estrucutra limpia de un solo Elemento Post si está publicado y accesible.
     * 
     * @param string $post_type
     * @param int $id
     * @return object|null  Post Objeto estandar WP o nulo
     */
    public function get_single(string $post_type, int $id)
    {
        $post = get_post($id);

        if (!$post) return null;

        // Comprobación dura para no escanear objetos con URIs alteradas (fuga de info)
        if ($post->post_type !== $post_type) return null;

        // Por ahora lo atamos a "publish" hasta que integremos al Gatekeeper y decida
        // si un "editor" quier ver un "draft" por la API.
        if ($post->post_status !== 'publish' && !current_user_can('edit_post', $post->ID)) {
            return null;
        }

        return $post;
    }
}
