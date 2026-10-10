package net.caaguazu.cead.panel

import androidx.compose.ui.window.ComposeUIViewController
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import net.caaguazu.cead.panel.data.Repositorio
import net.caaguazu.cead.panel.plataforma.PlataformaIos
import net.caaguazu.cead.panel.plataforma.PuenteAvisosIos
import net.caaguazu.cead.panel.ui.App
import net.caaguazu.cead.panel.ui.Navegacion
import okio.FileSystem
import platform.UIKit.UIViewController

/**
 * Lo que vive tanto como el proceso: el repositorio (la sesión y lo que espera
 * salir) y dónde está parada la persona. Se crea la primera vez que se pide.
 */
private object Proceso {
    val plataforma = PlataformaIos()
    val repo = Repositorio(plataforma, CoroutineScope(SupervisorJob() + Dispatchers.Default), sistema = FileSystem.SYSTEM)
    val nav = Navegacion().also { nav ->
        PuenteAvisosIos.alTocarAviso { tipo, id -> nav.abrirDesdeAviso(tipo, id) }
    }
}

/** Lo que Swift muestra: toda la app dibujada con Compose. */
fun MainViewController(): UIViewController = ComposeUIViewController {
    App(Proceso.plataforma, Proceso.repo, Proceso.nav)
}

/**
 * Swift lo llama cuando la app vuelve a primer plano: si hace rato que no
 * sincroniza, lo hace (ver [Repositorio.alPrimerPlano]).
 */
fun alVolverAlPrimerPlano() {
    Proceso.repo.alPrimerPlano()
}
