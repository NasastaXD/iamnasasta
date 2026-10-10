package net.caaguazu.cead.panel.plataforma

import platform.UIKit.UIApplication
import platform.UIKit.registerForRemoteNotifications
import platform.UserNotifications.UNAuthorizationOptionAlert
import platform.UserNotifications.UNAuthorizationOptionBadge
import platform.UserNotifications.UNAuthorizationOptionSound
import platform.UserNotifications.UNUserNotificationCenter

/**
 * El puente entre Firebase (que vive del lado de Swift, porque su SDK es de
 * Swift) y esta app.
 *
 * Swift lo avisa de tres cosas: que Firebase quedó configurado
 * ([disponible]), que hay un token ([tokenNuevo]) y que la persona tocó un
 * aviso ([avisoTocado]). Del otro lado, la app lo consulta como a cualquier
 * otro [Push].
 */
object PuenteAvisosIos {

    /** Swift lo pone en true si encontró `GoogleService-Info.plist` y configuró Firebase. */
    var disponible: Boolean = false

    var token: String? = null
        private set

    internal var alCambiarToken: ((String) -> Unit)? = null
    private var alTocar: ((String?, String?) -> Unit)? = null
    private var tocadoAntes: Pair<String?, String?>? = null

    /** Swift: Firebase entregó el token de este teléfono (o uno nuevo). */
    fun tokenNuevo(nuevo: String) {
        token = nuevo
        alCambiarToken?.invoke(nuevo)
    }

    /** Swift: la persona tocó una notificación. `tipo` e `id` son los datos que manda el servidor. */
    fun avisoTocado(tipo: String?, id: String?) {
        val abrir = alTocar
        if (abrir != null) abrir(tipo, id) else tocadoAntes = tipo to id
    }

    /**
     * La app dice qué hacer cuando tocan un aviso. Si el toque llegó antes de
     * que la app terminara de arrancar (el aviso fue lo que la abrió), se
     * entrega recién ahora.
     */
    internal fun alTocarAviso(abrir: (String?, String?) -> Unit) {
        alTocar = abrir
        tocadoAntes?.let { (tipo, id) ->
            tocadoAntes = null
            abrir(tipo, id)
        }
    }
}

class PushIos : Push {

    override val disponible: Boolean get() = PuenteAvisosIos.disponible

    override fun pedirPermiso() {
        // Sin Firebase no va a llegar ningún aviso: no tiene sentido pedir permiso.
        if (!disponible) return
        val opciones = UNAuthorizationOptionAlert or UNAuthorizationOptionBadge or UNAuthorizationOptionSound
        UNUserNotificationCenter.currentNotificationCenter().requestAuthorizationWithOptions(opciones) { concedido, _ ->
            if (concedido) enPrincipal { UIApplication.sharedApplication.registerForRemoteNotifications() }
        }
    }

    override suspend fun token(): String? = PuenteAvisosIos.token

    override fun alCambiarElToken(oyente: (String) -> Unit) {
        PuenteAvisosIos.alCambiarToken = oyente
    }
}
