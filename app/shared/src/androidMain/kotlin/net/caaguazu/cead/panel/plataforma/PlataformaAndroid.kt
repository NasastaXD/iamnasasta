package net.caaguazu.cead.panel.plataforma

import android.app.Application
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.core.content.FileProvider
import java.io.File

/**
 * Android: lo que el resto de la app no puede saber.
 *
 * Se crea una sola vez, en `CeadApp`, y vive tanto como el proceso. Las partes
 * que necesitan una pantalla abierta (elegir un archivo, pedir permiso) quedan
 * esperando a que `MainActivity` les enchufe cómo hacerlo.
 */
class PlataformaAndroid(private val app: Application) : Plataforma {

    override val nombre = "android"
    override val dirDatos: String get() = app.filesDir.absolutePath
    override val dirCache: String get() = app.cacheDir.absolutePath

    override val almacenSeguro: AlmacenSeguro = AlmacenSeguroAndroid(app)
    override val selector = SelectorAndroid(app)
    override val push = PushAndroid(app)

    override fun nombreDelAparato(): String {
        val fabricante = Build.MANUFACTURER.orEmpty().replaceFirstChar { it.uppercase() }
        val modelo = Build.MODEL.orEmpty()
        return if (modelo.startsWith(fabricante, ignoreCase = true)) modelo else "$fabricante $modelo".trim()
    }

    override fun abrirUrl(url: String) {
        // Solo enlaces web: un `intent:` o `file:` colado en un texto no se abre.
        if (!url.startsWith("http://") && !url.startsWith("https://")) return
        lanzar(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
    }

    override fun abrirArchivo(ruta: String, tipoMime: String?) {
        val archivo = File(ruta)
        if (!archivo.exists()) return
        val uri = FileProvider.getUriForFile(app, "${app.packageName}.archivos", archivo)
        lanzar(
            Intent(Intent.ACTION_VIEW)
                .setDataAndType(uri, tipoMime ?: "*/*")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION),
        )
    }

    override fun copiar(texto: String) {
        val cm = app.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
        cm.setPrimaryClip(ClipData.newPlainText("CEAD", texto))
    }

    override fun compartir(texto: String) {
        val envio = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, texto)
        lanzar(Intent.createChooser(envio, null))
    }

    private fun lanzar(intent: Intent) {
        try {
            app.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        } catch (e: Exception) {
            // Sin una app que lo abra (un teléfono sin visor de PDF): no es un error de la app.
        }
    }
}
