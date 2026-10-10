package net.caaguazu.cead.panel.plataforma

/**
 * Todo lo que la app necesita del sistema operativo, en una sola interfaz.
 *
 * Android e iOS la implementan cada uno; el resto de la app no sabe en cuál
 * corre. Se pasa por parámetro en vez de usar `expect/actual` por cada cosa:
 * así lo que hace falta de cada plataforma se lee de un vistazo, y las pruebas
 * ponen una implementación de mentira sin tocar nada más.
 *
 * Lo que está acá es TODO lo que no se puede escribir una sola vez. Cuanto más
 * corta sea esta lista, menos código hay que compilar a ciegas en el otro
 * sistema.
 */
interface Plataforma {

    /** "android" o "ios": es lo que se le dice al servidor al registrar el teléfono. */
    val nombre: String

    /** Un directorio donde la app puede escribir y que nadie más lee. */
    val dirDatos: String

    /** Un directorio para lo descargable (puede borrarlo el sistema si falta lugar). */
    val dirCache: String

    /** Dónde se guarda el token: el llavero de iOS, el almacén cifrado de Android. */
    val almacenSeguro: AlmacenSeguro

    /** Cómo se llama este aparato ("Moto G54"), para la lista de sesiones abiertas. */
    fun nombreDelAparato(): String

    /** Abre un enlace en el navegador. */
    fun abrirUrl(url: String)

    /** Abre un archivo ya descargado con la app que corresponda (visor de PDF, etc.). */
    fun abrirArchivo(ruta: String, tipoMime: String?)

    /** Copia texto al portapapeles. */
    fun copiar(texto: String)

    /** Abre el menú de compartir del sistema. */
    fun compartir(texto: String)

    /** Elegir una foto o un archivo del teléfono. */
    val selector: SelectorArchivos

    /** Los avisos al teléfono. */
    val push: Push
}

/** Un almacén de claves y valores cifrado por el sistema. */
interface AlmacenSeguro {
    fun leer(clave: String): String?
    fun guardar(clave: String, valor: String)
    fun borrar(clave: String)
}

/** Un archivo que la persona eligió, ya copiado a un lugar propio de la app. */
class ArchivoElegido(
    val ruta: String,
    val nombre: String,
    val tipoMime: String,
    val tamano: Long,
)

enum class TipoArchivo { IMAGEN, CUALQUIERA }

interface SelectorArchivos {
    /**
     * Abre el selector del sistema. Llama a [alElegir] con el archivo, o con
     * null si la persona cancela. No devuelve nada: en los dos sistemas el
     * selector es una pantalla aparte que contesta más tarde.
     */
    fun elegir(tipo: TipoArchivo, alElegir: (ArchivoElegido?) -> Unit)
}

interface Push {
    /** ¿Esta instalación tiene Firebase configurado? Sin eso no hay avisos, y no es un error. */
    val disponible: Boolean

    /** Pide permiso para mostrar notificaciones (iOS y Android 13+). */
    fun pedirPermiso()

    /**
     * El token de este teléfono, o null si no hay (sin Firebase, sin permiso, o
     * sin red). Se vuelve a pedir en cada arranque: cambia cuando el sistema
     * quiere.
     */
    suspend fun token(): String?

    /** Para enterarse cuando el sistema cambia el token. */
    fun alCambiarElToken(oyente: (String) -> Unit)
}
