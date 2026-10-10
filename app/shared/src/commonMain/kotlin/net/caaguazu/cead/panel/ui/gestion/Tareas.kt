package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.TaskAlt
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.ui.BotonPrincipal
import net.caaguazu.cead.panel.ui.CampoFechaHora
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Marca
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.PantallaScroll
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.util.Fechas

private val ESTADOS = listOf("pendiente" to "Pendiente", "en_curso" to "En curso", "hecha" to "Hecha", "cancelada" to "Cancelada")
private val PRIORIDADES = listOf("baja" to "Baja", "normal" to "Normal", "alta" to "Alta")

/** Las tareas del curso: las que el delegado/a va marcando y Dirección ve avanzar. */
@Composable
fun TareasDelCursoPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val tareas = d.gestion.tareasDelCurso.orEmpty().filter { it.estado != "cancelada" }

    Refrescable(ctx.repo) {
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (tareas.isEmpty()) item { EstadoVacio(Icons.Outlined.TaskAlt, "No hay tareas del curso", "Las que asigne Dirección aparecen acá.") }
            items(tareas, key = { it.id }) { t ->
                Tarjeta(acento = if (t.prioridad == "alta") Marca.Rojo else null) {
                    Text(t.titulo, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                    Text(
                        listOfNotNull(t.curso?.titulo, Fechas.vencimiento(t.vence)).joinToString(" · "),
                        style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                    )
                    if (t.detalle.isNotBlank()) Text(t.detalle, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 4.dp))
                    Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        ESTADOS.filter { it.first != "cancelada" }.forEach { (clave, nombre) ->
                            androidx.compose.material3.FilterChip(
                                selected = t.estado == clave, onClick = { ctx.repo.cambiarEstadoTarea(t.id, clave) }, label = { Text(nombre) },
                            )
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun AsignarTareaPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    // Los cursos salen de las listas que ya bajó la sincronización.
    val cursos = (d.gestion.audienciasComunicado?.cursos ?: d.gestion.audienciasEvento?.cursos).orEmpty()
        .mapNotNull { c -> c.valor.toIntOrNull()?.let { it to c.nombre } }
        .ifEmpty { d.gestion.notas?.cursos.orEmpty().map { it.id to it.titulo } }

    var titulo by remember { mutableStateOf("") }
    var detalle by remember { mutableStateOf("") }
    var curso by remember { mutableIntStateOf(0) }
    var prioridad by remember { mutableStateOf("normal") }
    var vence by remember { mutableStateOf("") }

    PantallaScroll {
        if (cursos.isEmpty()) {
            Text("No hay cursos guardados. Conectate a internet un momento para que se bajen.", color = LocalColoresApp.current.suave)
            return@PantallaScroll
        }
        Text(AVISO_SIN_SENAL, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        CampoTexto(titulo, { titulo = it }, "Título")
        CampoTexto(detalle, { detalle = it }, "Detalle (opcional)", lineas = 3, maxLineas = 8)
        Opciones("Curso", cursos, { it.first == curso }, { it.second }, { curso = it.first })
        Opciones("Prioridad", PRIORIDADES, { it.first == prioridad }, { it.second }, { prioridad = it.first })
        CampoFechaHora(vence, { vence = it }, "Vence (opcional)", conHora = false)
        BotonPrincipal("Asignar tarea", habilitado = titulo.isNotBlank() && curso != 0, alTocar = {
            ctx.repo.asignarTarea(titulo.trim(), detalle.trim(), curso, prioridad, vence)
            ctx.repo.mensaje("Tarea guardada. Se asigna cuando haya conexión.")
            ctx.nav.volver()
        })
    }
}
