package net.caaguazu.cead.panel.data

/**
 * Lo que se ve = lo último que dijo el servidor + lo que todavía no le conté.
 *
 * Cuando alguien marca una tarea como hecha sin señal, la pantalla tiene que
 * mostrarla hecha YA, no cuando vuelva la conexión. Pero tampoco se toca la
 * copia del servidor: la próxima sincronización la reemplaza entera, y si el
 * cambio estuviera mezclado ahí se perdería (o, peor, volvería a aparecer la
 * tarea sin hacer justo después de marcarla).
 *
 * Así que la copia del servidor se guarda intacta y los envíos pendientes se
 * aplican por encima, cada vez que algo cambia. Cuando el envío sale y el
 * servidor confirma, el envío desaparece y lo que se ve sigue igual, porque el
 * servidor ya dice lo mismo.
 */
object Proyeccion {

    fun aplicar(base: Snapshot, pendientes: List<Envio>, locales: Locales): Snapshot {
        var s = base
        for (e in pendientes) {
            if (e.fallido) continue // lo rechazado no cambió nada en el servidor
            s = when (e.tipo) {
                Tipos.TAREA_HECHA -> s.copy(
                    tareas = s.tareas.copy(
                        tareas = s.tareas.tareas.map {
                            if (it.id.toString() == e.datos["id"]) it.copy(hecha = e.datos["hecha"] == "true") else it
                        },
                    ),
                )
                Tipos.FAVORITO -> s.copy(
                    recursos = s.recursos.copy(
                        recursos = s.recursos.recursos.map {
                            if (it.id.toString() == e.datos["id"]) it.copy(favorito = e.datos["favorito"] == "true") else it
                        },
                    ),
                )
                Tipos.COMUNICADO_LEIDO -> {
                    val ya = s.comunicados.comunicados.firstOrNull { it.id.toString() == e.datos["id"] }
                    if (ya == null || ya.leido) s else s.copy(
                        comunicados = s.comunicados.copy(
                            comunicados = s.comunicados.comunicados.map { if (it === ya) it.copy(leido = true) else it },
                            sinLeer = (s.comunicados.sinLeer - 1).coerceAtLeast(0),
                        ),
                    )
                }
                Tipos.ENCUESTA -> s.copy(
                    encuestas = s.encuestas.copy(
                        encuestas = s.encuestas.encuestas.map {
                            if (it.id.toString() == e.datos["id"]) it.copy(respondida = true) else it
                        },
                    ),
                )
                Tipos.PERFIL -> s.copy(
                    misDatos = s.misDatos.copy(
                        nombre = e.datos["nombre"] ?: s.misDatos.nombre,
                        telefono = e.datos["telefono"] ?: s.misDatos.telefono,
                    ),
                )
                Tipos.PREFERENCIAS -> s.copy(
                    preferenciasPush = s.preferenciasPush + e.datos.mapValues { it.value == "true" },
                )
                Tipos.TAREA_ESTADO -> s.copy(
                    gestion = s.gestion.copy(
                        tareasDelCurso = s.gestion.tareasDelCurso?.map {
                            if (it.id.toString() == e.datos["id"]) it.copy(estado = e.datos["estado"] ?: it.estado) else it
                        },
                    ),
                )
                else -> s
            }
        }
        // El servidor no sabe qué encuestas anónimas respondí: lo sé yo.
        if (locales.encuestasAnonimas.isNotEmpty()) {
            s = s.copy(
                encuestas = s.encuestas.copy(
                    encuestas = s.encuestas.encuestas.map {
                        if (it.anonima && it.id in locales.encuestasAnonimas) it.copy(respondida = true) else it
                    },
                ),
            )
        }
        return s
    }
}

/** Los tipos de envío. Son texto porque se guardan en disco: renombrar uno rompe lo que esté en cola. */
object Tipos {
    const val TAREA_HECHA = "tarea_hecha"
    const val FAVORITO = "favorito"
    const val COMUNICADO_LEIDO = "comunicado_leido"
    const val ENCUESTA = "encuesta"
    const val CONTACTO = "contacto"
    const val REPORTE = "reporte"
    const val PERFIL = "perfil"
    const val FOTO = "foto"
    const val ENTREGA = "entrega"
    const val PREFERENCIAS = "preferencias"

    const val COMUNICADO = "comunicado"
    const val EVENTO = "evento"
    const val NOTA = "nota"
    const val ARTICULO = "articulo"
    const val TAREA_ESTADO = "tarea_estado"
    const val TAREA_NUEVA = "tarea_nueva"
}
