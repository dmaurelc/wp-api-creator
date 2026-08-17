<?php

namespace WpApiCreator\Admin;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WpApiCreator\Auth\ApiKeyProvider;
use WpApiCreator\Auth\KeyUsageStore;
use WpApiCreator\Domain\ConfigBuilder;

/**
 * Rutas de administracion del ciclo de vida de las API Keys.
 *
 * Cada key queda ligada a un usuario de WordPress y se guarda hasheada: la key en claro
 * solo existe en la respuesta de creacion y no vuelve a estar disponible.
 */
class ApiKeysAdminController
{
    /**
     * @param string $namespace
     * @return void
     */
    public function register_routes(string $namespace): void
    {
        register_rest_route($namespace, '/admin/api-keys', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/admin/api-keys', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'create'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        register_rest_route($namespace, '/admin/api-keys/(?P<id>[\w]+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'delete'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * @return bool
     */
    public function check_permissions(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Listado de keys sin exponer ningun secreto.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $usage = KeyUsageStore::get_all();

        $keys = array_map(function (array $entry) use ($usage) {
            return $this->present($entry, $usage);
        }, ConfigBuilder::get_api_keys());

        return new WP_REST_Response(['success' => true, 'keys' => array_values($keys)], 200);
    }

    /**
     * Crea una key para un usuario concreto y devuelve el secreto una unica vez.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function create(WP_REST_Request $request)
    {
        $user_id = (int) $request->get_param('user_id');
        if ($user_id <= 0 || !get_userdata($user_id)) {
            return new WP_Error(
                'invalid_key_user',
                __('Debes asociar la API Key a un usuario de WordPress existente.', 'wp-api-creator'),
                ['status' => 400]
            );
        }

        // En multisitio `get_userdata()` resuelve cuentas de toda la red. Sin esta guarda,
        // un administrador de sitio — que tiene `manage_options` pero no permisos de red —
        // podria emitir una credencial a nombre de un super admin y operar con sus permisos.
        if (is_multisite() && !is_user_member_of_blog($user_id, get_current_blog_id())) {
            return new WP_Error(
                'invalid_key_user',
                __('El usuario indicado no pertenece a este sitio.', 'wp-api-creator'),
                ['status' => 403]
            );
        }

        $expires_at = $this->parse_expiration($request->get_param('expires_at'));
        if ($expires_at instanceof WP_Error) {
            return $expires_at;
        }

        $name      = sanitize_text_field((string) ($request->get_param('name') ?: __('Nueva API Key', 'wp-api-creator')));
        $plain_key = ApiKeyProvider::generate_key();

        $entry = [
            'id'         => uniqid(),
            'name'       => $name,
            'hash'       => ApiKeyProvider::hash_key($plain_key),
            'prefix'     => ApiKeyProvider::prefix_of($plain_key),
            'user_id'    => $user_id,
            'expires_at' => $expires_at,
            'created_at' => current_time('mysql'),
            'legacy'     => false,
        ];

        $keys   = ConfigBuilder::get_api_keys();
        $keys[] = $entry;

        // Si la escritura falla, el modal mostraria al administrador una clave en claro
        // que no existe en la base de datos: la desplegaria en produccion y todas sus
        // peticiones responderian 401 sin explicacion.
        if (!ConfigBuilder::save_api_keys($keys)) {
            return new WP_Error(
                'api_key_not_saved',
                __('No se pudo guardar la API Key. No se ha creado ninguna credencial.', 'wp-api-creator'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            // Unica vez que el secreto viaja al cliente.
            'plain_key' => $plain_key,
            'key'       => $this->present($entry, []),
        ], 201);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete(WP_REST_Request $request)
    {
        $id = (string) $request->get_param('id');

        $keys      = ConfigBuilder::get_api_keys();
        $remaining = array_filter($keys, function (array $entry) use ($id) {
            return (string) $entry['id'] !== $id;
        });

        if (count($remaining) === count($keys)) {
            return new WP_Error(
                'api_key_not_found',
                __('La API Key indicada no existe.', 'wp-api-creator'),
                ['status' => 404]
            );
        }

        // `array_filter` conserva los indices originales; sin reindexar, borrar la primera
        // entrada haria que json_encode serializase la lista como objeto y el dashboard
        // dejaria de poder recorrerla.
        //
        // El resultado se comprueba: reportar una revocacion que no llego a persistirse
        // haria que el dashboard retirase de la tabla una credencial que sigue autenticando.
        if (!ConfigBuilder::save_api_keys(array_values($remaining))) {
            return new WP_Error(
                'api_key_not_revoked',
                __('No se pudo revocar la API Key. Sigue estando activa.', 'wp-api-creator'),
                ['status' => 500]
            );
        }

        KeyUsageStore::forget($id);

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Proyecta una entrada almacenada a su forma publica.
     *
     * @param array $entry
     * @param array $usage Mapa id => timestamp.
     * @return array
     */
    private function present(array $entry, array $usage): array
    {
        $user_id = isset($entry['user_id']) ? (int) $entry['user_id'] : 0;
        $user    = $user_id > 0 ? get_userdata($user_id) : null;
        $id      = (string) $entry['id'];

        $expires_at = isset($entry['expires_at']) && $entry['expires_at'] ? (int) $entry['expires_at'] : null;

        return [
            'id'           => $id,
            'name'         => $entry['name'] ?? '',
            'prefix'       => $entry['prefix'] ?? '',
            'user_id'      => $user_id,
            'user_name'    => $user ? $user->display_name : null,
            'user_roles'   => $user ? array_values($user->roles) : [],
            'expires_at'   => $expires_at,
            'last_used_at' => isset($usage[$id]) ? (int) $usage[$id] : null,
            'created_at'   => $entry['created_at'] ?? null,
            'legacy'       => !empty($entry['legacy']),
            'expired'      => $expires_at !== null && $expires_at < time(),
        ];
    }

    /**
     * @param mixed $value Timestamp UNIX, fecha ISO o vacio para "sin caducidad".
     * @return int|null|WP_Error
     */
    private function parse_expiration($value)
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);

        if (!$timestamp || $timestamp <= time()) {
            return new WP_Error(
                'invalid_key_expiration',
                __('La fecha de caducidad debe ser futura.', 'wp-api-creator'),
                ['status' => 400]
            );
        }

        return $timestamp;
    }
}
