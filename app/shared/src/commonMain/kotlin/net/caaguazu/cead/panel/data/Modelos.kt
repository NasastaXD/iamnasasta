package net.caaguazu.cead.panel.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json

/**
 * La forma de lo que contesta el servidor, campo por campo.
 *
 * Todo tiene valor por defecto a propósito. La app se instala y se queda en el
 * teléfono meses; el servidor se actualiza cuando el colegio quiere. Una app
 * vieja leyendo un servidor nuevo (o al revés) no puede romperse porque falte o
 * sobre un campo: lo desconocido se ignora y lo que falta toma un valor
 * razonable.
 */
val AppJson = Json {
    ignoreUnknownKeys = true
    coerceInputValues = true
    explicitNulls = false
    encodeDefaults = true
    isLenient = true
}

@Serializable
data class CursoRef(val id: Int = 0, val titulo: String = "")

@Serializable
data class Usuario(
    val id: Int = 0,
    val nombre: String = "",
    val email: String = "",
    val rol: String = "",
    @SerialName("rol_label") val rolLabel: String = "",
    val caps: List<String> = emptyList(),
    val curso: CursoRef? = null,
    val avatar: String? = null,
) {
    /** ¿Tiene esta capacidad? Las mismas que usa la barra lateral del panel web. */
    fun puede(cap: String): Boolean = "cead_acad_$cap" in caps || cap in caps
}

@Serializable
data class RespuestaLogin(
    val token: String,
    val vence: Long = 0,
    val usuario: Usuario,
)

/* ------------------------------------------------------------- horario */

@Serializable
data class Franja(
    val inicio: String = "",
    val fin: String = "",
    val materia: String = "",
    val docente: String = "",
    val aula: String = "",
)

@Serializable
data class DiaHorario(val dia: Int = 0, val franjas: List<Franja> = emptyList())

@Serializable
data class Horario(
    val curso: CursoRef? = null,
    val dias: List<DiaHorario> = emptyList(),
    val motivo: String? = null,
)

/* --------------------------------------------------------- comunicados */

@Serializable
data class Categoria(val slug: String = "", val nombre: String = "")

@Serializable
data class Comunicado(
    val id: Int = 0,
    val titulo: String = "",
    val fecha: String = "",
    val resumen: String = "",
    val imagen: String? = null,
    val categoria: Categoria? = null,
    val leido: Boolean = false,
    val contenido: String = "",
)

@Serializable
data class Comunicados(
    val comunicados: List<Comunicado> = emptyList(),
    @SerialName("sin_leer") val sinLeer: Int = 0,
)

/* ------------------------------------------------------------- boletín */

@Serializable
data class NotaPeriodo(
    val nota: Double? = null,
    val letra: String = "",
    val comentarios: String = "",
    val cargada: String = "",
)

@Serializable
data class MateriaBoletin(
    val materia: String = "",
    val curso: String = "",
    val notas: Map<String, NotaPeriodo> = emptyMap(),
)

@Serializable
data class Boletin(
    val materias: List<MateriaBoletin> = emptyList(),
    val periodos: List<String> = emptyList(),
)

/* -------------------------------------------------------------- tareas */

@Serializable
data class Tarea(
    val id: Int = 0,
    val titulo: String = "",
    val detalle: String = "",
    val vence: String? = null,
    val prioridad: String = "normal",
    val hecha: Boolean = false,
)

@Serializable
data class Tareas(val tareas: List<Tarea> = emptyList())

/* ---------------------------------------------------------- calendario */

@Serializable
data class Evento(
    val id: Int = 0,
    val titulo: String = "",
    val detalle: String = "",
    val inicio: String = "",
    val fin: String = "",
    @SerialName("todo_el_dia") val todoElDia: Boolean = false,
    val tipo: String = "evento",
    val lugar: String = "",
)

@Serializable
data class Calendario(val eventos: List<Evento> = emptyList())

/* ------------------------------------------------------------ recursos */

@Serializable
data class Termino(val slug: String = "", val nombre: String = "")

@Serializable
data class Recurso(
    val id: Int = 0,
    val titulo: String = "",
    val descripcion: String = "",
    val fecha: String = "",
    val url: String? = null,
    @SerialName("es_archivo") val esArchivo: Boolean = false,
    @SerialName("tipo_mime") val tipoMime: String? = null,
    val tamano: Long? = null,
    val materias: List<Termino> = emptyList(),
    val tipos: List<Termino> = emptyList(),
    val imagen: String? = null,
    val favorito: Boolean = false,
)

@Serializable
data class Recursos(val recursos: List<Recurso> = emptyList())

/* ----------------------------------------------------------- encuestas */

@Serializable
data class Pregunta(
    val id: Int = 0,
    val texto: String = "",
    val tipo: String = "text",
    val obligatoria: Boolean = false,
    val opciones: List<String> = emptyList(),
    val min: Int? = null,
    val max: Int? = null,
)

