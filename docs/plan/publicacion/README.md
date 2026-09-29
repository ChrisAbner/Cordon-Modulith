# Publicación de la v0.1.0

Material listo para pegar. Los pasos generales y su orden están en [../siguientes-pasos.md](../siguientes-pasos.md); aquí solo el recorrido de clics.

| Archivo | Para qué sirve |
|---|---|
| [pr-main.md](pr-main.md) | Título y descripción (en inglés) del pull request de la rama a `main`. |
| [release-v0.1.0.md](release-v0.1.0.md) | Título y notas (en inglés) del GitHub Release. |
| [../lanzamiento/](../lanzamiento/README.md) | Borradores de RFC, Laravel News, Reddit, X, artículos, mantenedores y CFP (ya con enlaces reales). |
| [../video.md](../video.md) | Guion del vídeo de 3 minutos. |

Orden recomendado: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8. Antes del paso 6 cambia en `CHANGELOG.md` `## [Unreleased] - 0.1.0` por `## [0.1.0] - AAAA-MM-DD` (puede ir en el propio PR).

## 1. Abrir el pull request

1. GitHub → repo `ChrisAbner/Cordon-Modulith` → pestaña **Pull requests** → **New pull request**.
2. base: `main`, compare: `claude/cordon-modulith-naming-1w2srv` → **Create pull request**.
3. Pega el título y la descripción de [pr-main.md](pr-main.md).
4. Espera a que pasen `tests`, `quality` y `benchmark` (primera vez que corre el CI). Corrige lo que falle en la rama.
5. **Merge pull request** (mejor "Create a merge commit" para conservar los 14 commits).

## 2. Hacer público el repositorio

Settings → General → bajar a **Danger Zone** → **Change repository visibility** → **Change to public** → confirma escribiendo el nombre del repo. Hazlo antes de Pages (en el plan gratuito Pages exige repo público) y de Packagist.

## 3. Activar GitHub Pages

Settings → **Pages** → **Build and deployment** → **Source: GitHub Actions**. Después lanza el workflow: Actions → **docs** → **Run workflow** (rama `main`), o haz push de cambios en `docs-site/`. Sitio esperado: https://chrisabner.github.io/Cordon-Modulith/

## 4. Activar Discussions

Settings → General → sección **Features** → marca **Discussions**. Luego pestaña **Discussions** → **New discussion** → categoría **Ideas** → pega el RFC de [../lanzamiento/01-rfc-discussions.md](../lanzamiento/01-rfc-discussions.md).

## 5. Crear el tag y el release

1. En local, sobre `main` actualizado:

   ```bash
   git checkout main && git pull
   git tag v0.1.0
   git push origin v0.1.0
   ```

2. GitHub → **Releases** → **Draft a new release** → **Choose a tag**: `v0.1.0` → Release title: `v0.1.0`.
3. Pega las notas de [release-v0.1.0.md](release-v0.1.0.md), marca **Set as a pre-release** → **Publish release**.

## 6. Enviar el paquete a Packagist

1. Inicia sesión en [packagist.org](https://packagist.org) con GitHub (**Log in with GitHub**).
2. **Submit** → https://packagist.org/packages/submit → Repository URL: `https://github.com/ChrisAbner/Cordon-Modulith` → **Check** → **Submit**.
3. Comprueba que el paquete es `chrisabner/cordon-modulith` y que aparece la versión `v0.1.0`.

## 7. Webhook de actualización automática

Al iniciar sesión con GitHub, Packagist instala su integración. Verifica en Packagist → tu paquete → si aparece el aviso "This package is not auto-updated", pulsa **Enable GitHub Hook** (o **Update** en tu perfil → Settings → **Connect GitHub**). Comprobación: en GitHub → Settings → **Webhooks** debe existir uno de `packagist.org`.

Luego prueba en un proyecto limpio: `composer require --dev chrisabner/cordon-modulith`.

## 8. Opcional: GitHub Marketplace (la acción)

1. Repo → **Releases** → **Draft a new release** (o edita el de `v0.1.0`).
2. Marca **Publish this Action to the GitHub Marketplace** (aparece porque existe `action.yml` en la raíz; acepta el acuerdo de distribución si te lo pide).
3. Elige categoría (por ejemplo *Code quality*) y publica. `action.yml` ya define nombre, icono y color.

Después, sigue con los materiales de [../lanzamiento/README.md](../lanzamiento/README.md).
