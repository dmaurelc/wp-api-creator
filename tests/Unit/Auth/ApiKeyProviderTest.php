<?php

namespace WpApiCreator\Tests\Unit\Auth;

use Brain\Monkey\Functions;
use WP_REST_Request;
use WpApiCreator\Admin\ApiKeysAdminController;
use WpApiCreator\Auth\ApiKeyProvider;
use WpApiCreator\Domain\ConfigBuilder;
use WpApiCreator\Tests\Support\WordPressOptionsFake;
use WpApiCreator\Tests\TestCase;

/**
 * Validacion de API Keys sobre el almacen real de configuracion.
 *
 * Los tests no mockean la capa de config entre escritura y lectura: hacerlo fue
 * exactamente lo que dejo pasar el desajuste de ubicacion que impedia autenticar.
 */
class ApiKeyProviderTest extends TestCase
{
    /** @var WordPressOptionsFake */
    private $options;

    /** @var int[] IDs de usuario que existen. */
    private $existing_users = [42, 99];

    protected function setUp(): void
    {
        parent::setUp();

        $this->existing_users = [42, 99];
        $this->options = WordPressOptionsFake::install();

        Functions\when('get_userdata')->alias(function ($user_id) {
            return in_array((int) $user_id, $this->existing_users, true)
                ? (object) ['ID' => (int) $user_id, 'display_name' => 'Usuario ' . $user_id, 'roles' => ['editor']]
                : false;
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('current_time')->justReturn('2026-08-17 12:31:00');
        Functions\when('__')->returnArg();
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('is_user_member_of_blog')->justReturn(true);
        Functions\when('get_current_blog_id')->justReturn(1);
    }

    /**
     * Deja una key en el almacen y devuelve su valor en claro.
     */
    private function seed_key(array $overrides = []): string
    {
        $plain = ApiKeyProvider::generate_key();

        $entry = array_merge([
            'id'         => 'key-1',
            'name'       => 'Integracion CRM',
            'hash'       => ApiKeyProvider::hash_key($plain),
            'prefix'     => ApiKeyProvider::prefix_of($plain),
            'user_id'    => 42,
            'expires_at' => null,
            'created_at' => '2026-08-17 12:31:00',
            'legacy'     => false,
        ], $overrides);

        $this->options->set(ConfigBuilder::OPTION_KEY, ['api_keys' => [$entry]]);

        return $plain;
    }

    public function test_una_key_valida_devuelve_su_propietario_y_no_un_administrador(): void
    {
        $plain = $this->seed_key(['user_id' => 99]);

        $this->assertSame(99, (new ApiKeyProvider())->validate_key($plain));
    }

    public function test_una_key_inexistente_no_autentica(): void
    {
        $this->seed_key();

        $this->assertNull((new ApiKeyProvider())->validate_key('ak_no-existe'));
    }

    public function test_una_key_caducada_no_autentica(): void
    {
        $plain = $this->seed_key(['expires_at' => time() - 60]);

        $this->assertNull((new ApiKeyProvider())->validate_key($plain));
    }

    public function test_una_key_legacy_no_autentica(): void
    {
        $plain = $this->seed_key(['legacy' => true]);

        $this->assertNull((new ApiKeyProvider())->validate_key($plain));
    }

    public function test_una_key_de_usuario_borrado_no_autentica(): void
    {
        $plain = $this->seed_key(['user_id' => 1234]);

        $this->assertNull((new ApiKeyProvider())->validate_key($plain));
    }

    public function test_la_master_key_de_desarrollo_ya_no_existe(): void
    {
        $this->seed_key();

        $this->assertNull((new ApiKeyProvider())->validate_key('wp_api_creator_development_master_key'));
    }

    public function test_registrar_el_uso_no_reescribe_la_configuracion(): void
    {
        $plain = $this->seed_key();
        $before = $this->options->get(ConfigBuilder::OPTION_KEY);

        (new ApiKeyProvider())->validate_key($plain);

        $this->assertSame($before, $this->options->get(ConfigBuilder::OPTION_KEY));
        $this->assertIsArray($this->options->get(ConfigBuilder::OPTION_KEY_USAGE));
        $this->assertArrayHasKey('key-1', $this->options->get(ConfigBuilder::OPTION_KEY_USAGE));
    }

    public function test_una_key_creada_desde_el_dashboard_autentica_de_extremo_a_extremo(): void
    {
        $request = new WP_REST_Request('POST', '/creator/v1/admin/api-keys');
        $request->set_param('name', 'Integracion CRM');
        $request->set_param('user_id', 42);

        $response = (new ApiKeysAdminController())->create($request);
        $plain_key = $response->get_data()['plain_key'];

        $this->assertSame(42, (new ApiKeyProvider())->validate_key($plain_key));
    }

    public function test_el_listado_no_expone_ningun_secreto(): void
    {
        $plain = $this->seed_key();

        $keys = (new ApiKeysAdminController())->index(new WP_REST_Request('GET', '/'))->get_data()['keys'];

        $this->assertCount(1, $keys);
        $this->assertArrayNotHasKey('hash', $keys[0]);
        $this->assertArrayNotHasKey('key', $keys[0]);
        $this->assertSame(ApiKeyProvider::prefix_of($plain), $keys[0]['prefix']);
    }

    public function test_crear_una_key_sin_usuario_valido_se_rechaza(): void
    {
        $request = new WP_REST_Request('POST', '/creator/v1/admin/api-keys');
        $request->set_param('name', 'Sin dueno');

        $result = (new ApiKeysAdminController())->create($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_key_user', $result->get_error_code());
    }

    public function test_crear_una_key_que_no_se_persiste_no_devuelve_ningun_secreto(): void
    {
        // Escritura imposible (DB en solo lectura, filtro pre_update_option, wp_options
        // lleno): entregar la clave en claro haría que el integrador desplegase una
        // credencial inexistente y recibiese 401 sin explicación.
        Functions\when('update_option')->justReturn(false);
        Functions\when('add_option')->justReturn(false);

        $request = new WP_REST_Request('POST', '/creator/v1/admin/api-keys');
        $request->set_param('name', 'Integracion CRM');
        $request->set_param('user_id', 42);

        $result = (new ApiKeysAdminController())->create($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('api_key_not_saved', $result->get_error_code());
    }

    public function test_una_revocacion_que_no_se_persiste_no_se_reporta_como_exito(): void
    {
        $this->seed_key();

        // Reportar éxito retiraría la key de la tabla del dashboard mientras sigue
        // autenticando: un fail-open de revocación con confirmación visual falsa.
        Functions\when('update_option')->justReturn(false);
        Functions\when('add_option')->justReturn(false);

        $request = new WP_REST_Request('DELETE', '/');
        $request->set_param('id', 'key-1');

        $result = (new ApiKeysAdminController())->delete($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('api_key_not_revoked', $result->get_error_code());
    }

    public function test_revocar_una_key_inexistente_devuelve_404(): void
    {
        $this->seed_key();

        $request = new WP_REST_Request('DELETE', '/');
        $request->set_param('id', 'no-existe');

        $result = (new ApiKeysAdminController())->delete($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('api_key_not_found', $result->get_error_code());
    }

    public function test_borrar_la_primera_key_deja_una_lista_indexada(): void
    {
        $entries = [];
        foreach (['a', 'b', 'c'] as $id) {
            $entries[] = [
                'id' => $id, 'name' => $id, 'hash' => str_repeat('0', 64),
                'prefix' => 'ak_0000', 'user_id' => 42, 'expires_at' => null, 'legacy' => false,
            ];
        }
        $this->options->set(ConfigBuilder::OPTION_KEY, ['api_keys' => $entries]);

        $request = new WP_REST_Request('DELETE', '/');
        $request->set_param('id', 'a');
        (new ApiKeysAdminController())->delete($request);

        $stored = $this->options->get(ConfigBuilder::OPTION_KEY)['api_keys'];

        $this->assertSame([0, 1], array_keys($stored));
        $this->assertSame('[', substr(json_encode($stored), 0, 1));
    }
}