@Serializable
data class Encuesta(
    val id: Int = 0,
    val titulo: String = "",
    val descripcion: String = "",
    val abierta: Boolean = false,
    val anonima: Boolean = false,
    val abre: String? = null,
    val cierra: String? = null,
    /** Null en una anónima: el servidor no guarda quién respondió, y no lo sabe. */
    val respondida: Boolean? = null,
    val preguntas: List<Pregunta> = emptyList(),
)

@Serializable
data class Encuestas(val encuestas: List<Encuesta> = emptyList())

@Serializable
data class PreguntaFrecuente(
    val id: Int = 0,
    val pregunta: String = "",
    val respuesta: String = "",
)

/* --------------------------------------------------------- cuenta, buzón */

@Serializable
data class MisDatos(
    val nombre: String = "",
    val email: String = "",
    val usuario: String = "",
    val telefono: String = "",
    @SerialName("telefono_verificado") val telefonoVerificado: Boolean = false,
    val foto: String? = null,
    val iniciales: String = "",
)

@Serializable
data class MensajePropio(
    val id: Int = 0,
    val para: String = "",
    val texto: String = "",
    val estado: String = "",
    val respuesta: String = "",
    val creado: String = "",
)

@Serializable
data class ReportePropio(
    val codigo: String = "",
    val categoria: String = "",
    val estado: String = "",
    val respuesta: String = "",
    val creado: String = "",
    val actualizado: String = "",
)

@Serializable
data class MisMensajes(
    val mensajes: List<MensajePropio> = emptyList(),
    val reportes: List<ReportePropio> = emptyList(),
)

@Serializable
data class EstadoReporte(
    val codigo: String = "",
    val estado: String = "",
    val respuesta: String = "",
    val actualizado: String = "",
)

@Serializable
data class Novedad(
    val tipo: String = "",
    val titulo: String = "",
    val cuando: String = "",
)

@Serializable
data class Novedades(
    val notificaciones: List<Novedad> = emptyList(),
    @SerialName("sin_leer") val sinLeer: Int = 0,
)

/* ------------------------------------------------------------- gestión */

@Serializable
data class OpcionAudiencia(val valor: String = "", val nombre: String = "")

@Serializable
data class OpcionesAudiencia(
    @SerialName("todo_el_colegio") val todoElColegio: Boolean = false,
    val roles: List<OpcionAudiencia> = emptyList(),
    val cursos: List<OpcionAudiencia> = emptyList(),
    val promociones: List<OpcionAudiencia> = emptyList(),
)

/** Una audiencia elegida: lo que viaja al servidor. */
@Serializable
data class AudienciaElegida(val type: String, val value: String)

@Serializable
data class AlumnoOpcion(val id: Int = 0, val nombre: String = "")

@Serializable
data class CursoNotas(
    val id: Int = 0,
    val titulo: String = "",
    val alumnos: List<AlumnoOpcion> = emptyList(),
)

@Serializable
data class MateriaOpcion(val id: Int = 0, val nombre: String = "")

@Serializable
data class Escala(
    val maxima: Double = 5.0,
    val aprobado: Double = 2.0,
    val etiquetas: Map<String, String> = emptyMap(),
)

@Serializable
data class OpcionesNotas(
    val periodos: List<String> = emptyList(),
    val escala: Escala = Escala(),
    val materias: List<MateriaOpcion> = emptyList(),
    val cursos: List<CursoNotas> = emptyList(),
)

@Serializable
data class CategoriaArticulo(val id: Int = 0, val nombre: String = "")

@Serializable
data class FormatoArticulo(
    val slug: String = "",
    val nombre: String = "",
    val pide: List<String> = emptyList(),
)

@Serializable
data class OpcionesArticulos(
    val categorias: List<CategoriaArticulo> = emptyList(),
    val formatos: List<FormatoArticulo> = emptyList(),
    val redes: Boolean = false,
)

@Serializable
data class TareaCurso(
    val id: Int = 0,
    val titulo: String = "",
    val detalle: String = "",
    val curso: CursoRef? = null,
    val estado: String = "pendiente",
    val prioridad: String = "normal",
    val vence: String? = null,
)

/** Lo que el staff necesita para trabajar sin señal. Cada parte aparece si tiene el permiso. */
@Serializable
data class Gestion(
    @SerialName("audiencias_comunicado") val audienciasComunicado: OpcionesAudiencia? = null,
    @SerialName("audiencias_evento") val audienciasEvento: OpcionesAudiencia? = null,
    val notas: OpcionesNotas? = null,
    val articulos: OpcionesArticulos? = null,
    @SerialName("tareas_del_curso") val tareasDelCurso: List<TareaCurso>? = null,
)

/* -------------------------------------------------- gestión, en línea */

@Serializable
data class ReporteBuzon(
    val id: Int = 0,
    val codigo: String = "",
    val tipo: String = "anonimo",
    val categoria: String = "",
    /** Null si no se pudo descifrar: se muestra como aviso, no como un reporte vacío. */
    val texto: String? = null,
    val estado: String = "",
    val respuesta: String = "",
    val notas: String = "",
    val telefono: String? = null,
    val creado: String = "",
)

