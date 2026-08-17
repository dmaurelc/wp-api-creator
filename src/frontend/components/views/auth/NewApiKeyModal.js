import { useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { CheckboxControl, Notice } from "@wordpress/components";

/**
 * Muestra la key en claro una unica vez.
 *
 * No se puede cerrar sin confirmar explicitamente: al cerrarse, el valor desaparece del
 * estado de React y no vuelve a estar disponible en ningun sitio.
 */
export default function NewApiKeyModal({ plainKey, keyName, onClose }) {
  const [confirmed, setConfirmed] = useState(false);
  const [copied, setCopied] = useState(false);
  const [copyError, setCopyError] = useState(false);

  // `navigator.clipboard` no existe fuera de un contexto seguro (por ejemplo un staging
  // servido por http). Sin la guarda, el handler lanzaba un TypeError y el botón parecía
  // no responder, aunque la clave sigue visible y seleccionable en pantalla.
  const handleCopy = () => {
    if (!navigator.clipboard?.writeText) {
      setCopyError(true);
      return;
    }
    navigator.clipboard
      .writeText(plainKey)
      .then(() => {
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      })
      .catch(() => setCopyError(true));
  };

  return (
    <div className="tw-fixed tw-inset-0 tw-z-[200] tw-flex tw-items-center tw-justify-center tw-bg-black/50 tw-p-4">
      <div className="tw-w-full tw-max-w-xl tw-bg-white tw-rounded-2xl tw-border tw-border-border tw-shadow-xl tw-p-8 tw-space-y-6">
        <div>
          <h3 className="tw-text-base tw-font-bold tw-text-foreground tw-m-0 tw-mb-2">
            {__("API Key creada", "wp-api-creator")}
          </h3>
          <p className="tw-text-sm tw-text-foreground-muted tw-m-0">
            {keyName}
          </p>
        </div>

        <Notice status="warning" isDismissible={false} className="tw-rounded-xl">
          {__(
            "Cópiala ahora. Por seguridad solo se guarda su hash, así que este valor no volverá a mostrarse.",
            "wp-api-creator",
          )}
        </Notice>

        <div className="tw-flex tw-items-center tw-gap-3">
          <code className="tw-flex-1 tw-block tw-p-4 tw-bg-foreground/5 tw-rounded-xl tw-text-[13px] tw-font-mono tw-break-all">
            {plainKey}
          </code>
          <button
            onClick={handleCopy}
            className="apig-btn apig-btn-secondary tw-shrink-0"
          >
            {copied
              ? __("Copiada", "wp-api-creator")
              : __("Copiar", "wp-api-creator")}
          </button>
        </div>

        {copyError && (
          <Notice status="warning" isDismissible={false} className="tw-rounded-xl">
            {__(
              "El navegador no permite copiar automáticamente en este contexto. Selecciona la clave y cópiala a mano.",
              "wp-api-creator",
            )}
          </Notice>
        )}

        <CheckboxControl
          label={__("Ya la he guardado en un lugar seguro", "wp-api-creator")}
          checked={confirmed}
          onChange={setConfirmed}
        />

        <div className="tw-flex tw-justify-end">
          <button
            onClick={onClose}
            disabled={!confirmed}
            className="apig-btn apig-btn-primary tw-px-8 disabled:tw-opacity-50 disabled:tw-cursor-not-allowed"
          >
            {__("Cerrar", "wp-api-creator")}
          </button>
        </div>
      </div>
    </div>
  );
}
