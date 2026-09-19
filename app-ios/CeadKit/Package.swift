// swift-tools-version: 5.9
import PackageDescription

/*
 * La lógica de la app separada de la app.
 *
 * No es una ceremonia de arquitectura: es lo único de iOS que se puede compilar
 * y probar fuera de una Mac. Los modelos y el cliente de la API son Foundation
 * puro, así que entran acá y se verifican en cualquier lado; SwiftUI y el
 * Llavero se quedan en el target de la app, donde no hay forma de probarlos sin
 * Xcode.
 *
 * El corte también es el correcto por sí solo: es la parte que más se toca
 * cuando la API cambia y la que más barato conviene tener bajo test.
 */
let package = Package(
    name: "CeadKit",
    platforms: [.iOS(.v16), .macOS(.v13)],
    products: [
        .library(name: "CeadKit", targets: ["CeadKit"]),
    ],
    targets: [
        .target(name: "CeadKit"),
        .testTarget(name: "CeadKitTests", dependencies: ["CeadKit"]),
    ]
)
