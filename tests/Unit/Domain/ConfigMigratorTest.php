<?php

namespace WpApiCreator\Tests\Unit\Domain;

use WpApiCreator\Auth\ApiKeyProvider;
use WpApiCreator\Domain\ConfigBuilder;
use WpApiCreator\Domain\ConfigMigrator;
use WpApiCreator\Tests\Support\WordPressOptionsFake;
use WpApiCreator\Tests\TestCase;

/**
 * Backup, consolidacion de API Keys e idempotencia de la rutina de actualizacion.
 */
class ConfigMigratorTest extends TestCase
{
    /** @var WordPressOptionsFake */
    private $options;

    /**
     * Configuracion tipica de un sitio en 1.0.0: keys escritas en la raiz por el
     * dashboard y keys leidas desde `settings` por el validador.
     */
    private function legacy_config(): array
    {
        return [
            'settings' => [
                'api_namespace' => 'mi-api/v1',
                'api_keys'      => [
                    ['id' => 'vieja', 'name' => 'Import antiguo', 'key' => 'ak_aaaa'],
                ],
            ],
            'endpoints' => [['slug' => 'propiedades']],
            'api_keys'  => [
                ['id' => 'dashboard', 'name' => 'Creada en el panel', 'key' => 'ak_bbbb'],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->options = WordPressOptionsFake::install([
            ConfigBuilder::OPTION_KEY => $this->legacy_config(),
        ]);
    }

    public function test_crea_el_backup_integro_antes_de_migrar(): void
    {
        ConfigMigrator::maybe_upgrade('1.1.0');

        $this->assertSame($this->legacy_config(), $this->options->get(ConfigBuilder::OPTION_BACKUP_KEY));
    }

    public function test_el_backup_no_se_sobrescribe_en_ejecuciones_posteriores(): void
    {
        ConfigMigrator::maybe_upgrade('1.1.0');
        $this->options->set(ConfigBuilder::OPTION_KEY, ['settings' => ['api_namespace' => 'otro/v1']]);

        ConfigMigrator::maybe_upgrade('1.2.0');

        $this->assertSame($this->legacy_config(), $this->options->get(ConfigBuilder::OPTION_BACKUP_KEY));
    }

    public function test_consolida_ambas_ubicaciones_en_la_raiz(): void
    {
        ConfigMigrator::maybe_upgrade('1.1.0');

        $config = $this->options->get(ConfigBuilder::OPTION_KEY);

        $this->assertCount(2, $config['api_keys']);
        $this->assertArrayNotHasKey('api_keys', $config['settings']);
        $this->assertSame(['vieja', 'dashboard'], array_column($config['api_keys'], 'id'));
    }

    public function test_marca_como_legacy_y_borra_los_secretos_en_claro(): void
    {
        ConfigMigrator::maybe_upgrade('1.1.0');

        foreach ($this->options->get(ConfigBuilder::OPTION_KEY)['api_keys'] as $entry) {
            $this->assertTrue($entry['legacy']);
            $this->assertArrayNotHasKey('key', $entry);
        }
    }

    public function test_las_keys_migradas_dejan_de_autenticar(): void
    {
        ConfigMigrator::maybe_upgrade('1.1.0');

        $provider = new ApiKeyProvider();

        $this->assertNull($provider->validate_key('ak_aaaa'));
        $this->assertNull($provider->validate_key('ak_bbbb'));
    }

    public function test_ejecutarla_dos_veces_produce_el_mismo_resultado(): void
    {
        ConfigMigrator::maybe_upgrade('1.1.0');
        $after_first = $this->options->get(ConfigBuilder::OPTION_KEY);

        // Se fuerza una segunda pasada simulando una version distinta.
        ConfigMigrator::maybe_upgrade('1.1.1');

        $this->assertSame($after_first, $this->options->get(ConfigBuilder::OPTION_KEY));
    }

    public function test_no_repite_el_trabajo_si_la_version_ya_esta_aplicada(): void
    {
        ConfigMigrator::maybe_upgrade('1.1.0');
        $this->options->set(ConfigBuilder::OPTION_KEY, ['settings' => ['api_namespace' => 'intacto/v1']]);

        ConfigMigrator::maybe_upgrade('1.1.0');

        $this->assertSame(['settings' => ['api_namespace' => 'intacto/v1']], $this->options->get(ConfigBuilder::OPTION_KEY));
    }

    public function test_registra_la_version_aplicada(): void
    {
        ConfigMigrator::maybe_upgrade('1.1.0');

        $this->assertSame('1.1.0', $this->options->get(ConfigMigrator::VERSION_OPTION));
    }

    // === Activacion segura de la cache de respuestas ===

    public function test_actualizar_a_1_2_0_deja_el_tiempo_de_cache_en_cero(): void
    {
        $config = $this->legacy_config();
        $config['settings']['cache_time'] = 300;
        $this->options->set(ConfigBuilder::OPTION_KEY, $config);
        $this->options->set(ConfigMigrator::VERSION_OPTION, '1.1.0');

        ConfigMigrator::maybe_upgrade('1.2.0');

        $stored = $this->options->get(ConfigBuilder::OPTION_KEY);
        $this->assertSame(0, $stored['settings']['cache_time']);
    }

    public function test_una_actualizacion_posterior_no_vuelve_a_apagar_la_cache(): void
    {
        $config = $this->legacy_config();
        $config['settings']['cache_time'] = 300;
        $this->options->set(ConfigBuilder::OPTION_KEY, $config);
        $this->options->set(ConfigMigrator::VERSION_OPTION, '1.2.0');

        ConfigMigrator::maybe_upgrade('1.2.1');

        $stored = $this->options->get(ConfigBuilder::OPTION_KEY);
        $this->assertSame(300, $stored['settings']['cache_time']);
    }

    public function test_un_sitio_que_ya_tenia_la_cache_apagada_no_se_reescribe(): void
    {
        $this->options->set(ConfigMigrator::VERSION_OPTION, '1.1.0');
        $antes = $this->options->get(ConfigBuilder::OPTION_KEY);

        ConfigMigrator::disable_response_cache();

        $this->assertSame($antes, $this->options->get(ConfigBuilder::OPTION_KEY));
    }
}
