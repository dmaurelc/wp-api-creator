<?php

namespace WpApiCreator\Tests\Unit\Schema;

use Brain\Monkey\Functions;
use WpApiCreator\Schema\FieldScanner;
use WpApiCreator\Tests\TestCase;

/**
 * Emision de las taxonomias de un CPT dentro del catalogo de campos.
 */
class FieldScannerTest extends TestCase
{
    /** @var object[] Taxonomias registradas para el CPT simulado. */
    private array $taxonomies = [];

    /** @var array Metas que devuelve el escaner de metadatos. */
    private array $metas = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->taxonomies = [];
        $this->metas = [];

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);

        // Un transient poblado corta el escaneo real de MetaScanner: los metadatos no son
        // el objeto de estos tests y su descubrimiento consulta la base de datos.
        Functions\when('get_transient')->alias(function () {
            return $this->metas;
        });

        Functions\when('get_object_taxonomies')->alias(function () {
            return $this->taxonomies;
        });
    }

    private function taxonomy(string $name, string $label, bool $public = true): object
    {
        return (object) [
            'name'         => $name,
            'label'        => $label,
            'public'       => $public,
            'hierarchical' => true,
            'rest_base'    => $name,
        ];
    }

    /**
     * @return array<string, array> Campos indexados por clave.
     */
    private function scan(string $post_type = 'snippet'): array
    {
        $fields = [];
        foreach (FieldScanner::get_available_fields($post_type) as $field) {
            $fields[$field['key']] = $field;
        }
        return $fields;
    }

    public function test_una_taxonomia_publica_se_ofrece_con_clave_cualificada(): void
    {
        $this->taxonomies = [$this->taxonomy('categoria', 'Categorías')];

        $fields = $this->scan();

        $this->assertArrayHasKey('tax:categoria', $fields);
        $this->assertSame('taxonomy', $fields['tax:categoria']['group']);
        $this->assertSame('taxonomy', $fields['tax:categoria']['source']);
        $this->assertSame('categoria', $fields['tax:categoria']['taxonomy']);
        $this->assertSame('Categorías', $fields['tax:categoria']['label']);
    }

    public function test_una_taxonomia_privada_no_se_ofrece(): void
    {
        $this->taxonomies = [$this->taxonomy('interna', 'Interna', false)];

        $this->assertArrayNotHasKey('tax:interna', $this->scan());
    }

    public function test_nav_menu_nunca_se_ofrece(): void
    {
        $this->taxonomies = [$this->taxonomy('nav_menu', 'Menús')];

        $this->assertArrayNotHasKey('tax:nav_menu', $this->scan());
    }

    public function test_una_taxonomia_y_una_meta_homonimas_no_colisionan(): void
    {
        $this->taxonomies = [$this->taxonomy('ubicacion', 'Ubicación')];
        $this->metas = ['ubicacion' => ['key' => 'ubicacion', 'label' => 'Ubicación ACF', 'source' => 'acf']];

        $fields = $this->scan();

        $this->assertSame('acf', $fields['ubicacion']['source']);
        $this->assertSame('taxonomy', $fields['tax:ubicacion']['source']);
    }

    public function test_los_campos_nativos_siguen_emitiendose(): void
    {
        $this->taxonomies = [$this->taxonomy('categoria', 'Categorías')];

        $fields = $this->scan();

        foreach (['id', 'title', 'content', 'featured_media'] as $native) {
            $this->assertArrayHasKey($native, $fields);
            $this->assertSame('native', $fields[$native]['group']);
        }
    }

    public function test_un_cpt_sin_taxonomias_no_emite_ninguna_clave_cualificada(): void
    {
        $keys = array_keys($this->scan());

        $this->assertSame([], array_filter($keys, function ($key) {
            return strpos($key, FieldScanner::TAXONOMY_PREFIX) === 0;
        }));
    }
}
