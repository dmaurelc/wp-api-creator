import { useState, useEffect } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import {
  Button,
  TextControl,
  ToggleControl,
  Notice,
  Spinner,
  CheckboxControl,
  Panel,
  PanelBody,
  PanelRow,
} from "@wordpress/components";
import apiFetch from "@wordpress/api-fetch";
import Combobox from "../ui/Combobox";

const SOURCE_LABELS = {
  native: "Core",
  taxonomy: "Taxonomías",
  registered_meta: "Código",
  acf: "ACF",
  jetengine: "JetEngine",
  metabox: "MetaBox",
  flavor_re: "Flavor RE",
};

// Paleta Zinc unificada
const SOURCE_COLORS = {
  native: { bg: "tw-bg-indigo-50/50", border: "tw-border-indigo-100" },
  taxonomy: { bg: "tw-bg-teal-50/50", border: "tw-border-teal-100" },
  registered_meta: {
    bg: "tw-bg-emerald-50/50",
    border: "tw-border-emerald-100",
  },
  acf: { bg: "tw-bg-amber-50/50", border: "tw-border-amber-100" },
  jetengine: { bg: "tw-bg-purple-50/50", border: "tw-border-purple-100" },
  metabox: { bg: "tw-bg-blue-50/50", border: "tw-border-blue-100" },
  flavor_re: { bg: "tw-bg-rose-50/50", border: "tw-border-rose-100" },
};

// Aviso propio de un grupo, mostrado al desplegarlo.
const SOURCE_NOTES = {
  taxonomy:
    "Los términos se devuelven bajo la clave `taxonomies` y cada taxonomía marcada acepta además un parámetro de filtrado con su nombre. Los permisos por campo no se aplican a las taxonomías.",
};

const SOURCE_ORDER = [
  "native",
  "taxonomy",
  "registered_meta",
  "acf",
  "jetengine",
  "metabox",
  "flavor_re",
];

const getFieldSource = (field) => {
  if (field.group === "native") return "native";
  if (field.group === "taxonomy") return "taxonomy";
  return field.source || "database_sample";
};

const isTaxonomyField = (field) => getFieldSource(field) === "taxonomy";

const getGroupedFields = (availableFields) => {
  const groups = {};
  availableFields.forEach(function (field) {
    const source = getFieldSource(field);
    if (!groups[source]) groups[source] = [];
    groups[source].push(field);
  });
  return groups;
};

const isAllCollapsed = (availableFields, collapsedSections) => {
  const grouped = getGroupedFields(availableFields);
  const activeSources = SOURCE_ORDER.filter(function (s) {
    return grouped[s] && grouped[s].length > 0;
  });
  return (
    activeSources.length > 0 &&
    activeSources.every(function (s) {
      return collapsedSections[s];
    })
  );
};

/* ===========================
   Iconos (Estilo Lucide/Shadcn)
   =========================== */
function ChevronIcon({ direction = "down", className = "" }) {
  const rotations = {
    down: "",
    right: "-tw-rotate-90",
    up: "tw-rotate-180",
    left: "tw-rotate-90",
  };
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
      className={`tw-transition-transform tw-duration-200 ${rotations[direction]} ${className}`}
    >
      <polyline points="6 9 12 15 18 9" />
    </svg>
  );
}

function RefreshIcon({ className = "" }) {
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
      <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" />
      <path d="M21 3v5h-5" />
      <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" />
      <path d="M3 21v-5h5" />
    </svg>
  );
}

function CheckIcon({ className = "" }) {
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
      <polyline points="20 6 9 17 4 12" />
    </svg>
  );
}

