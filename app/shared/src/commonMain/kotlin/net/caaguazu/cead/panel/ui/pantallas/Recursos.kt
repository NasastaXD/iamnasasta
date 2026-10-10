package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.CloudDownload
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.FolderOpen
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material.icons.outlined.Link
import androidx.compose.material.icons.outlined.PictureAsPdf
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.StarBorder
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import net.caaguazu.cead.panel.data.Recurso
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Marca
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta

fun tamanoTexto(bytes: Long?): String? {
    if (bytes == null || bytes <= 0) return null
    return when {
        bytes < 1024 -> "$bytes B"
        bytes < 1024 * 1024 -> "${bytes / 1024} KB"
        else -> "${((bytes * 10) / (1024 * 1024)) / 10.0} MB".replace('.', ',')
    }
}

private fun iconoDe(r: Recurso): ImageVector = when {
    !r.esArchivo -> Icons.Outlined.Link
    r.tipoMime == "application/pdf" -> Icons.Outlined.PictureAsPdf
    r.tipoMime?.startsWith("image/") == true -> Icons.Outlined.Image
    else -> Icons.Outlined.Description
}

fun coincideRecurso(r: Recurso, q: String): Boolean {
    val consulta = sinTildes(q.trim())
    if (consulta.isEmpty()) return true
    return sinTildes(r.titulo).contains(consulta) || sinTildes(r.descripcion).contains(consulta) ||
        r.materias.any { sinTildes(it.nombre).contains(consulta) }
}

@Composable
fun RecursosPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val locales by ctx.repo.locales.collectAsState()
    val d = datos ?: run { Cargando(); return }
    var consulta by remember { mutableStateOf("") }
    var materia by remember { mutableStateOf<String?>(null) }
    var soloFavoritos by remember { mutableStateOf(false) }
    var soloGuardados by remember { mutableStateOf(false) }
    val alcance = rememberCoroutineScope()
    var bajando by remember { mutableStateOf<Int?>(null) }

    val todos = d.recursos.recursos
    val materias = todos.flatMap { it.materias }.distinctBy { it.slug }.sortedBy { it.nombre }
    val lista = todos.filter { r ->
        coincideRecurso(r, consulta) &&
            (materia == null || r.materias.any { it.slug == materia }) &&
            (!soloFavoritos || r.favorito) &&
            (!soloGuardados || locales.descargas.containsKey("${r.id}"))
    }

    fun abrir(r: Recurso) {
        val guardado = ctx.repo.rutaDescarga(r)
        when {
            guardado != null -> ctx.plataforma.abrirArchivo(guardado, r.tipoMime)
            !r.esArchivo -> r.url?.let { ctx.plataforma.abrirUrl(it) }
            else -> alcance.launch {
                bajando = r.id
                val error = ctx.repo.descargarRecurso(r)
                bajando = null
                if (error == null) ctx.repo.rutaDescarga(r)?.let { ctx.plataforma.abrirArchivo(it, r.tipoMime) }
                else ctx.repo.mensaje(if (error.reintentable) "No hay conexión para bajar el archivo." else error.mensaje)
            }
        }
    }

    Refrescable(ctx.repo) {
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(
                        value = consulta, onValueChange = { consulta = it }, placeholder = { Text("Buscar recursos") },
                        leadingIcon = { Icon(Icons.Outlined.Search, null) }, singleLine = true,
                        modifier = Modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium,
                    )
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        item { FilterChip(selected = soloFavoritos, onClick = { soloFavoritos = !soloFavoritos }, label = { Text("★ Favoritos") }) }
                        item { FilterChip(selected = soloGuardados, onClick = { soloGuardados = !soloGuardados }, label = { Text("Sin conexión") }) }
                        items(materias) { m ->
                            FilterChip(selected = materia == m.slug, onClick = { materia = if (materia == m.slug) null else m.slug }, label = { Text(m.nombre) })
                        }
                    }
                }
            }
            if (lista.isEmpty()) {
                item {
                    EstadoVacio(
                        Icons.Outlined.FolderOpen,
                        if (todos.isEmpty()) "No hay recursos" else "Nada coincide",
                        if (todos.isEmpty()) "Los materiales de estudio que suban tus docentes aparecen acá." else "Probá con otra búsqueda o sacá algún filtro.",
                    )
                }
            }
            items(lista, key = { it.id }) { r ->
                val guardado = locales.descargas.containsKey("${r.id}")
                Tarjeta(alTocar = { abrir(r) }, relleno = 12.dp) {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(iconoDe(r), null, Modifier.size(30.dp), tint = MaterialTheme.colorScheme.primary)
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(r.titulo, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                            val meta = listOfNotNull(r.materias.firstOrNull()?.nombre, tamanoTexto(r.tamano)).joinToString(" · ")
                            if (meta.isNotBlank()) Text(meta, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
                            if (guardado) Etiqueta("Disponible sin conexión", LocalColoresApp.current.exito)
                        }
                        when {
                            bajando == r.id -> CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
                            r.esArchivo -> IconButton(onClick = {
                                if (guardado) ctx.repo.borrarDescarga(r) else alcance.launch {
                                    bajando = r.id
                                    val error = ctx.repo.descargarRecurso(r)
                                    bajando = null
                                    if (error != null) ctx.repo.mensaje(if (error.reintentable) "No hay conexión para bajar el archivo." else error.mensaje)
                                }
                            }) {
                                Icon(
                                    if (guardado) Icons.Outlined.CheckCircle else Icons.Outlined.CloudDownload,
                                    if (guardado) "Quitar del teléfono" else "Guardar para ver sin conexión",
                                    tint = if (guardado) LocalColoresApp.current.exito else LocalColoresApp.current.suave,
                                )
                            }
                        }
                        IconButton(onClick = { ctx.repo.fijarFavorito(r.id, !r.favorito) }) {
                            Icon(
                                if (r.favorito) Icons.Filled.Star else Icons.Outlined.StarBorder,
                                if (r.favorito) "Quitar de favoritos" else "Agregar a favoritos",
                                tint = if (r.favorito) Marca.Amarillo else LocalColoresApp.current.suave,
                            )
                        }
                    }
                }
            }
        }
    }
}
