package net.caaguazu.cead.panel.data

import kotlinx.serialization.Serializable

/**
 * Un envío esperando salir.
 *
 * Todo lo que la persona hace sin señal se anota acá y sale cuando vuelve la
 * conexión. [id] es la `Idempotency-Key`: si el pedido llega al servidor pero
 * la respuesta se pierde en el camino, reintentarlo con la misma clave no lo
 * duplica.
 */
@Serializable
data class Envio(
    val id: String,
    /** Qué es, para tratarlo distinto al terminar y para proyectarlo sobre la pantalla. */
    val tipo: String,
    /** Cómo se le explica a la persona en la lista de pendientes. */
    val titulo: String,
    val ruta: String,
    val cuerpo: String? = null,
    val campos: Map<String, String> = emptyMap(),
    val archivos: List<AdjuntoGuardado> = emptyList(),
    /**
     * Si es distinto de null, un envío nuevo con la misma clave reemplaza a
     * éste mientras espera. «Marcar hecha» y «desmarcar» sobre la misma tarea
     * son dos órdenes que se pisan: solo importa la última.
     */
    val clave: String? = null,
    /** Datos sueltos para proyectar el cambio sobre lo que se ve (ver [Proyeccion]). */
    val datos: Map<String, String> = emptyMap(),
    val creado: Long = 0,
    val intentos: Int = 0,
    /** Si no es null, el servidor lo rechazó y reintentar no sirve: se le muestra a la persona. */
    val error: String? = null,
) {
    val fallido: Boolean get() = error != null
}

@Serializable
data class AdjuntoGuardado(
    val campo: String,
    val ruta: String,
    val nombre: String,
    val tipoMime: String,
)

@Serializable
data class ReporteLocal(
    val codigo: String,
    val tipo: String,
    val categoria: String,
    val enviado: Long,
)

/**
 * Lo que la app sabe de sí misma y el servidor no puede decirle.
 *
 * El caso que obliga a esto: un reporte ANÓNIMO. El servidor no guarda quién lo
 * mandó, así que no hay forma de pedirle «mis reportes anónimos». El código
 * (`RPT-XXXXXX`) es la única llave, y el único lugar donde puede quedar para
 * poder seguir el reporte es acá.
 */
@Serializable
data class Locales(
    val reportes: List<ReporteLocal> = emptyList(),
    /** Encuestas anónimas que ya respondí: el servidor no lo sabe, y permite repetir. */
    val encuestasAnonimas: List<Int> = emptyList(),
    /** Recursos bajados para ver sin conexión: id → nombre de archivo en disco. */
    val descargas: Map<String, String> = emptyMap(),
    val ultimoTokenPush: String? = null,
    val pushRegistradoEn: Long = 0,
    val ultimaSync: Long = 0,
)

@Serializable
data class ColaGuardada(val envios: List<Envio> = emptyList())
