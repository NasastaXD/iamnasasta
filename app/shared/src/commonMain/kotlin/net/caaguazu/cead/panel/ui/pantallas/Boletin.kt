package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.School
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.Escala
import net.caaguazu.cead.panel.data.NotaPeriodo
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PaddingLista
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta

/** «4» en vez de «4.0», «3,5» en vez de «3.5»: como se escribe una nota en el colegio. */
fun notaTexto(n: Double): String {
    val entera = n.toLong()
    if (n == entera.toDouble()) return entera.toString()
    return ((n * 100).toLong() / 100.0).toString().trimEnd('0').trimEnd('.').replace('.', ',')
}

/** «Distinguido» para un 4, si la escala lo nombra. */
fun etiquetaDeNota(n: Double, escala: Escala): String? = escala.etiquetas[n.let { kotlin.math.round(it).toInt() }.toString()]

@Composable
fun BoletinPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val boletin = d.boletin

    Refrescable(ctx.repo) {
        if (boletin == null || boletin.materias.isEmpty()) {
            LazyColumn {
                item {
                    EstadoVacio(
                        Icons.Outlined.School,
                        if (boletin == null) "Tu cuenta no tiene boletín" else "Todavía no hay notas cargadas",
                        "Cuando tus docentes carguen notas, las ves acá.",
                    )
                }
            }
        } else {
            val escala = boletin.escala
            LazyColumn(contentPadding = PaddingLista, verticalArrangement = Arrangement.spacedBy(10.dp)) {
                items(boletin.materias, key = { it.materia + it.curso }) { m ->
                    Tarjeta {
                        Text(m.materia, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        if (m.curso.isNotBlank()) Text(m.curso, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
                        val periodos = boletin.periodos.filter { it in m.notas }
                        periodos.forEachIndexed { i, p ->
                            HorizontalDivider(Modifier.padding(vertical = 8.dp), color = LocalColoresApp.current.borde)
                            FilaNota(p, m.notas.getValue(p), escala)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun FilaNota(periodo: String, n: NotaPeriodo, escala: Escala) {
    val valor = n.nota
    val aprobada = valor != null && valor >= escala.aprobado
    val color = when {
        valor == null -> MaterialTheme.colorScheme.onSurface
        aprobada -> LocalColoresApp.current.exito
        else -> MaterialTheme.colorScheme.error
    }
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Column(Modifier.weight(1f)) {
            Text(periodo, style = MaterialTheme.typography.bodyMedium, fontWeight = FontWeight.Medium)
            val etiqueta = valor?.let { etiquetaDeNota(it, escala) }
            if (etiqueta != null) Text(etiqueta, style = MaterialTheme.typography.bodySmall, color = color)
            if (n.comentarios.isNotBlank()) Text(n.comentarios, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        }
        Text(
            valor?.let { notaTexto(it) } ?: n.letra.ifBlank { "—" },
            style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Black, color = color,
            modifier = Modifier.width(56.dp),
        )
    }
}
