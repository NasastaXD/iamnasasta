@file:OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)

package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AttachFile
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Image
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.AudienciaElegida
import net.caaguazu.cead.panel.data.OpcionAudiencia
import net.caaguazu.cead.panel.data.OpcionesAudiencia
import net.caaguazu.cead.panel.plataforma.ArchivoElegido
import net.caaguazu.cead.panel.plataforma.Plataforma
import net.caaguazu.cead.panel.plataforma.TipoArchivo
import net.caaguazu.cead.panel.ui.Aviso
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.TituloSeccion

/**
 * Una opción elegible, en chips que se acomodan en varias líneas.
 * Es lo que se usa en todos los formularios del personal: tocar una opción
 * en el teléfono es más rápido y menos propenso a errores que escribirla.
 */
@Composable
fun <T> Opciones(
    titulo: String?,
    opciones: List<T>,
    elegida: (T) -> Boolean,
    texto: (T) -> String,
    alElegir: (T) -> Unit,
) {
    if (titulo != null) TituloSeccion(titulo)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        opciones.forEach { o -> FilterChip(selected = elegida(o), onClick = { alElegir(o) }, label = { Text(texto(o)) }) }
    }
}

/**
 * A quién va dirigido. Las opciones ya vienen filtradas por lo que esta
 * persona puede publicar (el servidor las manda así): la app no repite esa
 * regla, y si cambia, cambia en un solo lugar.
 */
@Composable
fun SelectorAudiencias(
    opciones: OpcionesAudiencia?,
    elegidas: List<AudienciaElegida>,
    alCambiar: (List<AudienciaElegida>) -> Unit,
) {
    if (opciones == null) {
        Aviso("No hay opciones de destinatarios guardadas. Conectate a internet un momento para que se bajen.")
        return
    }
    fun esta(tipo: String, valor: String) = elegidas.any { it.type == tipo && it.value == valor }
    fun alternar(tipo: String, valor: String) {
        val sin = elegidas.filterNot { it.type == tipo && it.value == valor }
        // «Todo el colegio» ya incluye a todos: elegirlo vacía lo demás, y elegir
        // otra cosa lo saca. Dejarlos mezclados no tiene sentido.
        alCambiar(
            when {
                esta(tipo, valor) -> sin
                tipo == "all" -> listOf(AudienciaElegida("all", "*"))
                else -> sin.filterNot { it.type == "all" } + AudienciaElegida(tipo, valor)
            },
        )
    }

    TituloSeccion("¿A quién va dirigido?")
    if (opciones.todoElColegio) {
        FilterChip(selected = esta("all", "*"), onClick = { alternar("all", "*") }, label = { Text("Todo el colegio") })
    }
    grupo("Roles", opciones.roles) { o -> esta("role", o.valor) to { alternar("role", o.valor) } }
    grupo("Cursos", opciones.cursos) { o -> esta("course", o.valor) to { alternar("course", o.valor) } }
    grupo("Promociones", opciones.promociones) { o -> esta("cohort", o.valor) to { alternar("cohort", o.valor) } }
    if (elegidas.isEmpty()) {
        Text("Elegí al menos uno.", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
    }
}

@Composable
private fun grupo(titulo: String, lista: List<OpcionAudiencia>, estado: (OpcionAudiencia) -> Pair<Boolean, () -> Unit>) {
    if (lista.isEmpty()) return
    Text(titulo, style = MaterialTheme.typography.labelLarge, color = LocalColoresApp.current.suave)
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        lista.forEach { o ->
            val (elegida, alTocar) = estado(o)
            FilterChip(selected = elegida, onClick = alTocar, label = { Text(o.nombre) })
        }
    }
}

@Composable
fun FilaInterruptor(texto: String, valor: Boolean, alCambiar: (Boolean) -> Unit, detalle: String? = null) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        androidx.compose.foundation.layout.Column(Modifier.weight(1f)) {
            Text(texto, style = MaterialTheme.typography.bodyLarge)
            if (detalle != null) Text(detalle, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        }
        Switch(checked = valor, onCheckedChange = alCambiar)
    }
}

/** Elegir una imagen (o un archivo) y mostrar cuál es, con la opción de sacarla. */
@Composable
fun CampoArchivo(
    plataforma: Plataforma,
    elegido: ArchivoElegido?,
    alCambiar: (ArchivoElegido?) -> Unit,
    texto: String = "Agregar imagen",
    tipo: TipoArchivo = TipoArchivo.IMAGEN,
) {
    if (elegido == null) {
        TextButton(onClick = { plataforma.selector.elegir(tipo) { a -> if (a != null) alCambiar(a) } }) {
            Icon(if (tipo == TipoArchivo.IMAGEN) Icons.Outlined.Image else Icons.Outlined.AttachFile, null, Modifier.padding(end = 8.dp))
            Text(texto)
        }
    } else {
        Tarjeta(relleno = 8.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Outlined.Image, null, Modifier.padding(horizontal = 8.dp))
                Text(elegido.nombre, Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                IconButton(onClick = { alCambiar(null) }) { Icon(Icons.Outlined.Close, "Quitar") }
            }
        }
    }
}

/** Para no pedirle al servidor lo que ya se sabe: «sin conexión» no es un error en estos formularios. */
const val AVISO_SIN_SENAL = "Podés completarlo sin conexión: se envía cuando vuelva internet."
