<?php

namespace WpApiCreator\Admin;

use WpApiCreator\Core\Container;

class AdminMenu
{
    protected $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function register_hooks()
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function add_menu_page()
    {
        add_menu_page(
            'Configuración API Creator',
            'API Creator',
            'manage_options',
            'wp-api-creator-dashboard',
            [$this, 'render_dashboard'],
            'dashicons-rest-api',
            30
        );
    }

    public function enqueue_scripts($hook)
    {
        if ($hook !== 'toplevel_page_wp-api-creator-dashboard') {
            return;
        }

        $asset_file = WP_API_CREATOR_DIR . 'build/index.asset.php';
        $dependencies = ['wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n'];
        $version = WP_API_CREATOR_VERSION;

        // Si existe el archivo generado por @wordpress/scripts, lo usamos
        if (file_exists($asset_file)) {
            $asset = require $asset_file;
            $dependencies = $asset['dependencies'];
            $version = $asset['version'];
        }

        wp_enqueue_script(
            'wp-api-creator-admin-app',
            WP_API_CREATOR_URL . 'build/index.js',
            $dependencies,
            $version,
            true
        );

        // Encolamos estilos (tanto del paquete como propios si se generaron en build/index.css)
        wp_enqueue_style('wp-components');
        
        if (file_exists(WP_API_CREATOR_DIR . 'build/style-index.css')) {
            wp_enqueue_style(
                'wp-api-creator-admin-style',
                WP_API_CREATOR_URL . 'build/style-index.css',
                ['wp-components'],
                $version
            );
        }

        // Variables inyectadas en window.wpApiCreatorData
        wp_localize_script('wp-api-creator-admin-app', 'wpApiCreatorData', [
            // La version del plugin, no `$version`: esa se sobrescribe con el hash de
            // build cuando existe index.asset.php. El dashboard la tenia escrita a mano
            // y quedo desfasada en cuanto se publico una version nueva.
            'version'  => WP_API_CREATOR_VERSION,
            'root_url' => esc_url_raw(rest_url('creator/v1/')),
            'docs_url' => esc_url_raw(rest_url('creator/v1/docs')),
            'openapi_json_url' => esc_url_raw(rest_url('creator/v1/docs/openapi.json')),
            'admin_url' => admin_url(),
            'nonce'    => wp_create_nonce('wp_rest')
        ]);
    }

    public function render_dashboard()
    {
        // Contenedor principal para montar la app React
        echo '<div class="wrap"><div id="wp-api-creator-app"><p style="padding:20px; background:#fff; border-left:4px solid #00a0d2;">Cargando Dashboard de React... (Si este mensaje no desaparece, recuerda ejecutar <code>npm run build</code> en la carpeta del plugin)</p></div></div>';
    }
}
