import { __ } from "@wordpress/i18n";

export default function AppPasswordsPanel() {
  return (
    <div className="tw-space-y-8">
      <div>
        <h3 className="tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-foreground-subtle tw-m-0 tw-mb-4">
          {__("Application Passwords", "wp-api-creator")}
        </h3>
        <p className="tw-text-sm tw-text-foreground-muted">
          {__(
            "Usa la funcionalidad nativa de WordPress para autenticarte como tu usuario actual sin exponer tu contraseña principal.",
            "wp-api-creator",
          )}
        </p>
      </div>

      <div className="tw-bg-foreground/[0.02] tw-border tw-border-border tw-rounded-2xl tw-p-8 tw-text-center">
        <div className="tw-inline-flex tw-items-center tw-justify-center tw-w-16 tw-h-16 tw-rounded-3xl tw-bg-muted tw-text-foreground tw-mb-4">
          <span
            className="dashicons dashicons-smartphone"
            style={{ fontSize: "32px", width: "32px", height: "32px" }}
          ></span>
        </div>
        <h4 className="tw-text-base tw-font-bold tw-text-foreground tw-m-0 tw-mb-2">
          {__("Gestionar tus contraseñas", "wp-api-creator")}
        </h4>
        <p className="tw-text-sm tw-text-foreground-muted tw-mb-6">
          {__(
            "Puedes crear contraseñas específicas para cada aplicación en tu panel de perfil.",
            "wp-api-creator",
          )}
        </p>
        <a
          href={`${
            window.wpApiCreatorData?.admin_url || ""
          }profile.php#application-passwords-section`}
          target="_blank"
          rel="noopener noreferrer"
          className="apig-btn apig-btn-primary"
        >
          {__("Ir a mi perfil de WordPress", "wp-api-creator")}
        </a>
      </div>

      <div className="tw-bg-warning/5 tw-border tw-border-warning/10 tw-rounded-2xl tw-p-6">
        <h4 className="tw-text-sm tw-font-bold tw-text-warning tw-m-0 tw-mb-3">
          {__("Autenticación Básica", "wp-api-creator")}
        </h4>
        <p className="tw-text-xs tw-text-foreground-muted tw-m-0">
          {__(
            "Envía tus credenciales usando el esquema 'Basic'. El usuario es tu nombre de login y la contraseña es la clave generada de 24 caracteres.",
            "wp-api-creator",
          )}
        </p>
        <code className="tw-block tw-mt-4 tw-p-3 tw-bg-background/50 tw-rounded-xl tw-text-xs">
          Authorization: Basic [base64(usuario:app_password)]
        </code>
      </div>
    </div>
  );
}
