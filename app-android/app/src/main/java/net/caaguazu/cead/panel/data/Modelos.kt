package net.caaguazu.cead.panel.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * Lo que devuelve `cead-acad/v1`, tal cual.
 *
 * Los nombres están en castellano porque así los manda el servidor: renombrarlos
 * acá obligaría a mantener un diccionario entre dos archivos que nadie va a
 * mirar juntos.
 */

@Serializable
data class Curso(
    val id: Int,
    val titulo: String,
)

@Serializable
data class Perfil(
    val id: Int,
    val nombre: String,
    val email: String = "",
    val rol: String = "",
    @SerialName("rol_label") val rolLabel: String = "",
    val caps: List<String> = emptyList(),
    val curso: Curso? = null,
    val avatar: String? = null,
) {
    fun puede(cap: String) = caps.contains(cap)
}

@Serializable
data class RespuestaLogin(
    val token: String,
    val vence: Long = 0,
    val usuario: Perfil,
)

@Serializable
data class Sesion(
    val id: String,
    val nombre: String = "",
    val creado: Long = 0,
    val usado: Long = 0,
    val actual: Boolean = false,
)

@Serializable
data class Sesiones(val sesiones: List<Sesion> = emptyList())

/* ------------------------------------------------------------------ horario */

@Serializable
data class Franja(
    val inicio: String = "",
    val fin: String = "",
    val materia: String = "",
    val docente: String = "",
    val aula: String = "",
)

@Serializable
data class DiaHorario(
    val dia: Int,
    val franjas: List<Franja> = emptyList(),
)

@Serializable
data class Horario(
    val curso: Curso? = null,
    val dias: List<DiaHorario> = emptyList(),
    val motivo: String? = null,
)

/* -------------------------------------------------------------- comunicados */

@Serializable
data class Categoria(val slug: String = "", val nombre: String = "")

@Serializable
data class Comunicado(
    val id: Int,
    val titulo: String = "",
    val fecha: String = "",
    val resumen: String = "",
    val imagen: String? = null,
    val categoria: Categoria? = null,
    val leido: Boolean = false,
    val contenido: String? = null,
)

@Serializable
data class Comunicados(
    val comunicados: List<Comunicado> = emptyList(),
    val pagina: Int = 1,
    @SerialName("sin_leer") val sinLeer: Int = 0,
)

/* ------------------------------------------------------------------ boletín */

@Serializable
data class Nota(
    val nota: Double? = null,
    val letra: String = "",
    val comentarios: String = "",
    val cargada: String = "",
)

@Serializable
data class MateriaBoletin(
    val materia: String = "",
    val curso: String = "",
    // Clave: el nombre del período. Es un mapa y no una lista porque el colegio
    // puede renombrar o agregar períodos sin que la app tenga que saber cuáles
    // existen de antemano.
    val notas: Map<String, Nota> = emptyMap(),
)

@Serializable
data class Boletin(
    val materias: List<MateriaBoletin> = emptyList(),
    val periodos: List<String> = emptyList(),
)

/* ------------------------------------------------------------------- tareas */

@Serializable
data class Tarea(
    val id: Int,
    val titulo: String = "",
    val detalle: String = "",
    val vence: String? = null,
    val prioridad: String = "normal",
    val hecha: Boolean = false,
)

@Serializable
data class Tareas(val tareas: List<Tarea> = emptyList())

/* --------------------------------------------------------------- calendario */

@Serializable
data class Evento(
    val id: Int,
    val titulo: String = "",
    val detalle: String = "",
    val inicio: String = "",
    val fin: String = "",
    val lugar: String = "",
)

@Serializable
data class Calendario(val eventos: List<Evento> = emptyList())

/* -------------------------------------------------------------------- error */

/** La forma en que WordPress serializa un `WP_Error`. */
@Serializable
data class ErrorApi(
    val code: String = "",
    val message: String = "",
)
