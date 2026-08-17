import { useState, useEffect } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { TextControl, SelectControl, Spinner, Notice, Icon } from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";
import Combobox from "../../ui/Combobox";
import NewApiKeyModal from "./NewApiKeyModal";
import useUserOptions from "./use-user-options";

const DAY = 86400;

const EXPIRATION_CHOICES = [
  { label: __("Sin caducidad", "wp-api-creator"), value: "" },
  { label: __("30 días", "wp-api-creator"), value: String(30 * DAY) },
  { label: __("90 días", "wp-api-creator"), value: String(90 * DAY) },
  { label: __("1 año", "wp-api-creator"), value: String(365 * DAY) },
  { label: __("Fecha personalizada", "wp-api-creator"), value: "custom" },
];

function formatTimestamp(timestamp) {
  if (!timestamp) return null;
  return new Date(timestamp * 1000).toLocaleDateString();
}

/**
 * Normaliza la respuesta del listado.
 *
 * Un option corrupto anterior a la migración puede llegar como objeto en vez de array,
 * y `.map()` sobre un objeto deja la pestaña en blanco.
 */
function toKeyList(payload) {
  if (Array.isArray(payload)) return payload;
  return Object.values(payload || {});
}

export default function ApiKeysPanel() {
  const [apiKeys, setApiKeys] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isCreating, setIsCreating] = useState(false);
  const [message, setMessage] = useState(null);
  const [createdKey, setCreatedKey] = useState(null);

  const [newKeyName, setNewKeyName] = useState("");
  const [selectedUser, setSelectedUser] = useState("");
  const [expirationChoice, setExpirationChoice] = useState("");
  const [customExpiration, setCustomExpiration] = useState("");

  const users = useUserOptions();

  const fetchKeys = () => {
    setIsLoading(true);
    apiFetch({ path: "/creator/v1/admin/api-keys" })
      .then((res) => {
        if (res.success) setApiKeys(toKeyList(res.keys));
      })
      .catch(() =>
        setMessage({
          type: "error",
          text: __("Error al cargar las API Keys.", "wp-api-creator"),
        }),
      )
      .finally(() => setIsLoading(false));
  };

  useEffect(fetchKeys, []);

  const resolveExpiration = () => {
    if (expirationChoice === "") return null;
    if (expirationChoice === "custom") {
      if (!customExpiration) return null;
      return Math.floor(new Date(customExpiration).getTime() / 1000);
    }
    return Math.floor(Date.now() / 1000) + parseInt(expirationChoice, 10);
  };

  const handleCreateKey = () => {
    if (!newKeyName.trim() || !selectedUser) return;

    setIsCreating(true);
    apiFetch({
      path: "/creator/v1/admin/api-keys",
      method: "POST",
      data: {
        name: newKeyName,
        user_id: parseInt(selectedUser, 10),
        expires_at: resolveExpiration(),
      },
    })
      .then((res) => {
        if (res.success) {
          setCreatedKey({ plain: res.plain_key, name: res.key.name });
          setApiKeys((current) => [...current, res.key]);
          setNewKeyName("");
          setSelectedUser("");
          setExpirationChoice("");
          setCustomExpiration("");
        }
      })
      .catch((error) =>
        setMessage({
          type: "error",
          text: error?.message || __("Error al crear.", "wp-api-creator"),
        }),
      )
      .finally(() => setIsCreating(false));
  };

  const handleDeleteKey = (id) => {
    if (!confirm(__("¿Revocar esta API Key?", "wp-api-creator"))) return;

    apiFetch({ path: `/creator/v1/admin/api-keys/${id}`, method: "DELETE" })
      .then((res) => {
        if (res.success) {
          setApiKeys((current) => current.filter((k) => k.id !== id));
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

  const renderStatus = (key) => {
    if (key.legacy) {
      return (
        <span className="tw-text-[11px] tw-font-bold tw-bg-warning/10 tw-text-warning tw-px-2.5 tw-py-0.5 tw-rounded-full">
          {__("Obsoleta", "wp-api-creator")}
        </span>
      );
    }
    if (key.expired) {
      return (
        <span className="tw-text-[11px] tw-font-bold tw-bg-destructive/10 tw-text-destructive tw-px-2.5 tw-py-0.5 tw-rounded-full">
          {__("Caducada", "wp-api-creator")}
        </span>
      );
    }
    return (
      <span className="tw-text-[11px] tw-font-bold tw-bg-success/10 tw-text-success tw-px-2.5 tw-py-0.5 tw-rounded-full">
        {__("Activa", "wp-api-creator")}
      </span>
    );
  };

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

  const hasLegacyKeys = apiKeys.some((key) => key.legacy);

  return (
    <div className="tw-space-y-8">
      {createdKey && (
        <NewApiKeyModal
          plainKey={createdKey.plain}
          keyName={createdKey.name}
          onClose={() => setCreatedKey(null)}
        />
      )}

      <div>
        <h3 className="tw-text-xs tw-font-semibold tw-uppercase tw-tracking-widest tw-text-foreground-subtle tw-m-0 tw-mb-4">
          {__("API Keys", "wp-api-creator")}
        </h3>
        <p className="tw-text-sm tw-text-foreground-muted">
          {__(
            "Cada clave se asocia a un usuario de WordPress y hereda sus permisos. Se guarda hasheada: el valor en claro solo se muestra al crearla.",
            "wp-api-creator",
          )}
        </p>
      </div>

      {message && (
        <Notice
          status={message.type}
          onDismiss={() => setMessage(null)}
          className="tw-rounded-xl"
        >
          {message.text}
        </Notice>
      )}

      {hasLegacyKeys && (
        <Notice status="warning" isDismissible={false} className="tw-rounded-xl">
          {__(
            "Las claves marcadas como obsoletas pertenecen al esquema anterior y ya no autentican: no consta a qué usuario correspondían. Crea una nueva clave para cada integración y actualiza sus credenciales.",
            "wp-api-creator",
          )}
        </Notice>
      )}

      {users.error && (
        <Notice status="error" isDismissible={false} className="tw-rounded-xl">
          {users.error}
        </Notice>
      )}

      <div className="tw-grid tw-grid-cols-1 md:tw-grid-cols-4 tw-gap-4 tw-items-end tw-bg-foreground/[0.02] tw-p-6 tw-rounded-2xl tw-border tw-border-border">
        <div>
          <span className="tw-text-[13px] tw-font-medium tw-text-foreground tw-mb-2 tw-block">
            {__("Usuario propietario", "wp-api-creator")}
          </span>
          <Combobox
            options={users.options}
            value={selectedUser}
            onSelect={setSelectedUser}
            onSearch={users.search}
            isLoading={users.isLoading}
            placeholder={__("Selecciona un usuario...", "wp-api-creator")}
          />
        </div>

        <TextControl
          label={
            <span className="tw-text-[13px] tw-font-medium tw-text-foreground tw-mb-2 tw-block">
              {__("Nombre identificador", "wp-api-creator")}
            </span>
          }
          placeholder={__("Ej: App Externa, Partner...", "wp-api-creator")}
          value={newKeyName}
          onChange={setNewKeyName}
          __nextHasNoMarginBottom
        />

        <div>
          <SelectControl
            label={
              <span className="tw-text-[13px] tw-font-medium tw-text-foreground tw-mb-2 tw-block">
                {__("Caducidad", "wp-api-creator")}
              </span>
            }
            value={expirationChoice}
            options={EXPIRATION_CHOICES}
            onChange={setExpirationChoice}
            __nextHasNoMarginBottom
          />
          {expirationChoice === "custom" && (
            <input
              type="date"
              value={customExpiration}
              onChange={(e) => setCustomExpiration(e.target.value)}
              className="tw-mt-2 tw-w-full tw-h-10 tw-rounded-md tw-border tw-border-border tw-px-3 tw-text-sm"
            />
          )}
        </div>

        <button
          onClick={handleCreateKey}
          disabled={isCreating || !selectedUser || !newKeyName.trim()}
          className="apig-btn apig-btn-primary tw-h-12 tw-px-8 disabled:tw-opacity-50 disabled:tw-cursor-not-allowed"
        >
          {isCreating ? <Spinner /> : __("Generar Llave", "wp-api-creator")}
        </button>
      </div>

      <div className="tw-overflow-x-auto tw-border tw-border-border tw-rounded-xl">
        <table className="tw-w-full">
          <thead className="tw-bg-foreground/[0.02]">
            <tr>
              {[
                __("Nombre", "wp-api-creator"),
                __("Clave", "wp-api-creator"),
                __("Propietario", "wp-api-creator"),
                __("Caducidad", "wp-api-creator"),
                __("Último uso", "wp-api-creator"),
                __("Estado", "wp-api-creator"),
                "",
              ].map((label, index) => (
                <th
                  key={index}
                  className="tw-px-5 tw-py-4 tw-text-left tw-text-[11px] tw-font-semibold tw-uppercase tw-tracking-widest tw-text-foreground-subtle tw-border-b tw-border-border"
                >
                  {label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody className="tw-divide-y tw-divide-border/50">
            {apiKeys.length === 0 ? (
              <tr>
                <td
                  colSpan="7"
                  className="tw-px-6 tw-py-12 tw-text-center tw-text-sm tw-text-foreground-muted"
                >
                  {__("No hay API Keys generadas.", "wp-api-creator")}
                </td>
              </tr>
            ) : (
              apiKeys.map((key) => (
                <tr key={key.id} className="tw-group hover:tw-bg-foreground/[0.01]">
                  <td className="tw-px-5 tw-py-4 tw-text-[14px] tw-font-semibold">
                    {key.name}
                  </td>
                  <td className="tw-px-5 tw-py-4">
                    <code className="tw-text-[12px] tw-font-mono tw-text-foreground-muted tw-bg-foreground/5 tw-px-3 tw-py-1.5 tw-rounded-xl">
                      {key.prefix ? `${key.prefix}…` : "—"}
                    </code>
                  </td>
                  <td className="tw-px-5 tw-py-4 tw-text-[13px]">
                    {key.user_name || (
                      <span className="tw-text-foreground-subtle">
                        {__("Usuario no disponible", "wp-api-creator")}
                      </span>
                    )}
                  </td>
                  <td className="tw-px-5 tw-py-4 tw-text-[13px]">
                    {formatTimestamp(key.expires_at) ||
                      __("Sin caducidad", "wp-api-creator")}
                  </td>
                  <td className="tw-px-5 tw-py-4 tw-text-[13px]">
                    {formatTimestamp(key.last_used_at) ||
                      __("Nunca usada", "wp-api-creator")}
                  </td>
                  <td className="tw-px-5 tw-py-4">{renderStatus(key)}</td>
                  <td className="tw-px-5 tw-py-4 tw-text-right">
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

      <div className="tw-bg-muted/50 tw-border tw-border-border tw-rounded-2xl tw-p-6">
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
  );
}
