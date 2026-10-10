package net.caaguazu.cead.panel.data

import io.ktor.client.engine.mock.respond
import io.ktor.http.HttpHeaders
import io.ktor.http.HttpStatusCode
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.JsonObject
import net.caaguazu.cead.panel.ServidorFalso
import net.caaguazu.cead.panel.error
import net.caaguazu.cead.panel.json
import okio.Path.Companion.toPath
import okio.fakefilesystem.FakeFileSystem
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertIs
import kotlin.test.assertNull
import kotlin.test.assertTrue

class ApiClienteTest {

    private var vencida = 0

    private fun api(servidor: ServidorFalso, token: String? = "7.abc.secreto", fs: FakeFileSystem = FakeFileSystem()) =
        ApiCliente("https://cead.test", { token }, { vencida++ }, "Prueba", servidor.motor, fs)

    private fun fallo(r: Resultado<*>): ErrorApi = assertIs<Resultado.Fallo>(r).error

    @Test
    fun `manda el token y pide JSON`() = runTest {
        val s = ServidorFalso { json("""{"ok":true}""") }
        api(s).get("/me", JsonObject.serializer())

        val p = s.pedidos.single()
        assertEquals("Bearer 7.abc.secreto", p.headers[HttpHeaders.Authorization])
        assertEquals("application/json", p.headers[HttpHeaders.Accept])
        assertEquals("https://cead.test/wp-json/cead-acad/v1/me", p.url.toString())
    }

    @Test
    fun `sin sesion no manda el header de autorizacion`() = runTest {
        val s = ServidorFalso { json("""{"ok":true}""") }
        api(s, token = null).get("/me", JsonObject.serializer())
        assertNull(s.pedidos.single().headers[HttpHeaders.Authorization])
    }

    @Test
    fun `manda la version que ya tiene entre comillas y entiende el 304`() = runTest {
        val s = ServidorFalso { respond304() }
        val r = api(s).get("/sincronizar", Snapshot.serializer(), etag = "abc123")

        assertEquals("\"abc123\"", s.pedidos.single().headers[HttpHeaders.IfNoneMatch])
        assertEquals(Resultado.NoModificado, r)
    }

    private fun io.ktor.client.engine.mock.MockRequestHandleScope.respond304() =
        respond(content = "", status = HttpStatusCode.NotModified)

    @Test
    fun `la clave de idempotencia viaja en el header`() = runTest {
        val s = ServidorFalso { json("""{"ok":true}""") }
        api(s).post("/contacto", JsonObject.serializer(), """{"a":1}""", clave = "clave-1234")
        assertEquals("clave-1234", s.pedidos.single().headers["Idempotency-Key"])
    }

    @Test
    fun `lee el etag y si el servidor contesto desde su memoria`() = runTest {
        val s = ServidorFalso { json("""{"ok":true}""", HttpStatusCode.OK, "ETag" to "\"v9\"", "Idempotent-Replay" to "true") }
        val r = assertIs<Resultado.Ok<JsonObject>>(api(s).post("/x", JsonObject.serializer()))
        assertEquals("v9", r.etag)
        assertTrue(r.repetido)
    }

    @Test
    fun `un 401 con sesion avisa que vencio y se distingue de los demas errores`() = runTest {
        val s = ServidorFalso { error(401, "cead_api_token_invalido", "Token inválido") }
        val e = fallo(api(s).get("/me", JsonObject.serializer()))

        assertEquals(TipoError.SESION_VENCIDA, e.tipo)
        assertEquals(1, vencida)
    }

    /** Contraseña mal puesta: es un rechazo, no una sesión vencida (no hay sesión). */
    @Test
    fun `un 401 en el login es un rechazo con su mensaje y no borra nada`() = runTest {
        val s = ServidorFalso { error(401, "cead_api_credenciales", "Usuario o contraseña incorrectos.") }
        val e = fallo(api(s).post("/auth/login", JsonObject.serializer(), "{}", autenticado = false))

        assertEquals(TipoError.RECHAZADO, e.tipo)
        assertEquals("Usuario o contraseña incorrectos.", e.mensaje)
        assertEquals(0, vencida)
    }

