package net.caaguazu.cead.panel.data

import net.caaguazu.cead.panel.sincronizar
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

class ProyeccionTest {

    private val base = AppJson.decodeFromString(Snapshot.serializer(), sincronizar())

    private fun envio(tipo: String, vararg datos: Pair<String, String>, error: String? = null) =
        Envio(id = "k-$tipo", tipo = tipo, titulo = tipo, ruta = "/x", datos = datos.toMap(), error = error)

    @Test
    fun `sin pendientes se ve lo que dijo el servidor`() {
        assertEquals(base, Proyeccion.aplicar(base, emptyList(), Locales()))
    }

    @Test
    fun `marcar una tarea sin senal la muestra hecha ya, sin tocar la copia del servidor`() {
        val v = Proyeccion.aplicar(base, listOf(envio(Tipos.TAREA_HECHA, "id" to "55", "hecha" to "true")), Locales())

        assertTrue(v.tareas.tareas.single().hecha)
        assertFalse(base.tareas.tareas.single().hecha, "la copia del servidor queda intacta")
    }

    @Test
    fun `dos ordenes sobre la misma tarea, gana la ultima`() {
        val v = Proyeccion.aplicar(
            base,
            listOf(
                envio(Tipos.TAREA_HECHA, "id" to "55", "hecha" to "true"),
                envio(Tipos.TAREA_HECHA, "id" to "55", "hecha" to "false"),
            ),
            Locales(),
        )
        assertFalse(v.tareas.tareas.single().hecha)
    }

    @Test
    fun `un favorito pendiente se ve`() {
        val v = Proyeccion.aplicar(base, listOf(envio(Tipos.FAVORITO, "id" to "30", "favorito" to "true")), Locales())
        assertTrue(v.recursos.recursos.single().favorito)
    }

    @Test
    fun `leer un comunicado lo marca y baja el contador una sola vez`() {
        val leer = envio(Tipos.COMUNICADO_LEIDO, "id" to "10")
        val v = Proyeccion.aplicar(base, listOf(leer, leer), Locales())

        assertTrue(v.comunicados.comunicados.first { it.id == 10 }.leido)
        assertEquals(1, v.comunicados.sinLeer, "eran 2: bajó uno, no dos")
    }

    @Test
    fun `leer uno que ya estaba leido no toca el contador`() {
        val v = Proyeccion.aplicar(base, listOf(envio(Tipos.COMUNICADO_LEIDO, "id" to "9")), Locales())
        assertEquals(2, v.comunicados.sinLeer)
    }

    @Test
    fun `lo que el servidor rechazo no cambia lo que se ve`() {
        val v = Proyeccion.aplicar(base, listOf(envio(Tipos.TAREA_HECHA, "id" to "55", "hecha" to "true", error = "No es tu curso")), Locales())
        assertFalse(v.tareas.tareas.single().hecha)
    }

    @Test
    fun `una encuesta respondida deja de estar pendiente`() {
        val v = Proyeccion.aplicar(base, listOf(envio(Tipos.ENCUESTA, "id" to "91")), Locales())
        assertEquals(true, v.encuestas.encuestas.first { it.id == 91 }.respondida)
    }

    /** El servidor no guarda quién respondió una anónima: lo único que impide repetirla es esto. */
    @Test
    fun `una encuesta anonima respondida se recuerda localmente aunque el servidor diga no se`() {
        assertNull(base.encuestas.encuestas.first { it.id == 90 }.respondida)

        val v = Proyeccion.aplicar(base, emptyList(), Locales(encuestasAnonimas = listOf(90)))
        assertEquals(true, v.encuestas.encuestas.first { it.id == 90 }.respondida)
        // Y no se le pega a las que no son anónimas.
        assertEquals(false, v.encuestas.encuestas.first { it.id == 91 }.respondida)
    }

    @Test
    fun `los cambios del perfil se ven antes de salir`() {
        val v = Proyeccion.aplicar(base, listOf(envio(Tipos.PERFIL, "nombre" to "Ana María", "telefono" to "0991")), Locales())
        assertEquals("Ana María", v.misDatos.nombre)
        assertEquals("0991", v.misDatos.telefono)
    }

    @Test
    fun `las preferencias de avisos pendientes se ven`() {
        val v = Proyeccion.aplicar(base, listOf(envio(Tipos.PREFERENCIAS, "eventos" to "false")), Locales())
        assertFalse(v.preferenciasPush.getValue("eventos"))
        assertTrue(v.preferenciasPush.getValue("comunicados"))
    }

    @Test
    fun `un tipo que esta version no conoce se ignora`() {
        assertEquals(base, Proyeccion.aplicar(base, listOf(envio("tipo_del_futuro")), Locales()))
    }

    @Test
    fun `el snapshot del servidor se lee entero aunque traiga campos que la app no conoce`() {
        assertEquals("Ana Pérez", base.perfil?.nombre)
        assertEquals(3, base.perfil?.curso?.id)
        assertEquals(4.0, base.boletin?.materias?.first()?.notas?.get("Primera Etapa")?.nota)
        assertEquals(2, base.horario.dias.single().franjas.size)
        assertEquals("v1", base.version)
        assertEquals(2, base.comunicados.sinLeer)
        assertNull(base.comunicados.comunicados.first().imagen)
        assertTrue(base.gestion.notas == null && base.gestion.articulos == null)
    }
}
