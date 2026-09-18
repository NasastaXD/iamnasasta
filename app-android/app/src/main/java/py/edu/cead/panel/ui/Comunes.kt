package py.edu.cead.panel.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import py.edu.cead.panel.Carga

/**
 * Dibuja una sección según en qué estado esté.
 *
 * Existe para que ninguna pantalla se olvide de los tres casos. «Cargando»,
 * «vacío» y «falló» tienen que verse distinto: una lista vacía porque todavía no
 * llegó nada, otra porque de verdad no hay nada, y otra porque se cayó internet
 * son tres situaciones que la persona resuelve de tres maneras, y mostrarlas
 * igual la deja esperando algo que no va a pasar.
 */
@Composable
fun <T> Seccion(
    carga: Carga<T>,
    reintentar: () -> Unit,
    contenido: @Composable (T) -> Unit,
) {
    when (carga) {
        is Carga.Cargando -> Centro { CircularProgressIndicator() }
        is Carga.Fallo -> Centro {
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text(
                    carga.mensaje,
                    textAlign = TextAlign.Center,
                    style = MaterialTheme.typography.bodyMedium,
                )
                Button(onClick = reintentar) { Text("Reintentar") }
            }
        }
        is Carga.Listo -> contenido(carga.datos)
    }
}

@Composable
fun Centro(contenido: @Composable () -> Unit) {
    Column(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) { contenido() }
}

/**
 * El estado vacío de una sección.
 *
 * Dice qué falta y por qué, no solo «no hay nada»: a principio de año media app
 * está legítimamente vacía, y una pantalla en blanco sin explicación se lee como
 * que la app está rota.
 */
@Composable
fun Vacio(titulo: String, detalle: String) {
    Column(
        modifier = Modifier.fillMaxWidth().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Text(titulo, style = MaterialTheme.typography.titleMedium)
        Text(
            detalle,
            style = MaterialTheme.typography.bodyMedium,
            textAlign = TextAlign.Center,
            color = MaterialTheme.colorScheme.onBackground.copy(alpha = 0.7f),
        )
    }
}

/** Lunes = 1, como los guarda el plugin. */
fun nombreDelDia(dia: Int): String = when (dia) {
    1 -> "Lunes"
    2 -> "Martes"
    3 -> "Miércoles"
    4 -> "Jueves"
    5 -> "Viernes"
    6 -> "Sábado"
    7 -> "Domingo"
    else -> "Día $dia"
}
