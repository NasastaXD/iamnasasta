package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

/** Los colores de la marca: los mismos que ya usa el panel web y la PWA. */
object Marca {
    val Rojo = Color(0xFFE93B3C)
    val Azul = Color(0xFF49A3C8)
    val Amarillo = Color(0xFFF2B63D)
    val Naranja = Color(0xFFE8803A)
    val Verde = Color(0xFF3E9B6B)
    val Violeta = Color(0xFF7A5FC0)
    val Fondo = Color(0xFFF7F5F0)
    val Tinta = Color(0xFF1D2327)
}

/** Colores que no tiene Material pero la app usa: el de cada tipo de evento y el de cada estado. */
class ColoresApp(
    val exito: Color,
    val aviso: Color,
    val suave: Color,
    val borde: Color,
)

val LocalColoresApp = staticCompositionLocalOf {
    ColoresApp(exito = Marca.Verde, aviso = Marca.Naranja, suave = Color(0xFF6B7378), borde = Color(0xFFE3DFD6))
}

private val Claro = lightColorScheme(
    primary = Marca.Rojo,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFFFE0DF),
    onPrimaryContainer = Color(0xFF5C0E10),
    secondary = Marca.Azul,
    onSecondary = Color.White,
    // Los «contenedores secundarios» (el chip elegido, la pestaña activa) van en el
    // tono de la marca: el azul queda para los datos, no para marcar dónde estás.
    secondaryContainer = Color(0xFFFFE0DF),
    onSecondaryContainer = Color(0xFF5C0E10),
    background = Marca.Fondo,
    onBackground = Marca.Tinta,
    surface = Color.White,
    onSurface = Marca.Tinta,
    surfaceVariant = Color(0xFFEFEBE3),
    onSurfaceVariant = Color(0xFF5A6166),
    outline = Color(0xFFC9C4B8),
    error = Color(0xFFB3261E),
)

private val Oscuro = darkColorScheme(
    primary = Color(0xFFFF6B6C),
    onPrimary = Color(0xFF3A0508),
    primaryContainer = Color(0xFF5C1417),
    onPrimaryContainer = Color(0xFFFFDAD9),
    secondary = Color(0xFF7CC4E3),
    onSecondary = Color(0xFF05303F),
    secondaryContainer = Color(0xFF5C1417),
    onSecondaryContainer = Color(0xFFFFDAD9),
    background = Color(0xFF121212),
    onBackground = Color(0xFFECECEC),
    surface = Color(0xFF1E1E1E),
    onSurface = Color(0xFFECECEC),
    surfaceVariant = Color(0xFF2A2A2A),
    onSurfaceVariant = Color(0xFFB4B9BD),
    outline = Color(0xFF4A4A4A),
    error = Color(0xFFFF8A80),
)

private val Formas = Shapes(
    small = RoundedCornerShape(8.dp),
    medium = RoundedCornerShape(14.dp),
    large = RoundedCornerShape(20.dp),
)

@Composable
fun TemaCead(oscuro: Boolean = isSystemInDarkTheme(), content: @Composable () -> Unit) {
    val colores = if (oscuro) {
        ColoresApp(exito = Color(0xFF6FCF97), aviso = Color(0xFFF2994A), suave = Color(0xFFA0A6AA), borde = Color(0xFF3A3A3A))
    } else {
        ColoresApp(exito = Marca.Verde, aviso = Color(0xFFC96A1F), suave = Color(0xFF6B7378), borde = Color(0xFFE3DFD6))
    }
    CompositionLocalProvider(LocalColoresApp provides colores) {
        MaterialTheme(
            colorScheme = if (oscuro) Oscuro else Claro,
            shapes = Formas,
            content = content,
        )
    }
}

/** El color de cada tipo de evento del calendario (los mismos tipos que el panel web). */
fun colorDeEvento(tipo: String): Color = when (tipo) {
    "clase" -> Marca.Azul
    "reunion" -> Marca.Violeta
    "examen" -> Marca.Rojo
    "entrega" -> Marca.Naranja
    "feriado" -> Marca.Verde
    "acto" -> Marca.Amarillo
    "excursion" -> Color(0xFF2E9CA6)
    "cierre" -> Color(0xFF8A6D3B)
    else -> Marca.Azul
}

fun nombreDeEvento(tipo: String): String = when (tipo) {
    "clase" -> "Clase"
    "reunion" -> "Reunión"
    "examen" -> "Examen"
    "entrega" -> "Entrega"
    "feriado" -> "Feriado"
    "acto" -> "Acto"
    "excursion" -> "Excursión"
    "cierre" -> "Cierre"
    else -> "Evento"
}
