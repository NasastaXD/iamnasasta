package net.caaguazu.cead.panel.data

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.long
import kotlin.test.Test
import kotlin.test.assertEquals

class TsClienteTest {

    @Test
    fun `una lectura sin cuerpo sale con la hora real del telefono`() {
        val c = conTsCliente(null, 1_806_584_400_000)!!
        assertEquals(1_806_584_400_000, Json.parseToJsonElement(c).jsonObject["ts_cliente"]!!.jsonPrimitive.long)
    }

    @Test
    fun `se suma a lo que ya traia el cuerpo sin pisarlo`() {
        val c = Json.parseToJsonElement(conTsCliente("""{"respuestas":{"1":"4"}}""", 42)!!).jsonObject
        assertEquals("4", c["respuestas"]!!.jsonObject["1"]!!.jsonPrimitive.content)
        assertEquals(42, c["ts_cliente"]!!.jsonPrimitive.long)
        assertEquals("""{"ts_cliente":7}""", conTsCliente("""{"ts_cliente":7}""", 42))
    }

    @Test
    fun `sin hora o con un cuerpo que no es objeto, no toca nada`() {
        assertEquals("""{"a":1}""", conTsCliente("""{"a":1}""", 0))
        assertEquals("[1,2]", conTsCliente("[1,2]", 42))
    }
}
