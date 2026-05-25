# CEAD Académico — Plugin de WordPress

Plugin modular para el portal del **Centro Educativo de Alto Desempeño "Félix de Guarania"** (CEAD). Convive con el tema `cead-theme` (mismo repo) y reutiliza su estética (paleta, tipografías y componentes).

## Estado actual: Fase 1 — Cursos + Comunicados

### Fase 0 (incluida) — Fundación

- **Roles custom**: Dirección, Secretaría, Docente, Delegado, Alumno/a, Familia.
- **Invitaciones por link**: tokens hasheados, expiración, single-use.
- **Login/registro/recuperación** custom estilizados (no `wp-login.php`).
- **Panel** con sidebar y topbar (campanita de no-leídos, user chip).
- **Bloqueo opcional** de `wp-login.php` para roles del plugin.

### Fase 1 — añadido en esta versión

- **CPT `cead_acad_course`** con taxonomías `cohort` (año lectivo) y `grade_level`.
- **Metabox de curso**: turno (mañana/tarde/noche), división vinculada al tema, delegado/a, tutor/a.
- **Roster** (tabla `wp_cead_acad_roster`): relación user ↔ curso con `role_in_course` (student/delegate/teacher) e idempotente.
- **CPT `cead_acad_broadcast`** con taxonomía de categorías (académico/administrativo/eventos/urgente).
- **Tabla `cead_acad_audiences` polimórfica**: `subject_type` (broadcast/survey/event) × `audience_type` (all/role/course/cohort/user). Diseñada para reuso en F2 y F3.
- **Tabla `cead_acad_broadcast_reads`** con unique (broadcast_id, user_id) — read receipts idempotentes.
- **Feed `/panel/comunicados`** filtrado por audiencia del usuario, paginado, con filtro por categoría.
- **Single `/panel/comunicados/{id}`** marca como leído automáticamente al abrir.
- **Notificación opcional por email** al publicar un comunicado.
- Home del panel renovado: dashboard contextual con tarjetas (no-leídos, cursos, próximas fases) y los 3 comunicados más recientes.

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
