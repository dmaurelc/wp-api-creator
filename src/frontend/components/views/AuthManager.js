import { useState, useEffect } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import {
  Button,
  TextControl,
  Spinner,
  Notice,
  Icon,
} from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";

function AuthManager({ settings, onUpdateSettings }) {
  const [activeTab, setActiveTab] = useState("jwt");
  const [apiKeys, setApiKeys] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isCreating, setIsCreating] = useState(false);
  const [newKeyName, setNewKeyName] = useState("");
  const [message, setMessage] = useState(null);
  const [copiedId, setCopiedId] = useState(null);
  const [jwtExpiration, setJwtExpiration] = useState(
    settings?.jwt_expiration || 24,
  );

  const fetchKeys = () => {
    setIsLoading(true);
    apiFetch({ path: "/creator/v1/admin/api-keys" })
      .then((res) => {
        if (res.success) setApiKeys(res.keys || []);
      })
      .catch(() =>
        setMessage({
          type: "error",
          text: __("Error al cargar las API Keys.", "wp-api-creator"),
        }),
      )
      .finally(() => setIsLoading(false));
  };

  useEffect(() => {
    fetchKeys();
  }, []);

  const handleCreateKey = () => {
    if (!newKeyName.trim()) {
      setMessage({
        type: "error",
        text: __("Introduce un nombre.", "wp-api-creator"),
      });
      return;
    }
    setIsCreating(true);
    apiFetch({
      path: "/creator/v1/admin/api-keys",
      method: "POST",
      data: { name: newKeyName },
    })
      .then((res) => {
        if (res.success) {
          setApiKeys([...apiKeys, res.key]);
          setNewKeyName("");
          setMessage({
            type: "success",
            text: __("API Key creada.", "wp-api-creator"),
          });
        }
      })
      .catch(() =>
        setMessage({
          type: "error",
          text: __("Error al crear.", "wp-api-creator"),
        }),
      )
      .finally(() => setIsCreating(false));
  };

  const handleDeleteKey = (id) => {
    if (!confirm(__("¿Revocar esta API Key?", "wp-api-creator"))) return;
    apiFetch({ path: `/creator/v1/admin/api-keys/${id}`, method: "DELETE" })
      .then((res) => {
        if (res.success) {
          setApiKeys(apiKeys.filter((k) => k.id !== id));
          setMessage({
            type: "success",
            text: __("API Key revocada.", "wp-api-creator"),
          });
        }
      })
      .catch(() =>
        setMessage({
          type: "error",
          text: __("Error al revocar.", "wp-api-creator"),
        }),
      );
  };

  const handleCopy = (text, id) => {
    navigator.clipboard.writeText(text).then(() => {
      setCopiedId(id);
      setTimeout(() => setCopiedId(null), 2000);
    });
  };

  const handleSaveJwtSettings = () => {
    onUpdateSettings({
      ...settings,
      jwt_expiration: parseInt(jwtExpiration),
    });
    setMessage({
      type: "success",
      text: __("Configuración de JWT guardada.", "wp-api-creator"),
    });
  };

  const TabButton = ({ id, label, icon }) => (
    <button
      onClick={() => setActiveTab(id)}
      className={`tw-flex tw-items-center tw-gap-2 tw-px-5 tw-py-2.5 tw-rounded-xl tw-text-sm tw-font-semibold tw-transition-all tw-cursor-pointer tw-border-0 ${
        activeTab === id
          ? "tw-bg-primary tw-text-primary-foreground tw-shadow-sm"
          : "tw-bg-muted tw-text-muted-foreground hover:tw-bg-muted/80 hover:tw-text-foreground"
      }`}
    >
      <span
        className={`dashicons dashicons-${icon} tw-text-[16px] tw-w-[16px] tw-h-[16px]`}
      ></span>
      {label}
    </button>
  );

  if (isLoading) {
    return (
      <div className="tw-flex tw-items-center tw-justify-center tw-py-20 tw-text-foreground-subtle">
        <Spinner style={{ width: 18, height: 18 }} />
        <span className="tw-ml-2 tw-text-sm">
          {__("Cargando...", "wp-api-creator")}
        </span>
      </div>
    );
  }

  return (
    <div className="apig-animate tw-space-y-8">
      <div className="tw-flex tw-items-center tw-justify-between">
        <div>
          <h2 className="tw-text-base tw-font-bold tw-text-foreground tw-m-0">
            {__("Autenticación", "wp-api-creator")}
          </h2>
          <p className="tw-text-sm tw-text-foreground-muted tw-mt-1.2 tw-mb-0">
            {__(
              "Configura los métodos de acceso seguro para tu API.",
              "wp-api-creator",
            )}
          </p>
        </div>
      </div>

      {message && (
        <div className="tw-mb-5">
          <Notice
            status={message.type}
            onDismiss={() => setMessage(null)}
            className="tw-rounded-xl"
          >
            {message.text}
          </Notice>
        </div>
      )}

      <div className="tw-flex tw-gap-3">
        <TabButton id="jwt" label="JWT" icon="unlock" />
        <TabButton id="apikeys" label="API Keys" icon="admin-network" />
        <TabButton id="apppass" label="App Passwords" icon="smartphone" />
      </div>

      <div className="tw-bg-background tw-rounded-2xl tw-border tw-border-border">
        {activeTab === "jwt" && (
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
                  value={jwtExpiration}
                  onChange={(val) => setJwtExpiration(val)}
                  help={__(
                    "Tiempo de validez antes de requerir un nuevo token.",
                    "wp-api-creator",
                  )}
                  __nextHasNoMarginBottom
                />
                <button
                  onClick={handleSaveJwtSettings}
                  className="apig-btn apig-btn-secondary tw-mt-4 tw-w-full"
                >
                  {__("Actualizar Expiración", "wp-api-creator")}
                </button>
              </div>

              <div className="tw-bg-foreground/[0.02] tw-border tw-border-border tw-rounded-2xl tw-p-6 tw-flex tw-flex-col tw-justify-between">
                <div>
                  <span className="tw-text-[13px] tw-font-medium tw-text-foreground tw-mb-2 tw-block">
                    {__("Clave Secreta (Secret Key)", "wp-api-creator")}
                  </span>
                  <p className="tw-text-xs tw-text-foreground-muted tw-m-0">
                    {__(
                      "Utilizamos SECURE_AUTH_KEY definida en tu wp-config.php para la firma de tokens.",
                      "wp-api-creator",
                    )}
                  </p>
                </div>
                <div className="tw-flex tw-items-center tw-gap-2 tw-mt-4">
                  <span className="tw-text-[11px] tw-font-bold tw-bg-success/10 tw-text-success tw-px-2.5 tw-py-0.5 tw-rounded-full">
                    {__("Configurado en WP-Core", "wp-api-creator")}
                  </span>
                </div>
              </div>
            </div>

            <div className="tw-bg-muted/50 tw-border tw-border-border tw-rounded-2xl tw-p-6">
              <h4 className="tw-text-sm tw-font-bold tw-text-foreground tw-m-0 tw-mb-3">
                {__("¿Cómo usar?", "wp-api-creator")}
              </h4>
              <div className="tw-space-y-3">
                <p className="tw-text-xs tw-text-foreground-muted tw-m-0">
                  1.{" "}
                  {__(
                    "Auténticate enviando login y password a",
                    "wp-api-creator",
                  )}{" "}
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
        )}

        {activeTab === "apikeys" && (
          <div className="tw-space-y-8">
            <div>
              <h3 className="tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-foreground-subtle tw-m-0 tw-mb-4">
                {__("API Keys Globales", "wp-api-creator")}
              </h3>
              <p className="tw-text-sm tw-text-foreground-muted">
                {__(
                  "Claves estáticas para integraciones simples donde no se requiere contexto de usuario.",
                  "wp-api-creator",
                )}
              </p>
            </div>

            <div className="tw-flex tw-gap-4 tw-items-end tw-bg-foreground/[0.02] tw-p-6 tw-rounded-2xl tw-border tw-border-border">
              <div className="tw-flex-1">
                <TextControl
                  label={
                    <span className="tw-text-[13px] tw-font-medium tw-text-foreground tw-mb-2 tw-block">
                      {__("Nombre identificador", "wp-api-creator")}
                    </span>
                  }
                  placeholder={__(
                    "Ej: App Externa, Partner...",
                    "wp-api-creator",
                  )}
                  value={newKeyName}
                  onChange={setNewKeyName}
                  __nextHasNoMarginBottom
                />
              </div>
              <button
                onClick={handleCreateKey}
                disabled={isCreating}
                className="apig-btn apig-btn-primary tw-h-12 tw-px-8"
              >
                {isCreating ? (
                  <Spinner />
                ) : (
                  __("Generar Llave", "wp-api-creator")
                )}
              </button>
            </div>

            <div className="tw-overflow-hidden tw-border tw-border-border tw-rounded-xl">
              <table className="tw-w-full">
                <thead className="tw-bg-foreground/[0.02]">
                  <tr>
                    <th className="tw-px-6 tw-py-4 tw-text-left tw-text-[11px] tw-font-semibold tw-uppercase tw-tracking-widest tw-text-foreground-subtle tw-border-b tw-border-border">
                      {__("Nombre", "wp-api-creator")}
                    </th>
                    <th className="tw-px-6 tw-py-4 tw-text-left tw-text-[11px] tw-font-semibold tw-uppercase tw-tracking-widest tw-text-foreground-subtle tw-border-b tw-border-border">
                      {__("API Key", "wp-api-creator")}
                    </th>
                    <th className="tw-px-6 tw-py-4 tw-border-b tw-border-border"></th>
                  </tr>
                </thead>
                <tbody className="tw-divide-y tw-divide-border/50">
                  {apiKeys.length === 0 ? (
                    <tr>
                      <td
                        colSpan="3"
                        className="tw-px-6 tw-py-12 tw-text-center tw-text-sm tw-text-foreground-muted"
                      >
                        {__("No hay API Keys generadas.", "wp-api-creator")}
                      </td>
                    </tr>
                  ) : (
                    apiKeys.map((key) => (
                      <tr
                        key={key.id}
                        className="tw-group hover:tw-bg-foreground/[0.01]"
                      >
                        <td className="tw-px-6 tw-py-4 tw-text-[14px] tw-font-semibold">
                          {key.name}
                        </td>
                        <td className="tw-px-6 tw-py-4">
                          <div className="tw-flex tw-items-center tw-gap-3">
                            <code className="tw-text-[12px] tw-font-mono tw-text-green-600 tw-bg-green-500/5 tw-px-3 tw-py-1.5 tw-rounded-xl tw-border tw-border-green-500/10">
                              {key.key}
                            </code>
                            <button
                              onClick={() => handleCopy(key.key, key.id)}
                              className="tw-p-2 tw-rounded-xl tw-text-foreground-subtle hover:tw-text-foreground hover:tw-bg-muted tw-transition-all tw-cursor-pointer tw-border-0 tw-bg-transparent"
                            >
                              <span
                                className={`dashicons dashicons-${
                                  copiedId === key.id ? "yes" : "admin-page"
                                } ${
                                  copiedId === key.id ? "tw-text-success" : ""
                                }`}
                                style={{ fontSize: "16px" }}
                              ></span>
                            </button>
                          </div>
                        </td>
                        <td className="tw-px-6 tw-py-4 tw-text-right">
                          <button
                            onClick={() => handleDeleteKey(key.id)}
                            className="apig-btn apig-btn-destructive apig-btn-sm"
                          >
                            <Icon icon="trash" />
                            {__("Revocar", "wp-api-creator")}
                          </button>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
            <div className="tw-bg-muted/50 tw-border tw-border-border tw-rounded-2xl tw-p-6 tw-mt-8">
              <h4 className="tw-text-sm tw-font-bold tw-text-foreground tw-m-0 tw-mb-3">
                {__("¿Cómo usar?", "wp-api-creator")}
              </h4>
              <p className="tw-text-xs tw-text-foreground-muted tw-m-0">
                {__(
                  "Envía tu llave en el header de todas tus peticiones:",
                  "wp-api-creator",
                )}
              </p>
              <code className="tw-block tw-mt-3 tw-p-3 tw-bg-background/50 tw-rounded-xl tw-text-xs">
                X-API-KEY: [tu_api_key_generada]
              </code>
            </div>
          </div>
        )}

        {activeTab === "apppass" && (
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
        )}
      </div>
    </div>
  );
}

export default AuthManager;
