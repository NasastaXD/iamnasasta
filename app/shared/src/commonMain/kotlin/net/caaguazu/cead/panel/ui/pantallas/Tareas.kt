package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AttachFile
import androidx.compose.material.icons.outlined.TaskAlt
import androidx.compose.material3.Checkbox
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.Tarea
import net.caaguazu.cead.panel.plataforma.TipoArchivo
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Marca
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.TituloSeccion
import net.caaguazu.cead.panel.util.Fechas

@Composable
fun TareasPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val (hechas, pendientes) = d.tareas.tareas.partition { it.hecha }
    val ordenadas = pendientes.sortedWith(compareBy({ it.vence == null }, { it.vence }))

    Refrescable(ctx.repo) {
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (d.tareas.tareas.isEmpty()) {
                item { EstadoVacio(Icons.Outlined.TaskAlt, "No tenés tareas", "Las tareas de tu curso aparecen acá.") }
            }
            if (ordenadas.isNotEmpty()) {
                item { TituloSeccion("Pendientes (${ordenadas.size})") }
                items(ordenadas, key = { it.id }) { t -> TareaFila(ctx, t) }
            }
            if (hechas.isNotEmpty()) {
                item { TituloSeccion("Hechas (${hechas.size})") }
                items(hechas, key = { it.id }) { t -> TareaFila(ctx, t) }
            }
        }
    }
}

@Composable
private fun TareaFila(ctx: Ctx, t: Tarea) {
    var abierta by remember { mutableStateOf(false) }
    val vencida = !t.hecha && (Fechas.pared(t.vence)?.date?.let { it < Fechas.hoy() } == true)

    Tarjeta(alTocar = { abierta = !abierta }, acento = if (t.prioridad == "alta" && !t.hecha) Marca.Rojo else null, relleno = 6.dp) {
        Row(verticalAlignment = Alignment.Top) {
            Checkbox(checked = t.hecha, onCheckedChange = { ctx.repo.marcarTarea(t.id, it) })
            Column(Modifier.weight(1f).padding(top = 12.dp, end = 8.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(
                    t.titulo, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Medium,
                    textDecoration = if (t.hecha) TextDecoration.LineThrough else null,
                    color = if (t.hecha) LocalColoresApp.current.suave else MaterialTheme.colorScheme.onSurface,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Fechas.vencimiento(t.vence)?.let {
                        Text(
                            it, style = MaterialTheme.typography.bodySmall,
                            color = if (vencida) MaterialTheme.colorScheme.error else LocalColoresApp.current.suave,
                        )
                    }
                    if (t.prioridad == "alta") Etiqueta("Prioridad alta", Marca.Rojo)
                }
                if (abierta) {
                    if (t.detalle.isNotBlank()) Text(t.detalle, style = MaterialTheme.typography.bodyMedium)
                    TextButton(onClick = {
                        ctx.plataforma.selector.elegir(TipoArchivo.CUALQUIERA) { a -> if (a != null) ctx.repo.entregarTarea(t.id, a) }
                    }) {
                        androidx.compose.material3.Icon(Icons.Outlined.AttachFile, null, Modifier.padding(end = 6.dp))
                        Text("Subir entrega")
                    }
                }
            }
        }
    }
}
