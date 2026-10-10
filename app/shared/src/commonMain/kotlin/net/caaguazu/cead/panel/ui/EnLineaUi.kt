package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CloudOff
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import net.caaguazu.cead.panel.data.ErrorApi
import net.caaguazu.cead.panel.data.Resultado
import net.caaguazu.cead.panel.data.TipoError

/** Lo que se ve en una pantalla que solo funciona con conexión. */
sealed interface CargaEnLinea<out T> {
    data object Cargando : CargaEnLinea<Nothing>
    data class Lista<T>(val valor: T) : CargaEnLinea<T>
    data class Fallo(val error: ErrorApi) : CargaEnLinea<Nothing>
}

/**
 * Para las pantallas que piden algo al servidor al abrirse (el buzón de
 * coordinación, las métricas, los delegados): cargando, error con
 * «reintentar», o el contenido. Son las únicas que no leen del teléfono,
 * a propósito: ver [net.caaguazu.cead.panel.data.EnLinea].
 */
@Composable
fun <T> EnLineaPantalla(
    clave: Any? = Unit,
    cargar: suspend () -> Resultado<T>,
    contenido: @Composable (valor: T, recargar: () -> Unit) -> Unit,
) {
    var version by remember { mutableIntStateOf(0) }
    var estado by remember(clave) { mutableStateOf<CargaEnLinea<T>>(CargaEnLinea.Cargando) }
    // Volver a cargar no vacía la pantalla: se ve lo anterior hasta que llegue lo nuevo.
    LaunchedEffect(clave, version) {
        val r = cargar()
        estado = when (r) {
            is Resultado.Ok -> CargaEnLinea.Lista(r.valor)
            is Resultado.Fallo -> CargaEnLinea.Fallo(r.error)
            Resultado.NoModificado -> estado
        }
    }
    when (val e = estado) {
        CargaEnLinea.Cargando -> Cargando()
        is CargaEnLinea.Lista -> contenido(e.valor) { version++ }
        is CargaEnLinea.Fallo -> Box(Modifier.fillMaxSize(), Alignment.Center) {
            EstadoVacio(
                Icons.Outlined.CloudOff,
                if (e.error.tipo == TipoError.SIN_CONEXION) "Esto necesita conexión" else "No se pudo cargar",
                if (e.error.tipo == TipoError.SIN_CONEXION) {
                    "Por seguridad, esta información no se guarda en el teléfono: hace falta internet para verla."
                } else {
                    e.error.mensaje
                },
                accion = { TextButton(onClick = { version++; estado = CargaEnLinea.Cargando }) { Text("Reintentar") } },
            )
        }
    }
}
