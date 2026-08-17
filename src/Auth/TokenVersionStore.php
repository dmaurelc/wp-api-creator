<?php

namespace WpApiCreator\Auth;

use WpApiCreator\Domain\ConfigMigrator;

/**
 * Version de los tokens emitidos para cada usuario.
 *
 * Incrementar la version invalida de golpe todos los tokens vivos de ese usuario
 * sin necesidad de una lista negra de identificadores individuales.
 *
 * La ausencia del meta NUNCA equivale a una version valida: si un plugin de limpieza
 * de usermeta o una migracion la borrase, un token ya revocado volveria a validar.
 * Por eso la lectura de validacion devuelve null y la de emision siembra el valor.
 */
class TokenVersionStore
{
    /**
     * Version almacenada, o null si el usuario no tiene ninguna.
     *
     * @param int $user_id
     * @return int|null
     */
    public static function read(int $user_id): ?int
    {
        $stored = get_user_meta($user_id, ConfigMigrator::TOKEN_VERSION_META, true);

        if ($stored === '' || $stored === false || $stored === null) {
            return null;
        }

        return (int) $stored;
    }

    /**
     * Version a estampar en un token nuevo, sembrando el meta la primera vez.
     *
     * @param int $user_id
     * @return int
     */
    public static function read_for_issue(int $user_id): int
    {
        $version = self::read($user_id);

        if ($version === null) {
            $version = 1;
            update_user_meta($user_id, ConfigMigrator::TOKEN_VERSION_META, $version);
        }

        return $version;
    }

    /**
     * Invalida todos los tokens vivos del usuario.
     *
     * @param int $user_id
     * @return int La nueva version.
     */
    public static function revoke(int $user_id): int
    {
        $next = self::read_for_issue($user_id) + 1;

        // Si la escritura falla, la revocacion no ha ocurrido: devolver 0 permite al
        // llamante distinguirlo y no confirmar al administrador algo que no pasó.
        if (update_user_meta($user_id, ConfigMigrator::TOKEN_VERSION_META, $next) === false) {
            return 0;
        }

        return $next;
    }
}
