# 📘 CEAD Académico — Wiki del usuario

> Guía completa del sistema del **Centro Educativo de Alto Desempeño "Félix de Guarania"**.

<!-- El número de versión NO va escrito acá: lo imprime el pie de /wiki desde
     CEAD_ACAD_VERSION. Estuvo a mano un tiempo y se quedó en 0.44.1 mientras el
     pie de la misma página ya decía 0.59.0 — la wiki se contradecía a sí misma
     en pantalla. Un solo lugar, y ese lugar es el código. -->

Esta wiki explica **todo lo que tiene el sistema** en lenguaje simple: el panel web, la app, el bot de WhatsApp (CEADI) y la parte de administración. Está pensada para alumnado, familias, docentes, delegados, secretaría, consejo y dirección.

> 📍 Esta wiki se puede leer online en **`/wiki`** del sitio.
> ¿Preferís algo más corto y visual? Está la **[página del proyecto](/proyecto)**, que lo explica todo en dos minutos con ilustraciones.
> ¿Buscás la parte técnica (arquitectura, módulos, base de datos, despliegue)? Está en **[Wiki técnica](WIKI-TECNICA.md)**.

---

## Índice

1. [¿Qué es?](#1-qué-es)
2. [Acceso y cuentas](#2-acceso-y-cuentas)
3. [La app (PWA) y preferencias](#3-la-app-pwa-y-preferencias)
4. [Roles](#4-roles)
5. [El panel web, sección por sección](#5-el-panel-web-sección-por-sección)
6. [CEADI — el bot de WhatsApp](#6-ceadi--el-bot-de-whatsapp)
7. [Administración (wp-admin)](#7-administración-wp-admin)
8. [Familias](#8-familias)
9. [Actualizaciones del sistema](#9-actualizaciones-del-sistema)
10. [Preguntas frecuentes](#10-preguntas-frecuentes)

---

## 1. ¿Qué es?

CEAD Académico es la plataforma del colegio. Tiene tres caras:

1. **El panel web** (`/panel`) — donde cada persona, según su rol, ve comunicados, calendario, horarios, tareas, recursos, su carné, etc.
2. **CEADI**, el **bot de WhatsApp** — atiende consultas, horarios, eventos, reportes y mensajes desde el celular.
3. **La administración** (wp-admin) — donde dirección y secretaría cargan cursos, usuarios, comunicados, eventos, calificaciones e importan datos.

Se puede usar desde el navegador o **instalarse como app** en el celular (ver §3).

---

## 2. Acceso y cuentas

| Acción | Dirección |
|---|---|
| Ingresar | `/login` |
| Registrarse | Solo con un **link de invitación** que genera la dirección (`/i/<código>`) |
| Recuperar contraseña | `/recuperar` |
| Cerrar sesión | `/salir` |
| Panel | `/panel` |

- **No hay registro libre**: para crear una cuenta hace falta una invitación enviada por la dirección/secretaría.
- En el **login**, si te equivocás, **no se borra** el usuario que escribiste, y hay un botón **👁 para ver la contraseña**.
- Si olvidaste la contraseña, usá *"¿Olvidaste tu contraseña?"* en el login.

![Pantalla de ingreso al sistema CEAD](img/login.svg)
*Ilustración de la pantalla de ingreso. La apariencia real puede variar levemente.*

---

## 3. La app (PWA) y preferencias

- **Instalar como app**: en el menú, **"Instalar app"** explica paso a paso cómo agregarla a la pantalla de inicio en **Android (Chrome)**, **iPhone (Safari)** y **PC**. Se abre a pantalla completa, se actualiza sola y no ocupa casi nada. Es gratis.
- **Modo oscuro**: botón 🌗 en la barra superior. Queda guardado en tu dispositivo.
- **Menú**: en celular el menú lateral se abre con el botón ☰; en computadora está siempre visible.
- **Notificaciones** 🔔: la campana junta lo nuevo (comunicados sin leer, próximos eventos, tareas que vencen) con "marcar todo leído".
- **Buscador** 🔍: busca en comunicados, eventos y recursos.

---

## 4. Roles

| Rol | Para qué |
|---|---|
| **Dirección** | Acceso total: cursos, usuarios, invitaciones, comunicados, eventos, calificaciones, importadores, buzón, métricas. |
| **Secretaría** | Casi todo lo administrativo (cursos, invitaciones, comunicados, eventos, importadores, buzón). |
| **Docente** | Comunicados y eventos de sus cursos; carga de notas y tareas. |
| **Delegado/a** | Gestiona las **tareas de su curso**. |
| **Alumno/a** | Comunicados, horarios, calendario, tareas, recursos, carné, boletín (sus notas), escribir al CEAD. |
| **Familia** | Calendario, eventos, comunicados, recursos, tareas a nivel colegio. **No ve calificaciones.** |
| **Consejo Estudiantil** | Recibe y responde **sugerencias** dirigidas al Consejo; puede publicar comunicados. |

> El menú y las secciones que ve cada persona **se adaptan automáticamente** a su rol.

---

## 5. El panel web, sección por sección

### 🏠 Inicio
Tablero con saludo, **accesos rápidos**, **clases de hoy** (del horario del curso), **próximos eventos** y **últimos comunicados**. La primera vez por sesión muestra una breve **intro animada** del logo CEAD.

![Panel de inicio con accesos rápidos, clases de hoy, comunicados y eventos](img/panel-inicio.svg)
*Ilustración del panel de inicio. Cada persona ve las secciones de su rol.*

### 📣 Comunicados
Mensajes dirigidos a tu rol, curso o a vos. Marca los **no leídos**. Al abrir uno queda leído.

![Lista de comunicados con filtros y avisos de no leídos](img/panel-comunicados.svg)
*Ilustración del feed de comunicados, con filtros y marca de no leídos.*

### ✉️ Escribir al CEAD
Mandá un **mensaje directo** a **Dirección**, **Consejo** o **Administración**. Lo reciben en su **buzón** (con tu nombre) y te responden desde la app. *(También se puede hacer desde el bot — ver §6.)*

### 🗳️ Encuestas
Encuestas dirigidas a vos; las respondés y se registran. (Quien las crea puede ver resultados en la administración.)

### 📚 Horarios
Tu **horario semanal de clases** (materias, horas, docentes y aula) según tu curso. Lo carga la secretaría, a mano en cada curso o de una vez con el importador de "Horario de clases" (ver §7 Administración).

Si sos **delegado/a, docente, secretaría o dirección**, además tenés una sección aparte para **consultar el horario de otros cursos** (de solo lectura) — no reemplaza el tuyo, es una consulta extra.

### 📅 Calendario
**Eventos** (reuniones, exámenes, feriados, actos, excursiones, entregas, períodos de cierre…) en **vista mensual** o **agenda**. Incluye:
- Navegación por mes y colores por tipo de evento (cada tipo tiene un color por defecto; un evento puede tener uno propio).
- Los eventos de varios días (**fecha de cierre**: vacaciones, período de exámenes) se ven en TODOS los días que abarcan, no solo el primero.
- **Sincronizar con el celular**: botón para **Google Calendar** y **Apple/iPhone** (suscripción iCal) — los eventos aparecen y se actualizan solos en tu calendario.
> Los horarios de clases **no** se mezclan acá; están en "Horarios".

Los eventos institucionales (sin curso asignado: feriados, actos, períodos de cierre) también se ven, sin necesidad de iniciar sesión, en `/calendario` — la página pública del sitio.

### 📝 Mis tareas
Tareas de tu curso con **fecha de vencimiento** (resalta "vence hoy/vencida"), prioridad, **marcar como hecha** y **subir tu entrega** (archivo).

### 📁 Recursos
Biblioteca de materiales (PDFs, enlaces, mapas). Tiene **búsqueda**, **filtros por materia/tipo** y **favoritos** ⭐.

### 🪪 Mi carné
Tu **carné digital** con foto, rol, curso, documento y un **código QR**. Al escanearlo, abre una **página pública de verificación** que confirma que el carné es válido (muestra nombre, rol, curso y foto; **no** expone datos sensibles). Botón para **imprimir / guardar PDF**.

![Carné digital con foto, datos del alumno y código QR de verificación](img/carne.svg)
*Ilustración del carné digital y su QR de verificación pública.*

### 👤 Mi perfil
- Cambiar **foto**, **nombre** y **teléfono**.
- **Verificación de WhatsApp**: pedís un **código** que CEADI te manda al WhatsApp, lo ingresás y tu número queda **✅ verificado**.

### 📲 Instalar app
Tutorial para instalar el sistema como app (ver §3).

### 📊 Boletín *(solo alumnado)*
Tus **calificaciones**. Las familias **no** ven esta sección.

### 📨 Buzón *(staff: dirección, secretaría, consejo)*
Bandeja para gestionar **reportes** y **mensajes/sugerencias**:
- **Responder**, **aceptar**, **negar**.
- Marcar un reporte como **"No es un reporte"** (queda registrado como broma/no válido en otra categoría).
- **Papelera**: borrado temporal con **restaurar** o **borrar definitivo**.
- **Categorías**: Administración, Consejo y Dirección. El **Consejo** solo ve lo de su categoría.

### ⭐ Paneles por rol
- **Dirección** y **Secretaría**: accesos a su gestión.
- **Delegado/a**: "Tareas del curso".

---

## 6. CEADI — el bot de WhatsApp

CEADI atiende por WhatsApp. **Solo responde a números registrados** en el panel del CEAD; a un número desconocido le avisa una vez y no entra a los menús.

![Conversación con CEADI por WhatsApp: menú, lenguaje natural y notas de voz](img/ceadi-chat.svg)
*Ilustración de una conversación con CEADI por WhatsApp.*

### También está en el panel web
Abajo de todo, en cualquier pantalla del panel, hay una barra que dice **«Preguntale a CEADI»**: al tocarla se abre un chat sin salir de donde estabas. Ahí responde preguntas con el alcance de tu rol —un alumno pregunta por lo suyo, dirección llega hasta los números del colegio—. Las acciones que modifican algo (mandar un comunicado, publicar una nota) se siguen haciendo **por WhatsApp**, que es donde está el paso de confirmación antes de ejecutar.

### Dos formas de hablarle
- **Lenguaje natural (IA)**: le escribís como a una persona ("¿qué tengo mañana?", "¿cuándo es la reunión?") y CEADI entiende y responde. Si la dirección lo deja activado, es el modo por defecto. Ahora puede **encadenar consultas**: si le preguntás algo que necesita mirar dos cosas, mira las dos antes de contestar en vez de adivinar.
- **Menú numérico**: el menú clásico de siempre (escribí **menú** para verlo). Podés cambiar entre IA y menú cuando quieras.
- **Notas de voz** 🎤: también podés mandarle un **audio**; CEADI lo **transcribe** y te contesta (puede tardar unos segundos más que un texto).

> **Cómo responde**: CEADI es una herramienta de trabajo, no un chat de compañía. Contesta corto y al grano, sin vueltas. Si algo no puede hacerlo, lo dice en una línea.
>
> **Qué no va a hacer, por más que se lo pidan**: dar datos de otra persona (notas, teléfonos, documentos), repetir claves o contraseñas, ni ampliar permisos porque alguien *diga* ser docente o director. Quién sos lo define **tu número registrado**, no lo que escribas en el chat.

### Instagram → borrador de nota
Si está configurado, cada media hora CEADI revisa el Instagram del colegio. Cuando aparece una publicación nueva:

1. **Se trae la publicación con hasta dos fotos.**
2. **CEADI la redacta** y decide la **categoría** y la **maqueta** que le corresponden.
3. Las fotos se suben al sitio: la primera queda de **destacada** y la segunda va **dentro del cuerpo**.
4. Todo eso se guarda como **borrador de WordPress, ya armado**.
5. Recién ahí te llega el aviso al número de Dirección, con tres opciones: **1** publicar · **2** editar (decile el cambio por chat y lo reescribe) · **3** dejarlo en borrador.

El borrador **no se publica solo**. Y elegir *3* **no lo borra**: la nota queda guardada en el sitio por si la querés después.

> **Si se te pasa el rato**, el atajo del *1-2-3* caduca a los diez minutos como cualquier conversación del bot — pero el borrador no. Sigue en **Entradas → Borradores**, entero, con las fotos y la categoría puestas. El mensaje te deja el enlace directo para abrirlo.
>
> El número de Dirección tiene que corresponder a un **usuario que pueda publicar artículos**; si no, no se arma nada y queda anotado en el aviso de errores del panel de WhatsApp.

> **Aviso honesto**: Instagram no deja leer una cuenta sin autenticación, así que no existe una forma "gratis y sin permisos" de rastrearla que se mantenga andando — los endpoints públicos se cerraron y raspar la web termina en bloqueos. La opción confiable es la **API oficial**, que necesita acceso a la cuenta del colegio. Mientras tanto se puede enchufar un servicio externo que haga esa lectura (varios son pagos). Todo lo demás —detectar lo nuevo, redactar, mandarlo— ya funciona: cuando consigan el acceso a la cuenta, se completa el token en Ajustes y listo.

### Menú del alumnado
```
 1. Horarios            9. Consejo Estudiantil
 2. Sitio web          10. Recordatorios de eventos
 3. Calendario         11. Mi panel web
 4. Contacto           12. Mis ajustes
 5. Comunicados        13. Mis notas
 6. Reportar algo      14. Mis tareas
 7. Sugerencias        15. Mi carné
 8. Preguntas frecuentes
                        0. Salir
```
- **Reportar algo (6)**: anónimo o confidencial, con categoría; queda en el **buzón**.
- **Escribir a un encargado (7)**: elegís **Administración / Consejo / Dirección** y tu mensaje cae en el **buzón**.
- **Mis ajustes (12)**: ver tus datos, cambiar tu nombre para mostrar, pedir a Secretaría el cambio de número, y elegir si querés hablarle en **lenguaje natural** o con el **menú de números**.
- **Mis notas (13)**, **Mis tareas (14)** y **Mi carné (15)**: lo mismo que ves en el panel, pero sin salir del chat.
- Los reportes y mensajes **se gestionan solo en el panel** (no se reenvían a ningún WhatsApp de coordinación).

### Comandos útiles
| Escribí | Hace |
|---|---|
| **volver** | Vuelve un paso atrás |
| **cancelar** | Vuelve al menú principal |
| **bajar** | Reenvía el menú al final del chat |
| **baja** | Deja de recibir mensajes (opt-out) |

> Para no llenar el chat, CEADI **edita el mismo mensaje** mientras te guía (durante unos minutos) y baja un mensaje nuevo recién con tu próxima respuesta.

### Para staff (según rol)
Dirección/Secretaría/Docente pueden, desde el bot: enviar **comunicados**, **agregar eventos**, **crear invitaciones** para sumar gente, **cargar notas**, **consultar las notas de un curso**, ver el **panorama** del colegio, gestionar **artículos**, **asignar roles** a un número y usar **atajos**.

> **Docentes — cargar una nota por chat**: alcanza con decirle a CEADI *"ponele 4 a Pérez en Matemática del segundo periodo"*. CEADI busca al alumno **dentro de tu curso**, muestra la nota anterior si ya había una, y **recién guarda cuando aceptás**. Si hay dos apellidos parecidos, pregunta en vez de adivinar. Cada docente solo puede tocar **sus** cursos.
>
> **No hace falta que saques la cuenta**: podés decirle el **porcentaje** (*"sacó 75%"*) o el **puntaje** (*"45 de 60"*) y CEADI convierte a la escala del colegio (1 a 5), mostrándote de dónde salió el número. Avisa si la nota deja **aplazado/a** al alumno, y rechaza los imposibles (más puntos que el total, o un porcentaje dictado como si fuera nota).

### ✍️ Cómo escribe el colegio

CEADI tiene **dos voces distintas**, y no son la misma con otras palabras:

- **La del chat** (el *System prompt*): corta, directa, con voseo, sin vueltas. Para contestarte.
- **La de lo que se publica** (el campo **Estilo de redacción**): tercera persona, tono institucional, sin voseo ni emojis. Para notas del sitio, comunicados e informes.

Están separadas a propósito. Con un solo campo las dos se pisan: o las notas salen escritas como un mensaje de WhatsApp, o el chat sale escrito como una circular. El estilo de redacción se aplica solo cuando CEADI **redacta algo publicable** — incluidas las notas que entran por el puente de Instagram.

Se edita en **CEAD Académico → WhatsApp → 🤖 CEADI inteligente (IA)**. Dejarlo **vacío** significa *"usá el que trae el plugin"*, y ese mejora con cada actualización.

### 🎨 Flyers e imágenes generadas

CEADI puede **generar afiches, placas para redes y portadas** para las notas. Pedíselo por chat («hacé un flyer para la reunión de padres del jueves 18 a las 19:30 en el salón de actos») y te propone la idea antes de dibujar nada.

- **Nunca genera una imagen sola.** Siempre propone y espera el **1**. Cada imagen **se cobra**, así que una que nadie pidió es plata del colegio tirada.
- Si una nota no tiene foto, **puede ofrecerte** generarle una portada. Ofrecer no es permiso: si no le decís que sí, la nota se publica sin foto.
- **No genera "fotos" de cosas que pasaron.** Una imagen inventada de un acto que ocurrió de verdad es una foto falsa; para eso hay que mandarle la foto real.
- La imagen te llega **por WhatsApp** para que la mires, y queda guardada en la biblioteca del sitio.
- Cada generación queda en el **registro** con quién la pidió.

> **Se configura aparte** en la misma pantalla, sección *🎨 Imágenes (generación de flyers)*, y **viene apagada**. Va aparte porque el proveedor de chat que usa el colegio (DeepSeek) **no genera imágenes**: hace falta uno que sí, como OpenAI. Si tu proveedor sirve las dos cosas con la misma clave, dejá el campo de clave vacío.
>
> El **estilo gráfico** (colores, tipografía, qué evitar) también se edita ahí, y se le pega a todo lo que se genere para que las piezas se parezcan entre sí.

### 🧩 Cómo se dibuja lo que se publica

No todo lo que sale en la web se lee igual, así que no todo usa la misma maqueta. CEADI **elige** una según lo que escribiste, y te la dice en la propuesta antes de publicar — si se equivocó, se corrige con *"2. Editar"*.

| Maqueta | Cuándo | Cómo se ve |
|---|---|---|
| **Noticia** | Algo que ya pasó, contado como crónica | La de siempre: titular, foto, texto. Es el formato por defecto |
| **Evento** | Algo que va a pasar y a lo que hay que ir | El día en grande, la hora, el lugar y **cuánto falta** ya calculado |
| **Aviso** | Urgente y corto: suspensión, cambio de horario | Banda roja y texto grande, sin foto arriba |
| **Informe** | Documento largo con secciones: memoria, rendición | Índice navegable y secciones numeradas |
| **Logro** | Un premio, un campeonato, una beca | La foto de portada con el título encima |

- **Un evento sin fecha no es un evento.** Si CEADI elige «evento» pero no sabe cuándo es, la nota se publica como noticia común: es preferible a mostrar un cuadro de fecha vacío.
- También se puede cambiar **a mano** desde wp-admin, en el recuadro *«Maqueta de la nota»* al costado del editor de la entrada — ahí mismo se cargan la fecha y el lugar.
- En los listados, lo que **no** es noticia lleva un sello sobre la miniatura, para que un aviso no se confunda con una crónica.

### 📄 Docentes — mandar la planilla de notas

No hace falta cambiar tu forma de trabajar: **mandale a CEADI por WhatsApp la misma planilla que ya usás** (`.xlsx` o `.csv`), si querés con un texto tipo *"notas de Matemática, 2º periodo"*.

CEADI la lee, se da cuenta de cómo está armada (qué columna tiene los nombres y cuál las notas), y te muestra un resumen antes de tocar nada: cuántos alumnos reconoció, la muestra de las primeras notas, **cuáles va a saltear y por qué** (alguien que no está en el curso, una celda vacía, un nombre repetido) y cuántos quedan aplazados. Recién carga cuando aceptás.

- Entiende notas, **porcentajes** y **puntajes** (`45/60` o `75%` dentro de la celda).
- **El archivo no se guarda**: se lee, se usa y se descarta. Queda disponible un rato solo para que puedas **preguntarle cosas** — *"¿cuántos aprobaron?"*, *"¿cuál es el promedio?"* — sin que nada se cargue al sistema.
- Si tu planilla es un **Excel viejo (`.xls`)**, abrila y usá *Guardar como → `.xlsx`*.
- Si el archivo tiene **varias hojas**, lee la primera y te avisa.

### 📎 Mandarle un documento

CEADI también puede **leer un archivo** que le mandes por WhatsApp y contestarte sobre su contenido: sirve para consultar un reglamento largo, una circular o un apunte sin tener que buscar a mano.

- **Formatos**: PDF, Word (`.docx`), OpenDocument (`.odt`), texto (`.txt`, `.md`) y `.csv`. Hasta **8 MB**.
- **Los PDF tienen que tener texto de verdad.** Si el PDF es una **foto escaneada** de una hoja, CEADI no lo puede leer: no reconoce letras dentro de una imagen.
- De documentos muy largos toma los primeros ~12.000 caracteres, que alcanzan para la enorme mayoría de los casos.
- **El archivo no se guarda.** Se lee, se usa para responderte y se descarta.

> Esta función viene **apagada** de fábrica. Se prende desde *CEAD Académico → WhatsApp* en la administración.

### Pedirle acciones en lenguaje natural

Si usan el modo IA, pueden pedir estas acciones en lenguaje natural ("mandá un comunicado a todos avisando que…", "creá una invitación para un profe") y CEADI **pide confirmación antes de ejecutar** (aprobación humana). Cada acción respeta el permiso del rol.

> **Crear invitaciones por chat**: dirección/secretaría pueden pedirle a CEADI *"creá un link de alumno para 30 personas"* y, tras aprobar, reciben **un solo link de registro reutilizable** esa cantidad de veces. Por seguridad, **solo** se pueden crear invitaciones de **alumno, delegado o profe** (nunca de dirección o secretaría). Los links de **delegado** vencen al año; el resto, a los ~3 años.

---

## 7. Administración (wp-admin)

Acceso para dirección/secretaría desde `wp-admin`. El **Escritorio** tiene estética CEAD con **métricas** (alumnos, docentes, cursos, eventos próximos, altas del mes y estado del bot) y accesos rápidos.

| Sección | Para qué |
|---|---|
| **Usuarios** | Crear usuarios y editar todo lo suyo: nombre, email, teléfono, documento, fecha de nacimiento, rol, contraseña y **curso**. |
| **Invitaciones** | Generar links de invitación para sumar gente. |
| **Cursos** | Crear cursos, asignar delegado/tutor/alumnado y **cargar el horario semanal** (día, hora, materia, docente, aula), a mano. |
| **Horarios** | Repasar y corregir el horario semanal de cualquier curso desde un solo lugar, sin entrar curso por curso — útil después de una importación para revisar todo de una. |
| **Comunicados** | Publicar comunicados dirigidos a rol, curso o personas. |
| **Encuestas** | Crear encuestas y ver resultados. |
| **Eventos** | Reuniones, exámenes, actos del calendario. |
| **Recursos** | Subir materiales pedagógicos. |
| **Tareas (delegado)** | Tareas asignadas a cursos. |
| **Importadores** | Cargar por archivo CSV/Excel: **Alumnado, Calificaciones, Cursos, Horario de clases, Horarios/eventos**. El "Horario de clases" carga la grilla semanal de todos los cursos de una vez — pensado para una planilla real de secretaría, no para tipear fila por fila. "Horarios/eventos" acepta fechas en formato **AAAA-MM-DD o DD/MM/AAAA** (feriados y fechas célebres, sin curso asignado, quedan visibles para todo el mundo) y un **color propio opcional** por fila (#RRGGBB; vacío = el color del tipo). Funciona desde cualquier dispositivo; en pantalla chica, la tabla de mapeo se desplaza de costado. Cada import ya hecho tiene un botón **"Borrar lo importado"** para deshacerlo en masa (por ejemplo, al empezar un año nuevo). |
| **WhatsApp** | Estado del bridge, comunicados por WA, reportes, métricas, mensaje directo, **perfil de CEADI**, el interruptor de **Razonamiento** y el puente **Instagram → borrador**. |
| **Actualizaciones** | Ver/actualizar la versión del plugin (ver §9). |

---

## 8. Familias

Las familias entran al panel y ven **calendario, eventos, comunicados, recursos y tareas** a nivel colegio. **No ven calificaciones**, para evitar conflictos.

---

## 9. Actualizaciones del sistema

El plugin se **actualiza solo desde GitHub**: cuando hay una versión nueva, aparece en **Plugins → Actualizaciones** (o en *CEAD Académico → Actualizaciones*) y se instala con un clic. Requiere un **token de GitHub** cargado (en *Actualizaciones* o en `wp-config.php`). Hay un botón **"Probar conexión con GitHub"** para diagnosticar.

---

## 10. Preguntas frecuentes

**No me aparece "Instalar app".**
Abrilo desde el navegador (Chrome/Safari), no desde un link dentro de Instagram/WhatsApp. En iPhone tiene que ser con Safari.

**Cambié algo y no se ve.**
Cerrá y reabrí el panel/app; toma la última versión al cargar con internet.

**CEADI no me responde.**
Tu número tiene que estar **registrado** en el panel. Verificá tu teléfono en *Mi perfil* o pedí a la dirección que lo registre.

**Soy familia y no veo el boletín.**
Es a propósito: las familias no acceden a calificaciones.

**¿Dónde reporto algo o escribo a la dirección?**
Desde el panel en **"Escribir al CEAD"**, o desde CEADI (opciones 6 y 7). Todo llega al **buzón**.

---

*CEAD Académico · Félix de Guarania.*
