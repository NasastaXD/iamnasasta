# Cómo modificar y extender el bot (guía para devs / IA)

Esta guía explica dónde tocar para los cambios más comunes. El módulo está
pensado para que cada cambio sea **local y obvio**.

## Mapa de archivos

| Querés cambiar… | Archivo |
|---|---|
| El **texto** de cualquier mensaje | wp-admin → *WhatsApp · Mensajes* (sin código), o `class-wa-tables.php` → `default_messages()` |
| El **nombre/grupo** de un mensaje en el panel | `class-wa-tables.php` → `message_meta()` |
| La **lógica del menú** (qué hace cada opción) | `class-wa-engine.php` |
| Acceso a la base de datos del bot | `class-wa-store.php` |
| Envío/recepción con el bridge | `class-wa-bridge-client.php` / `class-wa-rest.php` |
| Reconocer un teléfono como alumno/staff | `class-wa-identity.php` |
| Envío masivo / lotes | `class-wa-broadcaster.php` |
| Tareas automáticas (recordatorios, etc.) | `class-wa-cron.php` |
| Pantallas de wp-admin | `class-wa-admin.php` |

> **Regla de oro de los mensajes:** el texto y la lógica están separados. Editar
> el texto de un menú **no** crea ni borra funciones; reconocer las opciones es
> código (ver receta 2).

---

## Receta 1 — Cambiar un texto

Lo más simple: **wp-admin → CEAD Académico → WhatsApp · Mensajes**, editás y
guardás. Para cambiar el valor *por defecto* (el que trae una instalación
nueva), editá la entrada en `default_messages()` de `class-wa-tables.php`.

Las variables `{name}`, `{count}`, `{events}`, etc. se reemplazan solas; hay que
conservarlas.

---

## Receta 2 — Agregar una opción al menú del alumnado

Ej.: agregar "11. Biblioteca". Son 3–4 pasos, todos en archivos distintos pero
claros:

1. **Texto del menú** — en `class-wa-tables.php` → `default_messages()`, agregá
   la línea al mensaje `student_menu` (y, si querés un mensaje propio para la
   respuesta, agregá una clave nueva; ver Receta 3).

2. **Reconocer la opción** — en `class-wa-engine.php`, método `student_menu()`,
   agregá un `case`:
   ```php
   case '11': $this->show_biblioteca( $phone, $identity ); break;
   ```

3. **Handler** — en el mismo archivo, creá el método:
   ```php
   private function show_biblioteca( $phone, $identity ) {
       $this->send( $phone, $this->m( 'biblioteca_info' ) );
       $this->back_to_student( $phone );  // vuelve al menú
   }
   ```

4. **(Opcional) Metadato del panel** — en `message_meta()` agregá
   `'biblioteca_info' => [ 'info', 'Biblioteca: info' ]` para que aparezca
   agrupado y con nombre claro en el panel.

Patrones útiles dentro de los handlers: `$this->m('clave')` trae el texto,
`$this->send($phone, $texto)` responde, `$this->store->set_state(...)` arranca
una conversación de varios pasos.

---

## Receta 3 — Agregar un mensaje nuevo

1. `message_meta()`: `'mi_clave' => [ 'info', 'Etiqueta legible' ]`.
2. `default_messages()`: `'mi_clave' => 'Texto por defecto con {variable}.'`.
3. Usalo en el motor: `$this->send( $phone, $this->m('mi_clave') )`.
4. Subí `CEAD_ACAD_DB_VERSION` en `cead-acad.php` para que el seeding cargue la
   clave en instalaciones existentes (idempotente).

---

## Receta 4 — Agregar una acción al menú del personal

Cada rol tiene su propio menú (selector inicial "¿A qué menú entrar?"). Los menús
son **declarativos y por capacidad**, definidos en `role_menus()` de
`class-wa-engine.php`:

1. `role_menus()`: agregá la acción `[ key, label, cap ]` al/los rol(es) deseados
   (cap `''` = siempre visible):
   ```php
   [ 'survey', 'Lanzar encuesta', 'cead_acad_create_survey' ],
   ```
2. `staff_menu()`: agregá el `case` que despacha por `key`:
   ```php
   case 'survey': $this->survey_start( $phone, $identity ); break;
   ```
3. Creá el handler. Protegé la acción con
   `if ( ! $this->require_cap( $phone, $identity, 'cead_acad_create_survey' ) ) return;`
   y terminá con `$this->reenter_staff( $phone );` para volver al menú del rol.

Las capacidades son las de cead-acad (`includes/class-cead-acad-capabilities.php`).
Para un atajo de una línea (estilo `-AA`/`-AE`), agregá la detección en
`maybe_handle_shortcut()`.

### Notas de features recientes
- **Artículos** = posts WP (`post_type 'post'`), cap `cead_acad_manage_articles`.
- **Roles por chat** (`roles` / `assign_role_to_phone()`): whitelist de roles no
  administrativos; cap `cead_acad_manage_roles`; crea el usuario si el número no existe.
- **Comunicados/anuncios** crean además un `cead_acad_broadcast`
  (`Cead_Acad_WA_Broadcaster::create_broadcast_post()`) para verse en el panel web.
- **Imágenes**: el bridge expone `/api/send-image`; el broadcaster reenvía la imagen
  del job; `store_image()` la guarda en la biblioteca de medios.

---

## Receta 5 — Usar un dato nuevo de cead-acad

El bot lee datos reales del plugin. Reutilizá las clases existentes en vez de
duplicar:

- Eventos/horarios: `Cead_Acad_Schedule_Feed::for_user( $uid, $from, $to )`.
- Comunicados: `Cead_Acad_Broadcasts_Feed::for_user( $uid, [...] )`.
- Cursos: `Cead_Acad_Courses_Roster::courses_for_user( $uid )`.
- Audiencias: `Cead_Acad_Audiences::*`.

Para personalizar, resolvé primero la identidad:
```php
$identity = Cead_Acad_WA_Identity::resolve( $phone ); // ['user_id','is_student','is_staff']
if ( $identity['user_id'] ) { /* personalizado */ } else { /* general */ }
```

---

## Receta 6 — Cambiar el comportamiento del envío masivo

`class-wa-broadcaster.php`: `BATCH_SIZE` (cuántos por tanda) y el `sleep(1)`
entre mensajes controlan el ritmo. El job vive en una opción y lo drena el cron
`Cead_Acad_WA_Cron::BROADCAST_EVENT`.

---

## Convenciones

- Clases `Cead_Acad_WA_*`, una por archivo en `modules/whatsapp/`.
- Tablas vía `cead_acad_table('wa_*')` (agregá nombres nuevos al whitelist de
  `includes/helpers.php`).
- Todo el acceso a BD pasa por `Cead_Acad_WA_Store`.
- Validá siempre `php -l` y, para cambios de schema, subí `CEAD_ACAD_DB_VERSION`.
