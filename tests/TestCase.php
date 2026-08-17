<?php

namespace WpApiCreator\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use ReflectionProperty;

/**
 * Base de la suite unitaria: arranca y detiene Brain Monkey en cada test.
 *
 * Ademas devuelve a su estado inicial las caches estaticas del plugin. Una propiedad
 * `static` sobrevive a todo el proceso PHP: sin este reset, el primer test que serializa
 * un post fija el mapeo de campos de ese post_type para los tests siguientes, y el
 * resultado depende del orden de ejecucion.
 */
abstract class TestCase extends PhpUnitTestCase
{
    /**
     * Propiedades estaticas que guardan cache y deben vaciarse entre tests.
     *
     * @var array<array{0: class-string, 1: string, 2: mixed}>
     */
    private const STATIC_CACHES = [
        [\WpApiCreator\Api\OutputSerializer::class, 'field_mappings', []],
        [\WpApiCreator\Schema\FieldScanner::class, 'fields_cache', []],
        [\WpApiCreator\Domain\ConfigBuilder::class, 'config_cache', null],
        [\WpApiCreator\Domain\ResponseCache::class, 'meta_bumped', false],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->resetStaticCaches();

        if (class_exists('WP_Query')) {
            \WP_Query::reset();
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Vacia las caches estaticas listadas en STATIC_CACHES.
     */
    private function resetStaticCaches(): void
    {
        foreach (self::STATIC_CACHES as [$class, $property, $empty]) {
            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionProperty($class, $property);
            $reflection->setAccessible(true);
            $reflection->setValue(null, $empty);
        }
    }
}
