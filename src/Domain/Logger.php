<?php

namespace WpApiCreator\Domain;

/**
 * Sistema simple de registro de logs mediante la API de Opciones de WordPress.
 * Mantiene un historial limitado de peticiones.
 */
class Logger
{

    const LOGS_OPTION_KEY = 'wp_api_creator_logs';
    const MAX_LOGS =  100; // Límite por motivos de rendimiento en options API
    
    /**
     * Ingresa un nuevo log.
     * En un ambiente de producción extenso, esto usaría una tabla custom.
     * 
     * @param string $endpoint Ruta del endpoint solicitado.
     * @param string $method GET, POST, PUT, DELETE, etc.
     * @param string $ip Dirección IP del solicitante.
     * @param string $user_label El rol del usuario o '(Guest)'
     * @param int $status_code 200, 401, 404, etc.
     * @param string $message Detalle del request.
     */
    public static function log(
        string $endpoint, 
        string $method, 
        string $ip, 
        string $user_label, 
        int $status_code, 
        string $message
    ) {
        $logs = get_option(self::LOGS_OPTION_KEY, []);
        
        $new_log = [
            'timestamp'   => current_time('mysql'),
            'endpoint'    => $method . ' ' . $endpoint,
            'ip'          => $ip,
            'user_label'  => $user_label,
            'status_code' => $status_code,
            'message'     => $message,
            'timestamp_unix' => time(),
        ];

        // Insert at the beginning
        array_unshift($logs, $new_log);

        // Keep only limited history
        if (count($logs) > self::MAX_LOGS) {
            $logs = array_slice($logs, 0, self::MAX_LOGS);
        }

        // Drop logs older than 72 hours
        $cutoff = time() - (72 * 3600);
        $filtered = array_filter($logs, function($l) use ($cutoff) {
            return ($l['timestamp_unix'] >= $cutoff);
        });

        update_option(self::LOGS_OPTION_KEY, array_values($filtered));
    }

    /**
     * Obtiene todos los logs almacenados.
     */
    public static function get_logs(): array {
        return get_option(self::LOGS_OPTION_KEY, []);
    }

    /**
     * Limpia la tabla / opción de logs.
     */
    public static function clear_logs()
    {
        return delete_option(self::LOGS_OPTION_KEY);
    }
}
