import { useState, useEffect } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import {
  Button,
  TextControl,
  ToggleControl,
  Spinner,
  Notice,
} from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";

function Settings({ onSaved }) {
  const [settings, setSettings] = useState({
    cache_time: 0,
    require_api_key: false,
  });
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isFlushing, setIsFlushing] = useState(false);
  const [message, setMessage] = useState(null);

  useEffect(() => {
    apiFetch({ path: "/creator/v1/admin/settings" })
      .then((res) => {
        if (res.success) setSettings(res.data);
      })
      .catch(() =>
        setMessage({
          type: "error",
          text: __("Error al cargar la configuración.", "wp-api-creator"),
        }),
      )
      .finally(() => setIsLoading(false));
  }, []);

  const handleSave = () => {
    setIsSaving(true);
    apiFetch({
      path: "/creator/v1/admin/settings",
      method: "POST",
      data: { settings },
    })
      .then((res) => {
        if (res.success) {
          setMessage({
            type: "success",
            text: __("Configuración guardada.", "wp-api-creator"),
          });
          if (onSaved) onSaved();
        }
      })
      .catch(() =>
        setMessage({
          type: "error",
          text: __("Error al guardar.", "wp-api-creator"),
        }),
      )
      .finally(() => setIsSaving(false));
  };

  const handleFlush = () => {
    setIsFlushing(true);
    apiFetch({ path: "/creator/v1/admin/flush-cache", method: "POST" })
      .then((res) => {
        if (res.success)
          setMessage({
            type: "success",
            text: res.message || __("Caché limpiada.", "wp-api-creator"),
          });
      })
      .catch(() =>
        setMessage({
          type: "error",
          text: __("Error al limpiar caché.", "wp-api-creator"),
        }),
      )
      .finally(() => setIsFlushing(false));
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

  return (
    <div className="apig-animate tw-space-y-8">
      <div className="tw-flex tw-items-center tw-justify-between">
        <div>
          <h2 className="tw-text-lg tw-font-semibold tw-text-foreground tw-m-0">
            {__("Configuración Global", "wp-api-creator")}
          </h2>
          <p className="tw-text-sm tw-text-foreground-muted tw-mt-1.2 tw-mb-0">
            {__(
              "Ajusta el comportamiento base y seguridad de tu API.",
              "wp-api-creator",
            )}
          </p>
        </div>
        <div className="tw-flex tw-gap-2">
          <button
            onClick={handleSave}
            disabled={isSaving}
            className="apig-btn apig-btn-primary tw-px-6"
          >
            {isSaving ? <Spinner /> : __("Guardar Cambios", "wp-api-creator")}
          </button>
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

      {/* Rendimiento & Rutas */}
      <section className="tw-bg-background tw-rounded-2xl tw-border tw-border-border tw-shadow-sm/5 tw-overflow-hidden">
        <div className="tw-px-6 tw-py-4 tw-bg-foreground/[0.02] tw-border-b tw-border-border">
          <h3 className="tw-text-[11px] tw-font-semibold tw-uppercase tw-tracking-widest tw-text-foreground-muted tw-m-0">
            {__("Rendimiento y Rutas", "wp-api-creator")}
          </h3>
        </div>

        <div className="tw-p-6 tw-space-y-6">
          <div className="tw-group">
            <TextControl
              label={
                <span className="tw-text-sm tw-font-semibold tw-text-foreground tw-mb-1.5 tw-block">
                  {__("Namespace Global", "wp-api-creator")}
                </span>
              }
              value={settings.api_namespace || "creator/v1"}
              onChange={(val) =>
                setSettings({ ...settings, api_namespace: val })
              }
              help={
                <span className="tw-text-[11px] tw-text-foreground-muted tw-mt-1.5 tw-block">
                  {__("Base de todas las rutas:", "wp-api-creator")}{" "}
                  <code className="tw-bg-foreground/5 tw-px-1.5 tw-py-0.5 tw-rounded-md tw-text-primary tw-font-bold tw-font-mono">
                    /wp-json/{settings.api_namespace || "..."}/...
                  </code>
                </span>
              }
            />
          </div>

          <div className="tw-group">
            <TextControl
              label={
                <span className="tw-text-sm tw-font-semibold tw-text-foreground tw-mb-1.5 tw-block">
                  {__("Tiempo de Caché (segundos)", "wp-api-creator")}
                </span>
              }
              type="number"
              value={settings.cache_time}
              onChange={(val) =>
                setSettings({ ...settings, cache_time: parseInt(val) || 0 })
              }
              help={__(
                "0 para desactivar. Recomendado: 300.",
                "wp-api-creator",
              )}
            />
          </div>

          <div className="tw-flex tw-items-center tw-justify-between tw-p-4 tw-bg-foreground/5 tw-rounded-xl tw-border tw-border-border/50">
            <div className="tw-flex tw-flex-col tw-gap-1">
              <span className="tw-text-sm tw-font-semibold tw-text-foreground">
                {__("Filtrar endpoints de WordPress", "wp-api-creator")}
              </span>
              <span className="tw-text-[11px] tw-text-foreground-muted">
                {__(
                  "Limita los campos devueltos por los endpoints nativos de WP.",
                  "wp-api-creator",
                )}
              </span>
            </div>
            <ToggleControl
              checked={settings.filter_wp_endpoints || false}
              onChange={(val) =>
                setSettings({ ...settings, filter_wp_endpoints: val })
              }
              className="tw-m-0"
            />
          </div>
        </div>
      </section>

      {/* Accciones Extra */}
      <div className="tw-pt-4 tw-flex tw-items-center tw-justify-end tw-gap-3">
        <button
          onClick={handleFlush}
          disabled={isFlushing}
          className="apig-btn apig-btn-secondary tw-text-xs"
        >
          <span className="dashicons dashicons-trash tw-mr-1.5 tw-text-[14px]"></span>
          {__("Limpiar caché de metadatos", "wp-api-creator")}
        </button>
      </div>
    </div>
  );
}

export default Settings;