    @Test
    fun `que se puede reintentar y que no`() = runTest {
        suspend fun con(codigo: Int) = fallo(api(ServidorFalso { error(codigo) }).get("/x", JsonObject.serializer()))

        assertFalse(con(400).reintentable)
        assertFalse(con(403).reintentable)
        assertFalse(con(404).reintentable)
        assertFalse(con(409).reintentable)
        assertTrue(con(408).reintentable)
        assertTrue(con(429).reintentable)
        assertTrue(con(500).reintentable)
        assertTrue(con(503).reintentable)
    }

    @Test
    fun `sin red es un error de conexion y se puede reintentar`() = runTest {
        val s = ServidorFalso { throw RuntimeException("Unable to resolve host") }
        val e = fallo(api(s).get("/me", JsonObject.serializer()))

        assertEquals(TipoError.SIN_CONEXION, e.tipo)
        assertTrue(e.reintentable)
    }

    @Test
    fun `una pagina de error de un proxy no se le muestra a la persona`() = runTest {
        val s = ServidorFalso { json("<html><body>502 Bad Gateway</body></html>", HttpStatusCode.BadGateway) }
        val e = fallo(api(s).get("/me", JsonObject.serializer()))

        assertEquals(TipoError.SERVIDOR, e.tipo)
        assertFalse(e.mensaje.contains("<html"))
    }

    @Test
    fun `una respuesta que no es lo esperado es un error del servidor, no un cierre de la app`() = runTest {
        val s = ServidorFalso { json("esto no es json") }
        val e = fallo(api(s).get("/sincronizar", Snapshot.serializer()))
        assertEquals("respuesta_invalida", e.codigo)
    }

    @Test
    fun `un archivo que ya no esta se rechaza con un motivo claro y no se manda nada`() = runTest {
        val s = ServidorFalso { json("""{"ok":true}""") }
        val e = fallo(
            api(s).postMultipart(
                "/perfil/foto", JsonObject.serializer(), emptyMap(),
                listOf(ArchivoAdjunto("foto", "/cache/no-existe.jpg", "f.jpg", "image/jpeg")),
            ),
        )
        assertEquals("archivo_perdido", e.codigo)
        assertFalse(e.reintentable)
        assertTrue(s.pedidos.isEmpty())
    }

    @Test
    fun `manda el archivo como multipart con su tipo`() = runTest {
        val fs = FakeFileSystem()
        fs.createDirectories("/cache".toPath())
        fs.write("/cache/f.jpg".toPath()) { writeUtf8("JPEGDATA") }
        val s = ServidorFalso { json("""{"ok":true}""") }

        api(s, fs = fs).postMultipart(
            "/perfil/foto", JsonObject.serializer(), mapOf("x" to "y"),
            listOf(ArchivoAdjunto("foto", "/cache/f.jpg", "f.jpg", "image/jpeg")), clave = "clave-9999",
        )

        val p = s.pedidos.single()
        assertTrue(p.body.contentType.toString().startsWith("multipart/form-data"))
        assertEquals("clave-9999", p.headers["Idempotency-Key"])
    }

    @Test
    fun `lee el codigo y el mensaje de un error de wordpress`() {
        assertEquals("a" to "b", ApiCliente.leerError("""{"code":"a","message":"b","data":{"status":400}}""", 400))
        assertEquals("http_404", ApiCliente.leerError("<html>", 404).first)
        assertEquals("http_500", ApiCliente.leerError("", 500).first)
    }

    @Test
    fun `arma cuerpos JSON con listas, mapas, booleanos y nulos`() {
        val j = ApiCliente.json("a" to 1, "b" to true, "c" to null, "d" to listOf(mapOf("type" to "all")), "e" to "x\"y")
        assertEquals("""{"a":1,"b":true,"c":null,"d":[{"type":"all"}],"e":"x\"y"}""", j)
    }
}
