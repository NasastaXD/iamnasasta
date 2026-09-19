import SwiftUI

/// Los colores de la marca, los mismos que ya usa el PWA del panel.
extension Color {
    static let ceadMarca = Color(red: 0xE9 / 255, green: 0x3B / 255, blue: 0x3C / 255)
    static let ceadFondo = Color(red: 0xF7 / 255, green: 0xF5 / 255, blue: 0xF0 / 255)
}

/**
 * Dibuja una sección según en qué estado esté.
 *
 * Existe para que ninguna pantalla se olvide de los tres casos. Una lista vacía
 * porque todavía no llegó nada, otra porque de verdad no hay nada, y otra
 * porque se cayó internet son tres situaciones que la persona resuelve de tres
 * maneras; mostrarlas igual la deja esperando algo que no va a pasar.
 */
struct Seccion<T, Contenido: View>: View {
    let carga: Carga<T>
    let reintentar: () -> Void
    @ViewBuilder let contenido: (T) -> Contenido

    var body: some View {
        switch carga {
        case .cargando:
            ProgressView()
                .frame(maxWidth: .infinity, maxHeight: .infinity)
        case .fallo(let mensaje):
            VStack(spacing: 12) {
                Text(mensaje)
                    .multilineTextAlignment(.center)
                    .foregroundStyle(.secondary)
                Button("Reintentar", action: reintentar)
                    .buttonStyle(.borderedProminent)
            }
            .padding(24)
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        case .listo(let datos):
            contenido(datos)
        }
    }
}

/**
 * El estado vacío de una sección.
 *
 * Dice qué falta y por qué, no solo «no hay nada»: a principio de año media app
 * está legítimamente vacía, y una pantalla en blanco sin explicación se lee
 * como que la app está rota.
 */
struct Vacio: View {
    let titulo: String
    let detalle: String

    var body: some View {
        VStack(spacing: 8) {
            Text(titulo).font(.headline)
            Text(detalle)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
        }
        .padding(32)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}
