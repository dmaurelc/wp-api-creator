<?php
/**
 * Plugin Name: WP Custom API Creator
 * Plugin URI:  #
 * Description: Convierte WordPress en una API personalizada configurable desde un dashboard propio.
 * Version:     1.0.0
 * Author:      Daniel Maurel
 * Text Domain: wp-api-creator
 */

use WpApiCreator\Core\Plugin;

// Si se accede a este archivo directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

// Definir constantes del plugin
define('WP_API_CREATOR_VERSION', '1.0.0');
define('WP_API_CREATOR_DIR', plugin_dir_path(__FILE__));
define('WP_API_CREATOR_URL', plugin_dir_url(__FILE__));

// Autoloader de Composer o Fallback nativo
$autoload_path = WP_API_CREATOR_DIR . 'vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
} else {
    // Fallback de Autoloader PSR-4 Nativo para no forzar Composer en desarrollo
    spl_autoload_register(function ($class) {
        $prefix = 'WpApiCreator\\';
        $base_dir = WP_API_CREATOR_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });

    // Sin alertas intrusivas. Operamos con el autoloader PSR-4 nativo sin ruido en modo desarrollo o local.
}

// Punto de entrada principal
function wp_api_creator_init() {
    $plugin = new Plugin();
    $plugin->init();
}

// Bootstrap
add_action('plugins_loaded', 'wp_api_creator_init');

// Hooks de activación y desactivación
register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);
