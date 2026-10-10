package net.caaguazu.cead.panel

import io.ktor.client.engine.mock.MockEngine
import io.ktor.client.engine.mock.MockRequestHandleScope
import io.ktor.client.engine.mock.respond
import io.ktor.client.request.HttpRequestData
import io.ktor.client.request.HttpResponseData
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import io.ktor.http.headersOf
import net.caaguazu.cead.panel.plataforma.AlmacenSeguro
import net.caaguazu.cead.panel.plataforma.ArchivoElegido
import net.caaguazu.cead.panel.plataforma.Plataforma
import net.caaguazu.cead.panel.plataforma.Push
import net.caaguazu.cead.panel.plataforma.SelectorArchivos
import net.caaguazu.cead.panel.plataforma.TipoArchivo

class MemoriaSegura : AlmacenSeguro {
    val datos = mutableMapOf<String, String>()
    override fun leer(clave: String) = datos[clave]
    override fun guardar(clave: String, valor: String) { datos[clave] = valor }
    override fun borrar(clave: String) { datos.remove(clave) }
}

class PushFalso(override var disponible: Boolean = false, var token: String? = null) : Push {
    var permisoPedido = false
    var oyente: ((String) -> Unit)? = null
    override fun pedirPermiso() { permisoPedido = true }
    override suspend fun token() = token
    override fun alCambiarElToken(oyente: (String) -> Unit) { this.oyente = oyente }
}

class PlataformaFalsa(
    override val almacenSeguro: MemoriaSegura = MemoriaSegura(),
    override val push: PushFalso = PushFalso(),
) : Plataforma {
    override val nombre = "android"
    override val dirDatos = "/datos"
    override val dirCache = "/cache"
    override fun nombreDelAparato() = "Teléfono de prueba"
    override fun abrirUrl(url: String) {}
    override fun abrirArchivo(ruta: String, tipoMime: String?) {}
    override fun copiar(texto: String) {}
    override fun compartir(texto: String) {}
    override val selector = object : SelectorArchivos {
        override fun elegir(tipo: TipoArchivo, alElegir: (ArchivoElegido?) -> Unit) = alElegir(null)
    }
}

/** Un servidor de mentira: guarda cada pedido que recibió y contesta con lo que diga [responder]. */
class ServidorFalso(
    private val responder: suspend MockRequestHandleScope.(HttpRequestData) -> HttpResponseData,
) {
    /** Los pedidos que LLEGARON al servidor (aunque los haya rechazado). */
    val pedidos = mutableListOf<HttpRequestData>()

    /** Todos los intentos, incluidos los que se perdieron en el camino por no haber red. */
    val intentos = mutableListOf<HttpRequestData>()

    val motor = MockEngine { req ->
        intentos += req
        val respuesta = responder(req)
        pedidos += req
        respuesta
    }

    /** Los pedidos a una ruta (sin el prefijo de la API). */
    fun a(ruta: String) = pedidos.filter { it.url.encodedPath.endsWith("/wp-json/cead-acad/v1$ruta") }

    fun intentosA(ruta: String) = intentos.filter { it.url.encodedPath.endsWith("/wp-json/cead-acad/v1$ruta") }
}

fun MockRequestHandleScope.json(cuerpo: String, estado: HttpStatusCode = HttpStatusCode.OK, vararg extra: Pair<String, String>) =
    respond(
        content = cuerpo,
        status = estado,
        headers = headersOf(
            HttpHeaders.ContentType to listOf("application/json"),
            *extra.map { it.first to listOf(it.second) }.toTypedArray(),
        ),
    )

fun MockRequestHandleScope.error(estado: Int, codigo: String = "cead_api_x", mensaje: String = "No.") =
    json("""{"code":"$codigo","message":"$mensaje","data":{"status":$estado}}""", HttpStatusCode.fromValue(estado))

/** Lo que contesta `/auth/login`. */
const val LOGIN_OK = """{"token":"7.abcdef123456.secreto","vence":1900000000,"usuario":{"id":7,"nombre":"Ana Pérez","email":"ana@x.py","rol":"cead_acad_student","rol_label":"Alumno/a","caps":["cead_acad_view_own_grades"],"curso":{"id":3,"titulo":"3° B"},"avatar":"https://x/a.png"}}"""
