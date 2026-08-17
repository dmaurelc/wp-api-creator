import { useState, useEffect } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { TextControl, Notice, Spinner } from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";
import Combobox from "../../ui/Combobox";
import useUserOptions from "./use-user-options";

export default function JwtPanel({ settings, onUpdateSettings, onNotify }) {
  const [jwtExpiration, setJwtExpiration] = useState(
    settings?.jwt_expiration || 24,
  );
  const [selectedUser, setSelectedUser] = useState("");
  const [isRevoking, setIsRevoking] = useState(false);
  const [isSaving, setIsSaving] = useState(false);

  const users = useUserOptions();

  // El panel se monta antes de que llegue el GET de ajustes, así que el valor inicial es
  // el de reserva. Sin esta sincronización, guardar sin tocar el campo degradaría a 24 h
  // una expiración mayor ya configurada.
  useEffect(() => {
    if (settings?.jwt_expiration) setJwtExpiration(settings.jwt_expiration);
  }, [settings?.jwt_expiration]);

  const handleSaveJwtSettings = () => {
    setIsSaving(true);
    Promise.resolve(
      onUpdateSettings({
        ...settings,
        jwt_expiration: Math.max(1, parseInt(jwtExpiration, 10) || 24),
      }),
    )
      .then(() =>
        onNotify({
          type: "success",
          text: __("Configuración de JWT guardada.", "wp-api-creator"),
        }),
      )
      .catch((error) =>
        onNotify({
          type: "error",
          text:
            error?.message ||
            __("No se pudo guardar la configuración.", "wp-api-creator"),
        }),
      )
      .finally(() => setIsSaving(false));
  };

  const handleRevokeTokens = () => {
    if (!selectedUser) return;
    if (
      !confirm(
        __(
          "Se invalidarán todos los tokens activos de este usuario. Sus aplicaciones tendrán que volver a autenticarse. ¿Continuar?",
          "wp-api-creator",
        ),
      )
    ) {
      return;
    }

    setIsRevoking(true);
    apiFetch({
      path: `/creator/v1/admin/users/${selectedUser}/tokens`,
      method: "DELETE",
    })
      .then((res) => {
        if (res.success) {
          onNotify({ type: "success", text: res.message });
          setSelectedUser("");
        }
      })
      .catch((error) =>
        onNotify({
          type: "error",
          text:
            error?.message ||
            __("No se pudieron revocar los tokens.", "wp-api-creator"),
        }),
      )
      .finally(() => setIsRevoking(false));
  };

  return (
    <div className="tw-space-y-8">
      <div>
        <h3 className="tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-foreground-subtle tw-m-0 tw-mb-4">
          {__("JSON Web Token (JWT)", "wp-api-creator")}
        </h3>
        <p className="tw-text-sm tw-text-foreground-muted">
          {__(
            "Estructura estándar de tokens para autenticación segura. Los tokens son emitidos por el servidor tras validar el usuario.",
            "wp-api-creator",
          )}
        </p>
      </div>

      <div className="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
        <div className="tw-bg-foreground/[0.02] tw-border tw-border-border tw-rounded-2xl tw-p-6">
          <TextControl
            label={
              <span className="tw-text-[13px] tw-font-medium tw-text-foreground tw-mb-2 tw-block">
                {__("Expiración del Token (Horas)", "wp-api-creator")}
              </span>
            }
            type="number"
            min={1}
            value={jwtExpiration}
            onChange={(val) => setJwtExpiration(val)}
            help={__(
              "Tiempo de validez antes de requerir un nuevo token. Mínimo 1 hora.",
              "wp-api-creator",
            )}
            __nextHasNoMarginBottom
          />
          <button
            onClick={handleSaveJwtSettings}
            disabled={isSaving}
            className="apig-btn apig-btn-secondary tw-mt-4 tw-w-full disabled:tw-opacity-50"
          >
            {isSaving ? (
              <Spinner />
            ) : (
              __("Actualizar Expiración", "wp-api-creator")
            )}
          </button>
        </div>

        <div className="tw-bg-foreground/[0.02] tw-border tw-border-border tw-rounded-2xl tw-p-6 tw-flex tw-flex-col tw-justify-between">
          <div>
            <span className="tw-text-[13px] tw-font-medium tw-text-foreground tw-mb-2 tw-block">
              {__("Clave Secreta (Secret Key)", "wp-api-creator")}
            </span>
            <p className="tw-text-xs tw-text-foreground-muted tw-m-0">
              {__(
                "Se usa, por orden: la constante WP_API_CREATOR_JWT_SECRET de tu wp-config.php; si no está definida, SECURE_AUTH_KEY; y si tampoco existe, una clave generada y almacenada por el plugin.",
                "wp-api-creator",
              )}
            </p>
          </div>
          <div className="tw-flex tw-items-center tw-gap-2 tw-mt-4">
            <span className="tw-text-[11px] tw-font-bold tw-bg-success/10 tw-text-success tw-px-2.5 tw-py-0.5 tw-rounded-full">
              {__("Resuelta automáticamente", "wp-api-creator")}
            </span>
          </div>
        </div>
      </div>

      <div className="tw-bg-foreground/[0.02] tw-border tw-border-border tw-rounded-2xl tw-p-6 tw-space-y-4">
        <div>
          <h4 className="tw-text-sm tw-font-bold tw-text-foreground tw-m-0 tw-mb-1">
            {__("Revocar tokens de un usuario", "wp-api-creator")}
          </h4>
          <p className="tw-text-xs tw-text-foreground-muted tw-m-0">
            {__(
              "Invalida de inmediato todos los tokens emitidos para esa cuenta. Útil si un dispositivo se pierde o una credencial se filtra.",
              "wp-api-creator",
            )}
          </p>
        </div>

        <div className="tw-flex tw-flex-col md:tw-flex-row tw-gap-4 md:tw-items-end">
          <div className="tw-flex-1">
            <Combobox
              options={users.options}
              value={selectedUser}
              onSelect={setSelectedUser}
              onSearch={users.search}
              isLoading={users.isLoading}
              placeholder={__("Selecciona un usuario...", "wp-api-creator")}
            />
          </div>
          <button
            onClick={handleRevokeTokens}
            disabled={!selectedUser || isRevoking}
            className="apig-btn apig-btn-destructive tw-h-10 tw-px-6 disabled:tw-opacity-50 disabled:tw-cursor-not-allowed"
          >
            {isRevoking ? <Spinner /> : __("Revocar tokens", "wp-api-creator")}
          </button>
        </div>

        {users.error && (
          <Notice status="error" isDismissible={false} className="tw-rounded-xl">
            {users.error}
          </Notice>
        )}
      </div>

      <div className="tw-bg-muted/50 tw-border tw-border-border tw-rounded-2xl tw-p-6">
        <h4 className="tw-text-sm tw-font-bold tw-text-foreground tw-m-0 tw-mb-3">
          {__("¿Cómo usar?", "wp-api-creator")}
        </h4>
        <div className="tw-space-y-3">
          <p className="tw-text-xs tw-text-foreground-muted tw-m-0">
            1.{" "}
            {__("Auténticate enviando login y password a", "wp-api-creator")}{" "}
            <code>/v1/auth/token</code>
          </p>
          <p className="tw-text-xs tw-text-foreground-muted tw-m-0">
            2.{" "}
            {__(
              "Incluye el token en el header de tus peticiones:",
              "wp-api-creator",
            )}
          </p>
          <code className="tw-block tw-p-3 tw-bg-background/50 tw-rounded-xl tw-text-xs">
            Authorization: Bearer [tu_token]
          </code>
        </div>
      </div>
    </div>
  );
}
