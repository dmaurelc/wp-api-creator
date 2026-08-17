<?php

namespace WpApiCreator\Auth;

use WpApiCreator\Domain\ConfigBuilder;

/**
 * Validacion de API Keys para integraciones de servidor a servidor.
 *
 * Cada key esta ligada a un usuario de WordPress real y se almacena hasheada.
 * No existe key maestra ni usuario administrador por defecto: la identidad
 * resuelta es siempre la del propietario declarado al crearla.
 */
class ApiKeyProvider
{
    /** Prefijo legible que identifica una key del plugin. */
    const KEY_PREFIX = 'ak_';

    /** Caracteres visibles en la interfaz para reconocer una key sin exponerla. */
    const PREFIX_LENGTH = 7;

    /**
     * Resuelve el usuario asociado a una API Key.
     *
     * @param string $api_key Key en claro recibida en la cabecera.
     * @return int|null ID del usuario propietario, o null si la key no autentica.
     */
    public function validate_key(string $api_key): ?int
    {
        $api_key = trim($api_key);
        if ($api_key === '') {
            return null;
        }

        $incoming_hash = self::hash_key($api_key);

        foreach (ConfigBuilder::get_api_keys() as $entry) {
            // Las keys del esquema anterior a 1.1.0 no autentican: nunca se supo
            // a que usuario pertenecian y no se rehidratan.
            if (!empty($entry['legacy'])) {
                continue;
            }

            $stored_hash = isset($entry['hash']) ? (string) $entry['hash'] : '';
            if ($stored_hash === '' || !hash_equals($stored_hash, $incoming_hash)) {
                continue;
            }

            if (!empty($entry['expires_at']) && (int) $entry['expires_at'] < time()) {
                return null;
            }

            $user_id = isset($entry['user_id']) ? (int) $entry['user_id'] : 0;
            if ($user_id <= 0 || !get_userdata($user_id)) {
                return null;
            }

            KeyUsageStore::touch((string) $entry['id']);

            return $user_id;
        }

        return null;
    }

    /**
     * Genera una key en claro criptograficamente segura.
     *
     * @return string
     */
    public static function generate_key(): string
    {
        return self::KEY_PREFIX . bin2hex(random_bytes(16));
    }

    /**
     * Hashea una key para su almacenamiento y comparacion.
     *
     * SHA-256 y no `password_hash`: una API Key es un secreto de 128 bits generado por
     * el plugin, no una contrasena humana. No necesita el coste de bcrypt, que ademas
     * obligaria a un `password_verify` contra cada key almacenada en cada peticion.
     *
     * @param string $plain_key
     * @return string
     */
    public static function hash_key(string $plain_key): string
    {
        return hash('sha256', $plain_key);
    }

    /**
     * Fragmento visible de una key, para identificarla en la interfaz.
     *
     * @param string $plain_key
     * @return string
     */
    public static function prefix_of(string $plain_key): string
    {
        return substr($plain_key, 0, self::PREFIX_LENGTH);
    }
}
