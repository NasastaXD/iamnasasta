package net.caaguazu.cead.panel.plataforma

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * El token en un almacén cifrado con una llave que vive en el chip de
 * seguridad del teléfono. Un respaldo de la memoria o un teléfono con root no
 * lo leen en claro.
 */
class AlmacenSeguroAndroid(private val contexto: Context) : AlmacenSeguro {

    private val prefs: SharedPreferences by lazy { abrir() }

    private fun abrir(): SharedPreferences {
        return try {
            crear()
        } catch (e: Exception) {
            // La llave del chip puede perderse (restaurar el teléfono, cambiar el
            // bloqueo de pantalla en algunos modelos). El archivo cifrado queda
            // ilegible para siempre: se borra y se arranca de nuevo, lo que
            // significa volver a iniciar sesión. Es mejor que no poder abrir la app.
            contexto.deleteSharedPreferences(ARCHIVO)
            crear()
        }
    }

    private fun crear(): SharedPreferences {
        val llave = MasterKey.Builder(contexto).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build()
        return EncryptedSharedPreferences.create(
            contexto,
            ARCHIVO,
            llave,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    override fun leer(clave: String): String? = prefs.getString(clave, null)

    override fun guardar(clave: String, valor: String) {
        prefs.edit().putString(clave, valor).apply()
    }

    override fun borrar(clave: String) {
        prefs.edit().remove(clave).apply()
    }

    private companion object {
        const val ARCHIVO = "cead_seguro"
    }
}
