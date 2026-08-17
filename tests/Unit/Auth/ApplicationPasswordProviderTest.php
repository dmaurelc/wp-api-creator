<?php

namespace WpApiCreator\Tests\Unit\Auth;

use Brain\Monkey\Functions;
use WP_Error;
use WpApiCreator\Auth\ApplicationPasswordProvider;
use WpApiCreator\Tests\TestCase;

/**
 * Validación de credenciales Basic contra Application Passwords.
 *
 * El caso central es la salida temprana de `wp_authenticate_application_password()`:
 * devuelve su primer argumento sin tocarlo (null) en lugar de un WP_Error cuando el sitio
 * no tiene ninguna Application Password creada. Tratar eso como éxito autenticaba a
 * cualquiera que conociese un nombre de usuario.
 */
class ApplicationPasswordProviderTest extends TestCase
{
    /** @var mixed Valor que devuelve el doble de la función de core. */
    private $core_result = null;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_authenticate_application_password')->alias(function () {
            return $this->core_result;
        });
    }

    private function header(string $username, string $password): string
    {
        return 'Basic ' . base64_encode($username . ':' . $password);
    }

    private function validate(string $username = 'admin', string $password = 'cualquier-cosa')
    {
        return (new ApplicationPasswordProvider())->validate_credentials($this->header($username, $password));
    }

    public function test_una_salida_temprana_de_core_no_autentica(): void
    {
        // Estado por defecto de casi cualquier sitio: `WP_Application_Passwords::is_in_use()`
        // es false y core devuelve el `$input_user` recibido, que aquí es null.
        $this->core_result = null;

        $result = $this->validate();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_app_password', $result->get_error_code());
        $this->assertSame(401, $result->get_error_data()['status']);
    }

    public function test_un_wp_error_de_core_no_autentica(): void
    {
        $this->core_result = new WP_Error('incorrect_password', 'mal', ['status' => 401]);

        $this->assertInstanceOf(WP_Error::class, $this->validate());
    }

    public function test_un_valor_inesperado_de_core_no_autentica(): void
    {
        // Un filtro de terceros sobre `application_password_is_api_request` puede alterar
        // el flujo; solo un WP_User acredita credenciales válidas.
        foreach ([false, 0, '', 42, (object) ['ID' => 1]] as $value) {
            $this->core_result = $value;

            $this->assertInstanceOf(WP_Error::class, $this->validate());
        }
    }

    public function test_un_usuario_validado_por_core_autentica(): void
    {
        $this->core_result = new \WP_User(42);

        $this->assertSame(42, $this->validate());
    }

    public function test_se_devuelve_el_id_resuelto_por_core_y_no_el_del_login_enviado(): void
    {
        // Core también acepta el correo como identificador: el usuario resuelto puede no
        // coincidir con una búsqueda propia por `user_login`.
        $this->core_result = new \WP_User(99);

        $this->assertSame(99, $this->validate('persona@ejemplo.test', 'clave'));
    }

    public function test_una_cabecera_que_no_es_basic_se_rechaza(): void
    {
        $result = (new ApplicationPasswordProvider())->validate_credentials('Bearer abc.def.ghi');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_auth_header', $result->get_error_code());
    }

    public function test_unas_credenciales_sin_dos_puntos_se_rechazan(): void
    {
        $result = (new ApplicationPasswordProvider())->validate_credentials('Basic ' . base64_encode('solousuario'));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_credentials_format', $result->get_error_code());
    }
}
