package net.caaguazu.cead.panel.ui

import androidx.compose.ui.text.font.FontWeight
import net.caaguazu.cead.panel.ui.BloqueHtml.Encabezado
import net.caaguazu.cead.panel.ui.BloqueHtml.Imagen
import net.caaguazu.cead.panel.ui.BloqueHtml.Item
import net.caaguazu.cead.panel.ui.BloqueHtml.Parrafo
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertIs
import kotlin.test.assertTrue

class HtmlComunicadoTest {

    private fun textos(html: String) = HtmlComunicado.parsear(html).map {
        when (it) {
            is Parrafo -> it.texto.text
            is Encabezado -> "H${it.nivel}:" + it.texto.text
            is Item -> "${it.marca} ${it.texto.text}"
            is BloqueHtml.Cita -> "> " + it.texto.text
            is Imagen -> "IMG:" + it.url
            BloqueHtml.Linea -> "---"
        }
    }

    @Test
    fun `un parrafo con negrita conserva el estilo solo donde corresponde`() {
        val b = HtmlComunicado.parsear("<p>El <strong>viernes</strong> hay examen.</p>").single() as Parrafo

        assertEquals("El viernes hay examen.", b.texto.text)
        val negrita = b.texto.spanStyles.filter { it.item.fontWeight == FontWeight.Bold }
        assertEquals(1, negrita.size)
        assertEquals("viernes", b.texto.text.substring(negrita.single().start, negrita.single().end))
    }

    @Test
    fun `dos parrafos son dos bloques y los espacios de HTML se colapsan`() {
        assertEquals(listOf("Uno dos", "Tres"), textos("<p>  Uno \n   dos </p>\n\n<p>Tres</p>"))
    }

    @Test
    fun `un salto de linea parte el bloque`() {
        assertEquals(listOf("Linea 1", "Linea 2"), textos("<p>Linea 1<br>Linea 2</p>"))
        assertEquals(listOf("A", "B"), textos("<p>A<br />B</p>"))
    }

    @Test
    fun `las listas llevan viñeta o numero, y la anidada se indenta`() {
        val b = HtmlComunicado.parsear("<ul><li>Uno</li><li>Dos<ol><li>Sub A</li><li>Sub B</li></ol></li></ul>")
        assertEquals(
            listOf("• Uno", "• Dos", "1. Sub A", "2. Sub B"),
            b.map { (it as Item).let { i -> "${i.marca} ${i.texto.text}" } },
        )
        assertEquals(listOf(0, 0, 1, 1), b.map { (it as Item).nivel })
    }

    @Test
    fun `una lista numerada cuenta desde uno y la siguiente vuelve a empezar`() {
        assertEquals(listOf("1. a", "2. b", "1. c"), textos("<ol><li>a</li><li>b</li></ol><ol><li>c</li></ol>"))
    }

    @Test
    fun `los encabezados y las citas se distinguen`() {
        assertEquals(listOf("H2:Titulo", "Texto", "> Cita"), textos("<h2>Titulo</h2><p>Texto</p><blockquote>Cita</blockquote>"))
    }

    @Test
    fun `los enlaces web se conservan y los peligrosos no`() {
        val b = HtmlComunicado.parsear("""<p>Mirá <a href="https://cead.caaguazu.net/x">acá</a> y <a href="javascript:alert(1)">esto</a>.</p>""").single() as Parrafo

        val links = b.texto.getLinkAnnotations(0, b.texto.length)
        assertEquals(1, links.size, "el javascript: no es un enlace")
        assertEquals("https://cead.caaguazu.net/x", (links.single().item as androidx.compose.ui.text.LinkAnnotation.Url).url)
        assertEquals("Mirá acá y esto.", b.texto.text)
    }

    @Test
    fun `las imagenes solo si son de la web`() {
        assertEquals(
            listOf("IMG:https://x/a.jpg"),
            textos("""<p><img src="https://x/a.jpg" alt="foto"></p><img src="file:///etc/passwd"><img src="data:image/png;base64,AAAA">"""),
        )
    }

    @Test
    fun `las entidades de WordPress se decodifican`() {
        assertEquals(listOf("Tom & Jerry — “ok” ¿sí? ñ € 😀"), textos("<p>Tom &amp; Jerry &mdash; &ldquo;ok&rdquo; &iquest;s&iacute;? &#241; &#8364; &#x1F600;</p>"))
    }

    @Test
    fun `lo que no se entiende queda como texto y nunca falla`() {
        assertEquals(listOf("Hola mundo"), textos("<custom-tag>Hola</custom-tag> <span class='x'>mundo</span>"))
        assertEquals(listOf("sin cerrar"), textos("<p><strong>sin cerrar"))
        assertEquals(emptyList(), textos(""))
        assertEquals(emptyList(), textos("   <p>  </p>  "))
    }

    @Test
    fun `los scripts y los estilos no aparecen`() {
        assertEquals(listOf("Texto"), textos("<style>p{color:red}</style><script>alert(1)</script><p>Texto</p><!-- nota -->"))
    }

    @Test
    fun `una tabla queda como lineas de texto, no desaparece`() {
        val t = textos("<table><tr><td>Lunes</td><td>Mate</td></tr><tr><td>Martes</td><td>Lengua</td></tr></table>")
        assertEquals(2, t.size)
        assertTrue(t[0].contains("Lunes") && t[0].contains("Mate"))
    }

    @Test
    fun `texto plano para las vistas previas`() {
        assertEquals("Hola\n• uno\n• dos", HtmlComunicado.aTextoPlano("<p>Hola</p><ul><li>uno</li><li>dos</li></ul>"))
    }

    @Test
    fun `decodificar deja en paz lo que no es una entidad`() {
        assertEquals("a & b &zzz; &#99999999;", HtmlComunicado.decodificar("a & b &zzz; &#99999999;"))
    }

    @Test
    fun `una imagen es un bloque aparte, no parte del parrafo`() {
        val b = HtmlComunicado.parsear("""<p>Antes <img src="https://x/a.jpg"> despues</p>""")
        assertEquals(3, b.size)
        assertIs<Imagen>(b[1])
    }
}
