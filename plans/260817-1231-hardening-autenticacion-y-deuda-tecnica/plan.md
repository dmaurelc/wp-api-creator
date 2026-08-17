---
title: "Hardening de autenticacion y deuda tecnica"
description: "Repara la capa de persistencia, cierra la Fase 4 de autenticacion y sella la superficie publica de la API. Revisado adversarialmente."
status: completed
priority: P1
branch: "main"
tags: [security, auth, jwt, api-keys, testing, release]
blockedBy: []
blocks: []
created: "2026-08-17T16:52:08.184Z"
createdBy: "ck:plan"
source: skill
---

# Hardening de autenticacion y deuda tecnica

## Overview

Auditoria del 17-ago-2026 sobre `wp-api-creator` v1.0.0, revisada despues por cuatro revisores adversariales. La revision cambio la estructura del plan: lo que parecia un endurecimiento de la autenticacion resulto apoyarse en una capa de persistencia rota.

**Hallazgo que reordena todo:** las API keys se escriben en `$config['api_keys']` (`AdminApi.php:337-345`) y se leen en `$config['settings']['api_keys']` (`ConfigBuilder.php:95,103`). Son dos arrays disjuntos que nadie puentea. **Ninguna key creada desde el dashboard ha autenticado jamas**; lo unico que funcionaba era la master key hardcodeada de `WP_DEBUG`. El diagnostico inicial ("cualquier key concede privilegios de administrador") describia un problema real en el codigo pero inalcanzable en la practica.

Los dos fallos de cara al usuario siguen siendo el nucleo del release, y ambos son promesas rotas de la interfaz:

1. `jwt_expiration` se guarda desde el dashboard y se lee en `ConfigBuilder`, pero ningun consumidor backend lo usa: `AuthController.php:49` fija 24h a fuego.
2. `require_api_key` se guarda y se refleja en el Swagger, pero el Router no lo exige en ninguna ruta — y ademas **no tiene interruptor en la interfaz**, asi que hoy solo es activable manipulando la base de datos.

## Decisiones tomadas (confirmadas con el usuario)

| Tema | Decision |
|------|----------|
| Modelo de API Keys | Key ligada a un `user_id` real, hash SHA-256 + prefijo, `expires_at`. Hereda el rol del usuario. |
| `last_used_at` | Se conserva, pero en su propia option `wp_api_creator_key_usage` con `autoload = no`. **Regla dura: ninguna ruta del path de request llama a `ConfigBuilder::save_config()`.** |
| Revocacion JWT | `token_version` en user_meta, obligatorio en el payload e inicializado a 1. Sin blacklist de `jti`. |
| Compatibilidad | Se rompe. Version **1.1.0**, no 2.0.0 — el usuario considera desproporcionado un major para un update de seguridad. Breaking changes documentados en el changelog. |
| Tests | PHPUnit + Brain Monkey/Mockery. Unitarios, sin Docker. |
| Refactor de DI | **Fuera de 1.1.0.** Su beneficiario declarado (tests de integracion) esta fuera de alcance. |
| `_include` | **Retirado.** Hoy se acepta y documenta un parametro que no hace nada. |
| `/docs` | Entra en el enforcement: con `require_api_key` activo exige credencial. |

## Phases

| Phase | Name | Status |
|-------|------|--------|
| 1 | [Prerrequisitos de integridad de datos](./phase-01-prerrequisitos-de-integridad-de-datos.md) | Completed |
| 2 | [Infraestructura de tests](./phase-02-infraestructura-de-tests.md) | Completed |
| 3 | [Hardening JWT](./phase-03-hardening-jwt.md) | Completed |
| 4 | [Rediseno de API Keys (backend)](./phase-04-rediseno-de-api-keys-backend.md) | Completed |
| 5 | [Dashboard y controles de seguridad](./phase-05-dashboard-y-controles-de-seguridad.md) | Completed |
| 6 | [Enforcement y cierre de superficie](./phase-06-enforcement-y-cierre-de-superficie.md) | Completed |
| 7 | [Status por permisos y release](./phase-07-status-por-permisos-y-release.md) | Completed |

