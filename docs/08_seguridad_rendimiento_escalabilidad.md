# 8. Rendimiento, Seguridad y Escalabilidad Futura

Conducir WP hacia un rol Headless y puramente Data-Driven requiere cuidados que un framework estándar no atiende por defecto.

## 1. Seguridad Avanzada

Además de Gatekeepers y Auth, el Middleware Layer asume el control del bloqueo absoluto de anomalías (WAF Ligero).

- **Inyecciones SQL:** Al usar `WP_Query` y `wpdb->prepare()` exhaustivamente se mitiga SQLi directo en CRUD. Ningún parámetro llega a la consulta sin pasar por un diccionario: los estados y los criterios de ordenación están acotados por `enum`, `meta_key` solo admite las metas que el endpoint expone, y los términos de taxonomía pasan uno a uno por `sanitize_title()`. Un parámetro inventado (`?_filter[test]=DROP T`) ni siquiera se declara, así que la ruta lo descarta antes del controlador.
- **XSS (Cross-Site Scripting):** Toda ingesta desde POST/PUT que deba guardar un string sufre `sanitize_text_field` (o filtrado WP_Kses para posts ricos en HTML si se habilitó explícitamente contenido rico desde schema).
- **Escalada de Privilegios:** Validar que el objeto a editar es propiedad fática del request, o del owner apropiado. Ningún payload de metadata puede cruzar hacia overrides que resetean passwords manipulando serializaciones inseguras.

## 1.1 Deuda conocida (registrada, no resuelta)

Tres puntos identificados durante la auditoría de 1.1.0 que se documentan con su ubicación exacta y la condición que los haría peligrosos.

### Clave de caché de autorización de campos

`Gatekeeper::$field_auth_cache` usa la clave `{user_id}_{field_key}_{action}` (`src/Permissions/Gatekeeper.php:123`, método `can_interact_with_field`) sin incluir la configuración del endpoint.

**Alcance real: hoy no es alcanzable.** La propiedad es `private`, no `static`: vive por instancia y cada petición construye un `Gatekeeper` que atiende un solo config. Dos CPT no pueden compartir veredicto porque no comparten instancia. La entrada previa de esta lista afirmaba lo contrario y exageraba el riesgo.

Se mantiene registrada porque hay dos cambios plausibles que la convertirían en un fallo real: hacer la propiedad `static`, o reutilizar una misma instancia de `Gatekeeper` entre configuraciones distintas — que es justo lo que haría un singleton. **Quien retome el refactor de inyección de dependencias debe incluir el endpoint en la clave de caché antes de colapsar las instancias**, o un campo restringido de un CPT se filtrará a través de otro.

### Swagger UI carga scripts de terceros sin SRI

`AdminApi::get_swagger_ui()` sirve una respuesta HTML que carga tres recursos de `unpkg.com` sin atributo `integrity`. Con `require_api_key` desactivado esa respuesta es anónima. Un compromiso del CDN ejecutaría código arbitrario en el navegador de quien abra la documentación.

### `KeyUsageStore::touch()` es un read-modify-write sin bloqueo

Dos peticiones concurrentes con API Keys distintas pueden perder una de las marcas de `last_used_at`. El impacto es cosmético y la guarda de 300 segundos lo hace poco frecuente. La alternativa sería una option por key, a costa de multiplicar las filas de `wp_options`.

### Validación de `iss` y dominios múltiples

`JwtProvider::validate_token()` compara `iss` con `get_bloginfo('url')`. En instalaciones con domain mapping, o con plugins que filtran `home_url` según la petición, un token emitido bajo un dominio se rechaza con `jwt_invalid_issuer` al usarlo desde otro. El remedio es reautenticar; si el escenario se vuelve habitual, habría que fijar el emisor a un valor estable en lugar de al dominio activo.

### Mapeo de campos estático y sin invalidación

`OutputSerializer::$field_mappings` es una propiedad `static` que nunca se invalida. Persiste durante todo el proceso PHP; si en el futuro se sirven varias configuraciones distintas en una misma petición, devolverá el mapeo de la primera.

## 2. Optimizaciones de Rendimiento (Performance)

Las transacciones API exigen latencias sub 200ms.

- **Object Cache Transparente:** Todas las consultas pesadas de introspección (búsqueda dinámica de Metas o CPTs y chequeos ACL Gatekeeper) implementan `wp_cache_get` y setean a memcached/redis local.
- **Eliminación del Overhead WPREST:** Rutas de salida evitan iteraciones inútiles de HAL/Links internos que WP_REST inyecta y añaden un excesivo trabajo del procesador en json_encode.
- **Paginación Inteligente y COUNT Limitado:** Si hay bases de más de 100,000 registros, el parámetro Total Count colapsa MySQL al buscar variables SQL-Calc. Se insertarán flags (Threshold) donde superando las 2,000 requests, el COUNT se asume aproximado en la metadada de paginación para evitar el cuellos de botella de query execution de InnoDB.

### 2.1 Caché de respuestas de colección (`cache_time`)

