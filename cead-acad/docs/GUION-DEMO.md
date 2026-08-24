# Guion de demo en vivo — CEAD Académico

> Checklist para mostrar el sistema sin trabarse. Pensado para ~8–10 minutos.
> La idea: contar una **historia** (un alumno y la dirección), no enumerar features.

---

## Antes de empezar (preparación)

- [ ] Tener **dos pestañas/dispositivos** abiertos: uno con sesión de **alumno**, otro con sesión de **dirección**.
- [ ] Tener el **celular con WhatsApp** listo y el bot **CEADI conectado** (verificar en *WhatsApp → Estado* que diga conectado; si no, escanear el QR antes).
- [ ] Tener a mano un **comunicado de ejemplo** ya pensado (título + texto corto).
- [ ] Cerrar pestañas y notificaciones que distraigan. Subir el brillo del celular.
- [ ] **Plan B**: tener abiertas las **ilustraciones de la wiki** (`/wiki`) por si la conexión falla — podés explicar con ellas.

---

## Parte 1 · La mirada del alumno (3–4 min)

1. **Login** (`/ingresar`) — Mostrá la pantalla con la estética CEAD. Mencioná: *"no hay registro libre, se entra por invitación"*.
2. **Inicio** (`/panel`) — Señalá los **accesos rápidos**, **clases de hoy**, **próximos eventos** y **últimos comunicados**. Frase clave: *"cada persona ve solo lo de su rol"*.
3. **Comunicados** — Abrí uno **no leído** → mostrá cómo queda **marcado como leído** y baja el contador de la campana 🔔.
4. **Calendario** — Mostrá la vista mensual y el botón de **sincronizar con Google/Apple** (iCal).
5. **Mi carné** 🪪 — Mostrá el **carné digital con QR**. Escaneá el QR con el celular → se abre la **página pública de verificación**. *(Momento "wow", funciona en vivo.)*

---

## Parte 2 · CEADI por WhatsApp (2–3 min)

6. Desde el **celular**, escribile a CEADI en **lenguaje natural**: *"¿qué clases tengo mañana?"* → mostrá que entiende y responde.
7. Mandá una **nota de voz** corta con una pregunta → mostrá que la **transcribe** y contesta. *(Otro momento fuerte.)*
8. Mencioná, sin demostrar en vivo: **reportes confidenciales** (cifrados) y **escribir a un encargado** — todo cae en el buzón del panel.

> Si la red está lenta, el bot puede tardar unos segundos: avisalo de antemano ("le toma un momentito") para que no parezca que se colgó.

---

## Parte 3 · La mirada de la dirección (2–3 min)

9. En la pestaña de **dirección**, entrá a **WhatsApp → Comunicados** (o desde el bot) y **enviá el comunicado de ejemplo** a un curso.
10. Volvé a la pestaña del **alumno** y mostrá que **el comunicado ya aparece** ahí. Frase clave: *"un solo mensaje, llega por web y por WhatsApp"*.
11. Mostrá rápido el **Escritorio de wp-admin** con las **métricas** (alumnos, docentes, cursos, estado del bot) y mencioná los **importadores CSV/Excel**.

---

## Cierre (1 min)

- Frase de cierre: *"Web, app y WhatsApp, un solo sistema hecho a medida para el CEAD — y se actualiza solo"*.
- Si hay tiempo / preguntas técnicas: abrí **`/wiki/tecnica`** y mostrá el **diagrama de arquitectura**.

---

## Si algo falla (plan B rápido)

| Falla | Qué hacer |
|------|-----------|
| El bot no responde | Mostralo con la **ilustración del chat** en `/wiki` y seguí; revisá el estado del bridge después. |
| No carga el panel | Usá las **ilustraciones de la wiki** para explicar cada pantalla. |
| Se cae el internet | Abrí la app **instalada (PWA)**: el panel ya visitado funciona **offline**. |
| El QR del carné no escanea | Mostrá la **página de verificación** abriéndola directo desde el otro dispositivo. |

---

*Guion de demo · CEAD Félix de Guarania.*
