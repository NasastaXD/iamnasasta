package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.LinkAnnotation
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextLinkStyles
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.withLink
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage

/** Un bloque del texto de un comunicado, ya sin etiquetas. */
sealed interface BloqueHtml {
    data class Parrafo(val texto: AnnotatedString) : BloqueHtml
    data class Encabezado(val nivel: Int, val texto: AnnotatedString) : BloqueHtml
    data class Item(val marca: String, val nivel: Int, val texto: AnnotatedString) : BloqueHtml
    data class Cita(val texto: AnnotatedString) : BloqueHtml
    data class Imagen(val url: String, val descripcion: String) : BloqueHtml
    data object Linea : BloqueHtml
}

/**
 * Del HTML que escribe WordPress a bloques que Compose sabe dibujar.
 *
 * Compose no tiene un visor de HTML, y meter un navegador adentro de cada
 * comunicado sería lento y no se vería como el resto de la app. Los
 * comunicados usan un puñado de etiquetas (párrafos, negritas, listas,
 * enlaces, imágenes); se entiende ese puñado y lo demás se muestra como
 * texto, que es lo que preferimos a que desaparezca contenido.
 *
 * No intenta ser un parser completo: tolera HTML mal cerrado y nunca falla.
 */
object HtmlComunicado {

    private data class Tramo(val texto: String, val negrita: Boolean, val cursiva: Boolean, val subrayado: Boolean, val codigo: Boolean, val url: String?)

    private class Lista(val ordenada: Boolean, var contador: Int = 0)

    private val ETIQUETA = Regex("""<(/?)([a-zA-Z][a-zA-Z0-9]*)([^>]*)>""")
    private val QUITAR = Regex("""(?is)<(script|style|head|iframe)\b.*?</\1\s*>|<!--.*?-->""")
    private val HREF = Regex("""(?i)\bhref\s*=\s*("([^"]*)"|'([^']*)')""")
    private val SRC = Regex("""(?i)\bsrc\s*=\s*("([^"]*)"|'([^']*)')""")
    private val ALT = Regex("""(?i)\balt\s*=\s*("([^"]*)"|'([^']*)')""")

    private val BLOQUES = setOf("p", "div", "section", "article", "figure", "figcaption", "tr", "table", "tbody", "thead", "pre", "header", "footer", "dl", "dt", "dd")

