<?php

namespace WpApiCreator\Api\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WpApiCreator\Media\MediaUploader;
use WpApiCreator\Permissions\Gatekeeper;

/**
 * Controlador de Medios.
 * Expone endpoints para subida de imágenes permitiendo interacciones Multipart
 * desde aplicaciones clientes separadas.
 */
class MediaController
{

    protected $uploader;
    protected $gatekeeper;

    public function __construct(MediaUploader $uploader, Gatekeeper $gatekeeper)
    {
        $this->uploader = $uploader;
        $this->gatekeeper = $gatekeeper; 
    }

    /**
     * Procesa e ingiere un archivo enviado mediante form-data Multipart.
     * Endpoint esperado: POST /v1/media
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function upload_media(WP_REST_Request $request)
    {
        
        // Para enviar un media por API hay que estar autenticado.
        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', 'Lo siento, no tienes permisos para subir archivos. Debes estar autenticado con un Token JWT correcto o Cookie del sistema.', ['status' => 401]);
        }

        // Estar autenticado no basta: escribir en wp-content/uploads exige la misma
        // capacidad que WordPress pide en el escritorio. Sin esto, una API Key de un
        // suscriptor podria subir archivos que ese usuario no puede subir desde wp-admin.
        if (!current_user_can('upload_files')) {
            return new WP_Error('rest_forbidden', 'Tu usuario no tiene la capacidad `upload_files` necesaria para subir archivos.', ['status' => 403]);
        }


        // Verificamos si en $_FILES se ha recibido algo esperado
        $files = $request->get_file_params();

        // Buscamos iterar sobre ellos, usualmente las APis los mandarán como la key 'file' o 'media' en FormData
        if (empty($files) || (!isset($files['file']) && !isset($files['media']))) {
            return new WP_Error('media_no_file_provided', 'No se proporcionó ningún archivo multipart con la llave `file` o `media`.', ['status' => 400]);
        }

        // Determinar dinámicarte la key que nos envió la app Frontend
        $file_key = isset($files['file']) ? 'file' : 'media';
        $uploaded_file = $files[$file_key];

        // Validamos si nos enviaron UN solo archivo, o si nos están mandando varios en un array a la vez.
        // Por la forma en que PHP parsea form-datas de multiples archivos es un poco sucias. Asumiremos para simplificar: un solo media a la vez.
        if (is_array($uploaded_file['name'])) {
            return new WP_Error('media_upload_multiplex_not_supported', 'Esta ruta API de subida masiva asicrona está diseñada para subir de a un solo archivo con llave sencilla a la vez.', ['status' => 400]);
        }

        // Llamamos al motor Core Subiendo el archivo de form cruso
        $result = $this->uploader->upload_file($uploaded_file, get_current_user_id());

        // Manejamos los WP_Error
        if (is_wp_error($result)) {
            return $result;
        }

        // En este punto, la llamada fue un éxito y el core creó la imagen.
        $attachment_id = $result;
        
        // Retornamos el payload Limpio con el ID adjunto de forma sincrona a la App para que ella haga
        // otra petición ahora sí asignando ese ID a una nueva casa / post object
        return new WP_REST_Response([
            'message' => 'El archivo se ha subido con éxito.',
            'media' => [
                'id' => $attachment_id,
                'source_url' => wp_get_attachment_url($attachment_id),
                'media_type' => get_post_mime_type($attachment_id)
            ]
        ], 201); // 201 Created
    }
}
