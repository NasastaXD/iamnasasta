import SwiftUI
import Shared

/// Toda la app se dibuja desde Kotlin (`shared/`): esto solo la arranca.
@main
struct CEADApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var delegado
    @Environment(\.scenePhase) private var fase

    var body: some Scene {
        WindowGroup {
            VistaCompose()
                // Compose maneja por su cuenta las zonas seguras (la muesca, la
                // barra de abajo) y el teclado.
                .ignoresSafeArea(.all)
        }
        .onChange(of: fase) { nueva in
            // Al volver a la app, se sincroniza si hace rato que no se hacía.
            if nueva == .active {
                MainViewControllerKt.alVolverAlPrimerPlano()
            }
        }
    }
}

private struct VistaCompose: UIViewControllerRepresentable {
    func makeUIViewController(context: Context) -> UIViewController {
        MainViewControllerKt.MainViewController()
    }

    func updateUIViewController(_ controlador: UIViewController, context: Context) {}
}
