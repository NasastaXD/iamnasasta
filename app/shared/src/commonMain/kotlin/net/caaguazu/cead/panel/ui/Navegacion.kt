package net.caaguazu.cead.panel.ui

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Campaign
import androidx.compose.material.icons.outlined.Checklist
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Menu
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.ui.graphics.vector.ImageVector
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

enum class Tab(val titulo: String, val icono: ImageVector) {
    INICIO("Inicio", Icons.Outlined.Home),
    HORARIO("Horario", Icons.Outlined.Schedule),
    COMUNICADOS("Avisos", Icons.Outlined.Campaign),
    TAREAS("Tareas", Icons.Outlined.Checklist),
    MAS("Más", Icons.Outlined.Menu),
}

/** Cada pantalla a la que se puede ir. Las del personal solo aparecen si la persona tiene el permiso. */
sealed interface Destino {
    // De consulta.
    data class Comunicado(val id: Int) : Destino
    data object Calendario : Destino
    data object Boletin : Destino
    data object Recursos : Destino
    data object Encuestas : Destino
    data class ResponderEncuesta(val id: Int) : Destino
    data object Faq : Destino
    data object Ceadi : Destino
    data object Novedades : Destino

    // Cuenta y escribir al colegio.
    data object Perfil : Destino
    data object Ajustes : Destino
    data object Sesiones : Destino
    data object Contacto : Destino
    data object Reportar : Destino
    data object MisMensajes : Destino
    data object Pendientes : Destino

    // Del personal.
    data object PublicarComunicado : Destino
    data object CargarEvento : Destino
    data object CargarNotas : Destino
    data object Buzon : Destino
    data object Metricas : Destino
    data object Invitaciones : Destino
    data object PublicarArticulo : Destino
    data object TareasDelCurso : Destino
    data object AsignarTarea : Destino
    data object Delegados : Destino
}

/**
 * Dónde está parada la persona: una pestaña y, por encima, las pantallas que
 * fue abriendo. Volver desarma de a una; tocar una pestaña las vacía.
 *
 * Es un objeto aparte de las pantallas, que el sistema puede destruir y
 * recrear, para que girar el teléfono no mande a nadie al inicio.
 */
class Navegacion {
    private val _tab = MutableStateFlow(Tab.INICIO)
    private val _pila = MutableStateFlow<List<Destino>>(emptyList())

    val tab: StateFlow<Tab> get() = _tab
    val pila: StateFlow<List<Destino>> get() = _pila

    val actual: Destino? get() = _pila.value.lastOrNull()

    /** ¿Hay algo a lo que volver? Android lo usa para decidir si el botón «atrás» cierra la app. */
    val puedeVolver: Boolean get() = _pila.value.isNotEmpty() || _tab.value != Tab.INICIO

    fun ir(destino: Destino) {
        // Abrir dos veces seguidas la misma pantalla (un doble toque) no la apila dos veces.
        if (_pila.value.lastOrNull() == destino) return
        _pila.value = _pila.value + destino
    }

    fun volver(): Boolean = when {
        _pila.value.isNotEmpty() -> { _pila.value = _pila.value.dropLast(1); true }
        _tab.value != Tab.INICIO -> { _tab.value = Tab.INICIO; true }
        else -> false
    }

    fun elegirTab(tab: Tab) {
        _pila.value = emptyList()
        _tab.value = tab
    }

    /** Reemplaza la pantalla de arriba (al terminar un formulario, para no volver a él). */
    fun reemplazar(destino: Destino) {
        _pila.value = _pila.value.dropLast(1) + destino
    }

    /**
     * Abre lo que dice un aviso del teléfono (los datos que manda el servidor).
     * Lo que no se reconoce deja la app en el inicio: mejor eso que no abrirla.
     */
    fun abrirDesdeAviso(tipo: String?, id: String?) {
        _pila.value = emptyList()
        val n = id?.toIntOrNull()
        when (tipo) {
            "comunicado" -> if (n != null) { _tab.value = Tab.COMUNICADOS; _pila.value = listOf(Destino.Comunicado(n)) }
            "evento" -> { _tab.value = Tab.INICIO; _pila.value = listOf(Destino.Calendario) }
            "tarea" -> _tab.value = Tab.TAREAS
            "boletin" -> { _tab.value = Tab.MAS; _pila.value = listOf(Destino.Boletin) }
            "mis_mensajes" -> { _tab.value = Tab.MAS; _pila.value = listOf(Destino.MisMensajes) }
            "buzon" -> { _tab.value = Tab.MAS; _pila.value = listOf(Destino.Buzon) }
            else -> _tab.value = Tab.INICIO
        }
    }
}
