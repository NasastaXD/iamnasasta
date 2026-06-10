# Roadmap de Features — CEAD Académico

> ✅ **Estado: las 10 features fueron implementadas** (plugin v0.22.0, junio 2026).
> Cada una tiene su commit propio en el historial (`Feature #1` … `Feature #10`).

Roadmap priorizado de mejoras para el monorepo (plugin `cead-acad/`, tema `cead/` y bridge
de WhatsApp `cead-acad/bridge/`). Las features están ordenadas por impacto/esfuerzo/riesgo
y cada una es entregable de forma independiente.

> Estado del proyecto al momento del análisis: plugin v0.20.1 (17 módulos, 18 tablas,
> 7 roles), tema v1.3.2, bot CEADI operativo. La funcionalidad base está completa;
> las oportunidades están en robustez, observabilidad, experiencia e integraciones.

---

## Tier 1 — Alto impacto, riesgo bajo (empezar por acá)

### 1. UI de Audit Log y logs del bot  *(Observabilidad)*
- **Por qué**: las tablas `cead_acad_audit_log` y `cead_acad_wa_logs` ya se llenan pero no hay
  forma de verlas desde wp-admin. Valor inmediato, cero riesgo (solo lectura).
- **Qué**: nueva página "CEAD Académico → Registros" con tabla paginada y filtros
  (usuario, acción, fecha, dirección in/out) usando `WP_List_Table`.
- **Archivos clave**: `cead-acad/admin/class-admin-menu.php` (registrar submenú),
  nuevo `cead-acad/admin/class-admin-logs.php`.
- **Esfuerzo**: bajo (1–2 días).

### 2. Suite de tests + CI  *(Calidad)*
- **Por qué**: hoy solo existe `cead-acad/tests/test-phone-normalization.php` (manual). Sin red
  de seguridad para cambios futuros. Habilita el resto del roadmap con confianza.
- **Qué**: PHPUnit con `brain/monkey` o `WP_Mock` para lógica pura (normalización de teléfonos,
  audiencias polimórficas, tokens de carné/iCal, parsers de importadores) + workflow de GitHub
  Actions que corra los tests en cada push/PR.
- **Archivos clave**: nuevo `cead-acad/composer.json`, `cead-acad/phpunit.xml`,
  `.github/workflows/tests.yml`. Targets de alto valor:
  `modules/broadcasts/class-broadcasts-audiences.php`, `modules/importers/class-importer-base.php`.
- **Esfuerzo**: medio (3–4 días el andamiaje + primeros tests).

### 3. Internacionalización (i18n)  *(Experiencia)*
- **Por qué**: el textdomain se carga pero no hay archivos `.pot/.po`; strings hardcodeados en
  español. Prepara el terreno para guaraní u otros idiomas.
- **Qué**: generar `cead-acad/languages/cead-acad.pot` con `wp i18n make-pot`, auditar que los
  strings usen `__()/_e()` con el textdomain correcto, agregar `load_plugin_textdomain`.
- **Esfuerzo**: medio (auditoría amplia pero mecánica).

---

## Tier 2 — Alto impacto, esfuerzo/riesgo medio

### 4. Rate limiting + reintentos en el bot  *(Calidad/robustez)*
- **Por qué**: `POST /wp-json/caag-bot/v1/incoming` no tiene rate limiting y el bridge hace un
  solo POST sin reintento → riesgo de abuso y de mensajes perdidos.
- **Qué**: throttle por teléfono/IP con transients (patrón ya usado para códigos de verificación)
  y reintentos con backoff en el bridge.
- **Archivos clave**: `modules/whatsapp/class-wa-rest.php`, `modules/whatsapp/class-wa-bridge-client.php`,
  `bridge/index.js`.

### 5. Dashboard de analíticas  *(Observabilidad)*
- **Por qué**: hay datos ricos sin visualizar — lecturas de comunicados (`broadcast_reads`),
  uso del bot (`wa_logs`), participación en encuestas. Valor de gestión para dirección.
- **Qué**: página "CEAD Académico → Métricas" con tarjetas y gráficos (Chart.js vía CDN):
  % de lectura por comunicado, mensajes del bot por día, participación en encuestas.
- **Archivos clave**: nuevo `cead-acad/admin/class-admin-metrics.php`; complementa el panel
  de dirección existente (`templates/panel/direccion.php`).

### 6. Exportación masiva de datos  *(Admin)*
- **Por qué**: se puede importar (CSV/XLSX) pero no exportar boletines/usuarios/comunicados.
  Cierra el ciclo y facilita respaldos.
- **Qué**: acciones de exportación CSV reusando la utilidad de export que ya tiene el módulo
  de encuestas (`modules/surveys/`).
- **Esfuerzo**: bajo-medio.

---

## Tier 3 — Buen valor, tomar después

### 7. Suspensión de usuarios *(Admin)*
Estado "suspendido" que bloquea el login en `modules/auth/` sin borrar datos
(hoy solo hay alta/borrado).

### 8. Emails con branding *(Experiencia)*
Recuperación de contraseña e invitaciones con plantilla HTML institucional
(filtros `wp_mail` / `retrieve_password_message` en `modules/auth/`).

### 9. Sync con Google Calendar *(Integraciones)*
Ya existe el iCal de suscripción (`/cal/<token>.ics`); documentar/mejorar la suscripción
one-way en Google Calendar antes de considerar API OAuth bidireccional (mucho más costosa).

### 10. PWA offline real *(PWA)*
Pasar el service worker (`modules/pwa/class-pwa.php`) de network-first básico a cache-first
para assets estáticos y páginas del panel ya visitadas, con página de fallback offline.

---

## Resumen de priorización

| # | Feature | Área | Tier | Estado |
|---|---------|------|------|--------|
| 1 | UI Audit/bot logs | Observabilidad | 1 | ✅ Implementada |
| 2 | Tests + CI | Calidad | 1 | ✅ Implementada |
| 3 | i18n (.pot) | Experiencia | 1 | ✅ Implementada |
| 4 | Rate limit + reintentos bot | Calidad | 2 | ✅ Implementada |
| 5 | Dashboard analíticas | Observabilidad | 2 | ✅ Implementada |
| 6 | Exportación masiva | Admin | 2 | ✅ Implementada |
| 7 | Suspensión de usuarios | Admin | 3 | ✅ Implementada |
| 8 | Emails con branding | Experiencia | 3 | ✅ Implementada |
| 9 | Sync Google Calendar | Integraciones | 3 | ✅ Implementada |
| 10 | PWA offline real | PWA | 3 | ✅ Implementada |

**Recomendación de arranque**: #1 (UI de logs) como primera entrega — máximo valor visible con
mínimo riesgo — y en paralelo montar #2 (tests + CI) para asegurar todo lo que venga después.
