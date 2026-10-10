@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import net.caaguazu.cead.panel.data.Buzon
import net.caaguazu.cead.panel.data.ReporteBuzon
import net.caaguazu.cead.panel.data.Resultado
import net.caaguazu.cead.panel.data.SugerenciaBuzon
import net.caaguazu.cead.panel.ui.Aviso
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.DialogoConfirmar
import net.caaguazu.cead.panel.ui.EnLineaPantalla
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Marca
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.pantallas.nombreDestinatario
import net.caaguazu.cead.panel.ui.pantallas.nombreEstado

/** Qué botones tiene cada cosa del buzón, igual que en el panel web. */
fun accionesSugerencia(enPapelera: Boolean) = if (enPapelera) listOf("restore" to "Restaurar", "purge" to "Borrar para siempre")
else listOf("respond" to "Responder", "accept" to "Aceptar", "deny" to "Negar", "trash" to "A la papelera")

fun accionesReporte(enPapelera: Boolean) = if (enPapelera) listOf("restore" to "Restaurar", "purge" to "Borrar para siempre")
else listOf("respond" to "Responder", "accept" to "Aceptar", "not_report" to "No es un reporte", "trash" to "A la papelera")

/**
 * El buzón de coordinación: reportes y sugerencias del alumnado.
 *
 * Solo con conexión y sin copia en el teléfono, a propósito: son relatos de
 * cosas graves con nombres adentro.
 */
@Composable
fun BuzonPantalla(ctx: Ctx) {
    EnLineaPantalla(cargar = { ctx.repo.enLinea.buzon() }) { buzon, recargar -> BuzonContenido(ctx, buzon, recargar) }
}

@Composable
private fun BuzonContenido(ctx: Ctx, buzon: Buzon, recargar: () -> Unit) {
    var seccion by remember { mutableStateOf(if (buzon.alcance == "consejo") "sugerencias" else "reportes") }
    val papelera = buzon.papelera
    val alcance = rememberCoroutineScope()
    var confirmar by remember { mutableStateOf<Triple<String, Int, String>?>(null) }

    fun actuar(tipo: String, id: Int, accion: String, respuesta: String) {
        alcance.launch {
            when (val r = ctx.repo.enLinea.actuarBuzon(tipo, id, accion, respuesta)) {
                is Resultado.Ok -> { ctx.repo.mensaje("Listo."); recargar() }
                is Resultado.Fallo -> ctx.repo.mensaje(if (r.error.reintentable) "Sin conexión: no se hizo." else r.error.mensaje)
                Resultado.NoModificado -> Unit
            }
        }
    }

    LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (buzon.alcance == "todo") FilterChip(selected = seccion == "reportes", onClick = { seccion = "reportes" }, label = { Text("Reportes (${buzon.reportes.size})") })
                FilterChip(selected = seccion == "sugerencias", onClick = { seccion = "sugerencias" }, label = { Text("Mensajes (${buzon.sugerencias.size})") })
                if (papelera != null) FilterChip(selected = seccion == "papelera", onClick = { seccion = "papelera" }, label = { Text("Papelera") })
            }
        }
        when (seccion) {
            "reportes" -> {
                if (buzon.reportes.isEmpty()) item { EstadoVacio(Icons.Outlined.Inbox, "No hay reportes") }
                items(buzon.reportes, key = { "r${it.id}" }) { r -> ReporteTarjeta(r, accionesReporte(false), { a, t -> if (a == "trash" || a == "not_report") confirmar = Triple("reporte", r.id, a) else actuar("reporte", r.id, a, t) }) }
            }
            "sugerencias" -> {
                if (buzon.sugerencias.isEmpty()) item { EstadoVacio(Icons.Outlined.Inbox, "No hay mensajes") }
                items(buzon.sugerencias, key = { "s${it.id}" }) { s -> SugerenciaTarjeta(s, accionesSugerencia(false), { a, t -> if (a == "trash") confirmar = Triple("sugerencia", s.id, a) else actuar("sugerencia", s.id, a, t) }) }
            }
            else -> {
                val pr = papelera?.reportes.orEmpty()
                val ps = papelera?.sugerencias.orEmpty()
                if (pr.isEmpty() && ps.isEmpty()) item { EstadoVacio(Icons.Outlined.Inbox, "La papelera está vacía") }
                items(pr, key = { "pr${it.id}" }) { r -> ReporteTarjeta(r, accionesReporte(true), { a, _ -> if (a == "purge") confirmar = Triple("reporte", r.id, a) else actuar("reporte", r.id, a, "") }) }
                items(ps, key = { "ps${it.id}" }) { s -> SugerenciaTarjeta(s, accionesSugerencia(true), { a, _ -> if (a == "purge") confirmar = Triple("sugerencia", s.id, a) else actuar("sugerencia", s.id, a, "") }) }
            }
        }
    }

    confirmar?.let { (tipo, id, accion) ->
        DialogoConfirmar(
            titulo = when (accion) { "purge" -> "¿Borrar para siempre?"; "not_report" -> "¿Marcar como «No es un reporte»?"; else -> "¿Mandar a la papelera?" },
            texto = if (accion == "purge") "No se puede deshacer." else "Podés deshacerlo desde la papelera.",
            confirmar = "Sí", peligroso = accion == "purge",
            alConfirmar = { confirmar = null; actuar(tipo, id, accion, "") },
            alCancelar = { confirmar = null },
        )
    }
}

