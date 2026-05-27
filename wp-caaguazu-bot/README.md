# Caaguazú Bot — Plugin WordPress + WhatsApp

Bot de WhatsApp para gestionar el sitio caaguazu.net desde el celular. Permite publicar, editar y borrar artículos, y responde consultas de lectores, sin necesidad de abrir el panel de WordPress.

---

## Arquitectura resumida

```
[WhatsApp] ←→ [Baileys Bridge (PC del admin)] ←→ [Cloudflare Tunnel (HTTPS)]
                                                              ↕
                                          [Plugin WordPress (shared hosting)]
                                                              ↕
                                                   [Base de datos MySQL WP]
```

---

## Requisitos

### Plugin WordPress (servidor)
- WordPress 6.0 o superior
- PHP 8.0 o superior
- Hosting compartido (cPanel) o VPS — cualquiera que soporte PHP es suficiente
- Acceso para subir plugins (FTP/cPanel File Manager)

### Bridge (su PC)
- Node.js 18 o superior → https://nodejs.org
- Conexión estable a internet
- La PC debe estar encendida mientras el bot esté activo

### WhatsApp
- Un número de WhatsApp **dedicado** exclusivamente al bot (no use su número personal)
- El número debe estar activo en un teléfono para el escaneo inicial del QR

---

## Instalación paso a paso

### 1. Instalar el plugin en WordPress

1. Suba la carpeta `wp-caaguazu-bot/` completa a `/wp-content/plugins/` de su WordPress
2. Vaya a **WordPress → Plugins** y active **Caaguazú Bot**
3. Verá el menú **Caaguazú Bot** en el panel de administración

### 2. Instalar el bridge en su PC

1. Copie la carpeta `bridge/` a su PC
2. Abra una terminal en esa carpeta y ejecute:
   ```
   npm install
   ```
3. Siga las instrucciones detalladas en [bridge/INSTALACION.md](bridge/INSTALACION.md)

### 3. Configurar el plugin

1. En WordPress, vaya a **Caaguazú Bot → Configuración**
2. Complete:
   - **URL del Bridge**: la URL de Cloudflare Tunnel (ej: `https://abc.trycloudflare.com`)
   - **Token compartido**: el mismo valor que tiene en el `.env` del bridge
3. Guarde la configuración

### 4. Vincular WhatsApp (escaneo QR)

1. Asegúrese de que el bridge esté corriendo en su PC
2. En WordPress, vaya a **Caaguazú Bot → Vincular WhatsApp**
3. Espere a que aparezca el código QR (puede tardar unos segundos)
4. En el teléfono con el número del bot: WhatsApp → Dispositivos vinculados → Vincular dispositivo → escanear QR
5. El panel debe mostrar "✅ Vinculado"

### 5. Agregar números administradores

1. Vaya a **Caaguazú Bot → Configuración → Números administradores**
2. Agregue los números (con código de país, sin "+") que podrán publicar, editar y borrar artículos
3. Los demás números que escriban al bot serán tratados como lectores

---

## Uso diario

### Como administrador
1. Envíe cualquier mensaje al número del bot
2. Recibirá un menú con opciones: publicar, editar, borrar, ver enlaces
3. **Publicar:** envíe el texto del artículo. La **primera línea** se usa como título y el
   resto como cuerpo. Puede **adjuntar una imagen** y se fijará como portada (imagen
   destacada). Luego elige categoría y si **publica** o lo deja como **borrador**.
4. **Editar:** elige el artículo y luego si desea **reemplazar** todo el contenido o
   **agregar al final**.

### Como lector
1. Cualquier persona puede escribirle al número del bot
2. Recibirá un menú para: ver artículos por categoría, recientes, **buscar por palabra
   clave** y **administrar sus suscripciones** a categorías.

### Broadcast desde WordPress
- Vaya a **Caaguazú Bot → Broadcast**
- Escriba el mensaje y seleccione los destinatarios: todos (sin bajas), solo lectores,
  solo admins, **suscriptores de una categoría**, o números específicos.
