---
phase: 2
title: "Infraestructura de tests"
status: completed
priority: P1
effort: "6h"
dependencies: [1]
---

# Phase 2: Infraestructura de tests

## Overview

PHPUnit + Brain Monkey para poder reescribir la capa de autenticacion con red de seguridad. Sin Docker ni base de datos.

Incluye un cambio de diseno que la revision adversarial demostro imprescindible: **hacer inyectable el secreto JWT**. Sin esa costura, la mitad de los tests que la fase 3 necesita no son escribibles.

## Requirements

**Funcionales**
- `composer test` corre en verde.
- Tests de caracterizacion del comportamiento actual de `JwtProvider` y `Gatekeeper` antes de modificarlos.
- El secreto de firma se puede inyectar sin depender de constantes.

**No funcionales**
- La suite completa corre en menos de 10 segundos, sin `@runInSeparateProcess`.
- Todo en `require-dev`. Cero dependencias de produccion nuevas.

## Architecture

**El problema de las constantes.** Brain Monkey intercepta funciones globales y hooks; **no puede mockear constantes**. `JwtProvider::get_secret_key()` se ramifica con `defined()` sobre `WP_API_CREATOR_JWT_SECRET` y `SECURE_AUTH_KEY`. Una constante definida en el bootstrap queda fijada para todo el proceso PHP, asi que los tres escenarios que la fase 3 necesita (constante propia / `SECURE_AUTH_KEY` / ningun secreto) son mutuamente excluyentes en la misma suite.

La salida no es forzar procesos separados — rompe el requisito de los 10 segundos — sino anadir una costura:

```php
public function __construct(?string $secret = null) {
    $this->secret = $secret;   // null => resolver por constantes en tiempo de uso
}
```

Con el secreto inyectable, las tres ramas se prueban en proceso. Los dos sitios que construyen `JwtProvider` (`Router.php:45` y `Router.php:262`) siguen llamando sin argumentos.

**Que se caracteriza y que no.** Se escriben tests de caracterizacion solo para el codigo que **sobrevive**: `JwtProvider` (se extiende, no se reescribe) y `Gatekeeper` (no se toca en este plan). No se caracteriza `ApiKeyProvider`: la fase 4 lo reescribe entero y fijar su comportamiento actual seria trabajo tirado — ademas su comportamiento actual es "no autentica nada" (ver fase 1).

```
tests/
  bootstrap.php              # autoload + stubs WP (WP_Error, is_wp_error)
  TestCase.php               # setUp/tearDown de Brain Monkey
  Unit/
    Auth/JwtProviderTest.php
    Permissions/GatekeeperTest.php
```

## Related Code Files

- Create: `phpunit.xml`, `tests/bootstrap.php`, `tests/TestCase.php`
- Create: `tests/Unit/Auth/JwtProviderTest.php`
- Create: `tests/Unit/Permissions/GatekeeperTest.php`
- Modify: `composer.json` (brain/monkey, mockery, `autoload-dev`, script `test`, email real del autor)
- Modify: `src/Auth/JwtProvider.php` (constructor con secreto opcional)

## Implementation Steps

1. `composer require --dev brain/monkey mockery/mockery`; anadir `autoload-dev` con `WpApiCreator\Tests\` -> `tests/` y el script `test`.
2. Crear `phpunit.xml` apuntando a `tests/bootstrap.php` con la suite `Unit`.
3. `tests/bootstrap.php`: cargar el autoloader, definir `ABSPATH` y stubs de `WP_Error` (`get_error_code`, `get_error_message`, `get_error_data`) e `is_wp_error()`. **No** definir `SECURE_AUTH_KEY` aqui.
4. `tests/TestCase.php` con `Brain\Monkey\setUp()` / `tearDown()`.
5. **Costura del secreto** — Anadir el constructor opcional a `JwtProvider` y hacer que `get_secret_key()` devuelva el inyectado si existe. Verificar los 2 sitios de construccion.
6. `JwtProviderTest` de caracterizacion, con el secreto inyectado: token valido resuelve el `user_id`; firma alterada -> `jwt_invalid_signature`; 2 segmentos -> `jwt_invalid_format`; `exp` pasado -> `jwt_expired`; `nbf` futuro -> `jwt_not_active`. Comentar los asserts que la fase 3 cambiara (la expiracion de 24h).
7. `GatekeeperTest`: rol `public`; invitado sin permiso (401); rol insuficiente (403); rol coincidente; administrador siempre pasa; metodo no mapeado (405).
8. Verificar que `.gitattributes` ya excluye `tests/`, `phpunit.xml` y `.phpunit.result.cache` del ZIP. Ya estan (lineas 33-37) — no duplicar.

## Success Criteria

- [x] `composer test` pasa en verde en menos de 10s sin procesos separados.
- [x] `new JwtProvider('secreto-de-test')` firma y valida sin tocar constantes.
- [x] `JwtProviderTest` cubre los 5 escenarios de firma y expiracion.
- [x] `GatekeeperTest` cubre los 6 casos de la matriz de autorizacion.
- [x] `composer.json` mantiene `require` con solo `php >=7.4`.
- [x] El ZIP generado no contiene `tests/` ni `phpunit.xml` (`unzip -l`).

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| Brain Monkey no puede redefinir funciones ya declaradas | Usar `Functions\when()`/`expect()`; nunca declarar funciones de WP a mano en el bootstrap |
| Los tests de caracterizacion fijan un bug como esperado | Comentar explicitamente los asserts que cambian en la fase 3 |
| El constructor opcional se percibe como cambio innecesario | Es el unico modo de probar la eliminacion del secreto de fallback, que es el cambio mas delicado del plan |
