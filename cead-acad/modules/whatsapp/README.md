# Módulo WhatsApp (CEAD Académico)

Bot de WhatsApp integrado a `cead-acad`, controlado desde wp-admin y alimentado
con los **datos reales del plugin** (eventos/horarios, comunicados, cursos).
Es un port completo del bot original (Fases 0–4) reescrito como módulo nativo.

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
Consejo + propuestas, recordatorios de eventos opt-in. `BAJA` para no recibir más.

**Staff (menú dinámico según capacidades de cead-acad):**

| Acción | Capacidad requerida |
|---|---|
| Enviar comunicado por WhatsApp (ahora o programado) | `cead_acad_publish_broadcast` (`_all` para "todos") |
| Agregar evento al calendario | `cead_acad_manage_schedule` |
| Bandeja de reportes / sugerencias | `cead_acad_manage_reports` *(nueva, en Dirección y Secretaría)* |
| Métricas | `cead_acad_view_metrics` |

> Los artículos web y la gestión de usuarios/roles del bot original se cubren
> de forma nativa en wp-admin (CPT de comunicados y roles de WordPress), por lo
> que no se duplican en el chat.

## Tablas (`wp_cead_acad_wa_*`)

`wa_session`, `wa_registry`, `wa_state`, `wa_messages`, `wa_reports`,
`wa_suggestions`, `wa_scheduled`, `wa_logs`. Se crean de forma idempotente vía
`Cead_Acad_Activator::create_tables()` (activación y migración por versión).
Los horarios, eventos, comunicados y cursos **no** se duplican: se leen de los
módulos existentes de cead-acad.
