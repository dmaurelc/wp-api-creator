<?php

namespace WpApiCreator\Core;

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
        // Aquí conectamos módulos como Admin, Api, Auth, etc.
        // Registramos las rutas dinámicas resolviendo desde el contenedor.
        $this->container->get(\WpApiCreator\Api\Router::class)->register_hooks();
        
        // Registramos el menú de administración
        if (is_admin()) {
            $this->container->get(\WpApiCreator\Admin\AdminMenu::class)->register_hooks();
        }
        
        // Admin API interactúa por REST API, independiente de is_admin()
        $this->container->get(\WpApiCreator\Admin\AdminApi::class)->register_hooks();
        
        add_action('init', [$this, 'on_wp_init']);
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
        // Crear roles iniciales, opciones por defecto, tablas de bd, etc.
        
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
