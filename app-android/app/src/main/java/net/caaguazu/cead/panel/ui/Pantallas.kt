package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Card
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.Boletin
import net.caaguazu.cead.panel.data.Comunicados
import net.caaguazu.cead.panel.data.Horario
import net.caaguazu.cead.panel.data.Tareas

/* ------------------------------------------------------------------ horario */

@Composable
fun PantallaHorario(horario: Horario) {
    if (horario.curso == null) {
        Vacio(
            "Todavía no estás en un curso",
            "Cuando secretaría te asigne a uno, tu horario aparece acá.",
        )
        return
    }
    if (horario.dias.isEmpty()) {
        Vacio(
            "Sin horario cargado",
            "El horario de ${horario.curso.titulo} todavía no fue cargado.",
        )
        return
    }

    LazyColumn(
        contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        item {
            Text(horario.curso.titulo, style = MaterialTheme.typography.titleLarge)
        }
        items(horario.dias) { dia ->
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp)) {
                    Text(nombreDelDia(dia.dia), style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(8.dp))
                    dia.franjas.forEachIndexed { i, f ->
                        if (i > 0) HorizontalDivider(Modifier.padding(vertical = 8.dp))
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                f.inicio.ifBlank { "--:--" },
                                style = MaterialTheme.typography.labelLarge,
                                modifier = Modifier.padding(end = 12.dp),
                            )
                            Column {
                                Text(f.materia, fontWeight = FontWeight.Medium)
                                val detalle = listOfNotNull(
                                    f.docente.takeIf { it.isNotBlank() },
                                    f.aula.takeIf { it.isNotBlank() },
                                ).joinToString(" · ")
                                if (detalle.isNotBlank()) {
                                    Text(
                                        detalle,
                                        style = MaterialTheme.typography.bodySmall,
                                        color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f),
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/* -------------------------------------------------------------- comunicados */

@Composable
fun PantallaComunicados(datos: Comunicados, onAbrir: (Int) -> Unit) {
    if (datos.comunicados.isEmpty()) {
        Vacio("Sin comunicados", "Cuando el colegio publique algo, lo vas a ver acá.")
        return
    }

    LazyColumn(
        contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        items(datos.comunicados) { c ->
            Card(
                modifier = Modifier.fillMaxWidth().clickable { onAbrir(c.id) },
            ) {
                Column(Modifier.padding(16.dp)) {
                    if (c.categoria != null) {
                        Text(
                            c.categoria.nombre.uppercase(),
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.primary,
                        )
                    }
                    Text(
                        c.titulo,
                        style = MaterialTheme.typography.titleMedium,
                        // Lo no leído pesa más: es la única señal que distingue
                        // lo que falta mirar de lo que ya se miró.
                        fontWeight = if (c.leido) FontWeight.Normal else FontWeight.Bold,
                    )
                    if (c.resumen.isNotBlank()) {
                        Spacer(Modifier.height(4.dp))
                        Text(
                            c.resumen,
                            style = MaterialTheme.typography.bodyMedium,
                            maxLines = 3,
                            color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.8f),
                        )
                    }
                }
            }
        }
    }
}

/* ------------------------------------------------------------------ boletín */

@Composable
fun PantallaBoletin(boletin: Boletin) {
    if (boletin.materias.isEmpty()) {
        Vacio(
            "Sin calificaciones cargadas",
            "Cuando dirección o tus docentes carguen notas, vas a verlas acá.",
        )
        return
    }

    LazyColumn(
        contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        items(boletin.materias) { m ->
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp)) {
                    Text(m.materia, style = MaterialTheme.typography.titleMedium)
                    Spacer(Modifier.height(8.dp))
                    // Se recorre la lista de períodos del servidor y no las
                    // claves del mapa: así todas las materias se leen en la
                    // misma columna aunque a alguna le falte un período.
                    boletin.periodos.forEach { periodo ->
                        val n = m.notas[periodo]
                        Row(
                            modifier = Modifier.fillMaxWidth().padding(vertical = 2.dp),
                            horizontalArrangement = Arrangement.SpaceBetween,
                        ) {
                            Text(periodo, style = MaterialTheme.typography.bodyMedium)
                            Text(
                                n?.let { it.letra.ifBlank { it.nota?.let { v -> formatearNota(v) } ?: "—" } } ?: "—",
                                style = MaterialTheme.typography.bodyMedium,
                                fontWeight = FontWeight.Medium,
                            )
                        }
                    }
                }
            }
        }
    }
}

/** Sin decimales cuando no hacen falta: «4» se lee mejor que «4.0». */
private fun formatearNota(v: Double): String =
    if (v == v.toLong().toDouble()) v.toLong().toString() else v.toString()

/* ------------------------------------------------------------------- tareas */

@Composable
fun PantallaTareas(datos: Tareas) {
    if (datos.tareas.isEmpty()) {
        Vacio("Sin tareas", "No tenés tareas cargadas en este momento.")
        return
    }

    val pendientes = datos.tareas.filterNot { it.hecha }
    val hechas = datos.tareas.filter { it.hecha }

    LazyColumn(
        contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        if (pendientes.isNotEmpty()) {
            item { Text("Pendientes", style = MaterialTheme.typography.titleMedium) }
            items(pendientes) { t -> FilaTarea(t.titulo, t.vence, t.prioridad, false) }
        }
        if (hechas.isNotEmpty()) {
            item {
                Spacer(Modifier.height(8.dp))
                Text("Hechas", style = MaterialTheme.typography.titleMedium)
            }
            items(hechas) { t -> FilaTarea(t.titulo, t.vence, t.prioridad, true) }
        }
    }
}

@Composable
private fun FilaTarea(titulo: String, vence: String?, prioridad: String, hecha: Boolean) {
    Card(modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(16.dp)) {
            Text(
                titulo,
                style = MaterialTheme.typography.bodyLarge,
                color = if (hecha) {
                    MaterialTheme.colorScheme.onSurface.copy(alpha = 0.5f)
                } else {
                    MaterialTheme.colorScheme.onSurface
                },
            )
            val pie = listOfNotNull(
                vence?.takeIf { it.isNotBlank() }?.let { "Vence $it" },
                prioridad.takeIf { it == "alta" }?.let { "Prioridad alta" },
            ).joinToString(" · ")
            if (pie.isNotBlank()) {
                Spacer(Modifier.height(4.dp))
                Text(
                    pie,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f),
                )
            }
        }
    }
}
