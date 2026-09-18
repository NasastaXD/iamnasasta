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