@Composable
private fun AccionesFila(acciones: List<Pair<String, String>>, alHacer: (String, String) -> Unit, puedeResponder: Boolean) {
    var respuesta by remember { mutableStateOf("") }
    var escribiendo by remember { mutableStateOf(false) }
    if (puedeResponder && (escribiendo || acciones.any { it.first in listOf("accept", "deny") })) {
        CampoTexto(respuesta, { respuesta = it }, "Respuesta (opcional)", lineas = 2, maxLineas = 6)
    }
    androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        acciones.forEach { (clave, nombre) ->
            if (clave == "respond" && !escribiendo && respuesta.isBlank()) {
                OutlinedButton(onClick = { escribiendo = true }) { Text(nombre) }
            } else {
                val texto = if (clave in listOf("respond", "accept", "deny")) respuesta else ""
                OutlinedButton(onClick = { alHacer(clave, texto); if (clave in listOf("respond", "accept", "deny")) respuesta = "" }, enabled = clave != "respond" || respuesta.isNotBlank()) { Text(nombre) }
            }
        }
    }
}

@Composable
private fun ReporteTarjeta(r: ReporteBuzon, acciones: List<Pair<String, String>>, alHacer: (String, String) -> Unit) {
    Tarjeta(acento = if (r.estado == "new") Marca.Rojo else null) {
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
            Text(r.codigo, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            Etiqueta(if (r.tipo == "confidencial") "Confidencial" else "Anónimo", MaterialTheme.colorScheme.secondary)
            Etiqueta(nombreEstado(r.estado))
        }
        Text(listOf(r.categoria, r.creado).filter { it.isNotBlank() }.joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        if (r.texto == null) Aviso("No se pudo descifrar este reporte.", esError = true)
        else Text(r.texto, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(vertical = 8.dp))
        if (r.notas.isNotBlank()) Text("Notas: ${r.notas}", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        if (r.respuesta.isNotBlank()) Text("Respuesta: ${r.respuesta}", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 4.dp))
        AccionesFila(acciones, alHacer, puedeResponder = r.tipo == "confidencial")
        if (r.tipo != "confidencial" && acciones.any { it.first == "respond" }) {
            Text("Es anónimo: no se le puede responder. Lo que escribas queda como nota interna.", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        }
    }
}

@Composable
private fun SugerenciaTarjeta(s: SugerenciaBuzon, acciones: List<Pair<String, String>>, alHacer: (String, String) -> Unit) {
    Tarjeta(acento = if (s.estado == "new") Marca.Azul else null) {
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = androidx.compose.ui.Alignment.CenterVertically) {
            Text("Para ${nombreDestinatario(s.categoria)}", fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            Etiqueta(nombreEstado(s.estado))
        }
        Text(s.creado, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        Text(s.texto, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(vertical = 8.dp))
        if (s.respuesta.isNotBlank()) Text("Respuesta: ${s.respuesta}", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(bottom = 4.dp))
        AccionesFila(acciones, alHacer, puedeResponder = true)
    }
}
