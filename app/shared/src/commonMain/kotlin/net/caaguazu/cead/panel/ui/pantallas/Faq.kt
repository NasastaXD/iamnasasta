package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ExpandLess
import androidx.compose.material.icons.outlined.ExpandMore
import androidx.compose.material.icons.outlined.HelpOutline
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.Html
import net.caaguazu.cead.panel.ui.HtmlComunicado
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta

@Composable
fun FaqPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    var consulta by remember { mutableStateOf("") }
    var abierta by remember { mutableStateOf<Int?>(null) }
    val q = sinTildes(consulta.trim())
    val lista = d.faq.filter { q.isEmpty() || sinTildes(it.pregunta).contains(q) || sinTildes(HtmlComunicado.aTextoPlano(it.respuesta)).contains(q) }

    Refrescable(ctx.repo) {
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                OutlinedTextField(
                    value = consulta, onValueChange = { consulta = it }, placeholder = { Text("Buscar una duda") },
                    leadingIcon = { Icon(Icons.Outlined.Search, null) }, singleLine = true,
                    modifier = Modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium,
                )
            }
            if (lista.isEmpty()) {
                item { EstadoVacio(Icons.Outlined.HelpOutline, if (d.faq.isEmpty()) "Todavía no hay preguntas frecuentes" else "Nada coincide") }
            }
            items(lista, key = { it.id }) { f ->
                val esta = abierta == f.id
                Tarjeta(alTocar = { abierta = if (esta) null else f.id }, modifier = Modifier.animateContentSize()) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(f.pregunta, Modifier.weight(1f), fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.titleSmall)
                        Icon(if (esta) Icons.Outlined.ExpandLess else Icons.Outlined.ExpandMore, null)
                    }
                    if (esta) Html(f.respuesta, Modifier.padding(top = 10.dp))
                }
            }
        }
    }
}
