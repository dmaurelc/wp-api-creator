<?php

namespace WpApiCreator\Introspection;

/**
 * Escanea y obtiene el blueprint de la estrcutura WP
 */
class CptScanner
{

    /**
     * Obtiene todos los Custom Post Types detectados.
     * Retorna estructuras relevantes y filtra nativos innecesarios.
     * 
     * @return array
     */
    public function get_available_post_types(): array {
        // En un entorno de WP se requiere llamar esto tarde (ej. 'wp_loaded')
        // ya que otros plugins pueden registrar sus CPT más tarde que nosotros.
        
        $builtin_to_keep = ['post', 'page']; // Queremos exponer estos nativos si el admin lo desea
        
        $args = [
            'public' => true,
        ];
        
        $post_types = get_post_types($args, 'objects');
        $filtered = [];

        foreach ($post_types as $pt) {
            // Ignorar nativos de media
            if ($pt->name === 'attachment' || $pt->name === 'revision' || $pt->name === 'nav_menu_item') {
                continue;
            }
            // Ignorar los _builtin a menos que esten en la whitelist
            if ($pt->_builtin && !in_array($pt->name, $builtin_to_keep)) {
                continue;
            }

            $filtered[$pt->name] = [
                'name' => $pt->name,
                'label' => $pt->label,
                'description' => $pt->description,
                'rest_base' => $pt->rest_base ?: $pt->name,
                'hierarchical' => $pt->hierarchical
            ];
        }

        return $filtered;
    }
}
