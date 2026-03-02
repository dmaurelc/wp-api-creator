<?php

namespace WpApiCreator\Api;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WpApiCreator\Domain\ConfigBuilder;
use WpApiCreator\Domain\Repositories\DynamicQueryBuilder;
use WpApiCreator\Api\OutputSerializer;
use WpApiCreator\Api\Controllers\CollectionController;
use WpApiCreator\Api\Controllers\SingleEntityController;
use WpApiCreator\Api\Controllers\MutationController;
use WpApiCreator\Api\Controllers\AuthController;
use WpApiCreator\Api\Controllers\MediaController;
use WpApiCreator\Permissions\Gatekeeper;
use WpApiCreator\Auth\AuthMiddleware;
use WpApiCreator\Auth\JwtProvider;
use WpApiCreator\Auth\ApiKeyProvider;
use WpApiCreator\Media\MediaUploader;
use WpApiCreator\Domain\Logger;

class Router
{
    
    /**
     * @var string
     */
    protected $namespace;

    /**
     * @var Gatekeeper
     */
    protected $gatekeeper;

    /**
     * @var AuthMiddleware
     */
    protected $auth_middleware;

    public function __construct()
    {
        $this->namespace = ConfigBuilder::get_global_settings()['api_namespace'] ?? 'wp-custom-api/v1';
        $this->gatekeeper = new Gatekeeper();
        $this->auth_middleware = new AuthMiddleware(new JwtProvider(), new ApiKeyProvider(), new \WpApiCreator\Auth\ApplicationPasswordProvider());
    }

    /**
     * Inicializa los ganchos de ruteo.
     */
    public function register_hooks(): void
    {
        // Enlaza el router dinámico
        add_action('rest_api_init', [$this, 'register_dynamic_routes']);
        
        // El middleware debe interceptar ANTES de que WP compruebe los permisos rest
        add_filter('rest_pre_dispatch', [$this, 'run_auth_middleware'], 10, 3);

        // Logging global para después de despachar la petición en nuestro namespace
        add_filter('rest_post_dispatch', [$this, 'log_api_activity'], 10, 3);

        // Filtrado de campos en endpoints WP core (si está habilitado)
        $settings = ConfigBuilder::get_global_settings();
        if (!empty($settings['filter_wp_endpoints'])) {
            $this->register_wp_endpoint_filters();
        }
    }

    /**
     * Registra filtros rest_prepare_{post_type} para filtrar campos de endpoints WP core.
     */
    protected function register_wp_endpoint_filters(): void
    {
        $wp_configs = ConfigBuilder::get_wp_endpoint_configs();
        if (empty($wp_configs)) return;

        foreach ($wp_configs as $post_type => $config) {
            if (empty($config['exposed_fields'])) continue;
            
            add_filter("rest_prepare_{$post_type}", function($response, $post, $request) use ($config) {
                return $this->filter_wp_response($response, $post, $request, $config);
            }, 99, 3);
        }
    }

