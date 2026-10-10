package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Poll
import androidx.compose.material3.Checkbox
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.Encuesta
import net.caaguazu.cead.panel.data.Pregunta
import net.caaguazu.cead.panel.ui.Aviso
import net.caaguazu.cead.panel.ui.BotonPrincipal
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.Destino
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Margen
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.util.Fechas

@Composable
fun EncuestasPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    // Primero las que se pueden responder.
    val lista = d.encuestas.encuestas.sortedWith(compareBy({ !(it.abierta && it.respondida != true) }, { -it.id }))

    Refrescable(ctx.repo) {
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (lista.isEmpty()) {
                item { EstadoVacio(Icons.Outlined.Poll, "No hay encuestas", "Cuando el colegio quiera saber tu opinión, la encuesta aparece acá.") }
            }
            items(lista, key = { it.id }) { e ->
                val pendiente = e.abierta && e.respondida != true
                Tarjeta(alTocar = { ctx.nav.ir(Destino.ResponderEncuesta(e.id)) }) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        when {
                            e.respondida == true -> Etiqueta("Respondida", LocalColoresApp.current.exito)
                            !e.abierta -> Etiqueta("Cerrada", LocalColoresApp.current.suave)
                            else -> Etiqueta("Para responder")
                        }
                        if (e.anonima) Etiqueta("Anónima", MaterialTheme.colorScheme.secondary)
                    }
                    Text(e.titulo, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                    if (e.descripcion.isNotBlank()) Text(e.descripcion, style = MaterialTheme.typography.bodyMedium, color = LocalColoresApp.current.suave, maxLines = 2)
                    if (pendiente) Fechas.pared(e.cierra)?.let {
                        Text("Cierra el ${Fechas.diaMes(it.date)}", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.aviso)
                    }
                }
            }
        }
    }
}

/**
 * Qué falta responder. Pura, para probarla: devuelve los textos de las
 * preguntas obligatorias que están vacías.
 */
fun faltantes(preguntas: List<Pregunta>, respuestas: Map<Int, Any>): List<String> =
    preguntas.filter { it.obligatoria }.filter { p ->
        when (val r = respuestas[p.id]) {
            null -> true
            is String -> r.isBlank()
            is List<*> -> r.isEmpty()
            else -> false
        }
    }.map { it.texto }

@Composable
fun EncuestaPantalla(ctx: Ctx, id: Int) {
    val datos by ctx.repo.datos.collectAsState()
    val e = datos?.encuestas?.encuestas?.firstOrNull { it.id == id }
    if (e == null) {
        EstadoVacio(Icons.Outlined.Poll, "No encontramos esta encuesta")
        return
    }
    val respuestas = remember(id) { mutableStateMapOf<Int, Any>() }
    var intento by remember { mutableStateOf(false) }

    val yaRespondida = e.respondida == true
    val puedeResponder = e.abierta && !yaRespondida && e.preguntas.isNotEmpty()
    val falta = faltantes(e.preguntas, respuestas)

    Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(Margen), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Text(e.titulo, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
        if (e.descripcion.isNotBlank()) Text(e.descripcion, style = MaterialTheme.typography.bodyLarge)
        if (e.anonima) Aviso("Esta encuesta es anónima: nadie, ni el colegio, va a saber cuáles fueron tus respuestas.")
        if (yaRespondida) Aviso("Ya respondiste esta encuesta. ¡Gracias!")
        else if (!e.abierta) Aviso("Esta encuesta ya cerró.")
        else if (e.preguntas.isEmpty()) Aviso("Las preguntas se bajan al sincronizar. Conectate a internet y volvé a abrirla.")

        if (puedeResponder) {
            e.preguntas.forEach { p ->
                PreguntaEncuesta(p, respuestas[p.id], error = intento && p.texto in falta) { respuestas[p.id] = it }
            }
            if (intento && falta.isNotEmpty()) Aviso("Falta responder: ${falta.joinToString(", ")}.", esError = true)
            BotonPrincipal("Enviar respuestas", alTocar = {
                intento = true
                if (falta.isEmpty()) {
                    ctx.repo.responderEncuesta(e, respuestas.toMap())
                    ctx.repo.mensaje("Respuestas guardadas. Se envían cuando haya conexión.")
                    ctx.nav.volver()
                }
            })
        }
    }
}

@Composable
private fun PreguntaEncuesta(p: Pregunta, valor: Any?, error: Boolean, alCambiar: (Any) -> Unit) {
    Tarjeta {
        Text(
            p.texto + if (p.obligatoria) " *" else "",
            style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold,
            color = if (error) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurface,
        )
        Column(Modifier.padding(top = 6.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            when (p.tipo) {
                "radio" -> p.opciones.forEach { o ->
                    Row(
                        Modifier.fillMaxWidth().selectable(selected = valor == o, role = Role.RadioButton, onClick = { alCambiar(o) }),
                        verticalAlignment = Alignment.CenterVertically,
                    ) { RadioButton(selected = valor == o, onClick = null); Text(o, Modifier.padding(start = 10.dp, top = 8.dp, bottom = 8.dp)) }
                }
                "checkbox" -> {
                    val marcadas = (valor as? List<*>)?.filterIsInstance<String>() ?: emptyList()
                    p.opciones.forEach { o ->
                        Row(
                            Modifier.fillMaxWidth().toggleable(value = o in marcadas, role = Role.Checkbox, onValueChange = { alCambiar(if (it) marcadas + o else marcadas - o) }),
                            verticalAlignment = Alignment.CenterVertically,
                        ) { Checkbox(checked = o in marcadas, onCheckedChange = null); Text(o, Modifier.padding(start = 10.dp, top = 8.dp, bottom = 8.dp)) }
                    }
                }
                "scale" -> {
                    val desde = p.min ?: 1
                    val hasta = p.max ?: 5
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        for (n in desde..hasta) {
                            FilterChip(selected = valor == "$n", onClick = { alCambiar("$n") }, label = { Text("$n") })
                        }
                    }
                }
                "long_text" -> CampoTexto((valor as? String) ?: "", { alCambiar(it) }, "Tu respuesta", lineas = 4, maxLineas = 8)
                "date" -> CampoTexto((valor as? String) ?: "", { alCambiar(it) }, "Fecha (AAAA-MM-DD)", teclado = KeyboardType.Number)
                else -> CampoTexto((valor as? String) ?: "", { alCambiar(it) }, "Tu respuesta")
            }
        }
    }
}
