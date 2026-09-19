import Foundation

/*
 * Lo que devuelve `cead-acad/v1`, tal cual.
 *
 * Los nombres están en castellano porque así los manda el servidor. Donde la
 * clave trae guion bajo (`rol_label`, `sin_leer`) se traduce con CodingKeys y
 * nada más: inventar nombres distintos obligaría a mantener un diccionario
 * mental entre este archivo y el PHP.
 */

public struct Curso: Codable, Hashable, Sendable {
    public let id: Int
    public let titulo: String
}

public struct Perfil: Codable, Hashable, Sendable {
    public let id: Int
    public let nombre: String
    public let email: String
    public let rol: String
    public let rolLabel: String
    public let caps: [String]
    public let curso: Curso?
    public let avatar: String?

    enum CodingKeys: String, CodingKey {
        case id, nombre, email, rol, caps, curso, avatar
        case rolLabel = "rol_label"
    }

    public func puede(_ cap: String) -> Bool { caps.contains(cap) }
}

public struct RespuestaLogin: Codable, Sendable {
    public let token: String
    public let vence: Int
    public let usuario: Perfil
}

public struct Sesion: Codable, Hashable, Identifiable, Sendable {
    public let id: String
    public let nombre: String
    public let creado: Int
    public let usado: Int
    public let actual: Bool
}

public struct Sesiones: Codable, Sendable {
    public let sesiones: [Sesion]
}

// MARK: - Horario

public struct Franja: Codable, Hashable, Sendable {
    public let inicio: String
    public let fin: String
    public let materia: String
    public let docente: String
    public let aula: String
}

public struct DiaHorario: Codable, Hashable, Identifiable, Sendable {
    public let dia: Int
    public let franjas: [Franja]

    public var id: Int { dia }

    /// Lunes = 1, como los guarda el plugin.
    public var nombre: String {
        switch dia {
        case 1: return "Lunes"
        case 2: return "Martes"
        case 3: return "Miércoles"
        case 4: return "Jueves"
        case 5: return "Viernes"
        case 6: return "Sábado"
        case 7: return "Domingo"
        default: return "Día \(dia)"
        }
    }
}

public struct Horario: Codable, Sendable {
    public let curso: Curso?
    public let dias: [DiaHorario]
    public let motivo: String?
}

// MARK: - Comunicados

public struct Categoria: Codable, Hashable, Sendable {
    public let slug: String
    public let nombre: String
}

public struct Comunicado: Codable, Hashable, Identifiable, Sendable {
    public let id: Int
    public let titulo: String
    public let fecha: String
    public let resumen: String
    public let imagen: String?
    public let categoria: Categoria?
    public let leido: Bool
    public let contenido: String?
}

public struct Comunicados: Codable, Sendable {
    public let comunicados: [Comunicado]
    public let pagina: Int
    public let sinLeer: Int

    enum CodingKeys: String, CodingKey {
        case comunicados, pagina
        case sinLeer = "sin_leer"
    }
}

// MARK: - Boletín

public struct Nota: Codable, Hashable, Sendable {
    public let nota: Double?
    public let letra: String
    public let comentarios: String
    public let cargada: String

    /// Lo que se muestra en la celda: la letra si la hay, si no el número.
    /// Sin decimales cuando no hacen falta — «4» se lee mejor que «4.0».
    public var texto: String {
        if !letra.isEmpty { return letra }
        guard let n = nota else { return "—" }
        return n == n.rounded() ? String(Int(n)) : String(n)
    }
}

public struct MateriaBoletin: Codable, Hashable, Identifiable, Sendable {
    public let materia: String
    public let curso: String
    /// Clave: el nombre del período. Es un diccionario y no una lista porque el
    /// colegio puede renombrar o agregar períodos sin que la app tenga que
    /// saber de antemano cuáles existen.
    public let notas: [String: Nota]

    public var id: String { materia + "|" + curso }
}

public struct Boletin: Codable, Sendable {
    public let materias: [MateriaBoletin]
    public let periodos: [String]
}

// MARK: - Tareas

public struct Tarea: Codable, Hashable, Identifiable, Sendable {
    public let id: Int
    public let titulo: String
    public let detalle: String
    public let vence: String?
    public let prioridad: String
    public let hecha: Bool
}

public struct Tareas: Codable, Sendable {
    public let tareas: [Tarea]
}

// MARK: - Calendario

public struct Evento: Codable, Hashable, Identifiable, Sendable {
    public let id: Int
    public let titulo: String
    public let detalle: String
    public let inicio: String
    public let fin: String
    public let lugar: String
}

public struct Calendario: Codable, Sendable {
    public let eventos: [Evento]
}

// MARK: - Errores

/// La forma en que WordPress serializa un `WP_Error`.
struct ErrorApi: Codable {
    let code: String
    let message: String
}

/// El pedido falló y la app tiene algo concreto que mostrar.
public struct ApiError: Error, LocalizedError, Equatable {
    public let codigo: String
    public let mensaje: String

    public var errorDescription: String? { mensaje }
}

/// La sesión ya no vale y hay que volver a pedir la contraseña.
///
/// Es su propio tipo y no un `ApiError` más porque la reacción es distinta: no
/// se muestra un cartel rojo, se borra el token y se manda a la pantalla de
/// entrada. Confundirlos deja a la persona mirando «no autorizado» en una
/// pantalla vacía, sin forma de volver a entrar.
public struct SesionVencida: Error, LocalizedError, Equatable {
    public let mensaje: String

    public var errorDescription: String? { mensaje }
}
