package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material3.MaterialTheme
import androidx.compose.ui.Modifier
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.Envio
import net.caaguazu.cead.panel.ui.BotonSecundario
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.DialogoConfirmar
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.util.Fechas

/**
 * Lo que hiciste sin conexión y todavía no salió, y lo que el colegio rechazó.
 *
 * Existe para que nada se pierda en silencio: si algo no salió, acá se ve qué
 * fue y por qué, y se puede reintentar o descartar.
 */
@Composable
fun PendientesPantalla(ctx: Ctx) {
    val pendientes by ctx.repo.pendientes.collectAsState()
    var aDescartar by remember { mutableStateOf<Envio?>(null) }

    LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
        if (pendientes.isEmpty()) {
            item { EstadoVacio(Icons.Outlined.CheckCircle, "No hay nada pendiente", "Todo lo que hiciste ya salió.") }
        } else {
            item { BotonSecundario("Enviar ahora", { ctx.repo.pedirSync() }, habilitado = pendientes.any { !it.fallido }) }
        }
        items(pendientes, key = { it.id }) { e ->
            Tarjeta {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(e.titulo, Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                    if (e.fallido) Etiqueta("No salió", MaterialTheme.colorScheme.error) else Etiqueta("Esperando", LocalColoresApp.current.aviso)
                }
                Text(
                    Fechas.relativa(Fechas.instanteDesdeMilis(e.creado)),
                    style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                )
                if (e.fallido) Text(e.error ?: "", color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
                else if (e.intentos > 0) Text("Se intentó ${e.intentos} ${if (e.intentos == 1) "vez" else "veces"}.", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
                Row {
                    if (e.fallido) TextButton(onClick = { ctx.repo.reintentar(e.id) }) { Text("Reintentar") }
                    TextButton(onClick = { aDescartar = e }) { Text("Descartar", color = MaterialTheme.colorScheme.error) }
                }
            }
        }
    }

    aDescartar?.let { e ->
        DialogoConfirmar(
            titulo = "¿Descartar este envío?",
            texto = "«${e.titulo}» no se va a mandar y no se puede recuperar.",
            confirmar = "Descartar", peligroso = true,
            alConfirmar = { ctx.repo.descartar(e.id); aDescartar = null },
            alCancelar = { aDescartar = null },
        )
    }
}
