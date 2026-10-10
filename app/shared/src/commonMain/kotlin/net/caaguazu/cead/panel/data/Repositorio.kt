@file:OptIn(kotlin.time.ExperimentalTime::class)

package net.caaguazu.cead.panel.data

import io.ktor.client.engine.HttpClientEngine
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonPrimitive
import net.caaguazu.cead.panel.Config
import net.caaguazu.cead.panel.plataforma.ArchivoElegido
import net.caaguazu.cead.panel.plataforma.Plataforma
import net.caaguazu.cead.panel.util.Fechas
import okio.FileSystem
import okio.Path.Companion.toPath
import kotlin.random.Random

/** Un mensaje de la charla con CEADI. [esPropio] = lo escribió la persona. */
data class MensajeCeadi(val texto: String, val esPropio: Boolean, val error: Boolean = false)

sealed interface EstadoSesion {
    /** Todavía leyendo del disco: no mostrar ni el login ni la app. */
    data object Cargando : EstadoSesion

    /** [motivo] explica por qué se llegó acá cuando no fue a propósito (sesión vencida). */
    data class SinSesion(val motivo: String? = null) : EstadoSesion

    data class Activa(val usuario: Usuario) : EstadoSesion
}

/**
 * El corazón de la app: qué se ve, qué está esperando salir y cómo se
 * sincroniza.
 *
 * Las pantallas de consulta leen [datos] y nada más; nunca piden algo al
 * servidor al abrirse. Las escrituras de todos los días (marcar una tarea,
 * mandar un mensaje, cargar una nota) se anotan en la cola y salen cuando hay
 * conexión: si no la hay, la persona no se entera de la diferencia.
 *
 * Lo que necesita ver datos de terceros en el momento (el buzón de
 * coordinación, los teléfonos de los delegados) NO pasa por acá: es en línea y
 * no se guarda en el teléfono. Ver [enLinea].
 */
