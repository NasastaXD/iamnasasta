package net.caaguazu.cead.panel.plataforma

import kotlinx.cinterop.ExperimentalForeignApi
import kotlinx.cinterop.useContents
import platform.CoreGraphics.CGRectMake
import platform.CoreGraphics.CGSizeMake
import platform.Foundation.NSFileManager
import platform.Foundation.NSFileSize
import platform.Foundation.NSNumber
import platform.Foundation.NSURL
import platform.Foundation.NSUUID
import platform.UIKit.UIGraphicsBeginImageContextWithOptions
import platform.UIKit.UIGraphicsEndImageContext
import platform.UIKit.UIGraphicsGetImageFromCurrentImageContext
import platform.UIKit.UIImage
import platform.UIKit.UIImageJPEGRepresentation
import platform.UIKit.UIImagePickerController
import platform.UIKit.UIImagePickerControllerDelegateProtocol
import platform.UIKit.UIImagePickerControllerOriginalImage
import platform.UIKit.UINavigationControllerDelegateProtocol
import platform.UIKit.UIDocumentPickerDelegateProtocol
import platform.UIKit.UIDocumentPickerViewController
import platform.UniformTypeIdentifiers.UTTypeItem
import platform.darwin.NSObject
import kotlin.math.max

/**
 * Elegir una foto de la galería o un archivo del teléfono.
 *
 * En los dos casos se copia a un archivo propio dentro de la app: lo que
 * devuelve el sistema es una ruta temporal que puede desaparecer, y si la
 * persona lo elige sin señal tiene que seguir ahí cuando vuelva la conexión.
 *
 * La cámara no se ofrece a propósito: usarla obliga a pedir un permiso y a
 * explicarlo en la tienda, y la galería no necesita ninguno.
 */
@OptIn(ExperimentalForeignApi::class)
class SelectorIos(private val dirDatos: String) : SelectorArchivos {

    // Los selectores guardan a su delegado con referencia débil: se retiene acá.
    private var delegadoImagen: DelegadoImagen? = null
    private var delegadoDocumento: DelegadoDocumento? = null

    override fun elegir(tipo: TipoArchivo, alElegir: (ArchivoElegido?) -> Unit) {
        enPrincipal {
            val presentador = controladorSuperior()
            if (presentador == null) {
                alElegir(null)
            } else if (tipo == TipoArchivo.IMAGEN) {
                val delegado = DelegadoImagen(dirDatos, alElegir)
                delegadoImagen = delegado
                // Por defecto el selector abre la galería.
                val selector = UIImagePickerController()
                selector.delegate = delegado
                presentador.presentViewController(selector, animated = true, completion = null)
            } else {
                val delegado = DelegadoDocumento(dirDatos, alElegir)
                delegadoDocumento = delegado
                val selector = UIDocumentPickerViewController(forOpeningContentTypes = listOf(UTTypeItem), asCopy = true)
                selector.delegate = delegado
                presentador.presentViewController(selector, animated = true, completion = null)
            }
        }
    }
}

@OptIn(ExperimentalForeignApi::class)
private class DelegadoImagen(
    private val dirDatos: String,
    private val alElegir: (ArchivoElegido?) -> Unit,
) : NSObject(), UIImagePickerControllerDelegateProtocol, UINavigationControllerDelegateProtocol {

    override fun imagePickerController(picker: UIImagePickerController, didFinishPickingMediaWithInfo: Map<Any?, *>) {
        val imagen = didFinishPickingMediaWithInfo[UIImagePickerControllerOriginalImage] as? UIImage
        picker.dismissViewControllerAnimated(true, completion = null)
        alElegir(imagen?.let { guardarImagen(it, dirDatos) })
    }

    override fun imagePickerControllerDidCancel(picker: UIImagePickerController) {
        picker.dismissViewControllerAnimated(true, completion = null)
        alElegir(null)
    }
}

private class DelegadoDocumento(
    private val dirDatos: String,
    private val alElegir: (ArchivoElegido?) -> Unit,
) : NSObject(), UIDocumentPickerDelegateProtocol {

    override fun documentPicker(controller: UIDocumentPickerViewController, didPickDocumentsAtURLs: List<*>) {
        val url = didPickDocumentsAtURLs.firstOrNull() as? NSURL
        alElegir(url?.let { copiarArchivo(it, dirDatos) })
    }

    override fun documentPickerWasCancelled(controller: UIDocumentPickerViewController) {
        alElegir(null)
    }
}

