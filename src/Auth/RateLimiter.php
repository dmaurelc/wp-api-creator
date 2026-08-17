<?php

namespace WpApiCreator\Auth;

/**
 * Limitador de intentos de credencial fallidos, respaldado por transients.
 *
 * Trabaja con claves independientes. La clave por IP sola es un arma de bloqueo:
 * unas pocas peticiones basura dejan sin servicio a una oficina entera, un CGNAT movil
 * o cualquier sitio tras proxy donde `REMOTE_ADDR` es el mismo para todo el trafico.
 * Y sin clave por cuenta, el credential stuffing distribuido pasa intacto.
 *
 * La ventana es **fija**, no deslizante: el contador guarda su instante de caducidad y
 * los incrementos posteriores no lo desplazan. Con una ventana deslizante, un cliente
 * que reintente en bucle mantendria su propio bloqueo — y el de la victima — de forma
 * indefinida, convirtiendo la proteccion en el vector de denegacion de servicio.
 */
class RateLimiter
{
    /** Fallos de contrasena: `/auth/token` y Basic Auth. */
    const PREFIX_IP   = 'wpac_fail_ip_';
    const PREFIX_USER = 'wpac_fail_user_';

    /**
     * Fallos de API Key, en su propio espacio.
     *
     * Compartir el contador con el inicio de sesion permitiria que una integracion s2s
     * con una key caducada, reintentando en bucle, dejase sin servicio el login de toda
     * su IP de salida.
     */
    const PREFIX_API_KEY = 'wpac_fail_key_';

    /** Politica por IP: rafagas de contrasenas desde un mismo origen. */
    const IP_THRESHOLD = 5;
    const IP_WINDOW    = 900;  // 15 minutos

    /** Politica por cuenta: ataque distribuido contra un mismo usuario. */
    const USER_THRESHOLD = 10;
    const USER_WINDOW    = 3600; // 60 minutos

    /**
     * Politica de API Keys, mas holgada: son secretos de 128 bits generados por el plugin,
     * inviables por fuerza bruta. El contador frena el escaneo ruidoso, no la adivinacion.
     */
    const API_KEY_THRESHOLD = 20;
    const API_KEY_WINDOW    = 900;

    /**
     * Indica si la clave ya supero su umbral.
     *
     * El umbral y la ventana son parametros de metodo y no del constructor porque
     * conviven varias politicas sobre el mismo almacen.
     *
     * @param string $key
     * @param int    $threshold
     * @return bool
     */
    public static function is_blocked(string $key, int $threshold): bool
    {
        return self::count($key) >= $threshold;
    }

    /**
     * Numero de fallos vigentes para una clave.
     *
     * @param string $key
     * @return int
     */
    public static function count(string $key): int
    {
        $entry = self::read($key);

        return $entry === null ? 0 : (int) $entry['count'];
    }

    /**
     * Registra un intento fallido. Solo cuentan los fallos; un exito limpia el contador.
     *
     * @param string $key
     * @param int    $window Segundos de vigencia de la ventana.
     * @return int Nuevo numero de fallos.
     */
    public static function register_failure(string $key, int $window = self::IP_WINDOW): int
    {
        $now   = time();
        $entry = self::read($key);

        if ($entry === null) {
            $entry = ['count' => 0, 'expires' => $now + max(1, $window)];
        }

        $entry['count'] = (int) $entry['count'] + 1;

        // La caducidad no se recalcula: el incremento no desplaza la ventana.
        set_transient($key, $entry, max(1, $entry['expires'] - $now));

        return $entry['count'];
    }

    /**
     * Limpia el contador de una clave.
     *
     * @param string $key
     * @return void
     */
    public static function clear(string $key): void
    {
        delete_transient($key);
    }

    /**
     * Segundos que restan hasta que la clave deje de estar bloqueada.
     *
     * @param string $key
     * @param int    $window Valor de reserva si no hay contador vigente.
     * @return int
     */
    public static function retry_after(string $key, int $window): int
    {
        $entry = self::read($key);

        return $entry === null ? $window : max(1, (int) $entry['expires'] - time());
    }

    /**
     * Clave de contador de contrasenas asociada a la IP de origen.
     *
     * @return string
     */
    public static function ip_key(): string
    {
        return self::PREFIX_IP . hash('sha256', self::resolve_ip());
    }

    /**
     * Clave de contador asociada a un nombre de usuario.
     *
     * @param string $username
     * @return string
     */
    public static function user_key(string $username): string
    {
        return self::PREFIX_USER . hash('sha256', strtolower($username));
    }

    /**
     * Clave de contador de API Keys invalidas, asociada a la IP de origen.
     *
     * @return string
     */
    public static function api_key_bucket(): string
    {
        return self::PREFIX_API_KEY . hash('sha256', self::resolve_ip());
    }

    /**
     * Registra un fallo de contrasena en ambas politicas.
     *
     * @param string|null $username Nombre de usuario intentado, si se conoce.
     * @return void
     */
    public static function register_login_failure(?string $username): void
    {
        self::register_failure(self::ip_key(), self::IP_WINDOW);

        if ($username !== null && $username !== '') {
            self::register_failure(self::user_key($username), self::USER_WINDOW);
        }
    }

    /**
     * Lee el contador vigente de una clave.
     *
     * @param string $key
     * @return array|null
     */
    private static function read(string $key): ?array
    {
        $value = get_transient($key);

        if (!is_array($value) || !isset($value['count'], $value['expires'])) {
            return null;
        }

        if ((int) $value['expires'] <= time()) {
            return null;
        }

        return $value;
    }

    /**
     * Resuelve la IP de origen.
     *
     * `REMOTE_ADDR` es el default duro. Confiar en `X-Forwarded-For` sin mas permitiria
     * a un atacante falsear la cabecera y bloquear a la victima que eligiese, por lo que
     * solo se lee cuando la instalacion declara explicitamente sus proxies de confianza
     * mediante la constante `WP_API_CREATOR_TRUSTED_PROXIES` (lista separada por comas).
     *
     * @return string
     */
    public static function resolve_ip(): string
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        if ($remote === '') {
            // Nunca colapsar un origen desconocido en un bucket compartido: seria
            // un unico contador para todo el trafico sin IP resoluble.
            return 'unknown-' . hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }

        if (!defined('WP_API_CREATOR_TRUSTED_PROXIES') || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remote;
        }

        $trusted = array_map('trim', explode(',', (string) WP_API_CREATOR_TRUSTED_PROXIES));
        if (!in_array($remote, $trusted, true)) {
            return $remote;
        }

        $forwarded = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $client    = $forwarded[0] ?? '';

        return filter_var($client, FILTER_VALIDATE_IP) ? $client : $remote;
    }
}
