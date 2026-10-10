# Compilar la app

## Lo común: pruebas

No necesitan emulador ni teléfono.

```bash
./gradlew :shared:desktopTest
```

Para sacar capturas de todas las pantallas (útil para revisar el diseño sin
abrir un emulador):

```bash
CEAD_CAPTURAS=/una/carpeta ./gradlew :shared:desktopTest --tests '*CapturasTest*'
```

> Maven Central a veces contesta `429 Too Many Requests` al bajar dependencias
> por primera vez. No es un error del proyecto: se espera un minuto y se vuelve
> a correr (con `--max-workers=1` si insiste).

## Android

Necesita el JDK 17 y el SDK de Android (Android Studio los trae). En
`local.properties` va `sdk.dir=/ruta/al/sdk` si Gradle no lo encuentra solo.

```bash
./gradlew :androidApp:assembleDebug     # app/androidApp/build/outputs/apk/debug
./gradlew :androidApp:bundleRelease     # paquete para Google Play (.aab)
```

### Firmar para publicar

La llave **no va en el repositorio**. Se pone en `~/.gradle/gradle.properties`
(fuera del proyecto):

```properties
cead.keystore=/ruta/a/la/llave.jks
cead.keystorePassword=...
cead.keyAlias=...
cead.keyPassword=...
```

Tiene que ser **la misma llave de subida** con la que ya se publicó la app en
Google Play: con otra, Play rechaza la actualización. Sin estas líneas se
genera un paquete sin firmar.

### Avisos (Firebase)

Copiá `google-services.json` (de la consola de Firebase) a `androidApp/`. Sin
ese archivo la app compila y anda igual, solo que sin avisos.
Detalle en [`../cead-acad/docs/AVISOS-PUSH.md`](../cead-acad/docs/AVISOS-PUSH.md).

## iPhone (en una Mac)

Una Mac sin nada instalado:

1. **Xcode**, desde la App Store (~10 GB: conviene arrancar la descarga y hacer
   otra cosa). Abrilo una vez y aceptá los componentes que pide.
2. **JDK 17** (`brew install --cask temurin@17`) y **XcodeGen**
   (`brew install xcodegen`).

Después:

```bash
cd app/iosApp
xcodegen generate
open CEAD.xcodeproj
```

Elegí un simulador de iPhone arriba y apretá ▶. La primera vez tarda: Xcode
corre Gradle para compilar el código Kotlin y descarga Firebase.

El `.xcodeproj` no está en el repositorio a propósito: es un archivo generado y
enorme. Si hay que cambiar algo del proyecto (identificador, versión, permisos),
se edita `iosApp/project.yml` y se vuelve a correr `xcodegen generate`. **No se
edita `Info.plist` ni el `.xcodeproj` a mano**: se regeneran y el cambio
desaparece.

### Si no compila

El código de `shared/src/iosMain` y los archivos Swift de `iosApp/CEAD` se
escribieron sin poder compilarlos (hace falta una Mac). Es probable que el
primer intento tire algún error de tipos. Copiá el mensaje completo de Xcode (o
de Gradle, que muestra los errores de Kotlin) y se arregla: todo lo que habla con
iOS está en esos dos lugares, y son pocos archivos:

- `shared/src/iosMain/.../plataforma/` — llavero, selector de fotos y archivos,
  visor, compartir, avisos.
- `shared/src/iosMain/.../MainViewController.kt` — el punto de entrada.
- `iosApp/CEAD/*.swift` — el arranque y Firebase.

### Probar en un iPhone de verdad

Hace falta una cuenta de Apple en *Xcode → Settings → Accounts*. Con una cuenta
gratuita se puede instalar en el teléfono propio (caduca cada siete días), pero
**no** con avisos: la capacidad de notificaciones necesita la cuenta de
desarrollador de pago. Si la cuenta es gratuita, hay que sacar el bloque
`entitlements` de `iosApp/project.yml` para que Xcode firme.

### Avisos (Firebase)

Copiá `GoogleService-Info.plist` a `iosApp/CEAD/` **antes** de generar el
proyecto. Sin él la app anda, solo que sin avisos. Además hay que subir la clave
APNs (`.p8`) a Firebase: ver
[`../cead-acad/docs/AVISOS-PUSH.md`](../cead-acad/docs/AVISOS-PUSH.md).

### Publicar

Con el esquema `CEAD` y *Any iOS Device*: *Product → Archive* y después
*Distribute App*. **Antes**, mirá en App Store Connect qué versión y número de
compilación están publicados: hay que subir uno mayor (en `iosApp/project.yml`).

El identificador es `net.caaguazu.cead.panel`, igual que el de Android, y **no
se puede cambiar una vez publicado**.
