package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Send
import androidx.compose.material.icons.outlined.SmartToy
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.SuggestionChip
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import net.caaguazu.cead.panel.data.MensajeCeadi
import net.caaguazu.cead.panel.data.Resultado
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Margen

private val sugerencias = listOf("¿Qué tengo hoy?", "¿Cuándo es el próximo examen?", "¿Hay comunicados nuevos?", "¿Cómo justifico una falta?")

/**
 * El asistente del colegio. Es lo único de la app que NO anda sin conexión:
 * la respuesta la arma un modelo en el servidor. Lo demás de la app sí.
 */
@Composable
fun CeadiPantalla(ctx: Ctx) {
    val charla by ctx.repo.conversacionCeadi.collectAsState()
    var texto by remember { mutableStateOf("") }
    var pensando by remember { mutableStateOf(false) }
    val alcance = rememberCoroutineScope()
    val lista = rememberLazyListState()

    LaunchedEffect(charla.size, pensando) {
        if (charla.isNotEmpty()) lista.animateScrollToItem(charla.size)
    }

    fun preguntar(pregunta: String) {
        val p = pregunta.trim()
        if (p.isEmpty() || pensando) return
        texto = ""
        ctx.repo.conversacionCeadi.update { it + MensajeCeadi(p, esPropio = true) }
        pensando = true
        alcance.launch {
            val r = ctx.repo.enLinea.ceadi(p)
            pensando = false
            ctx.repo.conversacionCeadi.update {
                it + when (r) {
                    is Resultado.Ok -> MensajeCeadi(r.valor.respuesta.ifBlank { "No supe qué contestarte. ¿Probás de otra forma?" }, esPropio = false)
                    is Resultado.Fallo -> MensajeCeadi(
                        if (r.error.reintentable) "No tengo conexión ahora. CEADI necesita internet para contestar; el resto de la app sigue andando." else r.error.mensaje,
                        esPropio = false, error = true,
                    )
                    Resultado.NoModificado -> MensajeCeadi("", false)
                }
            }
        }
    }

    Column(Modifier.fillMaxSize().imePadding()) {
        LazyColumn(
            Modifier.weight(1f), state = lista,
            contentPadding = androidx.compose.foundation.layout.PaddingValues(Margen), verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            if (charla.isEmpty()) {
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        EstadoVacio(
                            Icons.Outlined.SmartToy, "Preguntale a CEADI",
                            "Te cuenta de tu horario, el calendario y los comunicados. Necesita internet.",
                        )
                        sugerencias.forEach { s -> SuggestionChip(onClick = { preguntar(s) }, label = { Text(s) }) }
                    }
                }
            }
            items(charla) { m -> Burbuja(m) }
            if (pensando) item { Burbuja(MensajeCeadi("…", false)) }
        }
        Row(Modifier.fillMaxWidth().padding(horizontal = Margen, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            OutlinedTextField(
                value = texto, onValueChange = { texto = it.take(1000) }, placeholder = { Text("Escribí tu pregunta") },
                modifier = Modifier.weight(1f), maxLines = 4, shape = RoundedCornerShape(24.dp),
            )
            if (pensando) {
                Box(Modifier.padding(12.dp)) { CircularProgressIndicator(Modifier.padding(2.dp), strokeWidth = 2.dp) }
            } else {
                IconButton(onClick = { preguntar(texto) }, enabled = texto.isNotBlank()) { Icon(Icons.AutoMirrored.Outlined.Send, "Enviar") }
            }
        }
    }
}

@Composable
private fun Burbuja(m: MensajeCeadi) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (m.esPropio) Arrangement.End else Arrangement.Start) {
        Text(
            m.texto,
            modifier = Modifier.widthIn(max = 300.dp).clip(RoundedCornerShape(18.dp))
                .background(
                    when {
                        m.esPropio -> MaterialTheme.colorScheme.primary
                        m.error -> MaterialTheme.colorScheme.errorContainer
                        else -> MaterialTheme.colorScheme.surface
                    },
                )
                .padding(horizontal = 14.dp, vertical = 10.dp),
            color = if (m.esPropio) MaterialTheme.colorScheme.onPrimary else MaterialTheme.colorScheme.onSurface,
            style = MaterialTheme.typography.bodyLarge,
        )
    }
}
