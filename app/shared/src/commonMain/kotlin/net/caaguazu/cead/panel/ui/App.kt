package net.caaguazu.cead.panel.ui

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import coil3.ImageLoader
import coil3.compose.setSingletonImageLoaderFactory
import coil3.disk.DiskCache
import coil3.network.ktor3.KtorNetworkFetcherFactory
import net.caaguazu.cead.panel.data.EstadoSesion
import net.caaguazu.cead.panel.data.Repositorio
import net.caaguazu.cead.panel.plataforma.Plataforma
import okio.Path.Companion.toPath

/** Lo que cada pantalla necesita para andar: los datos, dónde está parada la persona, y el sistema. */
class Ctx(
    val repo: Repositorio,
    val nav: Navegacion,
    val plataforma: Plataforma,
)

/**
 * La app entera: entra por acá en Android y en iOS.
 *
 * Qué se ve depende solo de la sesión: sin ella, el login; con ella, la app.
 * Las pantallas no preguntan nada al servidor al abrirse: leen lo guardado en
 * el teléfono (ver [Repositorio]).
 */
@Composable
fun App(
    plataforma: Plataforma,
    repo: Repositorio,
    nav: Navegacion = remember { Navegacion() },
    /** Null = el del sistema. Se puede forzar para las capturas de prueba. */
    oscuro: Boolean? = null,
) {
    // Las imágenes (fotos de comunicados, de perfil) se guardan en disco: lo que
    // ya se vio se sigue viendo sin conexión.
    setSingletonImageLoaderFactory { contexto ->
        ImageLoader.Builder(contexto)
            .components { add(KtorNetworkFetcherFactory()) }
            .diskCache {
                DiskCache.Builder()
                    .directory((plataforma.dirCache + "/imagenes").toPath())
                    .maxSizeBytes(100L * 1024 * 1024)
                    .build()
            }
            .build()
    }

    val ctx = remember(repo, nav, plataforma) { Ctx(repo, nav, plataforma) }
    val sesion by repo.sesion.collectAsState()

    TemaCead(oscuro ?: androidx.compose.foundation.isSystemInDarkTheme()) {
        Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            when (val s = sesion) {
                EstadoSesion.Cargando -> Box(Modifier.fillMaxSize(), Alignment.Center) { CircularProgressIndicator() }
                is EstadoSesion.SinSesion -> LoginPantalla(repo, s.motivo)
                is EstadoSesion.Activa -> Principal(ctx, s.usuario)
            }
        }
    }
}
