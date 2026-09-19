import Foundation
import SwiftUI
import UIKit
import CeadKit

/**
 * Una sección que se está trayendo del servidor.
 *
 * Los tres estados son distintos a propósito: «cargando» no es lo mismo que
 * «vacío», y «vacío» no es lo mismo que «falló». Colapsarlos es lo que produce
 * pantallas que dicen «no hay nada» cuando en realidad se cayó internet.
 */
enum Carga<T> {
    case cargando
    case listo(T)
    case fallo(String)
}

@MainActor
final class PanelModel: ObservableObject {

    /// El sitio del colegio. Vive en el Info.plist (`CeadSitio`) y no en el
    /// código para que apuntar la app a un entorno de prueba sea cambiar un
    /// valor y no salir a buscar la URL por los archivos.
    private static var sitio: String {
        (Bundle.main.object(forInfoDictionaryKey: "CeadSitio") as? String) ?? "https://cead.caaguazu.net"
    }

    private let llavero = Llavero()
    private lazy var api = Api(sitio: Self.sitio, almacen: llavero)

    @Published var sesionAbierta: Bool
    @Published var entrando = false
    @Published var errorLogin: String?

    @Published var perfil: Perfil?
    @Published var horario: Carga<Horario> = .cargando
    @Published var comunicados: Carga<Comunicados> = .cargando
    @Published var boletin: Carga<Boletin> = .cargando
    @Published var tareas: Carga<Tareas> = .cargando
    @Published var abierto: Comunicado?

    /// El nombre de quien entró la última vez, para saludar antes de que cargue.
    var nombreRecordado: String? { llavero.nombre }

    init() {
        sesionAbierta = llavero.haySesion
        if sesionAbierta { cargarTodo() }
    }

    // MARK: - Sesión

    func entrar(usuario: String, clave: String) {
        guard !entrando else { return }
        entrando = true
        errorLogin = nil

        Task {
            do {
                // `Task` dentro de una clase @MainActor hereda el aislamiento,
                // así que UIDevice se lee acá sin salto de hilo.
                let r = try await api.login(
                    usuario: usuario,
                    clave: clave,
                    dispositivo: UIDevice.current.name
                )
                perfil = r.usuario
                sesionAbierta = true
                cargarTodo()
            } catch {
                errorLogin = error.localizedDescription
            }
            entrando = false
        }
    }

    func salir() {
        Task {
            await api.logout()
            limpiar()
        }
    }

    /**
     * El token dejó de valer mientras la app estaba abierta (venció, la
     * suspendieron, cambió la contraseña en otro lado). Se vuelve a la entrada
     * en vez de dejar pantallas a medio cargar con un error incomprensible.
     */
    private func caduco() {
        limpiar()
        errorLogin = "Tu sesión venció. Entrá de nuevo."
    }

    private func limpiar() {
        perfil = nil
        horario = .cargando
        comunicados = .cargando
        boletin = .cargando
        tareas = .cargando
        abierto = nil
        sesionAbierta = false
    }

    // MARK: - Datos

    func cargarTodo() {
        cargarPerfil()
        cargarHorario()
        cargarComunicados()
        cargarBoletin()
        cargarTareas()
    }

    private func cargarPerfil() {
        Task {
            do { perfil = try await api.yo() }
            catch is SesionVencida { caduco() }
            catch {
                // Silencioso a propósito: alimenta el saludo de la barra, no
                // una pantalla entera.
            }
        }
    }

    func cargarHorario() {
        horario = .cargando
        Task { horario = await traer { try await self.api.horario() } }
    }

    func cargarComunicados() {
        comunicados = .cargando
        Task { comunicados = await traer { try await self.api.comunicados() } }
    }

    func cargarBoletin() {
        boletin = .cargando
        Task { boletin = await traer { try await self.api.boletin() } }
    }

    func cargarTareas() {
        tareas = .cargando
        Task { tareas = await traer { try await self.api.tareas() } }
    }

    func abrir(_ id: Int) {
        Task {
            do {
                let c = try await api.comunicado(id: id)
                abierto = c
                marcarLeido(id)
            } catch is SesionVencida {
                caduco()
            } catch {
                // Abrir uno suelto puede fallar sin arruinar la lista.
            }
        }
    }

    /// Abrir un comunicado lo marca leído en el servidor. Si acá siguiera en
    /// negrita, la persona vería que «no se guardó» algo que sí se guardó.
    private func marcarLeido(_ id: Int) {
        guard case .listo(let lista) = comunicados else { return }
        let nuevos = lista.comunicados.map { c -> Comunicado in
            guard c.id == id, !c.leido else { return c }
            return Comunicado(
                id: c.id, titulo: c.titulo, fecha: c.fecha, resumen: c.resumen,
                imagen: c.imagen, categoria: c.categoria, leido: true, contenido: c.contenido
            )
        }
        let yaEstaba = lista.comunicados.first { $0.id == id }?.leido ?? true
        comunicados = .listo(Comunicados(
            comunicados: nuevos,
            pagina: lista.pagina,
            sinLeer: yaEstaba ? lista.sinLeer : max(0, lista.sinLeer - 1)
        ))
    }

    private func traer<T>(_ bloque: @escaping () async throws -> T) async -> Carga<T> {
        do {
            return .listo(try await bloque())
        } catch is SesionVencida {
            caduco()
            return .cargando
        } catch {
            return .fallo(error.localizedDescription)
        }
    }
}
