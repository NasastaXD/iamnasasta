package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import net.caaguazu.cead.panel.data.EstadoReporte
import net.caaguazu.cead.panel.data.MensajePropio
import net.caaguazu.cead.panel.data.ReportePropio
import net.caaguazu.cead.panel.data.Resultado
import net.caaguazu.cead.panel.ui.Aviso
import net.caaguazu.cead.panel.ui.BotonPrincipal
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.Destino
import net.caaguazu.cead.panel.ui.DialogoConfirmar
import net.caaguazu.cead.panel.ui.EstadoVacio
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PantallaScroll
import net.caaguazu.cead.panel.ui.Refrescable
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.TituloSeccion
import net.caaguazu.cead.panel.util.Fechas

fun nombreDestinatario(clave: String) = when (clave) {
    "direccion" -> "Dirección"
    "consejo" -> "Consejo Estudiantil"
    "administracion" -> "Administración / Secretaría"
    else -> clave
}

fun nombreEstado(estado: String) = when (estado) {
    "new" -> "Recibido"
    "in_review" -> "En revisión"
    "accepted" -> "Aceptado"
    "denied" -> "Rechazado"
    "resolved" -> "Resuelto"
    "not_report" -> "Cerrado"
    else -> estado
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ContactoPantalla(ctx: Ctx) {
    var para by remember { mutableStateOf("direccion") }
    var mensaje by remember { mutableStateOf("") }

    PantallaScroll {
        Text(
            "Tu mensaje llega al buzón del colegio y te responden por acá. Podés escribir sin conexión: sale cuando haya internet.",
            style = MaterialTheme.typography.bodyMedium, color = LocalColoresApp.current.suave,
        )
        TituloSeccion("Para")
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("direccion", "consejo", "administracion").forEach {
                FilterChip(selected = para == it, onClick = { para = it }, label = { Text(nombreDestinatario(it)) })
            }
        }
        CampoTexto(mensaje, { mensaje = it }, "Tu mensaje", lineas = 6, maxLineas = 12)
        BotonPrincipal("Enviar mensaje", habilitado = mensaje.isNotBlank(), alTocar = {
            ctx.repo.enviarMensaje(para, mensaje.trim())
            ctx.repo.mensaje("Mensaje guardado. Se envía cuando haya conexión.")
            ctx.nav.reemplazar(Destino.MisMensajes)
        })
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ReportarPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val categorias = datos?.categoriasReporte.orEmpty().ifEmpty { listOf("Otro") }
    var tipo by remember { mutableStateOf("anonimo") }
    var categoria by remember(categorias) { mutableStateOf(categorias.last()) }
    var texto by remember { mutableStateOf("") }
    var confirmar by remember { mutableStateOf(false) }

    PantallaScroll {
        Aviso("Si estás en peligro o alguien lo está, avisá a un adulto de confianza ahora mismo. Este reporte lo lee el colegio, pero no es una línea de emergencia.")
        TituloSeccion("¿Cómo querés reportar?")
        Tarjeta(alTocar = { tipo = "anonimo" }, acento = if (tipo == "anonimo") MaterialTheme.colorScheme.primary else null) {
            Text("Anónimo", fontWeight = FontWeight.Bold)
            Text(
                "El colegio no sabe quién sos. Vas a recibir un código para seguir el reporte, pero nadie te puede contestar por acá.",
                style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
            )
        }
        Tarjeta(alTocar = { tipo = "confidencial" }, acento = if (tipo == "confidencial") MaterialTheme.colorScheme.primary else null) {
            Text("Confidencial", fontWeight = FontWeight.Bold)
            Text(
                "Dirección ve tu nombre y puede contestarte, pero no lo comparte con nadie más.",
                style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
            )
        }
        TituloSeccion("Tema")
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            categorias.forEach { FilterChip(selected = categoria == it, onClick = { categoria = it }, label = { Text(it) }) }
        }
        CampoTexto(texto, { texto = it }, "Contá qué pasó", lineas = 6, maxLineas = 14)
        BotonPrincipal("Enviar reporte", habilitado = texto.isNotBlank(), alTocar = { confirmar = true })
    }

    if (confirmar) {
        DialogoConfirmar(
            titulo = "¿Enviar el reporte ${if (tipo == "anonimo") "anónimo" else "confidencial"}?",
            texto = if (tipo == "anonimo") "Cuando salga vas a ver el código para seguirlo en «Mis mensajes y reportes». Guardalo: es la única forma de saber cómo va."
            else "El colegio va a ver tu nombre.",
            confirmar = "Enviar",
            alConfirmar = {
                confirmar = false
                ctx.repo.enviarReporte(tipo, categoria, texto.trim())
                ctx.repo.mensaje("Reporte guardado. Sale cuando haya conexión.")
                ctx.nav.reemplazar(Destino.MisMensajes)
            },
            alCancelar = { confirmar = false },
        )
    }
}

