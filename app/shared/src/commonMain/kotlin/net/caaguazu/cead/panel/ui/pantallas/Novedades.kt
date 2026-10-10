package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.NotificationsNone
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EnLineaPantalla
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Tarjeta

/** Lo último que pasó en el colegio, tal como lo cuenta el servidor (la campanita del panel web). */
@Composable
fun NovedadesPantalla(ctx: Ctx) {
    EnLineaPantalla(cargar = { ctx.repo.enLinea.novedades() }) { n, _ ->
        LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (n.notificaciones.isEmpty()) {
                item { EstadoVacio(Icons.Outlined.NotificationsNone, "Sin novedades", "Cuando haya algo nuevo, lo ves acá.") }
            }
            items(n.notificaciones) { x ->
                Tarjeta {
                    if (x.tipo.isNotBlank()) Etiqueta(x.tipo.replaceFirstChar { it.uppercase() }, MaterialTheme.colorScheme.secondary)
                    Text(x.titulo, style = MaterialTheme.typography.bodyLarge)
                    if (x.cuando.isNotBlank()) Text(x.cuando, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
                }
            }
        }
    }
}
