package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Campaign
import androidx.compose.material.icons.outlined.ContentCopy
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import coil3.compose.AsyncImage
import net.caaguazu.cead.panel.data.Comunicado
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.Destino
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.Html
import net.caaguazu.cead.panel.ui.HtmlComunicado
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Margen
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Punto
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.util.Fechas

/** ¿Este comunicado tiene lo que se buscó? Sin tildes ni mayúsculas, en título, resumen y texto. */
fun coincide(c: Comunicado, consulta: String): Boolean {
    val q = sinTildes(consulta.trim())
    if (q.isEmpty()) return true
    return sinTildes(c.titulo).contains(q) || sinTildes(c.resumen).contains(q) ||
        sinTildes(HtmlComunicado.aTextoPlano(c.contenido)).contains(q)
}

fun sinTildes(s: String): String = buildString {
    for (ch in s.lowercase()) {
        append(
            when (ch) {
                'á', 'à', 'ä', 'â' -> 'a'
                'é', 'è', 'ë', 'ê' -> 'e'
                'í', 'ì', 'ï', 'î' -> 'i'
                'ó', 'ò', 'ö', 'ô' -> 'o'
                'ú', 'ù', 'ü', 'û' -> 'u'
                'ñ' -> 'n'
                else -> ch
            },
        )
    }
}

@Composable
fun ComunicadosPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    var consulta by remember { mutableStateOf("") }
    val lista = d.comunicados.comunicados.filter { coincide(it, consulta) }

    Refrescable(ctx.repo) {
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                OutlinedTextField(
                    value = consulta, onValueChange = { consulta = it },
                    placeholder = { Text("Buscar en comunicados") },
                    leadingIcon = { Icon(Icons.Outlined.Search, null) },
                    singleLine = true, modifier = Modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium,
                )
            }
            if (lista.isEmpty()) {
                item {
                    EstadoVacio(
                        Icons.Outlined.Campaign,
                        if (consulta.isBlank()) "No hay comunicados" else "Nada coincide con «$consulta»",
                        if (consulta.isBlank()) "Cuando el colegio publique uno, lo ves acá." else null,
                    )
                }
            }
            items(lista, key = { it.id }) { c -> ComunicadoFila(c) { ctx.nav.ir(Destino.Comunicado(c.id)) } }
        }
    }
}

@Composable
private fun ComunicadoFila(c: Comunicado, alTocar: () -> Unit) {
    Tarjeta(alTocar = alTocar) {
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (!c.leido) Punto()
                    c.categoria?.let { Etiqueta(it.nombre) }
                    Text(
                        Fechas.relativa(Fechas.instante(c.fecha)),
                        style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                    )
                }
                Text(
                    c.titulo, style = MaterialTheme.typography.titleMedium,
                    fontWeight = if (c.leido) FontWeight.Medium else FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis,
                )
                if (c.resumen.isNotBlank()) {
                    Text(
                        c.resumen, style = MaterialTheme.typography.bodyMedium, color = LocalColoresApp.current.suave,
                        maxLines = 2, overflow = TextOverflow.Ellipsis,
                    )
                }
            }
            if (!c.imagen.isNullOrBlank()) {
                AsyncImage(
                    model = c.imagen, contentDescription = null, contentScale = ContentScale.Crop,
                    modifier = Modifier.size(72.dp).clip(RoundedCornerShape(10.dp)),
                )
            }
        }
    }
}

@Composable
fun ComunicadoDetalle(ctx: Ctx, id: Int) {
    val datos by ctx.repo.datos.collectAsState()
    val c = datos?.comunicados?.comunicados?.firstOrNull { it.id == id }

    // Abrirlo es leerlo. Se anota (y sale cuando haya conexión).
    LaunchedEffect(id) { ctx.repo.marcarLeido(id) }

    if (c == null) {
        EstadoVacio(Icons.Outlined.Campaign, "No encontramos este comunicado", "Puede que ya no esté disponible.")
        return
    }

    androidx.compose.foundation.lazy.LazyColumn(
        contentPadding = androidx.compose.foundation.layout.PaddingValues(Margen),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    c.categoria?.let { Etiqueta(it.nombre) }
                    Fechas.instante(c.fecha)?.let {
                        Text(
                            "${Fechas.conDia(it.date)} · ${Fechas.hora(it)}",
                            style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                        )
                    }
                    Box(Modifier.weight(1f))
                    IconButton(onClick = { ctx.plataforma.compartir("${c.titulo}\n\n${HtmlComunicado.aTextoPlano(c.contenido)}") }) {
                        Icon(Icons.Outlined.Share, "Compartir")
                    }
                }
                Text(c.titulo, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            }
        }
        if (!c.imagen.isNullOrBlank()) {
            item {
                AsyncImage(
                    model = c.imagen, contentDescription = null, contentScale = ContentScale.FillWidth,
                    modifier = Modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium),
                )
            }
        }
        item {
            if (c.contenido.isBlank()) {
                Text(c.resumen, style = MaterialTheme.typography.bodyLarge)
            } else {
                Html(c.contenido)
            }
        }
    }
}
