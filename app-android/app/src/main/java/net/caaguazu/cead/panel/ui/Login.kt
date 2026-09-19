package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp

@Composable
fun PantallaLogin(
    entrando: Boolean,
    error: String?,
    onEntrar: (String, String) -> Unit,
) {
    var usuario by remember { mutableStateOf("") }
    var clave by remember { mutableStateOf("") }
    var verClave by remember { mutableStateOf(false) }

    val puedeEntrar = usuario.isNotBlank() && clave.isNotBlank() && !entrando

    Column(
        modifier = Modifier.fillMaxSize().padding(28.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text("CEAD", style = MaterialTheme.typography.displaySmall)
        Text(
            "Panel del colegio",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onBackground.copy(alpha = 0.7f),
        )

        Spacer(Modifier.height(32.dp))

        OutlinedTextField(
            value = usuario,
            onValueChange = { usuario = it },
            label = { Text("Usuario") },
            singleLine = true,
            enabled = !entrando,
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Text,
                imeAction = ImeAction.Next,
            ),
            modifier = Modifier.fillMaxWidth(),
        )

        Spacer(Modifier.height(12.dp))

        OutlinedTextField(
            value = clave,
            onValueChange = { clave = it },
            label = { Text("Contraseña") },
            singleLine = true,
            enabled = !entrando,
            visualTransformation = if (verClave) VisualTransformation.None else PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(
                keyboardType = KeyboardType.Password,
                imeAction = ImeAction.Done,
            ),
            keyboardActions = KeyboardActions(
                onDone = { if (puedeEntrar) onEntrar(usuario, clave) },
            ),
            trailingIcon = {
                TextButton(onClick = { verClave = !verClave }) {
                    Text(if (verClave) "Ocultar" else "Ver")
                }
            },
            modifier = Modifier.fillMaxWidth(),
        )

        if (error != null) {
            Spacer(Modifier.height(16.dp))
            Text(
                error,
                color = MaterialTheme.colorScheme.error,
                style = MaterialTheme.typography.bodyMedium,
                textAlign = TextAlign.Center,
            )
        }

        Spacer(Modifier.height(24.dp))

        Button(
            onClick = { onEntrar(usuario, clave) },
            enabled = puedeEntrar,
            modifier = Modifier.fillMaxWidth(),
        ) {
            if (entrando) {
                CircularProgressIndicator(
                    modifier = Modifier.height(18.dp),
                    strokeWidth = 2.dp,
                    color = MaterialTheme.colorScheme.onPrimary,
                )
            } else {
                Text("Entrar")
            }
        }
    }
}
