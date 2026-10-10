@file:OptIn(ExperimentalMaterial3Api::class, kotlin.time.ExperimentalTime::class)

package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import kotlinx.datetime.LocalDate
import kotlinx.datetime.TimeZone
import kotlinx.datetime.atStartOfDayIn
import kotlinx.datetime.toLocalDateTime
import net.caaguazu.cead.panel.util.Fechas
import kotlin.time.Instant

/** Texto canónico que entiende el servidor: `2026-10-09T14:30`, o `2026-10-09` si no hay hora. */
fun fechaCanonica(f: LocalDate, hora: Int? = null, minuto: Int = 0): String {
    val d = "${f.year.toString().padStart(4, '0')}-${(f.month.ordinal + 1).toString().padStart(2, '0')}-${f.day.toString().padStart(2, '0')}"
    return if (hora == null) d else "${d}T${hora.toString().padStart(2, '0')}:${minuto.toString().padStart(2, '0')}"
}

/**
 * Un campo de fecha (y hora, si se pide) que se elige en un calendario.
 *
 * Escribir una fecha a mano en un teléfono es una fuente de errores
 * (`12/10`, `12-10-26`, `el viernes`); con el selector, lo que sale ya es
 * una fecha válida. El valor viaja como texto canónico (ver [fechaCanonica]).
 */
@Composable
fun CampoFechaHora(
    valor: String,
    alCambiar: (String) -> Unit,
    etiqueta: String,
    conHora: Boolean = true,
    modifier: Modifier = Modifier,
    error: String? = null,
) {
    var dialogo by remember { mutableStateOf<String?>(null) } // "fecha" | "hora"
    val actual = Fechas.pared(valor)
    val texto = when {
        actual == null -> ""
        conHora && valor.contains('T') -> "${Fechas.conDia(actual.date)} · ${Fechas.hora(actual)}"
        else -> Fechas.conDia(actual.date)
    }

    val fuente = remember { MutableInteractionSource() }
    val tocado by fuente.collectIsPressedAsState()
    LaunchedEffect(tocado) { if (tocado) dialogo = "fecha" }

    OutlinedTextField(
        value = texto,
        onValueChange = {},
        readOnly = true,
        label = { Text(etiqueta) },
        modifier = modifier.fillMaxWidth(),
        interactionSource = fuente,
        isError = error != null,
        supportingText = error?.let { { Text(it) } },
        leadingIcon = { Icon(Icons.Outlined.CalendarMonth, null) },
        trailingIcon = if (valor.isNotEmpty()) {
            { IconButton(onClick = { alCambiar("") }) { Icon(Icons.Outlined.Close, "Borrar") } }
        } else null,
        shape = androidx.compose.material3.MaterialTheme.shapes.medium,
    )

    if (dialogo == "fecha") {
        val inicial = actual?.date ?: Fechas.hoy()
        val estado = rememberDatePickerState(
            initialSelectedDateMillis = inicial.atStartOfDayIn(TimeZone.UTC).toEpochMilliseconds(),
        )
        DatePickerDialog(
            onDismissRequest = { dialogo = null },
            confirmButton = {
                TextButton(onClick = {
                    val ms = estado.selectedDateMillis
                    dialogo = null
                    if (ms != null) {
                        val f = Instant.fromEpochMilliseconds(ms).toLocalDateTime(TimeZone.UTC).date
                        if (conHora) {
                            // Con hora, se pide enseguida: sin ella el evento quedaría a las 00:00.
                            alCambiar(fechaCanonica(f, actual?.hour ?: 8, actual?.minute ?: 0))
                            dialogo = "hora"
                        } else {
                            alCambiar(fechaCanonica(f))
                        }
                    }
                }) { Text("Aceptar") }
            },
            dismissButton = { TextButton(onClick = { dialogo = null }) { Text("Cancelar") } },
        ) { DatePicker(estado) }
    }

    if (dialogo == "hora") {
        val base = Fechas.pared(valor)
        val estado = rememberTimePickerState(base?.hour ?: 8, base?.minute ?: 0, is24Hour = true)
        AlertDialog(
            onDismissRequest = { dialogo = null },
            title = { Text("Hora") },
            text = { Column { TimePicker(estado) } },
            confirmButton = {
                TextButton(onClick = {
                    val f = Fechas.pared(valor)?.date
                    dialogo = null
                    if (f != null) alCambiar(fechaCanonica(f, estado.hour, estado.minute))
                }) { Text("Aceptar") }
            },
            dismissButton = { TextButton(onClick = { dialogo = null }) { Text("Cancelar") } },
        )
    }
}
