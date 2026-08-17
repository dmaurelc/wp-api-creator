<?php

namespace WpApiCreator\Tests\Unit\Api;

use Brain\Monkey\Functions;
use WpApiCreator\Api\CollectionArgs;
use WpApiCreator\Domain\Repositories\DynamicQueryBuilder;
use WpApiCreator\Tests\TestCase;

/**
 * Fuente canónica de los parámetros de colección.
 */
class CollectionArgsTest extends TestCase
{
    /** @var string[] Taxonomías registradas en el CPT simulado. */
    private array $registered = [];

    /** @var string[] Taxonomías registradas pero ya no públicas. */
    private array $private = [];

    /** @var int Veces que se consultó el registro de taxonomías. */
    private int $registry_reads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registered = [];
        $this->private = [];
        $this->registry_reads = 0;

        Functions\when('get_object_taxonomies')->alias(function () {
            $this->registry_reads++;
            return $this->registered;
        });

        Functions\when('get_taxonomy')->alias(function ($name) {
            return in_array($name, $this->registered, true)
                ? (object) ['name' => $name, 'public' => !in_array($name, $this->private, true)]
                : false;
        });

        Functions\when('sanitize_text_field')->returnArg();
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'snippet',
            'post_type' => 'snippet',
            'exposed_fields' => ['id', 'title'],
        ], $overrides);
    }

    // === Equivalencia con la lista previa a la extracción ===

    public function test_la_paginacion_y_el_estado_se_declaran_igual_que_antes_de_extraer_la_lista(): void
    {
        $args = CollectionArgs::for_endpoint($this->config());

        $this->assertSame([
            'type' => 'integer',
            'default' => 1,
            'sanitize_callback' => 'absint',
        ], $args['page']);

        $this->assertSame([
            'type' => 'integer',
            'default' => 10,
            'sanitize_callback' => 'absint',
        ], $args['limit']);

        $this->assertSame([
            'type' => 'string',
            'default' => 'publish',
            'enum' => DynamicQueryBuilder::ALLOWED_STATUSES,
            'description' => 'Estado de las entradas. Los estados no públicos exigen capacidades sobre el tipo de contenido.',
        ], $args['status']);
    }

    // === Taxonomías expuestas ===

    public function test_solo_devuelve_las_taxonomias_expuestas_y_registradas(): void
    {
        $this->registered = ['categoria', 'etiqueta'];

        $exposed = CollectionArgs::exposed_taxonomies($this->config([
            'exposed_fields' => ['id', 'tax:categoria', 'tax:inexistente'],
        ]));

        $this->assertSame(['categoria'], $exposed);
    }

    public function test_una_taxonomia_registrada_pero_no_expuesta_no_se_devuelve(): void
    {
        $this->registered = ['categoria', 'etiqueta'];

        $exposed = CollectionArgs::exposed_taxonomies($this->config([
            'exposed_fields' => ['id', 'tax:categoria'],
        ]));

        $this->assertNotContains('etiqueta', $exposed);
    }

    public function test_una_meta_homonima_de_una_taxonomia_no_se_confunde_con_ella(): void
    {
        $this->registered = ['ubicacion'];

        $exposed = CollectionArgs::exposed_taxonomies($this->config([
            'exposed_fields' => ['ubicacion'],
        ]));

        $this->assertSame([], $exposed);
    }

    public function test_resolver_las_taxonomias_no_dispara_consultas(): void
    {
        $this->registered = ['categoria'];

        CollectionArgs::exposed_taxonomies($this->config([
            'exposed_fields' => ['tax:categoria'],
        ]));

        // `get_object_taxonomies` es lectura del registro en memoria: una sola llamada y
        // ninguna otra función de acceso a datos. Si alguien reintroduce `FieldScanner`
        // aquí, este test no basta: lo cubre el criterio de que `src/Api` no lo importe.
        $this->assertSame(1, $this->registry_reads);
    }

    // === Declaración de argumentos ===

    public function test_cada_taxonomia_expuesta_declara_su_parametro(): void
    {
        $this->registered = ['categoria'];

        $args = CollectionArgs::for_endpoint($this->config([
            'exposed_fields' => ['id', 'tax:categoria'],
        ]));

        $this->assertArrayHasKey('categoria', $args);
        $this->assertSame('string', $args['categoria']['type']);
        $this->assertSame('sanitize_text_field', $args['categoria']['sanitize_callback']);
    }

    public function test_una_taxonomia_llamada_status_no_pisa_la_validacion_por_enum(): void
    {
        $this->registered = ['status'];

        $args = CollectionArgs::for_endpoint($this->config([
            'exposed_fields' => ['id', 'tax:status'],
        ]));

        $this->assertSame(DynamicQueryBuilder::ALLOWED_STATUSES, $args['status']['enum']);
        $this->assertSame('publish', $args['status']['default']);
    }

    public function test_una_taxonomia_llamada_limit_no_pisa_el_parametro_de_paginacion(): void
    {
        $this->registered = ['limit'];

        $args = CollectionArgs::for_endpoint($this->config([
            'exposed_fields' => ['id', 'tax:limit'],
        ]));

        $this->assertSame('integer', $args['limit']['type']);
    }

    // === Parámetros de búsqueda y ordenación ===

    public function test_la_ordenacion_se_declara_acotada_por_enum(): void
    {
        $args = CollectionArgs::for_endpoint($this->config());

        $this->assertSame(DynamicQueryBuilder::ALLOWED_ORDERBY, $args['orderby']['enum']);
        $this->assertSame('date', $args['orderby']['default']);
        $this->assertSame(['ASC', 'DESC'], $args['order']['enum']);
    }

    public function test_la_busqueda_se_declara_y_se_sanea(): void
    {
        $args = CollectionArgs::for_endpoint($this->config());

        $this->assertSame('sanitize_text_field', $args['search']['sanitize_callback']);
    }

    public function test_el_slug_no_se_sanea_con_sanitize_title_a_pelo(): void
    {
        // WordPress llama al callback con ($value, $request, $param) y `sanitize_title`
        // declara tres parámetros: la petición acabaría de texto de reserva y el contexto
        // dejaría de ser 'save'. Debe ir envuelto.
        $args = CollectionArgs::for_endpoint($this->config());

        $this->assertNotSame('sanitize_title', $args['slug']['sanitize_callback']);
        $this->assertSame([CollectionArgs::class, 'sanitize_slug'], $args['slug']['sanitize_callback']);
    }

    public function test_el_saneado_del_slug_ignora_los_argumentos_extra_de_wordpress(): void
    {
        Functions\when('sanitize_title')->alias(function ($title, $fallback = '', $context = 'save') {
            if ($context !== 'save') {
                return 'CONTEXTO-INCORRECTO';
            }
            return $title === '' ? $fallback : strtolower((string) $title);
        });

        $request = new \WP_REST_Request('GET', '/mi-api/v1/snippet');
        $callback = CollectionArgs::for_endpoint($this->config())['slug']['sanitize_callback'];

        $this->assertSame('mi-articulo', $callback('Mi-Articulo', $request, 'slug'));
        $this->assertSame('', $callback('', $request, 'slug'));
    }

    // === Metas filtrables ===

    public function test_solo_las_metas_expuestas_son_filtrables(): void
    {
        $keys = CollectionArgs::exposed_meta_keys($this->config([
            'exposed_fields' => ['id', 'title', 'precio', 'tax:categoria'],
        ]));

        $this->assertSame(['precio'], $keys);
    }

    public function test_una_meta_interna_nunca_es_filtrable_aunque_este_expuesta(): void
    {
        $keys = CollectionArgs::exposed_meta_keys($this->config([
            'exposed_fields' => ['_edit_last', '_stripe_customer_id', 'precio'],
        ]));

        $this->assertSame(['precio'], $keys);
    }

    public function test_meta_key_se_declara_con_enum_de_las_metas_expuestas(): void
    {
        $args = CollectionArgs::for_endpoint($this->config([
            'exposed_fields' => ['id', 'precio', 'codigos'],
        ]));

        $this->assertSame(['precio', 'codigos'], $args['meta_key']['enum']);
        $this->assertArrayHasKey('meta_value', $args);
    }

    public function test_un_endpoint_sin_metas_expuestas_no_declara_meta_key_ni_meta_value(): void
    {
        $args = CollectionArgs::for_endpoint($this->config([
            'exposed_fields' => ['id', 'title'],
        ]));

        $this->assertArrayNotHasKey('meta_key', $args);
        $this->assertArrayNotHasKey('meta_value', $args);
    }

    // === Pareja meta_key / meta_value ===

    public function test_meta_key_sin_meta_value_es_invalida(): void
    {
        Functions\when('rest_validate_request_arg')->justReturn(true);

        $request = new \WP_REST_Request('GET', '/mi-api/v1/snippet');
        $request->set_param('meta_key', 'precio');

        $result = CollectionArgs::validate_meta_key('precio', $request, 'meta_key');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function test_meta_key_con_meta_value_cero_es_valida(): void
    {
        Functions\when('rest_validate_request_arg')->justReturn(true);

        $request = new \WP_REST_Request('GET', '/mi-api/v1/snippet');
        $request->set_param('meta_key', 'destacado');
        $request->set_param('meta_value', '0');

        $this->assertTrue(CollectionArgs::validate_meta_key('destacado', $request, 'meta_key'));
    }

    // === Recogida de valores ===

    public function test_collect_solo_devuelve_los_parametros_declarados(): void
    {
        $this->registered = ['categoria'];
        $config = $this->config(['exposed_fields' => ['id', 'tax:categoria']]);

        $request = new \WP_REST_Request('GET', '/mi-api/v1/snippet');
        $request->set_param('page', 2);
        $request->set_param('categoria', 'guias');
        $request->set_param('parametro_inventado', 'x');

        $this->assertSame(['categoria' => 'guias', 'page' => 2], CollectionArgs::collect($request, $config));
    }

    public function test_collect_ignora_los_parametros_vacios(): void
    {
        $request = new \WP_REST_Request('GET', '/mi-api/v1/snippet');
        $request->set_param('search', '');

        $this->assertSame([], CollectionArgs::collect($request, $this->config()));
    }

    public function test_collect_devuelve_los_parametros_ordenados_por_nombre(): void
    {
        $request = new \WP_REST_Request('GET', '/mi-api/v1/snippet');
        $request->set_param('status', 'publish');
        $request->set_param('page', 1);
        $request->set_param('limit', 10);

        $this->assertSame(['limit', 'page', 'status'], array_keys(CollectionArgs::collect($request, $this->config())));
    }

    public function test_los_filtros_de_taxonomia_se_agrupan_para_el_repositorio(): void
    {
        $this->registered = ['categoria'];
        $config = $this->config(['exposed_fields' => ['id', 'tax:categoria']]);

        $args = CollectionArgs::to_query_args(
            ['categoria' => 'guias', 'page' => 2, 'status' => 'publish'],
            $config
        );

        $this->assertSame(['categoria' => 'guias'], $args['taxonomies']);
        $this->assertSame(2, $args['page']);
        $this->assertArrayNotHasKey('categoria', array_diff_key($args, ['taxonomies' => null]));
    }

    public function test_una_taxonomia_no_expuesta_no_llega_al_repositorio(): void
    {
        $this->registered = ['categoria', 'oculta'];
        $config = $this->config(['exposed_fields' => ['id', 'tax:categoria']]);

        $args = CollectionArgs::to_query_args(['oculta' => 'secreto'], $config);

        $this->assertSame([], $args['taxonomies']);
    }

    // === Colisión con parámetros reservados, también del lado de la consulta ===

    /**
     * El peligro no es solo que la taxonomía pise el `enum`: es que, sin declararse, su
     * valor por defecto llegue igualmente a `tax_query` y vacíe la colección entera.
     *
     * @dataProvider parametrosConValorPorDefecto
     */
    public function test_una_taxonomia_homonima_de_un_parametro_reservado_no_filtra(string $reservado, $valor_por_defecto): void
    {
        $this->registered = [$reservado];
        $config = $this->config(['exposed_fields' => ['id', 'tax:' . $reservado]]);

        // WordPress rellena los parámetros con `default` aunque el cliente no envíe nada.
        $args = CollectionArgs::to_query_args([$reservado => $valor_por_defecto], $config);

        $this->assertSame([], $args['taxonomies']);
    }

    public function parametrosConValorPorDefecto(): array
    {
        return [
            'status'  => ['status', 'publish'],
            'orderby' => ['orderby', 'date'],
            'order'   => ['order', 'DESC'],
            'page'    => ['page', 1],
            'limit'   => ['limit', 10],
        ];
    }

    public function test_una_taxonomia_homonima_de_slug_no_convierte_el_filtro_en_termino(): void
    {
        $this->registered = ['slug'];
        $config = $this->config(['exposed_fields' => ['id', 'tax:slug']]);

        $args = CollectionArgs::to_query_args(['slug' => 'mi-articulo'], $config);

        $this->assertSame([], $args['taxonomies']);
        $this->assertSame('mi-articulo', $args['slug']);
    }

    public function test_las_taxonomias_filtrables_excluyen_los_nombres_reservados(): void
    {
        $this->registered = ['status', 'categoria'];
        $config = $this->config(['exposed_fields' => ['tax:status', 'tax:categoria']]);

        $this->assertSame(['categoria'], CollectionArgs::filterable_taxonomies($config));
    }

    // === Visibilidad ===

    public function test_una_taxonomia_que_dejo_de_ser_publica_no_es_filtrable(): void
    {
        $this->registered = ['estado-interno'];
        $this->private = ['estado-interno'];

        $exposed = CollectionArgs::exposed_taxonomies($this->config([
            'exposed_fields' => ['tax:estado-interno'],
        ]));

        $this->assertSame([], $exposed);
    }

    public function test_una_taxonomia_que_dejo_de_ser_publica_no_declara_parametro(): void
    {
        $this->registered = ['estado-interno'];
        $this->private = ['estado-interno'];

        $args = CollectionArgs::for_endpoint($this->config([
            'exposed_fields' => ['tax:estado-interno'],
        ]));

        $this->assertArrayNotHasKey('estado-interno', $args);
    }

    public function test_meta_value_sin_meta_key_es_invalido(): void
    {
        Functions\when('rest_validate_request_arg')->justReturn(true);

        $request = new \WP_REST_Request('GET', '/mi-api/v1/snippet');
        $request->set_param('meta_value', '999');

        $result = CollectionArgs::validate_meta_value('999', $request, 'meta_value');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function test_la_pareja_completa_es_valida_por_ambos_lados(): void
    {
        Functions\when('rest_validate_request_arg')->justReturn(true);

        $request = new \WP_REST_Request('GET', '/mi-api/v1/snippet');
        $request->set_param('meta_key', 'precio');
        $request->set_param('meta_value', '999');

        $this->assertTrue(CollectionArgs::validate_meta_key('precio', $request, 'meta_key'));
        $this->assertTrue(CollectionArgs::validate_meta_value('999', $request, 'meta_value'));
    }

    public function test_el_validador_propio_no_anula_la_comprobacion_del_enum(): void
    {
        $rejection = new \WP_Error('rest_not_in_enum', 'No permitido', ['status' => 400]);
        Functions\when('rest_validate_request_arg')->justReturn($rejection);

        $request = new \WP_REST_Request('GET', '/mi-api/v1/snippet');
        $request->set_param('meta_key', '_edit_last');
        $request->set_param('meta_value', '1');

        $this->assertSame($rejection, CollectionArgs::validate_meta_key('_edit_last', $request, 'meta_key'));
    }
}
