<?php

namespace WpApiCreator\Tests\Unit\Domain;

use Brain\Monkey\Functions;
use WP_Query;
use WpApiCreator\Domain\Repositories\DynamicQueryBuilder;
use WpApiCreator\Tests\TestCase;

/**
 * Traducción de parámetros HTTP a argumentos de WP_Query.
 *
 * Se afirma sobre los argumentos capturados por el doble espía de WP_Query: la consulta
 * real necesitaría una base de datos, pero lo que estas fases arreglan es precisamente
 * qué llega a construirse.
 */
class DynamicQueryBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('absint')->alias(function ($value) {
            return abs((int) $value);
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_title')->alias(function ($value) {
            $value = strtolower((string) $value);
            $value = preg_replace('/[^a-z0-9\-_]/', '-', $value);
            return trim(preg_replace('/-+/', '-', $value), '-');
        });
        Functions\when('is_user_logged_in')->justReturn(false);
    }

    private function query(array $args): array
    {
        (new DynamicQueryBuilder())->get_collection('snippet', $args);
        return WP_Query::last_args();
    }

    public function test_sin_filtros_de_taxonomia_no_se_construye_tax_query(): void
    {
        $this->assertArrayNotHasKey('tax_query', $this->query(['page' => 1, 'limit' => 10]));
    }

    public function test_un_termino_construye_un_filtro_por_slug(): void
    {
        $args = $this->query(['taxonomies' => ['categoria' => 'guias']]);

        $this->assertSame([
            [
                'taxonomy' => 'categoria',
                'field'    => 'slug',
                'terms'    => ['guias'],
                'operator' => 'IN',
            ],
        ], $args['tax_query']);
    }

    public function test_varios_terminos_de_una_taxonomia_son_un_or(): void
    {
        $args = $this->query(['taxonomies' => ['categoria' => 'guias,php']]);

        $this->assertSame(['guias', 'php'], $args['tax_query'][0]['terms']);
        $this->assertSame('IN', $args['tax_query'][0]['operator']);
        $this->assertArrayNotHasKey('relation', $args['tax_query']);
    }

    public function test_dos_taxonomias_se_combinan_con_and(): void
    {
        $args = $this->query([
            'taxonomies' => ['categoria' => 'guias', 'ubicacion' => 'santiago'],
        ]);

        $this->assertSame('AND', $args['tax_query']['relation']);
        $this->assertCount(2, array_filter($args['tax_query'], 'is_array'));
    }

    public function test_los_terminos_se_sanean_antes_de_llegar_a_la_consulta(): void
    {
        $args = $this->query(['taxonomies' => ['categoria' => "guias'; DROP TABLE"]]);

        $this->assertSame(['guias-drop-table'], $args['tax_query'][0]['terms']);
    }

    public function test_una_taxonomia_con_valor_vacio_se_ignora(): void
    {
        $args = $this->query(['taxonomies' => ['categoria' => ' , , ']]);

        $this->assertArrayNotHasKey('tax_query', $args);
    }

    public function test_la_paginacion_y_el_estado_siguen_traduciendose_igual(): void
    {
        $args = $this->query(['page' => 3, 'limit' => 25, 'status' => 'publish']);

        $this->assertSame('snippet', $args['post_type']);
        $this->assertSame('publish', $args['post_status']);
        $this->assertSame(25, $args['posts_per_page']);
        $this->assertSame(3, $args['paged']);
    }

    public function test_el_limite_se_topa_en_cien(): void
    {
        $this->assertSame(100, $this->query(['limit' => 500])['posts_per_page']);
    }

    // === Parámetros que llegaban implementados pero nunca se ejecutaban ===

    public function test_la_busqueda_llega_a_la_consulta(): void
    {
        $this->assertSame('variaciones', $this->query(['search' => 'variaciones'])['s']);
    }

    public function test_una_busqueda_con_comodines_y_comillas_no_rompe_la_consulta(): void
    {
        $args = $this->query(['search' => "100% \"raro\"'"]);

        $this->assertSame("100% \"raro\"'", $args['s']);
    }

    public function test_la_ordenacion_permitida_llega_a_la_consulta(): void
    {
        $args = $this->query(['orderby' => 'title', 'order' => 'asc']);

        $this->assertSame('title', $args['orderby']);
        $this->assertSame('ASC', $args['order']);
    }

    public function test_una_ordenacion_fuera_de_la_lista_blanca_no_llega_a_la_consulta(): void
    {
        $args = $this->query(['orderby' => 'meta_value_num']);

        $this->assertArrayNotHasKey('orderby', $args);
    }

    public function test_rand_no_es_una_ordenacion_permitida(): void
    {
        $this->assertNotContains('rand', DynamicQueryBuilder::ALLOWED_ORDERBY);
        $this->assertArrayNotHasKey('orderby', $this->query(['orderby' => 'rand']));
    }

    public function test_el_filtro_por_meta_llega_a_la_consulta(): void
    {
        $args = $this->query(['meta_key' => 'codigos', 'meta_value' => '999']);

        $this->assertSame([
            ['key' => 'codigos', 'value' => '999', 'compare' => '='],
        ], $args['meta_query']);
    }

    public function test_meta_value_cero_filtra_en_lugar_de_ignorarse(): void
    {
        $args = $this->query(['meta_key' => 'destacado', 'meta_value' => '0']);

        $this->assertSame('0', $args['meta_query'][0]['value']);
    }

    public function test_el_filtro_por_slug_se_traduce_a_name(): void
    {
        $this->assertSame('mi-articulo', $this->query(['slug' => 'Mi Articulo'])['name']);
    }

    public function test_sin_slug_no_se_declara_name(): void
    {
        $this->assertArrayNotHasKey('name', $this->query(['page' => 1]));
    }

    public function test_meta_key_a_solas_no_construye_meta_query(): void
    {
        $this->assertArrayNotHasKey('meta_query', $this->query(['meta_key' => 'codigos']));
    }
}
