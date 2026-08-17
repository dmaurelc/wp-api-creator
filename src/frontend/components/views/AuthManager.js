import { useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { Notice } from "@wordpress/components";
import JwtPanel from "./auth/JwtPanel";
import ApiKeysPanel from "./auth/ApiKeysPanel";
import AppPasswordsPanel from "./auth/AppPasswordsPanel";

function AuthManager({ settings, onUpdateSettings }) {
  const [activeTab, setActiveTab] = useState("jwt");
  const [message, setMessage] = useState(null);

  const TabButton = ({ id, label, icon }) => (
    <button
      onClick={() => setActiveTab(id)}
      className={`tw-flex tw-items-center tw-gap-2 tw-px-5 tw-py-2.5 tw-rounded-xl tw-text-sm tw-font-semibold tw-transition-all tw-cursor-pointer tw-border-0 ${
        activeTab === id
          ? "tw-bg-primary tw-text-primary-foreground tw-shadow-sm"
          : "tw-bg-muted tw-text-muted-foreground hover:tw-bg-muted/80 hover:tw-text-foreground"
      }`}
    >
      <span
        className={`dashicons dashicons-${icon} tw-text-[16px] tw-w-[16px] tw-h-[16px]`}
      ></span>
      {label}
    </button>
  );

  return (
    <div className="apig-animate tw-space-y-8">
      <div className="tw-flex tw-items-center tw-justify-between">
        <div>
          <h2 className="tw-text-base tw-font-bold tw-text-foreground tw-m-0">
            {__("Autenticación", "wp-api-creator")}
          </h2>
          <p className="tw-text-sm tw-text-foreground-muted tw-mt-1.2 tw-mb-0">
            {__(
              "Configura los métodos de acceso seguro para tu API.",
              "wp-api-creator",
            )}
          </p>
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

      <div className="tw-flex tw-gap-3">
        <TabButton id="jwt" label="JWT" icon="unlock" />
        <TabButton id="apikeys" label="API Keys" icon="admin-network" />
        <TabButton id="apppass" label="App Passwords" icon="smartphone" />
      </div>

      <div className="tw-bg-background tw-rounded-2xl tw-border tw-border-border">
        {activeTab === "jwt" && (
          <JwtPanel
            settings={settings}
            onUpdateSettings={onUpdateSettings}
            onNotify={setMessage}
          />
        )}
        {activeTab === "apikeys" && <ApiKeysPanel />}
        {activeTab === "apppass" && <AppPasswordsPanel />}
      </div>
    </div>
  );
}

export default AuthManager;
