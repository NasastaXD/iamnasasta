package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Campaign
import androidx.compose.material.icons.outlined.CalendarMonth
import androidx.compose.material.icons.outlined.Edit
import androidx.compose.material.icons.outlined.EventAvailable
import androidx.compose.material.icons.outlined.FolderOpen
import androidx.compose.material.icons.outlined.Gavel
import androidx.compose.material.icons.outlined.Groups
import androidx.compose.material.icons.outlined.HelpOutline
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.Insights
import androidx.compose.material.icons.outlined.Mail
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.outlined.PersonSearch
import androidx.compose.material.icons.outlined.Poll
import androidx.compose.material.icons.outlined.PostAdd
import androidx.compose.material.icons.outlined.School
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material.icons.outlined.SmartToy
import androidx.compose.material.icons.outlined.TaskAlt
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.Usuario
import net.caaguazu.cead.panel.ui.Avatar
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.Destino
import net.caaguazu.cead.panel.ui.FilaMenu
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.Margen
import net.caaguazu.cead.panel.ui.Marca
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.TituloSeccion

@Composable
fun MasPantalla(ctx: Ctx, usuario: Usuario) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos
    val pendientesEncuesta = d?.encuestas?.encuestas?.count { it.abierta && it.respondida != true } ?: 0

    LazyColumn(
        contentPadding = androidx.compose.foundation.layout.PaddingValues(Margen),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item {
            Tarjeta(alTocar = { ctx.nav.ir(Destino.Perfil) }) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                    Avatar(d?.misDatos?.foto ?: usuario.avatar, d?.misDatos?.iniciales ?: usuario.nombre.take(2).uppercase(), 54.dp)
                    Column(Modifier.weight(1f)) {
                        Text(usuario.nombre, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                        Text(
                            listOfNotNull(usuario.rolLabel.ifBlank { null }, usuario.curso?.titulo).joinToString(" · "),
                            style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                        )
                    }
                }
            }
        }
        item {
            Grupo("Mi colegio") {
                FilaMenu(Icons.Outlined.CalendarMonth, "Calendario", "Eventos, exámenes y feriados", { ctx.nav.ir(Destino.Calendario) })
                if (d?.boletin != null) FilaMenu(Icons.Outlined.School, "Boletín", "Tus notas por etapa", { ctx.nav.ir(Destino.Boletin) }, color = Marca.Verde)
                FilaMenu(Icons.Outlined.FolderOpen, "Recursos", "Materiales de estudio", { ctx.nav.ir(Destino.Recursos) }, color = Marca.Naranja)
                FilaMenu(
                    Icons.Outlined.Poll, "Encuestas", null, { ctx.nav.ir(Destino.Encuestas) },
                    insignia = if (pendientesEncuesta > 0) "$pendientesEncuesta nueva${if (pendientesEncuesta == 1) "" else "s"}" else null,
                    color = Marca.Violeta,
                )
                FilaMenu(Icons.Outlined.HelpOutline, "Preguntas frecuentes", null, { ctx.nav.ir(Destino.Faq) }, color = Marca.Azul)
                FilaMenu(Icons.Outlined.SmartToy, "CEADI", "Preguntale al asistente del colegio", { ctx.nav.ir(Destino.Ceadi) }, color = MaterialTheme.colorScheme.secondary)
            }
        }
        item {
            Grupo("Escribir al colegio") {
                FilaMenu(Icons.Outlined.Mail, "Enviar un mensaje", "A Dirección, Consejo o Administración", { ctx.nav.ir(Destino.Contacto) })
                FilaMenu(Icons.Outlined.Gavel, "Hacer un reporte", "Anónimo o confidencial", { ctx.nav.ir(Destino.Reportar) }, color = MaterialTheme.colorScheme.error)
                FilaMenu(Icons.Outlined.Inbox, "Mis mensajes y reportes", "Respuestas y seguimiento", { ctx.nav.ir(Destino.MisMensajes) }, color = Marca.Azul)
            }
        }

        val gestion = buildList<@Composable () -> Unit> {
            if (usuario.puede("publish_broadcast")) add { FilaMenu(Icons.Outlined.Campaign, "Publicar comunicado", null, { ctx.nav.ir(Destino.PublicarComunicado) }) }
            if (usuario.puede("manage_schedule")) add { FilaMenu(Icons.Outlined.EventAvailable, "Cargar evento", null, { ctx.nav.ir(Destino.CargarEvento) }) }
            if (usuario.puede("record_grade")) add { FilaMenu(Icons.Outlined.School, "Cargar notas", null, { ctx.nav.ir(Destino.CargarNotas) }, color = Marca.Verde) }
            if (usuario.puede("manage_articles")) add { FilaMenu(Icons.Outlined.PostAdd, "Publicar en el sitio", null, { ctx.nav.ir(Destino.PublicarArticulo) }, color = Marca.Azul) }
            if (usuario.puede("manage_reports") || usuario.puede("manage_suggestions")) add { FilaMenu(Icons.Outlined.Inbox, "Buzón", "Reportes y sugerencias", { ctx.nav.ir(Destino.Buzon) }, color = Marca.Rojo) }
            if (usuario.puede("view_metrics")) add { FilaMenu(Icons.Outlined.Insights, "Métricas", null, { ctx.nav.ir(Destino.Metricas) }, color = Marca.Violeta) }
            if (usuario.puede("manage_invitations")) add { FilaMenu(Icons.Outlined.Groups, "Invitaciones", "Links para registrar gente", { ctx.nav.ir(Destino.Invitaciones) }, color = Marca.Naranja) }
            if (usuario.puede("complete_delegate_task") || usuario.puede("assign_tasks")) add { FilaMenu(Icons.Outlined.TaskAlt, "Tareas del curso", null, { ctx.nav.ir(Destino.TareasDelCurso) }, color = Marca.Verde) }
            if (usuario.puede("assign_tasks")) add { FilaMenu(Icons.Outlined.Edit, "Asignar tarea", null, { ctx.nav.ir(Destino.AsignarTarea) }) }
            if (usuario.puede("view_delegates")) add { FilaMenu(Icons.Outlined.PersonSearch, "Delegados", "Contactos por curso", { ctx.nav.ir(Destino.Delegados) }, color = Marca.Azul) }
        }
        if (gestion.isNotEmpty()) item { Grupo("Gestión") { gestion.forEach { it() } } }

        item {
            Grupo("Cuenta") {
                FilaMenu(Icons.Outlined.Person, "Mi perfil", null, { ctx.nav.ir(Destino.Perfil) })
                FilaMenu(Icons.Outlined.Settings, "Ajustes", "Avisos, sincronización y sesiones", { ctx.nav.ir(Destino.Ajustes) }, color = LocalColoresApp.current.suave)
            }
        }
    }
}

@Composable
private fun Grupo(titulo: String, contenido: @Composable () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        TituloSeccion(titulo)
        Tarjeta(relleno = 0.dp) { Column { contenido() } }
    }
}
