import Foundation
import Security
import CeadKit

/**
 * Dónde vive el token en el teléfono.
 *
 * Va al Llavero y no a `UserDefaults`: el token es una credencial de larga
 * vida, y `UserDefaults` es un plist en claro dentro del contenedor de la app —
 * cualquiera con un backup sin cifrar o un aparato con jailbreak lo lee.
 *
 * `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly` es la parte que importa y
 * se eligió con cuidado:
 *
 *  - `AfterFirstUnlock` y no `WhenUnlocked` porque si mañana la app refresca
 *    algo en segundo plano, con la pantalla bloqueada no podría leer el token y
 *    fallaría sin motivo visible.
 *  - `ThisDeviceOnly` porque un token no tiene por qué viajar en el backup de
 *    iCloud a un teléfono nuevo. La sesión es de ESTE aparato; en el otro se
 *    entra de nuevo, que es justamente lo que la lista de dispositivos del
 *    panel da por sentado.
 */
final class Llavero: AlmacenDeSesion, @unchecked Sendable {

    private let servicio = "py.edu.cead.panel"

    var token: String? {
        get { leer("token") }
        set { escribir("token", newValue) }
    }

    /// El nombre de quien entró, para poder saludar antes de que cargue el
    /// perfil. No es secreto, pero va al mismo lugar para que `limpiar()` sea
    /// una sola operación y no queden restos de una sesión cerrada.
    var nombre: String? {
        get { leer("nombre") }
        set { escribir("nombre", newValue) }
    }

    func limpiar() {
        for clave in ["token", "nombre"] {
            SecItemDelete(consulta(clave) as CFDictionary)
        }
    }

    // MARK: - Interno

    private func consulta(_ clave: String) -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: servicio,
            kSecAttrAccount as String: clave,
        ]
    }

    private func leer(_ clave: String) -> String? {
        var q = consulta(clave)
        q[kSecReturnData as String] = true
        q[kSecMatchLimit as String] = kSecMatchLimitOne

        var resultado: CFTypeRef?
        guard SecItemCopyMatching(q as CFDictionary, &resultado) == errSecSuccess,
              let datos = resultado as? Data else {
            return nil
        }
        return String(data: datos, encoding: .utf8)
    }

    private func escribir(_ clave: String, _ valor: String?) {
        guard let valor, !valor.isEmpty else {
            SecItemDelete(consulta(clave) as CFDictionary)
            return
        }

        // El Llavero no tiene «guardar»: hay que agregar o actualizar según
        // exista o no. Se borra primero para que el alta sea siempre el mismo
        // camino, en vez de dos con condiciones distintas.
        SecItemDelete(consulta(clave) as CFDictionary)

        var q = consulta(clave)
        q[kSecValueData as String] = Data(valor.utf8)
        q[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly
        SecItemAdd(q as CFDictionary, nil)
    }
}
