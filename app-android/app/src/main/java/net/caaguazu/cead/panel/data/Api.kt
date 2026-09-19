package net.caaguazu.cead.panel.data

import android.os.Build
import io.ktor.client.HttpClient
import io.ktor.client.call.body
import io.ktor.client.engine.android.Android
import io.ktor.client.plugins.contentnegotiation.ContentNegotiation
import io.ktor.client.request.delete
import io.ktor.client.request.get
import io.ktor.client.request.header
import io.ktor.client.request.parameter
import io.ktor.client.request.post
import io.ktor.client.request.setBody
import io.ktor.client.statement.HttpResponse
import io.ktor.http.ContentType
import io.ktor.http.HttpStatusCode
import io.ktor.http.contentType
import io.ktor.serialization.kotlinx.json.json
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

/** El pedido falló y la app tiene algo concreto que mostrar. */
class ApiError(val codigo: String, mensaje: String) : Exception(mensaje)

/**
 * La sesión ya no vale y hay que volver a pedir la contraseña.
 *
 * Es su propio tipo y no un `ApiError` más porque la reacción es distinta: no se
 * muestra un cartel rojo, se borra el token y se manda a la pantalla de entrada.
 * Confundirlos deja a la persona mirando «no autorizado» en una pantalla vacía,
 * sin forma de volver a entrar.
 */
class SesionVencida(mensaje: String) : Exception(mensaje)

class Api(
    private val sitio: String,
    private val almacen: Almacen,
) {

    private val http = HttpClient(Android) {
        expectSuccess = false
        install(ContentNegotiation) {
            json(Json {
                ignoreUnknownKeys = true
                explicitNulls = false
            })
        }
    }

    private fun url(ruta: String) = "${sitio.trimEnd('/')}/wp-json/cead-acad/v1$ruta"

    /* --------------------------------------------------------------- sesión */

    suspend fun login(usuario: String, clave: String): RespuestaLogin {
        val r = http.post(url("/auth/login")) {
            contentType(ContentType.Application.Json)
            setBody(
                JsonObject(
                    mapOf(
                        "usuario" to JsonPrimitive(usuario),
                        "clave" to JsonPrimitive(clave),
                        "dispositivo" to JsonPrimitive(nombreDelAparato()),
                    )
                )
            )
        }
        val datos: RespuestaLogin = leer(r)
        almacen.token = datos.token
        almacen.nombre = datos.usuario.nombre
        return datos
    }

    suspend fun logout() {
        // Se avisa al servidor para que el token muera del otro lado también,
        // pero el borrado local no depende de que ese aviso llegue: si falla,
        // salir de la sesión igual tiene que funcionar.
        runCatching { http.post(url("/auth/logout")) { autorizar() } }
        almacen.limpiar()
    }

    suspend fun yo(): Perfil = leer(http.get(url("/me")) { autorizar() })

    suspend fun sesiones(): Sesiones = leer(http.get(url("/auth/sesiones")) { autorizar() })

    suspend fun cerrarSesion(id: String) {
        leerVacio(http.delete(url("/auth/sesiones/$id")) { autorizar() })
    }

    /* ---------------------------------------------------------------- datos */

    suspend fun horario(curso: Int? = null): Horario = leer(
        http.get(url("/horario")) {
            autorizar()
            curso?.let { parameter("curso", it) }
        }
    )

    suspend fun comunicados(pagina: Int = 1): Comunicados = leer(
        http.get(url("/comunicados")) {
            autorizar()
            parameter("pagina", pagina)
        }
    )

    suspend fun comunicado(id: Int): Comunicado = leer(http.get(url("/comunicados/$id")) { autorizar() })

    suspend fun boletin(): Boletin = leer(http.get(url("/boletin")) { autorizar() })

    suspend fun tareas(): Tareas = leer(http.get(url("/tareas")) { autorizar() })

    suspend fun calendario(desde: String? = null, hasta: String? = null): Calendario = leer(
        http.get(url("/calendario")) {
            autorizar()
            desde?.let { parameter("desde", it) }
            hasta?.let { parameter("hasta", it) }
        }
    )

    /* -------------------------------------------------------------- interno */

    private fun io.ktor.client.request.HttpRequestBuilder.autorizar() {
        almacen.token?.let { header("Authorization", "Bearer $it") }
    }

    private suspend inline fun <reified T> leer(r: HttpResponse): T {
        revisar(r)
        return r.body()
    }

    private suspend fun leerVacio(r: HttpResponse) {
        revisar(r)
    }

    /**
     * Traduce un error HTTP en algo que la app pueda mostrar o actuar.
     *
     * El mensaje sale del propio `WP_Error` —ya viene escrito en castellano y
     * pensado para una persona— en vez de armar uno acá. Si el cuerpo no se
     * puede leer, recién ahí se inventa uno genérico.
     */
    private suspend fun revisar(r: HttpResponse) {
        if (r.status.value in 200..299) return

        val error = runCatching { r.body<ErrorApi>() }.getOrNull()
        val mensaje = error?.message?.takeIf { it.isNotBlank() }
            ?: "No se pudo conectar con el CEAD (${r.status.value})."

        if (r.status == HttpStatusCode.Unauthorized) {
            almacen.limpiar()
            throw SesionVencida(mensaje)
        }
        throw ApiError(error?.code ?: "http_${r.status.value}", mensaje)
    }

    /** «Moto de Ana», para que la lista de sesiones signifique algo. */
    private fun nombreDelAparato(): String {
        val marca = Build.MANUFACTURER.replaceFirstChar { it.uppercase() }
        val modelo = Build.MODEL
        return if (modelo.startsWith(marca, ignoreCase = true)) modelo else "$marca $modelo"
    }
}
