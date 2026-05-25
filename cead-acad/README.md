# CEAD Académico — Plugin de WordPress

Plugin modular para el portal del **Centro Educativo de Alto Desempeño "Félix de Guarania"** (CEAD). Convive con el tema `cead-theme` (mismo repo) y reutiliza su estética (paleta, tipografías y componentes).

## Estado actual: Fase 0 — Fundación

Implementado en esta fase:

- **Roles custom**: Dirección, Secretaría, Docente, Delegado, Alumno/a, Familia (con caps específicas del plugin).
- **Invitaciones por link**: la dirección genera tokens desde *wp-admin → CEAD Académico → Invitaciones*. Cada link permite registrar una sola cuenta con rol predefinido.
- **Login custom estilizado**: `/login` con la identidad visual del tema (no usa `wp-login.php`).
- **Registro por invitación**: `/registro?t=<token>`.
- **Recuperación de contraseña**: `/recuperar` y `/recuperar/restablecer`.
- **Panel stub**: `/panel` muestra una bienvenida y placeholders de los módulos por venir.
- **Bloqueo opcional** de `wp-login.php` para roles del plugin (redirige a `/login`).
- Rate limiting básico, tokens hasheados (sha256), nonces en todos los formularios.

## Instalación

1. Clonar este repo en `wp-content/plugins/iamnasasta/` o copiar la carpeta `cead-acad/` directamente a `wp-content/plugins/`.
2. Activar **CEAD Académico** en *wp-admin → Plugins*.
3. (Recomendado) Activar el tema `cead` para que el plugin herede colores y fuentes.

Al activar:
- Se crean las tablas `wp_cead_acad_invitations` y `wp_cead_acad_audit_log`.
- Se registran los 6 roles custom.
- Se programa un flush de rewrites para la próxima carga.
- Se crea `wp-content/uploads/cead-acad/` con `.htaccess deny` (preparado para importadores en Fase 4).

## Probar el flujo (5 minutos)

1. *wp-admin → CEAD Académico → Invitaciones → Nueva*.
2. Elegir rol (ej. Alumno/a) y "Cantidad: 1". **Generar invitaciones**.
3. Copiar el link rojo que aparece (solo se muestra UNA vez).
4. Abrir el link en una ventana de incógnito.
5. Completar el formulario de registro → caés directamente en `/panel`.
6. Probar `/salir` y volver a `/login` con esas credenciales.

## Configuración

Aún no hay pantalla de Settings. Variables que se pueden setear vía `update_option()`:

- `cead_acad_block_wp_login` (default `1`): si es `1`, los accesos a `wp-login.php` que no sean admins se redirigen a `/login`.

Constante opcional en `wp-config.php`:

- `CEAD_ACAD_HARD_UNINSTALL` = `true`: si está activa al desinstalar el plugin, borra tablas y roles. Por defecto, **NO** borra nada (más seguro).

## Estética

El plugin **no** redefine fuentes ni paleta si el tema CEAD está activo. Detecta la presencia de `cead-main` (handle del tema) y, si falta, encola Google Fonts (Anton, Cormorant Garamond, Mulish) por su cuenta y aplica los hex de fallback.

Componentes nuevos del plugin llevan prefijo `.cead-acad-*` (botón, card, grid, pill, msg, form, etc.) y se ven naturalmente alineados con `.cead-btn`, `.section`, `.eyebrow` del tema.

## Próximas fases (resumen)

- **F1**: Cursos + Roster + Comunicados (CPT, audiencias polimórficas, read receipts).
- **F2**: Encuestas (builder, respuestas en tabla custom, resultados con export).
- **F3**: Horarios (FullCalendar + iCal) y Recursos pedagógicos.
- **F4**: Importadores CSV/XLSX (PhpSpreadsheet) + Calificaciones y boletines.
- **F5**: Dashboards de Delegado, Secretaría y Dirección.

Plan detallado en `/root/.claude/plans/vas-a-trabajar-en-deep-noodle.md` (escrito en la sesión que generó la Fase 0).

## Estructura

```
cead-acad/
├── cead-acad.php            ← bootstrap (header WP, requires)
├── uninstall.php            ← borrado controlado por constante
├── README.md
├── includes/
│   ├── class-cead-acad-plugin.php
│   ├── class-cead-acad-activator.php
│   ├── class-cead-acad-deactivator.php
│   ├── class-cead-acad-capabilities.php
│   ├── class-cead-acad-rewrites.php
│   ├── class-cead-acad-template-loader.php
│   ├── class-cead-acad-assets.php
│   └── helpers.php
├── modules/auth/
│   ├── class-invitations.php
│   ├── class-auth-controller.php
│   └── class-password-reset.php
├── admin/
│   ├── class-admin-menu.php
│   └── views/invitations-list.php
├── templates/
│   ├── auth/{login,register,recover,recover-reset}.php
│   ├── auth/partials/auth-shell.php
│   └── panel/home.php
├── assets/
│   ├── css/cead-acad-frontend.css
│   ├── css/cead-acad-admin.css
│   └── js/cead-acad-frontend.js
└── languages/
    └── cead-acad.pot (pendiente)
```

## Override de templates desde el tema

Cualquier template del plugin se puede sobrescribir desde el tema activo poniéndolo en `wp-content/themes/<tema>/cead-acad/<ruta-relativa>.php`. El loader busca primero ahí; si no encuentra, usa el del plugin.

## Convenciones

- Prefijo funciones: `cead_acad_*` (no chocar con `cead_*` del tema).
- Tablas: `{$wpdb->prefix}cead_acad_*`.
- Meta keys privadas: `_cead_acad_*`.
- Clases CSS: `cead-acad-*`. Se reutilizan `.cead-btn`, `.section`, `.eyebrow`, `.container` del tema cuando aplican.
- Text-domain: `cead-acad`.
- PHP 8.1+, WordPress 6.4+.

## Seguridad

- Tokens de invitación: 48 chars hex generados con `random_bytes`, almacenados solo como sha256 hash. No se pueden recuperar después de mostrar.
- Nonces en todos los formularios.
- Rate limiting (transients por IP) en login, registro y recuperación.
- Sanitización contextual y `$wpdb->prepare` en todas las queries.
