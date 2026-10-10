package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.TasaMetrica
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EnLineaPantalla
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Marca
import net.caaguazu.cead.panel.ui.PantallaScroll
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.TituloSeccion
import net.caaguazu.cead.panel.ui.colorDeEvento
import net.caaguazu.cead.panel.ui.nombreDeEvento
import net.caaguazu.cead.panel.util.Fechas

@Composable
fun MetricasPantalla(ctx: Ctx) {
    EnLineaPantalla(cargar = { ctx.repo.enLinea.metricas() }) { m, _ ->
        PantallaScroll {
            TituloSeccion("Comunidad")
            Fila(listOf(m.personas.alumnos to "Alumnos/as", m.personas.delegados to "Delegados/as", m.personas.docentes to "Docentes"), Marca.Rojo)
            Fila(listOf(m.contenido.cursos to "Cursos", m.personas.inscripcionesActivas to "Inscripciones"), Marca.Azul)
            TituloSeccion("Contenido publicado")
            Fila(listOf(m.contenido.comunicados to "Comunicados", m.contenido.encuestas to "Encuestas"), Marca.Amarillo)
            Fila(listOf(m.contenido.eventos to "Eventos", m.contenido.recursos to "Recursos"), Marca.Naranja)

            TituloSeccion("Compromiso")
            m.ultimoComunicado?.let { Tasa("Último comunicado: lectura", it, "leyeron") { it.lecturas } }
            m.ultimaEncuesta?.let { Tasa("Última encuesta: respuestas", it, "respondieron") { it.respuestas } }

            if (m.proximosEventos.isNotEmpty()) {
                TituloSeccion("Próximos eventos")
                m.proximosEventos.forEach { e ->
                    Tarjeta(acento = colorDeEvento(e.tipo)) {
                        Text(e.titulo, fontWeight = FontWeight.SemiBold)
                        Fechas.pared(e.inicio)?.let { Text("${nombreDeEvento(e.tipo)} · ${Fechas.diaMes(it.date)} · ${Fechas.hora(it)}", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave) }
                    }
                }
            }
        }
    }
}

@Composable
private fun Fila(items: List<Pair<Int, String>>, color: androidx.compose.ui.graphics.Color) {
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        items.forEach { (n, etiqueta) ->
            Column(Modifier.weight(1f)) {
                Tarjeta(acento = color) {
                    Text("$n", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
                    Text(etiqueta, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
                }
            }
        }
    }
}

@Composable
private fun Tasa(titulo: String, t: TasaMetrica, verbo: String, hechos: () -> Int) {
    Tarjeta {
        Text(titulo, style = MaterialTheme.typography.labelMedium, color = LocalColoresApp.current.suave)
        Row(verticalAlignment = Alignment.Bottom, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("${t.tasa}%", style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Black)
            Text("${hechos()} de ${t.destinatarios} $verbo", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(bottom = 4.dp))
        }
        LinearProgressIndicator(progress = { t.tasa / 100f }, modifier = Modifier.padding(vertical = 6.dp).fillMaxWidth())
        Text(t.titulo, style = MaterialTheme.typography.bodyMedium)
    }
}
