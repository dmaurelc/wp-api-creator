<?php

namespace WpApiCreator\Tests\Support;

use Brain\Monkey\Functions;
use WpApiCreator\Domain\ConfigBuilder;

/**
 * Almacen de options y transients en memoria.
 *
 * Permite ejercitar el camino real de escritura y lectura de configuracion sin mockear
 * la capa intermedia: un test que mockease ambos lados no habria detectado el desajuste
 * de ubicacion que dejaba las API Keys sin autenticar.
 */
class WordPressOptionsFake
{
    /** @var array<string, mixed> */
    private $options = [];

    /**
     * Instala los stubs de options, transients y object cache.
     *
     * @param array $seed Options iniciales.
     * @return self
     */
    public static function install(array $seed = []): self
    {
        $fake = new self();
        $fake->options = $seed;

        Functions\when('get_option')->alias(function ($key, $default = false) use ($fake) {
            return array_key_exists($key, $fake->options) ? $fake->options[$key] : $default;
        });

        Functions\when('update_option')->alias(function ($key, $value, $autoload = null) use ($fake) {
            if (array_key_exists($key, $fake->options) && $fake->options[$key] === $value) {
                return false; // Mismo comportamiento que WordPress ante un valor identico.
            }
            $fake->options[$key] = $value;
            return true;
        });

        Functions\when('add_option')->alias(function ($key, $value, $deprecated = '', $autoload = 'yes') use ($fake) {
            if (array_key_exists($key, $fake->options)) {
                return false;
            }
            $fake->options[$key] = $value;
            return true;
        });

        Functions\when('delete_option')->alias(function ($key) use ($fake) {
            unset($fake->options[$key]);
            return true;
        });

        Functions\when('set_transient')->alias(function ($key, $value, $ttl = 0) use ($fake) {
            $fake->options['_transient_' . $key] = $value;
            $fake->options['_transient_timeout_' . $key] = time() + (int) $ttl;
            return true;
        });

        Functions\when('get_transient')->alias(function ($key) use ($fake) {
            $timeout = $fake->options['_transient_timeout_' . $key] ?? null;
            if ($timeout !== null && $timeout < time()) {
                unset($fake->options['_transient_' . $key], $fake->options['_transient_timeout_' . $key]);
                return false;
            }
            return $fake->options['_transient_' . $key] ?? false;
        });

        Functions\when('delete_transient')->alias(function ($key) use ($fake) {
            unset($fake->options['_transient_' . $key], $fake->options['_transient_timeout_' . $key]);
            return true;
        });

        Functions\when('wp_cache_get')->justReturn(false);
        Functions\when('wp_cache_set')->justReturn(true);
        Functions\when('wp_cache_delete')->justReturn(true);

        // La cache estatica de ConfigBuilder sobrevive entre tests del mismo proceso.
        ConfigBuilder::invalidate_cache();

        return $fake;
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->options[$key] = $value;
        ConfigBuilder::invalidate_cache();
    }

    /**
     * @return array
     */
    public function all(): array
    {
        return $this->options;
    }
}
