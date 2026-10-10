package net.caaguazu.cead.panel.plataforma

import com.russhwolf.settings.ExperimentalSettingsImplementation
import com.russhwolf.settings.KeychainSettings
import platform.Foundation.NSUserDefaults

/**
 * El token en el llavero de iOS, cifrado por el sistema.
 *
 * El llavero tiene una rareza: **sobrevive a desinstalar la app**. Quien
 * borra la app y la vuelve a instalar (o la instala otra persona en un
 * teléfono usado) encontraría la sesión de la anterior. Por eso, la primera
 * vez que corre una instalación nueva se vacía el llavero: `NSUserDefaults`
 * sí se borra al desinstalar, y sirve de marca para saber que es una
 * instalación nueva.
 */
@OptIn(ExperimentalSettingsImplementation::class)
class AlmacenSeguroIos : AlmacenSeguro {

    private val llavero = KeychainSettings(service = SERVICIO)

    init {
        val marcas = NSUserDefaults.standardUserDefaults
        if (!marcas.boolForKey(MARCA)) {
            llavero.clear()
            marcas.setBool(true, forKey = MARCA)
        }
    }

    override fun leer(clave: String): String? = llavero.getStringOrNull(clave)

    override fun guardar(clave: String, valor: String) {
        llavero.putString(clave, valor)
    }

    override fun borrar(clave: String) {
        llavero.remove(clave)
    }

    private companion object {
        const val SERVICIO = "net.caaguazu.cead.panel"
        const val MARCA = "cead_instalacion_iniciada"
    }
}