@Composable
fun MisMensajesPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val locales by ctx.repo.locales.collectAsState()
    val pendientes by ctx.repo.pendientes.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val alcance = rememberCoroutineScope()
    var codigo by remember { mutableStateOf("") }
    var consulta by remember { mutableStateOf<String?>(null) }
    var estados by remember { mutableStateOf<Map<String, EstadoReporte>>(emptyMap()) }

    // Los reportes: los que el servidor sabe míos (confidenciales) y los códigos que guardé yo (los anónimos).
    val delServidor = d.misMensajes.reportes.associateBy { it.codigo }
    val codigos = (locales.reportes.map { it.codigo } + delServidor.keys).distinct()
    val enEspera = pendientes.filter { it.tipo == "reporte" || it.tipo == "contacto" }

    Refrescable(ctx.repo) {
        PantallaScroll {
            if (enEspera.isNotEmpty()) {
                TituloSeccion("Esperando salir")
                enEspera.forEach { e ->
                    Tarjeta { Text(e.titulo, fontWeight = FontWeight.SemiBold); Text(if (e.fallido) e.error.orEmpty() else "Se envía cuando haya conexión.", style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave) }
                }
            }

            TituloSeccion("Mis mensajes")
            if (d.misMensajes.mensajes.isEmpty()) Text("Todavía no escribiste al colegio.", color = LocalColoresApp.current.suave)
            d.misMensajes.mensajes.forEach { m -> MensajeFila(m) }

            TituloSeccion("Mis reportes")
            if (codigos.isEmpty()) Text("No hiciste ningún reporte desde este teléfono.", color = LocalColoresApp.current.suave)
            codigos.forEach { c ->
                val s = delServidor[c]
                val remoto = estados[c]
                ReporteFila(
                    codigo = c, categoria = s?.categoria ?: locales.reportes.firstOrNull { it.codigo == c }?.categoria.orEmpty(),
                    estado = remoto?.estado ?: s?.estado, respuesta = remoto?.respuesta ?: s?.respuesta.orEmpty(),
                    alVerEstado = {
                        alcance.launch {
                            when (val r = ctx.repo.enLinea.estadoReporte(c)) {
                                is Resultado.Ok -> estados = estados + (c to r.valor)
                                is Resultado.Fallo -> ctx.repo.mensaje(if (r.error.reintentable) "Sin conexión." else r.error.mensaje)
                                Resultado.NoModificado -> Unit
                            }
                        }
                    },
                )
            }

            TituloSeccion("Seguir un reporte por código")
            CampoTexto(codigo, { codigo = it.uppercase().trim() }, "Código (RPT-XXXXXX)", ayuda = "El código te lo dieron al enviarlo.")
            BotonPrincipal("Ver estado", habilitado = codigo.length >= 8, alTocar = {
                consulta = codigo
                alcance.launch {
                    when (val r = ctx.repo.enLinea.estadoReporte(codigo)) {
                        is Resultado.Ok -> estados = estados + (codigo to r.valor)
                        is Resultado.Fallo -> ctx.repo.mensaje(if (r.error.reintentable) "Sin conexión." else r.error.mensaje)
                        Resultado.NoModificado -> Unit
                    }
                }
            })
            consulta?.let { c -> estados[c]?.let { ReporteFila(c, "", it.estado, it.respuesta, null) } }
        }
    }
}

@Composable
private fun MensajeFila(m: MensajePropio) {
    Tarjeta {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            Text("Para ${nombreDestinatario(m.para)}", Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
            Etiqueta(nombreEstado(m.estado), if (m.estado == "new") LocalColoresApp.current.suave else MaterialTheme.colorScheme.primary)
        }
        if (m.texto.isNotBlank()) Text(m.texto, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 4.dp))
        if (m.respuesta.isNotBlank()) {
            Text("Respuesta", style = MaterialTheme.typography.labelMedium, color = LocalColoresApp.current.suave, modifier = Modifier.padding(top = 8.dp))
            Text(m.respuesta, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

@Composable
private fun ReporteFila(codigo: String, categoria: String, estado: String?, respuesta: String, alVerEstado: (() -> Unit)?) {
    Tarjeta {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(codigo, fontWeight = FontWeight.Bold)
                if (categoria.isNotBlank()) Text(categoria, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
            }
            if (estado != null) Etiqueta(nombreEstado(estado))
        }
        if (respuesta.isNotBlank()) {
            Text("Respuesta", style = MaterialTheme.typography.labelMedium, color = LocalColoresApp.current.suave, modifier = Modifier.padding(top = 8.dp))
            Text(respuesta, style = MaterialTheme.typography.bodyMedium)
        }
        if (alVerEstado != null) TextButton(onClick = alVerEstado) { Text("Ver estado") }
    }
}