`Domain\ResponseCache` guarda en `wp_cache_*` la respuesta ya serializada de un listado. Alcance deliberadamente estrecho, y cada recorte elimina un modo de fallo concreto:

| Recorte | Qué evita |
|---|---|
| Solo la ruta de colección | La ruta de elemento único no declara `args`: el `id` viene del patrón de la ruta, así que una clave derivada de la lista de parámetros haría que `/recurso/42` y `/recurso/99` compartieran entrada. |
| Solo `status = publish` | El acceso a contenido no publicado se decide por entrada (`current_user_can('edit_post')`) y por autor (`author__in`). Ninguna clave basada en roles puede representar eso. |
| Sin `search`, `slug` ni `meta_value` | Son texto libre: un anónimo generaría claves ilimitadas, desalojaría las legítimas y convertiría la caché en un amplificador de denegación de servicio. `page` también es libre, pero se autolimita: más allá de los resultados existentes no hay nada que consultar. |

**Composición de la clave:** `blog_id`, slug del endpoint, hash de los parámetros recogidos, roles del usuario ordenados, `wp_cache_get_last_changed()` de `posts` y de `terms`, y tres contadores propios (configuración, metadatos, purga manual).

Los roles entran porque el Gatekeeper filtra metacampos por rol incluso sobre contenido publicado. El ID de usuario no hace falta: con `publish` forzado no hay capacidades por entrada en juego.

**Invalidación.** Los marcadores `last_changed` los mueve WordPress por su cuenta —`clean_post_cache()` al guardar, enviar a la papelera o borrar; `clean_term_cache()` al borrar o renombrar un término— lo que cubre de golpe la publicación programada y los cambios de taxonomía. Lo que **no** cubren son las metas: `update_metadata()` limpia la caché de `post_meta` sin llamar a `clean_post_cache()`, así que un PATCH que solo cambia metas no movería nada. De ahí los hooks `added/updated/deleted_post_meta`, que incrementan un contador propio una sola vez por petición.

Ese contador se toca lo menos posible, porque los hooks se disparan en todo el sitio y no solo en lo que la API publica: no se escribe nada si `cache_time` es 0 —el estado por defecto— ni si el tipo de contenido de la entrada no lo expone ningún endpoint. Sin esas dos guardas, un contador de visitas guardado en un metadato —patrón muy extendido— escribiría en `wp_options` en cada visita del front-end y dejaría la caché permanentemente invalidada.

La versión de configuración se incrementa dentro de `ConfigBuilder::save_config()`, no en `AdminApi`: es el único punto por el que pasan los cuatro caminos de guardado del dashboard **y** la migración.

**Regla dura mantenida:** nada de esto llama a `save_config()` desde el camino de una petición. Los contadores viven en `wp_api_creator_cache_versions`, una option propia con `autoload = no`.

**Sin object cache persistente no hay ahorro.** `wp_cache_set()` sin Redis o Memcached solo vive dentro de la petición. El dashboard lo detecta con `wp_using_ext_object_cache()` y lo advierte en lugar de dejar el ajuste mintiendo. No hay respaldo en transients a propósito: con filtros por taxonomía la combinatoria inflaría `wp_options`.

**Activación segura.** `cache_time` llevaba versiones guardándose sin efecto y el dashboard recomendaba 300. La migración a 1.2.0 lo fuerza a 0 para que nadie estrene una caché en producción sin pedirlo, y hay un botón de purga en Ajustes: desactivar el plugin no vacía un Redis externo.

## 3. Preparación de Escalabilidad y Headless (Roadmap)

La arquitectura expuesta prevé escenarios profesionales y corporativos, haciendo al código agnóstico respecto a la ubicación de la lógica.

### 3.1 Compatibilidad Soporte Multi-Site Extenso

Todas las opciones de UI y configuraciones se guardan por `Blog ID`. El Router intercepta la red según el `blog_id` base desde donde fue inicializado. Un API para Sitio Primario es diferente del Secondary.

### 3.2 Implicación Headless y Desacoplamiento de Themes

Si WP se utiliza meramente como un baúl de contenidos remotos (CMS puro, sin Front), la arquitectura desactiva automáticamente hooks vitales y bloquea enrutamientos frontend de WP en el root folder redirigiendo por 301.

### 3.3 Versionado Evolutivo de API (API Versioning)

El Router siempre inyecta el prefijo.
Actualmente es `/v1/{resource}`.
Si en el próximo año cambian tipos de respuestas o se implementan arquitecturas drásticas (ej. soporte a GraphQL subyacente), se crea el namespace de despliegue `/v2/` donde los serializadores heredan nuevas plantillas de mutación (esquema estricto) para evitar fallas a implementaciones frontend existentes y "Legacy clients" que sigan operacionales en `/v1/`.

### 3.4 Exportación Multi-ambiente e IA

La Configuración Master del Dashboard se condensa nativamente a JSON descargable en texto plano, lo cual permite replicar las mismas reglas para entornos Staging / Dev / Producción. En un futuro, un webhook receptor en la API de Staging puede integrar en CD/CI pipelines una resincronización automática de configuraciones post deploy.
