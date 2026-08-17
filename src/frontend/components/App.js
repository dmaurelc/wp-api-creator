import { useState, useEffect, useCallback } from "@wordpress/element";
import { Spinner } from "@wordpress/components";
import { __ } from "@wordpress/i18n";
import apiFetch from "@wordpress/api-fetch";

// Views
import EndpointManager from "./views/EndpointManager";
import Settings from "./views/Settings";
import AuthManager from "./views/AuthManager";
import ApiDocs from "./views/ApiDocs";
import Logs from "./views/Logs";

if (window.wpApiCreatorData) {
  apiFetch.use(apiFetch.createNonceMiddleware(window.wpApiCreatorData.nonce));
}

// ===========================
// Componente: Stat Chip
// ===========================
function StatChip({ label, children, loading }) {
  return (
    <div className="tw-flex tw-flex-col tw-gap-1.5 tw-p-4 tw-rounded-xl tw-border tw-border-border tw-bg-background">
      <span className="tw-text-[11px] tw-font-medium tw-text-foreground-subtle tw-uppercase tw-tracking-wider">
        {label}
      </span>
      {loading ? (
        <div className="tw-flex tw-items-center tw-gap-2 tw-text-foreground-subtle">
          <Spinner style={{ width: 16, height: 16 }} />
        </div>
      ) : (
        <div className="tw-text-sm tw-font-semibold tw-text-foreground">
          {children}
        </div>
      )}
    </div>
  );
}

// ===========================
// Tabs config
// ===========================
const TABS = [
  { id: "endpoints", label: "Endpoints", icon: "admin-plugins" },
  { id: "auth", label: __("Autenticación", "wp-api-creator"), icon: "lock" },
  {
    id: "settings",
    label: __("Ajustes", "wp-api-creator"),
    icon: "admin-settings",
  },
  { id: "docs", label: __("Docs", "wp-api-creator"), icon: "editor-help" },
  { id: "logs", label: "Logs", icon: "list-view" },
];

/**
 * Lee el hash de la URL y devuelve el ID de tab válido
 */
function getTabFromHash() {
  const hash = window.location.hash.replace("#", "");
  const valid = TABS.find(function (t) {
    return t.id === hash;
  });
  return valid ? valid.id : "endpoints";
}

