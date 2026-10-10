package net.caaguazu.cead.panel.data

import io.ktor.client.engine.mock.MockRequestHandleScope
import io.ktor.client.engine.mock.respond
import io.ktor.client.request.HttpRequestData
import io.ktor.client.request.HttpResponseData
import io.ktor.http.HttpMethod
import io.ktor.http.HttpStatusCode
import io.ktor.http.content.TextContent
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.UnconfinedTestDispatcher
import kotlinx.coroutines.test.runTest
import net.caaguazu.cead.panel.LOGIN_OK
import net.caaguazu.cead.panel.PlataformaFalsa
import net.caaguazu.cead.panel.ServidorFalso
import net.caaguazu.cead.panel.error
import net.caaguazu.cead.panel.json
import net.caaguazu.cead.panel.plataforma.ArchivoElegido
import net.caaguazu.cead.panel.sincronizar
import okio.Path.Companion.toPath
import okio.fakefilesystem.FakeFileSystem
import kotlin.test.Test
import kotlin.test.assertContains
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertIs
import kotlin.test.assertNotNull
import kotlin.test.assertNull
import kotlin.test.assertTrue

@OptIn(ExperimentalCoroutinesApi::class)
class RepositorioTest {

    /** Todo lo que arma una prueba: servidor de mentira, disco de mentira, teléfono de mentira. */
    private class Entorno(
        scope: TestScope,
        val fs: FakeFileSystem = FakeFileSystem(),
        val plat: PlataformaFalsa = PlataformaFalsa(),
        val login: String = LOGIN_OK,
        val extra: suspend MockRequestHandleScope.(HttpRequestData) -> HttpResponseData? = { null },
    ) {
        var enLinea = true
        var version = "v1"
        var tareaHecha = false
        var ahora = 1_000_000L
        val ambito = CoroutineScope(UnconfinedTestDispatcher(scope.testScheduler) + SupervisorJob())

        val servidor = ServidorFalso { req ->
            if (!enLinea) throw RuntimeException("sin red")
            extra(req) ?: run {
                val ruta = req.url.encodedPath.substringAfter("/wp-json/cead-acad/v1")
                when {
                    ruta == "/auth/login" -> json(login)
                    ruta == "/sincronizar" ->
                        if (req.headers["If-None-Match"] == "\"$version\"") respond("", HttpStatusCode.NotModified)
                        else json(sincronizar(version, tareaHecha), HttpStatusCode.OK, "ETag" to "\"$version\"")
                    else -> json("""{"ok":true}""")
                }
            }
        }

        fun repo() = Repositorio(plat, ambito, servidor.motor, fs, "https://cead.test", { ahora }, iniciarTrabajador = false)
    }

    /** Lo que la app hace por su cuenta corre en otro hilo: se espera a que se cumpla, con tope. */
    private suspend fun esperarQue(condicion: () -> Boolean) = withContext(Dispatchers.Default) {
        withTimeout(5_000) { while (!condicion()) delay(10) }
    }

    private suspend fun Repositorio.entrar() {
        assertNull(iniciarSesion("ana", "clave"))
        sincronizarAhora()
    }

    /* ----------------------------------------------------------- sesión */

    @Test
    fun `iniciar sesion guarda el token, deja la sesion activa y trae los datos`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()

