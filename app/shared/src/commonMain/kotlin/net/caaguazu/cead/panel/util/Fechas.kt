@file:OptIn(kotlin.time.ExperimentalTime::class)

package net.caaguazu.cead.panel.util

import kotlinx.datetime.DatePeriod
import kotlinx.datetime.DayOfWeek
import kotlinx.datetime.LocalDate
import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.TimeZone
import kotlinx.datetime.plus
import kotlinx.datetime.toLocalDateTime
import kotlinx.datetime.todayIn
import kotlin.time.Clock
import kotlin.time.Instant

/**
 * Fechas en castellano.
 *
 * El servidor manda dos clases de fecha y no se pueden tratar igual:
 *
 *  - Las de los eventos y tareas son «hora de pared» del colegio
 *    (`2026-10-09T14:30`), sin zona. Se muestran tal cual, sin convertir: un
 *    examen a las 14:30 es a las 14:30 aunque el teléfono esté de viaje.
 *  - Las de publicación (`2026-10-09T19:23:36+00:00`) son instantes. Esas sí se
 *    llevan a la hora del teléfono.
 */
object Fechas {

    private val MESES = listOf("enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "setiembre", "octubre", "noviembre", "diciembre")
    private val MESES_CORTOS = listOf("ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "set", "oct", "nov", "dic")
    private val DIAS = listOf("lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo")
    private val DIAS_CORTOS = listOf("lun", "mar", "mié", "jue", "vie", "sáb", "dom")

    fun mes(n: Int) = MESES[n - 1]
    fun mesCorto(n: Int) = MESES_CORTOS[n - 1]

    /** Lunes = 1 … domingo = 7, como lo manda el servidor en los horarios. */
    fun nombreDia(n: Int) = DIAS[(n - 1).coerceIn(0, 6)]
    fun nombreDiaCorto(n: Int) = DIAS_CORTOS[(n - 1).coerceIn(0, 6)]
    fun numeroDia(d: DayOfWeek) = d.ordinal + 1

    fun hoy(zona: TimeZone = TimeZone.currentSystemDefault()): LocalDate = Clock.System.todayIn(zona)

    fun ahora(zona: TimeZone = TimeZone.currentSystemDefault()): LocalDateTime =
        Clock.System.now().toLocalDateTime(zona)

    fun milis(): Long = Clock.System.now().toEpochMilliseconds()

    /** Unos milisegundos desde 1970, llevados a la hora del teléfono. */
    fun instanteDesdeMilis(ms: Long, zona: TimeZone = TimeZone.currentSystemDefault()): LocalDateTime =
        Instant.fromEpochMilliseconds(ms).toLocalDateTime(zona)

    /**
     * Una fecha de pared del colegio: `2026-10-09T14:30`, `2026-10-09 14:30:00`
     * o solo `2026-10-09` (medianoche). Null si no es una fecha.
     */
    fun pared(texto: String?): LocalDateTime? {
        val t = texto?.trim().orEmpty()
        val m = PARED.matchEntire(t) ?: return null
        val (a, mo, d) = m.destructured.let { Triple(it.component1().toInt(), it.component2().toInt(), it.component3().toInt()) }
        val hora = m.groupValues[4].ifEmpty { "0" }.toInt()
        val min = m.groupValues[5].ifEmpty { "0" }.toInt()
        return try {
            LocalDateTime(a, mo, d, hora, min, 0, 0)
        } catch (e: Exception) {
            null
        }
    }

    private val PARED = Regex("""^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::\d{2})?)?$""")

    /** Un instante con zona (`...+00:00` o `Z`), llevado a la hora del teléfono. */
    fun instante(texto: String?, zona: TimeZone = TimeZone.currentSystemDefault()): LocalDateTime? {
        val t = texto?.trim().orEmpty()
        if (t.isEmpty()) return null
        return try {
            Instant.parse(t).toLocalDateTime(zona)
        } catch (e: Exception) {
            // Sin zona: es una fecha de pared.
            pared(t)
        }
    }

    /** `9 oct`. */
    fun diaMes(f: LocalDate) = "${f.day} ${mesCorto((f.month.ordinal + 1))}"

    /** `9 de octubre de 2026`. */
    fun larga(f: LocalDate) = "${f.day} de ${mes((f.month.ordinal + 1))} de ${f.year}"

    /** `viernes 9 de octubre`. */
    fun conDia(f: LocalDate) = "${nombreDia(numeroDia(f.dayOfWeek))} ${f.day} de ${mes((f.month.ordinal + 1))}"

    /** `14:30`. */
    fun hora(f: LocalDateTime) = "${f.hour.toString().padStart(2, '0')}:${f.minute.toString().padStart(2, '0')}"

    /**
     * Cuándo pasó algo, como lo diría una persona: «hoy 14:30», «ayer», «9 oct».
     */
    fun relativa(f: LocalDateTime?, hoy: LocalDate = hoy()): String {
        if (f == null) return ""
        val dia = f.date
        return when {
            dia == hoy -> "hoy ${hora(f)}"
            dia == hoy.plus(DatePeriod(days = -1)) -> "ayer"
            dia.year == hoy.year -> diaMes(dia)
            else -> "${diaMes(dia)} ${dia.year}"
        }
    }

    /** Cuántos días tiene un mes (febrero de un año bisiesto, 29). */
    fun diasDelMes(anio: Int, mes: Int): Int {
        val bisiesto = (anio % 4 == 0 && anio % 100 != 0) || anio % 400 == 0
        return when (mes) {
            2 -> if (bisiesto) 29 else 28
            4, 6, 9, 11 -> 30
            else -> 31
        }
    }

    /** Hora de pared a partir de `HH:MM` (los horarios de clase), como minutos desde medianoche. */
    fun minutos(hhmm: String): Int? {
        val m = Regex("""^(\d{1,2}):(\d{2})""").find(hhmm.trim()) ?: return null
        return m.groupValues[1].toInt() * 60 + m.groupValues[2].toInt()
    }

    fun minutosDe(f: LocalDateTime) = f.hour * 60 + f.minute

    /** Para vencimientos de tareas: «hoy», «mañana», «vence el 12 oct», «venció el 3 oct». */
    fun vencimiento(vence: String?, hoy: LocalDate = hoy()): String? {
        val f = pared(vence)?.date ?: return null
        val dias = f.toEpochDays() - hoy.toEpochDays()
        return when {
            dias == 0L -> "vence hoy"
            dias == 1L -> "vence mañana"
            dias > 1 -> "vence el ${diaMes(f)}"
            dias == -1L -> "venció ayer"
            else -> "venció el ${diaMes(f)}"
        }
    }
}
