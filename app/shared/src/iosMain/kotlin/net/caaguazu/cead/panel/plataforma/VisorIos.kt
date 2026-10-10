package net.caaguazu.cead.panel.plataforma

import platform.Foundation.NSURL
import platform.UIKit.UIDocumentInteractionController
import platform.UIKit.UIDocumentInteractionControllerDelegateProtocol
import platform.UIKit.UIViewController
import platform.darwin.NSObject

/**
 * Abre un archivo ya descargado (un PDF, una imagen, un documento) con el
 * visor del sistema, que además trae el botón de compartir y de guardar.
 */
internal object VisorIos {

    // El controlador guarda a su delegado con referencia débil: si no lo
    // retenemos acá, se libera enseguida y el visor no llega a abrirse.
    private var delegado: DelegadoDelVisor? = null
    private var visor: UIDocumentInteractionController? = null

    fun mostrar(ruta: String) {
        val presentador = controladorSuperior() ?: return
        val nuevoDelegado = DelegadoDelVisor(presentador)
        val nuevoVisor = UIDocumentInteractionController.interactionControllerWithURL(NSURL.fileURLWithPath(ruta))
        nuevoVisor.delegate = nuevoDelegado
        delegado = nuevoDelegado
        visor = nuevoVisor
        nuevoVisor.presentPreviewAnimated(true)
    }
}

private class DelegadoDelVisor(private val presentador: UIViewController) : NSObject(), UIDocumentInteractionControllerDelegateProtocol {
    override fun documentInteractionControllerViewControllerForPreview(controller: UIDocumentInteractionController): UIViewController = presentador
}
