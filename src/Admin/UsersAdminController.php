<?php

namespace WpApiCreator\Admin;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WpApiCreator\Auth\TokenVersionStore;

/**
 * Rutas de administracion sobre usuarios: selector de propietario de API Keys y
 * revocacion de los tokens vivos de una cuenta.
 */
class UsersAdminController
{
    /** Tope de resultados por peticion del selector. */
    const MAX_RESULTS = 100;

    /**
     * @param string $namespace
     * @return void
     */
    public function register_routes(string $namespace): void
    {
        register_rest_route($namespace, '/admin/users', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'index'],
            'permission_callback' => [$this, 'check_list_permissions'],
            'args'                => [
                'search' => ['type' => 'string', 'required' => false],
                'number' => ['type' => 'integer', 'required' => false, 'default' => self::MAX_RESULTS],
            ],
        ]);

        register_rest_route($namespace, '/admin/users/(?P<id>[\d]+)/tokens', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'revoke_tokens'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);
    }

    /**
     * Listar usuarios corresponde a `list_users`, no a `manage_options`.
     *
     * @return bool
     */
    public function check_list_permissions(): bool
    {
        return current_user_can('list_users');
    }

    /**
     * @return bool
     */
    public function check_admin_permissions(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Busqueda y paginacion resueltas en el servidor.
     *
     * El Combobox del dashboard filtra en cliente sobre el array completo, asi que sin
     * limite un sitio con decenas de miles de suscriptores agotaria la memoria de PHP
     * nada mas abrir la pestana.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $search = trim((string) $request->get_param('search'));
        $number = (int) ($request->get_param('number') ?: self::MAX_RESULTS);
        $number = max(1, min(self::MAX_RESULTS, $number));

        // `fields` acotado a un subconjunto no ceba la cache de usuarios, de modo que leer
        // los roles despues dispararia dos consultas por fila. `all_with_meta` devuelve
        // objetos WP_User completos con sus roles ya resueltos, en el mismo numero de queries.
        $args = [
            'number'  => $number,
            'fields'  => 'all_with_meta',
            'orderby' => 'display_name',
            'order'   => 'ASC',
        ];

        if ($search !== '') {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name', 'user_nicename'];
        }

        $users = array_map(function ($user) {
            return [
                'id'    => (int) $user->ID,
                'name'  => $user->display_name,
                'roles' => array_values((array) $user->roles),
            ];
        }, get_users($args));

        return new WP_REST_Response(['success' => true, 'users' => $users], 200);
    }

    /**
     * Invalida todos los tokens JWT vivos del usuario indicado.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function revoke_tokens(WP_REST_Request $request)
    {
        $user_id = (int) $request->get_param('id');

        if ($user_id <= 0 || !get_userdata($user_id)) {
            return new WP_Error(
                'invalid_user',
                __('El usuario indicado no existe.', 'wp-api-creator'),
                ['status' => 404]
            );
        }

        $version = TokenVersionStore::revoke($user_id);

        if ($version === 0) {
            return new WP_Error(
                'tokens_not_revoked',
                __('No se pudieron invalidar los tokens. Siguen activos.', 'wp-api-creator'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success'       => true,
            'token_version' => $version,
            'message'       => __('Los tokens activos de este usuario han sido invalidados.', 'wp-api-creator'),
        ], 200);
    }
}
