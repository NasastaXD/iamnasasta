package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ContentCopy
import androidx.compose.material.icons.outlined.PersonSearch
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.ui.Avatar
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EnLineaPantalla
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.pantallas.sinTildes

/**
 * Quién representa a cada curso y cómo escribirle.
 *
 * Son teléfonos de terceros: solo con conexión, sin copia en el teléfono, y
 * cada vez que alguien abre esta pantalla el servidor lo deja anotado.
 */
@Composable
fun DelegadosPantalla(ctx: Ctx) {
    var consulta by remember { mutableStateOf("") }
    EnLineaPantalla(cargar = { ctx.repo.enLinea.delegados() }) { r, _ ->
        val q = sinTildes(consulta.trim())
        val lista = r.delegados.filter { q.isEmpty() || sinTildes(it.nombre).contains(q) || sinTildes(it.curso).contains(q) }
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                OutlinedTextField(
                    value = consulta, onValueChange = { consulta = it }, placeholder = { Text("Buscar por nombre o curso") },
                    leadingIcon = { Icon(Icons.Outlined.Search, null) }, singleLine = true, modifier = Modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium,
                )
            }
            if (lista.isEmpty()) item { EstadoVacio(Icons.Outlined.PersonSearch, if (r.delegados.isEmpty()) "No hay delegados cargados" else "Nada coincide") }
            items(lista, key = { it.userId }) { d ->
                Tarjeta {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Avatar(null, d.nombre.split(' ').take(2).joinToString("") { it.take(1) }.uppercase(), 44.dp)
                        Column(Modifier.weight(1f)) {
                            Text(d.nombre, fontWeight = FontWeight.SemiBold)
                            Text(listOf(d.curso, d.turno).filter { it.isNotBlank() }.joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
                            if (d.telefono.isNotBlank()) Text(d.telefono, style = MaterialTheme.typography.bodyMedium)
                            if (d.suspendido) Etiqueta("Suspendido", MaterialTheme.colorScheme.error)
                        }
                        if (d.telefono.isNotBlank()) {
                            IconButton(onClick = { ctx.plataforma.copiar(d.telefono); ctx.repo.mensaje("Teléfono copiado.") }) { Icon(Icons.Outlined.ContentCopy, "Copiar teléfono") }
                        }
                    }
                }
            }
        }
    }
}
