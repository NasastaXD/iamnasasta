package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.foundation.layout.Row
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.PostAdd
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import net.caaguazu.cead.panel.plataforma.ArchivoElegido
import net.caaguazu.cead.panel.ui.BotonPrincipal
import net.caaguazu.cead.panel.ui.CampoFechaHora
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PantallaScroll

/** Una nota en el sitio del colegio (caaguazu.net), con su maqueta y, si el director/a lo pide, en las redes. */
@Composable
fun PublicarArticuloPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val opc = d.gestion.articulos
    if (opc == null) {
        EstadoVacio(Icons.Outlined.PostAdd, "Todavía no se bajaron las opciones", "Conectate a internet un momento para que se guarden.")
        return
    }

    var titulo by remember { mutableStateOf("") }
    var contenido by remember { mutableStateOf("") }
    var categoria by remember { mutableIntStateOf(0) }
    var formato by remember { mutableStateOf("") }
    var fecha by remember { mutableStateOf("") }
    var lugar by remember { mutableStateOf("") }
    var redes by remember { mutableStateOf(false) }
    var imagen by remember { mutableStateOf<ArchivoElegido?>(null) }

    val fmt = opc.formatos.firstOrNull { it.slug == formato }
    val pideFecha = fmt?.pide?.contains("fecha") == true
    val pideLugar = fmt?.pide?.contains("lugar") == true
    val listo = titulo.isNotBlank() && contenido.isNotBlank() && (!pideFecha || fecha.isNotBlank())

    PantallaScroll {
        Text(AVISO_SIN_SENAL, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        CampoTexto(titulo, { titulo = it }, "Título")
        CampoTexto(contenido, { contenido = it }, "Contenido", lineas = 8, maxLineas = 30, ayuda = "Podés usar Markdown: **negrita**, listas con -, títulos con ##.")
        if (opc.categorias.isNotEmpty()) {
            Opciones("Categoría", opc.categorias, { it.id == categoria }, { it.nombre }, { categoria = if (categoria == it.id) 0 else it.id })
        }
        if (opc.formatos.isNotEmpty()) {
            Opciones("Maqueta", opc.formatos, { it.slug == formato }, { it.nombre }, { formato = if (formato == it.slug) "" else it.slug })
        }
        if (pideFecha) CampoFechaHora(fecha, { fecha = it }, "Fecha del evento")
        if (pideLugar) CampoTexto(lugar, { lugar = it }, "Lugar")
        CampoArchivo(ctx.plataforma, imagen, { imagen = it }, "Agregar imagen destacada")
        if (opc.redes) FilaInterruptor("Publicar también en las redes del colegio", redes, { redes = it })
        BotonPrincipal("Publicar", habilitado = listo, alTocar = {
            // El servidor espera la fecha como `AAAA-MM-DDTHH:MM`; el selector ya la da así.
            ctx.repo.publicarArticulo(titulo.trim(), contenido.trim(), categoria, formato, fecha, lugar.trim(), redes && opc.redes, imagen)
            ctx.repo.mensaje("Nota guardada. Se publica cuando haya conexión.")
            ctx.nav.volver()
        })
    }
}