    /**
     * Filtra la respuesta de un endpoint WP core según los campos expuestos configurados.
     */
    protected function filter_wp_response($response, $post, $request, $config)
    {
        $data = $response->get_data();
        if (!is_array($data)) return $response;

        $exposed = array_map('strval', $config['exposed_fields']);
        if (empty($exposed)) return $response;

        $filtered = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $exposed, true)) {
                $filtered[$key] = $value;
            }
        }

        // Asegurar que siempre incluya 'id' si existe
        if (isset($data['id']) && !isset($filtered['id'])) {
            $filtered['id'] = $data['id'];
        }

        // Inyectar meta fields configurados que no están en la respuesta nativa
        foreach ($exposed as $field_key) {
            if (!isset($filtered[$field_key]) && !isset($data[$field_key])) {
                $meta_value = get_post_meta($post->ID, $field_key, true);
                if ($meta_value !== '' && $meta_value !== false) {
                    $filtered[$field_key] = $meta_value;
                }
            }
        }

        $response->set_data($filtered);
        return $response;
    }

    /**
     * Corre el middleware de autenticación (JWT & API Keys) interceptando cada request al namespace.
     */
    public function run_auth_middleware($result, $server, $request)
    {
        // Solo nos interesa interceptar si cae en las rutas de nuestro propio plugin
        if (strpos($request->get_route(), '/' . $this->namespace) === 0) {
            $this->auth_middleware->authenticate_request($request);
        }
        return $result; // Pass-through
    }

    /**
     * Intercepta la respuesta final antes de emitirla para dejar el registro en el Logger.
     */
    public function log_api_activity($response, $server, $request)
    {
        $route = $request->get_route();
        
        // Solo registrar los logs en el namespace de la API (evitar spam de core WP)
        if (strpos($route, '/' . $this->namespace) === 0 && strpos($route, '/admin') === false) {
            $method = $request->get_method();
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            
            // Determinar etiqueta del usuario
            $user_id = get_current_user_id();
            $user_label = '(Guest)';
            if ($user_id) {
                $user = get_userdata($user_id);
                if ($user) {
                    $roles = $user->roles;
                    $user_label = 'User ID: ' . $user_id . (empty($roles) ? '' : ' (' . implode(',', $roles) . ')');
                }
            } else if (isset($_SERVER['HTTP_X_API_KEY'])) {
                // Posible clave de API estática (sin user atado temporalmente en este scope o es global)
                $user_label = '(API Key)'; 
            }

            // Obtener el código de estado
            $status_code = 200;
            if (is_wp_error($response)) {
                $error_data = $response->get_error_data();
                $status_code = isset($error_data['status']) ? $error_data['status'] : 500;
                $message = $response->get_error_message();
            } else if (method_exists($response, 'get_status')) {
                $status_code = $response->get_status();
                $data = $response->get_data();
                if (isset($data['message'])) {
                    $message = $data['message'];
                } else {
                    $message = sprintf(__('Petición %s exitosa en %s', 'wp-api-creator'), $method, $route);
                }
            } else {
                $message = sprintf(__('Petición %s procesada.', 'wp-api-creator'), $method);
            }

            // Guarda el Log 
            Logger::log(
                $route, 
                $method, 
                $ip, 
                $user_label, 
                $status_code, 
                mb_substr($message, 0, 150) // un poco más de margen
            );
        }

        return $response;
    }

    /**
     * Lee la configuración activa y registra los endpoints dinámicos en WP REST API.
     */
    public function register_dynamic_routes(): void
    {
        // En una implementación real, ConfigBuilder leería options de DB.
        $active_endpoints = ConfigBuilder::get_active_endpoints();

        // 1. REGISTRAR RUTA DE AUTENTICACION JWT (POST /v1/auth/token)
        register_rest_route($this->namespace, '/auth/token', [
            'methods'  => WP_REST_Server::CREATABLE, // POST
            'callback' => [$this, 'handle_auth_login'],
            'permission_callback' => '__return_true' // Auth logic doesn't require pre-auth
        ]);

        // 2. REGISTRAR RUTA GLOBAL DE SUBIDA DE MEDIOS (POST /v1/media)
        register_rest_route($this->namespace, '/media', [
            'methods'  => WP_REST_Server::CREATABLE, // POST
            'callback' => [$this, 'handle_media_upload'],
            'permission_callback' => '__return_true' // La protección contra intrusos la maneja el controlador validando si está autenticado
        ]);

        // 3. REGISTRAR RUTAS DINÁMICAS (CPTs Expuestos)
        foreach ($active_endpoints as $endpoint) {
            $base_route = '/' . $endpoint['slug'];

            // Endpoint: Colección (GET) y Creación (POST)
            register_rest_route($this->namespace, $base_route, [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'handle_collection_get'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                    'args'                => $this->get_collection_args(),
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$this, 'handle_create_post'],
                    'permission_callback' => [$this, 'check_write_permissions'],
                ],
            ]);

            // Endpoint: Elemento único (GET, PATCH, DELETE)
            $single_route = $base_route . '/(?P<id>[\d]+)';
            register_rest_route($this->namespace, $single_route, [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$this, 'handle_single_get'],
                    'permission_callback' => [$this, 'check_read_permissions'],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE, // PUT, PATCH
                    'callback'            => [$this, 'handle_update_post'],
                    'permission_callback' => [$this, 'check_write_permissions'],
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [$this, 'handle_delete_post'],
                    'permission_callback' => [$this, 'check_delete_permissions'],
                ],
            ]);
        }
    }

    // === MODIFICANDO ENDPOINTS DE MOCK A CODIGO EFECTIVO ===

    /**
     * Enlace con el AuthController para generar Tokens.
     */
    public function handle_auth_login(WP_REST_Request $request) {
        $controller = new AuthController(new JwtProvider());
        return $controller->generate_token($request);
    }

    /**
         * Enlace con el MediaController para Subidas
         */
        public function handle_media_upload(WP_REST_Request $request) {
            $controller = new MediaController(new MediaUploader(), $this->gatekeeper);
            return $controller->upload_media($request);
        }

    /**
     * Extrae el Config particular del Post Type que se está consultando dinámicamente.
     */
    protected function get_config_for_request(WP_REST_Request $request) {
        $route_base = str_replace('/' . $this->namespace . '/', '', $request->get_route());
        $route_slug = explode('/', ltrim($route_base, '/'))[0]; 
        
        $config = ConfigBuilder::get_active_endpoints();
        foreach($config as $c) {
            if (isset($c['slug']) && $c['slug'] === $route_slug) {
                return $c;
            }
        }
        return null; // Si devuelve null el enrutador fallará educadamente
    }

    public function handle_collection_get(WP_REST_Request $request) {
        $config = $this->get_config_for_request($request);
        if (!$config) return new WP_REST_Response(['message' => 'Resource Config Lost'], 500);

        // Proveemos controladores en modo semi-fábrica
        $controller = new CollectionController(new DynamicQueryBuilder(), new OutputSerializer());
        return $controller->get_items($request, $config);
    }

    public function handle_create_post(WP_REST_Request $request) {
        $config = $this->get_config_for_request($request);
        if (!$config) return new WP_REST_Response(['message' => 'Resource Config Lost'], 500);

        $controller = new MutationController(new DynamicQueryBuilder(), new OutputSerializer());
        return $controller->create_item($request, $config);
    }

    public function handle_single_get(WP_REST_Request $request) {
        $config = $this->get_config_for_request($request);
        if (!$config) return new WP_REST_Response(['message' => 'Resource Config Lost'], 500);

        $controller = new SingleEntityController(new DynamicQueryBuilder(), new OutputSerializer());
        return $controller->get_item($request, $config);
    }

    public function handle_update_post(WP_REST_Request $request) {
        $config = $this->get_config_for_request($request);
        if (!$config) return new WP_REST_Response(['message' => 'Resource Config Lost'], 500);

        $controller = new MutationController(new DynamicQueryBuilder(), new OutputSerializer());
        return $controller->update_item($request, $config);
    }

    public function handle_delete_post(WP_REST_Request $request) {
        $config = $this->get_config_for_request($request);
        if (!$config) return new WP_REST_Response(['message' => 'Resource Config Lost'], 500);

        $controller = new MutationController(new DynamicQueryBuilder(), new OutputSerializer());
        return $controller->delete_item($request, $config);
    }

    // --- MIDDLEWARES ACL / PERMISOS (GATEKEEPER) ---

    public function check_read_permissions(WP_REST_Request $request) {
        return $this->run_gatekeeper($request, 'GET');
    }

    public function check_write_permissions(WP_REST_Request $request) {
        $method = $request->get_method(); // Captura dinámicamente si es POST, PUT o PATCH
        return $this->run_gatekeeper($request, $method);
    }

    public function check_delete_permissions(WP_REST_Request $request) {
        return $this->run_gatekeeper($request, 'DELETE');
    }

    /**
     * Auxiliar interno para despachar la autorización tempranamente a través del Gatekeeper.
     */
    protected function run_gatekeeper(WP_REST_Request $request, string $method) {
        $config = $this->get_config_for_request($request);
        if (!$config) {
            return new \WP_Error('config_lost', 'El recurso solicitado no se encuentra habilitado.', ['status' => 404]);
        }
        
        return $this->gatekeeper->authorize_endpoint($request, $config, $method);
    }

    /**
     * Define los parámetros aceptados para el GET de colecciones
     */
    protected function get_collection_args(): array {
        return [
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'sanitize_callback' => 'absint',
            ],
            'limit' => [
                'type' => 'integer',
                'default' => 10,
                'sanitize_callback' => 'absint',
            ],
            '_include' => [
                'type' => 'string',
                'description' => 'Relaciones a incrustar separadas por coma.',
            ]
        ];
    }
}
