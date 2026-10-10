package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Campaign
import androidx.compose.material.icons.outlined.CloudDownload
import androidx.compose.material.icons.outlined.EventAvailable
import androidx.compose.material.icons.outlined.Groups
import androidx.compose.material.icons.outlined.Insights
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.PostAdd
import androidx.compose.material.icons.outlined.School
import androidx.compose.material.icons.outlined.SmartToy
import androidx.compose.material.icons.outlined.TaskAlt
import androidx.compose.material3.Checkbox
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.Config
import net.caaguazu.cead.panel.data.Evento
import net.caaguazu.cead.panel.data.Snapshot
import net.caaguazu.cead.panel.data.Usuario
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.Destino
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.FilaMenu
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Punto
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tab
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.TituloSeccion
import net.caaguazu.cead.panel.ui.colorDeEvento
import net.caaguazu.cead.panel.ui.nombreDeEvento
import net.caaguazu.cead.panel.util.Fechas

@Composable
fun InicioPantalla(ctx: Ctx, usuario: Usuario) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos

    Refrescable(ctx.repo) {
        if (d == null) {
            PrimeraDescarga(ctx)
        } else {
            LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item { Saludo(d, usuario) }
                item { HoyTarjeta(ctx, d) }
                item { ProximosEventos(ctx, d) }
                item { UltimosComunicados(ctx, d) }
                item { TareasPendientes(ctx, d) }
                item { Accesos(ctx, usuario) }
            }
        }
    }
}

/** Lo que se ve la primera vez, antes de que haya algo guardado. */
@Composable
private fun PrimeraDescarga(ctx: Ctx) {
    val sinConexion by ctx.repo.sinConexion.collectAsState()
    val sincronizando by ctx.repo.sincronizando.collectAsState()
    if (sincronizando && !sinConexion) {
        Cargando()
    } else {
        EstadoVacio(
            Icons.Outlined.CloudDownload,
            if (sinConexion) "Sin conexión" else "Todavía no hay datos",
            if (sinConexion) "La primera vez hace falta internet para bajar tus datos. Después funciona sin conexión."
            else "Estirá hacia abajo para actualizar.",
            accion = { TextButton(onClick = { ctx.repo.pedirSync() }) { Text("Reintentar") } },
        )
    }
}

@Composable
private fun Saludo(d: Snapshot, usuario: Usuario) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(Fechas.conDia(Fechas.hoy()).replaceFirstChar { it.uppercase() }, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
        val curso = d.horario.curso?.titulo ?: usuario.curso?.titulo
        Text(
            listOfNotNull(usuario.rolLabel.ifBlank { null }, curso).joinToString(" · "),
            color = LocalColoresApp.current.suave, style = MaterialTheme.typography.bodyMedium,
        )
    }
}

