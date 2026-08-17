<?php
/**
 * Clase responsable de generar y almacenar la especificación OpenAPI 3.0.x.
 */

namespace WpApiCreator\Schema;

use WpApiCreator\Api\CollectionArgs;
use WpApiCreator\Domain\ConfigBuilder;

class OpenApiBuilder
{

    const CACHE_OPTION_KEY = 'wp_api_creator_openapi_cache';

    /**
     * Construye el esquema OpenAPI completo de forma dinámica.
     */
    public static function build_schema(): array {
        $endpoints = ConfigBuilder::get_active_endpoints();
        
        $schema = [
            'openapi' => '3.0.3',
            'info' => [
                'title'       => 'WP API CREATOR - Custom API Documentation',
                'description' => 'Documentación generada automáticamente basada en la configuración de endpoints dinámicos.',
                'version'     => '1.0.0',
                'contact'     => [
                    'name' => 'Support',
                ]
            ],
            'servers' => [
                [
                    'url'         => get_rest_url(null, '/'),
                    'description' => 'WordPress REST API Root'
                ]
            ],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'ApiKeyAuth' => [
                        'type' => 'apiKey',
                        'in'   => 'header',
                        'name' => 'X-API-Key'
                    ],
                    'BearerAuth' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'bearerFormat' => 'JWT'
                    ]
                ]
            ]
        ];

        // 1. Obtener namespace dinámico
        $namespace = ConfigBuilder::get_global_settings()['api_namespace'];

        // 2. Añadir endpoints estáticos (Core)
        self::add_static_paths($schema, $namespace);

        // 3. Añadir endpoints dinámicos (Configurados por el usuario)
        foreach ($endpoints as $endpoint) {
            if (empty($endpoint['enabled'])) {
                continue;
            }

            $slug = '/' . $namespace . '/' . ltrim($endpoint['slug'], '/');
            $post_type = $endpoint['post_type'] ?? '';
            $permissions = $endpoint['permissions'] ?? [];
            $pt_object = get_post_type_object($post_type);
            $label = $pt_object ? $pt_object->label : ucfirst($post_type);
            
            // --- Ruta de Colección ---
            $path_item = [];
            
            if (!empty($permissions['read'])) {
                $path_item['get'] = [
                    'tags'        => [$label],
                    'summary'     => sprintf(__('Listar %s', 'wp-api-creator'), $label),
                    'description' => $endpoint['description'] ?? sprintf(__('Obtiene una lista paginada de %s.', 'wp-api-creator'), $label),
                    'parameters'  => self::params_for($endpoint),
                    'responses'   => [
                        '200' => ['description' => 'Operación exitosa']
                    ]
                ];
            }

            if (!empty($permissions['write'])) {
                $path_item['post'] = [
                    'tags'      => [$label],
                    'summary'   => sprintf(__('Crear %s', 'wp-api-creator'), $label),
                    'responses' => [
                        '201' => ['description' => __('Recurso creado correctamente', 'wp-api-creator')]
                    ]
                ];
            }

            if (!empty($path_item)) {
                $schema['paths'][$slug] = $path_item;
            }

            // --- Ruta de Elemento Único ---
            $single_slug = $slug . '/{id}';
            $single_path = [];

            if (!empty($permissions['read'])) {
                $single_path['get'] = [
                    'tags'       => [$label],
                    'summary'    => sprintf(__('Obtener un %s específico', 'wp-api-creator'), $label),
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses'  => ['200' => ['description' => __('Detalle del recurso', 'wp-api-creator')]]
                ];
            }

            if (!empty($permissions['write'])) {
                $single_path['patch'] = [
                    'tags'       => [$label],
                    'summary'    => sprintf(__('Actualizar %s', 'wp-api-creator'), $label),
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses'  => ['200' => ['description' => __('Recurso actualizado', 'wp-api-creator')]]
                ];
            }

            if (!empty($permissions['delete'])) {
                $single_path['delete'] = [
                    'tags'       => [$label],
                    'summary'    => sprintf(__('Eliminar %s', 'wp-api-creator'), $label),
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses'  => ['200' => ['description' => __('Recurso eliminado', 'wp-api-creator')]]
                ];
            }

            if (!empty($single_path)) {
                $schema['paths'][$single_slug] = $single_path;
            }
        }

        // Construir lista de post_types ya cubiertos por endpoints custom para no duplicar
        $covered_post_types = [];
        foreach ($endpoints as $ep) {
            if (!empty($ep['enabled']) && !empty($ep['post_type'])) {
                $covered_post_types[] = $ep['post_type'];
            }
        }

        // 4. Añadir rutas globales marcadas como visibles
        $global_visible = ConfigBuilder::get_global_routes_config();
        if (!empty($global_visible)) {
            $server = rest_get_server();
            $all_routes = $server->get_routes();

            // Mapa de nombres amigables para rutas WP conocidas
            $friendly_map = [
                '/wp/v2/posts'      => __('Entradas (Posts)', 'wp-api-creator'),
                '/wp/v2/pages'      => __('Páginas', 'wp-api-creator'),
                '/wp/v2/media'      => __('Medios', 'wp-api-creator'),
                '/wp/v2/categories' => __('Categorías', 'wp-api-creator'),
                '/wp/v2/tags'       => __('Etiquetas', 'wp-api-creator'),
                '/wp/v2/users'      => __('Usuarios', 'wp-api-creator'),
                '/wp/v2/taxonomies' => __('Taxonomías', 'wp-api-creator'),
                '/wp/v2/types'      => __('Tipos de Contenido', 'wp-api-creator'),
                '/wp/v2/statuses'   => __('Estados', 'wp-api-creator'),
            ];
            
            foreach ($global_visible as $route) {
                // Verificar que la ruta exista y no sea una de nuestras rutas internas
                if (!isset($all_routes[$route]) || str_contains($route, $namespace)) {
                    continue;
                }

                // Evitar duplicar CPTs que ya tienen un endpoint custom
                if (str_starts_with($route, '/wp/v2/')) {
                    $rest_base = str_replace('/wp/v2/', '', $route);
                    $is_duplicate = false;
                    foreach ($covered_post_types as $cpt) {
                        $pt_obj = get_post_type_object($cpt);
                        $cpt_base = $pt_obj ? ($pt_obj->rest_base ?: $cpt) : $cpt;
                        if ($cpt_base === $rest_base || $cpt === $rest_base) {
                            $is_duplicate = true;
                            break;
                        }
                    }
                    if ($is_duplicate) {
                        continue;
                    }
                }

                $handlers = $all_routes[$route];
                $path_item = [];
                $tag = $friendly_map[$route] ?? 'Global Routes';
                
                foreach ($handlers as $handler) {
                    if (!isset($handler['methods']) || !is_array($handler['methods'])) {
                        continue;
                    }
                    // WordPress devuelve methods como ['GET' => true, 'POST' => true]
                    foreach (array_keys($handler['methods']) as $method) {
                        $m = strtolower($method);
                        if (isset($path_item[$m])) {
                            continue; // Evitar duplicados
                        }
                        $path_item[$m] = [
                            'tags'    => [$tag],
                            'summary' => sprintf(__('Acceso a %s vía %s', 'wp-api-creator'), $tag, strtoupper($method)),
                            'responses' => [
                                '200' => ['description' => __('Operación exitosa', 'wp-api-creator')]
                            ]
                        ];
                    }
                }

                if (!empty($path_item)) {
                    $schema['paths'][$route] = $path_item;
                }
            }
        }

        // Aplicar seguridad global si se requiere
        $settings = ConfigBuilder::get_global_settings();
        if (!empty($settings['require_api_key'])) {
            $schema['security'] = [['ApiKeyAuth' => []]];
        }

        update_option(self::CACHE_OPTION_KEY, $schema);
        return $schema;
    }

    protected static function add_static_paths(&$schema, string $namespace)
    {
        $settings = ConfigBuilder::get_global_settings();
        
        // Auth: POST /auth/token - Solo si JWT está habilitado (o algún método que use auth dinámico)
        // Por simplicidad, lo incluimos si el namespace de la API está configurado y no se ha deshabilitado explícitamente
        $auth_enabled = $settings['enable_jwt'] ?? true; 
        
        if ($auth_enabled) {
            $schema['paths']["/{$namespace}/auth/token"] = [
                'post' => [
                    'tags'        => ['Auth'],
                    'summary'     => 'Generar Token JWT',
                    'description' => 'Intercambia credenciales de WordPress por un token de acceso.',
                    'requestBody' => [
                        'required' => true,
                        'content'  => [
                            'application/json' => [
                                'schema' => [
                                    'type'       => 'object',
                                    'required'   => ['username', 'password'],
                                    'properties' => [
                                        'username' => ['type' => 'string'],
                                        'password' => ['type' => 'string']
                                    ]
                                ]
                            ]
                        ]
                    ],
                    'responses' => [
                        '200' => ['description' => 'Token JWT generado'],
                        '403' => ['description' => 'Credenciales inválidas']
                    ]
                ]
            ];
        }

        // Media: POST /media - Solo si se ha habilitado específicamente (podemos usar una opción similar)
        $media_enabled = $settings['enable_media_endpoint'] ?? false;
        
        if ($media_enabled) {
            $schema['paths']["/{$namespace}/media"] = [
                'post' => [
                    'tags'        => ['Media'],
                    'summary'     => 'Subir Archivos',
                    'description' => 'Sube un archivo a la biblioteca de medios de WordPress.',
                    'security'    => [['BearerAuth' => []]],
                    'responses'   => [
                        '201' => ['description' => 'Archivo subido correctamente']
                    ]
                ]
            ];
        }
    }

    /**
     * Parámetros de consulta de la ruta de colección de un endpoint.
     *
     * Se derivan de `CollectionArgs`, la misma lista con la que el Router registra la
     * ruta. Antes eran una lista fija idéntica para todos los endpoints, y de ahí venía
     * el patrón que este release corta: documentación que describe parámetros que no
     * existen, y parámetros reales que nadie documenta.
     *
     * @param array $config Configuración del endpoint.
     * @return array Lista de objetos `parameter` de OpenAPI.
     */
    public static function params_for(array $config): array
    {
        $parameters = [];

        foreach (CollectionArgs::for_endpoint($config) as $name => $spec) {
            $parameters[] = [
                'name'        => $name,
                'in'          => 'query',
                'schema'      => self::to_openapi_schema($spec),
                'description' => (string) ($spec['description'] ?? ''),
            ];
        }

        return $parameters;
    }

    /**
     * Traduce una especificación de argumento de WP REST a un `schema` de OpenAPI.
     *
     * Los argumentos de WordPress mezclan validación y saneado —`sanitize_callback` es un
     * callable— mientras que OpenAPI solo describe la forma del dato. La traducción se
     * queda con lo que ambos formatos comparten.
     *
     * @param array $spec
     * @return array
     */
    protected static function to_openapi_schema(array $spec): array
    {
        $schema = ['type' => (string) ($spec['type'] ?? 'string')];

        if (array_key_exists('default', $spec)) {
            $schema['default'] = $spec['default'];
        }

        if (!empty($spec['enum']) && is_array($spec['enum'])) {
            $schema['enum'] = array_values($spec['enum']);
        }

        return $schema;
    }

    public static function get_cached_schema(): array {
        $schema = get_option(self::CACHE_OPTION_KEY);
        return is_array($schema) ? $schema : self::build_schema();
    }
}
