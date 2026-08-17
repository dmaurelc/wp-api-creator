import { useState, useRef, useEffect } from "@wordpress/element";
import { __ } from "@wordpress/i18n";

export default function Combobox({
  options = [],
  value = "",
  onSelect,
  placeholder = __("Selecciona...", "wp-api-creator"),
  disabled = false,
  className = "",
  // Cuando se pasa `onSearch`, el filtrado deja de hacerse en cliente y las opciones
  // las resuelve el padre contra el servidor. Necesario para listados que no caben en
  // memoria: un sitio con decenas de miles de usuarios no puede cargarse entero.
  onSearch = null,
  isLoading = false,
}) {
  const [isOpen, setIsOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [lastSelected, setLastSelected] = useState(null);
  const containerRef = useRef(null);

  // Con búsqueda en servidor, `options` cambia con cada consulta y la opción elegida puede
  // desaparecer del lote actual. Se recuerda la última selección para que el disparador no
  // vuelva al placeholder y dé a entender que se perdió.
  const matchInOptions = options.find((opt) => opt.value === value);
  const selectedOption =
    matchInOptions || (lastSelected?.value === value ? lastSelected : null);

  useEffect(() => {
    if (matchInOptions) setLastSelected(matchInOptions);
  }, [matchInOptions]);

  const filteredOptions = onSearch
    ? options
    : options.filter((opt) =>
        opt.label.toLowerCase().includes(search.toLowerCase()),
      );

  useEffect(() => {
    function handleClickOutside(event) {
      if (
        containerRef.current &&
        !containerRef.current.contains(event.target)
      ) {
        setIsOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  useEffect(() => {
    if (!isOpen) return;
    setSearch("");
    if (onSearch) onSearch("");
  }, [isOpen]);

  const handleSelect = (val) => {
    const chosen = options.find((opt) => opt.value === val);
    if (chosen) setLastSelected(chosen);
    onSelect(val);
    setIsOpen(false);
  };

  return (
    <div className={`tw-relative tw-w-full ${className}`} ref={containerRef}>
      <button
        type="button"
        onClick={() => !disabled && setIsOpen(!isOpen)}
        disabled={disabled}
        className={`tw-flex tw-h-10 tw-w-full tw-items-center tw-justify-between tw-rounded-md tw-border tw-border-border tw-bg-background tw-px-3 tw-py-2 tw-text-sm tw-ring-offset-background placeholder:tw-text-muted-foreground focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-primary/20 focus:tw-border-primary disabled:tw-cursor-not-allowed disabled:tw-opacity-50 tw-transition-all ${
          isOpen ? "tw-border-primary tw-ring-2 tw-ring-primary/20" : ""
        }`}
      >
        <span className="tw-truncate">
          {selectedOption ? selectedOption.label : placeholder}
        </span>
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
          className={`tw-opacity-50 tw-transition-transform ${
            isOpen ? "tw-rotate-180" : ""
          }`}
        >
          <path d="m6 9 6 6 6-6" />
        </svg>
      </button>

      {isOpen && (
        <div className="tw-absolute tw-z-[100] tw-mt-2 tw-max-h-60 tw-w-full tw-overflow-auto tw-rounded-md tw-border tw-border-border tw-bg-white tw-p-1 tw-text-popover-foreground tw-shadow-lg tw-ring-1 tw-ring-black/5 tw-animate-in tw-fade-in-0 tw-zoom-in-95">
          <div className="tw-flex tw-items-center tw-border-b tw-border-border tw-px-3 tw-pb-2 tw-pt-1 tw-bg-white">
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
              className="tw-mr-2 tw-opacity-50"
            >
              <circle cx="11" cy="11" r="8" />
              <path d="m21 21-4.3-4.3" />
            </svg>
            <input
              autoFocus
              className="tw-flex tw-h-8 tw-w-full tw-rounded-md tw-bg-transparent tw-py-3 tw-text-sm tw-outline-none placeholder:tw-text-muted-foreground disabled:tw-cursor-not-allowed disabled:tw-opacity-50 tw-border-none"
              placeholder={__("Buscar...", "wp-api-creator")}
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                if (onSearch) onSearch(e.target.value);
              }}
            />
          </div>
          <div className="tw-pt-1">
            {isLoading ? (
              <div className="tw-relative tw-flex tw-cursor-default tw-select-none tw-items-center tw-rounded-sm tw-px-2 tw-py-2 tw-text-sm tw-outline-none tw-text-slate-400">
                {__("Buscando...", "wp-api-creator")}
              </div>
            ) : filteredOptions.length === 0 ? (
              <div className="tw-relative tw-flex tw-cursor-default tw-select-none tw-items-center tw-rounded-sm tw-px-2 tw-py-2 tw-text-sm tw-outline-none tw-text-slate-400">
                {__("No se encontraron resultados.", "wp-api-creator")}
              </div>
            ) : (
              filteredOptions.map((opt) => (
                <div
                  key={opt.value}
                  onClick={() => handleSelect(opt.value)}
                  className={`tw-relative tw-flex tw-w-full tw-cursor-pointer tw-select-none tw-items-center tw-rounded-sm tw-px-2 tw-py-1.5 tw-text-sm tw-outline-none hover:tw-bg-slate-100 hover:tw-text-slate-900 tw-transition-colors ${
                    value === opt.value
                      ? "tw-bg-slate-100 tw-text-slate-900"
                      : "tw-text-slate-700"
                  }`}
                >
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
                    className={`tw-mr-2 tw-h-4 tw-w-4 ${
                      value === opt.value ? "tw-opacity-100" : "tw-opacity-0"
                    }`}
                  >
                    <path d="M20 6 9 17 4 12" />
                  </svg>
                  <span className="tw-truncate">{opt.label}</span>
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  );
}
