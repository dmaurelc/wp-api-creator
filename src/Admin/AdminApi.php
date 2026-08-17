<?php

namespace WpApiCreator\Admin;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WpApiCreator\Domain\ConfigBuilder;
use WpApiCreator\Schema\OpenApiBuilder;
use WpApiCreator\Domain\Logger;

/**
 * Endpoint de administración para el Dashboard de React.
 */
class AdminApi
{
    /**
     * Namespace fijo de las rutas de administracion, distinto del namespace publico
     * configurable. El Router lo excluye de su middleware para que una configuracion
     * que apuntase aqui no permitiese suplantar un usuario via cabecera.
     */
    const ROUTE_NAMESPACE = 'creator/v1';

    protected $namespace = self::ROUTE_NAMESPACE;

    public function register_hooks()
    {
        add_action('rest_api_init', [$this, 'register_admin_routes']);
    }

    public function register_admin_routes()
    {
        // Endpoints
        register_rest_route($this->namespace, '/admin/endpoints', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_endpoints'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);
        
        register_rest_route($this->namespace, '/admin/endpoints', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'save_endpoints'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        // Settings
        register_rest_route($this->namespace, '/admin/settings', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_settings'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        register_rest_route($this->namespace, '/admin/settings', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'save_settings'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        // Build Docs
        register_rest_route($this->namespace, '/admin/build-docs', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'build_docs'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        // OpenAPI JSON
        register_rest_route($this->namespace, '/docs/openapi.json', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_openapi_json'],
            'permission_callback' => [$this, 'check_docs_permissions']
        ]);

        // Swagger UI
        register_rest_route($this->namespace, '/docs', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_swagger_ui'],
            'permission_callback' => [$this, 'check_docs_permissions']
        ]);

        // API Keys y usuarios: modulos propios
        (new ApiKeysAdminController())->register_routes($this->namespace);
        (new UsersAdminController())->register_routes($this->namespace);

        // Logs
        register_rest_route($this->namespace, '/admin/logs', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_logs'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        register_rest_route($this->namespace, '/admin/logs', [
            'methods'  => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'clear_logs'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        // Introspection
        register_rest_route($this->namespace, '/admin/cpt-fields', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_cpt_fields'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        register_rest_route($this->namespace, '/admin/roles', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_roles'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        register_rest_route($this->namespace, '/admin/flush-cache', [
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'flush_cache'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        register_rest_route($this->namespace, '/admin/all-routes', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_all_routes'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        register_rest_route($this->namespace, '/admin/global-routes', [
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'save_global_routes'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        register_rest_route($this->namespace, '/admin/clear-cpt-cache', [
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'clear_cpt_cache'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        // WP Core Endpoint Config
        register_rest_route($this->namespace, '/admin/wp-endpoint-config', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_wp_endpoint_config'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        register_rest_route($this->namespace, '/admin/wp-endpoint-config', [
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'save_wp_endpoint_config'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);

        register_rest_route($this->namespace, '/admin/export/postman', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'export_postman_collection'],
            'permission_callback' => [$this, 'check_admin_permissions']
        ]);
    }

    public function check_admin_permissions()
    {
        return current_user_can('manage_options');
    }

    /**
     * Acceso a la documentacion generada.
     *
     * Con `require_api_key` desactivado la documentacion sigue siendo publica, como hasta
     * ahora. Al activarlo pasa a exigir credencial: el esquema describe los CPT expuestos,
     * sus campos y parametros, y publicarlo abiertamente equivaldria a entregar el mapa
     * completo de una API que el administrador cree cerrada.
     *
     * @return bool
     */
    public function check_docs_permissions()
    {
        $settings = ConfigBuilder::get_global_settings();

        if (empty($settings['require_api_key'])) {
            return true;
        }

        // Las rutas de administracion quedan fuera del middleware del Router, asi que la
        // credencial se resuelve aqui explicitamente.
        if (is_user_logged_in()) {
            return true;
        }

        $api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';

        return $api_key !== '' && (new \WpApiCreator\Auth\ApiKeyProvider())->validate_key((string) $api_key) !== null;
    }

    public function get_endpoints(WP_REST_Request $request)
    {
        $endpoints = ConfigBuilder::get_active_endpoints();
        return new WP_REST_Response([
            'success' => true,
            'data'    => array_values($endpoints)
        ], 200);
    }

    public function save_endpoints(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!isset($data['endpoints']) || !is_array($data['endpoints'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('El formato esperado es {"endpoints": [...] }', 'wp-api-creator')
            ], 400);
        }

        $config = get_option(ConfigBuilder::OPTION_KEY, []);
        if (!is_array($config)) {
            $config = [];
        }
        $config['endpoints'] = $data['endpoints'];
        $saved = ConfigBuilder::save_config($config);

        if ($saved) {
            delete_option(\WpApiCreator\Schema\OpenApiBuilder::CACHE_OPTION_KEY);
            return new WP_REST_Response([
                'success' => true,
                'message' => __('Endpoints guardados correctamente', 'wp-api-creator'),
                'data'    => array_values($config['endpoints'])
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => __('No se pudo guardar la configuración.', 'wp-api-creator')
        ], 500);
    }

    public function get_settings(WP_REST_Request $request)
    {
        return new WP_REST_Response([
            'success' => true,
            'data'    => ConfigBuilder::get_global_settings()
        ], 200);
    }

    public function save_settings(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!isset($data['settings']) || !is_array($data['settings'])) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('El formato esperado es {"settings": {...} }', 'wp-api-creator')
            ], 400);
        }

        $config = get_option(ConfigBuilder::OPTION_KEY, []);
        if (!is_array($config)) {
            $config = [];
        }

        $existing = isset($config['settings']) && is_array($config['settings']) ? $config['settings'] : [];

        // Fusion sobre lo existente y filtrado por whitelist: las claves ausentes del
        // payload conservan su valor y las desconocidas no se persisten.
        $sanitized = SettingsSanitizer::sanitize($data['settings'], $existing);
        if (is_wp_error($sanitized)) {
            return $sanitized;
        }

        $config['settings'] = $sanitized;
        $saved = ConfigBuilder::save_config($config);

        if ($saved) {
            delete_option(\WpApiCreator\Schema\OpenApiBuilder::CACHE_OPTION_KEY);
            return new WP_REST_Response([
                'success' => true,
                'message' => __('Ajustes globales guardados correctamente', 'wp-api-creator'),
                'data'    => ConfigBuilder::get_global_settings()
            ], 200);
        }

        return new WP_REST_Response(['success' => false], 500);
    }

    public function build_docs(WP_REST_Request $request)
    {
        $schema = OpenApiBuilder::build_schema();
        return new WP_REST_Response([
            'success' => true,
            'message' => __('Documentación generada con éxito.', 'wp-api-creator'),
            'schema'  => $schema
        ], 200);
    }

    public function get_openapi_json(WP_REST_Request $request)
    {
        $schema = OpenApiBuilder::get_cached_schema();
        return new WP_REST_Response($schema, 200);
    }

    public function get_logs(WP_REST_Request $request)
    {
        return new WP_REST_Response([
            'success' => true,
            'data'    => Logger::get_logs()
        ], 200);
    }

    public function clear_logs(WP_REST_Request $request)
    {
        Logger::clear_logs();
        return new WP_REST_Response(['success' => true], 200);
    }

    public function get_roles(WP_REST_Request $request)
    {
        $wp_roles = wp_roles();
        $roles = [];
        foreach ($wp_roles->role_names as $key => $name) {
            $roles[] = [
                'val' => $key,
                'name'  => translate_user_role($name)
            ];
        }
        // 'public' es la clave que el Gatekeeper reconoce para acceso sin autenticación
        array_unshift($roles, ['val' => 'public', 'name' => __('Público (Sin Auth)', 'wp-api-creator')]);
        return new WP_REST_Response(['success' => true, 'roles' => $roles], 200);
    }

    public function get_cpt_fields(WP_REST_Request $request)
    {
        $cpt = $request->get_param('cpt');
        if (empty($cpt)) return new \WP_Error('missing_cpt', __('CPT faltante', 'wp-api-creator'), ['status' => 400]);
        $fields = \WpApiCreator\Schema\FieldScanner::get_available_fields($cpt);
        return new WP_REST_Response(['success' => true, 'fields' => $fields], 200);
    }

    public function get_swagger_ui(WP_REST_Request $request)
    {
        $json_url = rest_url('/' . self::ROUTE_NAMESPACE . '/docs/openapi.json');
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WP API CREATOR - Swagger UI</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css" />
    <style>body { margin: 0; background: #fafafa; padding: 20px; }</style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-standalone-preset.js"></script>
    <script>
    window.onload = function() {
      window.ui = SwaggerUIBundle({
        url: "$json_url",
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
        layout: "StandaloneLayout",
        validatorUrl: null
      });
    };
    </script>
</body>
</html>
HTML;
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    public function flush_cache(WP_REST_Request $request)
    {
        \WpApiCreator\Introspection\MetaScanner::clear_all_caches();
        return new WP_REST_Response([
            'success' => true,
            'message' => __('Caché de introspección limpiada correctamente.', 'wp-api-creator')
        ], 200);
    }

    public function clear_cpt_cache(WP_REST_Request $request)
    {
        $cpt = $request->get_param('cpt');
        if (empty($cpt)) {
            return new WP_REST_Response(['success' => false, 'message' => 'CPT missing'], 400);
        }
        \WpApiCreator\Introspection\MetaScanner::clear_cpt_cache($cpt);
        return new WP_REST_Response([
            'success' => true,
            'message' => sprintf(__('Caché para %s limpiada.', 'wp-api-creator'), $cpt)
        ], 200);
    }

    public function get_all_routes(WP_REST_Request $request)
    {
        $server = rest_get_server();
        $routes = $server->get_routes();
        $config_visible = ConfigBuilder::get_global_routes_config();
        
        $response_data = [];
        
        foreach ($routes as $route => $handlers) {
            // Ignorar nuestras propias rutas administrativas para evitar bucles
            if (str_contains($route, 'creator/v1/admin') || str_contains($route, 'creator/v1/docs')) {
                continue;
            }

            // Agrupar por namespace si es posible
            $parts = explode('/', trim($route, '/'));
            $namespace = $parts[0] ?? 'other';
            if (isset($parts[1]) && !empty($parts[1]) && $namespace !== 'wp') {
                $namespace .= '/' . $parts[1];
            }

            $methods_raw = [];
            foreach ($handlers as $handler) {
                if (isset($handler['methods'])) {
                    $m = $handler['methods'];
                    if (is_numeric($m)) {
                        $bitmask_map = [
                            'GET'    => \WP_REST_Server::READABLE,
                            'POST'   => \WP_REST_Server::CREATABLE,
                            'PUT'    => 4, // WP_REST_Server::EDITABLE (Partial)
                            'DELETE' => \WP_REST_Server::DELETABLE,
                            'PATCH'  => 32,
                        ];
                        foreach ($bitmask_map as $name => $mask) {
                            if ($m & $mask) $methods_raw[] = $name;
                        }
                    } else {
                        $methods_raw = array_merge($methods_raw, (array)$m);
                    }
                }
            }
            $methods = array_unique($methods_raw);

            // Filtrar para mostrar solo las principales pedidas por el usuario + CPTs personalizados
            $is_essential = false;
            $essential_bases = ['pages', 'posts', 'media', 'taxonomies', 'tags', 'categories'];
            
            if (str_starts_with($route, '/wp/v2/')) {
                $rest_base = str_replace('/wp/v2/', '', $route);
                
                // Mapeo de nombres amigables para rutas esenciales
                $friendly_map = [
                    'pages'      => __('Páginas', 'wp-api-creator'),
                    'posts'      => __('Entradas (Posts)', 'wp-api-creator'),
                    'media'      => __('Medios (Galería)', 'wp-api-creator'),
                    'taxonomies' => __('Taxonomías', 'wp-api-creator'),
                    'tags'       => __('Etiquetas (Tags)', 'wp-api-creator'),
                    'categories' => __('Categorías', 'wp-api-creator'),
                    'users'      => __('Usuarios', 'wp-api-creator'),
                    'types'      => __('Tipos de Contenido', 'wp-api-creator'),
                    'statuses'   => __('Estados', 'wp-api-creator'),
                ];

                // 1. Check if it's a known essential base
                if (isset($friendly_map[$rest_base])) {
                    $is_essential = true;
                    $friendly_name = $friendly_map[$rest_base];
                } else {
                    // 2. Check if it's a custom post type (Non-builtin)
                    $post_types = get_post_types(['show_in_rest' => true, '_builtin' => false], 'objects');
                    foreach ($post_types as $pt) {
                        if ($pt->rest_base === $rest_base || $pt->name === $rest_base) {
                            $is_essential = true;
                            $friendly_name = $pt->label;
                            break;
                        }
                    }
                }
            } else {
                // Para rutas fuera de /wp/v2/ que el usuario haya activado manualmente o que sean de otros plugins
                $friendly_name = $route;
            }

            // Si no es esencial y no está marcada como visible ya, la saltamos para limpiar el dashboard
            if (!$is_essential && !in_array($route, $config_visible)) {
                continue;
            }

            $response_data[] = [
                'route'     => $route,
                'name'      => $friendly_name,
                'namespace' => $namespace,
                'methods'   => array_values($methods),
                'visible'   => in_array($route, $config_visible)
            ];
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => $response_data
        ], 200);
    }

    public function save_global_routes(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        if (!isset($data['visible_routes']) || !is_array($data['visible_routes'])) {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid data'], 400);
        }

        $config = get_option(ConfigBuilder::OPTION_KEY, []);
        if (!is_array($config)) {
            $config = [];
        }
        $config['global_routes'] = $data['visible_routes'];
        ConfigBuilder::save_config($config);

        delete_option(\WpApiCreator\Schema\OpenApiBuilder::CACHE_OPTION_KEY);

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Configuración de rutas globales guardada.', 'wp-api-creator')
        ], 200);
    }

    /**
     * Obtiene la configuración de un endpoint WP core por post_type.
     */
    public function get_wp_endpoint_config(WP_REST_Request $request)
    {
        $post_type = $request->get_param('post_type');
        if (!$post_type) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Se requiere el parámetro post_type.', 'wp-api-creator')
            ], 400);
        }

