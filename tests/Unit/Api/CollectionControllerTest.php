<?php

namespace WpApiCreator\Tests\Unit\Api;

use Brain\Monkey\Functions;
use WP_REST_Request;
use WpApiCreator\Api\Controllers\CollectionController;
use WpApiCreator\Api\OutputSerializer;
use WpApiCreator\Domain\ConfigBuilder;
use WpApiCreator\Domain\Repositories\DynamicQueryBuilder;
use WpApiCreator\Tests\Support\WordPressOptionsFake;
use WpApiCreator\Tests\TestCase;

/**
 * Integración del controlador de colección con la caché de respuestas.
 */
class CollectionControllerTest extends TestCase
{
    private WordPressOptionsFake $options;

    /** @var array<string, mixed> Object cache simulado. */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = [];
        $this->options = WordPressOptionsFake::install();

        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_get_current_user')->alias(function () {
            return (object) ['roles' => []];
        });
        Functions\when('wp_cache_get_last_changed')->justReturn('1');
        Functions\when('get_object_taxonomies')->justReturn([]);
        Functions\when('sanitize_text_field')->returnArg();

        Functions\when('wp_cache_get')->alias(function ($key, $group = '') {
            return $this->store[$group . '|' . $key] ?? false;
        });
        Functions\when('wp_cache_set')->alias(function ($key, $value, $group = '', $ttl = 0) {
            $this->store[$group . '|' . $key] = $value;
            return true;
        });
    }

    private function config(): array
    {
        return [
            'slug' => 'snippet',
            'post_type' => 'snippet',
            'exposed_fields' => ['id', 'title'],
        ];
    }

    private function request(array $params = []): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/mi-api/v1/snippet');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return $request;
    }

    /**
     * Repositorio que cuenta cuántas veces se le pidió la colección.
     */
    private function repository(): DynamicQueryBuilder
    {
        return new class extends DynamicQueryBuilder {
            public int $calls = 0;

            public function get_collection(string $post_type, array $args): array
            {
                $this->calls++;
                return [
                    'posts' => [],
                    'meta'  => ['total_items' => 0, 'total_pages' => 0, 'current_page' => 1, 'limit' => 10],
                ];
            }
        };
    }

    private function controller(DynamicQueryBuilder $repository): CollectionController
    {
        $serializer = new OutputSerializer(null, function () {
            return [];
        });

        return new CollectionController($repository, $serializer);
    }

    public function test_sin_cache_cada_peticion_consulta_el_repositorio(): void
    {
        $repository = $this->repository();
        $controller = $this->controller($repository);

        $controller->get_items($this->request(['status' => 'publish']), $this->config());
        $controller->get_items($this->request(['status' => 'publish']), $this->config());

        $this->assertSame(2, $repository->calls);
    }

    public function test_con_cache_la_segunda_peticion_identica_no_repite_el_trabajo(): void
    {
        $this->options->set(ConfigBuilder::OPTION_KEY, ['settings' => ['cache_time' => 300]]);

        $repository = $this->repository();
        $controller = $this->controller($repository);

        $primera = $controller->get_items($this->request(['status' => 'publish']), $this->config());
        $segunda = $controller->get_items($this->request(['status' => 'publish']), $this->config());

        $this->assertSame(1, $repository->calls);
        $this->assertSame($primera->get_data(), $segunda->get_data());
    }

    public function test_con_cache_una_pagina_distinta_no_reutiliza_la_entrada(): void
    {
        $this->options->set(ConfigBuilder::OPTION_KEY, ['settings' => ['cache_time' => 300]]);

        $repository = $this->repository();
        $controller = $this->controller($repository);

        $controller->get_items($this->request(['status' => 'publish', 'page' => 1]), $this->config());
        $controller->get_items($this->request(['status' => 'publish', 'page' => 2]), $this->config());

        $this->assertSame(2, $repository->calls);
    }

    public function test_una_busqueda_nunca_se_sirve_desde_cache(): void
    {
        $this->options->set(ConfigBuilder::OPTION_KEY, ['settings' => ['cache_time' => 300]]);

        $repository = $this->repository();
        $controller = $this->controller($repository);

        $controller->get_items($this->request(['status' => 'publish', 'search' => 'php']), $this->config());
        $controller->get_items($this->request(['status' => 'publish', 'search' => 'php']), $this->config());

        $this->assertSame(2, $repository->calls);
    }

    public function test_un_estado_no_publicado_nunca_se_sirve_desde_cache(): void
    {
        $this->options->set(ConfigBuilder::OPTION_KEY, ['settings' => ['cache_time' => 300]]);

        $repository = $this->repository();
        $controller = $this->controller($repository);

        $controller->get_items($this->request(['status' => 'draft']), $this->config());
        $controller->get_items($this->request(['status' => 'draft']), $this->config());

        $this->assertSame(2, $repository->calls);
    }

    /**
     * Convención estructural: la lectura de parámetros vive en un solo sitio.
     *
     * Si el controlador vuelve a leer directamente de la petición, la clave de caché deja
     * de describir lo que la consulta usa. Es el fallo histórico del plugin y no lo
     * detecta ningún test sobre la clave, porque la clave se construye desde la lista
     * declarada: lo que se rompe es el otro lado.
     */
    public function test_el_controlador_no_lee_parametros_por_su_cuenta(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/src/Api/Controllers/CollectionController.php');

        $this->assertStringNotContainsString('get_param(', $source);
    }
}
