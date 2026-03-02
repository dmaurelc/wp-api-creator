<?php

namespace WpApiCreator\Auth;

/**
 * Proveedor de Validación de API Keys de servidor a servidor (o integraciones de confianza).
 */
class ApiKeyProvider
{

    /**
     * Valida si un API Key proveída coincide con las registradas.
     * Por ahora, para la Fase 4 verificaremos contra una option ficticia en la base de datos.
     * En la Fase 6 (Dashboard SPA), el usuario podrá crear, nombrar y revocar varias keys aquí.
     * 
     * @param string $api_key
     * @return int|null Retorna el ID de administrador asignado a esta Key temporalmente, o null si es inválida.
     */
    public function validate_key(string $api_key): ?int {
        // Obtenemos la configuración global que contiene las API Keys generadas
        $settings = \WpApiCreator\Domain\ConfigBuilder::get_global_settings();
        $stored_keys = $settings['api_keys'] ?? [];
        
        // Mock de API Key mágica en modo de desarrollo
        if (defined('WP_DEBUG') && WP_DEBUG && $api_key === 'wp_api_creator_development_master_key') {
            return $this->get_fallback_admin_user();
        }

        foreach ($stored_keys as $key_data) {
            if (isset($key_data['key']) && $key_data['key'] === $api_key) {
                return $this->get_fallback_admin_user();
            }
        }

        return null;
    }

    /**
     * Auxiliar para mapear una API Key global a las capacidades del primer usuario administrador.
     */
    private function get_fallback_admin_user(): ?int {
        $admins = get_users(['role' => 'administrator', 'number' => 1]);
        if (!empty($admins)) {
            return $admins[0]->ID;
        }
        return null; // Caso extremo sin admin (raro).
    }
}