    fun parsear(html: String): List<BloqueHtml> {
        val limpio = QUITAR.replace(html, "")
        val salida = mutableListOf<BloqueHtml>()
        var tramos = mutableListOf<Tramo>()

        var negrita = 0
        var cursiva = 0
        var subrayado = 0
        var codigo = 0
        var url: String? = null
        val listas = ArrayDeque<Lista>()
        var citas = 0
        var encabezado = 0
        var marcaPendiente: String? = null

        fun construir(): AnnotatedString {
            // Los espacios de los bordes no cuentan: HTML los ignora.
            val lista = tramos.toMutableList()
            while (lista.isNotEmpty() && lista.first().texto.isBlank()) lista.removeAt(0)
            while (lista.isNotEmpty() && lista.last().texto.isBlank()) lista.removeAt(lista.lastIndex)
            if (lista.isNotEmpty()) lista[0] = lista[0].copy(texto = lista[0].texto.trimStart())
            if (lista.isNotEmpty()) lista[lista.lastIndex] = lista.last().copy(texto = lista.last().texto.trimEnd())

            return buildAnnotatedString {
                lista.forEach { t ->
                    val estilo = SpanStyle(
                        fontWeight = if (t.negrita) FontWeight.Bold else null,
                        fontStyle = if (t.cursiva) FontStyle.Italic else null,
                        textDecoration = if (t.subrayado) TextDecoration.Underline else null,
                        fontFamily = if (t.codigo) FontFamily.Monospace else null,
                    )
                    if (t.url != null) {
                        withLink(
                            LinkAnnotation.Url(
                                t.url,
                                TextLinkStyles(SpanStyle(color = Color(0xFF1A73B8), textDecoration = TextDecoration.Underline)),
                            ),
                        ) { withStyle(estilo) { append(t.texto) } }
                    } else {
                        withStyle(estilo) { append(t.texto) }
                    }
                }
            }
        }

        fun cerrar() {
            val txt = construir()
            tramos = mutableListOf()
            if (txt.text.isBlank()) {
                marcaPendiente = null
                return
            }
            salida += when {
                encabezado > 0 -> BloqueHtml.Encabezado(encabezado, txt)
                listas.isNotEmpty() -> BloqueHtml.Item(marcaPendiente ?: "•", listas.size - 1, txt)
                citas > 0 -> BloqueHtml.Cita(txt)
                else -> BloqueHtml.Parrafo(txt)
            }
            marcaPendiente = null
        }

        fun texto(t: String) {
            // El HTML colapsa cualquier racha de espacios y saltos en uno solo.
            val colapsado = decodificar(t).replace(Regex("""\s+"""), " ")
            if (colapsado.isEmpty()) return
            tramos += Tramo(colapsado, negrita > 0, cursiva > 0, subrayado > 0, codigo > 0, url)
        }

        var pos = 0
        for (m in ETIQUETA.findAll(limpio)) {
            if (m.range.first > pos) texto(limpio.substring(pos, m.range.first))
            pos = m.range.last + 1

            val cierra = m.groupValues[1] == "/"
            val nombre = m.groupValues[2].lowercase()
            val attrs = m.groupValues[3]

            when (nombre) {
                "strong", "b" -> negrita = (negrita + if (cierra) -1 else 1).coerceAtLeast(0)
                "em", "i" -> cursiva = (cursiva + if (cierra) -1 else 1).coerceAtLeast(0)
                "u" -> subrayado = (subrayado + if (cierra) -1 else 1).coerceAtLeast(0)
                "code", "kbd" -> codigo = (codigo + if (cierra) -1 else 1).coerceAtLeast(0)
                "a" -> url = if (cierra) null else HREF.find(attrs)?.let { decodificar(it.groupValues[2].ifEmpty { it.groupValues[3] }) }
                    ?.takeIf { it.startsWith("http://") || it.startsWith("https://") || it.startsWith("mailto:") || it.startsWith("tel:") }
                "br" -> {
                    // Un salto de línea dentro de un párrafo: se parte el bloque en dos.
                    cerrar()
                }
                "hr" -> { cerrar(); salida += BloqueHtml.Linea }
                "img" -> {
                    cerrar()
                    val src = SRC.find(attrs)?.let { decodificar(it.groupValues[2].ifEmpty { it.groupValues[3] }) }
                    val alt = ALT.find(attrs)?.let { decodificar(it.groupValues[2].ifEmpty { it.groupValues[3] }) } ?: ""
                    if (!src.isNullOrBlank() && (src.startsWith("http://") || src.startsWith("https://"))) salida += BloqueHtml.Imagen(src, alt)
                }
                "ul", "ol" -> {
                    cerrar()
                    if (cierra) { if (listas.isNotEmpty()) listas.removeLast() } else listas.addLast(Lista(nombre == "ol"))
                }
                "li" -> {
                    cerrar()
                    if (!cierra) {
                        val l = listas.lastOrNull()
                        marcaPendiente = if (l?.ordenada == true) { l.contador++; "${l.contador}." } else "•"
                    }
                }
                "blockquote" -> { cerrar(); citas = (citas + if (cierra) -1 else 1).coerceAtLeast(0) }
                "h1", "h2", "h3", "h4", "h5", "h6" -> { cerrar(); encabezado = if (cierra) 0 else nombre.substring(1).toInt() }
                "td", "th" -> if (cierra) texto("  ") // las celdas de una tabla quedan separadas en la misma línea
                in BLOQUES -> cerrar()
                else -> Unit // span, font, etc.: no cambian nada
            }
        }
        if (pos < limpio.length) texto(limpio.substring(pos))
        cerrar()
        return salida
    }