## Orden y paralelismo

```
Fase 1 (integridad de datos)  ← nada funciona sin esto
      └─> Fase 2 (tests)
             ├─> Fase 3 (JWT) ────┐
             └─> Fase 4 (keys) ───┴─> Fase 5 (dashboard) ─> Fase 6 (enforcement) ─> Fase 7 (release)
```

Las fases 3 y 4 son paralelizables. La 5 bloquea a la 6: el enforcement sin su interruptor solo seria activable desde la base de datos, y sin el dashboard actualizado la fase 4 deja la pestana de keys renderizando campos que ya no existen.

Esfuerzo estimado: ~56h (la estimacion inicial de 35h no contemplaba la fase 1 ni los controles de UI inexistentes).

## Riesgo global

| Riesgo | Impacto | Mitigacion |
|--------|---------|------------|
| La migracion de datos de la fase 1 corrompe la configuracion | Critico | Backup integro del option a `wp_api_creator_config_backup_1_0_0` antes de migrar, con runbook de restauracion (fase 7) |
| Activar `require_api_key` cierra endpoints en produccion | Alto | Interruptor nuevo con confirmacion explicita, desactivado por defecto. Nota: la mitigacion original ("solo afecta a quien ya lo activo") era falsa — nadie ha podido activarlo nunca desde la UI. |
| Las API keys existentes dejan de funcionar | Bajo | En la practica nunca autenticaron. Aun asi, marcado `legacy` visible y aviso para regenerarlas. |
| El enforcement rompe integraciones desde navegador | Alto | Exclusion explicita de `OPTIONS`: los preflight no llevan cabeceras personalizadas y un 401 ahi se manifiesta como un error de CORS opaco |
| Refactor de auth sin red de seguridad | Alto | Fase 2 antes de tocar `JwtProvider` y `ApiKeyProvider`, con la costura del secreto que hace testable la eliminacion del fallback |
| Rollback imposible tras destruir credenciales | Alto | Backup previo + runbook documentado. El plan original no tenia ninguna via de vuelta. |

## Fuera de alcance

- Refresh tokens y `/auth/refresh` (`token_version` cubre la invalidacion).
- Blacklist de `jti` individuales.
- Suite de integracion con `wp-env`.
- Scopes por key (se eligio herencia de rol).
- Refactor de DI y `_include` (ver decisiones).

## Deuda conocida documentada, no resuelta aqui

- `Gatekeeper::$field_auth_cache` no incluye el config en su clave (`Gatekeeper.php:138`). Quien retome el refactor de DI **debe** arreglarlo antes de colapsar las instancias de `Gatekeeper`, o dos CPT que compartan un meta con permisos distintos filtraran el campo restringido.
- `get_swagger_ui` carga scripts de `unpkg.com` sin SRI en una respuesta anonima.
- `OutputSerializer::$field_mappings` es `static` y nunca se invalida.

## Red Team Review

### Session — 2026-08-17
**Findings:** 40 brutos, 27 tras deduplicar (27 aceptados, 0 rechazados)
**Severity breakdown:** 6 Critical, 11 High, 10 Medium
**Revisores:** Security Adversary (Fact Checker), Failure Mode Analyst (Flow Tracer), Assumption Destroyer (Scope Auditor), Scope & Complexity Critic (Contract Verifier)

Ningun hallazgo se rechazo por el filtro de evidencia: los 27 traen citas `file:line`. Los seis criticos fueron verificados de nuevo de forma independiente antes de aplicarse.

