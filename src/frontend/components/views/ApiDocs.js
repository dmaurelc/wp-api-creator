import { useState, useCallback } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { Button, Notice } from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";

function ApiDocs() {
  const [status, setStatus] = useState("idle");
  const [lastGen, setLastGen] = useState(null);
  const [iframeKey, setIframeKey] = useState(0);

  const handleBuild = useCallback(() => {
    setStatus("building");
    apiFetch({ path: "/creator/v1/admin/build-docs", method: "POST" })
      .then(() => {
        setStatus("success");
        setLastGen(new Date().toLocaleString());
        // Recargar iframe forzando nueva key
        setIframeKey((prev) => prev + 1);
      })
      .catch(() => setStatus("error"));
  }, []);

  const docsUrl =
    window.wpApiCreatorData && window.wpApiCreatorData.docs_url
      ? window.wpApiCreatorData.docs_url
      : "/wp-json/creator/v1/docs";
  const openApiUrl =
    window.wpApiCreatorData && window.wpApiCreatorData.openapi_json_url
      ? window.wpApiCreatorData.openapi_json_url
      : window.location.origin + "/wp-json/creator/v1/docs/openapi.json";

  return (
    <div className="apig-animate">
      <div className="tw-flex tw-items-start tw-justify-between tw-mb-6">
        <div>
          <h2 className="tw-text-base tw-font-semibold tw-text-foreground tw-m-0">
            {__("Documentación API", "wp-api-creator")}
          </h2>
          <p className="tw-text-sm tw-text-foreground-muted tw-mt-1 tw-mb-0">
            {__("Swagger/OpenAPI generada automáticamente.", "wp-api-creator")}
          </p>
        </div>
        <button
          onClick={handleBuild}
          disabled={status === "building"}
          className="apig-btn apig-btn-primary"
        >
          {status === "building"
            ? __("Generando...", "wp-api-creator")
            : __("Regenerar", "wp-api-creator")}
        </button>
      </div>

      {status === "success" && (
        <div className="tw-mb-5">
          <Notice
            status="success"
            isDismissible
            onDismiss={() => setStatus("idle")}
          >
            {__("Documentación actualizada.", "wp-api-creator")}
          </Notice>
        </div>
      )}
      {status === "error" && (
        <div className="tw-mb-5">
          <Notice
            status="error"
            isDismissible
            onDismiss={() => setStatus("idle")}
          >
            {__("Error al generar.", "wp-api-creator")}
          </Notice>
        </div>
      )}

      {/* Links & Export */}
      <div className="tw-grid tw-grid-cols-3 tw-gap-4 tw-mb-6">
        <div className="tw-p-4 tw-rounded-xl tw-border tw-border-border tw-bg-foreground/[0.02]">
          <span className="tw-text-[11px] tw-font-semibold tw-text-foreground-subtle tw-uppercase tw-tracking-wider tw-block tw-mb-2">
            {__("Swagger UI", "wp-api-creator")}
          </span>
          <a
            href={docsUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="tw-text-xs tw-text-primary hover:tw-text-primary/80 tw-font-bold tw-font-mono tw-break-all tw-no-underline hover:tw-underline"
          >
            {docsUrl}
          </a>
        </div>
        <div className="tw-p-4 tw-rounded-xl tw-border tw-border-border tw-bg-foreground/[0.02]">
          <span className="tw-text-[11px] tw-font-semibold tw-text-foreground-subtle tw-uppercase tw-tracking-wider tw-block tw-mb-2">
            {__("OpenAPI JSON", "wp-api-creator")}
          </span>
          <a
            href={openApiUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="tw-text-xs tw-text-primary hover:tw-text-primary/80 tw-font-bold tw-font-mono tw-break-all tw-no-underline hover:tw-underline"
          >
            {openApiUrl}
          </a>
        </div>
        <div className="tw-p-4 tw-rounded-xl tw-border tw-border-border tw-bg-foreground/[0.02] tw-flex tw-flex-col tw-justify-between">
          <div>
            <span className="tw-text-[11px] tw-font-semibold tw-text-foreground-subtle tw-uppercase tw-tracking-wider tw-block tw-mb-1">
              {__("Postman Collection", "wp-api-creator")}
            </span>
            <p className="tw-text-[11px] tw-text-foreground-muted tw-m-0 tw-mb-3">
              {__("Importa en Postman v2.1+", "wp-api-creator")}
            </p>
          </div>
          <button
            onClick={() => {
              const root =
                window.wpApiCreatorData?.root ||
                window.location.origin + "/wp-json/";
              const url = `${root}creator/v1/admin/export/postman?_wpnonce=${window.wpApiCreatorData.nonce}`;
              window.open(url, "_blank");
            }}
            className="apig-btn apig-btn-secondary apig-btn-sm tw-w-full"
          >
            <span className="dashicons dashicons-external tw-mr-1.5 tw-text-[14px]"></span>
            {__("Exportar JSON", "wp-api-creator")}
          </button>
        </div>
      </div>

      {lastGen && (
        <p className="tw-text-xs tw-text-foreground-subtle tw-mb-4 tw-m-0">
          {__("Última generación:", "wp-api-creator")} {lastGen}
        </p>
      )}

      {/* Preview */}
      <div className="tw-border tw-border-border tw-rounded-lg tw-overflow-hidden">
        <div className="tw-flex tw-items-center tw-justify-between tw-px-4 tw-py-2.5 tw-bg-background-muted tw-border-b tw-border-border">
          <span className="tw-text-xs tw-font-medium tw-text-foreground-muted">
            {__("Vista previa", "wp-api-creator")}
          </span>
          <a
            href={docsUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="tw-text-xs tw-text-primary hover:tw-text-primary/80 tw-font-bold tw-no-underline hover:tw-underline tw-flex tw-items-center tw-gap-1"
          >
            {__("Abrir", "wp-api-creator")}
            <svg
              className="tw-w-3 tw-h-3"
              fill="none"
              viewBox="0 0 24 24"
              strokeWidth={2}
              stroke="currentColor"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"
              />
            </svg>
          </a>
        </div>
        <iframe
          key={iframeKey}
          src={docsUrl}
          className="tw-w-full tw-border-0"
          style={{ height: "100dvh" }}
          title="Swagger UI"
        />
      </div>
    </div>
  );
}

export default ApiDocs;
