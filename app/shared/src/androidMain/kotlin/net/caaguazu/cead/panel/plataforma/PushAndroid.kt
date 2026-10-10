package net.caaguazu.cead.panel.plataforma

import android.app.Application
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import kotlin.coroutines.resume
import kotlin.coroutines.suspendCoroutine

/**
 * Avisos al teléfono en Android, con Firebase Cloud Messaging.
 *
 * Sin el archivo `google-services.json` en `androidApp/` Firebase nunca se
 * inicializa, y [disponible] da falso: la app sigue andando, sin timbre. Ver
 * docs/AVISOS-PUSH.md.
 */
class PushAndroid(private val app: Application) : Push {

    private var oyente: ((String) -> Unit)? = null

    /** Lo enchufa `MainActivity`: pedir el permiso necesita una pantalla abierta. */
    var solicitudDePermiso: (() -> Unit)? = null

    override val disponible: Boolean
        get() = try {
            FirebaseApp.getApps(app).isNotEmpty()
        } catch (e: Throwable) {
            false
        }

    override fun pedirPermiso() {
        solicitudDePermiso?.invoke()
    }

    override suspend fun token(): String? {
        if (!disponible) return null
        return suspendCoroutine { c ->
            try {
                FirebaseMessaging.getInstance().token.addOnCompleteListener { t ->
                    c.resume(if (t.isSuccessful) t.result else null)
                }
            } catch (e: Throwable) {
                c.resume(null)
            }
        }
    }

    override fun alCambiarElToken(oyente: (String) -> Unit) {
        this.oyente = oyente
    }

    /** Lo llama el servicio de Firebase cuando el sistema entrega un token nuevo. */
    fun tokenNuevo(token: String) {
        oyente?.invoke(token)
    }
}
