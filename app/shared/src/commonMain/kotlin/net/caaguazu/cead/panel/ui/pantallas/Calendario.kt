package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowRight
import androidx.compose.material.icons.outlined.EventBusy
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import kotlinx.datetime.DatePeriod
import kotlinx.datetime.LocalDate
import kotlinx.datetime.LocalDateTime
import kotlinx.datetime.plus
import net.caaguazu.cead.panel.data.Evento
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Margen
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.colorDeEvento
import net.caaguazu.cead.panel.ui.nombreDeEvento
import net.caaguazu.cead.panel.util.Fechas

/** Un evento con sus fechas ya leídas. */
class EventoConFecha(val evento: Evento, val inicio: LocalDateTime, val fin: LocalDateTime?) {
    val primerDia: LocalDate get() = inicio.date
    val ultimoDia: LocalDate get() = (fin?.date ?: inicio.date).let { if (it < inicio.date) inicio.date else it }

    fun ocurreEl(dia: LocalDate) = dia >= primerDia && dia <= ultimoDia
}

/** Los eventos con fechas válidas, ordenados. Los que no se pueden leer se saltean: mejor eso que romper el calendario. */
fun eventosConFecha(eventos: List<Evento>): List<EventoConFecha> =
    eventos.mapNotNull { e ->
        val ini = Fechas.pared(e.inicio) ?: return@mapNotNull null
        EventoConFecha(e, ini, Fechas.pared(e.fin))
    }.sortedBy { it.inicio }

@Composable
fun CalendarioPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val todos = remember(d.calendario) { eventosConFecha(d.calendario.eventos) }
    val hoy = Fechas.hoy()

    var mes by remember { mutableStateOf(LocalDate(hoy.year, hoy.month.ordinal + 1, 1)) }
    var dia by remember { mutableStateOf<LocalDate?>(null) }

    val delMes = todos.filter { e ->
        val ultimo = LocalDate(mes.year, mes.month.ordinal + 1, Fechas.diasDelMes(mes.year, mes.month.ordinal + 1))
        e.primerDia <= ultimo && e.ultimoDia >= mes
    }
    val mostrados = if (dia != null) todos.filter { it.ocurreEl(dia!!) } else delMes

    Refrescable(ctx.repo) {
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                CabeceraMes(
                    mes,
                    alAnterior = { mes = mes.plus(DatePeriod(months = -1)); dia = null },
                    alSiguiente = { mes = mes.plus(DatePeriod(months = 1)); dia = null },
                )
            }
            item { Rejilla(mes, todos, hoy, dia) { dia = if (dia == it) null else it } }
            if (dia != null) {
                item {
                    FilterChip(selected = true, onClick = { dia = null }, label = { Text("${Fechas.conDia(dia!!)} · ver todo el mes") })
                }
            }
            if (mostrados.isEmpty()) {
                item {
                    EstadoVacio(
                        Icons.Outlined.EventBusy,
                        if (dia != null) "Nada ese día" else "Sin eventos este mes",
                        "Se guardan los eventos desde hace 30 días hasta unos 6 meses adelante.",
                    )
                }
            }
            items(mostrados, key = { it.evento.id }) { e -> EventoFila(e) }
        }
    }
}

@Composable
private fun CabeceraMes(mes: LocalDate, alAnterior: () -> Unit, alSiguiente: () -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        IconButton(onClick = alAnterior) { Icon(Icons.AutoMirrored.Outlined.KeyboardArrowLeft, "Mes anterior") }
        Text(
            "${Fechas.mes(mes.month.ordinal + 1).replaceFirstChar { it.uppercase() }} ${mes.year}",
            Modifier.weight(1f), textAlign = TextAlign.Center, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold,
        )
        IconButton(onClick = alSiguiente) { Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, "Mes siguiente") }
    }
}

