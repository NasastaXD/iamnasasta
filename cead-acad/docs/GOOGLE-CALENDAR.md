# Sincronización con Google Calendar (y otros calendarios)

El panel del CEAD expone un **feed iCal suscribible por usuario** con sus eventos
(exámenes, reuniones, actos, etc.). La sincronización es **one-way**: el calendario
externo lee los eventos del CEAD; los cambios hechos en Google/Apple no vuelven al CEAD.

## Cómo funciona

- Cada usuario tiene una URL firmada: `https://<sitio>/cal/<token>.ics`
  (token generado por `Cead_Acad_Account::feed_token()`, no requiere login).
- El feed se genera en `Cead_Acad_Schedule_Feed::output_subscription()` y solo
  incluye los eventos visibles para ese usuario (audiencias por rol/curso/cohorte).
- Los horarios se guardan en hora local del sitio y el feed los convierte a UTC
  usando la zona horaria configurada en WordPress (Ajustes → General). **Si el
  colegio cambia de zona horaria, ajustarla ahí**.
- El feed publica `X-PUBLISHED-TTL`/`REFRESH-INTERVAL` de 1 hora como sugerencia,
  pero cada proveedor decide su frecuencia real:
  - **Google Calendar**: refresca feeds externos cada ~12–24 h (no configurable).
  - **Apple Calendar**: configurable por el usuario (de 5 min a 1 semana).
  - **Outlook**: cada ~3–24 h.

## Cómo se suscribe un usuario

Desde **Panel → Calendario → "Sincronizar con el calendario de mi celular"**:

- **iPhone / Apple**: botón directo (usa `webcal://`).
- **Google Calendar**: botón "Agregar a Google Calendar", o manualmente:
  Google Calendar → ⚙️ Configuración → **Agregar calendario → Desde URL** → pegar
  la URL `https://.../cal/<token>.ics`.
- **Cualquier otra app**: copiar la URL y agregarla como "calendario por URL".

## Privacidad y revocación

- La URL contiene un token firmado: quien la tenga puede leer ese calendario.
  No compartirla públicamente.
- El token deriva del usuario; si se necesita rotación/revocación, ver
  `Cead_Acad_Account::feed_token()` / `verify_feed_token()`.

## ¿Sync bidireccional?

Requeriría OAuth con la API de Google Calendar (consentimiento por usuario,
refresh tokens, manejo de cuota y conflictos). Se evaluó y se pospuso: el costo
es alto y el caso de uso del CEAD (publicar eventos hacia los alumnos) queda
cubierto por la suscripción one-way.
