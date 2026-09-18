package py.edu.cead.panel.data

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/**
 * Dónde vive el token en el teléfono.
 *
 * Va cifrado con la clave del Keystore del aparato y no en un `SharedPreferences`
 * común: el token es una credencial de larga vida, y en un teléfono rooteado o
 * en un backup el archivo plano se lee sin esfuerzo.
 *
 * Si el almacén cifrado no se puede abrir —pasa en aparatos con el Keystore
 * roto, que existen— se cae a uno común en vez de dejar la app inutilizable. La
 * alternativa sería que a esa persona la app no le abra nunca y sin decirle por
 * qué.
 */
class Almacen(context: Context) {

    private val prefs: SharedPreferences = abrir(context)

    private fun abrir(context: Context): SharedPreferences = try {
        val clave = MasterKey.Builder(context)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            context,
            "cead_sesion",
            clave,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    } catch (e: Exception) {
        context.getSharedPreferences("cead_sesion_plano", Context.MODE_PRIVATE)
    }

    var token: String?
        get() = prefs.getString(TOKEN, null)
        set(valor) = prefs.edit().apply {
            if (valor == null) remove(TOKEN) else putString(TOKEN, valor)
        }.apply()

    var nombre: String?
        get() = prefs.getString(NOMBRE, null)
        set(valor) = prefs.edit().putString(NOMBRE, valor).apply()

    fun limpiar() = prefs.edit().clear().apply()

    val haySesion: Boolean get() = !token.isNullOrBlank()

    private companion object {
        const val TOKEN = "token"
        const val NOMBRE = "nombre"
    }
}
