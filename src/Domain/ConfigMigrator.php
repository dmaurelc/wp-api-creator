<?php

namespace WpApiCreator\Domain;

/**
 * Rutina de actualizacion del esquema de datos del plugin.
 *
 * Se ejecuta una sola vez por version, y solo desde contextos administrativos
 * (wp-admin, WP-CLI o cron). Nunca desde el path de request de la API: escribir
 * la configuracion completa desde ahi puede revertir cambios recien guardados.
 */
class ConfigMigrator
{
    /** Version del esquema de datos ya aplicada sobre este sitio. */
    const VERSION_OPTION = 'wp_api_creator_db_version';

    /** Meta que versiona los tokens emitidos para un usuario. */
    const TOKEN_VERSION_META = '_wpac_token_version';

    /**
     * Ejecuta la migracion si el esquema almacenado no coincide con el del plugin.
     *
     * @param string $target_version Version de destino.
     * @return void
     */
    public static function maybe_upgrade(string $target_version): void
    {
        if (get_option(self::VERSION_OPTION, '') === $target_version) {
            return;
        }

        self::backup_config();
        self::consolidate_api_keys();
        self::initialize_token_versions();

        update_option(self::VERSION_OPTION, $target_version, false);
    }

    /**
     * Copia integra del option antes de cualquier cambio destructivo.
     *
     * Es la unica via de vuelta documentada en el runbook de rollback.
     *
     * @return void
     */
    public static function backup_config(): void
    {
        $config = get_option(ConfigBuilder::OPTION_KEY, null);

        if (!is_array($config) || empty($config)) {
            return;
        }

        if (get_option(ConfigBuilder::OPTION_BACKUP_KEY, null) !== null) {
            return;
        }

        add_option(ConfigBuilder::OPTION_BACKUP_KEY, $config, '', 'no');
    }

    /**
     * Unifica las API Keys en la ubicacion canonica y neutraliza las del esquema viejo.
     *
     * Hasta 1.0.0 el dashboard escribia en `$config['api_keys']` y el validador leia
     * en `$config['settings']['api_keys']`: dos arrays disjuntos que nadie puenteaba,
     * asi que ninguna key creada desde la interfaz llego a autenticar nunca.
     *
     * La idempotencia se apoya en el contenido, no en un contador: al terminar no queda
     * ninguna entrada con la clave `key` ni ningun subarray dentro de `settings`, de modo
     * que una segunda ejecucion no encuentra nada que cambiar. Un `schema_version` dentro
     * de `settings` no serviria porque ese subarbol lo reemplaza el dashboard.
     *
     * @return void
     */
    public static function consolidate_api_keys(): void
    {
        $config = get_option(ConfigBuilder::OPTION_KEY, []);
        if (!is_array($config)) {
            return;
        }

        $root   = ConfigBuilder::normalize_api_keys($config['api_keys'] ?? []);
        $nested = ConfigBuilder::normalize_api_keys($config['settings']['api_keys'] ?? []);

        $merged = [];
        foreach (array_merge($nested, $root) as $entry) {
            // La raiz se procesa en segundo lugar y por tanto gana ante un mismo `id`.
            $merged[(string) $entry['id']] = self::mark_legacy_if_plaintext($entry);
        }

        $consolidated = array_values($merged);

        $needs_write = $consolidated !== ($config['api_keys'] ?? null)
            || isset($config['settings']['api_keys']);

        if (!$needs_write) {
            return;
        }

        $config['api_keys'] = $consolidated;
        unset($config['settings']['api_keys']);

        ConfigBuilder::save_config($config);
    }

    /**
     * Marca como legacy toda entrada que aun guarde la key en claro y borra el secreto.
     *
     * No se rehidratan: no consta a que usuario debian pertenecer, y asignarlas al
     * primer administrador perpetuaria el privilegio excesivo que 1.1.0 corrige.
     *
     * @param array $entry
     * @return array
     */
    private static function mark_legacy_if_plaintext(array $entry): array
    {
        if (!isset($entry['key'])) {
            return $entry;
        }

        unset($entry['key']);

        $entry['legacy']     = true;
        $entry['hash']       = '';
        $entry['prefix']     = $entry['prefix'] ?? '';
        $entry['user_id']    = $entry['user_id'] ?? 0;
        $entry['expires_at'] = $entry['expires_at'] ?? null;

        return $entry;
    }

    /**
     * Siembra la version de token de todos los usuarios existentes.
     *
     * La ausencia del meta nunca debe equivaler a una version valida: si un plugin de
     * limpieza de usermeta la borrase, un token ya revocado volveria a validar. La
     * validacion trata la ausencia como revocacion, y esta siembra evita que los
     * usuarios legitimos queden bloqueados tras actualizar.
     *
     * Se resuelve en una sola consulta para no iterar usuario a usuario en sitios grandes.
     *
     * @return void
     */
    public static function initialize_token_versions(): void
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value)
                 SELECT u.ID, %s, '1'
                 FROM {$wpdb->users} u
                 LEFT JOIN {$wpdb->usermeta} m
                        ON m.user_id = u.ID AND m.meta_key = %s
                 WHERE m.umeta_id IS NULL",
                self::TOKEN_VERSION_META,
                self::TOKEN_VERSION_META
            )
        );
    }
}
