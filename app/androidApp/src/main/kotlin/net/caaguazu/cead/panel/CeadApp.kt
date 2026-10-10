package net.caaguazu.cead.panel

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import net.caaguazu.cead.panel.data.Repositorio
import net.caaguazu.cead.panel.plataforma.PlataformaAndroid
import net.caaguazu.cead.panel.ui.Navegacion

/**
 * El proceso de la app.
 *
 * El repositorio vive acá y no en la pantalla: girar el teléfono destruye y
 * vuelve a crear la actividad, y no hay que perder ni la sesión ni lo que está
 * esperando salir por eso.
 */
class CeadApp : Application() {

    lateinit var plataforma: PlataformaAndroid
        private set
    lateinit var repo: Repositorio
        private set

    /** Dónde está parada la persona. Vive con el proceso para que girar el teléfono no la mande al inicio. */
    val nav = Navegacion()

    override fun onCreate() {
        super.onCreate()
        plataforma = PlataformaAndroid(this)
        repo = Repositorio(plataforma, CoroutineScope(SupervisorJob() + Dispatchers.Default))
        crearCanalDeAvisos()
    }

    private fun crearCanalDeAvisos() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val canal = NotificationChannel(CANAL_AVISOS, "Avisos del colegio", NotificationManager.IMPORTANCE_HIGH).apply {
            description = "Comunicados, eventos, tareas y respuestas del colegio."
        }
        getSystemService(NotificationManager::class.java).createNotificationChannel(canal)
    }

    companion object {
        const val CANAL_AVISOS = "cead_avisos"
    }
}
