package net.caaguazu.cead.panel.util

import kotlinx.datetime.LocalDate
import kotlinx.datetime.TimeZone
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNotNull
import kotlin.test.assertNull

class FechasTest {

    private val hoy = LocalDate(2026, 10, 9)

    @Test
    fun `una fecha de pared se lee tal cual, sin convertir de zona`() {
        val f = assertNotNull(Fechas.pared("2026-10-09T14:30"))
        assertEquals("14:30", Fechas.hora(f))
        assertEquals(LocalDate(2026, 10, 9), f.date)
    }

    @Test
    fun `acepta los dos formatos que guarda el servidor y la fecha sola`() {
        assertEquals("08:05", Fechas.hora(assertNotNull(Fechas.pared("2026-10-09 08:05:00"))))
        assertEquals("00:00", Fechas.hora(assertNotNull(Fechas.pared("2026-10-09"))))
    }

    @Test
    fun `lo que no es una fecha da null, incluidas las imposibles`() {
        assertNull(Fechas.pared(null))
        assertNull(Fechas.pared(""))
        assertNull(Fechas.pared("el viernes"))
        assertNull(Fechas.pared("2026-02-31T10:00"))
        assertNull(Fechas.pared("2026-10-09T25:00"))
    }

    @Test
    fun `un instante se lleva a la hora del telefono`() {
        // 19:23 UTC en Paraguay (UTC-3) son las 16:23.
        val f = assertNotNull(Fechas.instante("2026-10-09T19:23:36+00:00", TimeZone.of("America/Asuncion")))
        assertEquals("16:23", Fechas.hora(f))
    }

    @Test
    fun `un comunicado de la noche no cae en el dia siguiente`() {
        // 01:30 UTC del día 10 es todavía el día 9 a las 22:30 en Paraguay.
        val f = assertNotNull(Fechas.instante("2026-10-10T01:30:00+00:00", TimeZone.of("America/Asuncion")))
        assertEquals(LocalDate(2026, 10, 9), f.date)
    }

    @Test
    fun `los dias y los meses salen en castellano`() {
        assertEquals("viernes 9 de octubre", Fechas.conDia(hoy))
        assertEquals("9 oct", Fechas.diaMes(hoy))
        assertEquals("9 de octubre de 2026", Fechas.larga(hoy))
        assertEquals("miércoles", Fechas.nombreDia(3))
    }

    @Test
    fun `cuando paso algo, como lo diria una persona`() {
        fun rel(s: String) = Fechas.relativa(Fechas.pared(s), hoy)
        assertEquals("hoy 14:30", rel("2026-10-09T14:30"))
        assertEquals("ayer", rel("2026-10-08T14:30"))
        assertEquals("1 oct", rel("2026-10-01T10:00"))
        assertEquals("30 dic 2025", rel("2025-12-30T10:00"))
        assertEquals("", Fechas.relativa(null, hoy))
    }

    @Test
    fun `los vencimientos de las tareas`() {
        assertEquals("vence hoy", Fechas.vencimiento("2026-10-09", hoy))
        assertEquals("vence mañana", Fechas.vencimiento("2026-10-10", hoy))
        assertEquals("vence el 12 oct", Fechas.vencimiento("2026-10-12", hoy))
        assertEquals("venció ayer", Fechas.vencimiento("2026-10-08", hoy))
        assertEquals("venció el 3 oct", Fechas.vencimiento("2026-10-03", hoy))
        assertNull(Fechas.vencimiento(null, hoy))
        assertNull(Fechas.vencimiento("", hoy))
    }

    @Test
    fun `las horas de clase como minutos`() {
        assertEquals(7 * 60, Fechas.minutos("07:00"))
        assertEquals(13 * 60 + 40, Fechas.minutos("13:40"))
        assertNull(Fechas.minutos("mañana"))
    }
}
