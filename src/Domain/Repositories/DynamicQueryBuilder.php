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
     * Criterios de ordenación aceptados.
     *
     * Quedan fuera a propósito:
     * - `meta_value` y `meta_value_num`, que obligan a un JOIN sobre `wp_postmeta` sin
     *   índice útil para ordenar.
     * - `rand`, que es `ORDER BY RAND()`: una consulta sin índice por definición. Un
     *   cliente que necesite contenido aleatorio pide N elementos y los baraja.
     */
    const ALLOWED_ORDERBY = ['date', 'modified', 'title', 'menu_order', 'ID'];

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

        // Mapeo dinámico de orderby. El `enum` del argumento ya rechaza cualquier otro
        // valor con un 400; la lista blanca aquí es la segunda barrera para las llamadas
        // que no pasan por el Router.
        if (!empty($args['orderby']) && in_array($args['orderby'], self::ALLOWED_ORDERBY, true)) {
            $query_args['orderby'] = $args['orderby'];
        }
        if (!empty($args['order']) && in_array(strtoupper($args['order']), ['ASC', 'DESC'])) {
            $query_args['order'] = strtoupper($args['order']);
        }

        // Mapeo dinámico de Filtros Básicos (Meta Query pre-construída para strings estáticos).
        // Se comprueba presencia y no `!empty()`: `meta_value=0` es un valor legítimo que
        // antes se descartaba en silencio.
        if (isset($args['meta_key'], $args['meta_value']) && $args['meta_key'] !== '') {
             $query_args['meta_query'] = [
                [
                    'key'     => sanitize_text_field($args['meta_key']),
                    'value'   => sanitize_text_field((string) $args['meta_value']),
                    'compare' => '='
                ]
             ];
        }

        // Filtro por slug: es lo que necesita una ruta de detalle que no conoce el ID.
        if (!empty($args['slug'])) {
            $query_args['name'] = sanitize_title($args['slug']);
        }

        // Integración de Búsqueda de texto (S)
        if (!empty($args['search'])) {
            $query_args['s'] = sanitize_text_field($args['search']);
        }

        // Filtrado por taxonomía: OR dentro de una taxonomía, AND entre taxonomías.
        $tax_query = $this->build_tax_query($args['taxonomies'] ?? []);
        if (!empty($tax_query)) {
            $query_args['tax_query'] = $tax_query;
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
     * Traduce los filtros de taxonomía recibidos a una `tax_query` de WP_Query.
     *
     * Cada valor admite varios términos separados por coma, que se resuelven como OR
     * dentro de su taxonomía; varias taxonomías se combinan con AND.
     *
     * `sanitize_title` sobre cada término impide inyectar estructura en la consulta. Un
     * término inexistente devuelve cero resultados en lugar de un 400: validarlo contra
     * la lista real de términos costaría una consulta adicional por petición.
     *
     * @param mixed $taxonomies Mapa taxonomía => términos separados por coma.
     * @return array
     */
    protected function build_tax_query($taxonomies): array
    {
        if (!is_array($taxonomies) || empty($taxonomies)) {
            return [];
        }

        $tax_query = [];
        foreach ($taxonomies as $taxonomy => $raw) {
            $slugs = array_values(array_filter(array_map(
                'sanitize_title',
                explode(',', (string) $raw)
            )));

            if (empty($slugs)) {
                continue;
            }

            $tax_query[] = [
                'taxonomy' => (string) $taxonomy,
                'field'    => 'slug',
                'terms'    => $slugs,
                'operator' => 'IN',
            ];
        }

        if (count($tax_query) > 1) {
            $tax_query['relation'] = 'AND';
        }

        return $tax_query;
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
