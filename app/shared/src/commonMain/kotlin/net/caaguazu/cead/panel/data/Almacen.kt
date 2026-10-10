package net.caaguazu.cead.panel.data

import okio.FileSystem
import okio.Path
import okio.Path.Companion.toPath

/**
 * Archivos de la app en el disco del teléfono.
 *
 * Todo se escribe en un archivo temporal y se mueve de golpe sobre el
 * definitivo. Si el teléfono se queda sin batería a mitad de una escritura, el
 * archivo viejo sigue intacto: un snapshot o una cola de envíos a medio
 * escribir sería peor que no tener ninguno.
 */
class Almacen(private val sistema: FileSystem, raiz: String) {

    private val dir: Path = raiz.toPath()

    init {
        sistema.createDirectories(dir)
    }

    private fun ruta(nombre: String): Path {
        // Los nombres los arma la app, pero un archivo con `..` sacaría la
        // escritura de este directorio: se corta acá y no se confía en el llamador.
        require(!nombre.contains("..") && !nombre.startsWith("/")) { "nombre inválido: $nombre" }
        return dir / nombre
    }

    fun leer(nombre: String): String? {
        val p = ruta(nombre)
        return try {
            if (sistema.exists(p)) sistema.read(p) { readUtf8() } else null
        } catch (e: Exception) {
            null
        }
    }

    fun escribir(nombre: String, texto: String) {
        val destino = ruta(nombre)
        destino.parent?.let { sistema.createDirectories(it) }
        val tmp = ruta("$nombre.tmp")
        sistema.write(tmp) { writeUtf8(texto) }
        sistema.atomicMove(tmp, destino)
    }

    fun existe(nombre: String): Boolean = sistema.exists(ruta(nombre))

    fun borrar(nombre: String) {
        val p = ruta(nombre)
        if (sistema.exists(p)) sistema.deleteRecursively(p)
    }

    /** Los nombres que empiezan con este prefijo, directamente dentro del directorio. */
    fun listar(prefijo: String): List<String> =
        sistema.listOrNull(dir)?.map { it.name }?.filter { it.startsWith(prefijo) && !it.endsWith(".tmp") } ?: emptyList()

    fun rutaAbsoluta(nombre: String): String = ruta(nombre).toString()

    /** Para cerrar sesión: no queda nada de nadie. */
    fun borrarTodo() {
        if (sistema.exists(dir)) sistema.deleteRecursively(dir)
        sistema.createDirectories(dir)
    }
}
