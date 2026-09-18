# kotlinx.serialization genera los serializadores en tiempo de compilación y los
# alcanza por reflexión sobre el `Companion`. Sin estas reglas la app compila,
# pasa las pruebas en debug y explota en release al primer pedido a la API —
# que es el peor momento para enterarse.
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**

-keepclassmembers class py.edu.cead.panel.data.** {
    *** Companion;
}
-keepclasseswithmembers class py.edu.cead.panel.data.** {
    kotlinx.serialization.KSerializer serializer(...);
}
-keep,includedescriptorclasses class py.edu.cead.panel.data.**$$serializer { *; }

# Ktor elige el motor por ServiceLoader.
-keep class io.ktor.client.engine.android.** { *; }

# Tink —lo que cifra el token por debajo de EncryptedSharedPreferences— viene
# compilado contra anotaciones que no se empaquetan en la app: son de tiempo de
# compilación y no existen en runtime. R8 no lo sabe y corta el build entero.
#
# Sin esto no hay APK de release posible, que es un detalle feo de descubrir la
# primera vez que uno intenta subir a Play y no antes.
-dontwarn com.google.errorprone.annotations.**
-dontwarn javax.annotation.Nullable
-dontwarn javax.annotation.concurrent.GuardedBy

# slf4j busca su implementación por reflexión; en Android no hay ninguna y el
# propio slf4j lo maneja: cae a un logger que no hace nada.
-dontwarn org.slf4j.impl.StaticLoggerBinder
