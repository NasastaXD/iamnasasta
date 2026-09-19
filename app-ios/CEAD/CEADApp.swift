import SwiftUI
import CeadKit

@main
struct CEADApp: App {
    @StateObject private var modelo = PanelModel()

    var body: some Scene {
        WindowGroup {
            if modelo.sesionAbierta {
                Panel(modelo: modelo)
            } else {
                PantallaLogin(modelo: modelo)
            }
        }
    }
}

struct Panel: View {

    @ObservedObject var modelo: PanelModel
    @State private var solapa = Solapa.horario

    enum Solapa: String, CaseIterable {
        case horario, comunicados, boletin, tareas

        var titulo: String {
            switch self {
            case .horario: return "Horario"
            case .comunicados: return "Comunicados"
            case .boletin: return "Boletín"
            case .tareas: return "Tareas"
            }
        }

        var icono: String {
            switch self {
            case .horario: return "calendar"
            case .comunicados: return "megaphone"
            case .boletin: return "list.clipboard"
            case .tareas: return "checklist"
            }
        }
    }

    private var sinLeer: Int {
        if case .listo(let c) = modelo.comunicados { return c.sinLeer }
        return 0
    }

    var body: some View {
        TabView(selection: $solapa) {
            ForEach(Solapa.allCases, id: \.self) { s in
                NavigationStack {
                    contenido(de: s)
                        .navigationTitle(s.titulo)
                        .navigationBarTitleDisplayMode(.inline)
                        .toolbar {
                            ToolbarItem(placement: .topBarLeading) {
                                if let quien = modelo.perfil?.nombre ?? modelo.nombreRecordado {
                                    Text(quien).font(.caption).foregroundStyle(.secondary)
                                }
                            }
                            ToolbarItem(placement: .topBarTrailing) {
                                Button {
                                    modelo.salir()
                                } label: {
                                    Label("Salir", systemImage: "rectangle.portrait.and.arrow.right")
                                }
                            }
                        }
                }
                .tabItem { Label(s.titulo, systemImage: s.icono) }
                .badge(s == .comunicados ? sinLeer : 0)
                .tag(s)
            }
        }
        .tint(.ceadMarca)
        .sheet(item: $modelo.abierto) { c in
            NavigationStack {
                VistaComunicado(comunicado: c)
                    .toolbar {
                        ToolbarItem(placement: .topBarTrailing) {
                            Button("Listo") { modelo.abierto = nil }
                        }
                    }
            }
        }
    }

    @ViewBuilder
    private func contenido(de solapa: Solapa) -> some View {
        switch solapa {
        case .horario:
            Seccion(carga: modelo.horario, reintentar: modelo.cargarHorario) {
                PantallaHorario(horario: $0)
            }
        case .comunicados:
            Seccion(carga: modelo.comunicados, reintentar: modelo.cargarComunicados) {
                PantallaComunicados(datos: $0, abrir: modelo.abrir)
            }
        case .boletin:
            Seccion(carga: modelo.boletin, reintentar: modelo.cargarBoletin) {
                PantallaBoletin(boletin: $0)
            }
        case .tareas:
            Seccion(carga: modelo.tareas, reintentar: modelo.cargarTareas) {
                PantallaTareas(datos: $0)
            }
        }
    }
}
