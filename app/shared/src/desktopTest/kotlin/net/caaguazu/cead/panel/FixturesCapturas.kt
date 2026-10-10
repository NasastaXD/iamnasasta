package net.caaguazu.cead.panel

import kotlinx.datetime.DateTimeUnit
import kotlinx.datetime.plus

/** Un login de alguien del personal: así se ven también las pantallas de gestión. */
const val LOGIN_OK_STAFF = """{"token":"7.abcdef123456.secreto","vence":1900000000,"usuario":{"id":7,"nombre":"Ana Pérez","email":"ana@x.py","rol":"cead_acad_direction","rol_label":"Dirección","caps":["cead_acad_view_own_grades","cead_acad_publish_broadcast","cead_acad_publish_broadcast_all","cead_acad_manage_schedule","cead_acad_record_grade","cead_acad_manage_reports","cead_acad_manage_suggestions","cead_acad_view_metrics","cead_acad_manage_invitations","cead_acad_manage_articles","cead_acad_assign_tasks","cead_acad_complete_delegate_task","cead_acad_view_delegates"],"curso":{"id":3,"titulo":"3° B"},"avatar":null}}"""

/** Una foto del servidor con bastante contenido, para que las capturas se parezcan a un día real. */
fun sincronizarRico(): String {
    val hoy = net.caaguazu.cead.panel.util.Fechas.hoy()
    fun dia(n: Int) = hoy.plus(n, DateTimeUnit.DAY).toString()
    val dias = (1..5).joinToString(",") { d ->
        """{"dia":$d,"franjas":[
          {"inicio":"07:00","fin":"07:40","materia":"Matemática","docente":"Prof. Gómez","aula":"12"},
          {"inicio":"07:40","fin":"08:20","materia":"Lengua y Literatura","docente":"Prof. Benítez","aula":"12"},
          {"inicio":"08:20","fin":"09:00","materia":"Historia","docente":"Prof. Duarte","aula":"4"},
          {"inicio":"09:20","fin":"10:00","materia":"Educación Física","docente":"Prof. Ortiz","aula":"Patio"},
          {"inicio":"10:00","fin":"10:40","materia":"Inglés","docente":"","aula":"7"}]}"""
    }
    return """
{
  "perfil": {"id":7,"nombre":"Ana Pérez","email":"ana@x.py","rol":"cead_acad_direction","rol_label":"Dirección","caps":["cead_acad_view_own_grades","cead_acad_publish_broadcast","cead_acad_manage_reports","cead_acad_view_metrics"],"curso":{"id":3,"titulo":"3° B"},"avatar":null},
  "mis_datos": {"nombre":"Ana Pérez","email":"ana@x.py","usuario":"ana","telefono":"0981123456","telefono_verificado":false,"foto":null,"iniciales":"AP"},
  "horario": {"curso":{"id":3,"titulo":"3° B"},"dias":[$dias]},
  "comunicados": {"comunicados":[
     {"id":10,"titulo":"Examen de matemática del primer trimestre: traer calculadora","fecha":"${hoy}T15:23:36+00:00","resumen":"El viernes a primera hora. Temas: ecuaciones y funciones.","imagen":null,"categoria":{"slug":"academico","nombre":"Académico"},"leido":false,"contenido":"<p>El <strong>viernes</strong> hay examen de matemática a primera hora.</p><ul><li>Ecuaciones de primer grado</li><li>Funciones lineales</li></ul><p>Traer <em>calculadora</em> y regla. Más info en <a href=\"https://cead.caaguazu.net\">el sitio</a>.</p>"},
     {"id":9,"titulo":"Feriado del 12 de octubre","fecha":"${dia(-3)}T10:00:00+00:00","resumen":"No hay clases el lunes.","imagen":null,"categoria":{"slug":"administrativo","nombre":"Administrativo"},"leido":true,"contenido":"<p>No hay clases.</p>"},
     {"id":8,"titulo":"Reunión de padres del tercer curso","fecha":"${dia(-6)}T10:00:00+00:00","resumen":"Sábado a las 8:00 en el salón de actos.","imagen":null,"categoria":{"slug":"eventos","nombre":"Eventos"},"leido":false,"contenido":"<p>Los esperamos.</p>"}
   ],"sin_leer":2},
  "boletin": {"materias":[
     {"materia":"Matemática","curso":"3° B","notas":{"Primera Etapa":{"nota":4.0,"letra":"","comentarios":"Muy buen trabajo en clase","cargada":""},"Segunda Etapa":{"nota":3.5,"letra":"","comentarios":"","cargada":""}}},
     {"materia":"Lengua y Literatura","curso":"3° B","notas":{"Primera Etapa":{"nota":5.0,"letra":"","comentarios":"","cargada":""},"Segunda Etapa":{"nota":1.0,"letra":"","comentarios":"Debe recuperar","cargada":""}}}
   ],"periodos":["Primera Etapa","Segunda Etapa"]},
  "tareas": {"tareas":[
     {"id":55,"titulo":"Traer la plata del paseo a la cascada","detalle":"Son 50.000 Gs. Entregar a la delegada.","vence":"${dia(2)}","prioridad":"alta","hecha":false},
     {"id":56,"titulo":"Informe de Historia: la Guerra Grande","detalle":"","vence":"${dia(-1)}","prioridad":"normal","hecha":false},
     {"id":57,"titulo":"Completar la guía de ejercicios","detalle":"","vence":null,"prioridad":"baja","hecha":true}
   ]},
  "calendario": {"eventos":[
     {"id":80,"titulo":"Acto del Día de la Raza","detalle":"Vestimenta formal.","inicio":"${dia(2)}T08:00","fin":"${dia(2)}T10:00","todo_el_dia":false,"tipo":"acto","lugar":"Patio central"},
     {"id":81,"titulo":"Examen de Matemática","detalle":"","inicio":"${dia(4)}T07:00","fin":"","todo_el_dia":false,"tipo":"examen","lugar":"Aula 12"},
     {"id":82,"titulo":"Receso de invierno","detalle":"","inicio":"${dia(9)}","fin":"${dia(13)}","todo_el_dia":true,"tipo":"feriado","lugar":""},
     {"id":83,"titulo":"Excursión a Cascada Ñu Guazú","detalle":"","inicio":"${dia(15)}T06:00","fin":"${dia(15)}T18:00","todo_el_dia":false,"tipo":"excursion","lugar":"Salida desde el colegio"}
   ]},
  "recursos": {"recursos":[
     {"id":30,"titulo":"Guía de ejercicios: ecuaciones","descripcion":"","fecha":"","url":"https://x/guia.pdf","es_archivo":true,"tipo_mime":"application/pdf","tamano":2300000,"materias":[{"slug":"mat","nombre":"Matemática"}],"tipos":[],"imagen":null,"favorito":true},
     {"id":31,"titulo":"Presentación de la Guerra Grande","descripcion":"","fecha":"","url":"https://x/p.pptx","es_archivo":true,"tipo_mime":"application/vnd.ms-powerpoint","tamano":850000,"materias":[{"slug":"his","nombre":"Historia"}],"tipos":[],"imagen":null,"favorito":false},
     {"id":32,"titulo":"Biblioteca virtual del MEC","descripcion":"","fecha":"","url":"https://mec.gov.py","es_archivo":false,"tipo_mime":null,"tamano":null,"materias":[],"tipos":[],"imagen":null,"favorito":false}
   ]},
  "encuestas": {"encuestas":[
     {"id":90,"titulo":"Clima escolar","descripcion":"Queremos saber cómo te sentís en el colegio.","abierta":true,"anonima":true,"abre":null,"cierra":"${dia(5)}","respondida":null,"preguntas":[{"id":1,"texto":"¿Cómo te sentís en el colegio?","tipo":"scale","obligatoria":true,"opciones":[],"min":1,"max":5}]},
     {"id":91,"titulo":"Elección de materias optativas","descripcion":"","abierta":true,"anonima":false,"abre":null,"cierra":null,"respondida":false,"preguntas":[
        {"id":1,"texto":"¿Qué materia preferís?","tipo":"radio","obligatoria":true,"opciones":["Robótica","Teatro","Fotografía"],"min":null,"max":null},
        {"id":2,"texto":"¿Qué actividades te gustaría sumar?","tipo":"checkbox","obligatoria":false,"opciones":["Deportes","Música","Arte"],"min":null,"max":null},
        {"id":3,"texto":"¿Algo más que quieras contarnos?","tipo":"long_text","obligatoria":false,"opciones":[],"min":null,"max":null}]},
     {"id":92,"titulo":"Satisfacción con el comedor","descripcion":"","abierta":false,"anonima":false,"abre":null,"cierra":null,"respondida":true,"preguntas":[]}
   ]},
  "faq": [{"id":1,"pregunta":"¿Cómo justifico una falta?","respuesta":"<p>Presentá la justificación en <strong>secretaría</strong> dentro de las 48 horas.</p>"},{"id":2,"pregunta":"¿Cuándo se entregan los boletines?","respuesta":"<p>Al final de cada etapa.</p>"}],
  "mis_mensajes": {"mensajes":[{"id":1,"para":"direccion","texto":"¿Puedo cambiar de turno?","estado":"in_review","respuesta":"Pasá por secretaría con tu cédula.","creado":""}],"reportes":[]},
  "categorias_reporte": ["Bullying / acoso","Seguridad","Infraestructura","Otro"],
  "gestion": {
    "audiencias_comunicado": {"todo_el_colegio":true,"roles":[{"valor":"cead_acad_student","nombre":"Alumno/a"},{"valor":"cead_acad_delegate","nombre":"Delegado/a"},{"valor":"cead_acad_teacher","nombre":"Docente"}],"cursos":[{"valor":"3","nombre":"3° B"},{"valor":"4","nombre":"1° A"},{"valor":"5","nombre":"2° A"}],"promociones":[{"valor":"9","nombre":"Promoción 2026"}],"categorias":[{"valor":"academico","nombre":"Académico"},{"valor":"urgente","nombre":"Urgente"},{"valor":"eventos","nombre":"Eventos"}]},
    "audiencias_evento": {"todo_el_colegio":true,"roles":[{"valor":"cead_acad_student","nombre":"Alumno/a"}],"cursos":[{"valor":"3","nombre":"3° B"}],"promociones":[]},
    "notas": {"periodos":["Primera Etapa","Segunda Etapa","Final"],"escala":{"maxima":5,"aprobado":2,"etiquetas":{"5":"Sobresaliente","4":"Distinguido","3":"Bueno","2":"Regular","1":"Insuficiente"}},"materias":[{"id":1,"nombre":"Matemática"},{"id":2,"nombre":"Lengua y Literatura"}],"cursos":[{"id":3,"titulo":"3° B","alumnos":[{"id":21,"nombre":"Juan Pérez"},{"id":22,"nombre":"María González"},{"id":23,"nombre":"Luis Benítez"}]}]},
    "articulos": {"categorias":[{"id":4,"nombre":"Noticias"},{"id":5,"nombre":"Deportes"}],"formatos":[{"slug":"noticia","nombre":"Noticia","pide":[]},{"slug":"evento","nombre":"Evento","pide":["fecha","lugar"]}],"redes":true},
    "tareas_del_curso": [{"id":70,"titulo":"Juntar firmas para el paseo","detalle":"Una por alumno.","curso":{"id":3,"titulo":"3° B"},"estado":"en_curso","prioridad":"alta","vence":"2026-10-20"},{"id":71,"titulo":"Decorar el aula","detalle":"","curso":{"id":3,"titulo":"3° B"},"estado":"pendiente","prioridad":"normal","vence":null}]
  },
  "preferencias_push": {"comunicados":true,"eventos":true,"tareas":false,"notas":true,"buzon":true},
  "version": "v1",
  "generado": ""
}
""".trimIndent()
}
