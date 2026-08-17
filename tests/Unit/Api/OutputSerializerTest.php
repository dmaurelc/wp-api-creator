<?php

namespace WpApiCreator\Tests\Unit\Api;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use WP_Post;
use WP_Term;
use WpApiCreator\Api\OutputSerializer;
use WpApiCreator\Permissions\Gatekeeper;
use WpApiCreator\Tests\TestCase;

/**
 * Caracterizacion del serializador y emision de taxonomias.
 *
 * Los tests de caracterizacion fijan el comportamiento que ya existia antes de tocar la
 * clase: son la red que detecta que un cambio de forma interna altere la respuesta de un
 * endpoint ya configurado.
 */
class OutputSerializerTest extends TestCase
{
    /** @var array<string, mixed> Metas del post simulado. */
    private array $meta = [];

    /** @var array<string, WP_Term[]|false> Terminos por taxonomia. */
    private array $terms = [];

    /** @var string[] Taxonomias que ya no son publicas. */
    private array $private_taxonomies = [];

    /** @var array Campos que devuelve el escaner inyectado. */
    private array $available_fields = [];

    /** @var int Veces que se ejecuto el filtro `the_content`. */
    private int $content_filter_runs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meta = [];
        $this->terms = [];
        $this->private_taxonomies = [];
        $this->available_fields = [];
        $this->content_filter_runs = 0;

        Functions\when('apply_filters')->alias(function ($tag, $value) {
            if ($tag === 'the_content') {
                $this->content_filter_runs++;
                return '<p>' . $value . '</p>';
            }
            return $value;
        });

        Functions\when('get_post_datetime')->alias(function () {
            return new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        });

        Functions\when('get_post_thumbnail_id')->justReturn(0);
        Functions\when('wp_get_attachment_image_url')->justReturn('');

        Functions\when('get_post_meta')->alias(function ($post_id, $key) {
            return $this->meta[$key] ?? '';
        });

        Functions\when('get_the_terms')->alias(function ($post, $taxonomy) {
            return $this->terms[$taxonomy] ?? false;
        });

        Functions\when('get_taxonomy')->alias(function ($name) {
            return (object) ['name' => $name, 'public' => !in_array($name, $this->private_taxonomies, true)];
        });

