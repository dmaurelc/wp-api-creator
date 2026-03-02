<?php

namespace WpApiCreator\Core;

use Exception;

/**
 * Contenedor de Inyección de Dependencias básico (DI Container).
 */
class Container
{
    /**
     * @var array
     */
    protected $bindings = [];

    /**
     * @var array
     */
    protected $instances = [];

    /**
     * Registra un servicio o closure en el contenedor.
     *
     * @param string $id Identificador (usualmente el FQCN).
     * @param callable|mixed $concrete Implementación.
     */
    public function bind(string $id, $concrete): void
    {
        $this->bindings[$id] = $concrete;
    }

    /**
     * Registra una instancia única en el contenedor (Singleton).
     *
     * @param string $id Identificador.
     * @param callable|mixed $concrete Implementación.
     */
    public function singleton(string $id, $concrete): void
    {
        $this->bindings[$id] = function ($container) use ($concrete) {
            static $object;

            if (is_null($object)) {
                $object = is_callable($concrete) ? $concrete($container) : $concrete;
            }

            return $object;
        };
    }

    /**
     * Obtiene un servicio del contenedor.
     *
     * @param string $id
     * @return mixed
     * @throws Exception
     */
    public function get(string $id)
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->bindings[$id])) {
            throw new Exception("Servicio no encontrado en el contenedor: {$id}");
        }

        $concrete = $this->bindings[$id];
        $object = is_callable($concrete) ? $concrete($this) : $concrete;

        $this->instances[$id] = $object;

        return $object;
    }

    /**
     * Comprueba si el contenedor tiene el servicio.
     *
     * @param string $id
     * @return bool
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }
}