@Composable
private fun HoyTarjeta(ctx: Ctx, d: Snapshot) {
    val ahora = Fechas.ahora()
    val dia = Fechas.numeroDia(ahora.date.dayOfWeek)
    val franjas = d.horario.dias.firstOrNull { it.dia == dia }?.franjas.orEmpty()
    val minutosAhora = Fechas.minutosDe(ahora)

    Tarjeta(alTocar = { ctx.nav.elegirTab(Tab.HORARIO) }) {
        Text("Hoy", style = MaterialTheme.typography.titleSmall, color = LocalColoresApp.current.suave)
        if (d.horario.curso == null) {
            Text("No tenés un curso asignado todavía.", style = MaterialTheme.typography.bodyMedium)
        } else if (franjas.isEmpty()) {
            Text("Hoy no hay clases en tu horario.", style = MaterialTheme.typography.bodyLarge)
        } else {
            franjas.take(6).forEachIndexed { i, f ->
                if (i > 0) HorizontalDivider(Modifier.padding(vertical = 6.dp), color = LocalColoresApp.current.borde)
                val ini = Fechas.minutos(f.inicio)
                val fin = Fechas.minutos(f.fin)
                val enCurso = ini != null && fin != null && minutosAhora in ini until fin
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text(f.inicio, Modifier.width(48.dp), color = LocalColoresApp.current.suave, style = MaterialTheme.typography.bodyMedium)
                    Column(Modifier.weight(1f)) {
                        Text(f.materia, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        val detalle = listOf(f.docente, if (f.aula.isNotBlank()) "Aula ${f.aula}" else "").filter { it.isNotBlank() }.joinToString(" · ")
                        if (detalle.isNotBlank()) Text(detalle, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
                    }
                    if (enCurso) Etiqueta("Ahora", MaterialTheme.colorScheme.primary)
                }
            }
            if (franjas.size > 6) Text("y ${franjas.size - 6} más…", color = LocalColoresApp.current.suave, style = MaterialTheme.typography.bodySmall)
        }
    }
}

/** Los eventos que vienen: de hoy en adelante, ordenados, los primeros. */
fun proximosEventos(d: Snapshot, cuantos: Int = Config.ITEMS_INICIO): List<Pair<Evento, kotlinx.datetime.LocalDateTime>> {
    val hoy = Fechas.hoy()
    return d.calendario.eventos
        .mapNotNull { e -> Fechas.pared(e.inicio)?.let { e to it } }
        .filter { (e, ini) ->
            // Un evento de varios días que ya empezó pero no terminó también cuenta.
            val fin = Fechas.pared(e.fin)?.date
            ini.date >= hoy || (fin != null && fin >= hoy)
        }
        .sortedBy { it.second }
        .take(cuantos)
}

@Composable
private fun ProximosEventos(ctx: Ctx, d: Snapshot) {
    val proximos = proximosEventos(d)
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        TituloSeccion("Próximos eventos") { TextButton(onClick = { ctx.nav.ir(Destino.Calendario) }) { Text("Ver todo") } }
        if (proximos.isEmpty()) {
            Text("No hay eventos próximos.", color = LocalColoresApp.current.suave, style = MaterialTheme.typography.bodyMedium)
        } else {
            proximos.forEach { (e, ini) ->
                Tarjeta(alTocar = { ctx.nav.ir(Destino.Calendario) }, acento = colorDeEvento(e.tipo)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.width(44.dp)) {
                            Text("${ini.date.day}", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                            Text(Fechas.mesCorto(ini.date.month.ordinal + 1), style = MaterialTheme.typography.labelMedium, color = LocalColoresApp.current.suave)
                        }
                        Column(Modifier.weight(1f)) {
                            Text(e.titulo, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                            Text(
                                listOfNotNull(nombreDeEvento(e.tipo), if (e.todoElDia) "Todo el día" else Fechas.hora(ini), e.lugar.ifBlank { null }).joinToString(" · "),
                                style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun UltimosComunicados(ctx: Ctx, d: Snapshot) {
    val lista = d.comunicados.comunicados.take(Config.ITEMS_INICIO)
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        TituloSeccion("Comunicados") {
            if (d.comunicados.sinLeer > 0) Etiqueta("${d.comunicados.sinLeer} sin leer")
            TextButton(onClick = { ctx.nav.elegirTab(Tab.COMUNICADOS) }) { Text("Ver todo") }
        }
        if (lista.isEmpty()) {
            Text("No hay comunicados.", color = LocalColoresApp.current.suave, style = MaterialTheme.typography.bodyMedium)
        }
        lista.forEach { c ->
            Tarjeta(alTocar = { ctx.nav.ir(Destino.Comunicado(c.id)) }) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Top) {
                    if (!c.leido) Box(Modifier.padding(top = 6.dp)) { Punto() }
                    Column(Modifier.weight(1f)) {
                        Text(
                            c.titulo, fontWeight = if (c.leido) FontWeight.Medium else FontWeight.Bold,
                            maxLines = 2, overflow = TextOverflow.Ellipsis,
                        )
                        Text(
                            Fechas.relativa(Fechas.instante(c.fecha)),
                            style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun TareasPendientes(ctx: Ctx, d: Snapshot) {
    val pendientes = d.tareas.tareas.filter { !it.hecha }.sortedWith(compareBy({ it.vence == null }, { it.vence })).take(Config.ITEMS_INICIO)
    if (pendientes.isEmpty()) return
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        TituloSeccion("Tareas pendientes") { TextButton(onClick = { ctx.nav.elegirTab(Tab.TAREAS) }) { Text("Ver todo") } }
        Tarjeta {
            pendientes.forEachIndexed { i, t ->
                if (i > 0) HorizontalDivider(Modifier.padding(vertical = 2.dp), color = LocalColoresApp.current.borde)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(checked = false, onCheckedChange = { ctx.repo.marcarTarea(t.id, true) })
                    Column(Modifier.weight(1f)) {
                        Text(t.titulo, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        Fechas.vencimiento(t.vence)?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave) }
                    }
                }
            }
        }
    }
}

@Composable
private fun Accesos(ctx: Ctx, u: Usuario) {
    val gestion = buildList {
        if (u.puede("publish_broadcast")) add(Triple(Icons.Outlined.Campaign, "Publicar comunicado", Destino.PublicarComunicado))
        if (u.puede("manage_schedule")) add(Triple(Icons.Outlined.EventAvailable, "Cargar evento", Destino.CargarEvento))
        if (u.puede("record_grade")) add(Triple(Icons.Outlined.School, "Cargar notas", Destino.CargarNotas))
        if (u.puede("manage_reports") || u.puede("manage_suggestions")) add(Triple(Icons.Outlined.Inbox, "Buzón", Destino.Buzon))
        if (u.puede("view_metrics")) add(Triple(Icons.Outlined.Insights, "Métricas", Destino.Metricas))
        if (u.puede("manage_articles")) add(Triple(Icons.Outlined.PostAdd, "Publicar en el sitio", Destino.PublicarArticulo))
        if (u.puede("manage_invitations")) add(Triple(Icons.Outlined.Groups, "Invitaciones", Destino.Invitaciones))
    }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        TituloSeccion("Accesos")
        Tarjeta(relleno = 0.dp) {
            Column {
                FilaMenu(Icons.Outlined.SmartToy, "Preguntale a CEADI", "El asistente del colegio", { ctx.nav.ir(Destino.Ceadi) }, color = MaterialTheme.colorScheme.secondary)
                gestion.forEach { (icono, titulo, destino) -> FilaMenu(icono, titulo, null, { ctx.nav.ir(destino) }) }
            }
        }
    }
}
