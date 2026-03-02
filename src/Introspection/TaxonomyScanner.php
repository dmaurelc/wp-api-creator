<?php

namespace WpApiCreator\Introspection;

/**
 * Escanea y obtiene las taxonomías asociadas a un determinado Custom Post Type.
 */
class TaxonomyScanner
{

    /**
     * Obtiene y estructura las taxonomías ligadas a un CPT.
     * Retorna detalles relevantes (jerárquia, tipo).
     * 
     * @param string $post_type
     * @return array
     */
    public function get_taxonomies_for_post_type(string $post_type): array {
        $taxonomies = get_object_taxonomies($post_type, 'objects');
        $filtered = [];

        foreach ($taxonomies as $tax) {
            // Ignoramos taxs internos o privados de formato WP
            if (!$tax->public || $tax->name === 'nav_menu') {
                continue;
            }

            $filtered[$tax->name] = [
                'name'         => $tax->name,
                'label'        => $tax->label,
                'hierarchical' => $tax->hierarchical, // True para "Categorías", False para "Tags"
                'rest_base'    => $tax->rest_base ?: $tax->name
            ];
        }

        return $filtered;
    }
}
