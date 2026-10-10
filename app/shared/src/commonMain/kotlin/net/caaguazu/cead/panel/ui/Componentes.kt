@file:OptIn(ExperimentalMaterial3Api::class)

package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.CloudOff
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material.icons.outlined.Sync
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil3.compose.AsyncImage
import net.caaguazu.cead.panel.data.Repositorio
import net.caaguazu.cead.panel.util.Fechas

/** El margen de los costados de todas las pantallas. */
val Margen = 16.dp

@Composable
fun Tarjeta(
    modifier: Modifier = Modifier,
    alTocar: (() -> Unit)? = null,
    acento: Color? = null,
    relleno: Dp = 14.dp,
    contenido: @Composable () -> Unit,
) {
    val formas = MaterialTheme.shapes.medium
    val base = modifier.fillMaxWidth()
    val cuerpo: @Composable () -> Unit = {
        // La franja se dibuja encima del borde y no como una columna aparte: así el
        // contenido queda en el mismo lugar con franja y sin ella.
        Column(
            Modifier.fillMaxWidth()
                .drawBehind { if (acento != null) drawRect(acento, size = Size(5.dp.toPx(), size.height)) }
                .padding(relleno),
        ) { contenido() }
    }
    if (alTocar != null) {
        Surface(
            onClick = alTocar, modifier = base, shape = formas, color = MaterialTheme.colorScheme.surface,
            border = BorderStroke(1.dp, LocalColoresApp.current.borde),
        ) { cuerpo() }
    } else {
        Surface(
            modifier = base, shape = formas, color = MaterialTheme.colorScheme.surface,
            border = BorderStroke(1.dp, LocalColoresApp.current.borde),
        ) { cuerpo() }
    }
}

@Composable
fun TituloSeccion(texto: String, modifier: Modifier = Modifier, accion: (@Composable RowScope.() -> Unit)? = null) {
    Row(modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(
            texto, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold,
            modifier = Modifier.weight(1f),
        )
        accion?.invoke(this)
    }
}

@Composable
fun Etiqueta(texto: String, color: Color = MaterialTheme.colorScheme.primary, modifier: Modifier = Modifier) {
    Text(
        texto,
        modifier = modifier
            .clip(RoundedCornerShape(50))
            .background(color.copy(alpha = 0.14f))
            .padding(horizontal = 9.dp, vertical = 3.dp),
        color = color,
        fontSize = 12.sp,
        fontWeight = FontWeight.SemiBold,
        maxLines = 1,
        overflow = TextOverflow.Ellipsis,
    )
}

/** El puntito de «sin leer». */
@Composable
fun Punto(modifier: Modifier = Modifier, color: Color = MaterialTheme.colorScheme.primary) {
    Box(modifier.size(9.dp).clip(CircleShape).background(color))
}

