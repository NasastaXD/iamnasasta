@file:OptIn(ExperimentalMaterial3Api::class)

package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.NotificationsNone
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import net.caaguazu.cead.panel.data.Usuario
import net.caaguazu.cead.panel.ui.gestion.AsignarTareaPantalla
import net.caaguazu.cead.panel.ui.gestion.BuzonPantalla
import net.caaguazu.cead.panel.ui.gestion.CargarEventoPantalla
import net.caaguazu.cead.panel.ui.gestion.CargarNotasPantalla
import net.caaguazu.cead.panel.ui.gestion.DelegadosPantalla
import net.caaguazu.cead.panel.ui.gestion.InvitacionesPantalla
import net.caaguazu.cead.panel.ui.gestion.MetricasPantalla
import net.caaguazu.cead.panel.ui.gestion.PublicarArticuloPantalla
import net.caaguazu.cead.panel.ui.gestion.PublicarComunicadoPantalla
import net.caaguazu.cead.panel.ui.gestion.TareasDelCursoPantalla
import net.caaguazu.cead.panel.ui.pantallas.AjustesPantalla
import net.caaguazu.cead.panel.ui.pantallas.BoletinPantalla
import net.caaguazu.cead.panel.ui.pantallas.CalendarioPantalla
import net.caaguazu.cead.panel.ui.pantallas.CeadiPantalla
import net.caaguazu.cead.panel.ui.pantallas.ComunicadoDetalle
import net.caaguazu.cead.panel.ui.pantallas.ComunicadosPantalla
import net.caaguazu.cead.panel.ui.pantallas.ContactoPantalla
import net.caaguazu.cead.panel.ui.pantallas.EncuestaPantalla
import net.caaguazu.cead.panel.ui.pantallas.EncuestasPantalla
import net.caaguazu.cead.panel.ui.pantallas.FaqPantalla
import net.caaguazu.cead.panel.ui.pantallas.HorarioPantalla
import net.caaguazu.cead.panel.ui.pantallas.InicioPantalla
import net.caaguazu.cead.panel.ui.pantallas.MasPantalla
import net.caaguazu.cead.panel.ui.pantallas.MisMensajesPantalla
import net.caaguazu.cead.panel.ui.pantallas.NovedadesPantalla
import net.caaguazu.cead.panel.ui.pantallas.PendientesPantalla
import net.caaguazu.cead.panel.ui.pantallas.PerfilPantalla
import net.caaguazu.cead.panel.ui.pantallas.RecursosPantalla
import net.caaguazu.cead.panel.ui.pantallas.ReportarPantalla
import net.caaguazu.cead.panel.ui.pantallas.SesionesPantalla
import net.caaguazu.cead.panel.ui.pantallas.TareasPantalla

/**
 * El marco de la app: barra de arriba, pestañas de abajo, el aviso de
 * conexión, y la pantalla que toque en el medio.
 */
@Composable
fun Principal(ctx: Ctx, usuario: Usuario) {
    val tab by ctx.nav.tab.collectAsState()
    val pila by ctx.nav.pila.collectAsState()
    val destino = pila.lastOrNull()
    val snackbar = remember { SnackbarHostState() }
    val sincronizando by ctx.repo.sincronizando.collectAsState()

    // Un mensaje de una sola vez (un envío rechazado, un error de red).
    LaunchedEffect(Unit) { ctx.repo.avisos.collect { snackbar.showSnackbar(it) } }
    // iOS y Android 13+ piden permiso para mostrar avisos; se pide una vez, ya adentro.
    LaunchedEffect(usuario.id) { ctx.plataforma.push.pedirPermiso() }

    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = {
            TopAppBar(
                title = { Text(tituloDe(destino, tab, usuario), fontWeight = FontWeight.Bold) },
                navigationIcon = {
                    if (destino != null) IconButton(onClick = { ctx.nav.volver() }) { IconoVolver() }
                },
                actions = {
                    if (sincronizando) {
                        Box(Modifier.padding(end = 6.dp)) { CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) }
                    }
                    if (destino == null) {
                        IconButton(onClick = { ctx.nav.ir(Destino.Novedades) }) {
                            Icon(Icons.Outlined.NotificationsNone, "Novedades")
                        }
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.colorScheme.background),
            )
        },
        bottomBar = {
            if (destino == null) {
                NavigationBar(containerColor = MaterialTheme.colorScheme.surface) {
                    Tab.entries.forEach { t ->
                        NavigationBarItem(
                            selected = t == tab,
                            onClick = { ctx.nav.elegirTab(t) },
                            icon = { Icon(t.icono, t.titulo) },
                            label = { Text(t.titulo, maxLines = 1) },
                        )
                    }
                }
            }
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        Column(Modifier.padding(padding)) {
            BannerEstado(ctx.repo) { ctx.nav.ir(Destino.Pendientes) }
            Box(Modifier.weight(1f)) { Cuerpo(ctx, usuario, tab, destino) }
        }
    }
}

