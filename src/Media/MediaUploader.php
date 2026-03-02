<?php

namespace WpApiCreator\Media;

use WP_Error;

/**
 * Gestor avanzado de subida de medios.
 * Valida, sube y asocia archivos usando el motor interno y seguro de WordPress.
 */
class MediaUploader
{

    /**
     * Mime types permitidos por seguridad a través de la API.
     */
    protected $allowed_mime_types = [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'gif'          => 'image/gif',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
        'pdf'          => 'application/pdf',
        // Podemos expandir más tipos, pero restrictivo por defecto.
    ];

    /**
     * Procesa y sube de forma segura un archivo recibido vía $_FILES.
     * 
     * @param array $file_array El array del archivo crudo (ej. $_FILES['file'])
     * @param int|null $author_id El ID del usuario creador (para propiedad)
     * @return int|WP_Error El ID del attachment creado, o un error detallado.
     */
    public function upload_file(array $file_array, ?int $author_id = null)
    {
        
        // Incluimos las dependencias necesarias de WP Admin para poder procesar subidas 'sideloaded' y normales
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        // Overrides temporales para wp_handle_sideload / wp_handle_upload
        $upload_overrides = [
            'test_form' => false,
            'mimes'     => $this->allowed_mime_types // Validación estricta
        ];

        // Validamos explícitamente el tamaño o el error interno de subida de PHP
        if (isset($file_array['error']) && $file_array['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('media_upload_php_error', 'Error en la subida a nivel del servidor (PHP upload error code: ' . $file_array['error'] . ')', ['status' => 400]);
        }

        // Delegar manejo de mover el archivo a WP_Uploads a WordPress de manera segura
        $movefile = wp_handle_upload($file_array, $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            // Éxito al mover el archivo. Construimos el Attachment (Entrada de CPT 'attachment') para la BD
            
            $filename = wp_basename($movefile['file']);
            $wp_filetype = wp_check_filetype($filename, $this->allowed_mime_types);
            
            $attachment = [
                'post_mime_type' => $wp_filetype['type'],
                'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_author'    => $author_id ? $author_id : get_current_user_id()
            ];

            // Insertamos la entrada del Attachment en la BD usando wp_insert_attachment
            $attach_id = wp_insert_attachment($attachment, $movefile['file']);

            if (!is_wp_error($attach_id)) {
                // Generamos los metadatos (ej. tamañitos de miniaturas, width, height, webp auto-gen)
                $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
                wp_update_attachment_metadata($attach_id, $attach_data);
                
                return $attach_id; // Retorna el Integer del attachment creado exitosamente
            } else {
                return new WP_Error('media_attachment_creation_failed', 'Error insertando la entrada final del Attachment en la BD.', ['status' => 500]);
            }
        } else {
            // El override de WP detectó un problema (ej. Mimetype no permitido, inyección)
            return new WP_Error('media_upload_rejected', $movefile['error'], ['status' => 400]);
        }
    }
}