class Repositorio(
    val plataforma: Plataforma,
    private val alcance: CoroutineScope,
    motor: HttpClientEngine? = null,
    private val sistema: FileSystem = FileSystem.SYSTEM,
    sitio: String = Config.SITIO,
    private val reloj: () -> Long = { Fechas.milis() },
    /** Falso en las pruebas: ahí se llama a [sincronizarAhora] a mano, para no competir con el trabajador. */
    iniciarTrabajador: Boolean = true,
    /** Cada cuánto se reintenta lo que quedó esperando. Más corto en las pruebas. */
    private val reintentoMs: Long = REINTENTO_MS,
) {

    private val almacen = Almacen(sistema, plataforma.dirDatos + "/cead")
    private val seguro = plataforma.almacenSeguro

    internal val api = ApiCliente(
        sitio = sitio,
        token = { seguro.leer(K_TOKEN) },
        alSesionVencida = { sesionVencida() },
        agente = "CEAD-App/${Config.VERSION} (${plataforma.nombre})",
        motor = motor,
        sistema = sistema,
    )

    /* ---------------------------------------------------------- el estado */

    val sesion = MutableStateFlow<EstadoSesion>(EstadoSesion.Cargando)

    private val servidor = MutableStateFlow<Snapshot?>(null)
    val pendientes = MutableStateFlow<List<Envio>>(emptyList())
    val locales = MutableStateFlow(Locales())

    /** Lo que se ve: la copia del servidor con los envíos pendientes por encima. */
    val datos: StateFlow<Snapshot?> = combine(servidor, pendientes, locales) { s, p, l ->
        s?.let { Proyeccion.aplicar(it, p, l) }
    }.stateIn(alcance, SharingStarted.Eagerly, null)

    val sincronizando = MutableStateFlow(false)

    /**
     * La conversación con CEADI. Vive solo en memoria y se borra al salir de la
     * sesión: lo que se le pregunta a un asistente es más íntimo de lo que
     * parece, y no hay motivo para dejarlo en el teléfono.
     */
    val conversacionCeadi = MutableStateFlow<List<MensajeCeadi>>(emptyList())

    /** Milisegundos de la última sincronización que llegó al servidor, o 0. */
    val ultimaSync = MutableStateFlow(0L)

    /** El último intento falló por falta de red: se muestra «sin conexión» y se sigue con lo guardado. */
    val sinConexion = MutableStateFlow(false)

    /** Mensajes de una sola vez para mostrar abajo (un envío rechazado, un error). */
    val avisos: SharedFlow<String> get() = _avisos
    private val _avisos = MutableSharedFlow<String>(extraBufferCapacity = 16)

    private var ultimoIntento = 0L

    private val colaLock = Mutex()
    private val pedidos = Channel<Unit>(Channel.CONFLATED)

    init {
        if (iniciarTrabajador) {
            alcance.launch {
                for (p in pedidos) sincronizarAhora()
            }
            // Sin wifi en el colegio, lo que se carga en el aula espera. Cuando
            // vuelve la señal tiene que salir solo, sin que nadie se acuerde de
            // abrir la app ni de tirar de la pantalla: el teléfono no avisa a la
            // app cuándo hay red, así que se prueba cada tanto mientras haya algo
            // esperando (y solo entonces: no se gasta batería ni datos en vano).
            alcance.launch {
                while (true) {
                    delay(reintentoMs)
                    if (usuarioActual() != null && !sincronizando.value && pendientes.value.any { !it.fallido }) pedirSync()
                }
            }
        }
        // Se lee del disco acá mismo, sin esperar: es un archivo chico, y así la
        // primera pantalla ya sabe si hay sesión (no parpadea el login).
        arrancar()
        plataforma.push.alCambiarElToken { t -> alcance.launch { registrarPush(t, forzar = true) } }
    }

    private fun arrancar() {
        val token = seguro.leer(K_TOKEN)
        val usuario = seguro.leer(K_USUARIO)?.let { runCatching { AppJson.decodeFromString(Usuario.serializer(), it) }.getOrNull() }
        if (token == null || usuario == null) {
            sesion.value = EstadoSesion.SinSesion()
            return
        }
        cargarDeDisco(usuario.id)
        sesion.value = EstadoSesion.Activa(usuario)
        pedirSync()
    }

    /* ---------------------------------------------------------- sesión */

    /** Devuelve el error para mostrar, o null si entró. */
    suspend fun iniciarSesion(usuario: String, clave: String): ErrorApi? {
        val r = api.post(
            "/auth/login",
            RespuestaLogin.serializer(),
            cuerpoJson = ApiCliente.json(
                "usuario" to usuario.trim(),
                "clave" to clave,
                "dispositivo" to plataforma.nombreDelAparato(),
            ),
            autenticado = false,
        )
        return when (r) {
            is Resultado.Ok -> {
                seguro.guardar(K_TOKEN, r.valor.token)
                seguro.guardar(K_USUARIO, AppJson.encodeToString(Usuario.serializer(), r.valor.usuario))
                limpiarDeOtros(r.valor.usuario.id)
                cargarDeDisco(r.valor.usuario.id)
                sesion.value = EstadoSesion.Activa(r.valor.usuario)
                pedirSync()
                null
            }
            is Resultado.Fallo -> r.error
            Resultado.NoModificado -> ErrorApi(TipoError.SERVIDOR, "inesperado", "Respuesta inesperada.")
        }
    }

    /**
     * Sale de la sesión y borra TODO lo de esta persona del teléfono.
     *
     * Los envíos que no alcanzaron a salir se pierden: por eso la pantalla
     * avisa cuántos hay antes de confirmar.
     */
    suspend fun cerrarSesion() {
        val tokenPush = locales.value.ultimoTokenPush
        // Mejor esfuerzo: sin red, igual se sale. El servidor igual mata el
        // token cuando venza, y sacar el teléfono del servidor ya no importa
        // si el token que lo ataba deja de existir.
        if (tokenPush != null) {
            api.post("/dispositivos/baja", JsonObject.serializer(), ApiCliente.json("token" to tokenPush))
        }
        api.post("/auth/logout", JsonObject.serializer())
        borrarTodoLocal()
        sesion.value = EstadoSesion.SinSesion()
    }

    /** Para los botones: cerrar sesión sin quedarse esperando la red. */
    fun cerrarSesionEnSegundoPlano() {
        alcance.launch { cerrarSesion() }
    }

    private fun sesionVencida() {
        // No se borran los datos: si es la misma persona que vuelve a entrar,
        // los envíos que había dejado en cola siguen ahí.
        seguro.borrar(K_TOKEN)
        sesion.value = EstadoSesion.SinSesion("Tu sesión venció. Entrá de nuevo.")
    }

    private fun borrarTodoLocal() {
        seguro.borrar(K_TOKEN)
        seguro.borrar(K_USUARIO)
        almacen.borrarTodo()
        servidor.value = null
        pendientes.value = emptyList()
        locales.value = Locales()
        ultimaSync.value = 0
        conversacionCeadi.value = emptyList()
    }

    /** Si entra otra persona, lo de la anterior se va: no se mezclan ni se filtran. */
    private fun limpiarDeOtros(uid: Int) {
        val propio = "u$uid-"
        almacen.listar("u").filter { !it.startsWith(propio) }.forEach { almacen.borrar(it) }
        almacen.listar("recursos-u").filter { !it.startsWith("recursos-u$uid") }.forEach { almacen.borrar(it) }
    }

    private fun usuarioActual(): Usuario? = (sesion.value as? EstadoSesion.Activa)?.usuario

    private fun archivo(parte: String): String = "u${usuarioActual()?.id ?: 0}-$parte.json"

    private fun cargarDeDisco(uid: Int) {
        val p = "u$uid-"
        servidor.value = almacen.leer("${p}snapshot.json")?.let { runCatching { AppJson.decodeFromString(Snapshot.serializer(), it) }.getOrNull() }
        pendientes.value = almacen.leer("${p}cola.json")?.let { runCatching { AppJson.decodeFromString(ColaGuardada.serializer(), it).envios }.getOrNull() } ?: emptyList()
        locales.value = almacen.leer("${p}locales.json")?.let { runCatching { AppJson.decodeFromString(Locales.serializer(), it) }.getOrNull() } ?: Locales()
        ultimaSync.value = locales.value.ultimaSync
    }

    private fun guardarCola() {
        almacen.escribir(archivo("cola"), AppJson.encodeToString(ColaGuardada.serializer(), ColaGuardada(pendientes.value)))
    }

    private fun guardarLocales() {
        almacen.escribir(archivo("locales"), AppJson.encodeToString(Locales.serializer(), locales.value))
    }

    /* ----------------------------------------------------- sincronización */

    /** Pide una sincronización (juntar varios pedidos en uno). No espera el resultado. */
    fun pedirSync() {
        pedidos.trySend(Unit)
    }

    /**
     * La app volvió a primer plano (se desbloqueó el teléfono, se salió de otra
     * app). Si hace un rato que no sincroniza, lo hace: así los comunicados de
     * la mañana están al abrir la app y no hay que tirar de la pantalla.
     * Si acaba de hacerlo, no: abrir y cerrar la app no tiene que gastar datos.
     */
    fun alPrimerPlano() {
        if (usuarioActual() == null) return
        if (reloj() - ultimoIntento >= PRIMER_PLANO_MIN_MS) pedirSync()
    }

    /**
     * Primero manda lo que quedó esperando, después trae lo nuevo. En ese
     * orden: traer primero haría que el servidor contestara con datos que no
     * incluyen lo que la persona hizo hace un rato.
     */
    internal suspend fun sincronizarAhora() {
        if (usuarioActual() == null) return
        ultimoIntento = reloj()
        sincronizando.value = true
        try {
            vaciarCola()
            if (usuarioActual() == null) return // la cola pudo cerrar la sesión (401)

            val r = api.get("/sincronizar", Snapshot.serializer(), etag = servidor.value?.version?.ifEmpty { null })
            when (r) {
                is Resultado.Ok -> {
                    servidor.value = r.valor
                    almacen.escribir(archivo("snapshot"), AppJson.encodeToString(Snapshot.serializer(), r.valor))
                    sinConexion.value = false
                    marcarSync()
                    // El perfil del servidor es el que manda (el rol pudo cambiar).
                    r.valor.perfil?.let { actualizarUsuario(it) }
                }
                Resultado.NoModificado -> {
                    sinConexion.value = false
                    marcarSync()
                }
                is Resultado.Fallo -> when (r.error.tipo) {
                    TipoError.SIN_CONEXION -> sinConexion.value = true
                    TipoError.SESION_VENCIDA -> Unit // ya lo manejó sesionVencida()
                    else -> _avisos.tryEmit(r.error.mensaje)
                }
            }
            if (!sinConexion.value && usuarioActual() != null) registrarPush(null, forzar = false)
        } finally {
            sincronizando.value = false
        }
    }

    private fun marcarSync() {
        val ahora = reloj()
        ultimaSync.value = ahora
        locales.update { it.copy(ultimaSync = ahora) }
        guardarLocales()
    }

    private fun actualizarUsuario(u: Usuario) {
        if (u.id != usuarioActual()?.id) return
        sesion.value = EstadoSesion.Activa(u)
        seguro.guardar(K_USUARIO, AppJson.encodeToString(Usuario.serializer(), u))
    }

    /* -------------------------------------------------------- la cola */

    /**
     * Anota un envío y pide sacarlo. Vuelve enseguida: la pantalla ya muestra
     * el cambio (por la proyección) sin esperar a nadie.
     */
    fun encolar(envio: Envio) {
        alcance.launch {
            colaLock.withLock {
                val nuevo = envio.copy(creado = if (envio.creado == 0L) reloj() else envio.creado)
                pendientes.update { actual ->
                    // Un envío con la misma clave reemplaza al que todavía espera.
                    val sin = if (nuevo.clave != null) actual.filterNot { it.clave == nuevo.clave && !it.fallido } else actual
                    sin + nuevo
                }
                guardarCola()
            }
            pedirSync()
        }
    }

    private suspend fun vaciarCola() {
        // Una copia: la lista cambia mientras se envía (la gente sigue usando la app).
        val aEnviar = colaLock.withLock { pendientes.value.filter { !it.fallido } }
        for (envio in aEnviar) {
            val r = enviar(envio)
            when {
                r is Resultado.Ok -> {
                    alTerminar(envio, r.valor)
                    quitar(envio.id)
                }
                r is Resultado.Fallo && r.error.tipo == TipoError.SESION_VENCIDA -> return
                r is Resultado.Fallo && r.error.reintentable -> {
                    registrarIntento(envio.id)
                    return // lo más probable es la red: no sirve seguir con los demás
                }
                r is Resultado.Fallo -> marcarFallido(envio, r.error.mensaje)
                else -> quitar(envio.id)
            }
        }
    }

    private suspend fun enviar(e: Envio): Resultado<JsonObject> =
        if (e.archivos.isEmpty()) {
            api.post(e.ruta, JsonObject.serializer(), e.cuerpo, clave = e.id)
        } else {
            api.postMultipart(
                e.ruta,
                JsonObject.serializer(),
                campos = e.campos,
                archivos = e.archivos.map { ArchivoAdjunto(it.campo, it.ruta, it.nombre, it.tipoMime) },
                clave = e.id,
            )
        }

    private suspend fun quitar(id: String) = colaLock.withLock {
        val e = pendientes.value.firstOrNull { it.id == id }
        pendientes.update { l -> l.filterNot { it.id == id } }
        guardarCola()
        e?.archivos?.forEach { runCatching { borrarArchivo(it.ruta) } }
    }

    private suspend fun registrarIntento(id: String) = colaLock.withLock {
        pendientes.update { l -> l.map { if (it.id == id) it.copy(intentos = it.intentos + 1) else it } }
        guardarCola()
    }

    private suspend fun marcarFallido(e: Envio, motivo: String) {
        colaLock.withLock {
            pendientes.update { l -> l.map { if (it.id == e.id) it.copy(error = motivo) else it } }
            guardarCola()
        }
        _avisos.tryEmit("No se pudo enviar «${e.titulo}»: $motivo")
    }

    /** Descartar un envío rechazado (o uno que ya no se quiere mandar). */
    fun descartar(id: String) {
        alcance.launch { quitar(id) }
    }

    /** Volver a intentar uno rechazado, por ejemplo después de corregir algo del lado del colegio. */
    fun reintentar(id: String) {
        alcance.launch {
            colaLock.withLock {
                pendientes.update { l -> l.map { if (it.id == id) it.copy(error = null, intentos = 0) else it } }
                guardarCola()
            }
            pedirSync()
        }
    }

    private fun borrarArchivo(ruta: String) {
        // Solo lo que está bajo el directorio de la app: la ruta viene de un
        // archivo en disco, y no se confía en que nadie lo haya tocado.
        if (!ruta.startsWith(plataforma.dirCache) && !ruta.startsWith(plataforma.dirDatos)) return
        sistema.delete(ruta.toPath(), mustExist = false)
    }

    /** Lo que hay que hacer con la respuesta de un envío que salió bien. */
    private fun alTerminar(e: Envio, respuesta: JsonObject) {
        when (e.tipo) {
            Tipos.REPORTE -> {
                val codigo = respuesta["codigo"]?.jsonPrimitive?.contentOrNull ?: return
                locales.update {
                    it.copy(
                        reportes = it.reportes + ReporteLocal(
                            codigo = codigo,
                            tipo = e.datos["tipo"] ?: "anonimo",
                            categoria = e.datos["categoria"] ?: "",
                            enviado = reloj(),
                        ),
                    )
                }
                guardarLocales()
            }
        }
    }

    /* ---------------------------------------------------------- avisos push */

    private suspend fun registrarPush(nuevo: String?, forzar: Boolean) {
        if (usuarioActual() == null || !plataforma.push.disponible) return
        val token = nuevo ?: plataforma.push.token() ?: return
        val l = locales.value
        val reciente = reloj() - l.pushRegistradoEn < SIETE_DIAS
        if (!forzar && token == l.ultimoTokenPush && reciente) return

        val r = api.post(
            "/dispositivos",
            JsonObject.serializer(),
            ApiCliente.json("token" to token, "plataforma" to plataforma.nombre, "nombre" to plataforma.nombreDelAparato()),
        )
        if (r is Resultado.Ok) {
            locales.update { it.copy(ultimoTokenPush = token, pushRegistradoEn = reloj()) }
            guardarLocales()
        }
    }

    /* ------------------------------------------------------ envíos del día a día */

    fun marcarTarea(id: Int, hecha: Boolean) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.TAREA_HECHA, titulo = "Tarea ${if (hecha) "hecha" else "pendiente"}",
            ruta = "/tareas/$id/hecha", cuerpo = ApiCliente.json("hecha" to hecha),
            clave = "${Tipos.TAREA_HECHA}:$id", datos = mapOf("id" to "$id", "hecha" to "$hecha"),
        ),
    )

    fun fijarFavorito(id: Int, favorito: Boolean) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.FAVORITO, titulo = "Favorito",
            ruta = "/recursos/$id/favorito", cuerpo = ApiCliente.json("favorito" to favorito),
            clave = "${Tipos.FAVORITO}:$id", datos = mapOf("id" to "$id", "favorito" to "$favorito"),
        ),
    )

    /** Anota la lectura de un comunicado, una sola vez. */
    fun marcarLeido(id: Int) {
        val ya = datos.value?.comunicados?.comunicados?.firstOrNull { it.id == id }?.leido ?: return
        if (ya) return
        encolar(
            Envio(
                id = nuevoId(), tipo = Tipos.COMUNICADO_LEIDO, titulo = "Lectura de comunicado",
                ruta = "/comunicados/$id/leido", clave = "${Tipos.COMUNICADO_LEIDO}:$id", datos = mapOf("id" to "$id"),
            ),
        )
    }

    /** [respuestas]: id de pregunta → texto, o lista de textos si es de selección múltiple. */
    fun responderEncuesta(encuesta: Encuesta, respuestas: Map<Int, Any>) {
        if (encuesta.anonima) {
            locales.update { it.copy(encuestasAnonimas = (it.encuestasAnonimas + encuesta.id).distinct()) }
            guardarLocales()
        }
        encolar(
            Envio(
                id = nuevoId(), tipo = Tipos.ENCUESTA, titulo = "Encuesta «${encuesta.titulo}»",
                ruta = "/encuestas/${encuesta.id}/respuestas",
                cuerpo = ApiCliente.json("respuestas" to respuestas.mapKeys { it.key.toString() }),
                datos = mapOf("id" to "${encuesta.id}"),
            ),
        )
    }

    fun enviarMensaje(destinatario: String, mensaje: String) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.CONTACTO, titulo = "Mensaje al colegio",
            ruta = "/contacto", cuerpo = ApiCliente.json("destinatario" to destinatario, "mensaje" to mensaje),
        ),
    )

    fun enviarReporte(tipo: String, categoria: String, texto: String) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.REPORTE,
            titulo = if (tipo == "anonimo") "Reporte anónimo" else "Reporte confidencial",
            ruta = "/reportes",
            cuerpo = ApiCliente.json("tipo" to tipo, "categoria" to categoria, "texto" to texto),
            datos = mapOf("tipo" to tipo, "categoria" to categoria),
        ),
    )

    fun guardarPerfil(nombre: String, telefono: String) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.PERFIL, titulo = "Cambios en mi perfil",
            ruta = "/perfil", cuerpo = ApiCliente.json("nombre" to nombre, "telefono" to telefono),
            clave = Tipos.PERFIL, datos = mapOf("nombre" to nombre, "telefono" to telefono),
        ),
    )

    fun cambiarFoto(a: ArchivoElegido) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.FOTO, titulo = "Foto de perfil", ruta = "/perfil/foto",
            archivos = listOf(AdjuntoGuardado("foto", a.ruta, a.nombre, a.tipoMime)), clave = Tipos.FOTO,
        ),
    )

    fun entregarTarea(id: Int, a: ArchivoElegido) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.ENTREGA, titulo = "Entrega de tarea", ruta = "/tareas/$id/entrega",
            archivos = listOf(AdjuntoGuardado("entrega", a.ruta, a.nombre, a.tipoMime)),
        ),
    )

    fun guardarPreferencias(prefs: Map<String, Boolean>) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.PREFERENCIAS, titulo = "Preferencias de avisos",
            ruta = "/notificaciones/preferencias", cuerpo = ApiCliente.json("preferencias" to prefs),
            clave = Tipos.PREFERENCIAS, datos = prefs.mapValues { "${it.value}" },
        ),
    )

    /* ------------------------------------------------ envíos del personal */

    fun publicarComunicado(
        titulo: String,
        texto: String,
        audiencias: List<AudienciaElegida>,
        categoria: String,
        avisarEmail: Boolean,
        imagen: ArchivoElegido?,
    ) {
        val audJson = AppJson.encodeToString(kotlinx.serialization.builtins.ListSerializer(AudienciaElegida.serializer()), audiencias)
        val etiqueta = titulo.ifBlank { texto.take(40) }
        encolar(
            if (imagen == null) {
                Envio(
                    id = nuevoId(), tipo = Tipos.COMUNICADO, titulo = "Comunicado «$etiqueta»", ruta = "/gestion/comunicados",
                    cuerpo = ApiCliente.json("titulo" to titulo, "texto" to texto, "audiencias" to audiencias.map { mapOf("type" to it.type, "value" to it.value) }, "categoria" to categoria, "avisar_email" to avisarEmail),
                )
            } else {
                Envio(
                    id = nuevoId(), tipo = Tipos.COMUNICADO, titulo = "Comunicado «$etiqueta»", ruta = "/gestion/comunicados",
                    campos = mapOf("titulo" to titulo, "texto" to texto, "audiencias" to audJson, "categoria" to categoria, "avisar_email" to if (avisarEmail) "1" else "0"),
                    archivos = listOf(AdjuntoGuardado("imagen", imagen.ruta, imagen.nombre, imagen.tipoMime)),
                )
            },
        )
    }

    fun cargarEvento(
        titulo: String, detalle: String, inicio: String, fin: String, todoElDia: Boolean,
        lugar: String, tipo: String, audiencias: List<AudienciaElegida>,
    ) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.EVENTO, titulo = "Evento «$titulo»", ruta = "/gestion/eventos",
            cuerpo = ApiCliente.json(
                "titulo" to titulo, "detalle" to detalle, "inicio" to inicio, "fin" to fin, "todo_el_dia" to todoElDia,
                "lugar" to lugar, "tipo" to tipo, "audiencias" to audiencias.map { mapOf("type" to it.type, "value" to it.value) },
            ),
        ),
    )

    fun cargarNota(alumnoId: Int, alumno: String, cursoId: Int, materiaId: Int, materiaNueva: String, materia: String, periodo: String, nota: Double, comentario: String) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.NOTA, titulo = "Nota de $alumno en $materia ($periodo)", ruta = "/gestion/notas",
            cuerpo = ApiCliente.json(
                "alumno_id" to alumnoId, "curso_id" to cursoId, "materia_id" to materiaId, "materia_nueva" to materiaNueva,
                "periodo" to periodo, "nota" to nota, "comentario" to comentario,
            ),
            // Cargar la misma nota dos veces antes de que salga: queda la última.
            clave = "${Tipos.NOTA}:$alumnoId:$cursoId:$materia:$periodo",
        ),
    )

    fun publicarArticulo(
        titulo: String, contenido: String, categoria: Int, formato: String, fechaEvento: String,
        lugarEvento: String, redes: Boolean, imagen: ArchivoElegido?,
    ) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.ARTICULO, titulo = "Nota del sitio «$titulo»", ruta = "/gestion/articulos",
            cuerpo = if (imagen == null) ApiCliente.json(
                "titulo" to titulo, "contenido" to contenido, "categoria" to categoria, "formato" to formato,
                "fecha_evento" to fechaEvento, "lugar_evento" to lugarEvento, "redes" to redes,
            ) else null,
            campos = if (imagen == null) emptyMap() else mapOf(
                "titulo" to titulo, "contenido" to contenido, "categoria" to "$categoria", "formato" to formato,
                "fecha_evento" to fechaEvento, "lugar_evento" to lugarEvento, "redes" to if (redes) "1" else "0",
            ),
            archivos = imagen?.let { listOf(AdjuntoGuardado("imagen", it.ruta, it.nombre, it.tipoMime)) } ?: emptyList(),
        ),
    )

    fun cambiarEstadoTarea(id: Int, estado: String) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.TAREA_ESTADO, titulo = "Estado de una tarea del curso",
            ruta = "/delegado/tareas/$id/estado", cuerpo = ApiCliente.json("estado" to estado),
            clave = "${Tipos.TAREA_ESTADO}:$id", datos = mapOf("id" to "$id", "estado" to estado),
        ),
    )

    fun asignarTarea(titulo: String, detalle: String, cursoId: Int, prioridad: String, vence: String) = encolar(
        Envio(
            id = nuevoId(), tipo = Tipos.TAREA_NUEVA, titulo = "Tarea «$titulo»", ruta = "/gestion/tareas",
            cuerpo = ApiCliente.json("titulo" to titulo, "detalle" to detalle, "curso_id" to cursoId, "prioridad" to prioridad, "vence" to vence),
        ),
    )

    /** Un mensaje de una sola vez para la pantalla (abajo, que se va solo). */
    fun mensaje(texto: String) {
        _avisos.tryEmit(texto)
    }

    /* ---------------------------------------------------------- en línea */

    /** Lo que solo se puede hacer con conexión y no se guarda en el teléfono. */
    val enLinea = EnLinea(api)

    /* ------------------------------------------------- recursos sin conexión */

    /** La ruta del archivo de un recurso ya bajado, o null si no está. */
    fun rutaDescarga(r: Recurso): String? {
        val nombre = locales.value.descargas["${r.id}"] ?: return null
        val ruta = almacen.rutaAbsoluta("recursos-u${usuarioActual()?.id ?: 0}/$nombre")
        return if (sistema.exists(ruta.toPath())) ruta else null
    }

    /** Baja un recurso para verlo sin conexión. Devuelve el error, o null si anduvo. */
    suspend fun descargarRecurso(r: Recurso): ErrorApi? {
        val url = r.url ?: return ErrorApi(TipoError.RECHAZADO, "sin_url", "Este recurso no tiene archivo.")
        val uid = usuarioActual()?.id ?: return null
        val nombre = "${r.id}-" + (url.substringAfterLast('/').substringBefore('?').ifBlank { "recurso" })
        val destino = almacen.rutaAbsoluta("recursos-u$uid/$nombre")
        val e = api.descargar(url, destino)
        if (e == null) {
            locales.update { it.copy(descargas = it.descargas + ("${r.id}" to nombre)) }
            guardarLocales()
        }
        return e
    }

    fun borrarDescarga(r: Recurso) {
        val nombre = locales.value.descargas["${r.id}"] ?: return
        almacen.borrar("recursos-u${usuarioActual()?.id ?: 0}/$nombre")
        locales.update { it.copy(descargas = it.descargas - "${r.id}") }
        guardarLocales()
    }

    companion object {
        /** Cada cuánto se reintenta lo que no pudo salir. */
        const val REINTENTO_MS = 60_000L

        /** Sin sincronizar hace más que esto, volver a la app sincroniza. */
        const val PRIMER_PLANO_MIN_MS = 2 * 60_000L

        private const val K_TOKEN = "token"
        private const val K_USUARIO = "usuario"
        private const val SIETE_DIAS = 7L * 24 * 60 * 60 * 1000

        /** Una clave de idempotencia: 32 caracteres hexadecimales al azar. */
        fun nuevoId(): String =
            Random.nextBytes(16).joinToString("") { (it.toInt() and 0xff).toString(16).padStart(2, '0') }
    }

    private fun nuevoId() = Companion.nuevoId()
}
