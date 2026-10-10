@file:OptIn(androidx.compose.ui.InternalComposeUiApi::class, androidx.compose.ui.ExperimentalComposeUiApi::class)

package net.caaguazu.cead.panel

import androidx.compose.ui.ImageComposeScene
import androidx.compose.ui.unit.Density
import io.ktor.client.engine.mock.respond
import io.ktor.http.HttpStatusCode
import kotlinx.coroutines.runBlocking
import net.caaguazu.cead.panel.data.Repositorio
import net.caaguazu.cead.panel.ui.App
import net.caaguazu.cead.panel.ui.Destino
import net.caaguazu.cead.panel.ui.Navegacion
import net.caaguazu.cead.panel.ui.Tab
import okio.fakefilesystem.FakeFileSystem
import org.jetbrains.skia.EncodedImageFormat
import java.io.File
import kotlin.test.Test
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob

/**
 * Saca una captura de cada pantalla, dibujada de verdad (Skia, sin emulador).
 *
 * No prueba nada por sí sola: sirve para MIRAR lo que se dibuja, que es lo que
 * ninguna prueba de lógica dice. Solo corre si se define `CEAD_CAPTURAS` con la
 * carpeta donde guardarlas.
 */
class CapturasTest {

    private val carpeta: File? = System.getenv("CEAD_CAPTURAS")?.let { File(it).apply { mkdirs() } }

    private fun entorno(extra: Map<String, String> = emptyMap(), conLogin: Boolean = true): Triple<Repositorio, PlataformaFalsa, Navegacion> {
        val servidor = ServidorFalso { req ->
            val ruta = req.url.encodedPath.substringAfter("/wp-json/cead-acad/v1")
            when {
                ruta == "/auth/login" -> json(LOGIN_OK_STAFF)
                ruta == "/sincronizar" -> json(sincronizarRico(), HttpStatusCode.OK, "ETag" to "\"v1\"")
                ruta in extra -> json(extra.getValue(ruta))
                else -> json("""{"ok":true}""")
            }
        }
        val plat = PlataformaFalsa()
        val repo = Repositorio(plat, CoroutineScope(SupervisorJob() + Dispatchers.Unconfined), servidor.motor, FakeFileSystem(), "https://cead.test", iniciarTrabajador = false)
        if (conLogin) runBlocking {
            repo.iniciarSesion("ana", "x")
            repo.sincronizarAhora()
        }
        return Triple(repo, plat, Navegacion())
    }

    private fun captura(nombre: String, oscuro: Boolean = false, alto: Int = 2200, extra: Map<String, String> = emptyMap(), conLogin: Boolean = true, preparar: (Navegacion, Repositorio) -> Unit = { _, _ -> }) {
        val dir = carpeta ?: return
        val (repo, plat, nav) = entorno(extra, conLogin)
        preparar(nav, repo)
        val escena = ImageComposeScene(1080, alto, Density(2.625f)) { App(plat, repo, nav, oscuro) }
        // Unos cuadros para que termine de componer y de animar; con pausas, porque
        // lo que se pide «a la red» llega desde otro hilo.
        var t = 0L
        repeat(30) { escena.render(t); t += 50_000_000L; Thread.sleep(20) }
        val imagen = escena.render(t)
        File(dir, "$nombre.png").writeBytes(imagen.encodeToData(EncodedImageFormat.PNG)!!.bytes)
        escena.close()
    }

    @Test fun inicio() = captura("01-inicio")
    @Test fun inicioOscuro() = captura("02-inicio-oscuro", oscuro = true)
    @Test fun login() = captura("03-login", conLogin = false)
    @Test fun horario() = captura("04-horario") { n, _ -> n.elegirTab(Tab.HORARIO) }
    @Test fun comunicados() = captura("05-comunicados") { n, _ -> n.elegirTab(Tab.COMUNICADOS) }
    @Test fun comunicadoDetalle() = captura("06-comunicado") { n, _ -> n.ir(Destino.Comunicado(10)) }
    @Test fun tareas() = captura("07-tareas") { n, _ -> n.elegirTab(Tab.TAREAS) }
    @Test fun mas() = captura("08-mas") { n, _ -> n.elegirTab(Tab.MAS) }
    @Test fun calendario() = captura("09-calendario") { n, _ -> n.ir(Destino.Calendario) }
    @Test fun boletin() = captura("10-boletin") { n, _ -> n.ir(Destino.Boletin) }
    @Test fun recursos() = captura("11-recursos") { n, _ -> n.ir(Destino.Recursos) }
    @Test fun encuestas() = captura("12-encuestas") { n, _ -> n.ir(Destino.Encuestas) }
    @Test fun encuesta() = captura("13-encuesta") { n, _ -> n.ir(Destino.ResponderEncuesta(91)) }
    @Test fun faq() = captura("14-faq") { n, _ -> n.ir(Destino.Faq) }
    @Test fun perfil() = captura("15-perfil") { n, _ -> n.ir(Destino.Perfil) }
    @Test fun ajustes() = captura("16-ajustes") { n, _ -> n.ir(Destino.Ajustes) }
    @Test fun contacto() = captura("17-contacto") { n, _ -> n.ir(Destino.Contacto) }
    @Test fun reportar() = captura("18-reportar") { n, _ -> n.ir(Destino.Reportar) }
    @Test fun misMensajes() = captura("19-mis-mensajes") { n, _ -> n.ir(Destino.MisMensajes) }
    @Test fun ceadi() = captura("20-ceadi") { n, _ -> n.ir(Destino.Ceadi) }
    @Test fun comunicadoNuevo() = captura("22-publicar-comunicado", alto = 2600) { n, _ -> n.ir(Destino.PublicarComunicado) }
    @Test fun eventoNuevo() = captura("23-cargar-evento", alto = 2600) { n, _ -> n.ir(Destino.CargarEvento) }
    @Test fun notas() = captura("24-cargar-notas", alto = 2800) { n, _ -> n.ir(Destino.CargarNotas) }
    @Test fun articulo() = captura("25-articulo", alto = 2600) { n, _ -> n.ir(Destino.PublicarArticulo) }
    @Test fun tareasCurso() = captura("26-tareas-curso") { n, _ -> n.ir(Destino.TareasDelCurso) }
    @Test fun asignarTarea() = captura("27-asignar-tarea") { n, _ -> n.ir(Destino.AsignarTarea) }
    @Test fun buzon() = captura("28-buzon", alto = 2800, extra = EN_LINEA) { n, _ -> n.ir(Destino.Buzon) }
    @Test fun metricas() = captura("29-metricas", alto = 2400, extra = EN_LINEA) { n, _ -> n.ir(Destino.Metricas) }
    @Test fun invitaciones() = captura("30-invitaciones", alto = 2800, extra = EN_LINEA) { n, _ -> n.ir(Destino.Invitaciones) }
    @Test fun delegados() = captura("31-delegados", extra = EN_LINEA) { n, _ -> n.ir(Destino.Delegados) }
    @Test fun sesiones() = captura("32-sesiones", extra = EN_LINEA) { n, _ -> n.ir(Destino.Sesiones) }
    @Test fun novedades() = captura("33-novedades", extra = EN_LINEA) { n, _ -> n.ir(Destino.Novedades) }
    @Test fun masStaff() = captura("34-mas-staff", alto = 3200) { n, _ -> n.elegirTab(Tab.MAS) }
    @Test fun pendientes() = captura("21-pendientes") { n, r -> r.marcarTarea(55, true); r.enviarMensaje("direccion", "Hola"); n.ir(Destino.Pendientes) }

