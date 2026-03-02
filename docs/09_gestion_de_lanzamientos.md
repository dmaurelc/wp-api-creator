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

### Paso 3: Commit de Cambios

```bash
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

## Archivos de Configuración de Distribución

### .gitattributes

Define qué archivos se incluyen/excluyen del ZIP generado por `git archive`.

**Archivos excluidos del ZIP**:
- `node_modules/`, `vendor/`
- `src/frontend/` (fuente JS/SCSS)
- `package.json`, `composer.json`, `*.config.js`
- `docs/`, `.github/`
- Archivos `.md` excepto `README.md`
- Archivos de prueba (`test-cpt-meta.php`, etc.)

### .distignore

Usado por herramientas de WordPress que leen este archivo. Contiene las mismas exclusiones que `.gitattributes`.

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
unzip -l wp-api-creator.zip | grep -E "(node_modules|package\.json|tailwind\.config)"
# Debe retornar vacío

# Verificar que tiene los assets compilados
unzip -l wp-api-creator.zip | grep "build/index.js"
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
