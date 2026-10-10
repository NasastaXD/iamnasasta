package net.caaguazu.cead.panel.ui

import kotlinx.datetime.DatePeriod
import kotlinx.datetime.LocalDate
import kotlinx.datetime.plus
import net.caaguazu.cead.panel.data.Calendario
import net.caaguazu.cead.panel.data.Comunicado
import net.caaguazu.cead.panel.data.Escala
import net.caaguazu.cead.panel.data.Evento
import net.caaguazu.cead.panel.data.Pregunta
import net.caaguazu.cead.panel.data.Recurso
import net.caaguazu.cead.panel.data.Snapshot
import net.caaguazu.cead.panel.data.Termino
import net.caaguazu.cead.panel.ui.gestion.leerNota
import net.caaguazu.cead.panel.ui.pantallas.coincide
import net.caaguazu.cead.panel.ui.pantallas.coincideRecurso
import net.caaguazu.cead.panel.ui.pantallas.eventosConFecha
import net.caaguazu.cead.panel.ui.pantallas.faltantes
import net.caaguazu.cead.panel.ui.pantallas.proximosEventos
import net.caaguazu.cead.panel.util.Fechas
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Las pequeñas decisiones de las pantallas que no son dibujo: se prueban sin dibujar nada. */
class AyudantesTest {

    /* ----------------------------------------------------- fechas y notas */

    @Test
    fun `la fecha canonica lleva ceros y la hora solo si se pide`() {
        assertEquals("2026-03-05", fechaCanonica(LocalDate(2026, 3, 5)))
        assertEquals("2026-03-05T07:05", fechaCanonica(LocalDate(2026, 3, 5), 7, 5))
        assertEquals("2026-12-31T23:59", fechaCanonica(LocalDate(2026, 12, 31), 23, 59))
        assertEquals("2026-03-05T14:00", fechaCanonica(LocalDate(2026, 3, 5), 14))
    }

    @Test
    fun `la fecha canonica la entiende el lector de fechas de la app`() {
        val leida = Fechas.pared(fechaCanonica(LocalDate(2026, 10, 9), 14, 30))!!
        assertEquals(LocalDate(2026, 10, 9), leida.date)
        assertEquals("14:30", Fechas.hora(leida))
    }

    @Test
    fun `una nota se lee con coma o con punto y tiene que caber en la escala`() {
        val escala = Escala(maxima = 5.0)
        assertEquals(4.5, leerNota("4,5", escala))
        assertEquals(4.5, leerNota(" 4.5 ", escala))
        assertEquals(0.0, leerNota("0", escala))
        assertEquals(5.0, leerNota("5", escala))
        assertNull(leerNota("5,1", escala))
        assertNull(leerNota("-1", escala))
        assertNull(leerNota("", escala))
        assertNull(leerNota("cuatro", escala))
        assertEquals(100.0, leerNota("100", Escala(maxima = 100.0)))
    }

    /* ------------------------------------------------------------ búsquedas */

    @Test
    fun `buscar un comunicado ignora tildes y mayusculas y mira titulo, resumen y texto`() {
        val c = Comunicado(
            titulo = "Examen de Matemática", resumen = "Tercer curso",
            contenido = "<p>Traer <strong>calculadora</strong> y regla.</p>",
        )
        assertTrue(coincide(c, "matematica"))
        assertTrue(coincide(c, "MATEMÁTICA"))
        assertTrue(coincide(c, "tercer"))
        assertTrue(coincide(c, "calculadora"))
        assertFalse(coincide(c, "<strong>"), "el HTML no es texto que se busque")
        assertFalse(coincide(c, "fisica"))
        assertTrue(coincide(c, "   "), "sin consulta se ve todo")
    }

