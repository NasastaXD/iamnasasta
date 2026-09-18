package py.edu.cead.panel

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import py.edu.cead.panel.data.Almacen
import py.edu.cead.panel.data.Api
import py.edu.cead.panel.data.Boletin
import py.edu.cead.panel.data.Comunicado
import py.edu.cead.panel.data.Comunicados
import py.edu.cead.panel.data.Horario
import py.edu.cead.panel.data.Perfil
import py.edu.cead.panel.data.SesionVencida
import py.edu.cead.panel.data.Tareas

/**
 * Una sección que se está trayendo del servidor.
 *
 * Los tres estados son distintos a propósito: «cargando» no es lo mismo que
 * «vacío», y «vacío» no es lo mismo que «falló». Colapsarlos es lo que produce
 * pantallas que dicen «no hay nada» cuando en realidad se cayó internet.
 */
sealed interface Carga<out T> {
    data object Cargando : Carga<Nothing>
    data class Listo<T>(val datos: T) : Carga<T>
    data class Falló(val mensaje: String) : Carga<Nothing>
}

data class EstadoPanel(
    val perfil: Perfil? = null,
    val horario: Carga<Horario> = Carga.Cargando,
    val comunicados: Carga<Comunicados> = Carga.Cargando,
    val boletin: Carga<Boletin> = Carga.Cargando,
    val tareas: Carga<Tareas> = Carga.Cargando,
    val abierto: Comunicado? = null,
)

class PanelViewModel(app: Application) : AndroidViewModel(app) {

    private val almacen = Almacen(app)
    private val api = Api(BuildConfig.SITIO, almacen)

    private val _estado = MutableStateFlow(EstadoPanel())
    val estado: StateFlow<EstadoPanel> = _estado.asStateFlow()

    private val _entrando = MutableStateFlow(false)
    val entrando: StateFlow<Boolean> = _entrando.asStateFlow()

    private val _errorLogin = MutableStateFlow<String?>(null)
    val errorLogin: StateFlow<String?> = _errorLogin.asStateFlow()

    private val _sesionAbierta = MutableStateFlow(almacen.haySesion)
    val sesionAbierta: StateFlow<Boolean> = _sesionAbierta.asStateFlow()

    /** El nombre de quien entró la última vez, para saludar antes de que cargue. */
    val nombreRecordado: String? get() = almacen.nombre

    init {
        if (almacen.haySesion) cargarTodo()
    }

    /* --------------------------------------------------------------- sesión */

    fun entrar(usuario: String, clave: String) {
        if (_entrando.value) return
        _entrando.value = true
        _errorLogin.value = null
        viewModelScope.launch {
            try {
                val r = api.login(usuario.trim(), clave)
                _estado.update { it.copy(perfil = r.usuario) }
                _sesionAbierta.value = true
                cargarTodo()
            } catch (e: Exception) {
                _errorLogin.value = e.message ?: "No se pudo entrar."
            } finally {
                _entrando.value = false
            }
        }
    }

    fun salir() {
        viewModelScope.launch {
            api.logout()
            _estado.value = EstadoPanel()
            _sesionAbierta.value = false
        }
    }

    /**
     * El token dejó de valer mientras la app estaba abierta (venció, la
     * suspendieron, cambió la contraseña en otro lado). Se vuelve a la entrada
     * en vez de dejar pantallas a medio cargar con un error incomprensible.
     */
    private fun caducó() {
        _estado.value = EstadoPanel()
        _sesionAbierta.value = false
        _errorLogin.value = "Tu sesión venció. Entrá de nuevo."
    }

    /* ---------------------------------------------------------------- datos */

    fun cargarTodo() {
        cargarPerfil()
        cargarHorario()
        cargarComunicados()
        cargarBoletin()
        cargarTareas()
    }

    private fun cargarPerfil() = pedir({ api.yo() }) { p ->
        _estado.update { it.copy(perfil = p) }
    }

    fun cargarHorario() {
        _estado.update { it.copy(horario = Carga.Cargando) }
        pedirCarga({ api.horario() }) { c -> _estado.update { it.copy(horario = c) } }
    }

    fun cargarComunicados() {
        _estado.update { it.copy(comunicados = Carga.Cargando) }
        pedirCarga({ api.comunicados() }) { c -> _estado.update { it.copy(comunicados = c) } }
    }

    fun cargarBoletin() {
        _estado.update { it.copy(boletin = Carga.Cargando) }
        pedirCarga({ api.boletin() }) { c -> _estado.update { it.copy(boletin = c) } }
    }

    fun cargarTareas() {
        _estado.update { it.copy(tareas = Carga.Cargando) }
        pedirCarga({ api.tareas() }) { c -> _estado.update { it.copy(tareas = c) } }
    }

    fun abrir(id: Int) {
        pedir({ api.comunicado(id) }) { c ->
            _estado.update { est ->
                // La lista se actualiza sola: abrir un comunicado lo marca leído
                // en el servidor, y si acá siguiera en negrita la persona vería
                // que «no se guardó» algo que sí se guardó.
                val listaActual = est.comunicados
                val nueva = if (listaActual is Carga.Listo) {
                    Carga.Listo(
                        listaActual.datos.copy(
                            comunicados = listaActual.datos.comunicados.map {
                                if (it.id == id) it.copy(leido = true) else it
                            },
                            sinLeer = (listaActual.datos.sinLeer - 1).coerceAtLeast(0),
                        )
                    )
                } else {
                    listaActual
                }
                est.copy(abierto = c, comunicados = nueva)
            }
        }
    }

    fun cerrarComunicado() = _estado.update { it.copy(abierto = null) }

    /* -------------------------------------------------------------- interno */

    private fun <T> pedir(bloque: suspend () -> T, alLlegar: (T) -> Unit) {
        viewModelScope.launch {
            try {
                alLlegar(bloque())
            } catch (e: SesionVencida) {
                caducó()
            } catch (e: Exception) {
                // Silencioso a propósito: esto alimenta detalles secundarios
                // (perfil, un comunicado suelto) y no la pantalla entera.
            }
        }
    }

    private fun <T> pedirCarga(bloque: suspend () -> T, alLlegar: (Carga<T>) -> Unit) {
        viewModelScope.launch {
            try {
                alLlegar(Carga.Listo(bloque()))
            } catch (e: SesionVencida) {
                caducó()
            } catch (e: Exception) {
                alLlegar(Carga.Falló(e.message ?: "No se pudo cargar."))
            }
        }
    }
}
