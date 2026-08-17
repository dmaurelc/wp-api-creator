<?php

namespace WpApiCreator\Api\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WpApiCreator\Domain\Repositories\DynamicQueryBuilder;
use WpApiCreator\Api\CollectionArgs;
use WpApiCreator\Api\OutputSerializer;
use WpApiCreator\Domain\ResponseCache;

/**
 * Controlador puro de Endpoints de Colección (Listados GET).
 * Invoca el Repositorio tras limpiar los Request Params y delega el Mapeado de Salida al Serializador.
 */
class CollectionController
{

    protected $repository;
    protected $serializer;

    /**
     * @param DynamicQueryBuilder $repository
     * @param OutputSerializer $serializer
     */
    public function __construct(DynamicQueryBuilder $repository, OutputSerializer $serializer)
    {
        $this->repository = $repository;
        $this->serializer = $serializer;
    }

    /**
     * Manipulador maestro de requests a {namespace}/v1/{recurso}
     *
     * @param WP_REST_Request $request
     * @param array $config Endpoint configuration activa enviada por el Router
     * @return WP_REST_Response|WP_Error
     */
    public function get_items(WP_REST_Request $request, array $config)
    {

        $post_type = $config['post_type'];

        // Los parámetros se leen exclusivamente a través de CollectionArgs: es la misma
        // estructura que alimenta la consulta y la clave de caché, de modo que no pueden
        // describir peticiones distintas.
        $params = CollectionArgs::collect($request, $config);
        $args   = CollectionArgs::to_query_args($params, $config);

        // 1. Servir desde caché si el ajuste lo permite y la petición es cacheable
        $ttl = ResponseCache::ttl();
        $cache_key = null;

        if (ResponseCache::is_cacheable($params, $ttl)) {
            $cache_key = ResponseCache::key($config, $params);

            $cached = ResponseCache::get($cache_key);
            if ($cached !== null) {
                return new WP_REST_Response($cached, 200);
            }
        }

        // 2. Obtener resultado paginado desde nuestra Capa de Dominio Desacoplada
        $result = $this->repository->get_collection($post_type, $args);

        // 3. Aplicar Data Mapping individual mediante el OutputSerializer
        $mapped_data = array_map(function($post) use ($config) {
            return $this->serializer->serialize_post($post, $config);
        }, $result['posts']);

        $payload = [
            'data' => $mapped_data,
            'meta' => $result['meta']
        ];

        if ($cache_key !== null) {
            ResponseCache::set($cache_key, $payload, $ttl);
        }

        // 4. Responder
        return new WP_REST_Response($payload, 200);
    }
}
