<?php

namespace WpApiCreator\Core;

use WpApiCreator\Auth\TokenVersionStore;
use WpApiCreator\Domain\ConfigMigrator;

/**
 * Clase principal que inicializa el ciclo de vida del plugin.
 */
class Plugin
{
    /**
     * @var Container
     */
    protected $container;

    public function __construct()
    {
        $this->container = new Container();
        $this->register_services();
    }

    /**
     * Inicializa el plugin, cargando hooks, middlewares y rutas.
     */
    public function init(): void
    {
        // La migración de esquema corre antes que nada: el Router lee la configuración
        // en su constructor y debe ver ya la forma definitiva.
        $this->maybe_upgrade();

        // Aquí conectamos módulos como Admin, Api, Auth, etc.
        // Registramos las rutas dinámicas resolviendo desde el contenedor.
        $this->container->get(\WpApiCreator\Api\Router::class)->register_hooks();
        
        // Registramos el menú de administración
        if (is_admin()) {
            $this->container->get(\WpApiCreator\Admin\AdminMenu::class)->register_hooks();
        }
        
        // Admin API interactúa por REST API, independiente de is_admin()
        $this->container->get(\WpApiCreator\Admin\AdminApi::class)->register_hooks();

        $this->register_token_invalidation_hooks();

        add_action('init', [$this, 'on_wp_init']);
    }

    /**
     * Eventos que deben invalidar los tokens vivos de un usuario.
     *
     * @return void
     */
    protected function register_token_invalidation_hooks(): void
    {
        add_action('after_password_reset', function ($user) {
            if (is_object($user) && isset($user->ID)) {
                TokenVersionStore::revoke((int) $user->ID);
            }
        }, 10, 1);

        add_action('wp_set_password', function ($password, $user_id) {
            TokenVersionStore::revoke((int) $user_id);
        }, 10, 2);

        add_action('set_user_role', function ($user_id) {
            TokenVersionStore::revoke((int) $user_id);
        }, 10, 1);

        add_action('delete_user', function ($user_id) {
            TokenVersionStore::revoke((int) $user_id);
        }, 10, 1);

        // `profile_update` se dispara en cada guardado del perfil. Sin comparar el hash de
        // contrasena, cambiar una biografia cerraria todas las sesiones del usuario.
        add_action('profile_update', function ($user_id, $old_user_data) {
            $new_user_data = get_userdata((int) $user_id);

            if (!$new_user_data || !is_object($old_user_data)) {
                return;
            }

            if ($old_user_data->user_pass !== $new_user_data->user_pass) {
                TokenVersionStore::revoke((int) $user_id);
            }
        }, 10, 2);
    }

    /**
     * Ejecuta la migracion de esquema una sola vez, fuera del path de request de la API.
     *
     * @return void
     */
    protected function maybe_upgrade(): void
    {
        $is_management_context = is_admin()
            || (defined('WP_CLI') && WP_CLI)
            || wp_doing_cron();

        if (!$is_management_context) {
            return;
        }

        ConfigMigrator::maybe_upgrade(self::version());
    }

    /**
     * @return string
     */
    protected static function version(): string
    {
        return defined('WP_API_CREATOR_VERSION') ? (string) WP_API_CREATOR_VERSION : '0.0.0';
    }

    /**
     * Registra los servicios base en el contenedor (Dependency Injection).
     */
    protected function register_services(): void
    {
        // El contenedor se inyecta a sí mismo por si lo necesitan factories
        $this->container->singleton(Container::class, $this->container);

        // Registro de Router
        $this->container->singleton(\WpApiCreator\Api\Router::class, function() {
            return new \WpApiCreator\Api\Router();
        });

        // Registro del AdminMenu inyectando el contenedor (para que pueda resolver tools internas futuras)
        $this->container->singleton(\WpApiCreator\Admin\AdminMenu::class, function($c) {
            return new \WpApiCreator\Admin\AdminMenu($c);
        });

        // Registro de la Admin API (Endpoints locales del admin)
        $this->container->singleton(\WpApiCreator\Admin\AdminApi::class, function() {
            return new \WpApiCreator\Admin\AdminApi();
        });

        // Registro futuro de otros servicios...
    }

    /**
     * Hook de inicialización general de WordPress (init).
     */
    public function on_wp_init(): void
    {
        // Cargar traducciones si es necesario
        load_plugin_textdomain('wp-api-creator', false, dirname(plugin_basename(WP_API_CREATOR_DIR)) . '/languages');
    }

    /**
     * Se ejecuta durante la activación del plugin.
     */
    public static function activate(): void
    {
        // Backup del option, consolidacion de API Keys y siembra de versiones de token.
        ConfigMigrator::maybe_upgrade(self::version());

        // Es necesario hacer flush rules al activar tipos de post nuevos o rutas nuevas.
        flush_rewrite_rules();
    }

    /**
     * Se ejecuta durante la desactivación del plugin.
     */
    public static function deactivate(): void
    {
        // Limpiar tareas programadas, flush rules temporales, etc.
        flush_rewrite_rules();
    }
    
    /**
     * Retorna el contenedor de aplicaciones.
     */
    public function get_container(): Container
    {
        return $this->container;
    }
}
