import { useState, useEffect } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { Button, Spinner } from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";

function statusBadge(code) {
  if (code >= 500)
    return "tw-bg-destructive-muted tw-text-destructive-foreground tw-border-destructive-muted";
  if (code >= 400)
    return "tw-bg-warning-muted tw-text-warning-foreground tw-border-warning-muted";
  if (code >= 200 && code < 300)
    return "tw-bg-success-muted tw-text-success-foreground tw-border-success-muted";
  return "tw-bg-background-muted tw-text-foreground-muted tw-border-border";
}

function Logs() {
  const [logs, setLogs] = useState([]);
  const [isLoading, setIsLoading] = useState(true);

  const fetchLogs = () => {
    setIsLoading(true);
    apiFetch({ path: "/creator/v1/admin/logs" })
      .then((res) => setLogs(res.data || []))
      .catch((err) => console.error(err))
      .finally(() => setIsLoading(false));
  };

  useEffect(() => {
    fetchLogs();
  }, []);

  const handleClear = () => {
    if (!confirm(__("¿Vaciar logs?", "wp-api-creator"))) return;
    setIsLoading(true);
    apiFetch({ path: "/creator/v1/admin/logs", method: "DELETE" })
      .then(() => setLogs([]))
      .finally(() => setIsLoading(false));
  };

  return (
    <div className="apig-animate">
      <div className="tw-flex tw-items-start tw-justify-between tw-mb-6">
        <div>
          <h2 className="tw-text-base tw-font-semibold tw-text-foreground tw-m-0">
            {__("Logs", "wp-api-creator")}
          </h2>
          <p className="tw-text-sm tw-text-foreground-muted tw-mt-1 tw-mb-0">
            {__("Últimas peticiones a tu API.", "wp-api-creator")}
          </p>
        </div>
        <div className="tw-flex tw-gap-2">
          <button
            onClick={fetchLogs}
            disabled={isLoading}
            className="apig-btn apig-btn-secondary"
          >
            {__("Refrescar", "wp-api-creator")}
          </button>
          <button
            onClick={handleClear}
            disabled={isLoading || logs.length === 0}
            className="tw-text-xs tw-text-destructive-foreground hover:tw-text-destructive tw-bg-transparent tw-border-0 tw-cursor-pointer tw-transition-colors tw-font-medium tw-px-2 disabled:tw-opacity-40 disabled:tw-cursor-not-allowed"
          >
            {__("Vaciar", "wp-api-creator")}
          </button>
        </div>
      </div>

      {isLoading ? (
        <div className="tw-flex tw-items-center tw-justify-center tw-py-20 tw-text-foreground-subtle">
          <Spinner style={{ width: 18, height: 18 }} />
          <span className="tw-ml-2 tw-text-sm">
            {__("Cargando...", "wp-api-creator")}
          </span>
        </div>
      ) : !Array.isArray(logs) || logs.length === 0 ? (
        <div className="tw-py-12 tw-text-center tw-text-foreground-subtle">
          <p className="tw-text-sm tw-m-0">
            {__("Sin logs recientes.", "wp-api-creator")}
          </p>
        </div>
      ) : (
        <div className="tw-border tw-border-border tw-rounded-lg tw-overflow-hidden">
          {/* Header */}
          <div className="tw-grid tw-grid-cols-12 tw-gap-2 tw-px-4 tw-py-2.5 tw-bg-background-muted tw-border-b tw-border-border">
            <div className="tw-col-span-2 tw-text-[11px] tw-font-semibold tw-text-foreground-subtle tw-uppercase tw-tracking-wider">
              {__("Hora", "wp-api-creator")}
            </div>
            <div className="tw-col-span-4 tw-text-[11px] tw-font-semibold tw-text-foreground-subtle tw-uppercase tw-tracking-wider">
              {__("Endpoint", "wp-api-creator")}
            </div>
            <div className="tw-col-span-2 tw-text-[11px] tw-font-semibold tw-text-foreground-subtle tw-uppercase tw-tracking-wider">
              {__("Origen", "wp-api-creator")}
            </div>
            <div className="tw-col-span-1 tw-text-[11px] tw-font-semibold tw-text-foreground-subtle tw-uppercase tw-tracking-wider">
              {__("Código", "wp-api-creator")}
            </div>
            <div className="tw-col-span-3 tw-text-[11px] tw-font-semibold tw-text-foreground-subtle tw-uppercase tw-tracking-wider">
              {__("Detalle", "wp-api-creator")}
            </div>
          </div>

          {/* Rows */}
          <div className="tw-divide-y tw-divide-border">
            {logs.map((log, i) => (
              <div
                key={i}
                className="tw-grid tw-grid-cols-12 tw-gap-2 tw-px-4 tw-py-2.5 tw-items-center hover:tw-bg-background-subtle tw-transition-colors apig-stagger"
                style={{ animationDelay: `${i * 25}ms` }}
              >
                <div className="tw-col-span-2 tw-text-xs tw-text-foreground-subtle tw-font-mono">
                  {log.timestamp}
                </div>
                <div className="tw-col-span-4 tw-text-xs tw-font-medium tw-text-foreground tw-truncate">
                  {log.endpoint}
                </div>
                <div className="tw-col-span-2 tw-text-xs tw-text-foreground-subtle">
                  {log.ip}
                  {log.user_label && (
                    <span className="tw-block tw-text-[10px] tw-italic">
                      {log.user_label}
                    </span>
                  )}
                </div>
                <div className="tw-col-span-1">
                  <span
                    className={`tw-text-xxs tw-font-bold tw-px-1.5 tw-py-0.5 tw-rounded tw-border ${statusBadge(
                      log.status_code,
                    )}`}
                  >
                    {log.status_code}
                  </span>
                </div>
                <div className="tw-col-span-3 tw-text-xs tw-text-foreground-subtle tw-truncate">
                  {log.message}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

export default Logs;
