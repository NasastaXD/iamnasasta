# CEAD Académico — Plugin de WordPress

Plugin modular para el portal del **Centro Educativo de Alto Desempeño "Félix de Guarania"** (CEAD). Convive con el tema `cead-theme` (mismo repo) y reutiliza su estética (paleta, tipografías y componentes).

## v0.8.0 — XLSX + invitaciones robustas

- **Importadores aceptan .xlsx** además de .csv (lector liviano propio con ZipArchive + SimpleXML, sin dependencia de 15MB; cae con gracia a solo .csv si falta la extensión zip).
- **Plantilla de alumnos simplificada**: formato mínimo **Alumno | Curso | Mail**. Email **opcional por fila** — si falta, el alumno igual se importa (email interno placeholder). Dedup por documento → email → (nombre + curso).
- **Invitaciones reescritas**: procesamiento inline (sin rebote a admin-post.php), link copiable siempre visible, envío de email, selector/autocompletado de usuarios registrados, botón reenviar.

## Estado: Fase 5 — Paneles de rol (Delegado · Secretaría · Dirección)

### Fase 5 — añadido en esta versión

- **CPT `cead_acad_task`** con metabox (curso asignado, estado, prioridad, fecha de vencimiento). Estados: pendiente / en curso / hecha / cancelada.
- **`/panel/delegado`**: dashboard del delegado/a con tareas pendientes y completadas. Botones "Marcar en curso" y "Marcar hecha". Permisos: dirección/secretaría siempre; delegado solo del curso al que pertenece.
- **`/panel/secretaria`**: hub con conteos (invitaciones vigentes, cursos, alumnado, borradores), importaciones recientes y accesos rápidos.
- **`/panel/direccion`**: tablero de métricas — comunidad (alumnos, delegados, docentes, cursos, inscripciones activas), contenido publicado (comunicados/encuestas/eventos/recursos), engagement (tasa de lectura del último comunicado, tasa de respuesta de la última encuesta) y próximos eventos.
- Cada panel está cap-gated y se muestra en sidebar solo cuando aplica al rol del usuario.

### Fase 4 — Importadores + Calificaciones (CSV)

### Fase 4 — añadido en esta versión

- **Importador CSV** con flujo guiado de 3 pasos: subir → mapear columnas → validar/confirmar.
- **Importador de alumnado**: crea `wp_users` con rol `cead_acad_student`, los suma al roster del curso si el título existe. Idempotente por documento (CI) o email.
- **Importador de calificaciones**: idempotente por (alumno, materia, periodo). Crea términos de materia automáticamente si no existen.
- **Tabla `cead_acad_grades`** con unique key compuesto y referencia al `import_job_id` para trazabilidad.
- **Tabla `cead_acad_import_jobs`**: rastrea cada importación (mapping, reporte, contadores, autor, timestamps).
- **Mapeo de columnas automático** por similitud (`similar_text`) — al cargar el archivo el sistema sugiere la mejor coincidencia.
- **Sniff de delimitador** (`,`, `;`, `\t`, `|`).
- **Boletín del alumno** en `/panel/boletin`: tabla por curso, materia × periodo.
- **Plantillas CSV descargables** para alumnado y calificaciones.
- **Reporte de errores** descargable (CSV) por job.

XLSX/.xlsx pendiente para una próxima entrega con PhpSpreadsheet bundleado.

### Fase 3 — Horarios + Recursos

### Fase 3 — añadido en esta versión

- **CPT `cead_acad_event`** con metabox de fechas (inicio/fin/todo el día), tipo (clase/reunión/examen/evento) y lugar.
- **Targeting reutiliza** la tabla `cead_acad_audiences` (subject_type='event').
- **Frontend `/panel/horarios`**: agenda agrupada por día, próximos 60 días, código de color por tipo de evento.
- **Frontend `/panel/horarios/{id}`**: detalle con permisos verificados.
- **Export iCal** (.ics) por usuario: descarga sus eventos respetando audiencia.
- **CPT `cead_acad_resource`** con metabox de archivo adjunto (Media Library) o URL externa.
- **Taxonomías**: materias y tipos (mapa conceptual / PDF / enlace / imagen / video, seedeadas).
- **Targeting reutiliza** la tabla `cead_acad_audiences` (subject_type='resource').
- **Frontend `/panel/recursos`**: biblioteca con búsqueda + filtros por materia y tipo, grilla con thumbnails.
- **Frontend `/panel/recursos/{id}`**: detalle con botón "Abrir recurso".

### Fase 2 — Encuestas

### Fase 2 — añadido en esta versión

- **CPT `cead_acad_survey`** con metabox de configuración (ventana de fechas, modo anónimo) y **builder de preguntas** inline (drag-up/down, agregar/quitar dinámicamente).
- **Tipos de pregunta**: opción única, opción múltiple, texto corto, texto largo, escala numérica configurable (min/max 1-10), fecha.
- **Tablas custom**: `wp_cead_acad_survey_questions`, `wp_cead_acad_survey_responses` (unique survey/user para nominales), `wp_cead_acad_survey_answers`.
- **Targeting reutiliza** la tabla `cead_acad_audiences` de F1 (subject_type='survey').
- **Frontend** `/panel/encuestas`: pendientes / respondidas / cerradas.
- **Tomar encuesta** `/panel/encuestas/{id}`: validación de requeridas en cliente y servidor; encuestas anónimas vs nominales.
- **Export CSV** de respuestas desde wp-admin (botón en metabox y en la lista de encuestas). Incluye guard de CSV injection y BOM UTF-8 para Excel.
- Sidebar del panel actualizado con item "Encuestas".

### Fase 1 — Cursos + Comunicados

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
