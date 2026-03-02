<?php

namespace WpApiCreator\Permissions;

use WP_REST_Request;
use WP_Error;

/**
 * Gatekeeper: Validador Central para gobernar el acceso.
 * Basado en la sesión de WP actual y los Permisos Configurados en el Dashboard.
 */
class Gatekeeper
{

    /**
     * Evalúa si la persona realizando el request tiene permiso total para acceder al recurso y método.
     * Retorna 'true' si pasa la evaluación, o un WP_Error si es rechazada, lo cual WordPress
     * interpreta deteniendo la petición antes de que toque los controladores.
     *
     * @param WP_REST_Request $request
     * @param array $config
     * @param string $method Método simplificado (GET, POST, PATCH, DELETE)
     * @return true|WP_Error
     */
    public function authorize_endpoint(WP_REST_Request $request, array $config, string $method)
    {
        
        $permissions = $config['permissions'] ?? [];
        
        // Buscamos la regla especifica para este método (Ej: 'read' para GET, 'create' para POST)
        $action = $this->map_method_to_action($method);
        if (!$action) {
            return new WP_Error('gatekeeper_method_forbidden', 'Método HTTP no soportado por el Gatekeeper', ['status' => 405]);
        }

        $allowed_roles = $permissions[$action] ?? [];

        // 1. Regla de Oro Frontal: Si incluye 'public' o 'guest' (legacy), nadie es detenido. Todo público.
        if (in_array('public', $allowed_roles) || in_array('guest', $allowed_roles)) {
            return true;
        }

        // 2. Si no es público, requerimos un usuario logeado en WP (Cookie ahora, luego será JWT/Token F4).
        if (!is_user_logged_in()) {
            return new WP_Error('gatekeeper_unauthorized', 'Desautorizado. Se requiere una sesión activa (Token o Cookie) para este nivel de acceso.', ['status' => 401]);
        }

        $current_user = wp_get_current_user();
        
        // 3. Regla Intersección de Roles: 
        // ¿El usuario tiene alguno de los roles permitidos en el array de ConfigBuilder?
        $has_role = array_intersect($allowed_roles, $current_user->roles);

        // Los Administradores siempre pasan como salvavidas preventivo, luego verificamos la intersección.
        if (in_array('administrator', $current_user->roles) || !empty($has_role)) {
            return true;
        }

        // 4. Falló todo: Usuario Logeado pero rol insuficiente.
        return new WP_Error('gatekeeper_forbidden', "Prohibido. No tienes el rol necesario para efectuar una operación de tipo {$action}.", ['status' => 403]);
    }

    /**
     * Mapea métodos HTTP puros a las acciones semánticas del dashboard de permisos.
     */
    private function map_method_to_action(string $method): ?string
    {
        $map = [
            'GET'    => 'read',
            'POST'   => 'create',
            'PATCH'  => 'update',
            'PUT'    => 'update',
            'DELETE' => 'delete',
        ];

        return $map[strtoupper($method)] ?? null;
    }

    /**
     * FASE 3: VALIDACIÓN DE OWNERSHIP
     * Verifica si el usuario actual tiene derecho a modificar/eliminar un Post en particular.
     * Si no es el autor original, debe tener la capacidad abstracta de WP requerida (ej. 'edit_others_posts').
     *
     * @param object $post WP_Post
     * @param string $override_capability La capacidad que permitiría saltar la restricción del autor ('edit_others_posts', 'delete_others_posts')
     * @return true|WP_Error
     */
    public function verify_ownership($post, string $override_capability = 'edit_others_posts')
    {
        
        // El Administrador absoluto tiene paso libre en WordPress, pero para plugins REST estrictos
        // dependemos de current_user_can() que envuelve correctamente esto.
        
        if (!is_user_logged_in()) {
            return new WP_Error('ownership_unauthorized', 'Debes estar autenticado para realizar operaciones sobre entidades existentes.', ['status' => 401]);
        }

        $current_user_id = get_current_user_id();
        
        // 1. Es el dueño legitimo?
        if ((int) $post->post_author === $current_user_id) {
            return true;
        }

        // 2. No es el dueño, validar si tiene el permiso general elevado ('edit_others_posts' u otro CPT mapping)
        // Nota: En un CPT, WP mappearía a 'edit_others_{post_type}s', usamos el pre-mapped si el CPT lo define
        $post_type_object = get_post_type_object($post->post_type);
        if ($post_type_object && isset($post_type_object->cap->$override_capability)) {
             $override_capability = $post_type_object->cap->$override_capability;
        }

        if (current_user_can($override_capability)) {
            return true;
        }

        return new WP_Error(
            'ownership_forbidden', 
            'Prohibido. No eres el autor de esta entidad y no posees los privilegios administrativos para modificarla.', 
            ['status' => 403]
        );
    }

    private array $field_auth_cache = [];

    /**
     * FASE 3: FILTRADO GRANULAR METACAMPOS (READ)
     * Evalúa si el usuario en sesión tiene permisos para ver o editar un campo particular dictado desde el Dashboard.
     * Si la configuración de permisos del campo está vacía o declara 'public', es legible.
     * 
     * @param string $field_key
     * @param array $config
     * @param string $action ('read' o 'write')
     * @return bool
     */
    public function can_interact_with_field(string $field_key, array $config, string $action = 'read'): bool
    {
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $cache_key = "{$user_id}_{$field_key}_{$action}";

        if (isset($this->field_auth_cache[$cache_key])) {
            return $this->field_auth_cache[$cache_key];
        }

        // Buscamos si hay un bloque restrictivo específico para este campo en la config "field_permissions"
        $field_controls = $config['field_permissions'][$field_key][$action] ?? null;

        // Si no hay reglas escritas (nulo), o si declara explícitamente "public", asumimos que adopta la postura de "abierto"
        if ($field_controls === null || in_array('public', $field_controls)) {
            $this->field_auth_cache[$cache_key] = true;
            return true;
        }

        // Si no es publico, requiere login.
        if (!is_user_logged_in()) {
            $this->field_auth_cache[$cache_key] = false;
            return false;
        }

        $current_user = wp_get_current_user();

        // Validar si es administrador directo o si tiene uno de los roles
        $intersection = array_intersect($field_controls, $current_user->roles);
        
        $result = in_array('administrator', $current_user->roles) || !empty($intersection);
        $this->field_auth_cache[$cache_key] = $result;
        return $result;
    }
}
