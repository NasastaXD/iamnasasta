# Imágenes de la wiki

Acá viven las imágenes que usan las wikis (`docs/WIKI-*.md`) y que se sirven en
`/wiki` del sitio.

- Los archivos `*.svg` actuales son **ilustraciones** del panel hechas a mano en
  la estética CEAD (no son capturas reales).
- Para reemplazarlas por **capturas reales**: subí un PNG/JPG/SVG a esta carpeta
  y referencialo desde el Markdown como `img/<archivo>` (ruta relativa a `docs/`).
  El render de `/wiki` resuelve esa ruta a la URL del plugin automáticamente.

Ejemplo en Markdown:

```markdown
![Panel de inicio](img/panel-inicio.png)
*Ilustración del panel de inicio.*
```

> Si subís capturas reales, conviene mantener un ancho ~900px y evitar datos
> personales reales de alumnos en las imágenes.