// ===========================
// App Component
// ===========================
function App() {
  const [status, setStatus] = useState("loading");
  const [sidebarInfo, setSidebarInfo] = useState(null);
  const [settings, setSettings] = useState({});
  const [activeTab, setActiveTab] = useState(getTabFromHash);

  // --- Cargar info del sidebar ---
  const loadSidebarInfo = useCallback(function () {
    setStatus("loading");

    // Cargar settings (namespace) y rutas globales en paralelo
    Promise.all([
      apiFetch({ path: "/creator/v1/admin/settings" }),
      apiFetch({ path: "/creator/v1/admin/all-routes" }),
    ])
      .then(function (results) {
        const settingsRes = results[0];
        const routesRes = results[1];
        const settings =
          settingsRes && settingsRes.data ? settingsRes.data : {};
        const routes = routesRes && routesRes.data ? routesRes.data : [];

        const activeRoutes = routes.filter(function (r) {
          return r.visible;
        }).length;
        const totalRoutes = routes.length;

        setSettings(settings); // Guardar ajustes en el estado
        setSidebarInfo({
          namespace: settings.api_namespace || "creator/v1",
          activeRoutes: activeRoutes,
          totalRoutes: totalRoutes,
        });
        setStatus("ready");
      })
      .catch(function () {
        setStatus("error");
      });
  }, []);

  useEffect(() => {
    loadSidebarInfo();
  }, [loadSidebarInfo]);

  // --- Sincronizar tab con hash de la URL ---
  const navigateTab = useCallback(function (tabId) {
    window.location.hash = tabId;
    setActiveTab(tabId);
  }, []);

  // Escuchar cambios de hash (back/forward del navegador)
  useEffect(function () {
    const onHashChange = function () {
      setActiveTab(getTabFromHash());
    };
    window.addEventListener("hashchange", onHashChange);
    return function () {
      window.removeEventListener("hashchange", onHashChange);
    };
  }, []);

  // --- Callback cuando se guardan ajustes (para refrescar sidebar) ---
  const handleSettingsSaved = useCallback(
    function () {
      loadSidebarInfo();
    },
    [loadSidebarInfo],
  );

  const handleUpdateSettings = useCallback(
    async (newSettings) => {
      // El error se propaga a quien llama: tragárselo aquí hacía que las vistas
      // confirmasen como guardado un cambio que el servidor había rechazado.
      const response = await apiFetch({
        path: "/creator/v1/admin/settings",
        method: "POST",
        data: { settings: newSettings },
      });

      if (!response.success) {
        throw new Error(
          response.message ||
            __("El servidor no pudo guardar la configuración.", "wp-api-creator"),
        );
      }

      // Se adopta la respuesta saneada del servidor, no el payload enviado.
      setSettings(response.data || newSettings);
      loadSidebarInfo();

      return response;
    },
    [loadSidebarInfo],
  );

  function renderView() {
    switch (activeTab) {
      case "endpoints":
        return <EndpointManager onSaved={handleSettingsSaved} />;
      case "auth":
        return (
          <AuthManager
            settings={settings}
            onUpdateSettings={handleUpdateSettings}
          />
        );
      case "settings":
        return <Settings onSaved={handleSettingsSaved} />;
      case "docs":
        return <ApiDocs />;
      case "logs":
        return <Logs />;
      default:
        return null;
    }
  }

  return (
    <div className="tw-w-full tw-min-h-screen tw-bg-background apig-animate">
      <div className="tw-flex tw-gap-0">
        {/* ---- Sidebar Navigation ---- */}
        <aside className="tw-w-64 tw-h-screen tw-sticky tw-top-0 tw-bg-sidebar-bg tw-border-r tw-border-border tw-flex tw-flex-col">
          {/* Header del Sidebar */}
          <div className="tw-p-6 tw-flex tw-items-center tw-gap-3">
            <div className="tw-w-9 tw-h-9 tw-rounded-xl tw-bg-primary tw-flex tw-items-center tw-justify-center tw-text-primary-foreground tw-shadow-sm">
              <span
                className="dashicons dashicons-rest-api"
                style={{ fontSize: "20px", width: "20px", height: "20px" }}
              ></span>
            </div>
            <div>
              <h1 className="tw-text-base tw-font-bold tw-text-foreground tw-m-0 tw-leading-none">
                API Creator
              </h1>
              <span className="tw-text-xs tw-text-muted-foreground tw-font-medium">
                v{window.wpApiCreatorData?.version || "—"}
              </span>
            </div>
          </div>

          <div className="tw-flex-1 tw-flex tw-flex-col tw-gap-6 tw-px-3 tw-py-4">
            {/* Menu Sections */}
            <nav className="tw-flex tw-flex-col tw-gap-1.5">
              {TABS.map((tab) => (
                <button
                  key={tab.id}
                  onClick={() => navigateTab(tab.id)}
                  className={`tw-flex tw-items-center tw-gap-3 tw-px-4 tw-py-2.5 tw-text-sm tw-font-medium tw-transition-all tw-duration-200 tw-cursor-pointer tw-border-0 tw-rounded-lg ${
                    activeTab === tab.id
                      ? "tw-bg-foreground tw-text-background"
                      : "tw-bg-transparent tw-text-muted-foreground hover:tw-bg-muted hover:tw-text-foreground"
                  }`}
                  style={{ outline: "none" }}
                >
                  <span
                    className={`dashicons dashicons-${tab.icon} ${
                      activeTab === tab.id
                        ? "tw-text-background"
                        : "tw-opacity-50"
                    }`}
                    style={{ fontSize: "16px", width: "16px", height: "16px" }}
                  ></span>
                  {tab.label}
                </button>
              ))}
            </nav>

            <div className="tw-mt-auto">
              {/* Overview / Stats Card */}
              <div className="tw-p-4 tw-rounded-xl tw-border tw-border-border tw-bg-background tw-shadow-md">
                <div className="tw-flex tw-items-center tw-justify-between tw-mb-4">
                  <span className="tw-text-xs tw-font-bold tw-text-foreground tw-uppercase tw-tracking-widest">
                    {__("Estado", "wp-api-creator")}
                  </span>
                  {status === "loading" ? (
                    <Spinner style={{ width: 12, height: 12, margin: 0 }} />
                  ) : (
                    <div className="tw-flex tw-items-center tw-gap-2">
                      <div
                        className={`tw-w-2 tw-h-2 tw-rounded-full ${
                          status === "ready"
                            ? "tw-bg-success"
                            : "tw-bg-destructive"
                        }`}
                      />
                      <span className="tw-text-xs tw-font-medium tw-text-muted-foreground">
                        {status === "ready" ? "En línea" : "Error"}
                      </span>
                    </div>
                  )}
                </div>

                <div className="tw-space-y-4">
                  {/* Namespace Stat */}
                  <div>
                    <span className="tw-block tw-text-xs tw-font-medium tw-text-muted-foreground tw-mb-2">
                      {__("Namespace", "wp-api-creator")}
                    </span>
                    <div className="tw-px-3 tw-py-2 tw-bg-muted tw-rounded-lg tw-text-xs tw-font-mono tw-text-foreground tw-truncate tw-border tw-border-border/50">
                      /{sidebarInfo?.namespace || "creator/v1"}
                    </div>
                  </div>

                  {/* Endpoints Stat */}
                  <div className="tw-flex tw-items-center tw-justify-between">
                    <span className="tw-text-xs tw-font-medium tw-text-muted-foreground">
                      {__("Endpoints", "wp-api-creator")}
                    </span>
                    <div className="tw-flex tw-items-baseline tw-gap-1">
                      <span className="tw-text-sm tw-font-bold tw-text-foreground">
                        {sidebarInfo?.activeRoutes || 0}
                      </span>
                      <span className="tw-text-xs tw-text-muted-foreground">
                        / {sidebarInfo?.totalRoutes || 0}
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <div className="tw-mt-6 tw-px-2 tw-flex tw-items-center tw-gap-2">
                <div className="tw-w-1 tw-h-1 tw-rounded-full tw-bg-border"></div>
                <p className="tw-text-xs tw-text-muted-foreground tw-font-medium tw-m-0">
                  © {new Date().getFullYear()} WP API CREATOR
                </p>
              </div>
            </div>
          </div>
        </aside>

        {/* ---- Main Content ---- */}
        <main className="tw-flex-1 tw-min-w-0 tw-min-h-screen tw-bg-background">
          <div
            className="tw-p-10 tw-max-w-7xl tw-mx-auto apig-animate"
            key={activeTab}
          >
            {renderView()}
          </div>
        </main>
      </div>
    </div>
  );
}

export default App;