- Presione "Enviar mensaje". El envío se procesa **en segundo plano por lotes** (no se
  corta con listas grandes) y verá una **barra de progreso** en vivo.

> El procesamiento en lotes usa WP-Cron. En sitios con poco tráfico, WP-Cron puede
> demorarse entre lotes hasta que llegue una visita o se dispare el heartbeat (cada 5
> min). Para envíos masivos inmediatos en sitios de bajo tráfico, conviene configurar un
> cron real del sistema apuntando a `wp-cron.php`.

### Estadísticas
- Vaya a **Caaguazú Bot → Estadísticas** para ver mensajes recibidos/enviados (totales y
  últimos 7 días), usuarios únicos, conteo de admins/lectores y el historial de broadcasts.

### Editar los textos del bot
- Vaya a **Caaguazú Bot → Mensajes del bot**
- Edite cualquier plantilla (sin borrar los marcadores `{name}`, `{permalink}`,
  `{term}`, `{subs_list}`, etc.)

---

## Troubleshooting

### El QR no aparece en WordPress
- Verifique que el bridge esté corriendo en su PC (`node index.js`)
- Verifique que Cloudflare Tunnel esté activo
- Verifique que la URL del tunnel en **Configuración** esté correcta

### El QR apareció pero expiró
- Los QR expiran en aproximadamente 60 segundos
- Use el botón **Forzar reconexión** o detenga y reinicie el bridge

### El bot no responde a mensajes
1. Verifique que el bridge esté **CONECTADO** (pestaña Estado)
2. Verifique que el bridge esté corriendo en su PC
3. Verifique que el tunnel esté activo
4. Revise los logs en la pestaña **Estado**

### La URL del tunnel cambió
Cada vez que reinicia Cloudflare Tunnel gratuito, la URL cambia. Actualícela en **Caaguazú Bot → Configuración → URL del Bridge**.

### El bot muestra "Error genérico"
- Revise los logs de WordPress en `wp-content/debug.log` (si tiene `WP_DEBUG_LOG` activo)
- Los errores del bridge se muestran en la terminal donde corre `node index.js`

### Mensajes de broadcast no llegan
- WhatsApp limita el envío masivo; el bridge espera 1 segundo entre mensajes
- Si hay muchos fallos, el número puede haber sido temporalmente restringido

---

## Limitaciones conocidas

### ⚠️ Riesgo de ban por WhatsApp
Este plugin usa la librería **Baileys**, que accede a WhatsApp mediante ingeniería inversa del protocolo oficial. **WhatsApp puede banear el número** en cualquier momento, especialmente si:
- Envía muchos mensajes en poco tiempo
- Recibe reportes de spam
- WhatsApp detecta el uso de clientes no oficiales

**Recomendaciones para minimizar el riesgo:**
- Use un número **dedicado**, nunca el personal
- No envíe broadcasts masivos frecuentes
- Tenga un número de respaldo configurado

### El bot depende de la PC del admin
Si la PC del administrador está apagada, sin internet, o el bridge no está corriendo, el bot **no funcionará**. Para una operación continua, considere mover el bridge a un VPS de bajo costo ($3-5/mes en Hetzner, DigitalOcean, etc.).

### El tunnel gratuito de Cloudflare cambia de URL
Con el plan gratuito, la URL de Cloudflare Tunnel cambia cada vez que reinicia. Para URLs estables, se puede pagar el plan de Cloudflare o usar un dominio propio con el tunnel.

### Multimedia limitada
El bot procesa texto y, al publicar, **imágenes** (jpg/png/webp, hasta 5 MB) que usa como
portada del artículo. Audios, documentos, stickers y videos se ignoran.

### Mensajes grupales no soportados
El bot solo responde a chats individuales. Los mensajes enviados en grupos donde está el número del bot son ignorados.

### Mantenimiento del bridge
Baileys es una librería mantenida por la comunidad. Cuando WhatsApp actualiza su aplicación, el bridge puede dejar de funcionar temporalmente hasta que Baileys se actualice. Para actualizar:
```bash
npm update @whiskeysockets/baileys
```

---

## Licencia

MIT — Libre uso, modificación y distribución.
