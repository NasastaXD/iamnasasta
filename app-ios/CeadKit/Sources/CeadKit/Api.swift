import Foundation

#if canImport(FoundationNetworking)
// En Linux, URLSession vive en un módulo aparte. Importarlo condicionalmente es
// lo que permite compilar y probar este archivo fuera de una Mac, que es la
// única forma de verificar algo de iOS sin Xcode.
import FoundationNetworking
#endif

/// Dónde se guarda el token. La implementación de verdad es el Llavero, que
/// solo existe en Apple; acá va el hueco para poder probar sin él.
public protocol AlmacenDeSesion: AnyObject, Sendable {
    var token: String? { get set }
    var nombre: String? { get set }
    func limpiar()
}

public extension AlmacenDeSesion {
    var haySesion: Bool { !(token ?? "").isEmpty }
}

/// Quien hace el pedido HTTP. Se puede sustituir en los tests para no salir a
/// la red: probar el cliente contra un servidor real probaría el servidor.
public protocol Transporte: Sendable {
    func pedir(_ req: URLRequest) async throws -> (Data, HTTPURLResponse)
}

public struct TransporteURLSession: Transporte {
    let session: URLSession

    public init(session: URLSession = .shared) {
        self.session = session
    }

    public func pedir(_ req: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let (datos, respuesta) = try await session.data(for: req)
        guard let http = respuesta as? HTTPURLResponse else {
            throw ApiError(codigo: "sin_respuesta", mensaje: "No se pudo conectar con el CEAD.")
        }
        return (datos, http)
    }
}

public actor Api {

    private let sitio: String
    private let almacen: AlmacenDeSesion
    private let transporte: Transporte
    private let decodificador: JSONDecoder

    public init(sitio: String, almacen: AlmacenDeSesion, transporte: Transporte = TransporteURLSession()) {
        self.sitio = sitio
        self.almacen = almacen
        self.transporte = transporte
        self.decodificador = JSONDecoder()
    }

    // MARK: - Sesión

    @discardableResult
    public func login(usuario: String, clave: String, dispositivo: String) async throws -> RespuestaLogin {
        var req = try pedido("/auth/login", metodo: "POST", autorizado: false)
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        req.httpBody = try JSONSerialization.data(withJSONObject: [
            "usuario": usuario.trimmingCharacters(in: .whitespacesAndNewlines),
            "clave": clave,
            "dispositivo": dispositivo,
        ])

        let datos: RespuestaLogin = try await enviar(req)
        almacen.token = datos.token
        almacen.nombre = datos.usuario.nombre
        return datos
    }

    public func logout() async {
        // Se avisa al servidor para que el token muera del otro lado también,
        // pero el borrado local no depende de que ese aviso llegue: si falla,
        // salir de la sesión igual tiene que funcionar.
        if let req = try? pedido("/auth/logout", metodo: "POST") {
            _ = try? await transporte.pedir(req)
        }
        almacen.limpiar()
    }

    public func yo() async throws -> Perfil {
        try await enviar(try pedido("/me"))
    }

    public func sesiones() async throws -> Sesiones {
        try await enviar(try pedido("/auth/sesiones"))
    }

    public func cerrarSesion(id: String) async throws {
        let (datos, http) = try await transporte.pedir(try pedido("/auth/sesiones/\(id)", metodo: "DELETE"))
        try revisar(datos: datos, http: http)
    }

    // MARK: - Datos

    public func horario(curso: Int? = nil) async throws -> Horario {
        try await enviar(try pedido("/horario", consulta: curso.map { [("curso", String($0))] } ?? []))
    }

    public func comunicados(pagina: Int = 1) async throws -> Comunicados {
        try await enviar(try pedido("/comunicados", consulta: [("pagina", String(pagina))]))
    }

    public func comunicado(id: Int) async throws -> Comunicado {
        try await enviar(try pedido("/comunicados/\(id)"))
    }

    public func boletin() async throws -> Boletin {
        try await enviar(try pedido("/boletin"))
    }

    public func tareas() async throws -> Tareas {
        try await enviar(try pedido("/tareas"))
    }

    public func calendario(desde: String? = nil, hasta: String? = nil) async throws -> Calendario {
        var consulta: [(String, String)] = []
        if let desde { consulta.append(("desde", desde)) }
        if let hasta { consulta.append(("hasta", hasta)) }
        return try await enviar(try pedido("/calendario", consulta: consulta))
    }

    // MARK: - Interno

    func pedido(
        _ ruta: String,
        metodo: String = "GET",
        consulta: [(String, String)] = [],
        autorizado: Bool = true
    ) throws -> URLRequest {
        let base = sitio.hasSuffix("/") ? String(sitio.dropLast()) : sitio
        guard var componentes = URLComponents(string: base + "/wp-json/cead-acad/v1" + ruta) else {
            throw ApiError(codigo: "url_invalida", mensaje: "La dirección del CEAD no es válida.")
        }
        if !consulta.isEmpty {
            componentes.queryItems = consulta.map { URLQueryItem(name: $0.0, value: $0.1) }
        }
        guard let url = componentes.url else {
            throw ApiError(codigo: "url_invalida", mensaje: "La dirección del CEAD no es válida.")
        }

        var req = URLRequest(url: url)
        req.httpMethod = metodo
        if autorizado, let token = almacen.token, !token.isEmpty {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        return req
    }

    private func enviar<T: Decodable>(_ req: URLRequest) async throws -> T {
        let (datos, http) = try await transporte.pedir(req)
        try revisar(datos: datos, http: http)
        do {
            return try decodificador.decode(T.self, from: datos)
        } catch {
            throw ApiError(
                codigo: "respuesta_ilegible",
                mensaje: "El CEAD respondió algo que no se pudo leer."
            )
        }
    }

    /// Traduce un error HTTP en algo que la app pueda mostrar o actuar.
    ///
    /// El mensaje sale del propio `WP_Error` —ya viene escrito en castellano y
    /// pensado para una persona— en vez de armar uno acá. Si el cuerpo no se
    /// puede leer, recién ahí se inventa uno genérico.
    func revisar(datos: Data, http: HTTPURLResponse) throws {
        guard !(200...299).contains(http.statusCode) else { return }

        let error = try? decodificador.decode(ErrorApi.self, from: datos)
        let mensaje = (error?.message).flatMap { $0.isEmpty ? nil : $0 }
            ?? "No se pudo conectar con el CEAD (\(http.statusCode))."

        if http.statusCode == 401 {
            almacen.limpiar()
            throw SesionVencida(mensaje: mensaje)
        }
        throw ApiError(codigo: error?.code ?? "http_\(http.statusCode)", mensaje: mensaje)
    }
}
