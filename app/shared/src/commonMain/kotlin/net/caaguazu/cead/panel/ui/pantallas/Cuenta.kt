package net.caaguazu.cead.panel.ui.pantallas

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CameraAlt
import androidx.compose.material.icons.outlined.Devices
import androidx.compose.material.icons.outlined.Sync
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import net.caaguazu.cead.panel.Config
import net.caaguazu.cead.panel.data.Resultado
import net.caaguazu.cead.panel.plataforma.TipoArchivo
import net.caaguazu.cead.panel.ui.Avatar
import net.caaguazu.cead.panel.ui.BotonPrincipal
import net.caaguazu.cead.panel.ui.BotonSecundario
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Cargando
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.Destino
import net.caaguazu.cead.panel.ui.DialogoConfirmar
import net.caaguazu.cead.panel.ui.EnLineaPantalla
import net.caaguazu.cead.panel.ui.FilaMenu
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PantallaScroll
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.TituloSeccion
import net.caaguazu.cead.panel.util.Fechas

@Composable
fun PerfilPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val pendientes by ctx.repo.pendientes.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val m = d.misDatos
    var nombre by remember(m.nombre) { mutableStateOf(m.nombre) }
    var telefono by remember(m.telefono) { mutableStateOf(m.telefono) }
    var confirmarSalida by remember { mutableStateOf(false) }
    val cambios = nombre.trim() != m.nombre || telefono.trim() != m.telefono

    PantallaScroll {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center) {
            Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Avatar(m.foto ?: d.perfil?.avatar, m.iniciales, 96.dp)
                TextButton(onClick = {
                    ctx.plataforma.selector.elegir(TipoArchivo.IMAGEN) { a -> if (a != null) ctx.repo.cambiarFoto(a) }
                }) {
                    Icon(Icons.Outlined.CameraAlt, null, Modifier.padding(end = 6.dp).size(18.dp))
                    Text("Cambiar foto")
                }
            }
        }
        CampoTexto(nombre, { nombre = it }, "Nombre")
        CampoTexto(
            telefono, { telefono = it }, "Teléfono", teclado = KeyboardType.Phone,
            ayuda = if (m.telefono.isNotBlank() && !m.telefonoVerificado) "Sin verificar. Se verifica desde el panel web." else null,
        )
        CampoTexto(m.email, {}, "Correo", soloLectura = true, enabled = false)
        CampoTexto(m.usuario, {}, "Usuario", soloLectura = true, enabled = false)
        d.perfil?.let { p ->
            Text(
                listOfNotNull(p.rolLabel.ifBlank { null }, p.curso?.titulo).joinToString(" · "),
                color = LocalColoresApp.current.suave, style = MaterialTheme.typography.bodyMedium,
            )
        }
        BotonPrincipal("Guardar cambios", habilitado = cambios && nombre.isNotBlank(), alTocar = {
            ctx.repo.guardarPerfil(nombre.trim(), telefono.trim())
            ctx.repo.mensaje("Cambios guardados.")
        })
        BotonSecundario("Cerrar sesión", { confirmarSalida = true })
    }

    if (confirmarSalida) {
        val sinEnviar = pendientes.size
        DialogoConfirmar(
            titulo = "¿Cerrar sesión?",
            texto = if (sinEnviar > 0) {
                "Tenés $sinEnviar ${if (sinEnviar == 1) "envío" else "envíos"} que todavía no salió. Si cerrás la sesión ahora, se pierde${if (sinEnviar == 1) "" else "n"}. " +
                    "Además se borran del teléfono tus datos guardados."
            } else {
                "Se borran del teléfono tus datos guardados. Para verlos sin conexión tendrías que entrar de nuevo con internet."
            },
            confirmar = "Cerrar sesión", peligroso = true,
            alConfirmar = { confirmarSalida = false; ctx.repo.cerrarSesionEnSegundoPlano() },
            alCancelar = { confirmarSalida = false },
        )
    }
}

private val categoriasAvisos = listOf(
    "comunicados" to "Comunicados nuevos",
    "eventos" to "Eventos nuevos",
    "tareas" to "Tareas del curso",
    "notas" to "Notas nuevas",
    "buzon" to "Buzón y respuestas",
)