function EndpointEditor({ endpoint, isGlobal = false, onSave, onCancel }) {
  const [slug, setSlug] = useState(endpoint ? endpoint.slug : "");
  const [postType, setPostType] = useState(endpoint ? endpoint.post_type : "");
  const [description, setDescription] = useState(
    endpoint ? endpoint.description : "",
  );
  const [enabled, setEnabled] = useState(
    endpoint && endpoint.enabled !== undefined ? endpoint.enabled : true,
  );
  const [methods, setMethods] = useState(
    endpoint && endpoint.methods ? endpoint.methods : ["GET"],
  );

  const [permissions, setPermissions] = useState(
    endpoint && endpoint.permissions
      ? endpoint.permissions
      : {
          read: ["public"],
          write: ["editor", "administrator"],
          delete: ["administrator"],
        },
  );

  const [exposedFields, setExposedFields] = useState(() => {
    if (!endpoint || !endpoint.exposed_fields) return [];
    const rawFields = Array.isArray(endpoint.exposed_fields)
      ? endpoint.exposed_fields
      : Object.keys(endpoint.exposed_fields);

    return rawFields.map((f) => {
      if (typeof f === "string") return f;
      if (f && typeof f === "object" && f.key) return String(f.key);
      return String(f);
    });
  });
  const [availableFields, setAvailableFields] = useState([]);
  const [isLoadingFields, setIsLoadingFields] = useState(false);

  // Inicializar cerrados por defecto
  const [collapsedSections, setCollapsedSections] = useState({
    native: true,
    taxonomy: true,
    registered_meta: true,
    acf: true,
    jetengine: true,
    metabox: true,
    flavor_re: true,
  });

  function toggleSection(source) {
    setCollapsedSections((prev) => ({
      ...prev,
      [source]: !prev[source],
    }));
  }

  const [isSlugAuto, setIsSlugAuto] = useState(
    !endpoint || endpoint.slug === endpoint.post_type,
  );
  const [availablePostTypes, setAvailablePostTypes] = useState([]);
  const [isLoadingTypes, setIsLoadingTypes] = useState(true);
  const [availableRoles, setAvailableRoles] = useState([]);
  const [isLoadingRoles, setIsLoadingRoles] = useState(true);
  const [error, setError] = useState(null);
  const [apiNamespace, setApiNamespace] = useState("creator/v1");

  useEffect(function () {
    apiFetch({ path: "/wp/v2/types" })
      .then(function (types) {
        const options = Object.values(types)
          .filter(function (type) {
            return type.viewable !== false;
          })
          .map(function (type) {
            return {
              label: `${type.name} (${type.slug})`,
              value: type.slug,
            };
          });

        setAvailablePostTypes([
          {
            label: __("Selecciona contenido", "wp-api-creator"),
            value: "",
          },
          ...options,
        ]);
      })
      .catch(function (err) {
        console.error("Error obteniendo Post Types", err);
      })
      .finally(function () {
        setIsLoadingTypes(false);
      });

    apiFetch({ path: "/creator/v1/admin/roles" })
      .then(function (res) {
        if (res && res.roles) {
          setAvailableRoles(res.roles);
        }
      })
      .catch(function (err) {
        console.error("Error cargando roles", err);
      })
      .finally(function () {
        setIsLoadingRoles(false);
      });

    apiFetch({ path: "/creator/v1/admin/settings" })
      .then(function (res) {
        // El endpoint responde {success, data:{...}}: leer `res.api_namespace`
        // devolvía siempre undefined y la vista previa de la URL se quedaba en el
        // valor de reserva, mostrando un namespace que no era el configurado.
        if (res && res.data && res.data.api_namespace) {
          setApiNamespace(res.data.api_namespace);
        }
      })
      .catch(function () {});
  }, []);

  function fetchFields(forceRefresh) {
    if (forceRefresh === undefined) forceRefresh = false;
    if (!postType) {
      setAvailableFields([]);
      return;
    }
    setIsLoadingFields(true);
    setError(null);

    const apiPath = `/creator/v1/admin/cpt-fields?cpt=${postType}`;

    function performFetch() {
      apiFetch({ path: apiPath })
        .then((res) => {
          if (res && res.fields) {
            const sanitizedFields = res.fields
              .map((f) => ({
                ...f,
                key: String(f.key || ""),
                label:
                  typeof f.label === "string"
                    ? f.label
                    : String(f.label || f.key || ""),
              }))
              // Los endpoints nativos de WordPress devuelven sus términos con su propio
              // formato: una taxonomía marcada ahí no produciría nada.
              .filter((f) => !isGlobal || !isTaxonomyField(f));

            setAvailableFields(sanitizedFields);

            if (!endpoint && (!exposedFields || exposedFields.length === 0)) {
              // Las taxonomías quedan fuera de la preselección: añaden consultas de
              // términos y un parámetro de filtrado por cada una, así que se activan
              // a conciencia.
              setExposedFields(
                sanitizedFields
                  .filter((f) => !isTaxonomyField(f))
                  .map((f) => f.key),
              );
            }
          }
        })
        .catch((err) => {
          console.error("Error obteniendo campos del CPT", err);
          setError(err.message || "Error al cargar campos.");
        })
        .finally(() => setIsLoadingFields(false));
    }

    if (forceRefresh) {
      apiFetch({
        path: "/creator/v1/admin/clear-cpt-cache",
        method: "POST",
        data: { cpt: postType },
      })
        .then(() => performFetch())
        .catch(() => performFetch());
    } else {
      performFetch();
    }
  }

  useEffect(() => {
    fetchFields();
  }, [postType]);

  function refreshMetadata() {
    fetchFields(true);
  }

  function handleSave() {
    if (!slug || !postType) {
      setError(
        __(
          "El Slug y el Tipo de Contenido (CPT) son obligatorios.",
          "wp-api-creator",
        ),
      );
      return;
    }
    setError(null);

    if (isGlobal) {
      const wpConfig = {
        post_type: postType,
        exposed_fields: exposedFields,
        permissions: permissions,
        description: description,
        enabled: enabled,
      };
      apiFetch({
        path: "/creator/v1/admin/wp-endpoint-config",
        method: "POST",
        data: wpConfig,
      })
        .then(function (res) {
          if (res && res.success) {
            onSave(wpConfig, endpoint ? endpoint.slug : null);
          } else {
            setError(
              (res && res.message) || __("Error al guardar.", "wp-api-creator"),
            );
          }
        })
        .catch(function (err) {
          setError(
            (err && err.message) || __("Error al guardar.", "wp-api-creator"),
          );
        });
      return;
    }

    const newEndpoint = {
      slug: slug,
      post_type: postType,
      description: description,
      enabled: enabled,
      methods: methods,
      show_in_swagger: enabled, // Valor unificado
      permissions: permissions,
      exposed_fields: exposedFields,
    };

    onSave(newEndpoint, endpoint ? endpoint.slug : null);
  }

  function toggleField(key) {
    if (exposedFields.indexOf(key) !== -1) {
      setExposedFields(exposedFields.filter((f) => f !== key));
    } else {
      setExposedFields([...exposedFields, key]);
    }
  }

  function selectAllFields(source) {
    if (source === undefined) source = null;
    const fieldsToSelectArray = source
      ? availableFields
          .filter((f) => getFieldSource(f) === source)
          .map((f) => f.key)
      : availableFields.map((f) => f.key);

    setExposedFields(
      Array.from(new Set([...exposedFields, ...fieldsToSelectArray])),
    );
  }

  function deselectAllFields(source) {
    if (source === undefined) source = null;
    if (!source) {
      setExposedFields([]);
    } else {
      const fieldsToRemove = availableFields
        .filter((f) => getFieldSource(f) === source)
        .map((f) => f.key);
      setExposedFields(
        exposedFields.filter((f) => fieldsToRemove.indexOf(f) === -1),
      );
    }
  }

  function toggleAllSections(collapse) {
    const grouped = getGroupedFields(availableFields);
    const newCollapsed = {};
    SOURCE_ORDER.forEach((source) => {
      if (grouped[source] && grouped[source].length > 0) {
        newCollapsed[source] = collapse;
      }
    });
    setCollapsedSections(newCollapsed);
  }

  const allCollapsedValue = isAllCollapsed(availableFields, collapsedSections);

  return (
    <div className="apig-animate">
      {/* Header */}
      <div className="tw-flex tw-items-center tw-justify-between tw-mb-8">
        <div className="tw-flex tw-items-center tw-gap-4">
          <button
            onClick={onCancel}
            className="tw-flex tw-items-center tw-justify-center tw-w-8 tw-h-8 tw-rounded-full hover:tw-bg-muted tw-text-foreground-muted tw-transition-colors tw-border-0 tw-bg-transparent tw-cursor-pointer"
          >
            <ChevronIcon direction="left" />
          </button>
          <div>
            <h2 className="tw-text-2xl tw-font-bold tw-tracking-tight tw-text-foreground tw-m-0">
              {endpoint
                ? __("Editar Endpoint", "wp-api-creator")
                : __("Nuevo Endpoint", "wp-api-creator")}
            </h2>
            <p className="tw-text-sm tw-text-foreground-muted tw-mt-1.5 tw-mb-0">
              {isGlobal
                ? __(
                    "Configura cómo se expone esta ruta nativa de WordPress.",
                    "wp-api-creator",
                  )
                : __(
                    "Define una nueva ruta personalizada para tu API.",
                    "wp-api-creator",
                  )}
            </p>
          </div>
        </div>
        <div className="tw-flex tw-gap-3">
          <button onClick={onCancel} className="apig-btn apig-btn-secondary">
            {__("Cancelar", "wp-api-creator")}
          </button>
          <button onClick={handleSave} className="apig-btn apig-btn-primary">
            {__("Guardar", "wp-api-creator")}
          </button>
        </div>
      </div>

      {error && (
        <div className="tw-mb-6">
          <Notice status="error" isDismissible={false}>
            {error}
          </Notice>
        </div>
      )}

      <div className="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-8">
        {/* Main Column */}
        <div className="lg:tw-col-span-8 tw-space-y-8">
          {/* Basics Card */}
          <div className="tw-rounded-xl tw-border tw-border-border tw-bg-card tw-shadow-sm">
            <div className="tw-px-6 tw-py-4 tw-border-b tw-border-border">
              <h3 className="tw-text-sm tw-text-foreground tw-m-0">
                {__("Configuración Básica", "wp-api-creator")}
              </h3>
            </div>
            <div className="tw-p-6 tw-space-y-6">
              <div className="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                <div className="tw-space-y-2">
                  <label className="tw-text-sm tw-font-semibold tw-text-foreground tw-flex tw-items-center tw-gap-2">
                    {__("Tipo de Contenido", "wp-api-creator")}
                  </label>
                  <div className="tw-relative">
                    <Combobox
                      options={availablePostTypes}
                      value={postType}
                      onSelect={(val) => {
                        if (isSlugAuto) setSlug(val);
                        setPostType(val);
                      }}
                      disabled={isLoadingTypes || isGlobal}
                      placeholder={__("Selecciona contenido", "wp-api-creator")}
                    />
                  </div>
                </div>

                <div className="tw-space-y-2">
                  <span className="tw-text-sm tw-font-semibold tw-text-foreground">
                    {__("Ruta", "wp-api-creator")}
                  </span>
                  <TextControl
                    value={slug}
                    onChange={(val) => {
                      if (!isGlobal) {
                        setSlug(val.toLowerCase().replace(/[^a-z0-9-_]/g, ""));
                        setIsSlugAuto(false);
                      }
                    }}
                    disabled={isGlobal}
                    className="apig-no-margin"
                    __nextHasNoMarginBottom
                  />
                  <div className="tw-flex tw-items-center tw-gap-1.5 tw-px-1">
                    <span className="tw-text-[10px] tw-font-medium tw-text-foreground-muted">
                      URL:
                    </span>
                    <code className="tw-text-[10px] tw-text-primary tw-font-mono">
                      /wp-json/{apiNamespace}/{slug || "..."}
                    </code>
                  </div>
                </div>
              </div>

              <div className="tw-space-y-4">
                <div className="tw-space-y-2">
                  <span className="tw-text-sm tw-font-semibold tw-text-foreground">
                    {__("Descripción", "wp-api-creator")}
                  </span>
                  <TextControl
                    value={description}
                    onChange={setDescription}
                    placeholder={__(
                      "Describe el propósito de este endpoint...",
                      "wp-api-creator",
                    )}
                    className="apig-no-margin"
                    __nextHasNoMarginBottom
                  />
                </div>

                <div className="tw-space-y-2">
                  <span className="tw-text-sm tw-font-semibold tw-text-foreground">
                    {__("Métodos HTTP", "wp-api-creator")}
                  </span>
                  <div className="tw-flex tw-flex-wrap tw-gap-3 tw-p-3 tw-bg-muted/30 tw-rounded-lg tw-border tw-border-border">
                    {["GET", "POST", "PUT", "DELETE", "PATCH"].map((m) => (
                      <label
                        key={m}
                        className="tw-flex tw-items-center tw-gap-2 tw-cursor-pointer"
                      >
                        <input
                          type="checkbox"
                          className="tw-rounded tw-border-border tw-text-primary focus:tw-ring-primary"
                          checked={methods.includes(m)}
                          onChange={(e) => {
                            if (e.target.checked) {
                              setMethods([...methods, m]);
                            } else {
                              if (methods.length > 1) {
                                setMethods(methods.filter((x) => x !== m));
                              }
                            }
                          }}
                        />
                        <span className="tw-text-xs tw-font-bold tw-uppercase">
                          {m}
                        </span>
                      </label>
                    ))}
                  </div>
                </div>
              </div>

              <div className="tw-flex tw-items-center tw-justify-between tw-rounded-lg tw-border tw-border-border tw-bg-muted/30 tw-p-3">
                <div>
                  <span className="tw-text-sm tw-font-semibold tw-text-foreground tw-block">
                    {__("Estado Endpoint", "wp-api-creator")}
                  </span>
                  <span className="tw-text-xs tw-text-foreground-muted">
                    {enabled
                      ? __("Habilitado (Visible en Swagger)", "wp-api-creator")
                      : __(
                          "Deshabilitado (Oculto en Swagger)",
                          "wp-api-creator",
                        )}
                  </span>
                </div>
                <ToggleControl
                  checked={enabled}
                  onChange={setEnabled}
                  __nextHasNoMarginBottom
                />
              </div>
            </div>
          </div>

          {/* Fields Selector Card */}
          <div className="tw-rounded-xl tw-border tw-border-border tw-bg-card tw-shadow-sm">
            <div className="tw-px-6 tw-py-4 tw-border-b tw-border-border tw-flex tw-items-center tw-justify-between">
              <h3 className="tw-text-sm tw-text-foreground tw-m-0">
                {__("Esquema de Datos", "wp-api-creator")}
              </h3>

              {postType && (
                <div className="tw-flex tw-items-center tw-gap-1 tw-bg-muted tw-p-1 tw-rounded-md tw-border tw-border-border">
                  <button
                    onClick={() => toggleAllSections(!allCollapsedValue)}
                    className="tw-h-6 tw-w-6 tw-flex tw-items-center tw-justify-center tw-rounded tw-text-foreground-muted hover:tw-bg-background tw-bg-transparent tw-border-0 tw-cursor-pointer"
                    title={
                      allCollapsedValue
                        ? __("Expandir todo", "wp-api-creator")
                        : __("Colapsar todo", "wp-api-creator")
                    }
                  >
                    <ChevronIcon
                      direction={allCollapsedValue ? "down" : "up"}
                    />
                  </button>
                  <div className="tw-w-px tw-h-3 tw-bg-border" />
                  <button
                    onClick={refreshMetadata}
                    className={`tw-h-6 tw-w-6 tw-flex tw-items-center tw-justify-center tw-rounded tw-text-foreground-muted hover:tw-bg-background tw-bg-transparent tw-border-0 tw-cursor-pointer ${
                      isLoadingFields ? "tw-animate-spin" : ""
                    }`}
                    title={__("Refrescar campos", "wp-api-creator")}
                  >
                    <RefreshIcon />
                  </button>
                </div>
              )}
            </div>

            <div className="tw-p-6">
              {isLoadingFields ? (
                <div className="tw-flex tw-flex-col tw-items-center tw-justify-center tw-py-20 tw-text-foreground-muted tw-gap-3">
                  <Spinner />
                  <span className="tw-text-sm">
                    {__("Escaneando metadatos...", "wp-api-creator")}
                  </span>
                </div>
              ) : !postType ? (
                <div className="tw-flex tw-flex-col tw-items-center tw-justify-center tw-py-20 tw-text-foreground-muted tw-border tw-border-dashed tw-border-border tw-rounded-lg">
                  <span className="tw-text-sm">
                    {__(
                      "Selecciona un tipo de contenido para ver sus campos.",
                      "wp-api-creator",
                    )}
                  </span>
                </div>
              ) : (
                <div className="tw-space-y-4">
                  {(() => {
                    const grouped = getGroupedFields(availableFields);
                    const orderedSources = SOURCE_ORDER.filter(
                      (s) => grouped[s] && grouped[s].length > 0,
                    );

                    return orderedSources.map((source) => {
                      const fields = grouped[source];
                      const isCollapsed = collapsedSections[source];
                      const selectedInSource = fields.filter((f) =>
                        exposedFields.includes(String(f.key)),
                      ).length;
                      const label = SOURCE_LABELS[source] || source;
                      const sourceStyles = SOURCE_COLORS[source] || {
                        bg: "tw-bg-muted/30",
                        border: "tw-border-border",
                      };

                      return (
                        <div
                          key={source}
                          className={`tw-rounded-lg tw-border tw-overflow-hidden ${sourceStyles.border}`}
                        >
                          {/* Accordion Header */}
                          <div
                            className={`tw-flex tw-items-center tw-justify-between tw-px-4 tw-py-2.5 tw-cursor-pointer hover:tw-brightness-95 tw-transition-all ${
                              sourceStyles.bg
                            } ${
                              !isCollapsed
                                ? "tw-border-b " + sourceStyles.border
                                : ""
                            }`}
                            onClick={() => toggleSection(source)}
                          >
                            <div className="tw-flex tw-items-center tw-gap-3">
                              <ChevronIcon
                                className={
                                  isCollapsed ? "-tw-rotate-90" : "tw-rotate-0"
                                }
                              />
                              <span className="tw-text-xs tw-font-bold tw-text-foreground tw-uppercase tw-tracking-wider">
                                {label}
                              </span>
                              <span
                                className={`tw-text-[10px] tw-font-bold tw-px-1.5 tw-py-px tw-rounded-full ${
                                  selectedInSource > 0
                                    ? "tw-bg-primary/10 tw-text-primary"
                                    : "tw-bg-muted tw-text-foreground-muted"
                                }`}
                              >
                                {selectedInSource} / {fields.length}
                              </span>
                            </div>
                            <div
                              className="tw-flex tw-gap-3"
                              onClick={(e) => e.stopPropagation()}
                            >
                              <button
                                onClick={() => selectAllFields(source)}
                                className="tw-text-[10px] tw-font-bold tw-text-primary hover:tw-underline tw-bg-transparent tw-border-0 tw-p-0 tw-cursor-pointer"
                              >
                                {__("Todos", "wp-api-creator")}
                              </button>
                              <button
                                onClick={() => deselectAllFields(source)}
                                className="tw-text-[10px] tw-font-bold tw-text-destructive hover:tw-underline tw-bg-transparent tw-border-0 tw-p-0 tw-cursor-pointer"
                              >
                                {__("Ninguno", "wp-api-creator")}
                              </button>
                            </div>
                          </div>

                          {/* Accordion Body */}
                          {!isCollapsed && (
                            <div className="tw-p-4 tw-bg-card">
                              {SOURCE_NOTES[source] && (
                                <p className="tw-text-[11px] tw-text-foreground-muted tw-mt-0 tw-mb-3 tw-leading-relaxed">
                                  {SOURCE_NOTES[source]}
                                </p>
                              )}
                              <div className="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-2">
                                {fields.map((field) => (
                                  <div
                                    key={field.key}
                                    className={`tw-group tw-flex tw-items-start tw-gap-3 tw-p-3 tw-rounded-lg tw-border tw-transition-all ${
                                      exposedFields.includes(String(field.key))
                                        ? "tw-bg-primary/[0.02] tw-border-primary/20 tw-shadow-sm"
                                        : "tw-bg-background tw-border-border hover:tw-border-border-hover"
                                    }`}
                                  >
                                    <div className="tw-pt-1">
                                      <CheckboxControl
                                        checked={exposedFields.includes(
                                          String(field.key),
                                        )}
                                        onChange={() =>
                                          toggleField(String(field.key))
                                        }
                                        __nextHasNoMarginBottom
                                      />
                                    </div>
                                    <div
                                      className="tw-flex-1 tw-min-w-0"
                                      onClick={() =>
                                        toggleField(String(field.key))
                                      }
                                      style={{ cursor: "pointer" }}
                                    >
                                      <div className="tw-flex tw-items-center tw-justify-between tw-mb-0.5">
                                        <span className="tw-text-sm tw-font-semibold tw-text-foreground tw-truncate">
                                          {field.label}
                                        </span>
                                        {exposedFields.includes(
                                          String(field.key),
                                        ) && (
                                          <CheckIcon className="tw-text-primary" />
                                        )}
                                      </div>
                                      <code className="tw-text-[10px] tw-text-foreground-muted tw-font-mono tw-block tw-truncate tw-opacity-60">
                                        {field.key}
                                      </code>
                                    </div>
                                  </div>
                                ))}
                              </div>
                            </div>
                          )}
                        </div>
                      );
                    });
                  })()}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Sidebar Column */}
        <div className="lg:tw-col-span-4 tw-space-y-8">
          <div className="tw-rounded-xl tw-border tw-border-border tw-bg-card tw-shadow-sm tw-sticky tw-top-8">
            <div className="tw-px-6 tw-py-4 tw-border-b tw-border-border">
              <h3 className="tw-text-sm tw-text-foreground tw-m-0">
                {__("Permisos de Acceso", "wp-api-creator")}
              </h3>
            </div>
            <div className="tw-p-6">
              {isLoadingRoles ? (
                <div className="tw-flex tw-justify-center tw-py-10">
                  <Spinner />
                </div>
              ) : (
                <div className="tw-space-y-6">
                  {["read", "write", "delete"].map((action) => (
                    <div key={action} className="tw-space-y-3">
                      <div className="tw-flex tw-items-center tw-gap-2">
                        <div className="tw-w-6 tw-h-6 tw-rounded-md tw-bg-muted tw-flex tw-items-center tw-justify-center tw-text-foreground-muted">
                          <span
                            className={`dashicons dashicons-${
                              action === "read"
                                ? "visibility"
                                : action === "write"
                                ? "edit"
                                : "trash"
                            }`}
                            style={{
                              fontSize: "12px",
                              width: "12px",
                              height: "12px",
                            }}
                          ></span>
                        </div>
                        <span className="tw-text-xs tw-font-bold tw-uppercase tw-tracking-wider tw-text-foreground">
                          {action === "read"
                            ? __("Lectura", "wp-api-creator")
                            : action === "write"
                            ? __("Escritura", "wp-api-creator")
                            : __("Borrado", "wp-api-creator")}
                        </span>
                      </div>
                      <div className="tw-grid tw-grid-cols-1 tw-gap-1 tw-pl-8">
                        {availableRoles.map((role) => (
                          <div
                            key={`${action}-${role.val}`}
                            className="tw-flex tw-items-center tw-gap-2 tw-py-1"
                          >
                            <CheckboxControl
                              label={
                                <span className="tw-text-xs tw-font-medium tw-text-foreground-muted">
                                  {role.name}
                                </span>
                              }
                              checked={
                                permissions[action] &&
                                permissions[action].includes(role.val)
                              }
                              onChange={(checked) => {
                                const next = { ...permissions };
                                const currentActions = next[action] || [];
                                if (checked) {
                                  next[action] = Array.from(
                                    new Set([...currentActions, role.val]),
                                  );
                                } else {
                                  next[action] = currentActions.filter(
                                    (r) => r !== role.val,
                                  );
                                }
                                setPermissions(next);
                              }}
                              __nextHasNoMarginBottom
                            />
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

          <div className="tw-rounded-xl tw-border tw-border-orange-500/20 tw-bg-orange-500/[0.02] tw-p-5">
            <div className="tw-flex tw-gap-3">
              <span
                className="dashicons dashicons-warning tw-text-orange-500 tw-mt-1"
                style={{ fontSize: "16px" }}
              ></span>
              <div>
                <h4 className="tw-text-xs tw-font-bold tw-text-orange-950/80 tw-m-0 tw-mb-1">
                  {__("Seguridad API", "wp-api-creator")}
                </h4>
                <p className="tw-text-[11px] tw-text-orange-900/60 tw-m-0 tw-leading-relaxed">
                  {__(
                    "Asegúrate de conceder permisos solo a los roles que realmente necesitan acceder a estos datos.",
                    "wp-api-creator",
                  )}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Footer Actions */}
      <div className="tw-mt-12 tw-pt-8 tw-border-t tw-border-border tw-flex tw-justify-end tw-gap-4">
        <button onClick={onCancel} className="apig-btn apig-btn-secondary">
          {__("Descartar cambios", "wp-api-creator")}
        </button>
        <button
          onClick={handleSave}
          className="apig-btn apig-btn-primary tw-px-10"
        >
          {__("Guardar Cambios", "wp-api-creator")}
        </button>
      </div>
    </div>
  );
}

export default EndpointEditor;
