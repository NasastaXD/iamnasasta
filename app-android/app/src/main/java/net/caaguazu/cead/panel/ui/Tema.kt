package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/** Los colores de la marca, los mismos que ya usa el PWA del panel. */
private val Marca = Color(0xFFE93B3C)
private val Fondo = Color(0xFFF7F5F0)
private val Tinta = Color(0xFF1D2327)
private val Azul = Color(0xFF49A3C8)

private val Claro = lightColorScheme(
    primary = Marca,
    onPrimary = Color.White,
    secondary = Azul,
    background = Fondo,
    onBackground = Tinta,
    surface = Color.White,
    onSurface = Tinta,
)

private val Oscuro = darkColorScheme(
    primary = Marca,
    onPrimary = Color.White,
    secondary = Azul,
    background = Color(0xFF121212),
    onBackground = Color(0xFFECECEC),
    surface = Color(0xFF1E1E1E),
    onSurface = Color(0xFFECECEC),
)

@Composable
fun TemaCead(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = if (isSystemInDarkTheme()) Oscuro else Claro,
        content = content,
    )
}
