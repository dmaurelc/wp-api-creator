<?php

namespace WpApiCreator\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use WP_Error;
use WpApiCreator\Admin\SettingsSanitizer;
use WpApiCreator\Tests\TestCase;

/**
 * Whitelist, fusion y validacion del namespace de los ajustes globales.
 */
class SettingsSanitizerTest extends TestCase
{
    /** @var string[] Namespaces que el servidor REST declara registrados. */
    private $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('__')->returnArg();

        $this->registered = [];
        Functions\when('rest_get_server')->alias(function () {
            return new class ($this->registered) {
                private $namespaces;
                public function __construct(array $namespaces)
                {
                    $this->namespaces = $namespaces;
                }
                public function get_namespaces(): array
                {
                    return $this->namespaces;
                }
            };
        });
    }

    public function test_las_claves_ausentes_conservan_su_valor(): void
    {
        $existing = [
            'api_namespace'       => 'mi-api/v1',
            'jwt_expiration'      => 6,
            'filter_wp_endpoints' => true,
        ];

        // Payload de un formulario que se cargo a medias.
        $result = SettingsSanitizer::sanitize(['cache_time' => 300], $existing);

        $this->assertSame('mi-api/v1', $result['api_namespace']);
        $this->assertSame(6, $result['jwt_expiration']);
        $this->assertTrue($result['filter_wp_endpoints']);
        $this->assertSame(300, $result['cache_time']);
    }

    public function test_las_claves_desconocidas_se_ignoran(): void
    {
        $result = SettingsSanitizer::sanitize(
            ['cache_time' => 60, 'api_keys' => [['id' => 'x']], 'jwt_secret' => 'inyectado'],
            []
        );

        $this->assertArrayNotHasKey('api_keys', $result);
        $this->assertArrayNotHasKey('jwt_secret', $result);
        $this->assertSame(60, $result['cache_time']);
    }

    public function test_el_namespace_de_administracion_se_rechaza(): void
    {
        $result = SettingsSanitizer::sanitize(['api_namespace' => 'creator/v1'], []);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reserved_api_namespace', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function test_el_namespace_de_wordpress_core_se_rechaza(): void
    {
        $result = SettingsSanitizer::sanitize(['api_namespace' => 'wp/v2'], []);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reserved_api_namespace', $result->get_error_code());
    }

    public function test_un_namespace_vacio_se_rechaza(): void
    {
        $result = SettingsSanitizer::sanitize(['api_namespace' => '  '], []);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_api_namespace', $result->get_error_code());
    }

    public function test_un_namespace_con_formato_invalido_se_rechaza(): void
    {
        foreach (['sin-version', 'mi api/v1', 'mi-api/uno', '../../etc'] as $candidate) {
            $result = SettingsSanitizer::sanitize(['api_namespace' => $candidate], []);

            $this->assertInstanceOf(WP_Error::class, $result, $candidate);
            $this->assertSame('invalid_api_namespace', $result->get_error_code(), $candidate);
        }
    }

    public function test_un_namespace_valido_se_normaliza(): void
    {
        $result = SettingsSanitizer::sanitize(['api_namespace' => '/Mi-API/v2/'], []);

        $this->assertSame('mi-api/v2', $result['api_namespace']);
    }

    public function test_un_namespace_de_otro_plugin_se_rechaza(): void
    {
        // Registrar las rutas dentro de `wc/v3` las mezclaría con las de WooCommerce y
        // aplicaría el middleware y el enforcement de este plugin sobre ellas.
        $this->registered = ['wp/v2', 'wc/v3', 'jetpack/v4', 'creator/v1'];

        $result = SettingsSanitizer::sanitize(['api_namespace' => 'wc/v3'], []);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('reserved_api_namespace', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
    }

    public function test_reguardar_el_namespace_vigente_sigue_siendo_posible(): void
    {
        // El propio plugin registra su namespace, así que aparece en la lista del servidor.
        // Sin la exención, la pantalla de ajustes quedaría inutilizable.
        $this->registered = ['wp/v2', 'creator/v1', 'mi-api/v1'];

        $result = SettingsSanitizer::sanitize(
            ['api_namespace' => 'mi-api/v1', 'cache_time' => 300],
            ['api_namespace' => 'mi-api/v1']
        );

        $this->assertSame('mi-api/v1', $result['api_namespace']);
        $this->assertSame(300, $result['cache_time']);
    }

    public function test_un_namespace_libre_se_acepta(): void
    {
        $this->registered = ['wp/v2', 'wc/v3'];

        $result = SettingsSanitizer::sanitize(['api_namespace' => 'mi-api/v2'], ['api_namespace' => 'mi-api/v1']);

        $this->assertSame('mi-api/v2', $result['api_namespace']);
    }

    public function test_sin_servidor_rest_la_lista_estatica_sigue_protegiendo(): void
    {
        Functions\when('rest_get_server')->justReturn(null);

        $this->assertInstanceOf(
            WP_Error::class,
            SettingsSanitizer::sanitize(['api_namespace' => 'creator/v1'], [])
        );
        $this->assertSame(
            'libre/v1',
            SettingsSanitizer::sanitize(['api_namespace' => 'libre/v1'], [])['api_namespace']
        );
    }

    public function test_la_expiracion_jwt_nunca_baja_de_una_hora(): void
    {
        $result = SettingsSanitizer::sanitize(['jwt_expiration' => 0], []);

        $this->assertSame(1, $result['jwt_expiration']);
    }
}
