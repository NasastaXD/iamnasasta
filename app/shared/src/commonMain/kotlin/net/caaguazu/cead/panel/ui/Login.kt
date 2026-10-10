package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Visibility
import androidx.compose.material.icons.outlined.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.launch
import net.caaguazu.cead.panel.data.Repositorio
import net.caaguazu.cead.panel.data.TipoError

@Composable
fun LoginPantalla(repo: Repositorio, motivo: String? = null) {
    var usuario by remember { mutableStateOf("") }
    var clave by remember { mutableStateOf("") }
    var verClave by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var entrando by remember { mutableStateOf(false) }
    val alcance = rememberCoroutineScope()

    fun entrar() {
        if (entrando || usuario.isBlank() || clave.isEmpty()) return
        entrando = true
        error = null
        alcance.launch {
            val e = repo.iniciarSesion(usuario, clave)
            entrando = false
            if (e != null) {
                error = if (e.tipo == TipoError.SIN_CONEXION) {
                    "Para entrar por primera vez hace falta conexión a internet."
                } else {
                    e.mensaje
                }
            }
        }
    }

    Box(Modifier.fillMaxSize().safeDrawingPadding().imePadding()) {
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 24.dp, vertical = 32.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(14.dp, Alignment.CenterVertically),
        ) {
            Box(
                Modifier.size(72.dp).clip(RoundedCornerShape(20.dp)).background(Marca.Rojo),
                contentAlignment = Alignment.Center,
            ) { Text("C", color = Color.White, fontSize = 38.sp, fontWeight = FontWeight.Black) }

            Text("CEAD", fontSize = 30.sp, fontWeight = FontWeight.Black)
            Text(
                "Félix de Guarania",
                color = LocalColoresApp.current.suave,
                style = MaterialTheme.typography.titleMedium,
                textAlign = TextAlign.Center,
            )
            Box(Modifier.height(10.dp))

            if (motivo != null) Aviso(motivo)

            CampoTexto(usuario, { usuario = it.trim() }, "Usuario o correo", enabled = !entrando)
            CampoTexto(
                clave, { clave = it }, "Contraseña", enabled = !entrando, teclado = KeyboardType.Password,
                transformacion = if (verClave) VisualTransformation.None else PasswordVisualTransformation(),
                trailing = {
                    IconButton(onClick = { verClave = !verClave }) {
                        Icon(if (verClave) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility, if (verClave) "Ocultar" else "Mostrar")
                    }
                },
            )
            if (error != null) Aviso(error!!, esError = true)

            BotonPrincipal("Entrar", alTocar = ::entrar, habilitado = usuario.isNotBlank() && clave.isNotEmpty(), trabajando = entrando)

            Text(
                "Es la misma cuenta del panel del colegio. Una vez adentro, la app guarda lo que necesitás y funciona sin conexión.",
                color = LocalColoresApp.current.suave,
                style = MaterialTheme.typography.bodySmall,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth().padding(top = 6.dp),
            )
        }
    }
}
