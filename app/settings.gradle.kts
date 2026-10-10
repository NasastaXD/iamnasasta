pluginManagement {
    repositories {
        google {
            content {
                includeGroupByRegex("com\\.android.*")
                includeGroupByRegex("com\\.google.*")
                includeGroupByRegex("androidx.*")
            }
        }
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "CEAD"

/*
 * `shared` es TODA la app: pantallas, red, almacenamiento y cola de envíos, en
 * un solo código Kotlin para Android y iOS. `androidApp` y `iosApp` son
 * cáscaras finas que solo la arrancan.
 */
include(":shared")
include(":androidApp")
