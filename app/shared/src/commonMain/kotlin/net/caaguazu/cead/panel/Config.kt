package net.caaguazu.cead.panel

/**
 * Lo que cambia entre entornos, en un solo lugar.
 *
 * Vive en código común y no en el `BuildConfig` de Android para que iOS lea el
 * mismo valor: dos copias de la URL del sitio son dos lugares donde una se
 * queda apuntando al sitio equivocado.
 */
object Config {
    /** El sitio del colegio, de donde cuelga la API. */
    const val SITIO = "https://cead.caaguazu.net"

    const val VERSION = "0.2.0"

    /** Cuántos comunicados/eventos se muestran como mucho en el inicio. */
    const val ITEMS_INICIO = 3
}
