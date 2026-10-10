package net.caaguazu.cead.panel

import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage

/**
 * Recibe los avisos de Firebase.
 *
 * Con la app en segundo plano el sistema muestra la notificación solo, con el
 * canal que manda el servidor. Este servicio solo hace falta para dos cosas:
 * mostrarla cuando la app está ABIERTA (Firebase no lo hace) y enterarse de
 * que el token del teléfono cambió.
 */
class ServicioAvisos : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        (application as CeadApp).plataforma.push.tokenNuevo(token)
    }

    override fun onMessageReceived(mensaje: RemoteMessage) {
        val n = mensaje.notification ?: return
        val abrir = Intent(this, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        mensaje.data.forEach { (k, v) -> abrir.putExtra(k, v) }

        val pendiente = PendingIntent.getActivity(
            this, mensaje.messageId.hashCode(), abrir,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val notificacion = NotificationCompat.Builder(this, CeadApp.CANAL_AVISOS)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle(n.title)
            .setContentText(n.body)
            .setAutoCancel(true)
            .setContentIntent(pendiente)
            .build()
        getSystemService(Context.NOTIFICATION_SERVICE).let { (it as NotificationManager).notify(mensaje.messageId.hashCode(), notificacion) }
    }
}
