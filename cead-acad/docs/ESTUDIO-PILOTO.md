# Estudio piloto en CEAD (web)

El estudio del Premio Nacional Juvenil de Ciencias corre en dos lugares: en la **app** (backend
nuevo, repo `Colegios`) y en **este sitio** (CEAD sigue en WordPress). Los dos usan las mismas
reglas y el mismo formato de encuestas, para que los datos sean comparables.

## Puesta en marcha

1. En `wp-config.php` (nunca en la base):

   ```php
   define( 'CEAD_ESTUDIO_SALT', '…' );   // openssl rand -hex 32 — sin esto no se registra nada
   // Opcionales: secretos que hoy están en wp_options (spec 10). Si están, mandan sobre la opción.
   define( 'CEAD_ACAD_WA_KEY', '…' );    // cifrado de reportes del bot (base64 de 32 bytes)
   define( 'CEAD_ACAD_WA_TOKEN', '…' );  // token compartido con el bridge
   define( 'CEAD_ACAD_AI_KEY', '…' );
   define( 'CEAD_ACAD_STT_KEY', '…' );
   define( 'CEAD_ACAD_IMG_KEY', '…' );
   define( 'CEAD_ACAD_IG_TOKEN', '…' );
   define( 'CEAD_ACAD_IG_PROVEEDOR_KEY', '…' );
   ```
2. Modo piloto (apaga bot, CEADI, Instagram y turismo sin borrar código):
   `wp option update cead_acad_modo_piloto 1`
3. Crear la cuenta del equipo del estudio con el rol **Equipo del estudio** (`cead_acad_study_team`).
4. wp-admin → CEAD Académico → **Estudio piloto**: marcar «participa», código (`I1`), fecha de
   inicio y modo de consentimiento.

## Qué hace

| | |
|---|---|
| Eventos | `Cead_Acad_Estudio::registrar()` es el único punto de escritura (seudónimo HMAC, consentimiento, rol, curso generalizado, `meta` sin datos personales) |
| Lecturas | `mark_read( $id, $uid, $ts_cliente, $canal )`: hora real del teléfono + hora del servidor, marca de dudosa |
| Asistencia | `/panel/asistencia` (web) y `GET /asistencia/cursos`, `POST /asistencia` (app, idempotente), consolidado diario |
| Encuestas | Programador WP-Cron horario: una vez por mes y rol, recordatorios, **obligatoria** con 3 días de gracia (el panel y la API mandan a la encuesta; asistencia, perfil y la propia encuesta no se bloquean). Respuestas seudonimizadas, sin IP ni navegador |
| Tags | Cada pregunta puede llevar `features`: si el sitio no tiene esa feature, no se manda y queda en «omitidas». Roles: alumno, docente y director |
| Consentimiento | Acuerdo institucional o individual; retiro desde `/panel/estudio`; importador CSV |
| Exportación | Equipo del estudio: ZIP seudonimizado, k ≥ 5, diccionario |
| Bitácora | Tabla `estudio_bitacora`: envíos (con versión y preguntas omitidas), configuración importada, operaciones sobre datos. Solo se agrega |

## Encuestas editadas en el panel maestro

Las encuestas se editan en el panel maestro de la app (preguntas, opciones, tags, calendario).
Para llevarlas a este sitio: en el panel, `exportar_config_wp` → descargar el JSON → wp-admin →
Estudio piloto → **Importar configuración**. Se valida antes de reemplazar; lo ya enviado no
cambia; queda en la bitácora con el hash del archivo y las versiones de antes y después.

El archivo `config/estudio-encuestas.json` del plugin es la siembra de la spec y se usa solo si
nunca se importó nada.

## Al terminar (12 meses)

La recolección se cierra sola. Exportar por última vez y **borrar `CEAD_ESTUDIO_SALT`** de
`wp-config.php`: desde ahí los datos exportados no se pueden volver a vincular con nadie.
