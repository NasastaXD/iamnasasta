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
        versionCode = 1
        versionName = "0.2.0"
    }

    buildTypes {
        release {
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
    implementation(libs.kotlinx.coroutines.android)
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)
}
