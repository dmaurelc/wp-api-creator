<?php

namespace WpApiCreator\Tests\Unit\Schema;

use Brain\Monkey\Functions;
use WpApiCreator\Api\CollectionArgs;
use WpApiCreator\Schema\OpenApiBuilder;
use WpApiCreator\Tests\TestCase;

/**
 * Paridad entre lo que la API acepta y lo que la documentación declara.
 *
 * Es la clase de fallo que arrastraba el proyecto: `_include` documentado sin estar
 * declarado, `search` declarado en la documentación sin llegar al repositorio. Con el
 * esquema derivado de la misma lista que registra la ruta, la divergencia deja de ser
 * posible; este test lo comprueba sin depender de la red ni del estado de un sitio real.
 */
class OpenApiBuilderTest extends TestCase
{
    /** @var string[] */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->registered = [];

        Functions\when('get_object_taxonomies')->alias(function () {
            return $this->registered;
        });
        Functions\when('get_taxonomy')->alias(function ($name) {
            return (object) ['name' => $name, 'public' => true];
        });
        Functions\when('__')->returnArg();
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'snippet',
            'post_type' => 'snippet',
            'exposed_fields' => ['id', 'title'],
        ], $overrides);
    }

    public function test_todo_parametro_declarado_aparece_en_el_esquema_y_al_reves(): void
    {
        $this->registered = ['categoria'];
        $config = $this->config(['exposed_fields' => ['id', 'precio', 'tax:categoria']]);

        $this->assertSame(
            array_keys(CollectionArgs::for_endpoint($config)),
            array_column(OpenApiBuilder::params_for($config), 'name')
        );
    }

    public function test_los_parametros_de_taxonomia_solo_aparecen_en_los_endpoints_que_la_exponen(): void
    {
        $this->registered = ['categoria'];

        $con = array_column(OpenApiBuilder::params_for($this->config(['exposed_fields' => ['id', 'tax:categoria']])), 'name');
        $sin = array_column(OpenApiBuilder::params_for($this->config(['exposed_fields' => ['id']])), 'name');

        $this->assertContains('categoria', $con);
        $this->assertNotContains('categoria', $sin);
    }

    public function test_meta_key_no_se_documenta_si_el_endpoint_no_expone_metas(): void
    {
        $nombres = array_column(OpenApiBuilder::params_for($this->config(['exposed_fields' => ['id', 'title']])), 'name');

        $this->assertNotContains('meta_key', $nombres);
        $this->assertNotContains('meta_value', $nombres);
    }

    public function test_el_traductor_conserva_tipo_default_y_enum(): void
    {
        $params = [];
        foreach (OpenApiBuilder::params_for($this->config()) as $param) {
            $params[$param['name']] = $param;
        }

        $this->assertSame(['type' => 'integer', 'default' => 1], $params['page']['schema']);
        $this->assertSame('publish', $params['status']['schema']['default']);
        $this->assertContains('draft', $params['status']['schema']['enum']);
    }

    public function test_el_traductor_no_filtra_callables_al_esquema(): void
    {
        foreach (OpenApiBuilder::params_for($this->config()) as $param) {
            $this->assertSame(
                [],
                array_intersect(['sanitize_callback', 'validate_callback'], array_keys($param['schema']))
            );
        }
    }

    public function test_cada_parametro_documentado_lleva_su_descripcion(): void
    {
        $this->registered = ['categoria'];
        $params = OpenApiBuilder::params_for($this->config(['exposed_fields' => ['id', 'tax:categoria']]));

        foreach ($params as $param) {
            if (in_array($param['name'], ['page', 'limit'], true)) {
                continue; // La paginación se explica sola y su forma no cambió.
            }
            $this->assertNotSame('', $param['description'], "Falta descripción de {$param['name']}");
        }
    }
}