@Composable
private fun Rejilla(mes: LocalDate, eventos: List<EventoConFecha>, hoy: LocalDate, elegido: LocalDate?, alElegir: (LocalDate) -> Unit) {
    val anio = mes.year
    val numMes = mes.month.ordinal + 1
    val vacios = mes.dayOfWeek.ordinal // el lunes es la primera columna
    val dias = Fechas.diasDelMes(anio, numMes)
    val celdas = vacios + dias
    val filas = (celdas + 6) / 7

    Tarjeta(relleno = 8.dp) {
        Row(Modifier.fillMaxWidth()) {
            for (i in 1..7) {
                Text(
                    Fechas.nombreDiaCorto(i).take(2).replaceFirstChar { it.uppercase() },
                    Modifier.weight(1f), textAlign = TextAlign.Center, style = MaterialTheme.typography.labelMedium,
                    color = LocalColoresApp.current.suave,
                )
            }
        }
        for (f in 0 until filas) {
            Row(Modifier.fillMaxWidth()) {
                for (c in 0 until 7) {
                    val n = f * 7 + c - vacios + 1
                    Box(Modifier.weight(1f).aspectRatio(1f).padding(2.dp), contentAlignment = Alignment.Center) {
                        if (n in 1..dias) {
                            val fecha = LocalDate(anio, numMes, n)
                            val delDia = eventos.filter { it.ocurreEl(fecha) }
                            val esHoy = fecha == hoy
                            val esElegido = fecha == elegido
                            Column(
                                Modifier.fillMaxSize().clip(CircleShape)
                                    .background(if (esElegido) MaterialTheme.colorScheme.primary else Color.Transparent)
                                    // Un borde de 0.dp se dibuja igual (como línea finita): solo hoy lleva borde.
                                    .then(if (esHoy && !esElegido) Modifier.border(1.5.dp, MaterialTheme.colorScheme.primary, CircleShape) else Modifier)
                                    .clickable { alElegir(fecha) },
                                horizontalAlignment = Alignment.CenterHorizontally,
                                verticalArrangement = Arrangement.Center,
                            ) {
                                Text(
                                    "$n", style = MaterialTheme.typography.bodyMedium,
                                    fontWeight = if (esHoy) FontWeight.Bold else FontWeight.Normal,
                                    color = if (esElegido) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurface,
                                )
                                Row(Modifier.padding(top = 1.dp).height(6.dp), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                                    delDia.take(3).forEach {
                                        Box(
                                            Modifier.size(5.dp).clip(CircleShape)
                                                .background(if (esElegido) MaterialTheme.colorScheme.onPrimary else colorDeEvento(it.evento.tipo)),
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun EventoFila(e: EventoConFecha) {
    val ev = e.evento
    Tarjeta(acento = colorDeEvento(ev.tipo)) {
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.width(46.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text("${e.primerDia.day}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                Text(Fechas.mesCorto(e.primerDia.month.ordinal + 1), style = MaterialTheme.typography.labelMedium, color = LocalColoresApp.current.suave)
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(ev.titulo, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                val cuando = when {
                    ev.todoElDia -> if (e.ultimoDia > e.primerDia) "Del ${Fechas.diaMes(e.primerDia)} al ${Fechas.diaMes(e.ultimoDia)}" else "Todo el día"
                    e.fin != null && e.fin.date == e.inicio.date -> "${Fechas.hora(e.inicio)} a ${Fechas.hora(e.fin)}"
                    e.fin != null -> "${Fechas.diaMes(e.primerDia)} ${Fechas.hora(e.inicio)} al ${Fechas.diaMes(e.ultimoDia)} ${Fechas.hora(e.fin)}"
                    else -> Fechas.hora(e.inicio)
                }
                Text(
                    listOfNotNull(nombreDeEvento(ev.tipo), cuando, ev.lugar.ifBlank { null }).joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                )
                if (ev.detalle.isNotBlank()) Text(ev.detalle, style = MaterialTheme.typography.bodyMedium)
            }
        }
    }
}
