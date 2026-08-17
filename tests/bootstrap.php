<?php
/**
 * Bootstrap de la suite unitaria.
 *
 * No carga WordPress: Brain Monkey intercepta las funciones globales y aqui solo se
 * definen las constantes y las clases que WordPress declara y que no son funciones
 * (Brain Monkey no puede stubear ni clases ni constantes).
 *
 * Deliberadamente NO se define SECURE_AUTH_KEY: una constante queda fijada para todo el
 * proceso PHP, y los tests necesitan cubrir tanto su presencia como su ausencia. La
 * costura para eso es el secreto inyectable de JwtProvider.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!class_exists('WP_Error')) {
    /**
     * Doble minimo de WP_Error con la superficie que usa el plugin.
     */
    class WP_Error
    {
        /** @var array<string, string[]> */
        protected $errors = [];

        /** @var array<string, mixed> */
        protected $error_data = [];

        public function __construct($code = '', $message = '', $data = '')
        {
            if ($code === '') {
                return;
            }

            $this->errors[$code][] = $message;

            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_code()
        {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }

        public function get_error_message($code = '')
        {
            $code = $code ?: $this->get_error_code();
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data($code = '')
        {
            $code = $code ?: $this->get_error_code();
            return $this->error_data[$code] ?? null;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_User')) {
    /**
     * Doble minimo de WP_User: identidad y roles.
     */
    class WP_User
    {
        public $ID;
        public $roles = [];
        public $display_name = '';
        public $user_login = '';

        public function __construct($id = 0, array $roles = [])
        {
            $this->ID = (int) $id;
            $this->roles = $roles;
        }

        public function exists(): bool
        {
            return $this->ID > 0;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    /**
     * Doble minimo de WP_REST_Response.
     */
    class WP_REST_Response
    {
        protected $data;
        protected $status;
        protected $headers = [];

        public function __construct($data = null, $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return (int) $this->status;
        }

        public function header(string $key, $value): void
        {
            $this->headers[$key] = $value;
        }

        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    /**
     * Doble minimo de WP_REST_Request: solo cabeceras, parametros y atributos.
     */
    class WP_REST_Request
    {
        protected $method;
        protected $route;
        protected $headers = [];
        protected $params = [];
        protected $attributes = [];

        public function __construct(string $method = 'GET', string $route = '')
        {
            $this->method = $method;
            $this->route = $route;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function set_header(string $key, $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key)
        {
            return $this->headers[strtolower($key)] ?? null;
        }

        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        public function get_attributes(): array
        {
            return $this->attributes;
        }

        public function set_attributes(array $attributes): void
        {
            $this->attributes = $attributes;
        }
    }
}