        assertEquals("Ana Pérez", assertIs<EstadoSesion.Activa>(repo.sesion.value).usuario.nombre)
        assertEquals("7.abcdef123456.secreto", e.plat.almacenSeguro.datos["token"])
        assertEquals(1, repo.datos.value?.tareas?.tareas?.size)
        assertEquals("Bearer 7.abcdef123456.secreto", e.servidor.a("/sincronizar").single().headers["Authorization"])
        assertFalse(repo.sinConexion.value)
        assertEquals(e.ahora, repo.ultimaSync.value)
    }

    @Test
    fun `con credenciales malas devuelve el motivo y no guarda nada`() = runTest {
        val e = Entorno(this, extra = { req ->
            if (req.url.encodedPath.endsWith("/auth/login")) error(401, "cead_api_credenciales", "Usuario o contraseña incorrectos.") else null
        })
        val repo = e.repo()

        val fallo = repo.iniciarSesion("ana", "mala")

        assertEquals("Usuario o contraseña incorrectos.", fallo?.mensaje)
        assertIs<EstadoSesion.SinSesion>(repo.sesion.value)
        assertTrue(e.plat.almacenSeguro.datos.isEmpty())
    }

    @Test
    fun `sin sesion guardada arranca en el login y con sesion arranca adentro, sin red`() = runTest {
        val e = Entorno(this)
        assertIs<EstadoSesion.SinSesion>(e.repo().sesion.value)

        e.repo().entrar()
        e.enLinea = false // el aula no tiene wifi
        val reabierta = e.repo()

        assertIs<EstadoSesion.Activa>(reabierta.sesion.value)
        assertEquals("Matemática", reabierta.datos.value?.horario?.dias?.single()?.franjas?.first()?.materia)
    }

    @Test
    fun `la segunda sincronizacion manda la version que ya tiene y con 304 no cambia nada`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        val antes = repo.datos.value

        e.ahora += 5000
        repo.sincronizarAhora()

        assertEquals("\"v1\"", e.servidor.a("/sincronizar").last().headers["If-None-Match"])
        assertEquals(antes, repo.datos.value)
        assertEquals(e.ahora, repo.ultimaSync.value, "igual cuenta como sincronizada")
    }

    @Test
    fun `sin conexion se queda con lo guardado y lo marca`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()

        e.enLinea = false
        repo.sincronizarAhora()

        assertTrue(repo.sinConexion.value)
        assertNotNull(repo.datos.value)
        assertFalse(repo.sincronizando.value)

        e.enLinea = true
        repo.sincronizarAhora()
        assertFalse(repo.sinConexion.value)
    }

    @Test
    fun `cerrar sesion borra todo lo de la persona del telefono`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        repo.marcarTarea(55, true)
        e.enLinea = false
        repo.marcarTarea(55, false)

        e.enLinea = true
        repo.cerrarSesion()

        assertIs<EstadoSesion.SinSesion>(repo.sesion.value)
        assertTrue(e.plat.almacenSeguro.datos.isEmpty())
        assertNull(repo.datos.value)
        assertTrue(repo.pendientes.value.isEmpty())
        assertTrue(e.fs.listRecursively("/datos".toPath()).none { !e.fs.metadata(it).isDirectory }, "no queda ningun archivo")
        assertEquals(1, e.servidor.a("/auth/logout").size)
    }

    @Test
    fun `al cerrar sesion se da de baja el telefono de los avisos`() = runTest {
        val e = Entorno(this)
        e.plat.push.disponible = true
        e.plat.push.token = "token-de-firebase-1234567890"
        val repo = e.repo()
        repo.entrar()

        repo.cerrarSesion()

        val baja = e.servidor.a("/dispositivos/baja").single()
        assertContains((baja.body as TextContent).text, "token-de-firebase-1234567890")
    }

    /* ----------------------------------------------------- envíos sin señal */

    @Test
    fun `marcar una tarea sin senal la muestra hecha, queda en cola y sale al volver la conexion`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()

        e.enLinea = false
        repo.marcarTarea(55, true)

        assertTrue(repo.datos.value!!.tareas.tareas.single().hecha, "se ve hecha ya")
        assertEquals(1, repo.pendientes.value.size)
        assertTrue(e.fs.exists("/datos/cead/u7-cola.json".toPath()), "y sobrevive a que se cierre la app")

        repo.sincronizarAhora() // sigue sin red
        assertEquals(1, repo.pendientes.value.size)
        assertTrue(repo.datos.value!!.tareas.tareas.single().hecha)

        e.enLinea = true
        e.tareaHecha = true
        e.version = "v2"
        repo.sincronizarAhora()

        assertTrue(repo.pendientes.value.isEmpty())
        assertTrue(repo.datos.value!!.tareas.tareas.single().hecha)
        val post = e.servidor.a("/tareas/55/hecha").single()
        assertEquals(HttpMethod.Post, post.method)
        assertContains((post.body as TextContent).text, "\"hecha\":true")
    }

    @Test
    fun `primero sale lo pendiente y despues se trae lo nuevo`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        e.servidor.pedidos.clear()

        repo.marcarTarea(55, true)
        repo.sincronizarAhora()

        val rutas = e.servidor.pedidos.map { it.url.encodedPath.substringAfter("/v1") }
        assertEquals("/tareas/55/hecha", rutas.first())
        assertEquals("/sincronizar", rutas.last())
    }

    @Test
    fun `dos ordenes sobre la misma tarea mientras esperan salen como una sola`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()

        e.enLinea = false
        repo.marcarTarea(55, true)
        repo.marcarTarea(55, false)

        assertEquals(1, repo.pendientes.value.size)
        assertFalse(repo.datos.value!!.tareas.tareas.single().hecha)
    }

    @Test
    fun `reintentar manda la misma clave de idempotencia`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        e.servidor.pedidos.clear()

        e.enLinea = false
        repo.enviarMensaje("direccion", "Hola")
        repo.sincronizarAhora() // falla por la red
        e.enLinea = true
        repo.sincronizarAhora()

        assertEquals(0, repo.pendientes.value.size)
        assertEquals(1, e.servidor.a("/contacto").size, "la que no tenía red no llegó")
        val claves = e.servidor.intentosA("/contacto").map { it.headers["Idempotency-Key"] }
        assertEquals(2, claves.size)
        assertEquals(claves[0], claves[1], "es el mismo envío: si el primero SÍ había llegado, el servidor lo reconoce")
        assertTrue(claves[0]!!.length >= 8)
    }

    @Test
    fun `un rechazo deja el envio como fallido, avisa, y no frena a los demas`() = runTest {
        val e = Entorno(this, extra = { req ->
            if (req.url.encodedPath.endsWith("/contacto")) error(400, "vacio", "Escribí el mensaje.") else null
        })
        val repo = e.repo()
        repo.entrar()
        val avisos = mutableListOf<String>()
        e.ambito.launch { repo.avisos.collect { avisos += it } }

        repo.enviarMensaje("direccion", "   ")
        repo.marcarTarea(55, true)
        repo.sincronizarAhora()

        val quedan = repo.pendientes.value
        assertEquals(1, quedan.size)
        assertEquals("Escribí el mensaje.", quedan.single().error)
        assertTrue(quedan.single().fallido)
        assertTrue(avisos.single().contains("Escribí el mensaje."))
        assertEquals(1, e.servidor.a("/tareas/55/hecha").size, "la tarea salio igual")

        // Y un envio fallido no se reintenta solo.
        e.servidor.pedidos.clear()
        repo.sincronizarAhora()
        assertTrue(e.servidor.a("/contacto").isEmpty())
    }

    @Test
    fun `descartar saca un envio fallido y reintentar lo vuelve a la cola`() = runTest {
        var rechazar = true
        val e = Entorno(this, extra = { req ->
            if (rechazar && req.url.encodedPath.endsWith("/contacto")) error(403, "x", "No.") else null
        })
        val repo = e.repo()
        repo.entrar()
        repo.enviarMensaje("direccion", "Hola")
        repo.sincronizarAhora()
        val id = repo.pendientes.value.single().id

        rechazar = false
        repo.reintentar(id)
        repo.sincronizarAhora()
        assertTrue(repo.pendientes.value.isEmpty())

        rechazar = true
        repo.enviarMensaje("direccion", "Otra")
        repo.sincronizarAhora()
        repo.descartar(repo.pendientes.value.single().id)
        assertTrue(repo.pendientes.value.isEmpty())
    }

    @Test
    fun `un error de red frena la cola y conserva el orden`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        e.servidor.pedidos.clear()

        e.enLinea = false
        repo.enviarMensaje("direccion", "Primero")
        repo.marcarTarea(55, true)
        repo.sincronizarAhora()

        assertEquals(2, repo.pendientes.value.size)
        assertEquals(listOf("Mensaje al colegio", "Tarea hecha"), repo.pendientes.value.map { it.titulo })
        assertEquals(1, repo.pendientes.value.first().intentos)
        assertEquals(0, repo.pendientes.value.last().intentos, "al segundo ni se le llego")
    }

    @Test
    fun `un error del servidor tambien se reintenta mas tarde, no se descarta`() = runTest {
        var caido = true
        val e = Entorno(this, extra = { req ->
            if (caido && req.url.encodedPath.endsWith("/contacto")) error(503) else null
        })
        val repo = e.repo()
        repo.entrar()
        repo.enviarMensaje("direccion", "Hola")
        repo.sincronizarAhora()
        assertEquals(1, repo.pendientes.value.size)
        assertFalse(repo.pendientes.value.single().fallido)

        caido = false
        repo.sincronizarAhora()
        assertTrue(repo.pendientes.value.isEmpty())
    }

    /* ----------------------------------------- la sesión se vence en medio */

    @Test
    fun `si la sesion vence se conserva lo pendiente para cuando la misma persona vuelva a entrar`() = runTest {
        var vencida = false
        val e = Entorno(this, extra = { req ->
            if (vencida && !req.url.encodedPath.endsWith("/auth/login")) error(401, "cead_api_token_invalido", "Token inválido") else null
        })
        val repo = e.repo()
        repo.entrar()
        e.enLinea = false
        repo.enviarMensaje("direccion", "No quiero perder esto")
        e.enLinea = true
        vencida = true

        repo.sincronizarAhora()

        val sin = assertIs<EstadoSesion.SinSesion>(repo.sesion.value)
        assertEquals("Tu sesión venció. Entrá de nuevo.", sin.motivo)
        assertNull(e.plat.almacenSeguro.datos["token"])
        assertTrue(e.fs.exists("/datos/cead/u7-cola.json".toPath()))

        vencida = false
        assertNull(repo.iniciarSesion("ana", "clave nueva"))
        assertEquals(1, repo.pendientes.value.size, "lo que dejo en cola sigue ahi")
        repo.sincronizarAhora()
        assertTrue(repo.pendientes.value.isEmpty())
        // Uno rechazado por la sesión vencida y uno que salió bien.
        assertEquals(2, e.servidor.a("/contacto").size)
    }

    @Test
    fun `si entra otra persona lo de la anterior se va`() = runTest {
        var vencida = false
        var otra = false
        val e = Entorno(this, extra = { req ->
            val ruta = req.url.encodedPath
            when {
                vencida && !ruta.endsWith("/auth/login") -> error(401, "cead_api_token_invalido", "Token inválido")
                otra && ruta.endsWith("/auth/login") -> json(LOGIN_OK.replace("\"id\":7", "\"id\":8").replace("Ana Pérez", "Luis Gómez"))
                else -> null
            }
        })
        val repo = e.repo()
        repo.entrar()
        e.enLinea = false
        repo.enviarMensaje("direccion", "Mensaje de Ana")
        e.enLinea = true
        vencida = true
        repo.sincronizarAhora()

        vencida = false
        otra = true
        assertNull(repo.iniciarSesion("luis", "clave"))

        assertEquals("Luis Gómez", assertIs<EstadoSesion.Activa>(repo.sesion.value).usuario.nombre)
        assertTrue(repo.pendientes.value.isEmpty())
        assertNull(repo.datos.value)
        assertFalse(e.fs.exists("/datos/cead/u7-cola.json".toPath()), "los archivos de Ana ya no estan")
    }

    /* ------------------------------------------------ cosas particulares */

    @Test
    fun `el codigo de un reporte se guarda en el telefono al enviarse`() = runTest {
        val e = Entorno(this, extra = { req ->
            if (req.url.encodedPath.endsWith("/reportes")) json("""{"codigo":"RPT-ABC123"}""") else null
        })
        val repo = e.repo()
        repo.entrar()

        e.enLinea = false
        repo.enviarReporte("anonimo", "Seguridad", "Hay un vidrio roto.")
        assertTrue(repo.locales.value.reportes.isEmpty(), "todavia no tiene codigo")

        e.enLinea = true
        repo.sincronizarAhora()

        val r = repo.locales.value.reportes.single()
        assertEquals("RPT-ABC123", r.codigo)
        assertEquals("anonimo", r.tipo)
        assertEquals("Seguridad", r.categoria)
        // Sobrevive a reiniciar: es la unica forma de seguir un reporte anonimo.
        assertEquals("RPT-ABC123", e.repo().locales.value.reportes.single().codigo)
    }

    @Test
    fun `una encuesta anonima respondida no se puede repetir aunque el servidor diga no se`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        val anonima = repo.datos.value!!.encuestas.encuestas.first { it.anonima }

        repo.responderEncuesta(anonima, mapOf(1 to "5"))
        repo.sincronizarAhora()

        assertTrue(repo.pendientes.value.isEmpty())
        assertEquals(true, repo.datos.value!!.encuestas.encuestas.first { it.anonima }.respondida)
        val cuerpo = (e.servidor.a("/encuestas/90/respuestas").single().body as TextContent).text
        assertEquals("""{"respuestas":{"1":"5"}}""", cuerpo)
    }

    @Test
    fun `leer un comunicado se anota una sola vez`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        e.enLinea = false

        repo.marcarLeido(10)
        repo.marcarLeido(10) // ya figura leído en la proyección
        repo.marcarLeido(9) // este ya estaba leído desde el servidor

        assertEquals(1, repo.pendientes.value.size)
        assertEquals(1, repo.datos.value!!.comunicados.sinLeer)
    }

    @Test
    fun `una foto se manda como multipart y despues se borra del telefono`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        e.fs.createDirectories("/cache".toPath())
        e.fs.write("/cache/foto.jpg".toPath()) { writeUtf8("JPEG") }

        repo.cambiarFoto(ArchivoElegido("/cache/foto.jpg", "foto.jpg", "image/jpeg", 4))
        repo.sincronizarAhora()

        val p = e.servidor.a("/perfil/foto").single()
        assertTrue(p.body.contentType.toString().startsWith("multipart/form-data"))
        assertTrue(repo.pendientes.value.isEmpty())
        assertFalse(e.fs.exists("/cache/foto.jpg".toPath()), "la copia temporal no se queda ocupando lugar")
    }

    @Test
    fun `la cola no borra archivos de fuera de la app`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        e.fs.createDirectories("/otro".toPath())
        e.fs.write("/otro/ajeno.jpg".toPath()) { writeUtf8("X") }

        repo.cambiarFoto(ArchivoElegido("/otro/ajeno.jpg", "ajeno.jpg", "image/jpeg", 1))
        repo.sincronizarAhora()

        assertTrue(e.fs.exists("/otro/ajeno.jpg".toPath()))
    }

    /* ---------------------------------------------------------- avisos push */

    @Test
    fun `registra el telefono para los avisos una vez y de nuevo si cambia el token`() = runTest {
        val e = Entorno(this)
        e.plat.push.disponible = true
        e.plat.push.token = "token-uno-1234567890abcdef"
        val repo = e.repo()
        repo.entrar()
        assertEquals(1, e.servidor.a("/dispositivos").size)
        val cuerpo = (e.servidor.a("/dispositivos").single().body as TextContent).text
        assertContains(cuerpo, "\"plataforma\":\"android\"")
        assertContains(cuerpo, "token-uno-1234567890abcdef")

        e.ahora += 60_000
        repo.sincronizarAhora()
        assertEquals(1, e.servidor.a("/dispositivos").size, "mismo token, ya registrado")

        e.plat.push.token = "token-dos-1234567890abcdef"
        repo.sincronizarAhora()
        assertEquals(2, e.servidor.a("/dispositivos").size)
    }

    @Test
    fun `se vuelve a registrar cada tanto por si el servidor lo olvido`() = runTest {
        val e = Entorno(this)
        e.plat.push.disponible = true
        e.plat.push.token = "token-uno-1234567890abcdef"
        val repo = e.repo()
        repo.entrar()

        e.ahora += 8L * 24 * 60 * 60 * 1000
        repo.sincronizarAhora()

        assertEquals(2, e.servidor.a("/dispositivos").size)
    }

    @Test
    fun `sin firebase no se pide ni se manda ningun token`() = runTest {
        val e = Entorno(this)
        e.plat.push.token = "no-deberia-usarse-1234567890"
        val repo = e.repo()
        repo.entrar()

        assertTrue(e.servidor.a("/dispositivos").isEmpty())
    }

    @Test
    fun `si el sistema cambia el token se registra el nuevo enseguida`() = runTest {
        val e = Entorno(this)
        e.plat.push.disponible = true
        e.plat.push.token = "token-uno-1234567890abcdef"
        val repo = e.repo()
        repo.entrar()

        e.plat.push.oyente!!("token-nuevo-1234567890abcdef")
        esperarQue { e.servidor.a("/dispositivos").size == 2 }

        assertEquals(2, e.servidor.a("/dispositivos").size)
        assertContains((e.servidor.a("/dispositivos").last().body as TextContent).text, "token-nuevo-1234567890abcdef")
    }

    /* ------------------------------------------------ recursos descargados */

    @Test
    fun `un recurso bajado se reconoce, se guarda por persona y se puede borrar`() = runTest {
        val e = Entorno(this, extra = { req ->
            if (req.url.encodedPath.endsWith("guia.pdf")) respond("PDFDATA", HttpStatusCode.OK) else null
        })
        val repo = e.repo()
        repo.entrar()
        val recurso = repo.datos.value!!.recursos.recursos.single()
        assertNull(repo.rutaDescarga(recurso))

        assertNull(repo.descargarRecurso(recurso))

        val ruta = assertNotNull(repo.rutaDescarga(recurso))
        assertTrue(ruta.contains("recursos-u7"))
        assertEquals("PDFDATA", e.fs.read(ruta.toPath()) { readUtf8() })
        assertEquals(recurso.id.toString(), repo.locales.value.descargas.keys.single())

        repo.borrarDescarga(recurso)
        assertNull(repo.rutaDescarga(recurso))
    }

    @Test
    fun `un recurso sin archivo no se puede bajar`() = runTest {
        val e = Entorno(this)
        val repo = e.repo()
        repo.entrar()
        val e2 = repo.descargarRecurso(Recurso(id = 1, titulo = "Enlace", url = null))
        assertEquals("sin_url", e2?.codigo)
    }
}
