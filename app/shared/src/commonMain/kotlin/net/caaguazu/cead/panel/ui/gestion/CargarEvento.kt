package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import net.caaguazu.cead.panel.data.AudienciaElegida
import net.caaguazu.cead.panel.ui.BotonPrincipal
import net.caaguazu.cead.panel.ui.CampoFechaHora
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.PantallaScroll
import net.caaguazu.cead.panel.ui.nombreDeEvento
import net.caaguazu.cead.panel.util.Fechas

val TIPOS_EVENTO = listOf("clase", "reunion", "examen", "entrega", "feriado", "acto", "excursion", "cierre", "evento")

@Composable
fun CargarEventoPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }

    var titulo by remember { mutableStateOf("") }
    var detalle by remember { mutableStateOf("") }
    var tipo by remember { mutableStateOf("evento") }
    var todoElDia by remember { mutableStateOf(false) }
    var inicio by remember { mutableStateOf("") }
    var fin by remember { mutableStateOf("") }
    var lugar by remember { mutableStateOf("") }
    var audiencias by remember { mutableStateOf<List<AudienciaElegida>>(emptyList()) }

    val ini = Fechas.pared(inicio)
    val finF = Fechas.pared(fin)
    val finAntes = ini != null && finF != null && finF < ini
    val listo = titulo.isNotBlank() && ini != null && !finAntes && audiencias.isNotEmpty()

    PantallaScroll {
        Text(AVISO_SIN_SENAL, style = androidx.compose.material3.MaterialTheme.typography.bodySmall)
        CampoTexto(titulo, { titulo = it }, "Título")
        Opciones("Tipo", TIPOS_EVENTO, { it == tipo }, { nombreDeEvento(it) }, { tipo = it })
        FilaInterruptor("Todo el día", todoElDia, {
            todoElDia = it
            // Al pasar a «todo el día» la hora deja de importar: se quita para que no confunda.
            if (it) { inicio = inicio.substringBefore('T'); fin = fin.substringBefore('T') }
        })
        CampoFechaHora(inicio, { inicio = it }, "Empieza", conHora = !todoElDia)
        CampoFechaHora(fin, { fin = it }, "Termina (opcional)", conHora = !todoElDia, error = if (finAntes) "No puede terminar antes de empezar." else null)
        CampoTexto(lugar, { lugar = it }, "Lugar (opcional)")
        CampoTexto(detalle, { detalle = it }, "Detalle (opcional)", lineas = 3, maxLineas = 8)
        SelectorAudiencias(d.gestion.audienciasEvento, audiencias) { audiencias = it }
        BotonPrincipal("Cargar evento", habilitado = listo, alTocar = {
            ctx.repo.cargarEvento(titulo.trim(), detalle.trim(), inicio, fin, todoElDia, lugar.trim(), tipo, audiencias)
            ctx.repo.mensaje("Evento guardado. Sale cuando haya conexión.")
            ctx.nav.volver()
        })
    }
}