private const val MAX_LADO = 1600.0

/**
 * Achica la foto y la guarda como JPEG. Una foto de cámara pesa 5 a 10 MB y el
 * servidor del colegio rechaza subidas grandes; a 1600 px y calidad 85 pesa
 * unos 300 KB y se ve igual en un celular.
 */
@OptIn(ExperimentalForeignApi::class)
private fun guardarImagen(imagen: UIImage, dirDatos: String): ArchivoElegido? {
    val (ancho, alto) = imagen.size.useContents { width to height }
    val mayor = max(ancho, alto)
    val reducida = if (mayor > MAX_LADO) {
        val w = ancho * MAX_LADO / mayor
        val h = alto * MAX_LADO / mayor
        // Dibujar de nuevo aplica también el giro que anota la cámara: la foto
        // sacada en vertical no llega acostada.
        UIGraphicsBeginImageContextWithOptions(CGSizeMake(w, h), false, 1.0)
        imagen.drawInRect(CGRectMake(0.0, 0.0, w, h))
        val nueva = UIGraphicsGetImageFromCurrentImageContext()
        UIGraphicsEndImageContext()
        nueva ?: imagen
    } else {
        imagen
    }
    val datos = UIImageJPEGRepresentation(reducida, 0.85) ?: return null
    val nombre = "foto-${NSUUID().UUIDString}.jpg"
    val ruta = carpetaDeSubidas(dirDatos) + "/" + nombre
    if (!NSFileManager.defaultManager.createFileAtPath(ruta, contents = datos, attributes = null)) return null
    return ArchivoElegido(ruta, "foto.jpg", "image/jpeg", datos.length.toLong())
}

@OptIn(ExperimentalForeignApi::class)
private fun copiarArchivo(url: NSURL, dirDatos: String): ArchivoElegido? {
    val origen = url.path ?: return null
    val nombre = origen.substringAfterLast('/').ifBlank { "archivo" }
    val destino = carpetaDeSubidas(dirDatos) + "/" + NSUUID().UUIDString + "-" + limpiar(nombre)
    val conAcceso = url.startAccessingSecurityScopedResource()
    val copiado = NSFileManager.defaultManager.copyItemAtPath(origen, toPath = destino, error = null)
    if (conAcceso) url.stopAccessingSecurityScopedResource()
    if (!copiado) return null
    val tamano = (NSFileManager.defaultManager.attributesOfItemAtPath(destino, error = null)?.get(NSFileSize) as? NSNumber)?.longLongValue ?: 0L
    return ArchivoElegido(destino, nombre, tipoMimeDe(nombre), tamano)
}

@OptIn(ExperimentalForeignApi::class)
private fun carpetaDeSubidas(dirDatos: String): String {
    val ruta = "$dirDatos/subidas"
    NSFileManager.defaultManager.createDirectoryAtPath(ruta, withIntermediateDirectories = true, attributes = null, error = null)
    return ruta
}

private fun limpiar(nombre: String): String =
    nombre.map { if (it.isLetterOrDigit() && it.code < 128 || it in "._-") it else '_' }.joinToString("").takeLast(80)

/** El tipo de un archivo por su extensión. Lo que no se reconoce viaja como binario genérico. */
internal fun tipoMimeDe(nombre: String): String = when (nombre.substringAfterLast('.', "").lowercase()) {
    "pdf" -> "application/pdf"
    "jpg", "jpeg" -> "image/jpeg"
    "png" -> "image/png"
    "gif" -> "image/gif"
    "webp" -> "image/webp"
    "doc" -> "application/msword"
    "docx" -> "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
    "xls" -> "application/vnd.ms-excel"
    "xlsx" -> "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    "ppt" -> "application/vnd.ms-powerpoint"
    "pptx" -> "application/vnd.openxmlformats-officedocument.presentationml.presentation"
    "txt" -> "text/plain"
    "zip" -> "application/zip"
    else -> "application/octet-stream"
}