@Serializable
data class SugerenciaBuzon(
    val id: Int = 0,
    val categoria: String = "",
    val texto: String = "",
    val estado: String = "",
    val respuesta: String = "",
    val telefono: String? = null,
    val creado: String = "",
)

@Serializable
data class PapeleraBuzon(
    val reportes: List<ReporteBuzon> = emptyList(),
    val sugerencias: List<SugerenciaBuzon> = emptyList(),
)

@Serializable
data class Buzon(
    val alcance: String = "",
    val reportes: List<ReporteBuzon> = emptyList(),
    val sugerencias: List<SugerenciaBuzon> = emptyList(),
    val papelera: PapeleraBuzon? = null,
)

@Serializable
data class PersonasMetricas(
    val alumnos: Int = 0,
    val delegados: Int = 0,
    val docentes: Int = 0,
    @SerialName("inscripciones_activas") val inscripcionesActivas: Int = 0,
)

@Serializable
data class ContenidoMetricas(
    val cursos: Int = 0,
    val comunicados: Int = 0,
    val encuestas: Int = 0,
    val eventos: Int = 0,
    val recursos: Int = 0,
)

@Serializable
data class TasaMetrica(
    val id: Int = 0,
    val titulo: String = "",
    val destinatarios: Int = 0,
    val lecturas: Int = 0,
    val respuestas: Int = 0,
    val tasa: Int = 0,
)

@Serializable
data class EventoProximo(
    val id: Int = 0,
    val titulo: String = "",
    val inicio: String = "",
    val tipo: String = "evento",
)

@Serializable
data class Metricas(
    val personas: PersonasMetricas = PersonasMetricas(),
    val contenido: ContenidoMetricas = ContenidoMetricas(),
    @SerialName("ultimo_comunicado") val ultimoComunicado: TasaMetrica? = null,
    @SerialName("ultima_encuesta") val ultimaEncuesta: TasaMetrica? = null,
    @SerialName("proximos_eventos") val proximosEventos: List<EventoProximo> = emptyList(),
)

@Serializable
data class NotaCargada(
    val id: Int = 0,
    @SerialName("alumno_id") val alumnoId: Int = 0,
    val alumno: String = "",
    @SerialName("materia_id") val materiaId: Int = 0,
    val materia: String = "",
    val periodo: String = "",
    val nota: Double? = null,
    val letra: String = "",
    val comentario: String = "",
)

@Serializable
data class NotasDelCurso(
    val curso: CursoRef = CursoRef(),
    val notas: List<NotaCargada> = emptyList(),
)

@Serializable
data class RolInvitable(val valor: String = "", val nombre: String = "")

@Serializable
data class Invitacion(
    val id: Int = 0,
    val rol: String = "",
    @SerialName("rol_label") val rolLabel: String = "",
    val curso: CursoRef? = null,
    val email: String? = null,
    val estado: String = "",
    val usos: Int = 1,
    val restantes: Int = 0,
    val vence: String = "",
    val creada: String = "",
    val link: String? = null,
)

@Serializable
data class Invitaciones(
    val roles: List<RolInvitable> = emptyList(),
    val invitaciones: List<Invitacion> = emptyList(),
)

@Serializable
data class DelegadoFicha(
    @SerialName("user_id") val userId: Int = 0,
    val nombre: String = "",
    @SerialName("curso_id") val cursoId: Int = 0,
    val curso: String = "",
    val turno: String = "",
    val telefono: String = "",
    val suspendido: Boolean = false,
)

@Serializable
data class SesionAbierta(
    val id: String = "",
    val nombre: String = "",
    val creado: Long = 0,
    val usado: Long = 0,
    val actual: Boolean = false,
)

/* ---------------------------------------------------------- la foto */

/**
 * Todo lo de una persona, para guardarlo en el teléfono (`GET /sincronizar`).
 *
 * Es lo único que las pantallas de consulta leen: nada se pide al servidor
 * "al abrir". Así el aula sin wifi se ve igual que la casa con wifi.
 */
@Serializable
data class Snapshot(
    val perfil: Usuario? = null,
    @SerialName("mis_datos") val misDatos: MisDatos = MisDatos(),
    val horario: Horario = Horario(),
    val comunicados: Comunicados = Comunicados(),
    val boletin: Boletin? = null,
    val tareas: Tareas = Tareas(),
    val calendario: Calendario = Calendario(),
    val recursos: Recursos = Recursos(),
    val encuestas: Encuestas = Encuestas(),
    val faq: List<PreguntaFrecuente> = emptyList(),
    @SerialName("mis_mensajes") val misMensajes: MisMensajes = MisMensajes(),
    @SerialName("categorias_reporte") val categoriasReporte: List<String> = emptyList(),
    val gestion: Gestion = Gestion(),
    @SerialName("preferencias_push") val preferenciasPush: Map<String, Boolean> = emptyMap(),
    val version: String = "",
    val generado: String = "",
)
