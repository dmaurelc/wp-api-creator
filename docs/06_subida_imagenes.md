# 6. Sistema de Subida de Imágenes

Las aplicaciones modernas (sobre todo mobile o admin reactivos externos) necesitan flujos de ingesta de medios (File uploads) eficientes, seguros y adaptables, usualmente en formato `multipart/form-data`.

Usar la inyección base 64 como strings gigantes embebidos en el CRUD JSON inicial es una atrocidad técnica. Este plugin emplea **Endpoints Segregados de Medios**.

## 1. Arquitectura del Endpoint Específico

Se expone una ruta dedicada: `POST /wp-custom-api/v1/media`

### Flujo Operativo:

1. Las llamadas POST se configuran como `Content-Type: multipart/form-data`.
2. Se procesan los arrays en `$_FILES`.
3. El Security Middleware escanea el MIME-type real de la firma del archivo y no sólo la extensión (MIME Sniffing Prevention).
4. El Gatekeeper evalúa si el usuario autenticado tiene permisos estipulados para habilitar un `upload_files` (usualmente reservado a usuarios que tienen este cap native mapeado).
5. Se efectúa un sideload controlado delegando en constructores del core como `media_handle_sideload()` o `wp_handle_upload()`.
6. Si la lógica triunfa, retorna un Objeto Media JSON:
   ```json
   {
     "id": 8552,
     "url": "https://midominio.com/wp-content/uploads/2023/10/foto1.jpg",
     "thumbnails": {
       "medium": "...",
       "large": "..."
     }
   }
   ```

## 2. Asociación Automática Mágica

A menudo los desarrolladores front-end necesitan subir una imagen y anclarla a un CPT. Hacer esto a mano requiere enviar al `/media`, recibir ID `8552`, y luego disparar un PATCH `/v1/propiedades/40` enviando `{ "imagen_destacada": 8552 }`.

Nuestro plugin soporta asociación pre-vuelo enviando parámetros en el POST de `/media`:

- Parámetro FormData `associate_to_post: 40`.
- Parámetro FormData `associate_as: _thumbnail_id` (para featured images), o el key del metacampo de ACF (`acf_foto_galeria`).

El backend asegurará que el autor _tenga derechos de escritura_ sobre la propiedad "post ID 40" antes de permitir la asociación.

## 3. Seguridad y Protección contra Abuso

En un entorno Web API abierta, permitir subida de archivos es el pilar #1 de las vulnerabilidades RCE (Remote Code Execution).

**Barreras a implementar:**

1. **Límites de Tamaño Estrictos:** Independientes del `php.ini`. Validados por middleware de peso en bytes por Rol (e.g. Subs: máx 2MB, Editores: máx 10MB).
2. **Control Anti-Malware MIME:**
   - Restricción hardcodeada predeterminada a `image/jpeg`, `image/png`, `image/webp`.
   - Impedir la subida disimulada de `image/svg+xml` y PDFs a menos que se fuerce desde el admin por riesgo de XML Bomb / XSS.
3. **Ofuscación de Nombres (Rename Opcional):**
   Para evitar escaneos recursivos o conflictos, el módulo Media incluirá la provisión auto-renombradora que genera un hash único u UUID post-subida y blanquea metadatos EXIF.
4. **Rate Limiting Agresivo:** Se definirá en el nivel API Middleware que las rutas `/media` restringen a `X` requests por minuto por usuario para mitigar agotadores del almacenamiento del disco local.
