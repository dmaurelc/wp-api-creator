<?php

namespace WpApiCreator\Tests\Unit\Domain;

use Brain\Monkey\Functions;
use WpApiCreator\Domain\ConfigBuilder;
use WpApiCreator\Domain\ResponseCache;
use WpApiCreator\Tests\Support\WordPressOptionsFake;
use WpApiCreator\Tests\TestCase;

/**
 * Aislamiento e invalidación de la caché de respuestas.
 *
 * Los tests de aislamiento comprueban lo que de verdad puede hacer daño: que una
 * respuesta se sirva a quien no tenía derecho a verla. Se escriben antes de integrar la
 * caché en el controlador, no después.
 */
class ResponseCacheTest extends TestCase
{
    private WordPressOptionsFake $options;

    /** @var string[] Roles del usuario simulado. */
    private array $roles = [];

    /** @var bool */
    private bool $logged_in = false;

    /** @var array<string, string> Marcadores `last_changed` por grupo. */
    private array $last_changed = ['posts' => '1', 'terms' => '1'];

    /** @var array<string, mixed> Object cache simulado. */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->roles = [];
        $this->logged_in = false;
        $this->last_changed = ['posts' => '1', 'terms' => '1'];
        $this->store = [];

        $this->options = WordPressOptionsFake::install();

