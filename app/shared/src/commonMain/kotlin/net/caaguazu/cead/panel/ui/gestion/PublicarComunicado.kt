package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import net.caaguazu.cead.panel.data.AudienciaElegida
import net.caaguazu.cead.panel.plataforma.ArchivoElegido
import net.caaguazu.cead.panel.ui.BotonPrincipal
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.PantallaScroll

@Composable
fun PublicarComunicadoPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val opciones = d.gestion.audienciasComunicado

    var titulo by remember { mutableStateOf("") }
    var texto by remember { mutableStateOf("") }
    var audiencias by remember { mutableStateOf<List<AudienciaElegida>>(emptyList()) }
    var categoria by remember { mutableStateOf("") }
    var avisarEmail by remember { mutableStateOf(false) }
    var imagen by remember { mutableStateOf<ArchivoElegido?>(null) }

    val listo = texto.isNotBlank() && audiencias.isNotEmpty()

    PantallaScroll {
        Text(AVISO_SIN_SENAL, style = androidx.compose.material3.MaterialTheme.typography.bodySmall)
        CampoTexto(titulo, { titulo = it }, "Título (opcional)", ayuda = "Si lo dejás vacío, se arma con el comienzo del texto.")
        CampoTexto(texto, { texto = it }, "Texto del comunicado", lineas = 6, maxLineas = 20, ayuda = "Podés usar *negrita* con asteriscos.")
        SelectorAudiencias(opciones, audiencias) { audiencias = it }
        val categorias = opciones?.categorias.orEmpty()
        if (categorias.isNotEmpty()) {
            Opciones("Categoría", categorias, { it.valor == categoria }, { it.nombre }, { categoria = if (categoria == it.valor) "" else it.valor })
        }
        CampoArchivo(ctx.plataforma, imagen, { imagen = it })
        FilaInterruptor("Avisar también por email", avisarEmail, { avisarEmail = it })
        BotonPrincipal("Publicar", habilitado = listo, alTocar = {
            ctx.repo.publicarComunicado(titulo.trim(), texto.trim(), audiencias, categoria, avisarEmail, imagen)
            ctx.repo.mensaje("Comunicado guardado. Sale cuando haya conexión.")
            ctx.nav.volver()
        })
    }
}