private fun tituloDe(destino: Destino?, tab: Tab, usuario: Usuario): String = when (destino) {
    null -> when (tab) {
        Tab.INICIO -> "Hola, ${usuario.nombre.substringBefore(' ')}"
        Tab.COMUNICADOS -> "Comunicados"
        else -> tab.titulo
    }
    is Destino.Comunicado -> "Comunicado"
    Destino.Calendario -> "Calendario"
    Destino.Boletin -> "Boletín"
    Destino.Recursos -> "Recursos"
    Destino.Encuestas -> "Encuestas"
    is Destino.ResponderEncuesta -> "Encuesta"
    Destino.Faq -> "Preguntas frecuentes"
    Destino.Ceadi -> "CEADI"
    Destino.Novedades -> "Novedades"
    Destino.Perfil -> "Mi perfil"
    Destino.Ajustes -> "Ajustes"
    Destino.Sesiones -> "Sesiones abiertas"
    Destino.Contacto -> "Escribir al colegio"
    Destino.Reportar -> "Hacer un reporte"
    Destino.MisMensajes -> "Mis mensajes"
    Destino.Pendientes -> "Envíos pendientes"
    Destino.PublicarComunicado -> "Publicar comunicado"
    Destino.CargarEvento -> "Cargar evento"
    Destino.CargarNotas -> "Cargar notas"
    Destino.Buzon -> "Buzón"
    Destino.Metricas -> "Métricas"
    Destino.Invitaciones -> "Invitaciones"
    Destino.PublicarArticulo -> "Publicar en el sitio"
    Destino.TareasDelCurso -> "Tareas del curso"
    Destino.AsignarTarea -> "Asignar tarea"
    Destino.Delegados -> "Delegados"
}

@Composable
private fun Cuerpo(ctx: Ctx, usuario: Usuario, tab: Tab, destino: Destino?) {
    when (destino) {
        null -> when (tab) {
            Tab.INICIO -> InicioPantalla(ctx, usuario)
            Tab.HORARIO -> HorarioPantalla(ctx)
            Tab.COMUNICADOS -> ComunicadosPantalla(ctx)
            Tab.TAREAS -> TareasPantalla(ctx)
            Tab.MAS -> MasPantalla(ctx, usuario)
        }
        is Destino.Comunicado -> ComunicadoDetalle(ctx, destino.id)
        Destino.Calendario -> CalendarioPantalla(ctx)
        Destino.Boletin -> BoletinPantalla(ctx)
        Destino.Recursos -> RecursosPantalla(ctx)
        Destino.Encuestas -> EncuestasPantalla(ctx)
        is Destino.ResponderEncuesta -> EncuestaPantalla(ctx, destino.id)
        Destino.Faq -> FaqPantalla(ctx)
        Destino.Ceadi -> CeadiPantalla(ctx)
        Destino.Novedades -> NovedadesPantalla(ctx)
        Destino.Perfil -> PerfilPantalla(ctx)
        Destino.Ajustes -> AjustesPantalla(ctx)
        Destino.Sesiones -> SesionesPantalla(ctx)
        Destino.Contacto -> ContactoPantalla(ctx)
        Destino.Reportar -> ReportarPantalla(ctx)
        Destino.MisMensajes -> MisMensajesPantalla(ctx)
        Destino.Pendientes -> PendientesPantalla(ctx)
        Destino.PublicarComunicado -> PublicarComunicadoPantalla(ctx)
        Destino.CargarEvento -> CargarEventoPantalla(ctx)
        Destino.CargarNotas -> CargarNotasPantalla(ctx)
        Destino.Buzon -> BuzonPantalla(ctx)
        Destino.Metricas -> MetricasPantalla(ctx)
        Destino.Invitaciones -> InvitacionesPantalla(ctx)
        Destino.PublicarArticulo -> PublicarArticuloPantalla(ctx)
        Destino.TareasDelCurso -> TareasDelCursoPantalla(ctx)
        Destino.AsignarTarea -> AsignarTareaPantalla(ctx)
        Destino.Delegados -> DelegadosPantalla(ctx)
    }
}