| # | Finding | Severity | Disposition | Applied To |
|---|---------|----------|-------------|------------|
| 1 | `api_keys` se escribe y se lee en ubicaciones distintas; las keys del dashboard nunca autenticaron | Critical | Accept | Fase 1 (nueva) |
| 2 | `save_settings` reemplaza `settings` sin merge; destruye campos del servidor | Critical | Accept | Fase 1 |
| 3 | `/docs*` es `__return_true`; la fase eximia con premisa falsa | Critical | Accept | Fases 1, 6 |
| 4 | El cortocircuito no guarda `$result` ni excluye `OPTIONS`: rompe el preflight CORS | Critical | Accept | Fase 6 |
| 5 | `api_namespace` sin validar colisiona con `creator/v1` y permite escalada a `/admin/*` | Critical | Accept | Fase 1 |
| 6 | `last_used_at` fuerza read-modify-write del blob de config desde el path de request | Critical | Accept | Fase 4 |
| 7 | `generate_token(): string` no puede devolver `WP_Error`: TypeError fatal | High | Accept | Fase 3 |
| 8 | Brain Monkey no mockea constantes; `get_secret_key()` sin costura | High | Accept | Fase 2 |
| 9 | `token_version` fail-open: meta ausente = 0, los tokens viejos pasarian | High | Accept | Fase 3 |
| 10 | El requisito de revocacion no tenia superficie de UI en ninguna fase | High | Accept | Fases 3, 5 |
| 11 | `/media` solo comprueba `is_user_logged_in()` pese a recibir el Gatekeeper | High | Accept | Fase 6 |
| 12 | Cita erronea: el hardcode de `publish` esta en `:31`, no `:91`; y `edit_posts` expone borradores ajenos | High | Accept | Fase 7 |
| 13 | `delete_api_key` sin `array_values()` corrompe el JSON y rompe el dashboard | High | Accept | Fase 1 |
| 14 | `npm run zip` empaqueta desde `HEAD`: el ZIP saldria con el dashboard viejo | High | Accept | Fase 7 |
| 15 | Sin plan de rollback para un release que destruye credenciales | High | Accept | Fases 1, 7 |
| 16 | `require_api_key` no tiene control en la UI | High | Accept | Fase 5 |
| 17 | La exencion de `/admin` y `/docs` es codigo muerto (namespaces distintos) | High | Accept | Fase 6 |
| 18 | El refactor de DI se justificaba con tests fuera de alcance; `Container::get()` cachea todo | Medium | Accept | Fase 7 (recorte) |
| 19 | La validacion de jerarquia de roles protege un escenario imposible | Medium | Accept | Fase 4 (recorte) |
| 20 | `Gatekeeper::$field_auth_cache` sin config en la clave | Medium | Accept | Deuda documentada |
| 21 | `/admin/users` sin paginacion; el Combobox filtra en cliente | Medium | Accept | Fase 5 |
| 22 | Rate limiting solo por IP es un arma de bloqueo y no frena credential stuffing | Medium | Accept | Fase 6 |
| 23 | `.distignore` no contiene las entradas citadas y es configuracion muerta | Medium | Accept | Fase 7 |
| 24 | Docs de usuario omitidos de la sincronizacion | Medium | Accept | Fase 7 |
| 25 | `AuthManager.js:218-231` afirma que se usa `SECURE_AUTH_KEY`; la fase 3 lo cambia | Medium | Accept | Fase 3 |
| 26 | La fase de dashboard no bloqueaba al enforcement: ventana con `undefined` en la UI | Medium | Accept | plan.md (orden) |
| 27 | Estimacion de 35h sin margen para el trabajo prerrequisito | Medium | Accept | plan.md (~56h) |

### Whole-Plan Consistency Sweep
- Files reread: plan.md, phase-01 a phase-07 (reescritos por completo)
- Decision deltas checked: 8 (fase 1 nueva, `last_used_at` reubicado, DI cortado, `_include` retirado, `/docs` cerrado, renumeracion 6->7 fases, orden 5 bloquea 6, estimacion revisada)
- Reconciled stale references: 12 (cita `:91`->`:31`, premisa de privilegio excesivo, "protegido por capacidades", exenciones de ruta, ubicacion del secreto JWT, mitigacion de riesgo de `require_api_key`, `.distignore`, tipo de retorno de `generate_token`, whitelist de Fase 1 de tests, `ApiKeyProviderTest` movido a fase 4, `schema_version` sustituido por idempotencia por contenido, docs de usuario)
- Unresolved contradictions: 0
