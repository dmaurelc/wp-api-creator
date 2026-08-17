<?php

namespace WpApiCreator\Domain\Repositories;

use WP_Query;

/**
 * Encapsula la interacción real con WP_Query para desacoplarlo de los controladores REST.
 * Soporta parámetros de paginación y filtrado condicional extraídos de la URI HTTP de manera segura.
 */
class DynamicQueryBuilder
{
    /** Estados que un cliente puede pedir. Cualquier otro valor cae a `publish`. */
    const ALLOWED_STATUSES = ['publish', 'draft', 'pending', 'private'];

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

        $status = isset($args['status']) ? (string) $args['status'] : 'publish';
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $status = 'publish';
        }

        $query_args = [
            'post_type'      => $post_type,
            'post_status'    => $status,
            'posts_per_page' => $limit,
            'paged'          => $page,
        ];

        // El estado solicitado se contrasta contra las capacidades del usuario. WP_Query no
        // aplica ningun filtro de propiedad por si mismo: sin esto, pedir `?status=draft`
        // devolveria los borradores de todos los autores.
        $query_args = $this->apply_status_capabilities($query_args, $post_type, $status);

        if ($query_args === null) {
            return [
                'posts' => [],
                'meta'  => [
                    'total_items'  => 0,
                    'total_pages'  => 0,
                    'current_page' => $page,
                    'limit'        => $limit,
                ]
            ];
        }

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
     * Ajusta la consulta segun las capacidades que exige el estado solicitado.
     *
     * | Estado pedido     | Requisito                                                        |
     * |-------------------|------------------------------------------------------------------|
     * | publish           | ninguno                                                          |
     * | draft, pending    | `edit_others_posts` del CPT, o se fuerza `author__in` al usuario  |
     * | private           | `read_private_posts` del CPT                                     |
     *
     * @param array  $query_args
     * @param string $post_type
     * @param string $status
     * @return array|null Null cuando el usuario no puede ver ese estado en absoluto.
     */
    protected function apply_status_capabilities(array $query_args, string $post_type, string $status): ?array
    {
        if ($status === 'publish') {
            return $query_args;
        }

        if (!is_user_logged_in()) {
            return null;
        }

        if ($status === 'private') {
            return current_user_can($this->map_capability($post_type, 'read_private_posts'))
                ? $query_args
                : null;
        }

        // draft y pending
        if (current_user_can($this->map_capability($post_type, 'edit_others_posts'))) {
            return $query_args;
        }

        // Sin capacidad sobre contenido ajeno solo se devuelve lo propio.
        // `edit_posts` no basta como comprobacion: el rol `author` la tiene de serie y
        // recibiria los borradores de todos los autores.
        $query_args['author__in'] = [get_current_user_id()];

        return $query_args;
    }

    /**
     * Traduce una capacidad generica a la que declara el CPT, si la define.
     *
     * @param string $post_type
     * @param string $capability
     * @return string
     */
    protected function map_capability(string $post_type, string $capability): string
    {
        $post_type_object = get_post_type_object($post_type);

        if ($post_type_object && isset($post_type_object->cap->$capability)) {
            return (string) $post_type_object->cap->$capability;
        }

        return $capability;
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
