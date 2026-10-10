package net.caaguazu.cead.panel

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.core.content.ContextCompat
import net.caaguazu.cead.panel.plataforma.TipoArchivo
import net.caaguazu.cead.panel.ui.App

/**
 * La única pantalla de Android: toda la app se dibuja dentro de [App].
 *
 * Acá solo vive lo que necesita una actividad abierta: el botón «atrás», el
 * selector de archivos, el permiso de avisos y el toque sobre un aviso.
 */
class MainActivity : ComponentActivity() {

    private val app get() = application as CeadApp

    /** A quién le toca la respuesta del selector: se registra antes de que la actividad arranque. */
    private var alElegir: ((Uri?) -> Unit)? = null

    private val selector = registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        alElegir?.invoke(uri)
        alElegir = null
    }

    private val permisoDeAvisos = registerForActivityResult(ActivityResultContracts.RequestPermission()) { }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        app.plataforma.selector.abrir = { tipo, respuesta ->
            alElegir = respuesta
            selector.launch(if (tipo == TipoArchivo.IMAGEN) "image/*" else "*/*")
        }
        app.plataforma.push.solicitudDePermiso = { pedirPermisoDeAvisos() }

        // Girar el teléfono vuelve a crear la actividad con el mismo intent: el
        // aviso ya se abrió la primera vez y no hay que volver a llevar ahí.
        if (savedInstanceState == null) abrirAviso(intent)

        setContent {
            val nav = app.nav
            // Se leen para que el botón «atrás» se reevalúe cuando cambia la pantalla.
            val pila by nav.pila.collectAsState()
            val tab by nav.tab.collectAsState()
            BackHandler(enabled = pila.isNotEmpty() || tab != net.caaguazu.cead.panel.ui.Tab.INICIO) { nav.volver() }

            App(app.plataforma, app.repo, nav)
        }
    }

    /** Cada vez que la app se ve de nuevo: si hace rato que no sincroniza, lo hace. */
    override fun onStart() {
        super.onStart()
        app.repo.alPrimerPlano()
    }

    /** Con la app ya abierta, tocar un aviso llega acá y no a [onCreate]. */
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        abrirAviso(intent)
    }

    private fun abrirAviso(intent: Intent?) {
        val tipo = intent?.getStringExtra("tipo") ?: return
        app.nav.abrirDesdeAviso(tipo, intent.getStringExtra("id"))
    }

    private fun pedirPermisoDeAvisos() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        val ya = ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
        if (!ya) permisoDeAvisos.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    override fun onDestroy() {
        // La actividad se va; la plataforma sigue viva. Que no quede apuntando a una pantalla muerta.
        if (isFinishing) {
            app.plataforma.selector.abrir = null
            app.plataforma.push.solicitudDePermiso = null
        }
        super.onDestroy()
    }
}
