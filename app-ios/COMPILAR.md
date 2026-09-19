# Compilar la app de iOS

Estos pasos son para una Mac donde no hay nada instalado todavía.

## Antes de empezar

1. **Xcode**, desde la App Store. Son ~10 GB, así que conviene arrancar la
   descarga y hacer otra cosa mientras.
2. Abrilo una vez y aceptá la instalación de componentes que pide sola.

## Generar el proyecto

El `.xcodeproj` no está en el repositorio a propósito: es un archivo generado y
enorme, que produce conflictos ilegibles cada vez que dos personas agregan un
archivo. En su lugar está `project.yml`, que dice lo mismo en treinta líneas.

```bash
# Homebrew, si no está:
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

brew install xcodegen

cd app-ios
xcodegen generate
open CEAD.xcodeproj
```

Si preferís no instalar nada: en Xcode, *File → New → Project → iOS App*
(interfaz SwiftUI), y después arrastrás adentro la carpeta `CEAD/` y agregás
`CeadKit` con *File → Add Package Dependencies → Add Local*. El `project.yml`
tiene los valores que hay que poner (identificador, versión, iOS 16 mínimo).

## Probar

Con el proyecto abierto, elegí un simulador de iPhone arriba y apretá ▶.

Para probar en un iPhone de verdad hace falta una cuenta de Apple en
*Xcode → Settings → Accounts*. Con una cuenta gratuita alcanza para instalarlo
en tu propio teléfono, aunque caduca cada siete días; para publicar hace falta
la cuenta de desarrollador de pago.

## A qué servidor apunta

A `https://cead.caaguazu.net`, definido en `project.yml` bajo `CeadSitio`. Si el
dominio real es otro, se cambia ahí y se vuelve a correr `xcodegen generate`.

No se edita el `Info.plist` directamente: ese archivo lo genera XcodeGen desde
`project.yml`, así que un cambio hecho a mano dura hasta la próxima
regeneración y desaparece sin avisar.

## Qué está verificado y qué no

`CeadKit` —los modelos y el cliente de la API— **compila y pasa sus 16 tests**,
porque es Foundation puro y se puede correr fuera de una Mac. Los tests usan el
JSON exacto que arma el plugin, así que cubren la frontera con el servidor, que
es lo que puede romperse sin que nadie toque una línea de Swift.

Los archivos de `CEAD/` (SwiftUI, Llavero) **solo tienen la sintaxis revisada**.
Sin Xcode no hay forma de verificar tipos ni de ver una pantalla, así que es
probable que el primer intento de compilación en la Mac tire algún error de
tipos. Si pasa, mandame el mensaje y lo arreglo.

## Antes de publicar en la App Store

- El identificador es `net.caaguazu.cead.panel`, igual que el de Android. **Una vez
  publicado no se puede cambiar**, así que conviene confirmarlo antes.
- La cuenta de desarrollador de Apple cuesta US$99 al año. Según lo que
  hablamos, ya está paga.
