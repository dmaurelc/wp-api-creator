# Gestión de Lanzamientos y Distribución

Este documento describe el proceso para crear y distribuir nuevas versiones del plugin WP Custom API Creator.

---

## Resumen del Flujo de Trabajo

1. Actualizar versiones en archivos críticos
2. Documentar cambios en el changelog
3. Generar ZIP de distribución
4. Crear GitHub Release con el ZIP adjunto
5. Crear y pushear tag Git

---

## Archivos Críticos de Versión

### wp-api-creator.php

```php
/**
 * Plugin Name: WP Custom API Creator
 * Version:     1.0.0  // <-- ACTUALIZAR ESTE
 */
define('WP_API_CREATOR_VERSION', '1.0.0'); // <-- Y ESTE
```

### package.json

```json
{
  "version": "1.0.0"  // <-- ACTUALIZAR ESTE
}
```

### README.md - Changelog

```markdown
## Changelog

### 1.1.0
* Nueva característica X
* Corrección de bug Y

### 1.0.0
* Primer lanzamiento estable
...
```

---

## Scripts Disponibles

### `npm run build`

Compila los assets de frontend (JS/SCSS) hacia el directorio `build/`.

```bash
npm run build
```

**Salida**: `build/index.js`, `build/style-index.css`, `build/style-index-rtl.css`

### `npm run zip`

Genera el ZIP de distribución listo para release, respetando las reglas de `.gitattributes`.

```bash
npm run zip
```

**Salida**: `wp-api-creator.zip`

**Contenido del ZIP**:
- `wp-api-creator.php` - Archivo principal
- `README.md` - Documentación
- `build/` - Assets compilados
- `src/` - Código PHP (excepto `src/frontend/`)
- **NO incluye**: `node_modules/`, `package.json`, `*.config.js`, `docs/`, etc.

---

## Proceso Completo de Lanzamiento

> El dashboard **no** es un quinto sitio que actualizar: desde 1.1.0 lee la versión de
> `window.wpApiCreatorData.version`, que `AdminMenu` inyecta desde `WP_API_CREATOR_VERSION`.
> Hasta entonces la tenía escrita a mano en `App.js` y se quedó anclada en 1.0.0.

### Paso 1: Actualizar Versiones

Editar `wp-api-creator.php` (líneas 6 y 19):
```php
Version:     1.1.0
define('WP_API_CREATOR_VERSION', '1.1.0');
```

Editar `package.json` (línea 3):
```json
"version": "1.1.0"
```

### Paso 2: Actualizar Changelog

Agregar entrada en `README.md`:
```markdown
### 1.1.0
* Descripción de los cambios
```

### Paso 3: Compilar y commitear el build

**Este orden es obligatorio.** `npm run zip` ejecuta `git archive ... HEAD`, y `git archive` lee **el commit**, no el árbol de trabajo. Si el `build/` compilado no está commiteado, el ZIP saldrá con el dashboard de la versión anterior: el plugin nuevo servido con una interfaz vieja que consume campos que ya no existen.

```bash
npm run build
git add .
git commit -m "Release v1.1.0: descripción de cambios"
git push
```

### Paso 4: Generar ZIP

```bash
npm run zip
```

### Paso 5: Crear GitHub Release

```bash
gh release create v1.1.0 wp-api-creator.zip \
  --title "v1.1.0 - Título del release" \
  --notes "Descripción detallada de los cambios"
```

**O con notas largas en un archivo**:
```bash
gh release create v1.1.0 wp-api-creator.zip \
  --title "v1.1.0" \
  --notes-file RELEASE_NOTES.md
```

### Paso 6: Crear Tag Git

```bash
git tag v1.1.0
git push origin v1.1.0
```

---

## Runbook de reversión (rollback)

La actualización a 1.1.0 destruye credenciales de forma intencionada: las API Keys antiguas quedan inservibles y los tokens JWT vivos se invalidan. Este es el procedimiento para volver atrás si algo sale mal.

### Qué guarda la actualización

Al ejecutarse por primera vez, la rutina de migración copia el option de configuración íntegro, antes de tocar nada:

| Option | Contenido |
|---|---|
| `wp_api_creator_config_backup_1_0_0` | Copia exacta de `wp_api_creator_config` previa a la migración (`autoload = no`) |
| `wp_api_creator_db_version` | Versión de esquema ya aplicada; controla que la migración corra una sola vez |

El backup **no se sobrescribe** en actualizaciones posteriores: conserva siempre el estado anterior a 1.1.0.

### Procedimiento

1. **Comprobar que el backup existe** antes de nada. Si no está, no hay vuelta atrás por esta vía y hay que restaurar desde la copia de seguridad de la base de datos.

   ```bash
   wp option get wp_api_creator_config_backup_1_0_0 --format=json
   ```

2. **Guardar el estado actual**, por si hiciera falta rehacer el camino.

   ```bash
   wp option get wp_api_creator_config --format=json > config-1.1.0.json
   ```

3. **Restaurar la configuración anterior.**

   ```bash
   wp option get wp_api_creator_config_backup_1_0_0 --format=json > config-1.0.0.json
   wp option update wp_api_creator_config --format=json < config-1.0.0.json
   ```

4. **Borrar el marcador de versión de esquema**, o la migración no volverá a ejecutarse si más adelante se reintenta la actualización.

   ```bash
   wp option delete wp_api_creator_db_version
   ```

5. **Revertir el plugin a 1.0.0**: desinstalar la versión actual e instalar el ZIP de `v1.0.0` desde el release de GitHub. No desactivar con "Eliminar datos".

