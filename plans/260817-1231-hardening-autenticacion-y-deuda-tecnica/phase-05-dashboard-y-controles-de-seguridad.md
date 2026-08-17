---
phase: 5
title: "Dashboard y controles de seguridad"
status: completed
priority: P1
effort: "10h"
dependencies: [3, 4]
---

# Phase 5: Dashboard y controles de seguridad

## Overview

Adaptar el dashboard al nuevo modelo de keys y **construir los controles que el plan original daba por existentes**. La revision encontro que ni el interruptor de `require_api_key` ni el boton de revocacion de tokens existen en la UI: sin esta fase, dos funcionalidades del release no tienen forma de usarse.

Sube a prioridad P1 y pasa a bloquear la fase 6: el enforcement sin su interruptor solo seria activable manipulando la base de datos.

## Requirements

**Funcionales**
- Crear una key eligiendo usuario de WordPress y caducidad opcional.
- La key en claro se muestra una vez, con copiar y confirmacion obligatoria.
- El listado muestra prefijo, propietario, caducidad, ultimo uso y estado.
- Las keys `legacy` aparecen marcadas con explicacion y accion de regenerar.
- **Existe un interruptor para `require_api_key`** con advertencia al activarlo.
- **Existe un boton para revocar los tokens de un usuario.**

**No funcionales**
- Reutilizar el `Combobox` existente; no introducir componentes nuevos.
- Mantener el prefijo Tailwind `tw-` del proyecto.
- El selector de usuarios debe funcionar en sitios con decenas de miles de usuarios.

## Architecture

**Selector de usuarios.** Se anade `GET /admin/users` siguiendo el patron ya establecido por `/admin/roles` (`AdminApi.php:113-116`), que hace exactamente esto para otro selector. Dos requisitos que el plan original relegaba a "si hace falta" y son obligatorios desde el dia uno:

- Paginacion y busqueda **en servidor**: `get_users(['number' => 100, 'search' => ..., 'fields' => ['ID','display_name']])`. El `Combobox` filtra en cliente sobre el array completo (`Combobox.js:18-19`), asi que sin limite un sitio con 40.000 suscriptores agota la memoria de PHP al abrir la pestana.
- Capacidad `list_users`, no `manage_options`: es la que corresponde a listar usuarios.

**Flujo de creacion:**

```
[Formulario]  usuario* + nombre* + caducidad?
      |
      v POST /admin/api-keys
[Modal] key en claro + copiar + "Ya la guarde" (obligatorio)
      |
      v cerrar  (la key se borra del estado de React)
[Listado] ak_7f3a...  ·  Daniel (administrator)  ·  sin caducidad  ·  nunca usada
```

**Interruptor de `require_api_key`.** No existe: `Settings.js:15` solo lo declara en el estado inicial y el unico `ToggleControl` renderizado es `filter_wp_endpoints` (`:190-193`). Se anade con confirmacion explicita al activarlo, porque cierra todos los endpoints publicos.

**Revocacion de tokens.** Boton por usuario que llama al `DELETE /admin/users/{id}/tokens` de la fase 3.

## Related Code Files

- Modify: `src/frontend/components/views/AuthManager.js` (pestana de API Keys, `:263-395`; el listado lee hoy `key.key` en `:342` y `:345`)
- Modify: `src/frontend/components/views/Settings.js` (interruptor `require_api_key` + advertencia)
- Modify: `src/Admin/AdminApi.php` (`GET /admin/users`)

## Implementation Steps

1. **Endpoint de usuarios** — `GET /admin/users` con `number`, `search` y `fields` acotados, protegido por `current_user_can('list_users')`. Devolver `{id, name, roles}`.
2. **Formulario de creacion** — `Combobox` de usuario (obligatorio, con busqueda que consulta al servidor) y selector de caducidad (sin caducidad / 30 dias / 90 dias / 1 ano / fecha). Boton deshabilitado sin usuario.
3. **Modal de key unica** — Bloque monoespaciado con copiar al portapapeles y checkbox de confirmacion. No se cierra sin confirmar; al cerrarse se borra del estado.
4. **Listado adaptado** — Sustituir la columna de key en claro por `prefix` + `...`. Anadir propietario, caducidad y ultimo uso. Estado: activa / caducada / legacy. Blindar el render con `Array.isArray(res.keys) ? res.keys : Object.values(res.keys || {})` por si queda algun option corrupto de antes de la fase 1.
5. **Banner de legacy** — Si hay keys `legacy`, explicar que la actualizacion de seguridad las invalido y enlazar a crear una nueva.
6. **Interruptor de `require_api_key`** — `ToggleControl` en Settings siguiendo el patron de `filter_wp_endpoints`. Al activarlo, confirmacion: "Esto cerrara todos los endpoints publicos de tu API. Los clientes sin API key dejaran de funcionar."
7. **Boton de revocacion de tokens** — En la pestana JWT, por usuario, con confirmacion. Consume el endpoint de la fase 3.
8. **Rebuild y verificacion manual** — `npm run build` y comprobar el flujo completo contra un WordPress real.

## Success Criteria

- [ ] Crear una key sin seleccionar usuario es imposible desde la UI.
- [ ] La key en claro se muestra una vez; recargar no la vuelve a mostrar.
- [x] El listado nunca renderiza un secreto completo.
- [ ] Borrar la primera de tres keys no rompe el listado.
- [ ] Las keys legacy se muestran marcadas y con explicacion.
- [ ] `require_api_key` se puede activar y desactivar desde el dashboard, con advertencia.
- [ ] Revocar los tokens de un usuario desde la UI invalida sus tokens vivos.
- [ ] En un sitio con 10.000+ usuarios, la pestana de API Keys abre sin agotar memoria.
- [x] `npm run build` sin errores ni warnings nuevos.

## Risk Assessment

| Riesgo | Mitigacion |
|--------|------------|
| El usuario cierra el modal sin copiar la key | Checkbox obligatorio y texto explicito de que no volvera a mostrarse |
| `/admin/users` como vector de enumeracion | `current_user_can('list_users')`, devuelve solo id/nombre/roles |
| Sitios con muchos usuarios | Busqueda y limite en servidor desde el primer commit, no como mitigacion futura |
| El interruptor de enforcement se activa por error | Confirmacion explicita; se puede revertir desde la misma pantalla porque `/admin/*` queda fuera del enforcement (fase 6) |

> **Estado de verificación.** Las casillas marcadas están verificadas por test unitario (`composer test`) o por comando (`grep`, `npm run build`). Las casillas sin marcar están **implementadas pero pendientes de verificación funcional** contra una instalación real de WordPress: el entorno de desarrollo no tiene una disponible y la suite es unitaria, sin `wp-env`. No representan trabajo pendiente de código.
