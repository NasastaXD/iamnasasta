# Avisos al teléfono (push)

Con el bot de WhatsApp dado de baja, los avisos de la app —un comunicado nuevo,
una respuesta del buzón, una tarea— llegan como notificación al teléfono. Se
mandan por **Firebase Cloud Messaging (FCM)**, que es **gratis** y no tiene
cuota mensual que pagar.

El servidor ya está listo. Lo único que falta es **conectarlo a un proyecto de
Firebase del colegio**. Hasta que se haga, todo lo demás funciona igual: la app
anda, solo que sin timbre.

## Qué avisa y qué no muestra

| Evento | Quién lo recibe | Qué dice en la pantalla bloqueada |
|---|---|---|
| Comunicado publicado | La audiencia del comunicado | «Nuevo comunicado» + el título |
| Evento cargado | La audiencia del evento | «Nuevo evento» + título y fecha |
| Tarea asignada a un curso | El delegado/a del curso | «Tarea nueva para tu curso» + título |
| Nota cargada | El alumno/a | «Hay una nota nueva» + materia y etapa (**nunca la nota**) |
| Respuesta del buzón | Quien escribió, si se sabe quién es | «Te respondieron» (**nunca el texto**) |
| Reporte o mensaje nuevo en el buzón | Dirección / Secretaría (o el Consejo, si es para el Consejo) | «Entró un reporte nuevo» (genérico) |

- Un **reporte anónimo** no genera aviso de respuesta: el sistema no sabe de
  quién es, y no tiene que averiguarlo. Quien lo mandó lo sigue con su código
  (`RPT-XXXXXX`) desde la app.
- Cada persona puede apagar categorías (comunicados, eventos, tareas, notas,
  buzón) desde la app.
- Los avisos **no se mandan mientras se publica**: se encolan en lotes y salen
  por el cron de WordPress. Publicar un comunicado a todo el colegio no hace
  esperar a nadie.

## Qué hay que hacer (una sola vez)

### 1. Crear el proyecto de Firebase

1. Entrar a <https://console.firebase.google.com> con la cuenta del colegio y
   **Agregar proyecto** (se puede desactivar Google Analytics).
2. **Agregar app → Android**, con el identificador **`net.caaguazu.cead.panel`**.
   Descargar `google-services.json` y copiarlo a `app/androidApp/`.
3. **Agregar app → iOS**, con el mismo identificador. Descargar
   `GoogleService-Info.plist` y copiarlo a `app/iosApp/CEAD/` (antes de generar
   el proyecto de Xcode). Estos dos archivos son de cada instalación y no se
   suben al repositorio.
4. Para iPhone, además: en *Configuración del proyecto → Cloud Messaging →
   Configuración de la app de Apple*, subir una **clave APNs (.p8)** creada en
   developer.apple.com → *Certificates, Identifiers & Profiles → Keys*.

> El identificador `net.caaguazu.cead.panel` es el mismo con el que se publica
> la app en las tiendas. Tiene que coincidir **exactamente**.

### 2. Sacar la clave de la cuenta de servicio

*Configuración del proyecto → Cuentas de servicio → Generar nueva clave
privada.* Baja un archivo `.json`.

**Ese archivo es una llave**: quien lo tenga puede mandar notificaciones a
nombre del colegio. Por eso:

- **No se sube a GitHub ni se manda por chat/mail.**
- **No se guarda en la base de datos de WordPress.**
- Se sube al servidor **fuera de la carpeta pública** (por ejemplo, un nivel
  arriba de `public_html`) y con permisos `600`.

### 3. Decirle a WordPress dónde está

En `wp-config.php`, antes de la línea que dice *«That's all, stop editing!»*:

```php
define( 'CEAD_ACAD_FCM_CREDENTIALS', '/ruta/completa/fuera/de/public_html/cead-fcm.json' );
```

### 4. Comprobar que anda

Con la app instalada e iniciada la sesión, en **Más → Ajustes** hay un botón
**«Probar avisos»**: manda un aviso de prueba al propio teléfono. (Por debajo
llama a `POST /wp-json/cead-acad/v1/dispositivos/prueba`.) La respuesta dice si
Firebase quedó configurado y cuántos teléfonos recibieron el aviso:

```json
{ "configurado": true, "enviados": 1, "descartados": 0, "fallidos": 0 }
```

- `"configurado": false` → falta el paso 3 (o la ruta/permisos del archivo están mal).
- `"fallidos"` mayor que 0 → Firebase rechazó el envío; lo más común es que el
  proyecto no sea el mismo de la app, o que la cuenta de servicio no tenga el
  permiso *Firebase Cloud Messaging API Admin*.

## Si algo no sale

- **El cron de WordPress tiene que estar andando.** Si el hosting lo
  desactivó (`DISABLE_WP_CRON` en `wp-config.php`), tiene que haber un cron del
  sistema que llame a `wp-cron.php` cada pocos minutos; si no, los avisos
  quedan encolados y no salen.
- **Un teléfono deja de recibir avisos cuando cierra la sesión** (o cuando la
  sesión vence, o cambia la contraseña). Es a propósito: un celular prestado o
  vendido no sigue recibiendo los avisos de la cuenta que dejó.
- **Si la clave se filtró**, entrar a Google Cloud Console → *IAM →
  Cuentas de servicio* y **eliminar esa clave**; luego generar otra y reemplazar
  el archivo del servidor.

## Para quien mantenga el código

- `Cead_Acad_Push_Fcm`: firma el JWT, pide el token de acceso (cacheado) y manda
  el mensaje. Distingue «el token ya no existe» (se descarta) de un error
  pasajero o de formato (se conserva): un error nuestro no puede dejar sin
  avisos a todo el colegio.
- `Cead_Acad_Push`: registro de dispositivos (atados a la sesión de la app),
  preferencias, cola por lotes y escuchas de cada evento.
- Los avisos de publicación pasan por `Cead_Acad_Push::marcar_y_avisar()`, que
  avisa **una sola vez** por publicación y recién cuando la audiencia ya está
  guardada.
- Para cambiar la cuenta de servicio sin archivo (por ejemplo, en un entorno
  de pruebas), el filtro `cead_acad_fcm_credentials` devuelve el arreglo
  decodificado.
