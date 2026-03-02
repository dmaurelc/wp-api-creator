# 8. Rendimiento, Seguridad y Escalabilidad Futura

Conducir WP hacia un rol Headless y puramente Data-Driven requiere cuidados que un framework estándar no atiende por defecto.

## 1. Seguridad Avanzada

Además de Gatekeepers y Auth, el Middleware Layer asume el control del bloqueo absoluto de anomalías (WAF Ligero).

- **Inyecciones SQL:** Al usar `WP_Query` y `wpdb->prepare()` exhaustivamente se mitiga SQLi directo en CRUD. Si hay parámetros de filtros locos en URL (`?_filter[test]=DROP T`), las llaves se sanitizan mediante validadores duros limitados a diccionarios.
- **XSS (Cross-Site Scripting):** Toda ingesta desde POST/PUT que deba guardar un string sufre `sanitize_text_field` (o filtrado WP_Kses para posts ricos en HTML si se habilitó explícitamente contenido rico desde schema).
- **Escalada de Privilegios:** Validar que el objeto a editar es propiedad fática del request, o del owner apropiado. Ningún payload de metadata puede cruzar hacia overrides que resetean passwords manipulando serializaciones inseguras.

## 2. Optimizaciones de Rendimiento (Performance)

Las transacciones API exigen latencias sub 200ms.

- **Object Cache Transparente:** Todas las consultas pesadas de introspección (búsqueda dinámica de Metas o CPTs y chequeos ACL Gatekeeper) implementan `wp_cache_get` y setean a memcached/redis local.
- **Eliminación del Overhead WPREST:** Rutas de salida evitan iteraciones inútiles de HAL/Links internos que WP_REST inyecta y añaden un excesivo trabajo del procesador en json_encode.
- **Paginación Inteligente y COUNT Limitado:** Si hay bases de más de 100,000 registros, el parámetro Total Count colapsa MySQL al buscar variables SQL-Calc. Se insertarán flags (Threshold) donde superando las 2,000 requests, el COUNT se asume aproximado en la metadada de paginación para evitar el cuellos de botella de query execution de InnoDB.

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
