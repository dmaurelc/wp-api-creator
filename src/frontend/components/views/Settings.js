import { useState, useEffect, useCallback } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { TextControl, ToggleControl, Spinner, Notice } from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";

function Settings({ onSaved }) {
  const [settings, setSettings] = useState(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState(null);
  const [isSaving, setIsSaving] = useState(false);
  const [isFlushing, setIsFlushing] = useState(false);
  const [message, setMessage] = useState(null);

  // Hasta que el GET tenga éxito el formulario no tiene datos con los que hacer
  // round-trip: guardar en ese estado enviaría un payload incompleto.
  const loadSettings = useCallback(() => {
    setIsLoading(true);
    setLoadError(null);
    apiFetch({ path: "/creator/v1/admin/settings" })
      .then((res) => {
        if (res.success) {
          setSettings(res.data);
        } else {
          setLoadError(__("La respuesta del servidor no fue válida.", "wp-api-creator"));
        }
      })
      .catch((error) =>
        setLoadError(
          error?.message ||
            __("Error al cargar la configuración.", "wp-api-creator"),
        ),
      )
      .finally(() => setIsLoading(false));
  }, []);

  useEffect(() => {
    loadSettings();
  }, [loadSettings]);

  const updateField = (key, value) =>
    setSettings((current) => ({ ...current, [key]: value }));

  const handleToggleEnforcement = (enabled) => {
    if (
      enabled &&
      !confirm(
        __(
          "Esto cerrará todas las rutas de tu namespace y la documentación. Los clientes sin credencial válida dejarán de funcionar. No afecta a los endpoints nativos de WordPress (/wp-json/wp/v2/...). ¿Continuar?",
          "wp-api-creator",
        ),
      )
    ) {
      return;
    }
    updateField("require_api_key", enabled);
  };

  const handleSave = () => {
    setIsSaving(true);
    apiFetch({
      path: "/creator/v1/admin/settings",
      method: "POST",
      data: { settings },
    })
      .then((res) => {
        if (res.success) {
          if (res.data) setSettings(res.data);
          setMessage({
            type: "success",
            text: __("Configuración guardada.", "wp-api-creator"),
          });
          if (onSaved) onSaved();
        }
      })
      .catch((error) =>
        setMessage({
          type: "error",
          // El servidor rechaza namespaces reservados o mal formados con un motivo concreto.
          text: error?.message || __("Error al guardar.", "wp-api-creator"),
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

  if (loadError || !settings) {
    return (
      <div className="apig-animate tw-space-y-5">
        <Notice status="error" isDismissible={false} className="tw-rounded-xl">
          {loadError}
        </Notice>
        <p className="tw-text-sm tw-text-foreground-muted">
          {__(
            "No se muestra el formulario para evitar guardar una configuración incompleta y borrar ajustes existentes.",
            "wp-api-creator",
          )}
        </p>
        <button onClick={loadSettings} className="apig-btn apig-btn-primary">
          {__("Reintentar", "wp-api-creator")}
        </button>
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
              value={settings.api_namespace || ""}
              onChange={(val) => updateField("api_namespace", val)}
              help={
                <span className="tw-text-[11px] tw-text-foreground-muted tw-mt-1.5 tw-block">
                  {__("Base de todas las rutas:", "wp-api-creator")}{" "}
                  <code className="tw-bg-foreground/5 tw-px-1.5 tw-py-0.5 tw-rounded-md tw-text-primary tw-font-bold tw-font-mono">
                    /wp-json/{settings.api_namespace || "mi-api/v1"}/...
                  </code>
                  <br />
                  {__(
                    'Formato "mi-api/v1". No se admiten namespaces reservados por WordPress ni el de administración del plugin.',
                    "wp-api-creator",
                  )}
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
              onChange={(val) => updateField("cache_time", parseInt(val) || 0)}
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
              onChange={(val) => updateField("filter_wp_endpoints", val)}
              className="tw-m-0"
            />
          </div>
        </div>
      </section>

      {/* Seguridad */}
      <section className="tw-bg-background tw-rounded-2xl tw-border tw-border-border tw-shadow-sm/5 tw-overflow-hidden">
        <div className="tw-px-6 tw-py-4 tw-bg-foreground/[0.02] tw-border-b tw-border-border">
          <h3 className="tw-text-[11px] tw-font-semibold tw-uppercase tw-tracking-widest tw-text-foreground-muted tw-m-0">
            {__("Seguridad", "wp-api-creator")}
          </h3>
        </div>

        <div className="tw-p-6">
          <div className="tw-flex tw-items-center tw-justify-between tw-p-4 tw-bg-foreground/5 tw-rounded-xl tw-border tw-border-border/50">
            <div className="tw-flex tw-flex-col tw-gap-1 tw-pr-6">
              <span className="tw-text-sm tw-font-semibold tw-text-foreground">
                {__("Exigir credencial en tu namespace", "wp-api-creator")}
              </span>
              <span className="tw-text-[11px] tw-text-foreground-muted">
                {__(
                  "Con esta opción activa, cualquier petición a las rutas de tu namespace sin API Key o token válido recibe un 401, incluso en endpoints marcados como públicos. La documentación en /docs también queda cerrada.",
                  "wp-api-creator",
                )}
              </span>
              <span className="tw-text-[11px] tw-text-foreground-subtle tw-mt-1">
                {__(
                  "No afecta a los endpoints nativos de WordPress (/wp-json/wp/v2/...), que WordPress registra por su cuenta y se rigen por sus propias reglas de acceso. Marcar una ruta como visible en «Rutas globales» solo la incluye en la documentación; no la expone ni la protege.",
                  "wp-api-creator",
                )}
              </span>
            </div>
            <ToggleControl
              checked={settings.require_api_key || false}
              onChange={handleToggleEnforcement}
              className="tw-m-0"
            />
          </div>

          {settings.require_api_key && (
            <div className="tw-mt-4">
              <Notice status="warning" isDismissible={false} className="tw-rounded-xl">
                {__(
                  "Recuerda guardar los cambios. Asegúrate de haber creado al menos una API Key activa antes de cerrar la API.",
                  "wp-api-creator",
                )}
              </Notice>
            </div>
          )}
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
