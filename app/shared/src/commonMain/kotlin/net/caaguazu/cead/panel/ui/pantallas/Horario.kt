package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CloudDownload
import androidx.compose.material.icons.outlined.EventBusy
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.Franja
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Margen
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.util.Fechas

/**
 * El horario semanal. Es lo primero que se consulta en el aula, donde no hay
 * wifi: se lee de lo guardado en el teléfono, sin pedir nada.
 */
@Composable
fun HorarioPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val horario = d.horario

    if (horario.curso == null) {
        EstadoVacio(
            Icons.Outlined.EventBusy, "Sin curso asignado",
            "Cuando te inscriban en un curso, tu horario aparece acá.",
        )
        return
    }
    if (horario.dias.isEmpty()) {
        EstadoVacio(Icons.Outlined.CloudDownload, "El horario de ${horario.curso.titulo} todavía no está cargado", "Secretaría lo carga al empezar el año.")
        return
    }

    val hoy = Fechas.numeroDia(Fechas.hoy().dayOfWeek)
    val dias = horario.dias.map { it.dia }
    // Se abre en hoy; si hoy no hay clases (un domingo), en el primer día que sí.
    var elegido by remember(dias) { mutableIntStateOf(if (hoy in dias) hoy else dias.first()) }
    val franjas = horario.dias.firstOrNull { it.dia == elegido }?.franjas.orEmpty()
    val ahoraMin = if (elegido == hoy) Fechas.minutosDe(Fechas.ahora()) else -1

    Refrescable(ctx.repo) {
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Text(horario.curso.titulo, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        items(dias) { dia ->
                            FilterChip(
                                selected = dia == elegido,
                                onClick = { elegido = dia },
                                label = { Text(Fechas.nombreDiaCorto(dia).replaceFirstChar { it.uppercase() } + if (dia == hoy) " · hoy" else "") },
                            )
                        }
                    }
                }
            }
            items(franjas) { f -> FranjaFila(f, ahoraMin) }
        }
    }
}

@Composable
private fun FranjaFila(f: Franja, ahoraMin: Int) {
    val ini = Fechas.minutos(f.inicio)
    val fin = Fechas.minutos(f.fin)
    val enCurso = ahoraMin >= 0 && ini != null && fin != null && ahoraMin in ini until fin
    Tarjeta(acento = if (enCurso) MaterialTheme.colorScheme.primary else null) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            Column(Modifier.width(56.dp)) {
                Text(f.inicio, fontWeight = FontWeight.Bold)
                Text(f.fin, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
            }
            Column(Modifier.weight(1f)) {
                Text(f.materia, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                val detalle = listOf(f.docente, if (f.aula.isNotBlank()) "Aula ${f.aula}" else "").filter { it.isNotBlank() }.joinToString(" · ")
                if (detalle.isNotBlank()) Text(detalle, style = MaterialTheme.typography.bodyMedium, color = LocalColoresApp.current.suave)
            }
            if (enCurso) Etiqueta("Ahora")
        }
    }
}