    private val ENTIDADES = mapOf(
        "amp" to "&", "lt" to "<", "gt" to ">", "quot" to "\"", "apos" to "'", "nbsp" to " ",
        "hellip" to "…", "ndash" to "–", "mdash" to "—", "lsquo" to "‘", "rsquo" to "’", "ldquo" to "“", "rdquo" to "”",
        "laquo" to "«", "raquo" to "»", "iexcl" to "¡", "iquest" to "¿", "aacute" to "á", "eacute" to "é", "iacute" to "í",
        "oacute" to "ó", "uacute" to "ú", "ntilde" to "ñ", "uuml" to "ü", "Aacute" to "Á", "Eacute" to "É", "Iacute" to "Í",
        "Oacute" to "Ó", "Uacute" to "Ú", "Ntilde" to "Ñ", "ordm" to "º", "ordf" to "ª", "deg" to "°", "euro" to "€", "bull" to "•",
        "middot" to "·", "copy" to "©",
    )

    /** `&amp;`, `&#8217;`, `&#x1F600;`: lo que WordPress mete en el texto. */
    fun decodificar(s: String): String {
        if ('&' !in s) return s
        return Regex("""&(#x[0-9a-fA-F]+|#[0-9]+|[a-zA-Z]+);""").replace(s) { m ->
            val e = m.groupValues[1]
            when {
                e.startsWith("#x") || e.startsWith("#X") -> e.substring(2).toIntOrNull(16)?.let { aTexto(it) } ?: m.value
                e.startsWith("#") -> e.substring(1).toIntOrNull()?.let { aTexto(it) } ?: m.value
                else -> ENTIDADES[e] ?: m.value
            }
        }
    }

    private fun aTexto(cp: Int): String? {
        if (cp <= 0 || cp > 0x10FFFF || cp in 0xD800..0xDFFF) return null
        if (cp < 0x10000) return cp.toChar().toString()
        val c = cp - 0x10000
        return charArrayOf((0xD800 + (c shr 10)).toChar(), (0xDC00 + (c and 0x3FF)).toChar()).concatToString()
    }

    /** El texto sin formato, para vistas previas y para buscar. */
    fun aTextoPlano(html: String): String =
        parsear(html).joinToString("\n") {
            when (it) {
                is BloqueHtml.Parrafo -> it.texto.text
                is BloqueHtml.Encabezado -> it.texto.text
                is BloqueHtml.Item -> "${it.marca} ${it.texto.text}"
                is BloqueHtml.Cita -> it.texto.text
                is BloqueHtml.Imagen -> it.descripcion
                BloqueHtml.Linea -> ""
            }
        }.trim()
}

/** El texto de un comunicado, dibujado con los estilos de la app. */
@Composable
fun Html(html: String, modifier: Modifier = Modifier) {
    val bloques = remember(html) { HtmlComunicado.parsear(html) }
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        bloques.forEach { b ->
            when (b) {
                is BloqueHtml.Parrafo -> Text(b.texto, style = MaterialTheme.typography.bodyLarge)
                is BloqueHtml.Encabezado -> Text(
                    b.texto,
                    style = when (b.nivel) {
                        1 -> MaterialTheme.typography.headlineSmall
                        2 -> MaterialTheme.typography.titleLarge
                        else -> MaterialTheme.typography.titleMedium
                    },
                    fontWeight = FontWeight.Bold,
                )
                is BloqueHtml.Item -> Row(Modifier.padding(start = (b.nivel * 16).dp)) {
                    Text(b.marca, Modifier.width(24.dp), style = MaterialTheme.typography.bodyLarge)
                    Text(b.texto, style = MaterialTheme.typography.bodyLarge)
                }
                is BloqueHtml.Cita -> Row(Modifier.fillMaxWidth().padding(start = 4.dp)) {
                    Box(Modifier.width(3.dp).height(20.dp).background(MaterialTheme.colorScheme.primary))
                    Text(
                        b.texto, Modifier.padding(start = 10.dp), style = MaterialTheme.typography.bodyLarge,
                        fontStyle = FontStyle.Italic, color = LocalColoresApp.current.suave,
                    )
                }
                is BloqueHtml.Imagen -> AsyncImage(
                    model = b.url, contentDescription = b.descripcion.ifBlank { null },
                    modifier = Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium),
                    contentScale = ContentScale.FillWidth,
                )
                BloqueHtml.Linea -> Box(Modifier.fillMaxWidth().height(1.dp).background(LocalColoresApp.current.borde))
            }
        }
    }
}
