# CEAD Académico — la app

La app del colegio para Android y iPhone. **Es el panel entero**: todo lo que se
hace en el sitio (ver el horario, leer comunicados, responder encuestas,
escribir al colegio, cargar notas, publicar comunicados, el buzón…) se hace
también desde acá, y **anda sin conexión**: en el colegio no hay wifi.

Es un solo código en Kotlin (Compose Multiplatform) que corre en los dos
sistemas. El identificador es `net.caaguazu.cead.panel` en ambas tiendas.

## Cómo funciona sin conexión

Las pantallas **nunca le preguntan nada al servidor**: leen lo que hay guardado
en el teléfono. Una sincronización aparte (al abrir la app, al volver a ella,
al tirar de la pantalla, y cada minuto mientras haya algo esperando) baja la
foto completa de la persona y manda lo que quedó pendiente.

- **Bajar**: `GET /sincronizar` trae todo en un pedido, con versión (ETag). Si
  no cambió nada, el servidor contesta 304 sin cuerpo: cuesta unos bytes.
- **Subir**: cada acción (marcar una tarea, responder una encuesta, cargar una
  nota, publicar un comunicado…) entra a una **cola** guardada en disco, con una
  clave de idempotencia. Si el pedido se corta a la mitad y se reintenta, el
  servidor no la repite. Lo que el servidor rechaza para siempre queda a la
  vista en *Envíos pendientes*, sin frenar al resto.
- **Ver lo pendiente como hecho**: la pantalla muestra la copia del servidor con
  los envíos pendientes encima (`Proyeccion`), así que marcar una tarea sin señal
  la deja hecha al instante.
- **Lo que no se guarda**: el buzón de coordinación, los teléfonos de los
  delegados y las notas de otros alumnos se miran **en línea** y no quedan en el
  teléfono. La conversación con CEADI vive solo en memoria.
- **Sesión**: al cerrar sesión se borra todo. Si la sesión vence, lo local se
  conserva para la misma persona; si entra otra, se borra lo de la anterior.

## Qué hay en cada carpeta

| Carpeta | Qué es |
|---|---|
| `shared/` | **Casi toda la app**: datos, cola, sincronización y las pantallas |
| `shared/src/commonMain` | El código común (Android + iOS) |
| `shared/src/androidMain` | Lo propio de Android: archivos, avisos, almacén cifrado |
| `shared/src/iosMain` | Lo propio de iPhone: llavero, selector de fotos, avisos |
| `shared/src/commonTest`, `desktopTest` | Las pruebas y las capturas de pantalla |
| `androidApp/` | El envoltorio de Android (manifiesto, actividad, Firebase) |
| `iosApp/` | El envoltorio de iPhone (Swift: arranque y Firebase) |

Lo único que cambia entre sistemas se concentra en la interfaz `Plataforma`
(`shared/.../plataforma/Plataforma.kt`): cuanto más corta sea esa lista, menos
código hay que probar a ciegas en el otro sistema.

## Compilar

Pasos completos en [`COMPILAR.md`](COMPILAR.md). Resumen:

```bash
# Pruebas (corren en cualquier máquina, sin emulador)
./gradlew :shared:desktopTest

# Android
./gradlew :androidApp:assembleDebug      # APK para probar
./gradlew :androidApp:bundleRelease      # paquete para Google Play

# iPhone (en una Mac): ver COMPILAR.md
```

## Qué está verificado y qué no

| | |
|---|---|
| Lógica (datos, cola, sincronización, proyección, fechas, filtros) | ✅ 127 pruebas, corren en cada compilación |
| Pantallas | ✅ Se dibujan en pruebas y se revisan como imagen (alumno y staff, claro y oscuro) |
| Android | ✅ Compila en debug y en release (R8) |
| El código común es independiente del sistema | ✅ Compila como metadatos puros |
| **iPhone** | ⚠️ El código **no se pudo compilar** donde se escribió (hace falta una Mac). Es muy probable que el primer intento tire algún error de tipos en `shared/src/iosMain`; ahí es donde hay que mirar |
| Avisos al teléfono | ⚠️ El servidor está probado; la entrega real necesita el proyecto de Firebase del colegio (ver `cead-acad/docs/AVISOS-PUSH.md`) |

## Una advertencia sobre los números de versión

Google Play y la App Store **rechazan** un paquete cuyo número no sea mayor al
último que se subió. Las versiones salen de:

- Android: `androidApp/build.gradle.kts` (`versionCode` empieza en 10000, a
  propósito alto; se puede forzar con `-Pcead.versionCode=N`).
- iPhone: `iosApp/project.yml` (`MARKETING_VERSION`, `CURRENT_PROJECT_VERSION`).

Mirá el número de lo que ya está publicado en Play Console y App Store Connect
antes de subir.