@Composable
fun AjustesPantalla(ctx: Ctx) {
    val datos by ctx.repo.datos.collectAsState()
    val ultima by ctx.repo.ultimaSync.collectAsState()
    val sincronizando by ctx.repo.sincronizando.collectAsState()
    val d = datos ?: run { Cargando(); return }
    val alcance = rememberCoroutineScope()
    var resultadoPrueba by remember { mutableStateOf<String?>(null) }
    var probando by remember { mutableStateOf(false) }
    val prefs = d.preferenciasPush

    PantallaScroll {
        TituloSeccion("Sincronización")
        Tarjeta {
            Text(
                if (ultima > 0) "Última vez: ${Fechas.relativa(Fechas.instanteDesdeMilis(ultima))}" else "Todavía no se sincronizó.",
                style = MaterialTheme.typography.bodyMedium,
            )
            Text(
                "La app guarda tus datos para que los veas sin conexión (en el aula, por ejemplo). Se actualizan solos cuando hay internet.",
                style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave, modifier = Modifier.padding(vertical = 6.dp),
            )
            BotonSecundario(if (sincronizando) "Sincronizando…" else "Sincronizar ahora", { ctx.repo.pedirSync() }, habilitado = !sincronizando)
        }

        TituloSeccion("Avisos en el teléfono")
        Tarjeta {
            if (!ctx.plataforma.push.disponible) {
                Text(
                    "Los avisos todavía no están activados en esta versión de la app.",
                    style = MaterialTheme.typography.bodyMedium, color = LocalColoresApp.current.suave,
                )
            } else {
                categoriasAvisos.forEach { (clave, titulo) ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(titulo, Modifier.weight(1f))
                        Switch(checked = prefs[clave] != false, onCheckedChange = { ctx.repo.guardarPreferencias(mapOf(clave to it)) })
                    }
                }
                BotonSecundario(if (probando) "Probando…" else "Probar avisos", {
                    probando = true
                    alcance.launch {
                        val r = ctx.repo.enLinea.probarAvisos()
                        probando = false
                        resultadoPrueba = when (r) {
                            is Resultado.Ok -> when {
                                !r.valor.configurado -> "El colegio todavía no configuró los avisos en el servidor."
                                r.valor.enviados > 0 -> "Listo: mandamos un aviso a este teléfono."
                                else -> "El servidor está listo, pero no tiene registrado este teléfono. Cerrá sesión y volvé a entrar."
                            }
                            is Resultado.Fallo -> if (r.error.reintentable) "Sin conexión." else r.error.mensaje
                            Resultado.NoModificado -> null
                        }
                    }
                }, habilitado = !probando)
                resultadoPrueba?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave) }
            }
        }

        TituloSeccion("Seguridad")
        Tarjeta(relleno = 0.dp) {
            FilaMenu(Icons.Outlined.Devices, "Sesiones abiertas", "Dónde tenés la cuenta iniciada", { ctx.nav.ir(Destino.Sesiones) })
        }

        TituloSeccion("Acerca de")
        Tarjeta {
            Text("CEAD · versión ${Config.VERSION}", style = MaterialTheme.typography.bodyMedium)
            Text(Config.SITIO, style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave)
        }
    }
}

@Composable
fun SesionesPantalla(ctx: Ctx) {
    val alcance = rememberCoroutineScope()
    var aCerrar by remember { mutableStateOf<String?>(null) }

    EnLineaPantalla(cargar = { ctx.repo.enLinea.sesiones() }) { r, recargar ->
        PantallaScroll {
            Text(
                "Estos son los dispositivos donde tu cuenta está iniciada. Si no reconocés alguno, cerrá esa sesión.",
                style = MaterialTheme.typography.bodyMedium, color = LocalColoresApp.current.suave,
            )
            r.sesiones.forEach { s ->
                Tarjeta {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Column(Modifier.weight(1f)) {
                            Text(s.nombre.ifBlank { "Dispositivo" } + if (s.actual) " (este)" else "", fontWeight = FontWeight.SemiBold)
                            if (s.usado > 0) Text(
                                "Último uso: ${Fechas.relativa(Fechas.instanteDesdeMilis(s.usado * 1000))}",
                                style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                            )
                        }
                        if (!s.actual) TextButton(onClick = { aCerrar = s.id }) { Text("Cerrar", color = MaterialTheme.colorScheme.error) }
                    }
                }
            }
        }
        aCerrar?.let { id ->
            DialogoConfirmar(
                "¿Cerrar esa sesión?", "Ese dispositivo va a tener que volver a entrar con la contraseña.", "Cerrar sesión", peligroso = true,
                alConfirmar = { aCerrar = null; alcance.launch { ctx.repo.enLinea.cerrarSesionRemota(id); recargar() } },
                alCancelar = { aCerrar = null },
            )
        }
    }
}