6. **Limpiar cachés.**

   ```bash
   wp cache flush
   ```

### Qué no se recupera

- **Los tokens JWT emitidos antes de la actualización siguen invalidados.** El meta `_wpac_token_version` no se revierte. Los clientes deben reautenticarse. Para dejarlo como estaba: `wp user meta delete <id> _wpac_token_version` sobre los usuarios afectados.
- **Las API Keys en claro no se regeneran solas**: la restauración del option devuelve las entradas antiguas con su campo `key`, que 1.0.0 vuelve a leer. Si el backup no estuviera, hay que crear claves nuevas y actualizar cada integración.
- El secreto en `wp_api_creator_jwt_secret` queda huérfano. Es inocuo; borrarlo si se quiere limpieza total.

### Alternativa sin volver atrás

En la mayoría de casos es preferible quedarse en 1.1.0 y **desactivar `require_api_key`** desde el dashboard, que es lo único que cierra endpoints hacia fuera. El resto de cambios son correcciones de comportamiento que no dependen de esa opción.

---

## Archivos de Configuración de Distribución

### .gitattributes

Define qué archivos se incluyen/excluyen del ZIP generado por `git archive`.

> **Los patrones de directorio necesitan `/**`.** En `.gitattributes` los atributos se resuelven por archivo, así que un patrón terminado en barra (`tests/`) no excluye nada de lo que hay dentro. Hasta 1.1.0 el archivo usaba esa forma y el ZIP incluía `docs/` y todo `src/frontend/` sin compilar. La forma correcta es declarar ambas líneas, la entrada de árbol y su contenido:
>
> ```
> tests export-ignore
> tests/** export-ignore
> ```
>
> Comprobación rápida antes de publicar:
>
> ```bash
> git check-attr export-ignore -- tests/bootstrap.php src/frontend/index.js docs/00_indice_maestro.md
> # Los tres deben decir: export-ignore: set
> ```

**Archivos excluidos del ZIP**:
- `node_modules/`, `vendor/`
- `src/frontend/` (fuente JS/SCSS)
- `package.json`, `composer.json`, `*.config.js`
- `docs/`, `.github/`
- Archivos `.md` excepto `README.md`
- Archivos de prueba (`test-cpt-meta.php`, etc.)

> `.distignore` se eliminó en 1.1.0. Ninguna herramienta del proyecto lo consumía — `npm run zip` usa `git archive --worktree-attributes`, que solo lee `.gitattributes` — y su contenido había divergido, de modo que era configuración muerta capaz de inducir a error. Si en el futuro se adopta `wp dist-archive`, habrá que recrearlo en sincronía con `.gitattributes`.

### .gitignore

Define qué archivos NO se rastrean en Git.

**Incluye**:
- `*.zip` - Los ZIPs generados no se suben al repo (solo a releases)
- `node_modules/`, `vendor/`
- Archivos de entorno, logs, caches

---

## Verificación de ZIP

Antes de crear el release, verifica el contenido del ZIP:

```bash
# Verificar que README.md está incluido
unzip -l wp-api-creator.zip | grep README.md

# Verificar que NO tiene archivos de desarrollo
unzip -l wp-api-creator.zip | grep -E "(node_modules|package\.json|tailwind\.config|tests/|phpunit\.xml|src/frontend/)"
# Debe retornar vacío

# Verificar que tiene los assets compilados
unzip -l wp-api-creator.zip | grep "build/index.js"

# Verificar que el dashboard empaquetado es el de esta versión y no el del commit
# anterior. `git archive` lee el commit, no el árbol de trabajo.
unzip -p wp-api-creator.zip build/index.js | grep -c "Ya la he guardado en un lugar seguro"
# Debe retornar 1 en 1.1.0
```

---

## Actualizaciones Automáticas en Sitios WordPress

### Opción Recomendada: GitHub Updater

1. Instalar el plugin [GitHub Updater](https://github.com/afragen/github-updater) en cada sitio
2. Agregar en `wp-api-creator.php`:

```php
/**
 * Plugin Name: WP Custom API Creator
 * Version:     1.0.0
 * ...
 * GitHub Plugin URI: dmaurelc/wp-api-creator
 * Primary Branch: main
 */
```

Con esto, WordPress detectará automáticamente las nuevas versiones desde GitHub Releases.

---

## Estructura del Repositorio

```
wp-api-creator/
├── .git/                    # Repo Git (local, no en ZIP)
├── .github/                 # Configuración GitHub (no en ZIP)
├── .gitattributes           # Control de archivos en ZIP
├── .gitignore               # Archivos ignorados por Git
├── .distignore              # Exclusiones de distribución
├── wp-api-creator.php       # Archivo principal
├── README.md                # Documentación (incluido en ZIP)
├── package.json             # Configuración NPM (no en ZIP)
├── composer.json            # Autoloading PHP (no en ZIP)
├── build/                   # Assets compilados (en ZIP)
├── src/                     # Código fuente
│   ├── Admin/               # (en ZIP)
│   ├── Api/                 # (en ZIP)
│   └── frontend/            # Fuente JS/SCSS (NO en ZIP)
└── docs/                    # Documentación técnica (no en ZIP)
```

---

## Recursos

- **Repositorio**: https://github.com/dmaurelc/wp-api-creator
- **Releases**: https://github.com/dmaurelc/wp-api-creator/releases
- **GitHub CLI Docs**: https://cli.github.com/manual/gh_release
