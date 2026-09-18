package py.edu.cead.panel

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.Campaign
import androidx.compose.material.icons.filled.Checklist
import androidx.compose.material.icons.filled.Grading
import androidx.compose.material.icons.filled.Logout
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.fromHtml
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import py.edu.cead.panel.data.Comunicados
import py.edu.cead.panel.ui.PantallaBoletin
import py.edu.cead.panel.ui.PantallaComunicados
import py.edu.cead.panel.ui.PantallaHorario
import py.edu.cead.panel.ui.PantallaLogin
import py.edu.cead.panel.ui.PantallaTareas
import py.edu.cead.panel.ui.Seccion
import py.edu.cead.panel.ui.TemaCead

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            TemaCead {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    App()
                }
            }
        }
    }
}

private enum class Tab(val titulo: String, val icono: ImageVector) {
    Horario("Horario", Icons.Filled.CalendarMonth),
    Comunicados("Comunicados", Icons.Filled.Campaign),
    Boletin("Boletín", Icons.Filled.Grading),
    Tareas("Tareas", Icons.Filled.Checklist),
}

@Composable
private fun App(vm: PanelViewModel = viewModel()) {
    val sesionAbierta by vm.sesionAbierta.collectAsState()
    val entrando by vm.entrando.collectAsState()
    val errorLogin by vm.errorLogin.collectAsState()

    if (!sesionAbierta) {
        PantallaLogin(
            entrando = entrando,
            error = errorLogin,
            onEntrar = vm::entrar,
        )
        return
    }

    Panel(vm)
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun Panel(vm: PanelViewModel) {
    val estado by vm.estado.collectAsState()
    var tab by remember { mutableStateOf(Tab.Horario) }

    val abierto = estado.abierto
    if (abierto != null) {
        Scaffold(
            topBar = {
                TopAppBar(
                    title = { Text(abierto.titulo, maxLines = 1) },
                    navigationIcon = {
                        IconButton(onClick = vm::cerrarComunicado) {
                            Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Volver")
                        }
                    },
                )
            },
        ) { pad ->
            Column(
                Modifier.padding(pad).padding(16.dp).verticalScroll(rememberScrollState()),
            ) {
                // El contenido viene como HTML del editor de WordPress. Se
                // convierte a texto con formato en vez de mostrar las etiquetas
                // crudas, y sin WebView: no hace falta un navegador entero para
                // leer un comunicado.
                Text(AnnotatedString.fromHtml(abierto.contenido ?: abierto.resumen))
            }
        }
        return
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(tab.titulo)
                        val quien = estado.perfil?.nombre ?: vm.nombreRecordado
                        if (quien != null) {
                            Text(quien, style = MaterialTheme.typography.labelSmall)
                        }
                    }
                },
                actions = {
                    IconButton(onClick = vm::salir) {
                        Icon(Icons.Filled.Logout, contentDescription = "Salir")
                    }
                },
            )
        },
        bottomBar = {
            NavigationBar {
                Tab.entries.forEach { t ->
                    NavigationBarItem(
                        selected = tab == t,
                        onClick = { tab = t },
                        label = { Text(t.titulo) },
                        icon = {
                            if (t == Tab.Comunicados) {
                                BadgedBox(badge = {
                                    val n = (estado.comunicados as? Carga.Listo<Comunicados>)?.datos?.sinLeer ?: 0
                                    if (n > 0) Badge { Text(n.toString()) }
                                }) { Icon(t.icono, contentDescription = t.titulo) }
                            } else {
                                Icon(t.icono, contentDescription = t.titulo)
                            }
                        },
                    )
                }
            }
        },
    ) { pad ->
        Column(Modifier.padding(pad)) {
            when (tab) {
                Tab.Horario -> Seccion(estado.horario, vm::cargarHorario) { PantallaHorario(it) }
                Tab.Comunicados -> Seccion(estado.comunicados, vm::cargarComunicados) {
                    PantallaComunicados(it, vm::abrir)
                }
                Tab.Boletin -> Seccion(estado.boletin, vm::cargarBoletin) { PantallaBoletin(it) }
                Tab.Tareas -> Seccion(estado.tareas, vm::cargarTareas) { PantallaTareas(it) }
            }
        }
    }
}
