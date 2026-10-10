import UIKit
import UserNotifications
import Shared
#if canImport(FirebaseMessaging)
import FirebaseCore
import FirebaseMessaging
#endif

/// Los avisos al teléfono. El SDK de Firebase es de Swift, así que esta parte
/// vive acá y le cuenta a la app (Kotlin) lo que pasa a través de
/// `PuenteAvisosIos`: si Firebase quedó listo, cuál es el token y qué aviso
/// tocó la persona.
final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {

    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {
        UNUserNotificationCenter.current().delegate = self

        #if canImport(FirebaseMessaging)
        // Sin el archivo de configuración del colegio no se arranca Firebase:
        // configurarlo sin él haría fallar la app. Ver docs/AVISOS-PUSH.md.
        if Bundle.main.path(forResource: "GoogleService-Info", ofType: "plist") != nil {
            FirebaseApp.configure()
            Messaging.messaging().delegate = self
            PuenteAvisosIos.shared.disponible = true
        }
        #endif
        return true
    }

    func application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        #if canImport(FirebaseMessaging)
        Messaging.messaging().apnsToken = deviceToken
        #endif
    }

    /// Con la app abierta, el aviso también se muestra.
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        completionHandler([.banner, .list, .sound])
    }

    /// La persona tocó un aviso: se abre lo que el aviso dice (el comunicado, el buzón…).
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let datos = response.notification.request.content.userInfo
        let tipo = datos["tipo"] as? String
        let id = (datos["id"] as? String) ?? (datos["id"] as? NSNumber)?.stringValue
        PuenteAvisosIos.shared.avisoTocado(tipo: tipo, id: id)
        completionHandler()
    }
}

#if canImport(FirebaseMessaging)
extension AppDelegate: MessagingDelegate {
    func messaging(_ messaging: Messaging, didReceiveRegistrationToken fcmToken: String?) {
        if let token = fcmToken {
            PuenteAvisosIos.shared.tokenNuevo(nuevo: token)
        }
    }
}
#endif
