package net.caaguazu.cead.panel.plataforma

import platform.UIKit.UIApplication
import platform.UIKit.UIViewController
import platform.darwin.dispatch_async
import platform.darwin.dispatch_get_main_queue

/**
 * UIKit solo se toca desde el hilo principal. Siempre se pasa por la cola
 * principal, aunque ya estemos en ella: corre en el próximo ciclo, imperceptible,
 * y no hace falta distinguir de qué hilo se llamó.
 */
internal fun enPrincipal(bloque: () -> Unit) {
    dispatch_async(dispatch_get_main_queue(), bloque)
}

/**
 * La pantalla que está arriba de todo: sobre ella se muestran el selector de
 * archivos, el visor y el menú de compartir.
 *
 * `keyWindow` está marcado como viejo desde iOS 13 (pensado para varias ventanas
 * en iPad), pero esta app es solo de iPhone y tiene una única ventana.
 */
internal fun controladorSuperior(): UIViewController? {
    var actual = UIApplication.sharedApplication.keyWindow?.rootViewController
    while (true) {
        val encima = actual?.presentedViewController ?: break
        actual = encima
    }
    return actual
}