    @Test
    fun `buscar un recurso mira titulo, descripcion y materia`() {
        val r = Recurso(titulo = "Guía de ejercicios", descripcion = "Unidad uno", materias = listOf(Termino(slug = "matematica", nombre = "Matemática")))
        assertTrue(coincideRecurso(r, "guia"))
        assertTrue(coincideRecurso(r, "unidad"))
        assertTrue(coincideRecurso(r, "matematica"))
        assertFalse(coincideRecurso(r, "lengua"))
        assertTrue(coincideRecurso(r, ""))
    }

    /* ------------------------------------------------------------ encuestas */

    @Test
    fun `falta responder solo lo obligatorio que esta vacio`() {
        val preguntas = listOf(
            Pregunta(id = 1, texto = "Nombre", obligatoria = true),
            Pregunta(id = 2, texto = "Comentario", obligatoria = false),
            Pregunta(id = 3, texto = "Materias", tipo = "checkbox", obligatoria = true),
            Pregunta(id = 4, texto = "Nota", tipo = "scale", obligatoria = true),
            Pregunta(id = 5, texto = "Otra", obligatoria = true),
        )
        val falta = faltantes(preguntas, mapOf(1 to "   ", 3 to emptyList<String>(), 4 to 3))
        assertEquals(listOf("Nombre", "Materias", "Otra"), falta)
        assertTrue(faltantes(preguntas, mapOf(1 to "Ana", 3 to listOf("Lengua"), 4 to 3, 5 to "x")).isEmpty())
    }

    /* ------------------------------------------------------------ calendario */

    @Test
    fun `los eventos se ordenan y los de fecha ilegible se saltean sin romper nada`() {
        val lista = eventosConFecha(
            listOf(
                Evento(id = 1, titulo = "B", inicio = "2026-10-12T08:00"),
                Evento(id = 2, titulo = "Roto", inicio = "pasado mañana"),
                Evento(id = 3, titulo = "A", inicio = "2026-10-10T08:00"),
            ),
        )
        assertEquals(listOf(3, 1), lista.map { it.evento.id })
    }

    @Test
    fun `un evento de varios dias ocurre cada uno de esos dias`() {
        val e = eventosConFecha(listOf(Evento(id = 1, inicio = "2026-10-19", fin = "2026-10-23"))).single()
        assertFalse(e.ocurreEl(LocalDate(2026, 10, 18)))
        assertTrue(e.ocurreEl(LocalDate(2026, 10, 19)))
        assertTrue(e.ocurreEl(LocalDate(2026, 10, 21)))
        assertTrue(e.ocurreEl(LocalDate(2026, 10, 23)))
        assertFalse(e.ocurreEl(LocalDate(2026, 10, 24)))
    }

    @Test
    fun `un fin anterior al inicio no estira el evento hacia atras`() {
        val e = eventosConFecha(listOf(Evento(id = 1, inicio = "2026-10-19T10:00", fin = "2026-10-12T10:00"))).single()
        assertEquals(LocalDate(2026, 10, 19), e.ultimoDia)
        assertFalse(e.ocurreEl(LocalDate(2026, 10, 15)))
    }

    @Test
    fun `los proximos eventos son de hoy en adelante, en orden, y cuentan los de varios dias en curso`() {
        val hoy = Fechas.hoy()
        fun dia(n: Int) = fechaCanonica(hoy.plus(DatePeriod(days = n)))
        val snap = Snapshot(
            calendario = Calendario(
                listOf(
                    Evento(id = 1, titulo = "Ayer", inicio = dia(-1)),
                    Evento(id = 2, titulo = "Pasado mañana", inicio = dia(2)),
                    Evento(id = 3, titulo = "Hoy", inicio = dia(0)),
                    Evento(id = 4, titulo = "Receso en curso", inicio = dia(-2), fin = dia(3)),
                    Evento(id = 5, titulo = "Terminó", inicio = dia(-5), fin = dia(-3)),
                ),
            ),
        )
        assertEquals(listOf(4, 3, 2), proximosEventos(snap).map { it.first.id })
        assertEquals(listOf(4), proximosEventos(snap, cuantos = 1).map { it.first.id })
    }
}
