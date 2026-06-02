# Módulo WhatsApp (CEAD Académico)

Bot de WhatsApp integrado a `cead-acad`, controlado desde wp-admin y alimentado
con los **datos reales del plugin** (eventos/horarios, comunicados, cursos).
Es un port completo del bot original (Fases 0–4) reescrito como módulo nativo.

## Estructura (todo unificado en este repo)

```
cead-acad/
├── modules/whatsapp/      ← este módulo (lógica del bot en WordPress)
│   ├── class-wa-*.php      (11 clases)
│   ├── README.md           (este archivo)
│   └── EXTENDING.md         ← cómo modificar/añadir cosas (devs / IA)
└── bridge/                ← el "bridge" Node.js que habla con WhatsApp
    ├── index.js, setup-tunnel.js, package.json, .env.example
    └── INSTALACION.md      ← guía de instalación para no técnicos
```

- **¿Cambiar un texto del bot?** wp-admin → *CEAD Académico → WhatsApp · Mensajes*
  (agrupado y con nombres claros; es solo texto).
- **¿Cambiar o agregar una función?** ver `EXTENDING.md` (recetas paso a paso).
- **¿Instalar el bridge?** ver `bridge/INSTALACION.md`.

## Arquitectura

```
Bridge Baileys (PC del admin, NO se toca)
        │  POST /wp-json/caag-bot/v1/incoming  (header X-Caag-Token)
        ▼
Cead_Acad_WA_REST ─► Cead_Acad_WA_Engine (máquina de estados por teléfono)
                              │
        ┌─────────────────────┼───────────────────────────┐
        ▼                     ▼                            ▼
Cead_Acad_WA_Store     Cead_Acad_WA_Identity        Datos de cead-acad
(tablas wa_*)          (_cead_acad_phone → user)    (Schedule_Feed, Broadcasts_Feed,
                                                     Audiences, Courses_Roster)
        ▲
Cead_Acad_WA_Broadcaster (envío por lotes) ◄── Cead_Acad_WA_Cron (heartbeat,
                                                broadcast, programados, recordatorios)
        │
        ▼
Cead_Acad_WA_Bridge_Client ─► POST {bridge_url}/api/send|status|restart|logout
```

## Clases

| Clase | Rol |
|---|---|
| `Cead_Acad_WA_Module` | Orquestador: arma dependencias y arranca REST/cron/admin. |
| `Cead_Acad_WA_Tables` | Schema (`wp_cead_acad_wa_*`) + seed de plantillas/opciones. |
| `Cead_Acad_WA_Store` | Acceso a datos: sesión, registro, estado, mensajes, reportes, sugerencias, programados, logs. |
| `Cead_Acad_WA_Crypto` | Cifrado de reportes (libsodium / AES-256-GCM). |
| `Cead_Acad_WA_Bridge_Client` | Cliente saliente hacia el bridge. |
| `Cead_Acad_WA_REST` | Endpoints `caag-bot/v1/{incoming,status,update-bridge-url}`. |
| `Cead_Acad_WA_Identity` | Resuelve teléfono → usuario (meta `_cead_acad_phone`) y rol/caps. |
| `Cead_Acad_WA_Engine` | Máquina de estados + handlers de alumnado y staff. |
| `Cead_Acad_WA_Broadcaster` | Envío masivo escalonado (job en opción + cron). |
| `Cead_Acad_WA_Cron` | Heartbeat, lotes, comunicados programados, recordatorios, limpieza. |
| `Cead_Acad_WA_Admin` | Panel de control en wp-admin. |

## Contrato del bridge (preservado, no requiere tocar el bridge)

- REST entrante: `POST /wp-json/caag-bot/v1/incoming` con header `X-Caag-Token`.
  Payload del bridge: `{ from, body, pushName, timestamp, media }`.
- REST auxiliares: `GET /caag-bot/v1/status`, `POST /caag-bot/v1/update-bridge-url`.
- Saliente hacia el bridge: `POST {bridge_url}/api/send|status|restart|logout`
  con `X-Caag-Token`. `/api/status` devuelve `{ connected, number, qr }`.

El token compartido y la URL del bridge se configuran en
**CEAD Académico → WhatsApp → Configuración**.

## Personalización mixta

`Cead_Acad_WA_Identity::resolve($phone)` busca el usuario cuyo meta
`_cead_acad_phone` coincide (tolerando el código de país por subcadena):

- **Con match** → datos personalizados: horarios/comunicados del usuario vía
  `Cead_Acad_Schedule_Feed::for_user()` / `Cead_Acad_Broadcasts_Feed::for_user()`.
- **Sin match** → modo general: eventos/comunicados con audiencia `all`.

## Funciones del bot

**Alumnado:** horarios (con "ahora/sigue"), sitio web, calendario, contacto,
lectura de comunicados, reporte anónimo/confidencial (cifrado + código de
seguimiento + reenvío a un número responsable), sugerencias, FAQ, tablón del
Consejo + propuestas, recordatorios de eventos opt-in, y **"Mi panel web"**
(promoción del panel). `BAJA` para no recibir más.

**Menú inicial por rol:** quien tiene permisos elige primero a qué menú entrar
("Estudiantes" o el/los menú(s) de su rol). Un alumno entra directo.

**Staff (menú por rol, cada acción gateada por capacidad de cead-acad):**

| Acción | Capacidad requerida |
|---|---|
| Enviar comunicado / anuncio (ahora o programado; **crea el comunicado en el panel** y lo envía por WA, soporta **imagen**) | `cead_acad_publish_broadcast` (`_all` para "todos") |
| Agregar evento al calendario | `cead_acad_manage_schedule` |
| Artículos del sitio (publicar/editar/borrar entradas del blog WP) | `cead_acad_manage_articles` *(nueva)* |
| Bandeja de reportes / sugerencias | `cead_acad_manage_reports` |
| Asignar roles a un número (profesor/delegado/consejo; crea el usuario si no existe) | `cead_acad_manage_roles` *(nueva, solo Dirección)* |
| Métricas | `cead_acad_view_metrics` |

**Atajos rápidos (staff):** `-AA <texto>` publica un anuncio para todos;
`-AE <texto>` agrega un evento (el bot pregunta la fecha).

**Imágenes:** el bot recibe imágenes y, en un comunicado/anuncio, las **replica**
a la audiencia y las deja como imagen destacada del comunicado en el panel.

## Tablas (`wp_cead_acad_wa_*`)

`wa_session`, `wa_registry`, `wa_state`, `wa_messages`, `wa_reports`,
`wa_suggestions`, `wa_scheduled`, `wa_logs`. Se crean de forma idempotente vía
`Cead_Acad_Activator::create_tables()` (activación y migración por versión).
Los horarios, eventos, comunicados y cursos **no** se duplican: se leen de los
módulos existentes de cead-acad.

## Reconocer alumnos por teléfono

El bot identifica al usuario por el meta `_cead_acad_phone` comparando el número
**normalizado a E.164** (exacto, no por subcadena → sin falsos positivos). El
código de país se configura en *WhatsApp → Configuración* (opción
`cead_acad_wa_country_code`, por defecto `595`). Hay un test ejecutable de la
normalización en `tests/test-phone-normalization.php` (`php cead-acad/tests/test-phone-normalization.php`).

## Compatibilidad

Instalá **un solo** plugin de WhatsApp: este módulo expone el namespace REST
`caag-bot/v1`, igual que el viejo plugin `wp-caaguazu-bot`. Si ambos están
activos, las rutas chocan. Mantené activo solo cead-acad.