        $config = ConfigBuilder::get_wp_endpoint_config($post_type);
        return new WP_REST_Response([
            'success' => true,
            'data'    => $config ?? []
        ], 200);
    }

    /**
     * Guarda la configuración de un endpoint WP core.
     */
    public function save_wp_endpoint_config(WP_REST_Request $request)
    {
        $data = $request->get_json_params();
        $post_type = $data['post_type'] ?? null;

        if (!$post_type) {
            return new WP_REST_Response([
                'success' => false,
                'message' => __('Se requiere el campo post_type.', 'wp-api-creator')
            ], 400);
        }

        $endpoint_config = [
            'post_type'      => $post_type,
            'exposed_fields' => $data['exposed_fields'] ?? [],
            'permissions'    => $data['permissions'] ?? [],
            'description'    => $data['description'] ?? '',
            'enabled'        => $data['enabled'] ?? true,
        ];

        $saved = ConfigBuilder::save_wp_endpoint_config($post_type, $endpoint_config);

        if ($saved) {
            delete_option(OpenApiBuilder::CACHE_OPTION_KEY);
            return new WP_REST_Response([
                'success' => true,
                'message' => __('Configuración de endpoint WP guardada.', 'wp-api-creator'),
                'data'    => $endpoint_config
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => __('No se pudo guardar la configuración.', 'wp-api-creator')
        ], 500);
    }

    /**
     * Genera y sirve la colección de Postman.
     */
    public function export_postman_collection(WP_REST_Request $request)
    {
        $openapi_schema = OpenApiBuilder::build_schema();
        $collection = \WpApiCreator\Schema\PostmanCollectionBuilder::build_collection($openapi_schema);

        // Limpiar cualquier salida previa absoluta para evitar corrupción del JSON
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Forzar descarga como archivo JSON con headers robustos
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="wp-api-postman-collection.json"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
