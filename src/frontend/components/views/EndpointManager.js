import { useState, useEffect, useCallback } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { Button, Spinner, Notice, ToggleControl } from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";
import EndpointEditor from "./EndpointEditor";

/* ===========================
   Iconos (Estilo Lucide/Shadcn)
   =========================== */
function ExternalLinkIcon({ className = "" }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="14"
      height="14"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
    >
      <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
      <polyline points="15 3 21 3 21 9" />
      <line x1="10" y1="14" x2="21" y2="3" />
    </svg>
  );
}

function PlusIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      className="tw-mr-2"
    >
      <line x1="12" y1="5" x2="12" y2="19" />
      <line x1="5" y1="12" x2="19" y2="12" />
    </svg>
  );
}

/* ===========================
   Endpoint Manager (Unificado)
   =========================== */
function EndpointManager({ onSaved }) {
  // Custom endpoints
  const [customEndpoints, setCustomEndpoints] = useState([]);
  // Global routes
  const [globalRoutes, setGlobalRoutes] = useState([]);
  // UI states
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState(null);
  const [saveMsg, setSaveMsg] = useState(null);
  const [view, setView] = useState("list"); // "list" | "editor"
  const [editingEndpoint, setEditingEndpoint] = useState(null);
  const [editingIsGlobal, setEditingIsGlobal] = useState(false);
  const [isSavingGlobal, setIsSavingGlobal] = useState(false);
  // Namespace & site URL
  const [apiNamespace, setApiNamespace] = useState(
    window.wpApiCreatorData && window.wpApiCreatorData.root_url
      ? window.wpApiCreatorData.root_url
          .split("/wp-json/")[1]
          .replace(/\/$/, "")
      : "creator/v1",
  );
  const [siteUrl, setSiteUrl] = useState("");

  // ---- Load data ----
  const loadData = useCallback(function () {
    setIsLoading(true);
    Promise.all([
      apiFetch({ path: "/creator/v1/admin/endpoints" }),
      apiFetch({ path: "/creator/v1/admin/all-routes" }),
      apiFetch({ path: "/creator/v1/admin/settings" }),
    ])
      .then(function (results) {
        const endpointsRes = results[0];
        const routesRes = results[1];
        const settingsRes = results[2];

        if (endpointsRes.success && endpointsRes.data)
          setCustomEndpoints(endpointsRes.data);
        if (routesRes.success) setGlobalRoutes(routesRes.data || []);
        if (settingsRes) {
          const sData = settingsRes.data || settingsRes;
          setApiNamespace(sData.api_namespace || "creator/v1");
          setSiteUrl(window.location.origin);
        }
      })
      .catch(function (err) {
        setError(err.message);
      })
      .finally(function () {
        setIsLoading(false);
      });
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  // ---- Custom Endpoint CRUD ----
  function handleSaveEndpoint(newEndpoint, oldSlug) {
    setIsLoading(true);
    let updated = [...customEndpoints];
    if (oldSlug) {
      const idx = updated.findIndex(function (e) {
        return e.slug === oldSlug;
      });
      if (idx > -1) updated[idx] = newEndpoint;
    } else {
      updated.push(newEndpoint);
    }
    apiFetch({
      path: "/creator/v1/admin/endpoints",
      method: "POST",
      data: { endpoints: updated },
    })
      .then(function (res) {
        if (res.success && res.data) {
          setCustomEndpoints(res.data);
          setView("list");
          if (onSaved) onSaved();
        }
      })
      .finally(function () {
        setIsLoading(false);
      });
  }

  function handleDelete(slug) {
    if (
      !window.confirm(
        __("¿Estás seguro de eliminar este endpoint?", "wp-api-creator"),
      )
    )
      return;

    setIsLoading(true);
    const updated = customEndpoints.filter(function (e) {
      return e.slug !== slug;
    });
    apiFetch({
      path: "/creator/v1/admin/endpoints",
      method: "POST",
      data: { endpoints: updated },
    })
      .then(function (res) {
        if (res.success && res.data) {
          setCustomEndpoints(res.data);
          if (onSaved) onSaved();
        }
      })
      .catch(function (err) {
        setError(err.message);
      })
      .finally(function () {
        setIsLoading(false);
      });
  }

  function handleToggleEnabled(slug) {
    const updated = customEndpoints.map((ep) => {
      if (ep.slug === slug) {
        const newState = ep.enabled === false ? true : false;
        return {
          ...ep,
          enabled: newState,
          show_in_swagger: newState,
        };
      }
      return ep;
    });

    setCustomEndpoints(updated);

    apiFetch({
      path: "/creator/v1/admin/endpoints",
      method: "POST",
      data: { endpoints: updated },
    }).catch((err) => {
      setError(err.message);
      loadData();
    });
  }

  // ---- Global Route Toggle ----
  function handleToggleGlobal(route) {
    setGlobalRoutes(function (prev) {
      return prev.map(function (r) {
        return r.route === route ? { ...r, visible: !r.visible } : r;
      });
    });
  }

  function handleToggleAllInNamespace(ns, visible) {
    setGlobalRoutes(function (prev) {
      return prev.map(function (r) {
        return r.namespace === ns ? { ...r, visible: visible } : r;
      });
    });
  }

  function handleSaveGlobalRoutes() {
    setIsSavingGlobal(true);
    const visible = globalRoutes
      .filter(function (r) {
        return r.visible;
      })
      .map(function (r) {
        return r.route;
      });
    apiFetch({
      path: "/creator/v1/admin/global-routes",
      method: "POST",
      data: { visible_routes: visible },
    })
      .then(function (res) {
        setSaveMsg({
          type: res.success ? "success" : "error",
          text: res.success
            ? __("Configuración guardada.", "wp-api-creator")
            : res.message,
        });
        if (res.success && onSaved) onSaved();
      })
      .catch(function (err) {
        setSaveMsg({ type: "error", text: err.message });
      })
      .finally(function () {
        setIsSavingGlobal(false);
      });
  }

  // ---- Edit handler ----
  function handleEdit(ep, isGlobal) {
    if (ep === undefined) ep = null;
    if (isGlobal === undefined) isGlobal = false;

    if (isGlobal && ep) {
      const routeParts = (ep.route || "").replace(/^\//, "").split("/");
      const lastSegment = routeParts[routeParts.length - 1] || "";

      const routeToPostType = {
        posts: "post",
        pages: "page",
        media: "attachment",
        categories: "category",
        tags: "post_tag",
      };
      const guessedPostType = routeToPostType[lastSegment] || lastSegment;

      apiFetch({
        path: `/creator/v1/admin/wp-endpoint-config?post_type=${guessedPostType}`,
      })
        .then(function (res) {
          const saved = res && res.success && res.data ? res.data : null;
          setEditingEndpoint({
            slug: lastSegment,
            post_type: guessedPostType,
            description: (saved && saved.description) || ep.name || "",
            enabled:
              saved && typeof saved.enabled !== "undefined"
                ? saved.enabled
                : ep.visible || false,
            permissions: (saved && saved.permissions) ||
              ep.permissions || {
                read: ["public"],
                write: ["editor", "administrator"],
                delete: ["administrator"],
              },
            exposed_fields:
              (saved && saved.exposed_fields) || ep.exposed_fields || [],
          });
          setEditingIsGlobal(isGlobal);
          setView("editor");
        })
        .catch(function () {
          setEditingEndpoint({
            slug: lastSegment,
            post_type: guessedPostType,
            description: ep.name || "",
            enabled: ep.visible || false,
            permissions: ep.permissions || {
              read: ["public"],
              write: ["editor", "administrator"],
              delete: ["administrator"],
            },
            exposed_fields: ep.exposed_fields || [],
          });
          setEditingIsGlobal(isGlobal);
          setView("editor");
        });
      return;
    }
    setEditingEndpoint(ep);
    setEditingIsGlobal(isGlobal);
    setView("editor");
  }

  // Build a full URL for an endpoint
  function buildEndpointUrl(path) {
    const base = (siteUrl || window.location.origin).replace(/\/+$/, "");
    const cleanPath = (path || "").replace(/^\/+/, "");
    return `${base}/wp-json/${cleanPath}`;
  }

  // ---- Editor view ----
  if (view === "editor") {
    return (
      <EndpointEditor
        endpoint={editingEndpoint}
        isGlobal={editingIsGlobal}
        onSave={handleSaveEndpoint}
        onCancel={() => setView("list")}
      />
    );
  }

  // ---- Counts ----
  const activeGlobal = globalRoutes.filter((r) => r.visible).length;

  return (
    <div className="apig-animate">
      {/* Header */}
      <div className="tw-flex tw-items-center tw-justify-between tw-mb-8">
        <div>
          <h2 className="tw-text-2xl tw-font-bold tw-tracking-tight tw-text-foreground tw-m-0">
            Endpoints
          </h2>
          <p className="tw-text-sm tw-text-foreground-muted tw-mt-1.5 tw-mb-0">
            {__(
              "Gestiona tus endpoints personalizados y las rutas integradas de WordPress.",
              "wp-api-creator",
            )}
          </p>
        </div>
        <button
          onClick={() => handleEdit(null, false)}
          className="apig-btn apig-btn-primary"
        >
          <PlusIcon />
          {__("Crear Endpoint", "wp-api-creator")}
        </button>
      </div>

      {error && (
        <div className="tw-mb-6">
          <Notice status="error" onDismiss={() => setError(null)}>
            {error}
          </Notice>
        </div>
      )}
      {saveMsg && (
        <div className="tw-mb-6">
          <Notice status={saveMsg.type} onDismiss={() => setSaveMsg(null)}>
            {saveMsg.text}
          </Notice>
        </div>
      )}

      {isLoading ? (
        <div className="tw-flex tw-items-center tw-justify-center tw-py-20 tw-text-foreground-subtle">
          <Spinner />
        </div>
      ) : (
        <div className="tw-space-y-12">
          {/* ---- SECCIÓN: Endpoints Personalizados ---- */}
          <section>
            <div className="tw-flex tw-items-center tw-gap-2 tw-mb-4">
              <h3 className="tw-text-base tw-font-semibold tw-text-foreground tw-m-0">
                {__("Endpoints Personalizados", "wp-api-creator")}
              </h3>
              <span className="tw-inline-flex tw-items-center tw-rounded-full tw-border tw-px-2.5 tw-py-0.5 tw-text-xs tw-font-semibold tw-border-transparent tw-bg-primary/10 tw-text-primary">
                {customEndpoints.length}
              </span>
            </div>

            {customEndpoints.length === 0 ? (
              <div className="tw-flex tw-flex-col tw-items-center tw-justify-center tw-rounded-lg tw-border-2 tw-border-dashed tw-border-border tw-p-12 tw-text-center">
                <div className="tw-mb-4 tw-rounded-full tw-bg-primary/5 tw-p-3">
                  <PlusIcon />
                </div>
                <h3 className="tw-text-base tw-font-semibold tw-text-foreground">
                  {__("Sin endpoints", "wp-api-creator")}
                </h3>
                <p className="tw-mt-2 tw-text-sm tw-text-foreground-muted">
                  {__(
                    "Empieza creando un endpoint personalizado para tu contenido.",
                    "wp-api-creator",
                  )}
                </p>
              </div>
            ) : (
              <div className="tw-rounded-xl tw-border tw-border-border tw-bg-card tw-shadow-sm tw-overflow-hidden">
                <div className="tw-divide-y tw-divide-border-strong">
                  {customEndpoints.map((ep, index) => {
                    const fullUrl = buildEndpointUrl(
                      `${apiNamespace}/${ep.slug}`,
                    );
                    return (
                      <div
                        key={ep.slug}
                        className="tw-group tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-5 apig-row-hover tw-transition-all"
                      >
                        <div className="tw-flex-1 tw-min-w-0">
                          <div className="tw-flex tw-items-center tw-gap-3 tw-mb-2">
                            <span className="tw-text-base tw-font-bold tw-text-foreground tw-tracking-tight">
                              {ep.slug}
                            </span>
                            <span className="tw-inline-flex tw-items-center tw-rounded-md tw-bg-secondary tw-px-1.5 tw-py-0.5 tw-text-[10px] tw-font-bold tw-text-secondary-foreground tw-uppercase">
                              {ep.post_type}
                            </span>
                            {ep.enabled !== false && (
                              <span className="tw-inline-flex tw-items-center tw-rounded-full tw-bg-emerald-500/10 tw-px-1.5 tw-py-px tw-text-[9px] tw-font-bold tw-text-emerald-600 tw-uppercase tw-tracking-tight tw-border tw-border-emerald-500/20">
                                Swagger
                              </span>
                            )}
                          </div>
                          {ep.description && (
                            <p className="tw-text-sm tw-text-foreground-muted tw-m-0 tw-mb-2 tw-line-clamp-1">
                              {ep.description}
                            </p>
                          )}
                          <div className="tw-flex tw-items-center tw-gap-2">
                            <div className="tw-flex tw-items-center tw-gap-1.5 tw-rounded-md tw-bg-transparent tw-px-2 tw-py-1 tw-border tw-border-border hover:tw-border-primary/30 tw-transition-colors">
                              <code className="tw-text-[11px] tw-text-foreground/80 tw-font-mono tw-font-medium">
                                /{apiNamespace}/{ep.slug}
                              </code>
                              <a
                                href={fullUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="tw-text-foreground-muted hover:tw-text-primary tw-transition-colors"
                              >
                                <ExternalLinkIcon />
                              </a>
                            </div>
                            <div className="tw-flex tw-flex-wrap tw-gap-1">
                              {(ep.methods || ["GET"]).map((m) => (
                                <span
                                  key={m}
                                  className={`tw-text-[9px] tw-px-1.5 tw-py-0.5 tw-rounded tw-uppercase tw-font-bold tw-border ${
                                    m === "GET"
                                      ? "tw-bg-success-muted/50 tw-text-success-foreground tw-border-success-muted"
                                      : m === "POST"
                                      ? "tw-bg-info-muted/50 tw-text-info-foreground tw-border-info-muted"
                                      : m === "DELETE"
                                      ? "tw-bg-destructive-muted/50 tw-text-destructive-foreground tw-border-destructive-muted"
                                      : "tw-bg-muted tw-text-foreground-muted tw-border-border"
                                  }`}
                                >
                                  {m}
                                </span>
                              ))}
                            </div>
                          </div>
                        </div>
                        <div className="tw-flex tw-items-center tw-gap-4">
                          <div className="tw-flex tw-items-center tw-gap-2 tw-px-3 tw-py-1 tw-bg-muted/30 tw-rounded-lg tw-border tw-border-border">
                            <span className="tw-text-[10px] tw-font-bold tw-text-foreground-muted tw-uppercase">
                              {__("Estado", "wp-api-creator")}
                            </span>
                            <ToggleControl
                              checked={ep.enabled !== false}
                              onChange={() => handleToggleEnabled(ep.slug)}
                              __nextHasNoMarginBottom
                            />
                          </div>
                          <div className="tw-flex tw-items-center tw-gap-2">
                            <button
                              onClick={() => handleEdit(ep, false)}
                              className="apig-btn apig-btn-outline apig-btn-sm"
                            >
                              {__("Editar", "wp-api-creator")}
                            </button>
                            <button
                              onClick={() => handleDelete(ep.slug)}
                              className="apig-btn apig-btn-destructive apig-btn-sm"
                            >
                              {__("Eliminar", "wp-api-creator")}
                            </button>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            )}
          </section>

          {/* ---- SECCIÓN: Rutas Globales (WordPress) ---- */}
          <section>
            <div className="tw-flex tw-items-center tw-justify-between tw-mb-6">
              <div className="tw-flex tw-items-center tw-gap-2">
                <h3 className="tw-text-base tw-font-semibold tw-text-foreground tw-m-0">
                  {__("Rutas de WordPress", "wp-api-creator")}
                </h3>
                <span className="tw-inline-flex tw-items-center tw-rounded-full tw-border tw-px-2.5 tw-py-0.5 tw-text-xs tw-font-semibold tw-border-transparent tw-bg-secondary tw-text-secondary-foreground">
                  {activeGlobal}/{globalRoutes.length}
                </span>
              </div>
              <button
                onClick={handleSaveGlobalRoutes}
                disabled={isSavingGlobal}
                className="apig-btn apig-btn-primary apig-btn-sm"
              >
                {isSavingGlobal ? (
                  <Spinner />
                ) : (
                  __("Guardar Cambios", "wp-api-creator")
                )}
              </button>
            </div>

            <div className="tw-mb-6 tw-rounded-lg tw-border tw-border-border tw-bg-muted/30 tw-p-4">
              <p className="tw-text-sm tw-text-foreground-muted tw-m-0">
                {__(
                  "Las rutas activadas se indexarán automáticamente y estarán configurables para el archivo Swagger/OpenAPI.",
                  "wp-api-creator",
                )}
              </p>
            </div>

            {(() => {
              const grouped = globalRoutes.reduce((acc, r) => {
                const ns = r.namespace || "core";
                if (!acc[ns]) acc[ns] = [];
                acc[ns].push(r);
                return acc;
              }, {});

              return (
                <div className="tw-space-y-6">
                  {Object.keys(grouped)
                    .sort()
                    .map((ns) => (
                      <div
                        key={ns}
                        className="tw-rounded-xl tw-border tw-border-border tw-bg-card tw-shadow-sm tw-overflow-hidden"
                      >
                        <div className="tw-px-6 tw-py-3 tw-bg-muted/50 tw-border-b tw-border-border tw-flex tw-items-center tw-justify-between">
                          <span className="tw-text-[10px] tw-font-bold tw-text-foreground-muted tw-uppercase tw-tracking-widest">
                            {ns}
                          </span>
                          <div className="tw-flex tw-items-center tw-gap-4">
                            <span className="tw-text-[10px] tw-font-medium tw-text-foreground-muted">
                              {grouped[ns].filter((r) => r.visible).length} /{" "}
                              {grouped[ns].length} activos
                            </span>
                            <div className="tw-flex tw-gap-2">
                              <button
                                onClick={() =>
                                  handleToggleAllInNamespace(ns, true)
                                }
                                className="tw-text-[10px] tw-font-bold tw-text-primary hover:tw-underline tw-bg-transparent tw-border-0 tw-p-0 tw-cursor-pointer"
                              >
                                {__("Activar todos", "wp-api-creator")}
                              </button>
                              <div className="tw-w-px tw-h-3 tw-bg-border" />
                              <button
                                onClick={() =>
                                  handleToggleAllInNamespace(ns, false)
                                }
                                className="tw-text-[10px] tw-font-bold tw-text-destructive hover:tw-underline tw-bg-transparent tw-border-0 tw-p-0 tw-cursor-pointer"
                              >
                                {__("Desactivar todos", "wp-api-creator")}
                              </button>
                            </div>
                          </div>
                        </div>
                        <div className="tw-divide-y tw-divide-border-strong">
                          {grouped[ns].map((item) => {
                            const routeUrl = buildEndpointUrl(item.route);
                            return (
                              <div
                                key={item.route}
                                className="tw-group tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-4 apig-row-hover tw-transition-colors"
                              >
                                <div className="tw-flex-1 tw-min-w-0">
                                  <div className="tw-flex tw-items-center tw-gap-2 tw-mb-1.5">
                                    <span className="tw-text-sm tw-font-semibold tw-text-foreground">
                                      {item.name !== item.route
                                        ? item.name
                                        : __("Ruta Genérica", "wp-api-creator")}
                                    </span>
                                    {item.visible && (
                                      <span className="tw-inline-flex tw-items-center tw-rounded-full tw-bg-emerald-500/10 tw-px-1.5 tw-py-px tw-text-[9px] tw-font-bold tw-text-emerald-600 tw-uppercase tw-tracking-tight tw-border tw-border-emerald-500/20">
                                        Swagger
                                      </span>
                                    )}
                                  </div>
                                  <div className="tw-flex tw-items-center tw-gap-1.5">
                                    <code className="tw-text-[11px] tw-text-foreground-muted tw-font-mono">
                                      {item.route}
                                    </code>
                                    <a
                                      href={routeUrl}
                                      target="_blank"
                                      rel="noopener noreferrer"
                                      className="tw-text-foreground-subtle hover:tw-text-primary tw-transition-colors"
                                    >
                                      <ExternalLinkIcon />
                                    </a>
                                  </div>
                                </div>
                                <div className="tw-flex tw-items-center tw-gap-6">
                                  <button
                                    onClick={() => handleEdit(item, true)}
                                    className="tw-text-xs tw-font-medium tw-text-foreground-muted hover:tw-text-foreground tw-bg-transparent tw-border-0 tw-cursor-pointer tw-transition-colors hover:tw-underline"
                                  >
                                    {__("Configurar", "wp-api-creator")}
                                  </button>
                                  <div className="tw-flex tw-items-center">
                                    <ToggleControl
                                      label=""
                                      checked={item.visible}
                                      onChange={() =>
                                        handleToggleGlobal(item.route)
                                      }
                                      __nextHasNoMarginBottom
                                    />
                                  </div>
                                </div>
                              </div>
                            );
                          })}
                        </div>
                      </div>
                    ))}
                </div>
              );
            })()}
          </section>
        </div>
      )}
    </div>
  );
}

export default EndpointManager;
