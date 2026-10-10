package net.caaguazu.cead.panel.data

import io.ktor.client.HttpClient
import io.ktor.client.engine.HttpClientEngine
import io.ktor.client.plugins.HttpTimeout
import io.ktor.client.request.HttpRequestBuilder
import io.ktor.client.request.header
import io.ktor.client.request.request
import io.ktor.client.request.setBody
import io.ktor.client.request.forms.MultiPartFormDataContent
import io.ktor.client.request.forms.formData
import io.ktor.client.statement.bodyAsText
import io.ktor.client.statement.bodyAsBytes
import io.ktor.http.ContentType
import io.ktor.http.Headers
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpMethod
import io.ktor.http.contentType
import io.ktor.http.parameters
import io.ktor.http.takeFrom
import kotlinx.coroutines.CancellationException
import kotlinx.serialization.KSerializer
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okio.FileSystem
import okio.Path.Companion.toPath
import okio.buffer
import okio.use

/**
 * Qué salió mal, en términos de lo que la app tiene que HACER:
 *
 *  - [SIN_CONEXION]: no hay red o el servidor no contesta. Se reintenta más tarde.
 *  - [SESION_VENCIDA]: el token ya no vale. Hay que volver a pedir la contraseña.
 *  - [RECHAZADO]: el servidor entendió y dijo que no (datos inválidos, sin
 *    permiso). Reintentar no cambia nada; hay que mostrar el motivo.
 *  - [SERVIDOR]: falló del otro lado (5xx). Se reintenta más tarde.
 */
enum class TipoError { SIN_CONEXION, SESION_VENCIDA, RECHAZADO, SERVIDOR }

class ErrorApi(
    val tipo: TipoError,
    val codigo: String,
    val mensaje: String,
    val http: Int = 0,
) {
    /**
     * ¿Tiene sentido volver a intentar el mismo pedido más tarde?
     *
     * Es lo que decide la cola de envíos: lo reintentable se queda esperando;
     * lo rechazado se marca como fallido y se le muestra a la persona, porque
     * mandarlo mil veces no lo va a arreglar. El 408 y el 429 son rechazos que
     * el propio servidor dice que son pasajeros.
     */
    val reintentable: Boolean
        get() = tipo == TipoError.SIN_CONEXION || tipo == TipoError.SERVIDOR || http == 408 || http == 429

    override fun toString() = "ErrorApi($tipo, $codigo, $http, $mensaje)"
}

sealed class Resultado<out T> {
    /** [etag] viene en los GET que lo traen; [repetido] si el servidor contestó desde su memoria de idempotencia. */
    data class Ok<T>(val valor: T, val etag: String? = null, val repetido: Boolean = false) : Resultado<T>()
    data object NoModificado : Resultado<Nothing>()
    data class Fallo(val error: ErrorApi) : Resultado<Nothing>()
}

/** Un archivo para subir en un pedido multipart (leído del disco al enviar, no antes). */
class ArchivoAdjunto(
    val campo: String,
    val ruta: String,
    val nombre: String,
    val tipoMime: String,
)

/**
 * El cliente de la API del colegio.
 *
 * No sabe nada de pantallas ni de la cola: arma el pedido, lo manda y traduce
 * la respuesta a [Resultado]. Nada de lo que falle sale de acá como excepción,
 * porque una excepción suelta en una corrutina de la cola tumba la cola entera.
 */
