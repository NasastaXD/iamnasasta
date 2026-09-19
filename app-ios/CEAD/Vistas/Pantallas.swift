import SwiftUI
import CeadKit

// MARK: - Horario

struct PantallaHorario: View {
    let horario: Horario

    var body: some View {
        if horario.curso == nil {
            Vacio(
                titulo: "Todavía no estás en un curso",
                detalle: "Cuando secretaría te asigne a uno, tu horario aparece acá."
            )
        } else if horario.dias.isEmpty {
            Vacio(
                titulo: "Sin horario cargado",
                detalle: "El horario de \(horario.curso?.titulo ?? "tu curso") todavía no fue cargado."
            )
        } else {
            List {
                ForEach(horario.dias) { dia in
                    Section(dia.nombre) {
                        ForEach(Array(dia.franjas.enumerated()), id: \.offset) { _, f in
                            HStack(alignment: .top, spacing: 12) {
                                Text(f.inicio.isEmpty ? "--:--" : f.inicio)
                                    .font(.callout.monospacedDigit())
                                    .foregroundStyle(.secondary)
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(f.materia).font(.body.weight(.medium))
                                    let detalle = [f.docente, f.aula]
                                        .filter { !$0.isEmpty }
                                        .joined(separator: " · ")
                                    if !detalle.isEmpty {
                                        Text(detalle).font(.caption).foregroundStyle(.secondary)
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

// MARK: - Comunicados

struct PantallaComunicados: View {
    let datos: Comunicados
    let abrir: (Int) -> Void

    var body: some View {
        if datos.comunicados.isEmpty {
            Vacio(
                titulo: "Sin comunicados",
                detalle: "Cuando el colegio publique algo, lo vas a ver acá."
            )
        } else {
            List(datos.comunicados) { c in
                Button { abrir(c.id) } label: {
                    VStack(alignment: .leading, spacing: 4) {
                        if let cat = c.categoria {
                            Text(cat.nombre.uppercased())
                                .font(.caption2.weight(.semibold))
                                .foregroundStyle(Color.ceadMarca)
                        }
                        // Lo no leído pesa más: es la única señal que distingue
                        // lo que falta mirar de lo que ya se miró.
                        Text(c.titulo)
                            .font(.headline)
                            .fontWeight(c.leido ? .regular : .bold)
                        if !c.resumen.isEmpty {
                            Text(c.resumen)
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                                .lineLimit(3)
                        }
                    }
                }
                .buttonStyle(.plain)
            }
        }
    }
}

struct VistaComunicado: View {
    let comunicado: Comunicado

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                Text(comunicado.titulo).font(.title2.bold())
                // El cuerpo viene como HTML del editor de WordPress. Se
                // convierte a texto con formato en vez de mostrar las etiquetas
                // crudas, y sin WebView: no hace falta un navegador entero para
                // leer un comunicado.
                Text(textoDelCuerpo)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding()
        }
    }

    private var textoDelCuerpo: AttributedString {
        let html = comunicado.contenido ?? comunicado.resumen
        guard let datos = html.data(using: .utf8),
              let attr = try? NSAttributedString(
                  data: datos,
                  options: [
                      .documentType: NSAttributedString.DocumentType.html,
                      .characterEncoding: String.Encoding.utf8.rawValue,
                  ],
                  documentAttributes: nil
              ) else {
            // Si el HTML no se puede interpretar, mejor el texto crudo que una
            // pantalla vacía: el comunicado igual se lee.
            return AttributedString(html)
        }
        return AttributedString(attr)
    }
}

// MARK: - Boletín

struct PantallaBoletin: View {
    let boletin: Boletin

    var body: some View {
        if boletin.materias.isEmpty {
            Vacio(
                titulo: "Sin calificaciones cargadas",
                detalle: "Cuando dirección o tus docentes carguen notas, vas a verlas acá."
            )
        } else {
            List(boletin.materias) { m in
                Section(m.materia) {
                    // Se recorre la lista de períodos del servidor y no las
                    // claves del diccionario: así todas las materias se leen en
                    // la misma columna aunque a alguna le falte un período.
                    ForEach(boletin.periodos, id: \.self) { periodo in
                        HStack {
                            Text(periodo)
                            Spacer()
                            Text(m.notas[periodo]?.texto ?? "—")
                                .fontWeight(.medium)
                                .monospacedDigit()
                        }
                    }
                }
            }
        }
    }
}

// MARK: - Tareas

struct PantallaTareas: View {
    let datos: Tareas

    private var pendientes: [Tarea] { datos.tareas.filter { !$0.hecha } }
    private var hechas: [Tarea] { datos.tareas.filter(\.hecha) }

    var body: some View {
        if datos.tareas.isEmpty {
            Vacio(titulo: "Sin tareas", detalle: "No tenés tareas cargadas en este momento.")
        } else {
            List {
                if !pendientes.isEmpty {
                    Section("Pendientes") {
                        ForEach(pendientes) { FilaTarea(tarea: $0) }
                    }
                }
                if !hechas.isEmpty {
                    Section("Hechas") {
                        ForEach(hechas) { FilaTarea(tarea: $0) }
                    }
                }
            }
        }
    }
}

private struct FilaTarea: View {
    let tarea: Tarea

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(tarea.titulo)
                .foregroundStyle(tarea.hecha ? .secondary : .primary)
            let pie = [
                tarea.vence.flatMap { $0.isEmpty ? nil : "Vence \($0)" },
                tarea.prioridad == "alta" ? "Prioridad alta" : nil,
            ].compactMap { $0 }.joined(separator: " · ")
            if !pie.isEmpty {
                Text(pie).font(.caption).foregroundStyle(.secondary)
            }
        }
    }
}
