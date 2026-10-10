package net.caaguazu.cead.panel.data

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject

@Serializable
data class RespuestaDelegados(val delegados: List<DelegadoFicha> = emptyList())

@Serializable
data class RespuestaSesiones(val sesiones: List<SesionAbierta> = emptyList())

@Serializable
data class RespuestaCeadi(val respuesta: String = "")

@Serializable
data class RespuestaInvitacion(val invitacion: Invitacion = Invitacion())

@Serializable
data class RespuestaBuzon(val ok: Boolean = false)

@Serializable
data class RespuestaPrueba(
    val configurado: Boolean = false,
    val enviados: Int = 0,
    val descartados: Int = 0,
    val fallidos: Int = 0,
)

/**
 * Lo que solo se hace con conexión y NO se guarda en el teléfono.
 *
 * No es por comodidad sino por lo que son: el buzón de coordinación tiene
 * reportes con nombres y relatos, y el directorio de delegados tiene teléfonos
 * de terceros. Dejarlos en una copia local sería dejar esos datos en un
 * teléfono que se puede perder, prestar o vender. Mejor que haya que estar en
 * línea para verlos.
 */
class EnLinea(private val api: ApiCliente) {

    suspend fun buzon() = api.get("/gestion/buzon", Buzon.serializer())

    suspend fun actuarBuzon(tipo: String, id: Int, accion: String, respuesta: String) =
        api.post(
            "/gestion/buzon/$tipo/$id",
            JsonObject.serializer(),
            ApiCliente.json("accion" to accion, "respuesta" to respuesta),
            clave = Repositorio.nuevoId(),
        )

    suspend fun metricas() = api.get("/gestion/metricas", Metricas.serializer())

    suspend fun notasDelCurso(cursoId: Int) =
        api.get("/gestion/notas", NotasDelCurso.serializer(), consulta = mapOf("curso_id" to "$cursoId"))

    suspend fun invitaciones() = api.get("/gestion/invitaciones", Invitaciones.serializer())

    suspend fun invitar(rol: String, usos: Int, cursoId: Int, email: String) =
        api.post(
            "/gestion/invitaciones",
            RespuestaInvitacion.serializer(),
            ApiCliente.json("rol" to rol, "usos" to usos, "curso_id" to cursoId, "email" to email),
            clave = Repositorio.nuevoId(),
        )

    suspend fun revocarInvitacion(id: Int) =
        api.post("/gestion/invitaciones/$id/revocar", RespuestaInvitacion.serializer(), clave = Repositorio.nuevoId())

    suspend fun delegados() = api.get("/delegados", RespuestaDelegados.serializer())

    suspend fun ceadi(mensaje: String) =
        api.post("/ceadi", RespuestaCeadi.serializer(), ApiCliente.json("mensaje" to mensaje))

    suspend fun estadoReporte(codigo: String) =
        api.get("/reportes/${codigo.trim().uppercase()}", EstadoReporte.serializer())

    suspend fun novedades() = api.get("/notificaciones", Novedades.serializer())

    suspend fun sesiones() = api.get("/auth/sesiones", RespuestaSesiones.serializer())

    suspend fun cerrarSesionRemota(id: String) =
        api.delete("/auth/sesiones/$id", JsonObject.serializer())

    suspend fun probarAvisos() = api.post("/dispositivos/prueba", RespuestaPrueba.serializer())
}
