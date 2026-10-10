package net.caaguazu.cead.panel.plataforma

import kotlinx.cinterop.ExperimentalForeignApi
import platform.Foundation.NSApplicationSupportDirectory
import platform.Foundation.NSCachesDirectory
import platform.Foundation.NSFileManager
import platform.Foundation.NSSearchPathDirectory
import platform.Foundation.NSSearchPathForDirectoriesInDomains
import platform.Foundation.NSTemporaryDirectory
import platform.Foundation.NSURL
import platform.Foundation.NSUserDomainMask
import platform.UIKit.UIActivityViewController
import platform.UIKit.UIApplication
import platform.UIKit.UIDevice
import platform.UIKit.UIPasteboard

/**
 * iOS: lo que el resto de la app no puede saber.
 *
 * Este archivo y los de su carpeta se escribieron sin poder compilarlos (hace
 * falta una Mac). Si el primer intento de compilar tira un error de tipos, es
 * acá donde hay que mirar: es la única parte de la app que habla con UIKit.
 */
@OptIn(ExperimentalForeignApi::class)
class PlataformaIos : Plataforma {

    override val nombre = "ios"
    override val dirDatos: String = directorio(NSApplicationSupportDirectory)
    override val dirCache: String = directorio(NSCachesDirectory)

    override val almacenSeguro: AlmacenSeguro = AlmacenSeguroIos()
    override val selector: SelectorArchivos = SelectorIos(dirDatos)
    override val push: Push = PushIos()

    override fun nombreDelAparato(): String {
        // `UIDevice.name` desde iOS 16 devuelve solo «iPhone»: no distingue un aparato de otro.
        val aparato = UIDevice.currentDevice
        return "${aparato.model} (iOS ${aparato.systemVersion})"
    }

    override fun abrirUrl(url: String) {
        // Solo enlaces web: un esquema raro colado en un texto no se abre.
        if (!url.startsWith("http://") && !url.startsWith("https://")) return
        val destino = NSURL.URLWithString(url) ?: return
        enPrincipal {
            UIApplication.sharedApplication.openURL(destino, options = emptyMap<Any?, Any>(), completionHandler = null)
        }
    }

    override fun abrirArchivo(ruta: String, tipoMime: String?) {
        if (!NSFileManager.defaultManager.fileExistsAtPath(ruta)) return
        enPrincipal { VisorIos.mostrar(ruta) }
    }

    override fun copiar(texto: String) {
        UIPasteboard.generalPasteboard.string = texto
    }

    override fun compartir(texto: String) {
        enPrincipal {
            val hoja = UIActivityViewController(activityItems = listOf(texto), applicationActivities = null)
            controladorSuperior()?.presentViewController(hoja, animated = true, completion = null)
        }
    }

    private fun directorio(tipo: NSSearchPathDirectory): String {
        val ruta = (NSSearchPathForDirectoriesInDomains(tipo, NSUserDomainMask, true).firstOrNull() as? String)
            ?: NSTemporaryDirectory()
        // Application Support no existe hasta que alguien lo crea.
        NSFileManager.defaultManager.createDirectoryAtPath(ruta, withIntermediateDirectories = true, attributes = null, error = null)
        return ruta.trimEnd('/')
    }
}
