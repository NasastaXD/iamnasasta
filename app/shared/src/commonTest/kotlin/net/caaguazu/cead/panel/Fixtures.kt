package net.caaguazu.cead.panel

/**
 * Una respuesta de `GET /sincronizar` tal como la arma el servidor (PHP): los
 * mapas vacíos como `{}`, los nulos como `null`, los números de las notas como
 * decimales, y un campo que la app no conoce (`algo_nuevo`) para comprobar que
 * un servidor más nuevo que la app no la rompe.
 */
fun sincronizar(version: String = "v1", tareaHecha: Boolean = false, sinLeer: Int = 2): String = """
{
  "perfil": {"id":7,"nombre":"Ana Pérez","email":"ana@x.py","rol":"cead_acad_student","rol_label":"Alumno/a","caps":["cead_acad_view_own_grades"],"curso":{"id":3,"titulo":"3° B"},"avatar":"https://x/a.png"},
  "mis_datos": {"nombre":"Ana Pérez","email":"ana@x.py","usuario":"ana","telefono":"0981123456","telefono_verificado":false,"foto":null,"iniciales":"AP"},
  "horario": {"curso":{"id":3,"titulo":"3° B"},"dias":[{"dia":1,"franjas":[{"inicio":"07:00","fin":"07:40","materia":"Matemática","docente":"Prof. Gómez","aula":"12"},{"inicio":"07:40","fin":"08:20","materia":"Lengua","docente":"","aula":""}]}]},
  "comunicados": {"comunicados":[
     {"id":10,"titulo":"Examen de matemática","fecha":"2026-10-09T19:23:36+00:00","resumen":"El viernes.","imagen":null,"categoria":{"slug":"academico","nombre":"Académico"},"leido":false,"contenido":"<p>El <strong>viernes</strong> hay examen.</p>"},
     {"id":9,"titulo":"Feriado","fecha":"2026-10-01T10:00:00+00:00","resumen":"No hay clases.","imagen":"https://x/i.jpg","categoria":null,"leido":true,"contenido":"<p>Sin clases.</p>"}
   ],"sin_leer":$sinLeer},
  "boletin": {"materias":[{"materia":"Matemática","curso":"3° B","notas":{"Primera Etapa":{"nota":4.0,"letra":"","comentarios":"","cargada":"2026-09-01 10:00:00"}}}],"periodos":["Primera Etapa"]},
  "tareas": {"tareas":[{"id":55,"titulo":"Traer la plata del paseo","detalle":"","vence":"2026-10-12","prioridad":"alta","hecha":$tareaHecha}]},
  "calendario": {"eventos":[{"id":80,"titulo":"Acto del 12","detalle":"","inicio":"2026-10-12T08:00","fin":"","todo_el_dia":false,"tipo":"acto","lugar":"Patio"}]},
  "recursos": {"recursos":[{"id":30,"titulo":"Guía de ejercicios","descripcion":"","fecha":"2026-09-20T00:00:00+00:00","url":"https://x/wp-content/uploads/guia.pdf","es_archivo":true,"tipo_mime":"application/pdf","tamano":120000,"materias":[{"slug":"mat","nombre":"Matemática"}],"tipos":[],"imagen":null,"favorito":false}]},
  "encuestas": {"encuestas":[
     {"id":90,"titulo":"Clima escolar","descripcion":"","abierta":true,"anonima":true,"abre":null,"cierra":null,"respondida":null,"preguntas":[{"id":1,"texto":"¿Cómo te sentís?","tipo":"scale","obligatoria":true,"opciones":[],"min":1,"max":5}]},
     {"id":91,"titulo":"Materias","descripcion":"","abierta":true,"anonima":false,"abre":null,"cierra":null,"respondida":false,"preguntas":[]}
   ]},
  "faq": [{"id":1,"pregunta":"¿Cómo justifico una falta?","respuesta":"<p>En secretaría.</p>"}],
  "mis_mensajes": {"mensajes":[],"reportes":[]},
  "categorias_reporte": ["Bullying / acoso","Otro"],
  "gestion": {},
  "preferencias_push": {"comunicados":true,"eventos":true,"tareas":true,"notas":true,"buzon":true},
  "algo_nuevo": {"que":"la app no conoce"},
  "version": "$version",
  "generado": "2026-10-09T19:30:00+00:00"
}
""".trimIndent()
