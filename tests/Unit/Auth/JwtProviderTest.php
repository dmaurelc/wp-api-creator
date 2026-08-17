<?php

namespace WpApiCreator\Tests\Unit\Auth;

use Brain\Monkey\Functions;
use WP_Error;
use WpApiCreator\Auth\JwtProvider;
use WpApiCreator\Tests\TestCase;

/**
 * Caracterizacion y hardening de la emision y validacion de tokens.
 */
class JwtProviderTest extends TestCase
{
    const SECRET = 'secreto-de-test';
    const SITE_URL = 'https://ejemplo.test';
    const USER_ID = 7;

    /** @var int Version de token que devuelve el meta simulado. */
    private $token_version = 1;

    /** @var bool Si el usuario del token existe. */
    private $user_exists = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token_version = 1;
        $this->user_exists = true;

        Functions\when('get_bloginfo')->justReturn(self::SITE_URL);
        Functions\when('wp_generate_uuid4')->justReturn('11111111-2222-3333-4444-555555555555');

        Functions\when('get_user_meta')->alias(function () {
            return $this->token_version === null ? '' : (string) $this->token_version;
        });
        Functions\when('update_user_meta')->alias(function ($user_id, $key, $value) {
            $this->token_version = (int) $value;
            return true;
        });
        Functions\when('get_userdata')->alias(function () {
            return $this->user_exists ? (object) ['ID' => self::USER_ID] : false;
        });
    }

    private function provider(?string $secret = self::SECRET): JwtProvider
    {
        return new JwtProvider($secret);
    }

    public function test_un_token_recien_emitido_resuelve_su_usuario(): void
    {
        $provider = $this->provider();

        $token = $provider->generate_token(self::USER_ID, 24);

        $this->assertIsString($token);
        $this->assertSame(self::USER_ID, $provider->validate_token($token));
    }

    public function test_una_firma_alterada_se_rechaza(): void
    {
        $provider = $this->provider();
        $token = $provider->generate_token(self::USER_ID);

        $parts = explode('.', $token);
        $parts[2] = strrev($parts[2]);

        $result = $provider->validate_token(implode('.', $parts));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_invalid_signature', $result->get_error_code());
    }

    public function test_un_token_sin_tres_segmentos_se_rechaza(): void
    {
        $result = $this->provider()->validate_token('cabecera.payload');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_invalid_format', $result->get_error_code());
    }

    public function test_un_token_caducado_se_rechaza(): void
    {
        $token = $this->craft_token(['exp' => time() - 10]);

        $result = $this->provider()->validate_token($token);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_expired', $result->get_error_code());
    }

    public function test_un_token_aun_no_activo_se_rechaza(): void
    {
        $token = $this->craft_token(['nbf' => time() + 3600]);

        $result = $this->provider()->validate_token($token);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_not_active', $result->get_error_code());
    }

    public function test_la_expiracion_respeta_las_horas_indicadas(): void
    {
        $before = time();
        $token = $this->provider()->generate_token(self::USER_ID, 2);

        $payload = $this->decode_payload($token);

        $this->assertGreaterThanOrEqual($before + 7200, $payload['exp']);
        $this->assertLessThanOrEqual(time() + 7200, $payload['exp']);
    }

    public function test_un_token_de_otra_instalacion_se_rechaza(): void
    {
        $token = $this->craft_token(['iss' => 'https://otro-dominio.test']);

        $result = $this->provider()->validate_token($token);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_invalid_issuer', $result->get_error_code());
    }

    public function test_un_token_sin_version_se_considera_revocado(): void
    {
        // Forma exacta de los tokens emitidos hasta 1.0.0: `data` sin `tv`.
        $token = $this->craft_token([], ['user_id' => self::USER_ID]);

        $result = $this->provider()->validate_token($token);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_revoked', $result->get_error_code());
    }

    public function test_un_token_con_version_desincronizada_se_considera_revocado(): void
    {
        $token = $this->provider()->generate_token(self::USER_ID);

        $this->token_version = 2; // El administrador revoco los tokens del usuario.

        $result = $this->provider()->validate_token($token);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_revoked', $result->get_error_code());
    }

    public function test_la_ausencia_del_meta_de_version_no_valida_el_token(): void
    {
        $token = $this->provider()->generate_token(self::USER_ID);

        $this->token_version = null; // Un plugin de limpieza borro el usermeta.

        $result = $this->provider()->validate_token($token);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_revoked', $result->get_error_code());
    }

    public function test_un_token_de_un_usuario_borrado_se_rechaza(): void
    {
        $token = $this->provider()->generate_token(self::USER_ID);

        $this->user_exists = false;

        $result = $this->provider()->validate_token($token);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_user_not_found', $result->get_error_code());
    }

    public function test_revocar_incrementa_la_version_de_token(): void
    {
        $provider = $this->provider();

        $this->assertSame(2, $provider->revoke_all_for_user(self::USER_ID));
        $this->assertSame(2, $this->token_version);
    }

    public function test_sin_ninguna_llave_disponible_no_se_firma_nada(): void
    {
        // La cadena vacia representa "ningun secreto resoluble", sin tocar constantes.
        $provider = $this->provider('');

        $result = $provider->generate_token(self::USER_ID);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_secret_unavailable', $result->get_error_code());
    }

    public function test_sin_ninguna_llave_disponible_la_validacion_no_es_fatal(): void
    {
        $result = $this->provider('')->validate_token('a.b.c');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jwt_secret_unavailable', $result->get_error_code());
    }

    /**
     * Construye un token firmado con el secreto de test y el payload indicado.
     *
     * @param array      $overrides Claves del payload a sobrescribir.
     * @param array|null $data      Contenido de `data`; null usa el estandar con `tv`.
     * @return string
     */
    private function craft_token(array $overrides = [], ?array $data = null): string
    {
        $now = time();

        $payload = array_merge([
            'iss' => self::SITE_URL,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 3600,
            'jti' => 'test',
            'data' => $data ?? ['user_id' => self::USER_ID, 'tv' => $this->token_version],
        ], $overrides);

        $header64 = $this->base64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload64 = $this->base64url(json_encode($payload));
        $signature = $this->base64url(hash_hmac('sha256', $header64 . '.' . $payload64, self::SECRET, true));

        return $header64 . '.' . $payload64 . '.' . $signature;
    }

    private function decode_payload(string $token): array
    {
        $parts = explode('.', $token);

        return json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
    }

    private function base64url(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
