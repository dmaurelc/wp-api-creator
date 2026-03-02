<?php

namespace WpApiCreator\Api\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WpApiCreator\Domain\Repositories\DynamicQueryBuilder;
use WpApiCreator\Api\OutputSerializer;
use WpApiCreator\Permissions\Gatekeeper;

/**
 * Controla la Mutación (Creación, Actualización y Borrado) de Entidades (POST/PATCH/DELETE).
 */
class MutationController
{

    protected $repository;
    protected $serializer;
    protected $gatekeeper;

    public function __construct(DynamicQueryBuilder $repository, OutputSerializer $serializer, Gatekeeper $gatekeeper = null)
    {
        $this->repository = $repository;
        $this->serializer = $serializer;
        $this->gatekeeper = $gatekeeper ?? new Gatekeeper();
    }

    /**
     * Crea un nuevo elemento (POST)
     */
    public function create_item(WP_REST_Request $request, array $config)
    {
        $post_type = $config['post_type'];
        
        $post_data = [
            'post_type'    => $post_type,
            'post_title'   => sanitize_text_field($request->get_param('title') ?: 'Sin Titulo'),
            'post_content' => wp_kses_post($request->get_param('content') ?: ''),
            'post_status'  => 'publish', // En el futuro será influenciado por config si entra a moderation.
        ];

        // Insertar a base de datos
        $post_id = wp_insert_post($post_data, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Procesar metadatos enviados
        $this->update_fields($post_id, $request, $config);

        // Asociar Imagen Destacada si fue proveída (y validar que sea un Attachment real)
        $featured_media = $request->get_param('featured_media');
        if (!empty($featured_media) && is_numeric($featured_media)) {
            $media_id = (int) $featured_media;
            if (get_post_type($media_id) === 'attachment') {
                set_post_thumbnail($post_id, $media_id);
            }
        }

        // Retornar objeto creado, ya estructurado
        $post = get_post($post_id);
        return new WP_REST_Response([
            'message' => 'Elemento creado con éxito.',
            'data'    => $this->serializer->serialize_post($post, $config)
        ], 201);
    }

    /**
     * Actualiza un elemento existente (PATCH/PUT)
     */
    public function update_item(WP_REST_Request $request, array $config)
    {
        $post_type = $config['post_type'];
        $id = (int) $request->get_param('id');
        
        $existing_post = $this->repository->get_single($post_type, $id);
        if (!$existing_post) {
            return new WP_Error('not_found', 'Elemento no encontrado o sin permisos de edición.', ['status' => 404]);
        }

        // --- VALIDACIÓN DE OWNERSHIP ---
        $ownership_check = $this->gatekeeper->verify_ownership($existing_post, 'edit_others_posts');
        if (is_wp_error($ownership_check)) {
            return $ownership_check;
        }
        // -------------------------------

        $post_data = ['ID' => $id];
        
        if ($request->has_param('title')) {
            $post_data['post_title'] = sanitize_text_field($request->get_param('title'));
        }
        if ($request->has_param('content')) {
            $post_data['post_content'] = wp_kses_post($request->get_param('content'));
        }

        // Si mandó contenido base de post nativo
        if (count($post_data) > 1) {
            wp_update_post($post_data);
        }

        // Mutar Custom Fields
        $this->update_fields($id, $request, $config);

        // Modificar Imagen Destacada si fue proveída
        $featured_media = $request->get_param('featured_media');
        if (!empty($featured_media) && is_numeric($featured_media)) {
            $media_id = (int) $featured_media;
            if (get_post_type($media_id) === 'attachment') {
                set_post_thumbnail($id, $media_id);
            }
        } elseif ($request->has_param('featured_media') && empty($featured_media)) {
            // Eliminar imagen destacada si se envió intencionalmente vacío o nulo
            delete_post_thumbnail($id);
        }

        // Responder
        $post = get_post($id);
        return new WP_REST_Response([
            'message' => 'Elemento modificado exitosamente.',
            'data'    => $this->serializer->serialize_post($post, $config)
        ], 200);
    }

    /**
     * Elimina el elemento (DELETE)
     */
    public function delete_item(WP_REST_Request $request, array $config)
    {
        $post_type = $config['post_type'];
        $id = (int) $request->get_param('id');

        $existing_post = $this->repository->get_single($post_type, $id);
        if (!$existing_post) {
            return new WP_Error('not_found', 'Elemento no encontrado o intocable por sus permisos.', ['status' => 404]);
        }

        // --- VALIDACIÓN DE OWNERSHIP ---
        $ownership_check = $this->gatekeeper->verify_ownership($existing_post, 'delete_others_posts');
        if (is_wp_error($ownership_check)) {
            return $ownership_check;
        }
        // -------------------------------

        // En fase futura este bool será configuración de usuario (Enviar a papelera vs Hard Delete)
        $force_delete = false;
        $result = wp_delete_post($id, $force_delete); 
        
        if (!$result) {
            return new WP_Error('delete_failed', 'El gestor falló al intentar borrar tu entrada.', ['status' => 500]);
        }

        return new WP_REST_Response([
            'message' => 'Operación DELETE completada.',
            'id'      => $id
        ], 200);
    }

    /**
     * Auxiliar interno para sobreescribir Custom Fields.
     */
    protected function update_fields(int $post_id, WP_REST_Request $request, array $config)
    {
        $fields_to_update = $request->get_param('fields');
        if (!is_array($fields_to_update)) return;

        $allowed_fields = $config['exposed_fields'] ?? [];

        foreach ($fields_to_update as $key => $value) {
            // Regla estricta: Solo lo "expuesto" puede de vuelta "actualizarse"
            if (in_array($key, $allowed_fields)) {
                // [PENDIENTE] Gatekeeper WRITE Check - Fase 3: ¿Tiene usuario permiso de actualizar este campo aislado?
                
                // [PENDIENTE] Invocación a Data Validator - ¿Es un integer para ACF?
                $value_sanitized = is_string($value) ? sanitize_text_field($value) : $value;
                update_post_meta($post_id, $key, $value_sanitized);
            }
        }
    }
}
