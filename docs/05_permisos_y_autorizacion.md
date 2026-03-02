# 5. Sistema de Permisos por Rol (El "Gatekeeper")

Una vez superada la fase de Autenticación (`Auth` module proveyó la "identidad" de la solicitud mediante un token o key vigente), entra en juego el módulo de Autorización (`Permissions`), el cual actúa como embudo restrictivo (_Gatekeeper_).

El nivel de protección del plugin garantiza solidez en la lectura/escritura y visibilidad asimétrica de campos.

## 1. Adhesión al Ecosistema de Roles Nativos (`WP_Roles`)

No reinventaremos la rueda creando nuestro propio silo aislado de sistemas de roles (y corriendo el riesgo de desincronización con plugins como User Role Editor). El plugin asume como anclas (keys mapeables) los roles globales detectados (`administrator`, `editor`, `subscriber`, `guest`).

Se gestionará perfiles de usuario, con el rol pseudo-virtual adicional `guest` para consultas no autenticadas (tokens ausentes).

## 2. Arquitectura de Matriz de Permisos

Dentro de las configuraciones almacenadas (Options API), se persiste una matriz estructurada que el Gatekeeper evaluará en velocidad < 1ms por cada request.

**Dimesiones de la Matriz:**

### Nivel 1: Acceso a nivel de Endpoint (Resource)

Por cada endpoint activo (`/v1/properties`), se determinan las operaciones autorizadas mediante mapeo de Capabililties:

- **Rol 'Subscriber':** Puede `{ "read": true, "write": false, "delete": false }`
- **Rol 'Editor':** Puede `{ "read": true, "write": true, "delete": true }`

### Nivel 2: Acceso Direccionado (Ownership)

¿Un 'Vendedor' puede mutar inmuebles de _otros_ vendedores?

- Se aplica el anclaje clásico nativo: Validar los campos relacionales (`post_author`) en caso de que sea `edit_posts` vs `edit_others_posts`, delegando donde sea posible a un chequeo `current_user_can('edit_post', $post_id)`.

### Nivel 3: Control a nivel Metacampo (Granularity Extrema)

Este es el "Killer Feature". No basta con dejar ver una `Reseña`; un rol de 'Invitado' no debería poder leer campos meta privados como `email_cliente` asociado a la reseña.

El Output Schema Processor iterará cada campo autorizado:

```php
// Ejemplo Gatekeeper interrumpiendo fields logic
foreach ($post_fields as $field_key => $value) {
   if ( ! Gatekeeper::userLacksFieldPerm($current_role, $post_type, $field_key, 'read') ) {
       $response['fields'][$field_key] = $value;
   }
}
```

Así mismo en Peticiones POST/PATCH (`write`):
Si un usuario de bajo nivel envía a un endpoint habilitado de PATCH un JSON conteniendo `{"price": 50, "is_featured": true}`, y carece de permisos para modificar el campo `is_featured`, el Ingestion Service omite silenciosamente ese metacampo (o arroja 403 sobre el payload dependiendo del setting de tolerancia a modo estricto).

## 3. Integración a Nivel Código (Middleware)

Todo esto forma parte de la cadena de enrutamiento:

1. Request entrante por `POST /v1/casas`.
2. El Middleware interceptor solicita el chequeo temprano.
   `Gatekeeper::authorizeRequest($request)`
3. Obtiene context = "casas" | method = "POST" | identity = Rol "agente".
4. Indagación en options en caché para "casas". Retorna permisos del agente.
5. Autorizado -> Siguiente capa (Controller de la BD).

## 4. Extendibilidad y Filtros Custom

Todo el proceso de toma de decisiones está forrado de filtros y action hooks. Un desarrollador podrá añadir condicionales lógicas extremas no cubiertas por interfaz gráfica.

Ejemplo: Limitar que el Rol "agente" sólo acceda a endpoints pasadas las 10 PM.

```php
add_filter('wpcapi_gatekeeper_authorize', function($is_allowed, $context, $user) {
    if ($user->has_role('agente') && $context->resource === 'casas') {
        if (date('H') > 22) return false;
    }
    return $is_allowed;
}, 10, 3);
```
