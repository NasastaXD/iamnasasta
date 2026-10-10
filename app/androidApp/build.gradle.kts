plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.compose.compiler)
}

/*
 * Los avisos al teléfono necesitan el archivo de configuración de Firebase del
 * colegio. Es de cada instalación y no se sube al repositorio, así que sin él
 * la app compila y anda igual, solo que sin timbre. Ver docs/AVISOS-PUSH.md.
 */
if (file("google-services.json").exists()) {
    pluginManager.apply("com.google.gms.google-services")
}

android {
    namespace = "net.caaguazu.cead.panel"
    compileSdk = 36

    defaultConfig {
        applicationId = "net.caaguazu.cead.panel"
        minSdk = 24
        targetSdk = 36
        // Google Play rechaza un paquete cuyo versionCode no sea MAYOR al último
        // que se subió. Se empieza alto a propósito, por si la app que ya está
        // publicada se subió con un número chico. Si hiciera falta más, se
        // sube acá (o se pasa -Pcead.versionCode=N al compilar).
        versionCode = (findProperty("cead.versionCode") as String?)?.toIntOrNull() ?: 10000
        versionName = "1.0.0"
    }

    /*
     * La firma de release. Los datos de la llave NO van en el repositorio: se
     * ponen en ~/.gradle/gradle.properties (fuera del proyecto) con estas
     * cuatro líneas, y sin ellas se genera un APK sin firmar:
     *
     *   cead.keystore=/ruta/a/la/llave.jks
     *   cead.keystorePassword=...
     *   cead.keyAlias=...
     *   cead.keyPassword=...
     */
    val llave = findProperty("cead.keystore") as String?
    if (llave != null) {
        signingConfigs {
            create("release") {
                storeFile = file(llave)
                storePassword = findProperty("cead.keystorePassword") as String?
                keyAlias = findProperty("cead.keyAlias") as String?
                keyPassword = findProperty("cead.keyPassword") as String?
            }
        }
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.findByName("release")
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
        debug {
            isMinifyEnabled = false
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
    }
}

dependencies {
    implementation(project(":shared"))
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.fragment)
    implementation(libs.okio)
    implementation(libs.kotlinx.coroutines.android)
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)
}