class ApiCliente(
    private val sitio: String,
    private val token: () -> String?,
    private val alSesionVencida: () -> Unit,
    private val agente: String,
    motor: HttpClientEngine? = null,
    private val sistema: FileSystem,
) {

    private val http: HttpClient = run {
        val config: io.ktor.client.HttpClientConfig<*>.() -> Unit = {
            expectSuccess = false
            followRedirects = false
            install(HttpTimeout) {
                connectTimeoutMillis = 15_000
                requestTimeoutMillis = 90_000
                socketTimeoutMillis = 60_000
            }
        }
        if (motor != null) HttpClient(motor, config) else HttpClient(config)
    }

    private fun url(ruta: String) = "${sitio.trimEnd('/')}/wp-json/cead-acad/v1$ruta"

    /* ------------------------------------------------------------ pedidos */

    suspend fun <T> get(
        ruta: String,
        serializador: KSerializer<T>,
        etag: String? = null,
        consulta: Map<String, String> = emptyMap(),
    ): Resultado<T> = ejecutar(serializador, HttpMethod.Get, ruta, true) {
        consulta.forEach { (k, v) -> url.parameters.append(k, v) }
        if (etag != null) header(HttpHeaders.IfNoneMatch, "\"$etag\"")
    }

    suspend fun <T> post(
        ruta: String,
        serializador: KSerializer<T>,
        cuerpoJson: String? = null,
        clave: String? = null,
        autenticado: Boolean = true,
    ): Resultado<T> = ejecutar(serializador, HttpMethod.Post, ruta, autenticado) {
        if (clave != null) header("Idempotency-Key", clave)
        if (cuerpoJson != null) {
            contentType(ContentType.Application.Json)
            setBody(cuerpoJson)
        }
    }

    suspend fun <T> delete(ruta: String, serializador: KSerializer<T>): Resultado<T> =
        ejecutar(serializador, HttpMethod.Delete, ruta, true) {}

    /** Un POST con campos de texto y archivos. Los archivos se leen del disco acá, al mandar. */
    suspend fun <T> postMultipart(
        ruta: String,
        serializador: KSerializer<T>,
        campos: Map<String, String>,
        archivos: List<ArchivoAdjunto>,
        clave: String? = null,
    ): Resultado<T> {
        // Si el archivo ya no está (el sistema limpió la caché), no tiene sentido
        // reintentar: se rechaza con un motivo que la persona entienda.
        val leidos = archivos.map { a ->
            val bytes = try {
                sistema.source(a.ruta.toPath()).buffer().use { it.readByteArray() }
            } catch (e: Exception) {
                return Resultado.Fallo(ErrorApi(TipoError.RECHAZADO, "archivo_perdido", "El archivo ya no está en el teléfono. Elegilo de nuevo."))
            }
            a to bytes
        }
        return ejecutar(serializador, HttpMethod.Post, ruta, true) {
            if (clave != null) header("Idempotency-Key", clave)
            setBody(
                MultiPartFormDataContent(
                    formData {
                        campos.forEach { (k, v) -> append(k, v) }
                        leidos.forEach { (a, bytes) ->
                            append(
                                a.campo,
                                bytes,
                                Headers.build {
                                    append(HttpHeaders.ContentType, a.tipoMime)
                                    append(HttpHeaders.ContentDisposition, "filename=\"${a.nombre.replace("\"", "")}\"")
                                },
                            )
                        }
                    }
                )
            )
        }
    }

    /** Baja un archivo (público) a disco. Devuelve null si anduvo, o el motivo si no. */
    suspend fun descargar(urlAbsoluta: String, destino: String): ErrorApi? {
        return try {
            val r = http.request {
                method = HttpMethod.Get
                url.takeFrom(urlAbsoluta)
                header(HttpHeaders.UserAgent, agente)
            }
            if (r.status.value !in 200..299) {
                return ErrorApi(TipoError.RECHAZADO, "descarga", "No se pudo bajar el archivo.", r.status.value)
            }
            val bytes = r.bodyAsBytes()
            val ruta = destino.toPath()
            ruta.parent?.let { sistema.createDirectories(it) }
            sistema.sink(ruta).buffer().use { it.write(bytes) }
            null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Throwable) {
            ErrorApi(TipoError.SIN_CONEXION, "sin_conexion", "No hay conexión.")
        }
    }

    /* ------------------------------------------------------------ interno */

    private suspend fun <T> ejecutar(
        serializador: KSerializer<T>,
        metodo: HttpMethod,
        ruta: String,
        autenticado: Boolean,
        armar: HttpRequestBuilder.() -> Unit,
    ): Resultado<T> {
        val respuesta = try {
            http.request {
                method = metodo
                url.takeFrom(url(ruta))
                header(HttpHeaders.Accept, "application/json")
                header(HttpHeaders.UserAgent, agente)
                if (autenticado) token()?.let { header(HttpHeaders.Authorization, "Bearer $it") }
                armar()
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Throwable) {
            // Sin red, sin DNS, timeout, TLS: para la app es todo lo mismo.
            return Resultado.Fallo(ErrorApi(TipoError.SIN_CONEXION, "sin_conexion", "No hay conexión."))
        }

        val codigo = respuesta.status.value
        if (codigo == 304) return Resultado.NoModificado

        val texto = try {
            respuesta.bodyAsText()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Throwable) {
            return Resultado.Fallo(ErrorApi(TipoError.SIN_CONEXION, "sin_conexion", "No hay conexión."))
        }

        if (codigo in 200..299) {
            return try {
                Resultado.Ok(
                    valor = AppJson.decodeFromString(serializador, texto),
                    etag = respuesta.headers[HttpHeaders.ETag]?.trim('"', ' '),
                    repetido = respuesta.headers["Idempotent-Replay"] == "true",
                )
            } catch (e: Exception) {
                Resultado.Fallo(ErrorApi(TipoError.SERVIDOR, "respuesta_invalida", "El servidor contestó algo que la app no entiende.", codigo))
            }
        }

        val (cod, mensaje) = leerError(texto, codigo)
        if (codigo == 401 && autenticado) {
            alSesionVencida()
            return Resultado.Fallo(ErrorApi(TipoError.SESION_VENCIDA, cod, mensaje, codigo))
        }
        val tipo = if (codigo >= 500) TipoError.SERVIDOR else TipoError.RECHAZADO
        return Resultado.Fallo(ErrorApi(tipo, cod, mensaje, codigo))
    }

    companion object {
        /**
         * El código y el mensaje de un error de WordPress (`{code, message, data}`).
         *
         * Cuando el cuerpo no es eso —una página de error de un proxy, por
         * ejemplo— se devuelve un mensaje genérico en vez de volcarle HTML a
         * la persona.
         */
        fun leerError(cuerpo: String, http: Int): Pair<String, String> {
            val obj = try {
                AppJson.parseToJsonElement(cuerpo).jsonObject
            } catch (e: Exception) {
                null
            }
            val codigo = obj?.get("code")?.jsonPrimitive?.contentOrNull
            val mensaje = obj?.get("message")?.jsonPrimitive?.contentOrNull
            if (codigo != null && mensaje != null) return codigo to mensaje
            return "http_$http" to when {
                http >= 500 -> "El servidor tuvo un problema. Probá de nuevo en un rato."
                http == 404 -> "No se encontró lo que se pidió."
                http == 403 -> "No tenés permiso para hacer eso."
                else -> "No se pudo completar la acción."
            }
        }

        /** Un objeto JSON chico, para armar cuerpos sin definir una clase por cada uno. */
        fun json(vararg pares: Pair<String, Any?>): String =
            JsonObject(pares.associate { (k, v) -> k to aJson(v) }).toString()

        private fun aJson(v: Any?): kotlinx.serialization.json.JsonElement = when (v) {
            null -> kotlinx.serialization.json.JsonNull
            is kotlinx.serialization.json.JsonElement -> v
            is Boolean -> JsonPrimitive(v)
            is Number -> JsonPrimitive(v)
            is Map<*, *> -> JsonObject(v.entries.associate { (k, x) -> k.toString() to aJson(x) })
            is Iterable<*> -> kotlinx.serialization.json.JsonArray(v.map { aJson(it) })
            else -> JsonPrimitive(v.toString())
        }
    }
}
