<?php

namespace WpApiCreator\Api\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WpApiCreator\Domain\Repositories\DynamicQueryBuilder;
use WpApiCreator\Api\OutputSerializer;

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

        // Extraer sanitizaciones configuradas por el Router
        $args = [
            'page'  => $request->get_param('page'),
            'limit' => $request->get_param('limit'),
            '_include' => $request->get_param('_include') // TODO: Para cargar relaciones anidadas POST-Serializer
        ];

        // 1. Obtener resultado paginado desde nuestra Capa de Dominio Desacoplada
        $result = $this->repository->get_collection($post_type, $args);

        // 2. Aplicar Data Mapping individual mediante el OutputSerializer
        $mapped_data = array_map(function($post) use ($config) {
            return $this->serializer->serialize_post($post, $config);
        }, $result['posts']);

        // 3. Responder
        return new WP_REST_Response([
            'data' => $mapped_data,
            'meta' => $result['meta']
        ], 200);
    }
}
