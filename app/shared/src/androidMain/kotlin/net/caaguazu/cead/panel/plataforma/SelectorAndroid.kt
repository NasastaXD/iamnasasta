package net.caaguazu.cead.panel.plataforma

import android.app.Application
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.ExifInterface
import android.net.Uri
import android.provider.OpenableColumns
import java.io.File
import java.util.UUID
import kotlin.concurrent.thread
import kotlin.math.max

/**
 * Elegir una foto o un archivo.
 *
 * El selector del sistema contesta con un `content://`, que ningún otro código
 * de la app sabe leer. Se copia a un archivo propio (en `filesDir`, no en la
 * caché: si la persona lo elige sin señal, tiene que seguir ahí cuando vuelva
 * la conexión, y el sistema limpia la caché cuando le falta lugar).
 *
 * Las fotos se achican antes de guardarse. Una foto de cámara pesa 5 a 10 MB y
 * el servidor del colegio rechaza subidas grandes; a 1600 px y calidad 85 pesa
 * unos 300 KB y se ve igual en un celular.
 */
class SelectorAndroid(private val app: Application) : SelectorArchivos {

    /** Lo enchufa `MainActivity`: abre el selector y devuelve el `Uri` elegido (o null). */
    var abrir: ((TipoArchivo, (Uri?) -> Unit) -> Unit)? = null

    override fun elegir(tipo: TipoArchivo, alElegir: (ArchivoElegido?) -> Unit) {
        val lanzador = abrir
        if (lanzador == null) {
            alElegir(null)
            return
        }
        lanzador(tipo) { uri ->
            if (uri == null) {
                alElegir(null)
            } else {
                // Copiar y achicar puede tardar: no en el hilo de la pantalla.
                thread(name = "cead-selector") { alElegir(copiar(uri, tipo)) }
            }
        }
    }

    private fun copiar(uri: Uri, tipo: TipoArchivo): ArchivoElegido? {
        return try {
            val cr = app.contentResolver
            val nombre = nombreDe(uri)
            val mime = cr.getType(uri) ?: "application/octet-stream"
            val dir = File(app.filesDir, "subidas").apply { mkdirs() }
            val crudo = File(dir, "${UUID.randomUUID()}-${limpiar(nombre)}")
            cr.openInputStream(uri)?.use { entrada -> crudo.outputStream().use { entrada.copyTo(it) } } ?: return null

            if (tipo == TipoArchivo.IMAGEN && mime.startsWith("image/")) {
                val reducida = File(dir, "${UUID.randomUUID()}.jpg")
                if (reducir(crudo, reducida)) {
                    crudo.delete()
                    return ArchivoElegido(reducida.absolutePath, nombre.substringBeforeLast('.') + ".jpg", "image/jpeg", reducida.length())
                }
            }
            ArchivoElegido(crudo.absolutePath, nombre, mime, crudo.length())
        } catch (e: Exception) {
            null
        }
    }

    private fun nombreDe(uri: Uri): String {
        app.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c ->
            if (c.moveToFirst()) return c.getString(0) ?: "archivo"
        }
        return uri.lastPathSegment ?: "archivo"
    }

    private fun limpiar(nombre: String) = nombre.replace(Regex("[^A-Za-z0-9._-]"), "_").takeLast(80)

    private fun reducir(origen: File, destino: File, maxLado: Int = 1600): Boolean {
        val medidas = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(origen.path, medidas)
        if (medidas.outWidth <= 0 || medidas.outHeight <= 0) return false

        // Se decodifica ya a una fracción del tamaño: una foto de 48 megapíxeles
        // entera en memoria revienta teléfonos chicos.
        var muestra = 1
        while (max(medidas.outWidth, medidas.outHeight) / muestra > maxLado * 2) muestra *= 2
        val bmp = BitmapFactory.decodeFile(origen.path, BitmapFactory.Options().apply { inSampleSize = muestra }) ?: return false

        val m = Matrix()
        val escala = maxLado.toFloat() / max(bmp.width, bmp.height)
        if (escala < 1f) m.postScale(escala, escala)
        // La cámara guarda la foto «de costado» y anota en el EXIF cómo girarla.
        // Al volver a comprimir esa anotación se pierde: sin esto, las fotos
        // sacadas en vertical llegan acostadas.
        when (ExifInterface(origen.path).getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)) {
            ExifInterface.ORIENTATION_ROTATE_90 -> m.postRotate(90f)
            ExifInterface.ORIENTATION_ROTATE_180 -> m.postRotate(180f)
            ExifInterface.ORIENTATION_ROTATE_270 -> m.postRotate(270f)
        }
        val final = Bitmap.createBitmap(bmp, 0, 0, bmp.width, bmp.height, m, true)
        destino.outputStream().use { final.compress(Bitmap.CompressFormat.JPEG, 85, it) }
        return true
    }
}