@Composable
fun Cargando(modifier: Modifier = Modifier) {
    Box(modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
}

@Composable
fun EstadoVacio(
    icono: ImageVector,
    titulo: String,
    detalle: String? = null,
    modifier: Modifier = Modifier,
    accion: (@Composable () -> Unit)? = null,
) {
    Column(
        modifier.fillMaxWidth().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterVertically),
    ) {
        Icon(icono, null, Modifier.size(44.dp), tint = LocalColoresApp.current.suave)
        Text(titulo, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
        if (detalle != null) {
            Text(detalle, color = LocalColoresApp.current.suave, style = MaterialTheme.typography.bodyMedium)
        }
        accion?.invoke()
    }
}

@Composable
fun BotonPrincipal(
    texto: String,
    alTocar: () -> Unit,
    modifier: Modifier = Modifier,
    habilitado: Boolean = true,
    trabajando: Boolean = false,
) {
    Button(
        onClick = alTocar, enabled = habilitado && !trabajando, modifier = modifier.fillMaxWidth().height(50.dp),
        shape = MaterialTheme.shapes.medium,
    ) {
        if (trabajando) {
            CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp, color = MaterialTheme.colorScheme.onPrimary)
        } else {
            Text(texto, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
fun BotonSecundario(texto: String, alTocar: () -> Unit, modifier: Modifier = Modifier, habilitado: Boolean = true) {
    OutlinedButton(
        onClick = alTocar, enabled = habilitado, modifier = modifier.fillMaxWidth().height(48.dp),
        shape = MaterialTheme.shapes.medium,
    ) { Text(texto, fontWeight = FontWeight.SemiBold) }
}

@Composable
fun CampoTexto(
    valor: String,
    alCambiar: (String) -> Unit,
    etiqueta: String,
    modifier: Modifier = Modifier,
    lineas: Int = 1,
    maxLineas: Int = lineas,
    teclado: KeyboardType = KeyboardType.Text,
    error: String? = null,
    ayuda: String? = null,
    soloLectura: Boolean = false,
    enabled: Boolean = true,
    transformacion: androidx.compose.ui.text.input.VisualTransformation = androidx.compose.ui.text.input.VisualTransformation.None,
    trailing: (@Composable () -> Unit)? = null,
) {
    OutlinedTextField(
        value = valor,
        onValueChange = alCambiar,
        label = { Text(etiqueta) },
        modifier = modifier.fillMaxWidth(),
        minLines = lineas,
        maxLines = maxLineas,
        singleLine = lineas == 1 && maxLineas == 1,
        keyboardOptions = KeyboardOptions(keyboardType = teclado),
        isError = error != null,
        readOnly = soloLectura,
        enabled = enabled,
        visualTransformation = transformacion,
        trailingIcon = trailing,
        supportingText = (error ?: ayuda)?.let { { Text(it) } },
        shape = MaterialTheme.shapes.medium,
    )
}

@Composable
fun Avatar(url: String?, iniciales: String, tamano: Dp = 44.dp, modifier: Modifier = Modifier) {
    Box(
        modifier.size(tamano).clip(CircleShape).background(MaterialTheme.colorScheme.primaryContainer),
        contentAlignment = Alignment.Center,
    ) {
        Text(
            iniciales.ifBlank { "?" }, color = MaterialTheme.colorScheme.onPrimaryContainer,
            fontWeight = FontWeight.Bold, fontSize = (tamano.value * 0.38f).sp,
        )
        if (!url.isNullOrBlank()) {
            AsyncImage(model = url, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        }
    }
}

/** Una fila de «icono + texto», la que repiten los menús y los detalles. */
@Composable
fun FilaMenu(
    icono: ImageVector,
    titulo: String,
    detalle: String? = null,
    alTocar: () -> Unit,
    insignia: String? = null,
    color: Color = MaterialTheme.colorScheme.primary,
) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = alTocar).padding(horizontal = Margen, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Box(
            Modifier.size(38.dp).clip(RoundedCornerShape(11.dp)).background(color.copy(alpha = 0.12f)),
            contentAlignment = Alignment.Center,
        ) { Icon(icono, null, Modifier.size(21.dp), tint = color) }
        Column(Modifier.weight(1f)) {
            Text(titulo, style = MaterialTheme.typography.bodyLarge, fontWeight = FontWeight.Medium)
            if (detalle != null) Text(detalle, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        }
        if (insignia != null) Etiqueta(insignia)
    }
}

@Composable
fun Aviso(texto: String, modifier: Modifier = Modifier, esError: Boolean = false) {
    val color = if (esError) MaterialTheme.colorScheme.error else LocalColoresApp.current.aviso
    Row(
        modifier.fillMaxWidth().clip(MaterialTheme.shapes.medium).background(color.copy(alpha = 0.12f)).padding(12.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Icon(Icons.Outlined.ErrorOutline, null, Modifier.size(20.dp), tint = color)
        Text(texto, color = MaterialTheme.colorScheme.onSurface, style = MaterialTheme.typography.bodyMedium)
    }
}

@Composable
fun DialogoConfirmar(
    titulo: String,
    texto: String,
    confirmar: String,
    alConfirmar: () -> Unit,
    alCancelar: () -> Unit,
    peligroso: Boolean = false,
) {
    AlertDialog(
        onDismissRequest = alCancelar,
        title = { Text(titulo) },
        text = { Text(texto) },
        confirmButton = {
            TextButton(onClick = alConfirmar) {
                Text(confirmar, color = if (peligroso) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.primary)
            }
        },
        dismissButton = { TextButton(onClick = alCancelar) { Text("Cancelar") } },
    )
}

/**
 * Estirar para actualizar.
 *
 * No pide «actualizar» nada nuevo: dispara la sincronización de siempre, que
 * primero manda lo pendiente y después trae lo nuevo.
 */
@Composable
fun Refrescable(repo: Repositorio, modifier: Modifier = Modifier, contenido: @Composable () -> Unit) {
    val sincronizando by repo.sincronizando.collectAsState()
    PullToRefreshBox(
        isRefreshing = sincronizando,
        onRefresh = { repo.pedirSync() },
        modifier = modifier.fillMaxSize(),
    ) { contenido() }
}

/**
 * La franja de arriba que dice en qué estado está lo que se ve: sin conexión,
 * sincronizando, o con envíos esperando. Es lo que le explica a alguien en el
 * aula, sin wifi, que lo que ve es lo último que se guardó y no un error.
 */
@Composable
fun BannerEstado(repo: Repositorio, alTocarPendientes: () -> Unit) {
    val sinConexion by repo.sinConexion.collectAsState()
    val sincronizando by repo.sincronizando.collectAsState()
    val pendientes by repo.pendientes.collectAsState()
    val ultima by repo.ultimaSync.collectAsState()

    val enEspera = pendientes.count { !it.fallido }
    val fallidos = pendientes.count { it.fallido }

    val (texto, color, icono) = when {
        fallidos > 0 -> Triple("$fallidos ${if (fallidos == 1) "envío no salió" else "envíos no salieron"}. Tocá para verlos.", MaterialTheme.colorScheme.error, Icons.Outlined.ErrorOutline)
        sinConexion -> Triple(
            buildString {
                append("Sin conexión")
                if (ultima > 0) append(" · datos de ").append(Fechas.relativa(Fechas.instanteDesdeMilis(ultima)))
                if (enEspera > 0) append(" · $enEspera por enviar")
            },
            LocalColoresApp.current.aviso, Icons.Outlined.CloudOff,
        )
        enEspera > 0 -> Triple("$enEspera ${if (enEspera == 1) "envío esperando" else "envíos esperando"} conexión", LocalColoresApp.current.aviso, Icons.Outlined.Sync)
        else -> null
    } ?: return

    Row(
        Modifier.fillMaxWidth().background(color.copy(alpha = 0.14f))
            .clickable(enabled = pendientes.isNotEmpty(), onClick = alTocarPendientes)
            .padding(horizontal = Margen, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(icono, null, Modifier.size(16.dp), tint = color)
        Text(texto, fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface, modifier = Modifier.weight(1f))
        if (sincronizando) CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
    }
}

/** Una pantalla con scroll y el margen de siempre: la base de los formularios y detalles. */
@Composable
fun PantallaScroll(modifier: Modifier = Modifier, contenido: @Composable androidx.compose.foundation.layout.ColumnScope.() -> Unit) {
    Column(
        modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(Margen),
        verticalArrangement = Arrangement.spacedBy(12.dp),
        content = contenido,
    )
}

fun espacioEntreItems(dp: Int = 10) = Arrangement.spacedBy(dp.dp)

val PaddingLista = PaddingValues(start = Margen, end = Margen, top = 8.dp, bottom = 24.dp)

@Composable
fun Separador(alto: Dp = 8.dp) = Spacer(Modifier.height(alto))

@Composable
fun IconoVolver() = Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Volver")
