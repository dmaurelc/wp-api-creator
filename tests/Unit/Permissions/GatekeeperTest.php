<?php

namespace WpApiCreator\Tests\Unit\Permissions;

use Brain\Monkey\Functions;
use WP_Error;
use WP_REST_Request;
use WpApiCreator\Permissions\Gatekeeper;
use WpApiCreator\Tests\TestCase;

/**
 * Matriz de autorizacion por rol del Gatekeeper.
 */
class GatekeeperTest extends TestCase
{
    /** @var string[] Roles del usuario simulado. */
    private $roles = [];

    /** @var bool */
    private $logged_in = false;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('is_user_logged_in')->alias(function () {
            return $this->logged_in;
        });
        Functions\when('wp_get_current_user')->alias(function () {
            return (object) ['roles' => $this->roles];
        });
    }

    private function authorize(array $permissions, string $method = 'GET')
    {
        return (new Gatekeeper())->authorize_endpoint(
            new WP_REST_Request($method, '/mi-api/v1/recurso'),
            ['permissions' => $permissions],
            $method
        );
    }

    public function test_un_recurso_publico_no_exige_sesion(): void
    {
        $this->assertTrue($this->authorize(['read' => ['public']]));
    }

    public function test_un_invitado_sin_permiso_recibe_401(): void
    {
        $result = $this->authorize(['read' => ['editor']]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('gatekeeper_unauthorized', $result->get_error_code());
        $this->assertSame(401, $result->get_error_data()['status']);
    }

    public function test_un_usuario_con_rol_insuficiente_recibe_403(): void
    {
        $this->logged_in = true;
        $this->roles = ['subscriber'];

        $result = $this->authorize(['read' => ['editor']]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('gatekeeper_forbidden', $result->get_error_code());
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    public function test_un_usuario_con_el_rol_declarado_pasa(): void
    {
        $this->logged_in = true;
        $this->roles = ['editor'];

        $this->assertTrue($this->authorize(['read' => ['editor']]));
    }

    public function test_un_administrador_siempre_pasa(): void
    {
        $this->logged_in = true;
        $this->roles = ['administrator'];

        $this->assertTrue($this->authorize(['read' => ['editor']]));
    }

    public function test_un_metodo_no_mapeado_recibe_405(): void
    {
        $result = $this->authorize(['read' => ['public']], 'OPTIONS');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('gatekeeper_method_forbidden', $result->get_error_code());
        $this->assertSame(405, $result->get_error_data()['status']);
    }
}
