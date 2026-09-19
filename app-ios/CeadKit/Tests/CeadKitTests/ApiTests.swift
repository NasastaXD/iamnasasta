import XCTest
@testable import CeadKit

#if canImport(FoundationNetworking)
import FoundationNetworking
#endif

/**
 * Lo que se prueba acá es la frontera con el servidor.
 *
 * El JSON de estos tests no es inventado: es la forma exacta que arma
 * `Cead_Acad_API` en el plugin. Esa es la única parte de la app de iOS que
 * puede romperse sin que nadie toque una línea de Swift —alcanza con que el
 * PHP renombre un campo— y la única que se puede verificar sin una Mac.
 */
final class ApiTests: XCTestCase {

    // MARK: - Dobles

    final class AlmacenFalso: AlmacenDeSesion, @unchecked Sendable {
        var token: String?
        var nombre: String?
        var limpiado = false

        init(token: String? = "7.aabbccddeeff.\(String(repeating: "a", count: 64))") {
            self.token = token
        }

        func limpiar() {
            token = nil
            nombre = nil
            limpiado = true
        }
    }

    struct TransporteFalso: Transporte, @unchecked Sendable {
        let cuerpo: Data
        let codigo: Int
        let espia: Espia?

        init(_ json: String, codigo: Int = 200, espia: Espia? = nil) {
            self.cuerpo = Data(json.utf8)
            self.codigo = codigo
            self.espia = espia
        }

        func pedir(_ req: URLRequest) async throws -> (Data, HTTPURLResponse) {
            espia?.ultimo = req
            let http = HTTPURLResponse(
                url: req.url!,
                statusCode: codigo,
                httpVersion: nil,
                headerFields: nil
            )!
            return (cuerpo, http)
        }
    }

    final class Espia: @unchecked Sendable {
        var ultimo: URLRequest?
    }

    private func api(
        _ json: String,
        codigo: Int = 200,
        almacen: AlmacenFalso = AlmacenFalso(),
        espia: Espia? = nil
    ) -> (Api, AlmacenFalso) {
        (
            Api(
                sitio: "https://cead.edu.py",
                almacen: almacen,
                transporte: TransporteFalso(json, codigo: codigo, espia: espia)
            ),
            almacen
        )
    }

    // MARK: - Contrato con el plugin

    func testDecodificaElPerfilConSuRolLabel() async throws {
        let (cliente, _) = api("""
        {"id":12,"nombre":"Ana López","email":"ana@cead.edu.py","rol":"cead_acad_student",
         "rol_label":"Estudiante","caps":["cead_acad_view_own_grades"],
         "curso":{"id":20,"titulo":"2.º Servicios Turísticos"},"avatar":null}
        """)

        let perfil = try await cliente.yo()

        XCTAssertEqual(perfil.nombre, "Ana López")
        XCTAssertEqual(perfil.rolLabel, "Estudiante", "rol_label llega con guion bajo del servidor")
        XCTAssertEqual(perfil.curso?.titulo, "2.º Servicios Turísticos")
        XCTAssertTrue(perfil.puede("cead_acad_view_own_grades"))
        XCTAssertFalse(perfil.puede("cead_acad_manage_roles"))
    }

    func testDecodificaElHorarioAgrupadoPorDia() async throws {
        let (cliente, _) = api("""
        {"curso":{"id":20,"titulo":"2.º"},"dias":[
          {"dia":1,"franjas":[{"inicio":"07:00","fin":"08:00","materia":"Matemática","docente":"Sanny","aula":"3"}]},
          {"dia":3,"franjas":[]}
        ]}
        """)

        let horario = try await cliente.horario()

        XCTAssertEqual(horario.dias.count, 2)
        XCTAssertEqual(horario.dias[0].nombre, "Lunes")
        XCTAssertEqual(horario.dias[1].nombre, "Miércoles")
        XCTAssertEqual(horario.dias[0].franjas.first?.docente, "Sanny")
    }

