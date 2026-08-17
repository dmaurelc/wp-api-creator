<?php

namespace WpApiCreator\Tests\Unit\Auth;

use WpApiCreator\Auth\RateLimiter;
use WpApiCreator\Tests\Support\WordPressOptionsFake;
use WpApiCreator\Tests\TestCase;

/**
 * Contadores de intentos fallidos por IP y por cuenta.
 */
class RateLimiterTest extends TestCase
{
    /** @var WordPressOptionsFake */
    private $options;

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = WordPressOptionsFake::install();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        parent::tearDown();
    }

    public function test_bloquea_al_alcanzar_el_umbral(): void
    {
        $key = RateLimiter::ip_key();

        for ($i = 0; $i < RateLimiter::IP_THRESHOLD - 1; $i++) {
            RateLimiter::register_failure($key, RateLimiter::IP_WINDOW);
        }
        $this->assertFalse(RateLimiter::is_blocked($key, RateLimiter::IP_THRESHOLD));

        RateLimiter::register_failure($key, RateLimiter::IP_WINDOW);
        $this->assertTrue(RateLimiter::is_blocked($key, RateLimiter::IP_THRESHOLD));
    }

    public function test_las_dos_politicas_cuentan_por_separado(): void
    {
        $ip_key = RateLimiter::ip_key();
        $user_key = RateLimiter::user_key('editor@ejemplo.test');

        for ($i = 0; $i < RateLimiter::IP_THRESHOLD; $i++) {
            RateLimiter::register_failure($ip_key, RateLimiter::IP_WINDOW);
        }

        $this->assertTrue(RateLimiter::is_blocked($ip_key, RateLimiter::IP_THRESHOLD));
        $this->assertFalse(RateLimiter::is_blocked($user_key, RateLimiter::USER_THRESHOLD));
    }

    public function test_un_exito_limpia_el_contador(): void
    {
        $key = RateLimiter::ip_key();
        RateLimiter::register_failure($key, RateLimiter::IP_WINDOW);

        RateLimiter::clear($key);

        $this->assertSame(0, RateLimiter::count($key));
    }

    public function test_el_contador_caduca_al_expirar_la_ventana(): void
    {
        $key = RateLimiter::ip_key();

        // Contador cuya ventana ya venció, tal como quedaría almacenado.
        $this->options->set('_transient_' . $key, ['count' => 3, 'expires' => time() - 1]);

        $this->assertSame(0, RateLimiter::count($key));
        $this->assertFalse(RateLimiter::is_blocked($key, RateLimiter::IP_THRESHOLD));
    }

    public function test_los_fallos_sucesivos_no_desplazan_la_ventana(): void
    {
        $key = RateLimiter::ip_key();

        RateLimiter::register_failure($key, 60);
        $primer_plazo = RateLimiter::retry_after($key, 60);

        // Un cliente que reintenta en bucle no debe poder prolongar su propio bloqueo
        // — ni el de la víctima — de forma indefinida.
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::register_failure($key, 3600);
        }

        $this->assertLessThanOrEqual($primer_plazo, RateLimiter::retry_after($key, 60));
        $this->assertSame(6, RateLimiter::count($key));
    }

    public function test_los_fallos_de_api_key_no_contaminan_el_contador_de_login(): void
    {
        $api_bucket = RateLimiter::api_key_bucket();

        for ($i = 0; $i < RateLimiter::API_KEY_THRESHOLD; $i++) {
            RateLimiter::register_failure($api_bucket, RateLimiter::API_KEY_WINDOW);
        }

        $this->assertTrue(RateLimiter::is_blocked($api_bucket, RateLimiter::API_KEY_THRESHOLD));
        // Una integración s2s con la key caducada no puede dejar sin login a su IP.
        $this->assertSame(0, RateLimiter::count(RateLimiter::ip_key()));
        $this->assertFalse(RateLimiter::is_blocked(RateLimiter::ip_key(), RateLimiter::IP_THRESHOLD));
    }

    public function test_register_login_failure_alimenta_ambas_politicas(): void
    {
        RateLimiter::register_login_failure('editor');

        $this->assertSame(1, RateLimiter::count(RateLimiter::ip_key()));
        $this->assertSame(1, RateLimiter::count(RateLimiter::user_key('editor')));
    }

    public function test_register_login_failure_sin_usuario_solo_cuenta_la_ip(): void
    {
        RateLimiter::register_login_failure(null);

        $this->assertSame(1, RateLimiter::count(RateLimiter::ip_key()));
    }

    public function test_retry_after_devuelve_los_segundos_restantes(): void
    {
        $key = RateLimiter::ip_key();
        RateLimiter::register_failure($key, 600);

        $retry = RateLimiter::retry_after($key, RateLimiter::IP_WINDOW);

        $this->assertGreaterThan(0, $retry);
        $this->assertLessThanOrEqual(600, $retry);
    }

    public function test_la_clave_por_usuario_ignora_mayusculas(): void
    {
        $this->assertSame(
            RateLimiter::user_key('Editor'),
            RateLimiter::user_key('editor')
        );
    }

    public function test_x_forwarded_for_se_ignora_sin_proxies_declarados(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

        $this->assertSame('203.0.113.7', RateLimiter::resolve_ip());
    }

    public function test_un_origen_sin_ip_no_comparte_bucket_generico(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $_SERVER['HTTP_USER_AGENT'] = 'cliente-a';
        $first = RateLimiter::resolve_ip();

        $_SERVER['HTTP_USER_AGENT'] = 'cliente-b';
        $second = RateLimiter::resolve_ip();

        $this->assertNotSame($first, $second);
        unset($_SERVER['HTTP_USER_AGENT']);
    }
}
