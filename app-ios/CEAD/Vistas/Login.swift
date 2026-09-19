import SwiftUI

struct PantallaLogin: View {

    @ObservedObject var modelo: PanelModel

    @State private var usuario = ""
    @State private var clave = ""
    @FocusState private var foco: Campo?

    private enum Campo { case usuario, clave }

    private var puedeEntrar: Bool {
        !usuario.trimmingCharacters(in: .whitespaces).isEmpty
            && !clave.isEmpty
            && !modelo.entrando
    }

    var body: some View {
        VStack(spacing: 0) {
            Spacer()

            Text("CEAD").font(.largeTitle.bold())
            Text("Panel del colegio")
                .font(.subheadline)
                .foregroundStyle(.secondary)

            VStack(spacing: 12) {
                TextField("Usuario", text: $usuario)
                    .textContentType(.username)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .focused($foco, equals: .usuario)
                    .submitLabel(.next)
                    .onSubmit { foco = .clave }

                SecureField("Contraseña", text: $clave)
                    .textContentType(.password)
                    .focused($foco, equals: .clave)
                    .submitLabel(.go)
                    .onSubmit { if puedeEntrar { entrar() } }
            }
            .textFieldStyle(.roundedBorder)
            .padding(.top, 32)

            if let error = modelo.errorLogin {
                Text(error)
                    .font(.subheadline)
                    .foregroundStyle(.red)
                    .multilineTextAlignment(.center)
                    .padding(.top, 16)
            }

            Button(action: entrar) {
                if modelo.entrando {
                    ProgressView().frame(maxWidth: .infinity)
                } else {
                    Text("Entrar").frame(maxWidth: .infinity)
                }
            }
            .buttonStyle(.borderedProminent)
            .tint(.ceadMarca)
            .controlSize(.large)
            .disabled(!puedeEntrar)
            .padding(.top, 24)

            Spacer()
        }
        .padding(28)
        .disabled(modelo.entrando)
    }

    private func entrar() {
        foco = nil
        modelo.entrar(usuario: usuario, clave: clave)
    }
}