    /// Un curso sin horario cargado no es un error: el servidor manda `curso`
    /// en null y un motivo. La app tiene que poder decir «todavía no te
    /// asignaron curso» en vez de mostrar una falla.
    func testUnCursoSinAsignarLlegaComoNuloYNoComoError() async throws {
        let (cliente, _) = api(#"{"curso":null,"dias":[],"motivo":"sin_curso"}"#)

        let horario = try await cliente.horario()

        XCTAssertNil(horario.curso)
        XCTAssertEqual(horario.motivo, "sin_curso")
    }

    func testDecodificaComunicadosConSuContadorSinLeer() async throws {
        let (cliente, _) = api("""
        {"comunicados":[
          {"id":5,"titulo":"Acto del 14","fecha":"2026-05-10T12:00:00+00:00","resumen":"Vengan",
           "imagen":null,"categoria":{"slug":"actos","nombre":"Actos"},"leido":false}
        ],"pagina":1,"sin_leer":3}
        """)

        let lista = try await cliente.comunicados()

        XCTAssertEqual(lista.sinLeer, 3, "sin_leer llega con guion bajo del servidor")
        XCTAssertEqual(lista.comunicados.first?.categoria?.nombre, "Actos")
        XCTAssertFalse(lista.comunicados.first!.leido)
    }

    /// El boletín viene con las notas en un diccionario por período, no en una
    /// lista: el colegio puede renombrar o agregar períodos sin que la app se
    /// entere.
    func testDecodificaElBoletinConPeriodosVariables() async throws {
        let (cliente, _) = api("""
        {"materias":[
          {"materia":"Historia","curso":"2.º","notas":{
            "1er trimestre":{"nota":4,"letra":"","comentarios":"","cargada":"2026-04-01 10:00:00"},
            "2do trimestre":{"nota":null,"letra":"FI","comentarios":"Falta","cargada":"2026-07-01 10:00:00"}
          }}
        ],"periodos":["1er trimestre","2do trimestre"]}
        """)

        let boletin = try await cliente.boletin()
        let historia = boletin.materias[0]

        XCTAssertEqual(boletin.periodos, ["1er trimestre", "2do trimestre"])
        XCTAssertEqual(historia.notas["1er trimestre"]?.texto, "4", "sin decimales de más")
        XCTAssertEqual(historia.notas["2do trimestre"]?.texto, "FI", "la letra gana sobre el número")
        XCTAssertNil(historia.notas["2do trimestre"]?.nota)
    }

    func testUnaTareaSinVencimientoNoRompeElDecodificado() async throws {
        let (cliente, _) = api("""
        {"tareas":[{"id":9,"titulo":"Leer","detalle":"","vence":null,"prioridad":"alta","hecha":false}]}
        """)

        let tareas = try await cliente.tareas()

        XCTAssertNil(tareas.tareas[0].vence)
        XCTAssertEqual(tareas.tareas[0].prioridad, "alta")
    }

    // MARK: - Sesión

    func testElLoginGuardaElTokenYElNombre() async throws {
        let almacen = AlmacenFalso(token: nil)
        let (cliente, _) = api("""
        {"token":"12.aabbccddeeff.ff","vence":1790000000,
         "usuario":{"id":12,"nombre":"Ana","email":"a@b.c","rol":"cead_acad_student",
                    "rol_label":"Estudiante","caps":[],"curso":null,"avatar":null}}
        """, almacen: almacen)

        try await cliente.login(usuario: "ana", clave: "secreta", dispositivo: "iPhone de Ana")

        XCTAssertEqual(almacen.token, "12.aabbccddeeff.ff")
        XCTAssertEqual(almacen.nombre, "Ana")
    }

    /**
     * El caso que justifica que `SesionVencida` sea su propio tipo.
     *
     * Un 401 no es «mostrá un cartel rojo»: es «esta sesión murió». Si llegara
     * como un error común, la persona se quedaría mirando «no autorizado» en
     * una pantalla vacía, con el token muerto guardado y sin forma de volver a
     * la pantalla de entrada.
     */
    func testUn401TiraSesionVencidaYBorraElToken() async {
        let almacen = AlmacenFalso()
        let (cliente, _) = api(
            #"{"code":"cead_api_token_invalido","message":"Sesión vencida. Entrá de nuevo."}"#,
            codigo: 401,
            almacen: almacen
        )

        do {
            _ = try await cliente.yo()
            XCTFail("un 401 tiene que tirar")
        } catch let e as SesionVencida {
            XCTAssertEqual(e.mensaje, "Sesión vencida. Entrá de nuevo.")
        } catch {
            XCTFail("tiró \(type(of: error)) en vez de SesionVencida")
        }

        XCTAssertNil(almacen.token, "el token muerto no se guarda")
        XCTAssertTrue(almacen.limpiado)
    }

    /// Un 403 NO es sesión vencida: la sesión sirve, lo que falta es permiso.
    /// Borrar el token ahí echaría a alguien por pedir algo que no le tocaba.
    func testUn403NoBorraLaSesion() async {
        let almacen = AlmacenFalso()
        let (cliente, _) = api(
            #"{"code":"cead_api_sin_permiso","message":"No tenés permiso para ver boletines."}"#,
            codigo: 403,
            almacen: almacen
        )

        do {
            _ = try await cliente.boletin()
            XCTFail("un 403 tiene que tirar")
        } catch let e as ApiError {
            XCTAssertEqual(e.codigo, "cead_api_sin_permiso")
            XCTAssertEqual(e.mensaje, "No tenés permiso para ver boletines.")
        } catch {
            XCTFail("tiró \(type(of: error)) en vez de ApiError")
        }

        XCTAssertNotNil(almacen.token, "la sesión sigue siendo válida")
        XCTAssertFalse(almacen.limpiado)
    }

    /// El mensaje se toma del `WP_Error`, que ya viene escrito para una
    /// persona. Solo cuando el cuerpo no se puede leer se arma uno genérico.
    func testUnaRespuestaDeErrorIlegibleIgualDaUnMensajeUtil() async {
        let (cliente, _) = api("no soy json", codigo: 500)

        do {
            _ = try await cliente.tareas()
            XCTFail("un 500 tiene que tirar")
        } catch let e as ApiError {
            XCTAssertTrue(e.mensaje.contains("500"))
        } catch {
            XCTFail("tiró \(type(of: error)) en vez de ApiError")
        }
    }

    // MARK: - Armado del pedido

    func testMandaElTokenComoBearer() async throws {
        let espia = Espia()
        let (cliente, _) = api(#"{"tareas":[]}"#, espia: espia)

        _ = try await cliente.tareas()

        let auth = espia.ultimo?.value(forHTTPHeaderField: "Authorization")
        XCTAssertEqual(auth, "Bearer 7.aabbccddeeff.\(String(repeating: "a", count: 64))")
    }

    /// El login no lleva token: es el pedido que sirve JUSTAMENTE para no
    /// tener uno. Mandar el viejo haría que un token vencido se colara en el
    /// intento de renovarlo.
    func testElLoginNoMandaElTokenViejo() async throws {
        let espia = Espia()
        let (cliente, _) = api("""
        {"token":"x","vence":0,"usuario":{"id":1,"nombre":"A","email":"","rol":"",
         "rol_label":"","caps":[],"curso":null,"avatar":null}}
        """, espia: espia)

        try await cliente.login(usuario: "ana", clave: "x", dispositivo: "iPhone")

        XCTAssertNil(espia.ultimo?.value(forHTTPHeaderField: "Authorization"))
    }

    func testArmaLaUrlConLaRutaDelPluginYLosParametros() async throws {
        let espia = Espia()
        let (cliente, _) = api(#"{"comunicados":[],"pagina":2,"sin_leer":0}"#, espia: espia)

        _ = try await cliente.comunicados(pagina: 2)

        let url = espia.ultimo?.url?.absoluteString
        XCTAssertEqual(url, "https://cead.edu.py/wp-json/cead-acad/v1/comunicados?pagina=2")
    }

    /// Una barra de más en la dirección del sitio no puede producir `//wp-json`.
    func testUnaBarraDeMasEnElSitioNoDuplicaLaRuta() async throws {
        let espia = Espia()
        let cliente = Api(
            sitio: "https://cead.edu.py/",
            almacen: AlmacenFalso(),
            transporte: TransporteFalso(#"{"tareas":[]}"#, espia: espia)
        )

        _ = try await cliente.tareas()

        XCTAssertEqual(espia.ultimo?.url?.absoluteString, "https://cead.edu.py/wp-json/cead-acad/v1/tareas")
    }

    func testCerrarUnaSesionUsaDelete() async throws {
        let espia = Espia()
        let (cliente, _) = api("{}", espia: espia)

        try await cliente.cerrarSesion(id: "aabbccddeeff")

        XCTAssertEqual(espia.ultimo?.httpMethod, "DELETE")
        XCTAssertTrue(espia.ultimo?.url?.absoluteString.hasSuffix("/auth/sesiones/aabbccddeeff") == true)
    }

    /// Salir tiene que funcionar aunque el aviso al servidor falle: si no, un
    /// corte de internet dejaría a alguien sin poder cerrar sesión en su propio
    /// teléfono.
    func testSalirBorraLaSesionAunqueElServidorFalle() async {
        let almacen = AlmacenFalso()
        let (cliente, _) = api("", codigo: 500, almacen: almacen)

        await cliente.logout()

        XCTAssertNil(almacen.token)
        XCTAssertTrue(almacen.limpiado)
    }
}