        Functions\when('is_user_logged_in')->justReturn(false);
    }

    private function post(array $props = []): WP_Post
    {
        return new WP_Post(array_merge([
            'ID'           => 7,
            'post_title'   => 'Titulo',
            'post_content' => 'Cuerpo',
            'post_excerpt' => 'Extracto',
            'post_name'    => 'titulo',
            'post_status'  => 'publish',
            'post_author'  => 3,
            'post_type'    => 'snippet',
        ], $props));
    }

    private function serializer(): OutputSerializer
    {
        return new OutputSerializer(new Gatekeeper(), function () {
            return $this->available_fields;
        });
    }

    private function serialize(array $config, array $post_props = []): array
    {
        return $this->serializer()->serialize_post($this->post($post_props), $config);
    }

    // === Caracterizacion: comportamiento previo que no puede cambiar ===

    public function test_sin_lista_blanca_devuelve_todos_los_campos_nativos(): void
    {
        $result = $this->serialize([]);

        $this->assertSame(
            ['id', 'title', 'content', 'excerpt', 'slug', 'status', 'author', 'date', 'modified'],
            array_values(array_diff(array_keys($result), ['fields']))
        );
        $this->assertSame(7, $result['id']);
        $this->assertSame('<p>Cuerpo</p>', $result['content']);
        $this->assertSame('2026-01-15T10:00:00+00:00', $result['date']);
    }

    public function test_la_lista_blanca_filtra_los_campos_nativos(): void
    {
        $result = $this->serialize(['exposed_fields' => ['id', 'title']]);

        $this->assertSame(['id', 'title'], array_values(array_diff(array_keys($result), ['fields'])));
        $this->assertArrayNotHasKey('content', $result);
    }

    public function test_featured_media_se_emite_cuando_el_post_tiene_miniatura(): void
    {
        Functions\when('get_post_thumbnail_id')->justReturn(99);
        Functions\when('wp_get_attachment_image_url')->justReturn('https://ejemplo.test/img.jpg');

        $result = $this->serialize(['exposed_fields' => ['id', 'featured_media']]);

        $this->assertSame(
            ['id' => 99, 'url' => 'https://ejemplo.test/img.jpg'],
            $result['featured_media']
        );
    }

    public function test_las_metas_se_agrupan_por_su_fuente(): void
    {
        $this->available_fields = [
            ['key' => 'precio', 'group' => 'meta', 'source' => 'acf'],
            ['key' => 'codigo', 'group' => 'meta', 'source' => 'metabox'],
        ];
        $this->meta = ['precio' => '1000', 'codigo' => 'AB-1'];

        $result = $this->serialize(['exposed_fields' => ['precio', 'codigo']]);

        $this->assertSame(['acf' => ['precio' => '1000'], 'metabox' => ['codigo' => 'AB-1']], $result['fields']);
    }

    public function test_una_meta_con_prefijo_bajo_nunca_se_expone(): void
    {
        $this->available_fields = [['key' => '_secreto', 'group' => 'meta', 'source' => 'meta']];
        $this->meta = ['_secreto' => 'valor'];

        $result = $this->serialize(['exposed_fields' => ['_secreto']]);

        $this->assertSame([], $result['fields']);
    }

    public function test_los_campos_de_muestreo_de_base_de_datos_nunca_se_exponen(): void
    {
        $this->available_fields = [['key' => 'huerfano', 'group' => 'meta', 'source' => 'database_sample']];
        $this->meta = ['huerfano' => 'valor'];

        $result = $this->serialize(['exposed_fields' => ['huerfano']]);

        $this->assertSame([], $result['fields']);
    }

    // === Resolucion perezosa de los campos nativos ===

    public function test_the_content_no_se_ejecuta_si_el_endpoint_no_expone_content(): void
    {
        $this->serialize(['exposed_fields' => ['id', 'title']]);

        $this->assertSame(0, $this->content_filter_runs);
    }

    public function test_the_content_se_ejecuta_una_sola_vez_si_el_endpoint_expone_content(): void
    {
        $this->serialize(['exposed_fields' => ['content']]);

        $this->assertSame(1, $this->content_filter_runs);
    }

    public function test_sin_lista_blanca_the_content_sigue_ejecutandose(): void
    {
        $this->serialize([]);

        $this->assertSame(1, $this->content_filter_runs);
    }

    // === Taxonomias ===

    public function test_una_taxonomia_seleccionada_se_emite_con_id_nombre_y_slug(): void
    {
        $this->terms['categoria'] = [
            new WP_Term(['term_id' => 4, 'name' => 'Guías', 'slug' => 'guias']),
            new WP_Term(['term_id' => 9, 'name' => 'PHP', 'slug' => 'php']),
        ];

        $result = $this->serialize(['exposed_fields' => ['id', 'tax:categoria']]);

        $this->assertSame([
            'categoria' => [
                ['id' => 4, 'name' => 'Guías', 'slug' => 'guias'],
                ['id' => 9, 'name' => 'PHP', 'slug' => 'php'],
            ],
        ], $result['taxonomies']);
    }

    public function test_una_taxonomia_no_aparece_bajo_fields(): void
    {
        $this->terms['categoria'] = [new WP_Term(['term_id' => 4, 'name' => 'Guías', 'slug' => 'guias'])];

        $result = $this->serialize(['exposed_fields' => ['tax:categoria']]);

        $this->assertSame([], $result['fields']);
    }

    public function test_una_meta_homonima_de_una_taxonomia_sigue_emitiendose(): void
    {
        $this->available_fields = [
            ['key' => 'ubicacion', 'group' => 'meta', 'source' => 'acf'],
            ['key' => 'tax:ubicacion', 'group' => 'taxonomy', 'source' => 'taxonomy'],
        ];
        $this->meta = ['ubicacion' => 'Providencia'];
        $this->terms['ubicacion'] = [new WP_Term(['term_id' => 2, 'name' => 'Las Condes', 'slug' => 'las-condes'])];

        $result = $this->serialize(['exposed_fields' => ['ubicacion', 'tax:ubicacion']]);

        $this->assertSame(['acf' => ['ubicacion' => 'Providencia']], $result['fields']);
        $this->assertSame(
            [['id' => 2, 'name' => 'Las Condes', 'slug' => 'las-condes']],
            $result['taxonomies']['ubicacion']
        );
    }

    public function test_un_post_sin_terminos_devuelve_un_array_vacio(): void
    {
        $this->terms['categoria'] = false;

        $result = $this->serialize(['exposed_fields' => ['tax:categoria']]);

        $this->assertSame(['categoria' => []], $result['taxonomies']);
    }

    public function test_un_error_al_leer_terminos_devuelve_un_array_vacio(): void
    {
        $this->terms['categoria'] = new \WP_Error('invalid_taxonomy', 'Taxonomía inválida', ['status' => 400]);

        $result = $this->serialize(['exposed_fields' => ['tax:categoria']]);

        $this->assertSame(['categoria' => []], $result['taxonomies']);
    }

    public function test_una_taxonomia_que_dejo_de_ser_publica_no_emite_sus_terminos(): void
    {
        $this->private_taxonomies = ['estado-interno'];
        $this->terms['estado-interno'] = [new WP_Term(['term_id' => 1, 'name' => 'Reservado', 'slug' => 'reservado'])];

        $result = $this->serialize(['exposed_fields' => ['id', 'tax:estado-interno']]);

        $this->assertArrayNotHasKey('taxonomies', $result);
    }

    public function test_un_endpoint_sin_taxonomias_no_incluye_la_clave(): void
    {
        $result = $this->serialize(['exposed_fields' => ['id', 'title']]);

        $this->assertArrayNotHasKey('taxonomies', $result);
    }

    public function test_un_endpoint_sin_lista_blanca_no_incluye_la_clave(): void
    {
        $result = $this->serialize([]);

        $this->assertArrayNotHasKey('taxonomies', $result);
    }
}