    private val EN_LINEA = mapOf(
        "/gestion/buzon" to """{"alcance":"todo","reportes":[{"id":1,"codigo":"RPT-A1B2C3","tipo":"confidencial","categoria":"Bullying / acoso","texto":"🔒 De: Luis Benítez (Alumno/a, 3° B)\n\nUn compañero me molesta todos los días en el recreo.","estado":"new","respuesta":"","notas":"","telefono":null,"creado":"2026-10-08 10:12:00"},{"id":2,"codigo":"RPT-D4E5F6","tipo":"anonimo","categoria":"Seguridad","texto":"Hay un vidrio roto en el baño del patio.","estado":"in_review","respuesta":"","notas":"","telefono":null,"creado":"2026-10-07 09:00:00"}],"sugerencias":[{"id":1,"categoria":"direccion","texto":"✉️ De Ana (Alumno/a)\n\n¿Puedo cambiar de turno?","estado":"new","respuesta":"","telefono":null,"creado":"2026-10-08 08:00:00"}],"papelera":{"reportes":[],"sugerencias":[]}}""",
        "/gestion/metricas" to """{"personas":{"alumnos":412,"delegados":18,"docentes":36,"inscripciones_activas":430},"contenido":{"cursos":18,"comunicados":57,"encuestas":6,"eventos":24,"recursos":112},"ultimo_comunicado":{"id":10,"titulo":"Examen de matemática","destinatarios":430,"lecturas":301,"respuestas":0,"tasa":70},"ultima_encuesta":{"id":90,"titulo":"Clima escolar","destinatarios":430,"lecturas":0,"respuestas":128,"tasa":30},"proximos_eventos":[{"id":80,"titulo":"Acto del Día de la Raza","inicio":"2026-10-12T08:00","tipo":"acto"}]}""",
        "/gestion/invitaciones" to """{"roles":[{"valor":"cead_acad_student","nombre":"Alumno/a"},{"valor":"cead_acad_delegate","nombre":"Delegado/a"},{"valor":"cead_acad_teacher","nombre":"Docente"}],"invitaciones":[{"id":5,"rol":"cead_acad_student","rol_label":"Alumno/a","curso":{"id":3,"titulo":"3° B"},"email":null,"estado":"valid","usos":30,"restantes":22,"vence":"2027-10-01T00:00:00+00:00","creada":"2026-10-01T00:00:00+00:00","link":"https://cead.caaguazu.net/i/abc123"},{"id":4,"rol":"cead_acad_teacher","rol_label":"Docente","curso":null,"email":"prof@x.py","estado":"expired","usos":1,"restantes":1,"vence":"2026-01-01T00:00:00+00:00","creada":"2025-12-01T00:00:00+00:00","link":null}]}""",
        "/delegados" to """{"delegados":[{"user_id":31,"nombre":"Sofía Ramírez","curso_id":3,"curso":"3° B","turno":"Mañana","telefono":"0981 555 123","suspendido":false},{"user_id":32,"nombre":"Diego Acosta","curso_id":4,"curso":"1° A","turno":"Tarde","telefono":"0971 222 333","suspendido":false}]}""",
        "/auth/sesiones" to """{"sesiones":[{"id":"abc123def456","nombre":"Motorola G54","creado":1790000000,"usado":1790900000,"actual":true},{"id":"fff111eee222","nombre":"Samsung A15","creado":1780000000,"usado":1785000000,"actual":false}]}""",
        "/notificaciones" to """{"notificaciones":[{"tipo":"comunicado","titulo":"Examen de matemática del primer trimestre","cuando":"hace 2 horas"},{"tipo":"evento","titulo":"Acto del Día de la Raza","cuando":"ayer"}],"sin_leer":2}""",
    )
}