        Functions\when('get_current_blog_id')->justReturn(1);
        Functions\when('is_user_logged_in')->alias(function () {
            return $this->logged_in;
        });
        Functions\when('wp_get_current_user')->alias(function () {
            return (object) ['roles' => $this->roles];
        });
        Functions\when('wp_cache_get_last_changed')->alias(function ($group) {
            return $this->last_changed[$group] ?? '1';
        });
    }

    /**
     * Sustituye el object cache de solo lectura del fake por uno con memoria.
     */
    private function installObjectCache(): void
    {
        Functions\when('wp_cache_get')->alias(function ($key, $group = '') {
            return $this->store[$group . '|' . $key] ?? false;
        });
        Functions\when('wp_cache_set')->alias(function ($key, $value, $group = '', $ttl = 0) {
            $this->store[$group . '|' . $key] = $value;
            return true;
        });
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'snippet',
            'post_type' => 'snippet',
            'exposed_fields' => ['id', 'title'],
        ], $overrides);
    }

    // === Condiciones de cacheabilidad ===

    public function test_con_tiempo_cero_nada_es_cacheable(): void
    {
        $this->assertFalse(ResponseCache::is_cacheable(['status' => 'publish'], 0));
    }

    public function test_una_coleccion_publicada_es_cacheable(): void
    {
        $this->assertTrue(ResponseCache::is_cacheable(['status' => 'publish'], 300));
    }

    public function test_un_estado_distinto_de_publicado_nunca_se_cachea(): void
    {
        $this->assertFalse(ResponseCache::is_cacheable(['status' => 'draft'], 300));
        $this->assertFalse(ResponseCache::is_cacheable(['status' => 'private'], 300));
    }

    public function test_una_busqueda_nunca_se_cachea(): void
    {
        $this->assertFalse(ResponseCache::is_cacheable(
            ['status' => 'publish', 'search' => 'lo que sea'],
            300
        ));
    }

    /**
     * Los tres parámetros de valor libre permiten a un anónimo generar claves ilimitadas,
     * desalojar las entradas legítimas y convertir la caché en un amplificador.
     *
     * @dataProvider parametrosDeValorLibre
     */
    public function test_un_parametro_de_valor_libre_expulsa_la_peticion_de_la_cache(string $param): void
    {
        $this->assertFalse(ResponseCache::is_cacheable(
            ['status' => 'publish', $param => 'valor-arbitrario'],
            300
        ));
    }

    public function parametrosDeValorLibre(): array
    {
        return [
            'search'     => ['search'],
            'slug'       => ['slug'],
            'meta_value' => ['meta_value'],
        ];
    }

    public function test_los_filtros_acotados_si_se_cachean(): void
    {
        $this->assertTrue(ResponseCache::is_cacheable(
            ['status' => 'publish', 'page' => 2, 'limit' => 10, 'orderby' => 'title', 'categoria' => 'guias'],
            300
        ));
    }

    // === Aislamiento ===

    public function test_dos_roles_distintos_no_comparten_entrada(): void
    {
        $params = ['status' => 'publish'];

        $this->logged_in = true;
        $this->roles = ['editor'];
        $editor = ResponseCache::key($this->config(), $params);

        $this->roles = ['subscriber'];
        $subscriber = ResponseCache::key($this->config(), $params);

        $this->assertNotSame($editor, $subscriber);
    }

    public function test_un_invitado_no_comparte_entrada_con_un_usuario(): void
    {
        $params = ['status' => 'publish'];

        $guest = ResponseCache::key($this->config(), $params);

        $this->logged_in = true;
        $this->roles = ['editor'];

        $this->assertNotSame($guest, ResponseCache::key($this->config(), $params));
    }

    public function test_el_orden_de_los_roles_no_produce_entradas_distintas(): void
    {
        $params = ['status' => 'publish'];
        $this->logged_in = true;

        $this->roles = ['editor', 'shop_manager'];
        $primero = ResponseCache::key($this->config(), $params);

        $this->roles = ['shop_manager', 'editor'];

        $this->assertSame($primero, ResponseCache::key($this->config(), $params));
    }

    public function test_dos_endpoints_no_comparten_entrada(): void
    {
        $params = ['status' => 'publish'];

        $this->assertNotSame(
            ResponseCache::key($this->config(['slug' => 'snippet']), $params),
            ResponseCache::key($this->config(['slug' => 'propiedad']), $params)
        );
    }

    public function test_parametros_distintos_producen_entradas_distintas(): void
    {
        $this->assertNotSame(
            ResponseCache::key($this->config(), ['status' => 'publish', 'page' => 1]),
            ResponseCache::key($this->config(), ['status' => 'publish', 'page' => 2])
        );
    }

    public function test_los_mismos_parametros_en_distinto_orden_comparten_entrada(): void
    {
        $unos = ['page' => 2, 'status' => 'publish'];
        $otros = ['status' => 'publish', 'page' => 2];
        ksort($unos);
        ksort($otros);

        $this->assertSame(
            ResponseCache::key($this->config(), $unos),
            ResponseCache::key($this->config(), $otros)
        );
    }

    // === Invalidación ===

    public function test_publicar_o_editar_una_entrada_invalida_la_clave(): void
    {
        $params = ['status' => 'publish'];
        $antes = ResponseCache::key($this->config(), $params);

        $this->last_changed['posts'] = '2';

        $this->assertNotSame($antes, ResponseCache::key($this->config(), $params));
    }

    public function test_borrar_o_renombrar_un_termino_invalida_la_clave(): void
    {
        $params = ['status' => 'publish'];
        $antes = ResponseCache::key($this->config(), $params);

        $this->last_changed['terms'] = '2';

        $this->assertNotSame($antes, ResponseCache::key($this->config(), $params));
    }

    public function test_editar_solo_metas_invalida_la_clave(): void
    {
        $params = ['status' => 'publish'];
        $antes = ResponseCache::key($this->config(), $params);

        ResponseCache::bump_meta_version();

        $this->assertNotSame($antes, ResponseCache::key($this->config(), $params));
    }

    // === Acotación del invalidador de metadatos ===

    public function test_sin_cache_activa_tocar_metas_no_escribe_nada(): void
    {
        Functions\when('get_post_type')->justReturn('snippet');
        $antes = $this->options->all();

        ResponseCache::bump_meta_version_for_post(7);

        $this->assertSame($antes, $this->options->all());
    }

    public function test_una_meta_de_un_tipo_de_contenido_no_expuesto_no_invalida(): void
    {
        $this->options->set(ConfigBuilder::OPTION_KEY, [
            'settings'  => ['cache_time' => 300],
            'endpoints' => [['slug' => 'snippet', 'post_type' => 'snippet']],
        ]);
        Functions\when('get_post_type')->justReturn('shop_order');

        $antes = ResponseCache::key($this->config(), ['status' => 'publish']);
        ResponseCache::bump_meta_version_for_post(7);

        $this->assertSame($antes, ResponseCache::key($this->config(), ['status' => 'publish']));
    }

    public function test_una_meta_de_un_tipo_de_contenido_expuesto_si_invalida(): void
    {
        $this->options->set(ConfigBuilder::OPTION_KEY, [
            'settings'  => ['cache_time' => 300],
            'endpoints' => [['slug' => 'snippet', 'post_type' => 'snippet']],
        ]);
        Functions\when('get_post_type')->justReturn('snippet');

        $antes = ResponseCache::key($this->config(), ['status' => 'publish']);
        ResponseCache::bump_meta_version_for_post(7);

        $this->assertNotSame($antes, ResponseCache::key($this->config(), ['status' => 'publish']));
    }

    public function test_una_peticion_que_toca_muchas_metas_escribe_la_version_una_sola_vez(): void
    {
        ResponseCache::bump_meta_version();
        $tras_la_primera = ResponseCache::key($this->config(), ['status' => 'publish']);

        ResponseCache::bump_meta_version();
        ResponseCache::bump_meta_version();

        $this->assertSame($tras_la_primera, ResponseCache::key($this->config(), ['status' => 'publish']));
    }

    public function test_guardar_la_configuracion_desde_cualquier_ruta_invalida_la_clave(): void
    {
        $params = ['status' => 'publish'];
        $antes = ResponseCache::key($this->config(), $params);

        ConfigBuilder::save_config(['endpoints' => [['slug' => 'snippet']]]);

        $this->assertNotSame($antes, ResponseCache::key($this->config(), $params));
    }

    public function test_la_purga_manual_invalida_la_clave(): void
    {
        $params = ['status' => 'publish'];
        $antes = ResponseCache::key($this->config(), $params);

        ResponseCache::purge();

        $this->assertNotSame($antes, ResponseCache::key($this->config(), $params));
    }

    public function test_los_contadores_de_invalidacion_no_viven_en_la_configuracion(): void
    {
        ResponseCache::purge();

        $this->assertIsArray($this->options->get(ResponseCache::OPTION_VERSIONS));
        $this->assertArrayNotHasKey(
            'cache_versions',
            (array) $this->options->get(ConfigBuilder::OPTION_KEY, [])
        );
    }

    // === Lectura y escritura ===

    public function test_lo_guardado_se_recupera_con_la_misma_clave(): void
    {
        $this->installObjectCache();
        $payload = ['data' => [['id' => 1]], 'meta' => ['total_items' => 1]];

        $key = ResponseCache::key($this->config(), ['status' => 'publish']);
        ResponseCache::set($key, $payload, 300);

        $this->assertSame($payload, ResponseCache::get($key));
    }

    public function test_una_clave_no_poblada_devuelve_null(): void
    {
        $this->installObjectCache();

        $this->assertNull(ResponseCache::get('inexistente'));
    }

    public function test_con_tiempo_cero_no_se_escribe_nada(): void
    {
        $this->installObjectCache();

        ResponseCache::set('clave', ['data' => []], 0);

        $this->assertNull(ResponseCache::get('clave'));
    }

    // === Ajuste ===

    public function test_el_tiempo_de_vida_sale_de_la_configuracion(): void
    {
        $this->options->set(ConfigBuilder::OPTION_KEY, ['settings' => ['cache_time' => 300]]);

        $this->assertSame(300, ResponseCache::ttl());
    }

    public function test_un_tiempo_negativo_se_trata_como_desactivado(): void
    {
        $this->options->set(ConfigBuilder::OPTION_KEY, ['settings' => ['cache_time' => -5]]);

        $this->assertSame(0, ResponseCache::ttl());
    }
}
