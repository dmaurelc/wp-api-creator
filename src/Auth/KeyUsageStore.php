<?php

namespace WpApiCreator\Auth;

use WpApiCreator\Domain\ConfigBuilder;

/**
 * Registro del ultimo uso de cada API Key.
 *
 * Vive en su propia option (`autoload = no`) y no dentro del blob de configuracion:
 * anotar una fecha desde el path de request obligaria a reescribir el documento entero
 * — endpoints, permisos y ajustes incluidos — partiendo de una lectura cacheada hasta
 * 5 minutos, con el riesgo de revertir cambios que un administrador acaba de guardar.
 */
class KeyUsageStore
{
    /** Ventana minima entre dos escrituras para la misma key. */
    const THROTTLE_SECONDS = 300;

    /**
     * Mapa completo id_de_key => timestamp.
     *
     * @return array
     */
    public static function get_all(): array
    {
        $usage = get_option(ConfigBuilder::OPTION_KEY_USAGE, []);
        return is_array($usage) ? $usage : [];
    }

    /**
     * Ultimo uso registrado de una key concreta.
     *
     * @param string $key_id
     * @return int|null Timestamp UNIX, o null si nunca se uso.
     */
    public static function get(string $key_id): ?int
    {
        $usage = self::get_all();
        return isset($usage[$key_id]) ? (int) $usage[$key_id] : null;
    }

    /**
     * Anota el uso de una key, como maximo una vez cada THROTTLE_SECONDS.
     *
     * @param string $key_id
     * @return void
     */
    public static function touch(string $key_id): void
    {
        $usage = self::get_all();
        $now   = time();

        if (isset($usage[$key_id]) && ($now - (int) $usage[$key_id]) < self::THROTTLE_SECONDS) {
            return;
        }

        $usage[$key_id] = $now;
        update_option(ConfigBuilder::OPTION_KEY_USAGE, $usage, false);
    }

    /**
     * Elimina el registro de una key revocada.
     *
     * @param string $key_id
     * @return void
     */
    public static function forget(string $key_id): void
    {
        $usage = self::get_all();

        if (!isset($usage[$key_id])) {
            return;
        }

        unset($usage[$key_id]);
        update_option(ConfigBuilder::OPTION_KEY_USAGE, $usage, false);
    }
}
