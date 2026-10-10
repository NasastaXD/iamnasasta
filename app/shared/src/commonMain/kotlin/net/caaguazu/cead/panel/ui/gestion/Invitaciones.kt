@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package net.caaguazu.cead.panel.ui.gestion

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ContentCopy
import androidx.compose.material.icons.outlined.Share
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
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
import net.caaguazu.cead.panel.data.Invitacion
import net.caaguazu.cead.panel.data.Resultado
import net.caaguazu.cead.panel.ui.BotonPrincipal
import net.caaguazu.cead.panel.ui.CampoTexto
import net.caaguazu.cead.panel.ui.Ctx
import net.caaguazu.cead.panel.ui.DialogoConfirmar
import net.caaguazu.cead.panel.ui.EnLineaPantalla
import net.caaguazu.cead.panel.ui.Etiqueta
import net.caaguazu.cead.panel.ui.LocalColoresApp
import net.caaguazu.cead.panel.ui.PantallaScroll
import net.caaguazu.cead.panel.ui.Tarjeta
import net.caaguazu.cead.panel.ui.TituloSeccion

fun estadoInvitacion(estado: String) = when (estado) {
    "valid" -> "Vigente"
    "used" -> "Agotada"
    "expired" -> "Vencida"
    "revoked" -> "Revocada"
    else -> estado
}

/** Los links para que alguien se registre. Con conexión: el link lo genera el servidor. */
@Composable
fun InvitacionesPantalla(ctx: Ctx) {
    val alcance = rememberCoroutineScope()
    var rol by remember { mutableStateOf("") }
    var usos by remember { mutableStateOf("1") }
    var email by remember { mutableStateOf("") }
    var creando by remember { mutableStateOf(false) }
    var ultimoLink by remember { mutableStateOf<String?>(null) }
    var aRevocar by remember { mutableStateOf<Invitacion?>(null) }

    EnLineaPantalla(cargar = { ctx.repo.enLinea.invitaciones() }) { inv, recargar ->
        PantallaScroll {
            TituloSeccion("Nueva invitación")
            Opciones("Para quién", inv.roles, { it.valor == rol }, { it.nombre }, { rol = it.valor })
            CampoTexto(usos, { usos = it.filter(Char::isDigit).take(4) }, "Cuántas personas pueden usar el link", teclado = KeyboardType.Number)
            CampoTexto(email, { email = it.trim() }, "Correo (opcional, se le manda el link)", teclado = KeyboardType.Email)
            BotonPrincipal("Crear invitación", habilitado = rol.isNotBlank() && (usos.toIntOrNull() ?: 0) >= 1, trabajando = creando, alTocar = {
                creando = true
                alcance.launch {
                    val r = ctx.repo.enLinea.invitar(rol, usos.toIntOrNull() ?: 1, 0, email)
                    creando = false
                    when (r) {
                        is Resultado.Ok -> { ultimoLink = r.valor.invitacion.link; email = ""; recargar() }
                        is Resultado.Fallo -> ctx.repo.mensaje(if (r.error.reintentable) "Sin conexión: hace falta internet para crear el link." else r.error.mensaje)
                        Resultado.NoModificado -> Unit
                    }
                }
            })
            ultimoLink?.let { LinkTarjeta(ctx, it) }

            TituloSeccion("Últimas invitaciones")
            if (inv.invitaciones.isEmpty()) Text("Todavía no hay invitaciones.", color = LocalColoresApp.current.suave)
            inv.invitaciones.forEach { i ->
                Tarjeta {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(i.rolLabel.ifBlank { i.rol }, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        Etiqueta(estadoInvitacion(i.estado), if (i.estado == "valid") LocalColoresApp.current.exito else LocalColoresApp.current.suave)
                    }
                    Text(
                        listOfNotNull(i.curso?.titulo, i.email, "${i.restantes} de ${i.usos} usos").joinToString(" · "),
                        style = MaterialTheme.typography.bodySmall, color = LocalColoresApp.current.suave,
                    )
                    if (i.link != null) {
                        Row {
                            TextButton(onClick = { ctx.plataforma.copiar(i.link); ctx.repo.mensaje("Link copiado.") }) { Text("Copiar link") }
                            TextButton(onClick = { ctx.plataforma.compartir(i.link) }) { Text("Compartir") }
                            TextButton(onClick = { aRevocar = i }) { Text("Revocar", color = MaterialTheme.colorScheme.error) }
                        }
                    }
                }
            }
        }
        aRevocar?.let { i ->
            DialogoConfirmar(
                "¿Revocar esta invitación?", "El link deja de servir y nadie más puede registrarse con él.", "Revocar", peligroso = true,
                alConfirmar = { aRevocar = null; alcance.launch { ctx.repo.enLinea.revocarInvitacion(i.id); recargar() } },
                alCancelar = { aRevocar = null },
            )
        }
    }
}

@Composable
private fun LinkTarjeta(ctx: Ctx, link: String) {
    Tarjeta(acento = LocalColoresApp.current.exito) {
        Text("Invitación creada", fontWeight = FontWeight.Bold)
        Text(link, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(vertical = 6.dp))
        Row {
            IconButton(onClick = { ctx.plataforma.copiar(link); ctx.repo.mensaje("Link copiado.") }) { Icon(Icons.Outlined.ContentCopy, "Copiar") }
            IconButton(onClick = { ctx.plataforma.compartir(link) }) { Icon(Icons.Outlined.Share, "Compartir") }
        }
    }
}
