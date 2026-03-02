<?php

namespace WpApiCreator\Schema;

/**
 * Clase responsable de transformar el esquema OpenAPI en una Colección de Postman v2.1.
 */
class PostmanCollectionBuilder
{

    /**
     * Genera el JSON de la colección de Postman basado en el esquema OpenAPI actual.
     */
    public static function build_collection(array $openapi_schema): array {
        $namespace = $openapi_schema['info']['title'] ?? 'WP API CREATOR';
        
        $collection = [
            'info' => [
                'name'        => $namespace,
                'description' => $openapi_schema['info']['description'] ?? '',
                'schema'      => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'
            ],
            'item' => [],
            'variable' => [
                [
                    'key'   => 'base_url',
                    'value' => get_rest_url(null, '/'),
                    'type'  => 'string'
                ]
            ]
        ];

        // Añadir Auth global si está configurado
        if (isset($openapi_schema['security'])) {
            $collection['auth'] = [
                'type'   => 'apikey',
                'apikey' => [
                    ['key' => 'key', 'value' => 'X-API-Key', 'type' => 'string'],
                    ['key' => 'value', 'value' => '{{api_key}}', 'type' => 'string'],
                    ['key' => 'in', 'value' => 'header', 'type' => 'string']
                ]
            ];
            $collection['variable'][] = [
                'key'   => 'api_key',
                'value' => '',
                'type'  => 'string'
            ];
        }

        // Agrupar items por tags
        $items_by_tag = [];

        foreach ($openapi_schema['paths'] as $path => $methods) {
            foreach ($methods as $method => $details) {
                $tag = $details['tags'][0] ?? 'Generales';
                
                if (!isset($items_by_tag[$tag])) {
                    $items_by_tag[$tag] = [
                        'name' => $tag,
                        'item' => []
                    ];
                }

                $items_by_tag[$tag]['item'][] = self::build_item($path, $method, $details);
            }
        }

        $collection['item'] = array_values($items_by_tag);

        return $collection;
    }

    /**
     * Construye un item (request) individual para la colección.
     */
    private static function build_item(string $path, string $method, array $details): array {
        // Convertir {id} a :id para Postman
        $postman_path = str_replace(['{', '}'], [':', ''], ltrim($path, '/'));
        $path_parts = explode('/', $postman_path);

        $request = [
            'name' => $details['summary'] ?? $path,
            'request' => [
                'method' => strtoupper($method),
                'header' => [],
                'url'    => [
                    'raw'   => '{{base_url}}/' . $postman_path,
                    'host'  => ['{{base_url}}'],
                    'path'  => $path_parts
                ],
                'description' => $details['description'] ?? ''
            ]
        ];

        // Manejar parámetros de ruta
        if (isset($details['parameters'])) {
            $variables = [];
            foreach ($details['parameters'] as $param) {
                if ($param['in'] === 'path') {
                    $variables[] = [
                        'key'   => $param['name'],
                        'value' => '1',
                        'description' => $param['description'] ?? ''
                    ];
                }
                if ($param['in'] === 'query') {
                    $request['request']['url']['query'][] = [
                        'key'   => $param['name'],
                        'value' => isset($param['schema']['default']) ? (string)$param['schema']['default'] : '',
                        'description' => $param['description'] ?? ''
                    ];
                }
            }
            if (!empty($variables)) {
                $request['request']['url']['variable'] = $variables;
            }
        }

        // Manejar Body (ej: Auth login)
        if (isset($details['requestBody'])) {
            $request['request']['body'] = [
                'mode' => 'raw',
                'raw'  => json_encode(self::get_example_body($details['requestBody']), JSON_PRETTY_PRINT),
                'options' => [
                    'raw' => [
                        'language' => 'json'
                    ]
                ]
            ];
        }

        return $request;
    }

    /**
     * Genera un ejemplo de body basado en el esquema.
     */
    private static function get_example_body(array $requestBody): array {
        $schema = $requestBody['content']['application/json']['schema'] ?? [];
        $example = [];

        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $prop => $details) {
                $example[$prop] = $details['default'] ?? ($details['type'] === 'string' ? "example_$prop" : 0);
            }
        }

        return $example;
    }
}
