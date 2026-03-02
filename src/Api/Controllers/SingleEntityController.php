<?php

namespace WpApiCreator\Api\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WpApiCreator\Domain\Repositories\DynamicQueryBuilder;
use WpApiCreator\Api\OutputSerializer;

/**
 * Controlador puro de Endpoints Individuales (GET /v1/entidad/ID).
 */
class SingleEntityController
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
     * Manejador de la solicitud GET única.
     * 
     * @param WP_REST_Request $request
     * @param array $config
     * @return WP_REST_Response|WP_Error
     */
    public function get_item(WP_REST_Request $request, array $config)
    {
        
        $post_type = $config['post_type'];
        $id = (int) $request->get_param('id');

        // Búsqueda del post asegurando Ownership basico
        $post = $this->repository->get_single($post_type, $id);

        if (!$post) {
            return new WP_Error('not_found', 'Elemento no encontrado o sin permisos.', ['status' => 404]);
        }

        // Serializamos con el OutputSerializer
        $mapped_data = $this->serializer->serialize_post($post, $config);

        return new WP_REST_Response(['data' => $mapped_data], 200);
    }
}
