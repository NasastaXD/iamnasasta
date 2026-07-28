# CEAD Académico — Plugin de WordPress

Plugin modular para el portal del **Centro Educativo de Alto Desempeño "Félix de Guarania"** (CEAD). Convive con el tema `cead-theme` (mismo repo) y gestiona toda la operación académica y administrativa de la institución: alumnado, cursos, comunicados, encuestas, horarios, recursos, calificaciones, tareas y un bot de WhatsApp integrado.

- **Versión:** 0.37.0 · **DB Version:** 17
- **Requiere:** WordPress 6.4+ · PHP 8.1+
- **Rama principal:** `main`
- **Documentación de usuario y técnica:** ver `docs/WIKI-USUARIO.md` y `docs/WIKI-TECNICA.md` (online en `/wiki`).

---

## Índice

1. [¿Qué hace el plugin?](#qué-hace-el-plugin)
2. [Instalación](#instalación)
3. [Inicio rápido (5 minutos)](#inicio-rápido)
4. [Roles y permisos](#roles-y-permisos)
5. [Panel frontend `/panel`](#panel-frontend)
6. [Módulo: Auth (Login, Registro, Invitaciones)](#módulo-auth)
7. [Módulo: Gestión de Usuarios (wp-admin)](#módulo-gestión-de-usuarios)
8. [Módulo: Cursos y Roster](#módulo-cursos-y-roster)
9. [Módulo: Comunicados](#módulo-comunicados)
10. [Módulo: Encuestas](#módulo-encuestas)
11. [Módulo: Horarios y Eventos](#módulo-horarios-y-eventos)
12. [Módulo: Recursos](#módulo-recursos)
13. [Módulo: Calificaciones y Boletín](#módulo-calificaciones-y-boletín)
14. [Módulo: Tareas (Delegado)](#módulo-tareas-delegado)
15. [Módulo: Importadores CSV/XLSX](#módulo-importadores)
16. [Módulo: Bot de WhatsApp](#módulo-bot-de-whatsapp)
17. [Tablas de base de datos](#tablas-de-base-de-datos)
18. [REST API](#rest-api)
19. [Cron (tareas programadas)](#cron)
20. [Configuración y opciones](#configuración-y-opciones)
21. [User meta keys](#user-meta-keys)
22. [Seguridad](#seguridad)
23. [Estética y tema](#estética-y-tema)
24. [Estructura de archivos](#estructura-de-archivos)
25. [Convenciones para desarrolladores](#convenciones-para-desarrolladores)
26. [Extender el bot de WhatsApp](#extender-el-bot-de-whatsapp)

---

## ¿Qué hace el plugin?

| Área | Qué resuelve |
|------|-------------|
| **Auth** | Login/registro estilizados, recuperación de contraseña, invitaciones por link con token seguro |
| **Usuarios** | Gestión manual de usuarios desde wp-admin: crear, asignar rol, teléfono |
| **Cursos** | CPT de cursos con turno/división, roster alumnado/delegado/docente, taxonomías cohorte y grado |
| **Comunicados** | Broadcasts con audiencia polimórfica (rol / curso / cohorte / usuario / todos), read receipts, feed personalizado |
| **Encuestas** | Builder de preguntas (6 tipos), ventana de fechas, modo anónimo, export CSV |
| **Horarios** | Eventos con fechas, tipos y lugar; targeting por audiencia; export iCal |
| **Recursos** | Archivos y URLs pedagógicas, taxonomías de materia y tipo, ACL por audiencia |
| **Calificaciones** | Tabla por alumno × materia × periodo, boletín en el panel |
| **Tareas** | CPT de tareas para delegados con estado, prioridad y fecha de vencimiento |
| **Importadores** | Subida guiada de CSV/XLSX para alumnado, calificaciones, cursos y eventos |
| **WhatsApp Bot** | Bot conversacional completo para alumnos y staff, broadcasting masivo, reportes cifrados |

---

## Instalación

```bash
# Opción A — Clonar el repo
cd wp-content/plugins
git clone https://github.com/nasastaxd/iamnasasta iamnasasta
# → el plugin queda en wp-content/plugins/iamnasasta/cead-acad/

# Opción B — Solo el plugin
cp -r cead-acad/ wp-content/plugins/cead-acad/
```

1. Activar **CEAD Académico** en *wp-admin → Plugins*.
2. Ir a *Ajustes → Enlaces permanentes* y guardar una vez (activa las rutas `/panel`, `/login`, etc.).
3. (Opcional pero recomendado) Activar el tema `cead` para heredar paleta y fuentes.

**Al activar el plugin se crean automáticamente:**
- 18 tablas `wp_cead_acad_*` (ver [Tablas de base de datos](#tablas-de-base-de-datos))
- 7 roles custom con sus capabilities
- Términos de taxonomía predeterminados (categorías de comunicados, tipos de recurso)
- Opciones de configuración del bot de WhatsApp
- Directorio `wp-content/uploads/cead-acad/` protegido con `.htaccess`

**Variable de entorno (opcional en `wp-config.php`):**
```php
define( 'CEAD_ACAD_HARD_UNINSTALL', true );
// Si está activa al desinstalar el plugin, borra tablas y roles.
// Por defecto NO borra nada (más seguro).
```

---

## Inicio rápido

### Agregar el primer usuario (vía wp-admin)

1. *wp-admin → CEAD Académico → Usuarios → Agregar usuario*
2. Completar: nombre, usuario, email (opcional), teléfono, rol
3. La contraseña se genera automáticamente y se muestra **una sola vez** — anotarla y dársela al usuario

### Agregar el primer usuario (vía invitación)

1. *wp-admin → CEAD Académico → Invitaciones → Nueva invitación*
2. Elegir rol y cantidad → **Generar**
3. Copiar el link (se muestra una sola vez), enviárselo al usuario
4. El usuario entra al link, completa nombre/usuario/email/teléfono/contraseña → queda en `/panel`

### Registrar tu propio número de WhatsApp

Para que el bot de WhatsApp te reconozca como director/a:

```
wp-admin → CEAD Académico → Usuarios → (tu usuario) → Editar
→ Campo "Teléfono (WhatsApp)" → 595981123456 → Guardar cambios
```

O vía WP-CLI:
```bash
wp user meta update <ID> _cead_acad_phone 595981123456
```

---

## Roles y permisos

### Roles instalados

| Slug | Nombre | Acceso típico |
|------|--------|---------------|
| `cead_acad_direction` | Dirección | Todo: invitaciones, cursos, comunicados (todos), encuestas, horarios, recursos, importación, calificaciones, reportes, artículos, roles, métricas |
| `cead_acad_secretary` | Secretaría | Igual a Dirección excepto `manage_roles` |
| `cead_acad_teacher` | Docente | Comunicados, encuestas, horarios, recursos, calificaciones |
| `cead_acad_delegate` | Delegado/a | Subir recursos, gestionar tareas del curso, ver sus notas |
| `cead_acad_student` | Alumno/a | Ver sus notas, panel, encuestas, recursos, comunicados dirigidos |
| `cead_acad_guardian` | Familia | Ver notas de su hijo/a |
| `cead_acad_student_council` | Consejo Estudiantil | Publicar comunicados, gestionar reportes |

### Capabilities custom

Todas con prefijo `cead_acad_`:

| Capability | Quién la tiene |
|-----------|----------------|
| `view_panel` | Todos los roles del plugin |
| `manage_invitations` | Dirección, Secretaría |
| `manage_courses` | Dirección, Secretaría |
| `assign_delegate` | Dirección |
| `publish_broadcast` | Dirección, Secretaría, Docente, Consejo |
| `publish_broadcast_all` | Dirección, Secretaría |
| `create_survey` | Dirección, Secretaría, Docente |
| `view_survey_results` | Dirección |
| `manage_schedule` | Dirección, Secretaría, Docente |
| `upload_resource` | Dirección, Secretaría, Docente, Delegado |
| `import_data` | Dirección, Secretaría |
| `view_metrics` | Dirección |
| `assign_tasks` | Dirección, Secretaría |
| `record_grade` | Dirección, Secretaría, Docente |
| `view_course_grades` | Dirección, Secretaría, Docente |
| `view_own_grades` | Alumno, Delegado, Familia |
| `manage_reports` | Dirección, Secretaría, Consejo |
| `manage_articles` | Dirección, Secretaría |
| `manage_roles` | Solo Dirección |
| `complete_delegate_task` | Delegado |

> Los administradores de WordPress (`manage_options`) reciben **todas** las caps `cead_acad_*` automáticamente vía filtro `user_has_cap`, sin necesidad de tener un rol custom.

---

## Panel frontend

Rutas disponibles luego de activar el plugin y refrescar permalink:

| URL | Acceso | Descripción |
|-----|--------|-------------|
| `/login` | Público | Formulario de login estilizado |
| `/registro?t=<token>` | Público (con token) | Crear cuenta a partir de invitación |
| `/recuperar` | Público | Solicitar reset de contraseña |
| `/recuperar/restablecer` | Público (con token de email) | Ingresar nueva contraseña |
| `/salir` | Logueado | Cerrar sesión y redirigir a `/login` |
| `/panel` | `view_panel` | Dashboard: comunicados recientes, cursos, novedades |
| `/panel/comunicados` | `view_panel` | Feed de comunicados del usuario (filtrado por audiencia) |
| `/panel/comunicados/{id}` | `view_panel` + audiencia | Ver y marcar como leído un comunicado |
| `/panel/encuestas` | Logueado | Encuestas pendientes / respondidas / cerradas |
| `/panel/encuestas/{id}` | Logueado | Responder encuesta |
| `/panel/horarios` | Logueado | Agenda de eventos próximos (60 días) |
| `/panel/horarios/{id}` | Logueado | Detalle de evento |
| `/panel/recursos` | Logueado | Biblioteca de recursos con filtros |
| `/panel/recursos/{id}` | Logueado + audiencia | Detalle y descarga de recurso |
| `/panel/boletin` | `view_own_grades` | Boletín de calificaciones por periodo |
| `/panel/delegado` | Delegado/a | Dashboard de tareas del delegado |
| `/panel/secretaria` | Secretaría | Hub administrativo |
| `/panel/direccion` | Dirección | Tablero de métricas de la institución |

**Bloqueo de `wp-login.php`:** activado por defecto. Los usuarios del plugin que intenten entrar por `wp-login.php` son redirigidos a `/login`. Se puede desactivar con `update_option('cead_acad_block_wp_login', 0)`.

**Override de templates:** cualquier template puede ser reemplazado desde el tema activo colocándolo en `wp-content/themes/<tema>/cead-acad/<ruta-relativa>.php`.

---

## Módulo: Auth

**Archivos:** `modules/auth/class-invitations.php`, `class-auth-controller.php`, `class-password-reset.php`

### Invitaciones

- Tokens de 48 chars hex generados con `random_bytes()`, almacenados solo como SHA-256 hash
- Vinculan rol y curso al usuario que se registra
- **Multiuso**: un mismo link puede reutilizarse `max_uses` veces (campo "Usos del link"); el consumo es atómico (`consume()`), sin pasarse del tope aunque varios se registren a la vez. `max_uses = 1` = link de un solo uso
- Expiración configurable (1–3650 días); **default según el rol**: Delegado/a 365 (1 año), resto 1095 (~3 años)
- Envío automático por email si se especifica dirección
- Estados: válida / usada (tope alcanzado) / expirada / revocada. El listado muestra `usos: N/M`

**Gestión:** *wp-admin → CEAD Académico → Invitaciones*

### Registro

Formulario en `/registro?t=<token>` con campos:
- Nombre y apellido
- Nombre de usuario (solo letras, números, `.`, `-`)
- Email
- **Teléfono (WhatsApp)** — obligatorio, se guarda como `_cead_acad_phone`
- Contraseña (mínimo 8 caracteres)

### Recuperación de contraseña

Flujo en dos pasos: `/recuperar` (ingresa email → recibe link) → `/recuperar/restablecer` (ingresa nueva contraseña).

---

## Módulo: Gestión de Usuarios

**Archivos:** `admin/class-admin-menu.php`, `admin/views/users-list.php`

**Acceso:** *wp-admin → CEAD Académico → Usuarios*

Permite gestionar usuarios del plugin sin necesidad de WP-CLI ni plugins adicionales:

### Agregar usuario

Campos del formulario:
- Nombre completo (obligatorio)
- Usuario / login (obligatorio, sin espacios)
- Email (opcional)
- Teléfono/WhatsApp (opcional, recomendado)
- Rol (todos los roles del plugin)

La contraseña se genera automáticamente y se muestra **una sola vez** al crear.

### Tabla de usuarios

Muestra todos los usuarios con roles del plugin más administradores de WordPress. Columnas: nombre, usuario, email, rol, teléfono.

### Editar usuario

Botón "Editar" por cada fila → formulario con:
- Teléfono (editable)
- Rol (solo para usuarios con rol del plugin, no para admins WP)

> Al cambiar el rol, el sistema elimina el rol CEAD anterior y asigna el nuevo.

---

## Módulo: Cursos y Roster

**Archivos:** `modules/courses/class-courses-cpt.php`, `class-courses-roster.php`, `class-courses-admin.php`

### CPT `cead_acad_course`

Meta fields:
- `_cead_acad_turno` — mañana / tarde / noche
- `_cead_acad_division` — división vinculada al tema (A, B, etc.)
- `_cead_acad_delegate` — user_id del delegado/a
- `_cead_acad_tutor` — user_id del tutor/a docente

Taxonomías:
- `cead_acad_cohort` — año lectivo (ej. "2025")
- `cead_acad_grade_level` — año/grado (ej. "1er año")

### Roster (inscripciones)

Tabla `wp_cead_acad_roster` — relación usuario ↔ curso con rol en el curso:
- `student` — alumno/a
- `delegate` — delegado/a del curso
- `teacher` — docente asignado

Las inscripciones son idempotentes (UNIQUE user_id + course_id).

---

## Módulo: Comunicados

**Archivos:** `modules/broadcasts/class-broadcasts-cpt.php`, `class-broadcasts-audiences.php`, `class-broadcasts-targeting.php`, `class-broadcasts-reads.php`, `class-broadcasts-feed.php`

### CPT `cead_acad_broadcast`

Taxonomía `cead_acad_broadcast_category` con términos predeterminados: `academico`, `administrativo`, `eventos`, `urgente`.

### Audiencias polimórficas

Cada comunicado puede dirigirse a cualquier combinación de:
- `all` — toda la comunidad
- `role` — un rol específico (ej. solo docentes)
- `course` — alumnado de un curso
- `cohort` — alumnado de un año lectivo
- `user` — usuario individual

La tabla `wp_cead_acad_audiences` es compartida por comunicados, encuestas y eventos.

### Feed personalizado

`Cead_Acad_Broadcasts_Feed::for_user($uid)` devuelve los comunicados accesibles para un usuario según su rol, cursos y cohortes. Se paginan en `/panel/comunicados`.

### Read receipts

Al abrir `/panel/comunicados/{id}` se registra automáticamente en `wp_cead_acad_broadcast_reads`. La campanita de la topbar muestra el conteo de no-leídos.

---

## Módulo: Encuestas

**Archivos:** `modules/surveys/class-surveys-*.php`

### CPT `cead_acad_survey`

Meta fields:
- `_cead_acad_survey_opens_at` — inicio de la ventana (datetime)
- `_cead_acad_survey_closes_at` — cierre de la ventana (datetime)
- `_cead_acad_survey_anonymous` — modo anónimo (sin vincular respuesta al usuario)

### Tipos de pregunta

| Tipo | Descripción |
|------|-------------|
| `radio` | Opción única (lista de opciones) |
| `checkbox` | Selección múltiple |
| `text` | Texto corto (input) |
| `long_text` | Texto largo (textarea) |
| `scale` | Escala numérica configurable (min/max 1–10) |
| `date` | Fecha |

### Respuestas y export

- Encuestas nominales: UNIQUE (survey_id, user_id) — un voto por persona
- Encuestas anónimas: user_id = NULL, guardan IP hasheada
- Export CSV desde el metabox del admin: botón "Exportar respuestas"
- Guard anti CSV-injection + BOM UTF-8 para Excel

---

## Módulo: Horarios y Eventos

**Archivos:** `modules/schedule/class-schedule-cpt.php`, `class-schedule-admin.php`, `class-schedule-feed.php`

### CPT `cead_acad_event`

Meta fields:
- `_cead_acad_event_start` — inicio (DATETIME)
- `_cead_acad_event_end` — fin (DATETIME)
- `_cead_acad_event_all_day` — todo el día (boolean)
- `_cead_acad_event_type` — clase / reunion / examen / evento
- `_cead_acad_event_location` — lugar

### Frontend

`/panel/horarios` muestra agenda agrupada por día, código de color por tipo, próximos 60 días.

### Export iCal

`GET /panel/horarios?ical=1` — descarga `.ics` con los eventos del usuario (filtrado por audiencia).

---

## Módulo: Recursos

**Archivos:** `modules/resources/class-resources-cpt.php`, `class-resources-admin.php`, `class-resources-acl.php`

### CPT `cead_acad_resource`

Dos tipos de recurso:
- **Archivo adjunto** — sube a la biblioteca de medios de WP (`_cead_acad_resource_attachment_id`)
- **URL externa** — enlace a Drive, YouTube, etc. (`_cead_acad_resource_url`)

Taxonomías:
- `cead_acad_subject` — materia (creada automáticamente al importar)
- `cead_acad_resource_type` — mapa-conceptual / pdf / enlace / imagen / video

### ACL por audiencia

La clase `Cead_Acad_Resources_ACL` verifica que el usuario tenga acceso según la audiencia definida. `/panel/recursos/{id}` devuelve 404 si no corresponde.

---

## Módulo: Calificaciones y Boletín

**Archivos:** `modules/grades/class-grades-db.php`

**Tabla:** `wp_cead_acad_grades`

Schema: `(student_user_id, course_id, subject_term_id, cohort_term_id, period, score, letter, comments, recorded_by, import_job_id)`

UNIQUE key por `(student_user_id, course_id, subject_term_id, period)` — idempotente en importaciones repetidas.

**Frontend:** `/panel/boletin` muestra tabla por curso con materias como filas y periodos como columnas.

---

## Módulo: Tareas (Delegado)

**Archivos:** `modules/panels/class-tasks-cpt.php`

### CPT `cead_acad_task`

Meta fields:
- `_cead_acad_task_course` — curso al que pertenece la tarea
- `_cead_acad_task_status` — pendiente / en_curso / hecha / cancelada
- `_cead_acad_task_priority` — baja / normal / alta
- `_cead_acad_task_due_date` — fecha de vencimiento

**Frontend:** `/panel/delegado` muestra tareas pendientes y completadas del curso del delegado/a con botones de cambio de estado.

**Permisos:** Dirección/Secretaría ven todas. El delegado solo ve tareas de su curso.

---

## Módulo: Importadores

**Archivos:** `modules/importers/class-importer-*.php`

**Acceso:** *wp-admin → CEAD Académico → Importadores CSV*

Flujo de 3 pasos: **Subir** → **Mapear columnas** → **Validar y confirmar**

Formatos soportados: `.csv` (detecta delimitador `,` `;` `\t` `|`) y `.xlsx` (lector propio con ZipArchive + SimpleXML, sin dependencias).

El mapeo de columnas es automático por similitud (`similar_text`). Los archivos se guardan en `wp-content/uploads/cead-acad/` (protegido, acceso denegado por HTTP).

### Importador de Alumnado

| Campo CSV | Requerido | Descripción |
|-----------|-----------|-------------|
| `nombre` | Sí | Nombre completo |
| `curso` | No | Título del curso a inscribir |
| `email` | No | Email (placeholder si no hay) |
| `documento` | No | CI/DNI para dedup |
| `telefono` | No | Guardado como `_cead_acad_phone` |
| `fecha_nac` | No | Fecha de nacimiento |

Dedup: por documento → email → (nombre + curso). Idempotente: actualiza si ya existe.

### Importador de Calificaciones

| Campo CSV | Requerido |
|-----------|-----------|
| `documento` | Sí |
| `curso` | Sí |
| `materia` | Sí |
| `periodo` | Sí |
| `nota` | No |
| `letra` | No |
| `comentario` | No |

Crea términos de materia automáticamente. Idempotente por UNIQUE key.

### Importador de Cursos

Campos: `titulo` (req), `cohorte` (req), `grado`, `turno`, `division`, `descripcion`.

### Importador de Eventos

Campos: `titulo` (req), `inicio` (req, `YYYY-MM-DD HH:MM`), `fin`, `tipo`, `lugar`, `todo_el_dia`, `curso`, `descripcion`.

---

## Módulo: Bot de WhatsApp

**Archivos:** `modules/whatsapp/class-wa-*.php` (11 clases) + `bridge/index.js`

### Arquitectura

```
Bridge Baileys (PC del director/a, ejecuta Node.js)
        │  POST /wp-json/caag-bot/v1/incoming  (header X-Caag-Token)
        ▼
Cead_Acad_WA_REST  ─►  Cead_Acad_WA_Engine  (máquina de estados por teléfono)
                               │
          ┌────────────────────┼──────────────────────────┐
          ▼                    ▼                           ▼
    WA_Store             WA_Identity                Datos del plugin
    (tablas wa_*)        (phone → user_id)          (Schedule, Broadcasts,
          ▲                                          Courses, Audiences)
    WA_Broadcaster  ◄──  WA_Cron
          │
          ▼
    WA_Bridge_Client  ─►  POST {bridge_url}/api/send|send-image|status|restart|logout
```

### Reconocimiento de usuarios

`Cead_Acad_WA_Identity::resolve($phone)`:
- Normaliza el número a E.164 estricto (código de país configurable, default `595` Paraguay)
- Busca el usuario por meta `_cead_acad_phone` (búsqueda amplia LIKE + confirmación exacta)
- Retorna `user_id`, `is_student`, `is_staff`
- **Con match** → experiencia personalizada (horarios/comunicados del usuario)
- **Sin match** → modo general (eventos/comunicados con audiencia `all`)

Formatos de teléfono aceptados: `0981123456`, `981123456`, `595981123456`, `+595981123456`

### Menú inicial por rol

Si el número pertenece a un usuario con permisos de staff, el bot pregunta primero:
```
¿A qué menú querés entrar?
1. Estudiantes
2. Dirección  (o el rol del usuario)
0. Salir
```
Un alumno puro entra directamente al menú de alumnos.

### Funciones para alumnado

| Opción | Descripción |
|--------|-------------|
| **1 · Horarios** | Próximos eventos personalizados por curso |
| **2 · Sitio web** | Links configurables (sitio, panel) |
| **3 · Calendario** | Eventos próximos del calendario general |
| **4 · Contacto** | Info de contacto de dirección y secretaría |
| **5 · Comunicados** | Lista y lectura de comunicados del usuario |
| **6 · Reportar** | Reporte anónimo o confidencial (cifrado, código de seguimiento) |
| **7 · Sugerencias** | Enviar sugerencia anónima |
| **8 · FAQ** | Preguntas frecuentes configurables |
| **9 · Consejo Estudiantil** | Ver tablón del consejo + enviar propuestas |
| **A · Recordatorios** | Opt-in/opt-out para recordatorios de eventos |
| **P · Panel web** | Link a `/panel` (promoción del portal) |
| **BAJA** | Darse de baja de mensajes del bot |

### Funciones para personal

Cada acción está gateada por una capability de cead-acad:

| Acción | Capability | Descripción |
|--------|-----------|-------------|
| **Comunicados** | `publish_broadcast` | Redactar y enviar a alumnos/staff/todos. Soporta imagen. Programar para más tarde |
| **Eventos** | `manage_schedule` | Crear evento rápido (título + fecha, entiende «mañana a las 10») |
| **Cargar nota** | `record_grade` | Vía IA con aprobación: resuelve alumno/materia por nombre dentro del curso y guarda la calificación (idempotente). Docente limitado a sus cursos |
| **Ver notas del curso** | `view_course_grades` | Consulta de las notas ya cargadas, con filtro por materia/periodo |
| **Panorama** | `view_metrics` | Resumen de uso del bot y pendientes del buzón |
| **Invitaciones** | `manage_invitations` | Crear **un link de registro reutilizable** N veces y devolverlo. Vía IA con aprobación. **Solo** roles alumno/delegado/profe (nunca dirección/secretaría). Vencimiento por rol (delegado 1 año) |
| **Artículos** | `manage_articles` | Publicar / editar / borrar posts del blog de WordPress |
| **Reportes** | `manage_reports` | Bandeja de reportes con estados y notas |
| **Sugerencias** | `manage_reports` | Bandeja de sugerencias recibidas |
| **Roles** | `manage_roles` | Asignar rol a un número (crea el usuario si no existe) |
| **Métricas** | `view_metrics` | Estadísticas de uso del bot (30 días) |
| **Atajos** | — | Ver lista de atajos rápidos disponibles |

### Atajos rápidos (staff)

Se pueden usar en cualquier momento desde el chat, sin entrar al menú:

| Atajo | Resultado |
|-------|-----------|
| `-AA <texto>` | Crea y envía comunicado a **todos** al instante (req. `publish_broadcast_all`) |
| `-AE <texto>` | Abre flujo de evento rápido con ese título; pide fecha (req. `manage_schedule`) |

### Comunicados y sincronía con el panel web

Cualquier comunicado enviado desde el bot (chat o `-AA`) **también crea un `cead_acad_broadcast`** en WordPress con la audiencia correspondiente. Así aparece en `/panel/comunicados` y en la opción "Comunicados" del bot.

| Target del bot | Audiencia en el panel |
|---------------|----------------------|
| `students` | Roles alumno + delegado |
| `staff` | Roles dirección + secretaría + docente |
| `all` | Audiencia `all` |

### Imágenes

- El director puede enviar una imagen + caption en el flujo de comunicado
- El bot guarda la imagen en la biblioteca de medios de WordPress (como imagen destacada del comunicado)
- Al enviar masivo, la imagen se replica a toda la audiencia vía el endpoint `/api/send-image` del bridge

### Reportes anónimos/confidenciales

- Cuerpo cifrado con libsodium (o AES-256-GCM como fallback)
- Código de seguimiento `AAAA-####` para que el alumno pueda hacer follow-up
- Categorías configurables (bullying, seguridad, infraestructura, etc.)
- Opción de reenvío automático a un número responsable

### Programar comunicados

Desde el flujo de comunicado del chat, se puede elegir enviarlo "ahora" o "programado" (ingresa fecha y hora). Los programados se guardan en `wa_scheduled` y los dispara el cron cada 5 minutos.

### Envío masivo (broadcasting)

- Un job a la vez (no se encolan múltiples)
- Batch de 10 mensajes por ciclo de cron, con pausa de 1s entre mensajes
- Progreso visible en *wp-admin → CEAD Académico → WA · Comunicados*

### Configuración del bot

*wp-admin → CEAD Académico → Bot de WhatsApp*:

| Parámetro | Descripción |
|-----------|-------------|
| URL del bridge | Dónde está corriendo el servidor Node.js |
| Token compartido | Clave de autenticación entre WordPress y el bridge |
| Código de país | Default `595` (Paraguay) |
| Links del sitio | URL del sitio y del panel |
| Contactos | Teléfonos y emails visibles desde el bot |
| Categorías de reportes | Opciones del menú de reportes |
| FAQ | Preguntas y respuestas frecuentes |
| Tablón del consejo | Texto del consejo estudiantil |
| Días para recordatorios | Con cuánta anticipación avisar de eventos (default 1) |
| Número de reenvío de reportes | A dónde reenviar reportes recibidos |

### Mensajes del bot (plantillas)

*wp-admin → CEAD Académico → WA · Mensajes*

Más de 50 mensajes editables desde el panel, organizados por grupos:
- **menus** — textos de los menús de navegación
- **sistema** — bienvenida, error, no reconocido
- **info** — horarios, sitio, contacto
- **reportes** — confirmaciones y estados de reportes
- **sugerencias** — confirmación de sugerencias
- **recordatorios** — plantilla de recordatorio de eventos
- **staff** — prompts y confirmaciones del menú de staff
- **otros** — council, FAQ, panel web

Las variables `{name}`, `{count}`, `{events}`, `{run}`, etc. se reemplazan automáticamente.

### Bridge (Node.js)

**Archivo:** `bridge/index.js`

Servidor Node.js que usa la librería [Baileys](https://github.com/WhiskeySockets/Baileys) para conectarse a WhatsApp Web. Corre en la PC del director/a (o cualquier servidor) y se expone vía Cloudflare Tunnel.

Endpoints que expone el bridge:

| Endpoint | Método | Descripción |
|----------|--------|-------------|
| `/api/send` | POST | Enviar mensaje de texto a un número |
| `/api/send-image` | POST | Enviar imagen con caption |
| `/api/status` | GET | Estado de conexión + QR y código de vinculación si está desconectado |
| `/api/pair` | POST | Pide a WhatsApp un código de 8 caracteres para vincular por número (alternativa al QR) |
| `/api/restart` | POST | Reiniciar la sesión de Baileys |
| `/api/logout` | POST | Cerrar sesión de WhatsApp |

Todos requieren el header `X-Caag-Token` con el token compartido.

**Ver:** `bridge/INSTALACION.md` para la guía paso a paso de instalación del bridge.

---

## Tablas de base de datos

Todas con prefijo `wp_cead_acad_`. Se crean/actualizan idempotentemente con `dbDelta()` en cada activación o cuando sube `CEAD_ACAD_DB_VERSION`.

### Tablas del plugin

| Tabla | Descripción |
|-------|-------------|
| `invitations` | Tokens de invitación (hash SHA-256, email, rol, curso, expiración) |
| `audit_log` | Registro de acciones (usuario, acción, entidad, IP, timestamp) |
| `roster` | Inscripciones usuario ↔ curso con rol en el curso |
| `audiences` | Audiencias polimórficas (broadcast/survey/event × all/role/course/cohort/user) |
| `broadcast_reads` | Read receipts de comunicados (broadcast_id, user_id) |
| `survey_questions` | Preguntas de encuestas (tipo, texto, opciones JSON) |
| `survey_responses` | Respuestas a encuestas (usuario o anónimo) |
| `survey_answers` | Respuestas individuales por pregunta |
| `grades` | Calificaciones por (alumno, curso, materia, periodo) |
| `import_jobs` | Historial de importaciones con mapping y reporte JSON |

### Tablas del bot de WhatsApp

| Tabla | Descripción |
|-------|-------------|
| `wa_session` | Estado del bridge (URL, token, QR, conexión, número vinculado) — 1 fila |
| `wa_registry` | Registro de números (phone, user_id, nombre, opt_out, event_reminders) |
| `wa_state` | Estado de conversación por número (current_state, context_data JSON) |
| `wa_messages` | Plantillas de mensajes editables desde el panel |
| `wa_reports` | Reportes recibidos (cifrados, con código de seguimiento) |
| `wa_suggestions` | Sugerencias recibidas |
| `wa_scheduled` | Comunicados programados (message, target, run_at) |
| `wa_logs` | Historial de mensajes entrantes y salientes |

---

## REST API

### Namespace del bot: `caag-bot/v1`

Autenticación: header `X-Caag-Token` con el token configurado en *WhatsApp → Configuración*.

| Método | Ruta | Descripción |
|--------|------|-------------|
| `POST` | `/caag-bot/v1/incoming` | Recibe un mensaje del bridge (from, body, pushName, timestamp, media) |
| `GET` | `/caag-bot/v1/status` | Devuelve estado de la sesión (connected, number, qr) |
| `POST` | `/caag-bot/v1/update-bridge-url` | Actualiza la URL del bridge dinámicamente |

> El namespace `caag-bot/v1` es compatible con el plugin anterior `wp-caaguazu-bot`. **No actives ambos a la vez.**

### CPTs expuestos en WP REST API

Todos los CPTs del plugin (`cead_acad_course`, `cead_acad_broadcast`, `cead_acad_event`, `cead_acad_resource`, `cead_acad_task`) están accesibles en `/wp-json/wp/v2/<tipo>` respetando las capabilities de cada rol.

---

## Cron

Tareas programadas de WordPress:

| Hook | Frecuencia | Descripción |
|------|-----------|-------------|
| `cead_acad_wa_heartbeat` | Cada 1 minuto | Consulta estado del bridge y actualiza `wa_session` |
| `cead_acad_wa_broadcast` | Cada 5 minutos | Procesa un lote del job de broadcasting activo |
| `cead_acad_wa_scheduled` | Cada 5 minutos | Lanza comunicados programados cuya `run_at` ya pasó |
| `cead_acad_wa_reminders` | Diario | Envía recordatorios de eventos a quienes optaron in |
| `cead_acad_wa_gc` | Cada hora | Limpia estados de conversación viejos (>60 min) y logs antiguos (>90 días) |

Horarios personalizados registrados: `cead_acad_wa_minute` (60s) y `cead_acad_wa_5min` (300s).

---

## Configuración y opciones

| Opción (`wp_options`) | Default | Descripción |
|-----------------------|---------|-------------|
| `cead_acad_db_version` | `9` | Versión del schema; al subir se re-ejecuta `dbDelta` y el seed de mensajes |
| `cead_acad_block_wp_login` | `1` | Redirige `wp-login.php` a `/login` para usuarios del plugin |
| `cead_acad_flush_rewrites` | `0` | Flag para refrescar rewrite rules en el próximo `init` |
| `cead_acad_wa_country_code` | `595` | Código de país para normalización de teléfonos |
| `cead_acad_wa_site_links` | Array | Links del sitio y panel mostrados por el bot |
| `cead_acad_wa_contacts` | Array | Contactos institucionales |
| `cead_acad_wa_report_categories` | Array | Categorías del menú de reportes |
| `cead_acad_wa_faq` | Array | Preguntas frecuentes |
| `cead_acad_wa_council_board` | String | Texto del tablón del Consejo |
| `cead_acad_wa_reminder_days` | `1` | Días de anticipación para recordatorios |
| `cead_acad_wa_report_forward_number` | `''` | Número al que reenviar reportes recibidos |
| `cead_acad_wa_broadcast_job` | Object | Job activo de broadcasting (cursor, sent, failed, total, status) |

---

## User meta keys

Todas con prefijo `_cead_acad_`:

| Meta key | Quién la escribe | Descripción |
|----------|-----------------|-------------|
| `_cead_acad_legal_name` | Registro / importador / admin | Nombre completo legal |
| `_cead_acad_phone` | Registro / importador / admin / bot | Número de WhatsApp (E.164 sin `+`) |
| `_cead_acad_document_id` | Importador de alumnado | Número de CI/DNI |
| `_cead_acad_birthdate` | Importador de alumnado | Fecha de nacimiento (YYYY-MM-DD) |
| `_cead_acad_no_email` | Importador de alumnado | `1` si el usuario no tiene email real |
| `_cead_acad_invited_via` | Registro | ID de la invitación utilizada |
| `_cead_acad_current_course_id` | Registro / importador | ID del curso principal del usuario |
| `_cead_acad_imported_via_job` | Importadores | ID del job de importación que creó/actualizó al usuario |

---

## Seguridad

- **Tokens de invitación**: 48 chars hex de `random_bytes()`, almacenados solo como hash SHA-256. Una vez mostrado el token, no se puede recuperar.
- **Nonces** en todos los formularios de admin y frontend.
- **Rate limiting** por IP (transients): login 8/min, registro 5/min, acciones generales 5/min.
- **Sanitización contextual**: `sanitize_text_field`, `sanitize_email`, `sanitize_key`, `esc_*` en outputs.
- **`$wpdb->prepare()`** en todas las queries con parámetros de usuario.
- **Capability checks** antes de cada operación (no solo en routing).
- **ACL de recursos**: verificación de audiencia al acceder a recursos y comunicados individuales.
- **Teléfonos**: normalización estricta a E.164 + comparación exacta (no LIKE) para evitar falsos positivos.
- **Reportes**: cuerpo cifrado con libsodium (`sodium_crypto_secretbox`) o AES-256-GCM como fallback.
- **Archivos importados**: directorio con `.htaccess deny` para que no sean accesibles por HTTP.
- **Bloqueo de `wp-login.php`**: redirige a usuarios del plugin a la pantalla custom.

---

## Estética y tema

El plugin **no redefine** fuentes ni paleta si el tema CEAD (`cead-theme`) está activo.

- Detecta el handle CSS `cead-main`; si está presente, no encola Google Fonts propios.
- Componentes del plugin usan prefijo `.cead-acad-*` y son compatibles con `.cead-btn`, `.section`, `.eyebrow`, `.container` del tema.
- Si el tema no está activo, el plugin encola sus propios estilos de fallback (fuentes Anton, Cormorant Garamond, Mulish desde Google Fonts).

---

## Estructura de archivos

```
cead-acad/
├── cead-acad.php                         ← Bootstrap principal del plugin
├── uninstall.php                         ← Limpieza controlada (ver CEAD_ACAD_HARD_UNINSTALL)
├── README.md                             ← Este archivo
│
├── includes/
│   ├── class-cead-acad-plugin.php        ← Clase principal, arranca módulos
│   ├── class-cead-acad-activator.php     ← dbDelta, roles, seed de datos
│   ├── class-cead-acad-deactivator.php   ← Limpiar crons al desactivar
│   ├── class-cead-acad-capabilities.php  ← Roles y capabilities del plugin
│   ├── class-cead-acad-rewrites.php      ← Rutas frontend (/panel, /login, etc.)
│   ├── class-cead-acad-template-loader.php ← Carga templates con override de tema
│   ├── class-cead-acad-assets.php        ← Registro de CSS/JS
│   └── helpers.php                       ← Funciones globales (cead_acad_url, rate_limit, etc.)
│
├── admin/
│   ├── class-admin-menu.php              ← Menú wp-admin + páginas de Usuarios e Invitaciones
│   └── views/
│       ├── invitations-list.php          ← Vista: gestión de invitaciones
│       ├── users-list.php                ← Vista: gestión manual de usuarios
│       ├── importers-hub.php             ← Vista: hub de importadores
│       ├── importer-mapping.php          ← Vista: paso de mapeo de columnas
│       ├── importer-preview.php          ← Vista: paso de previsualización
│       └── importer-result.php           ← Vista: resultado de importación
│
├── modules/
│   ├── auth/
│   │   ├── class-invitations.php         ← Generación y validación de invitaciones
│   │   ├── class-auth-controller.php     ← Login, registro, validaciones
│   │   └── class-password-reset.php      ← Recuperación de contraseña
│   │
│   ├── courses/
│   │   ├── class-courses-cpt.php         ← CPT cead_acad_course + taxonomías
│   │   ├── class-courses-roster.php      ← Lógica de inscripciones
│   │   └── class-courses-admin.php       ← Metabox + acción add/remove de roster
│   │
│   ├── broadcasts/
│   │   ├── class-broadcasts-cpt.php      ← CPT cead_acad_broadcast + taxonomía
│   │   ├── class-broadcasts-audiences.php ← CRUD de la tabla audiences
│   │   ├── class-broadcasts-targeting.php ← Resolver audiencia → lista de users
│   │   ├── class-broadcasts-reads.php    ← Read receipts
│   │   └── class-broadcasts-feed.php     ← Feed filtrado por usuario
│   │
│   ├── surveys/
│   │   ├── class-surveys-cpt.php         ← CPT cead_acad_survey
│   │   ├── class-surveys-questions.php   ← Builder de preguntas
│   │   ├── class-surveys-responses.php   ← Guardar y leer respuestas
│   │   ├── class-surveys-admin.php       ← Metabox + export CSV
│   │   └── class-surveys-frontend.php    ← Renderizado y submit del frontend
│   │
│   ├── schedule/
│   │   ├── class-schedule-cpt.php        ← CPT cead_acad_event
│   │   ├── class-schedule-admin.php      ← Metabox de fechas y tipo
│   │   └── class-schedule-feed.php       ← Feed y export iCal
│   │
│   ├── resources/
│   │   ├── class-resources-cpt.php       ← CPT cead_acad_resource + taxonomías
│   │   ├── class-resources-admin.php     ← Metabox de archivo/URL
│   │   └── class-resources-acl.php       ← Verificación de acceso por audiencia
│   │
│   ├── importers/
│   │   ├── class-importer-base.php       ← Clase base con flujo común
│   │   ├── class-importer-reader.php     ← Interfaz de lectura
│   │   ├── class-importer-csv-reader.php ← Lector CSV con detección de delimitador
│   │   ├── class-importer-xlsx-reader.php ← Lector XLSX liviano (sin dependencias)
│   │   ├── class-importer-job.php        ← Gestión de jobs en tabla import_jobs
│   │   ├── class-importer-students.php   ← Importar alumnado
│   │   ├── class-importer-grades.php     ← Importar calificaciones
│   │   ├── class-importer-courses.php    ← Importar cursos
│   │   ├── class-importer-events.php     ← Importar eventos
│   │   └── class-importer-admin.php      ← UI del hub de importadores
│   │
│   ├── grades/
│   │   └── class-grades-db.php           ← Acceso a tabla grades
│   │
│   ├── panels/
│   │   └── class-tasks-cpt.php           ← CPT cead_acad_task + metabox + acciones
│   │
│   └── whatsapp/
│       ├── class-wa-module.php           ← Orquestador: crea dependencias y arranca todo
│       ├── class-wa-tables.php           ← Schema de tablas wa_* + seed de mensajes/opciones
│       ├── class-wa-store.php            ← Acceso a todas las tablas del bot
│       ├── class-wa-crypto.php           ← Cifrado de reportes (libsodium / AES-256-GCM)
│       ├── class-wa-identity.php         ← Normalización E.164 + resolución phone → user
│       ├── class-wa-bridge-client.php    ← Cliente HTTP hacia el bridge (send, send-image, status)
│       ├── class-wa-engine.php           ← Máquina de estados: menús, flujos, atajos
│       ├── class-wa-broadcaster.php      ← Broadcasting masivo con job + batch
│       ├── class-wa-rest.php             ← Endpoints REST caag-bot/v1/*
│       ├── class-wa-cron.php             ← Heartbeat, lotes, programados, recordatorios, GC
│       ├── class-wa-admin.php            ← Panel de control en wp-admin (5 subpáginas)
│       ├── README.md                     ← Documentación del módulo WhatsApp
│       └── EXTENDING.md                  ← Recetas para modificar/extender el bot
│
├── templates/
│   ├── auth/
│   │   ├── login.php
│   │   ├── register.php
│   │   ├── recover.php
│   │   ├── recover-reset.php
│   │   └── partials/auth-shell.php
│   └── panel/
│       ├── home.php, shell.php
│       ├── comunicados/{feed,single}.php
│       ├── encuestas/{list,take}.php
│       ├── horarios/{list,single}.php
│       ├── recursos/{list,single}.php
│       ├── boletin/show.php
│       ├── delegado/dashboard.php
│       ├── secretaria/dashboard.php
│       ├── direccion/dashboard.php
│       └── partials/{topbar,sidebar}.php
│
├── bridge/
│   ├── index.js                          ← Servidor Node.js (Baileys + endpoints)
│   ├── setup-tunnel.js                   ← Configurador de Cloudflare Tunnel
│   ├── package.json
│   ├── .env.example                      ← Variables de entorno del bridge
│   └── INSTALACION.md                    ← Guía de instalación paso a paso
│
├── assets/
│   ├── css/cead-acad-frontend.css
│   ├── css/cead-acad-admin.css
│   └── js/cead-acad-frontend.js
│
└── tests/
    └── test-phone-normalization.php      ← Test ejecutable de normalización E.164
```

---

## Convenciones para desarrolladores

| Tipo | Convención |
|------|-----------|
| Funciones globales | `cead_acad_*` |
| Clases | `Cead_Acad_*` (PascalCase) |
| Tablas custom | `{$wpdb->prefix}cead_acad_*` |
| Meta keys privadas | `_cead_acad_*` |
| Clases CSS | `cead-acad-*` |
| Text-domain | `cead-acad` |
| Options WP | `cead_acad_*` |
| Cron hooks | `cead_acad_*` |
| REST namespace | `caag-bot/v1` |
| PHP mínimo | 8.1 (match, enums, named args, fibers) |
| WP mínimo | 6.4 |

### Helpers globales disponibles

```php
cead_acad_url( $route )           // URL absoluta de una ruta del plugin
cead_acad_table( $name )          // Nombre completo de una tabla del plugin
cead_acad_user_is_staff()         // true si el usuario actual es dirección/secretaría/admin
cead_acad_user_is_staff( $user )  // idem para un usuario específico
cead_acad_verify_nonce( $field, $action )  // Verifica nonce de formulario
cead_acad_rate_limit( $key, $max, $window ) // Rate limiting por IP (retorna true si se puede)
```

### Regla de versiones

Al agregar un mensaje nuevo al bot:
1. Sumar `CEAD_ACAD_DB_VERSION` en `cead-acad.php` (para que el seed se re-ejecute en instalaciones existentes)

Al agregar un rol o capability:
1. Sumar `CEAD_ACAD_VERSION` en `cead-acad.php` (activa `Cead_Acad_Capabilities::install()`)
2. Agregar el rol a `uninstall.php` si corresponde

---

## Extender el bot de WhatsApp

Ver `modules/whatsapp/EXTENDING.md` para recetas paso a paso:

- **Receta 1**: Cambiar un texto (solo wp-admin, sin código)
- **Receta 2**: Agregar una opción al menú del alumnado
- **Receta 3**: Agregar un mensaje nuevo al catálogo
- **Receta 4**: Agregar una acción al menú del personal (con capability gate)
- **Receta 5**: Usar datos del plugin (horarios, comunicados, cursos) dentro del bot
- **Receta 6**: Cambiar velocidad o tamaño de batch del envío masivo

### Verificación antes de commitear

```bash
# PHP
php -l cead-acad/modules/whatsapp/class-wa-engine.php

# Node.js (bridge)
node --check cead-acad/bridge/index.js

# Test de normalización de teléfonos
php cead-acad/tests/test-phone-normalization.php
```
