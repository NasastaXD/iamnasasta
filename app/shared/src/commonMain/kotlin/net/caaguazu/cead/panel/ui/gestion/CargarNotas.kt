@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.School
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.CursoNotas
import net.caaguazu.cead.panel.data.Escala
import net.caaguazu.cead.panel.ui.Aviso
import net.caaguazu.cead.panel.ui.BotonPrincipal
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EnLineaPantalla
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PantallaScroll
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.TituloSeccion
import net.caaguazu.cead.panel.ui.pantallas.etiquetaDeNota
import net.caaguazu.cead.panel.ui.pantallas.notaTexto
import net.caaguazu.cead.panel.ui.pantallas.sinTildes

/** La nota escrita, si es un número dentro de la escala. Acepta coma o punto. */
fun leerNota(texto: String, escala: Escala): Double? {
    val n = texto.trim().replace(',', '.').toDoubleOrNull() ?: return null
    return if (n in 0.0..escala.maxima) n else null
}

/**
 * Cargar notas desde el teléfono, sin conexión.
 *
 * El docente carga en el aula —donde no hay wifi— con las opciones que bajó la
 * última vez (cursos, alumnos, materias, etapas). Después de guardar una nota
 * quedan el curso, la materia y la etapa elegidos, porque lo normal es cargar
 * a todo el curso una tras otra: lo único que cambia es el alumno y la nota.
 */
@Composable
fun CargarNotasPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val opc = d.gestion.notas
    if (opc == null || opc.cursos.isEmpty()) {
        EstadoVacio(Icons.Outlined.School, "No hay cursos para cargar notas", "Si tenés cursos asignados, conectate a internet un momento para que se bajen.")
        return
    }

    var verCargadas by remember { mutableStateOf(false) }
    var cursoId by remember { mutableIntStateOf(opc.cursos.first().id) }
    var alumnoId by remember { mutableIntStateOf(0) }
    var materiaId by remember { mutableIntStateOf(0) }
    var materiaNueva by remember { mutableStateOf("") }
    var periodo by remember { mutableStateOf(opc.periodos.firstOrNull().orEmpty()) }
    var notaTxt by remember { mutableStateOf("") }
    var comentario by remember { mutableStateOf("") }
    var buscar by remember { mutableStateOf("") }
    var cargadas by remember { mutableIntStateOf(0) }

    val curso: CursoNotas = opc.cursos.firstOrNull { it.id == cursoId } ?: opc.cursos.first()
    val alumno = curso.alumnos.firstOrNull { it.id == alumnoId }
    val nota = leerNota(notaTxt, opc.escala)
    val materia = opc.materias.firstOrNull { it.id == materiaId }?.nombre ?: materiaNueva.trim()
    val listo = alumno != null && nota != null && periodo.isNotBlank() && (materiaId != 0 || materiaNueva.isNotBlank())

    PantallaScroll {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(selected = !verCargadas, onClick = { verCargadas = false }, label = { Text("Cargar") })
            FilterChip(selected = verCargadas, onClick = { verCargadas = true }, label = { Text("Ver cargadas") })
        }

        if (opc.cursos.size > 1) {
            Opciones("Curso", opc.cursos, { it.id == cursoId }, { it.titulo }, { cursoId = it.id; alumnoId = 0 })
        } else {
            Text(curso.titulo, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
        }

        if (verCargadas) {
            NotasCargadas(ctx, cursoId)
            return@PantallaScroll
        }

        Text(AVISO_SIN_SENAL, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)

        TituloSeccion("Alumno/a")
        CampoTexto(buscar, { buscar = it }, "Buscar por nombre")
        val q = sinTildes(buscar.trim())
        val visibles = curso.alumnos.filter { q.isEmpty() || sinTildes(it.nombre).contains(q) }
        Opciones(null, visibles.take(40), { it.id == alumnoId }, { it.nombre }, { alumnoId = it.id })
        if (visibles.size > 40) Text("Mostrando 40 de ${visibles.size}: buscá por nombre para ver el resto.", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)

        Opciones("Materia", opc.materias, { it.id == materiaId }, { it.nombre }, { materiaId = if (materiaId == it.id) 0 else it.id; materiaNueva = "" })
        if (materiaId == 0) {
            CampoTexto(materiaNueva, { materiaNueva = it }, "O escribí una materia nueva", ayuda = "Se crea solo si no existe.")
        }
        Opciones("Etapa", opc.periodos, { it == periodo }, { it }, { periodo = it })

        CampoTexto(
            notaTxt, { notaTxt = it }, "Nota (0 a ${notaTexto(opc.escala.maxima)})", teclado = KeyboardType.Decimal,
            error = if (notaTxt.isNotBlank() && nota == null) "Poné un número entre 0 y ${notaTexto(opc.escala.maxima)}." else null,
            ayuda = nota?.let { n ->
                val et = etiquetaDeNota(n, opc.escala)
                (et?.let { "$it · " } ?: "") + if (n < opc.escala.aprobado) "queda aplazado/a" else "aprobado"
            },
        )
        CampoTexto(comentario, { comentario = it }, "Comentario (opcional)", lineas = 2, maxLineas = 4)

        if (nota != null && nota < opc.escala.aprobado) Aviso("Con esa nota queda aplazado/a. Revisá que no sea un error de tipeo.")

        BotonPrincipal("Guardar nota", habilitado = listo, alTocar = {
            ctx.repo.cargarNota(alumno!!.id, alumno.nombre, curso.id, materiaId, materiaNueva.trim(), materia, periodo, nota!!, comentario.trim())
            cargadas++
            ctx.repo.mensaje("Nota de ${alumno.nombre} guardada. Se envía cuando haya conexión.")
            // Siguiente alumno: se queda todo lo demás.
            alumnoId = 0; notaTxt = ""; comentario = ""; buscar = ""
        })
        if (cargadas > 0) Text("Guardaste $cargadas ${if (cargadas == 1) "nota" else "notas"} en esta sesión.", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.exito)
    }
}

@Composable
private fun NotasCargadas(ctx: Ctx, cursoId: Int) {
    EnLineaPantalla(clave = cursoId, cargar = { ctx.repo.enLinea.notasDelCurso(cursoId) }) { r, _ ->
        if (r.notas.isEmpty()) {
            Text("Todavía no hay notas cargadas en este curso.", color = LocalColoresApp.current.suave)
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Por seguridad, las notas de otros no se guardan en el teléfono: esto se ve solo con conexión.", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
                r.notas.groupBy { it.alumno }.forEach { (nombre, notas) ->
                    Tarjeta {
                        Text(nombre.ifBlank { "Alumno" }, fontWeight = FontWeight.Bold)
                        notas.forEach { n ->
                            Row(Modifier.padding(top = 4.dp)) {
                                Text("${n.materia} · ${n.periodo}", Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                                Text(n.nota?.let { notaTexto(it) } ?: n.letra.ifBlank { "—" }, fontWeight = FontWeight.Bold)
                            }
                        }
                    }
                }
            }
        }
    }
}
