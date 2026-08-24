# Integración CEAD ↔ caaguazu.net — Acceso con un clic

**Para**: quien mantiene `caaguazu-cuentas` y `caaguazu-portal`.
**De**: el lado CEAD (plugin `cead-acad`, panel del colegio).
**Estado**: propuesta de contrato. Nada implementado del lado de ustedes todavía.

---

## 1. Qué se quiere

En el CEAD hay un curso de **Servicios Turísticos**. Su alumnado y sus docentes
tienen que poder entrar al portal turístico **sin registrarse de nuevo y sin
recordar otra contraseña**: desde el panel del colegio tocan un botón y caen
adentro del portal, ya identificados y con los permisos que les corresponden.

En una línea: **el CEAD afirma quién es la persona; caaguazu.net decide qué
hacer con esa afirmación.**

## 2. Por qué esta forma y no otra

Leí `caaguazu-cuentas` antes de escribir esto. La decisión de tener cuentas
propias, separadas de los usuarios de WordPress, es correcta y **esta
integración no la toca**: no proponemos crear usuarios de WordPress ni usar la
cookie de WP. Se crean cuentas en `caaguazu_accounts`, como cualquier otra.

Descartamos dos caminos más simples, y conviene decir por qué:

- **Compartir la base de usuarios**: son dos WordPress distintos, en dos
  dominios. Acoplarlos obliga a que uno dependa de la disponibilidad del otro
  para algo tan básico como iniciar sesión.
- **Mandar los datos firmados en la URL** (un JWT o similar en el query string).
  Funciona, pero la URL con los datos personales queda en el historial del
  navegador, en la cabecera `Referer` y en los logs de cualquier proxy. Y un
  enlace firmado se puede reusar hasta que venza, salvo que además guardes
  estado — y si vas a guardar estado, ya no ganás nada por firmar.

Lo que proponemos es el patrón del *authorization code* de OAuth, reducido a lo
mínimo: **por la URL viaja un código opaco que no dice nada, y los datos de la
persona se piden por detrás, de servidor a servidor.**

## 3. El flujo

```
Alumno                 Panel CEAD              caaguazu.net           CEAD (REST)
  │                        │                        │                      │
  │ toca «Ir al portal»    │                        │                      │
  ├───────────────────────►│                        │                      │
  │                        │ genera código opaco    │                      │
  │                        │ (guarda claims, 2 min) │                      │
  │  302 → /acceso-cead?code=XXXX                   │                      │
  │◄───────────────────────┤                        │                      │
  ├────────────────────────────────────────────────►│                      │
  │                        │                        │ POST /redeem {code}  │
  │                        │                        ├─────────────────────►│
  │                        │                        │                      │ valida
  │                        │                        │  claims (JSON)       │ consume
  │                        │                        │◄─────────────────────┤
  │                        │                        │ busca o crea cuenta  │
  │                        │                        │ aplica grant         │
  │                        │                        │ abre sesión propia   │
  │   302 → /portal (ya adentro)                    │                      │
  │◄────────────────────────────────────────────────┤                      │
```

Lo importante: el paso de la derecha es **servidor a servidor y autenticado**.
El navegador nunca ve los datos de la persona, solo un código sin significado.

## 4. Contrato

### 4.1 El enlace que manda el CEAD

```
https://caaguazu.net/acceso-cead?code=<64 hex>
```

Nada más. Sin email, sin nombre, sin rol. Si el código se filtra, sirve **una
sola vez y por dos minutos**, y sin el secreto compartido no se puede canjear.

### 4.2 El canje (lo que ustedes llaman)

```http
POST https://<sitio-del-cead>/wp-json/cead-sso/v1/redeem
Content-Type: application/json

{
  "code": "<64 hex>",
  "ts":   1755990000,
  "sig":  "<hmac_sha256(code + '|' + ts, SECRETO_COMPARTIDO)>"
}
```

- `ts` es UNIX en segundos. Rechazamos si difiere más de **300 s** del nuestro.
- `sig` en hex. Comparación con `hash_equals()`.
- Rate limit por IP de nuestro lado.

### 4.3 Lo que devolvemos

**200 OK**

```json
{
  "ok": true,
  "cead_uid": 412,
  "email": "ana.penayo@ejemplo.com",
  "nombre": "Ana Penayo",
  "telefono": "595981111111",
  "rol": "alumno_turismo",
  "curso": "2.º Servicios Turísticos",
  "emitido": 1755989950
}
```

**4xx** — `{"ok": false, "error": "<clave>"}` con: `code_invalido`,
`code_vencido`, `code_usado`, `firma_invalida`, `desfase_horario`.

Cada canje queda auditado de nuestro lado.

### 4.4 `rol`: nombres lógicos, no los de ustedes

Mandamos un rol **lógico**, y ustedes lo mapean a lo que exista en el panel:

| Lo que manda el CEAD | Quién es |
|---|---|
| `alumno_turismo` | Alumno/a inscripto en el curso de Servicios Turísticos |
| `docente_turismo` | Docente asignado a ese curso |

Es a propósito: **los roles del portal son de ustedes**, y si mandáramos
`promotur_mini` directamente, cualquier cambio en su modelo de roles rompería
nuestro plugin. Un mapa en su lado (`alumno_turismo → promotur_mini`, por
ejemplo) los deja mover sus roles sin avisarnos.

Si mandamos un rol que no conocen, **no inventen**: rechacen el acceso y
regístrenlo. Es preferible una puerta cerrada a un permiso adivinado.

## 5. Lo que hay que hacer del lado de ustedes

1. **Una ruta pública** `\/acceso-cead` que tome `?code=`, haga el canje del 4.2
   y redirija al portal. Si el canje falla, una pantalla que lo diga sin
   detalles técnicos.

2. **Buscar o crear la cuenta**, en este orden:
   - Por `cead_uid` guardado en la cuenta (ver punto 3) → esa es la cuenta.
   - Si no, por email → vincular esa cuenta (ver §6.1).
   - Si no existe ninguna, crear con `Caaguazu_Cuentas_Accounts::create_with_hash()`.

3. **Guardar `cead_uid`** en la cuenta, con `caaguazu_account_meta_set()` o en el
   `metadata` JSON. Es lo que hace que la segunda visita encuentre la misma
   cuenta aunque la persona haya cambiado su email en el CEAD. **El email no
   sirve como llave estable**; el `cead_uid` sí.

4. **Cuentas sin contraseña utilizable.** `create_with_hash()` pide un
   `pass_hash`: guarden uno aleatorio que no corresponda a ninguna contraseña
   (p. ej. `wp_generate_password( 64, true, true )` pasado por el hasher). Así
   la cuenta existe, entra por SSO, y **no se puede adivinar una contraseña que
   nunca se fijó**. Si la persona quiere entrar directo alguna vez, que use el
   flujo de recuperación que ya tienen.

5. **Aplicar el grant en cada canje**, no solo al crear: `caaguazu_account_grant()`
   con el rol mapeado. Así, si alguien deja el curso y vuelve con otro rol, o le
   sacan el acceso, se refleja la próxima vez que entre.

6. **Abrir la sesión**: `Caaguazu_Cuentas_Sessions::instance()->start( $account_id )`.
   Sugerimos **no** usar `remember = true` para sesiones nacidas por SSO — ver §7.

7. **El secreto compartido** va como constante en `wp-config.php` de los dos
   sitios, no como opción en la base:
   ```php
   define( 'CEAD_TUR_SSO_SECRET', '…64 hex…' );
   ```
   En la base terminaría en cualquier export o backup que alguien pase por chat.

## 6. Decisiones que necesito de ustedes

### 6.1 Email que ya existe → vincular

Si llega `ana@ejemplo.com` y ya hay una cuenta con ese email sin `cead_uid`
—una promotora de antes, por ejemplo—: **vincúlenlas**. La cuenta pasa a tener
también el `cead_uid` y el grant que corresponda.

En una versión anterior de este documento pedía lo contrario (rechazar y que un
admin vinculara a mano). Estaba mal calibrado, por dos motivos:

1. **El caso probable no es un ataque, es la misma persona.** En Caaguazú, un
   alumno de Servicios Turísticos puede perfectamente ser ya promotor. Rechazar
   ahí es fricción pura contra el caso que más se va a dar.
2. **El ataque no está al alcance de un alumno.** Del lado del CEAD el email es
   de solo lectura: un alumno no puede cambiarse el suyo, solo secretaría o
   dirección desde wp-admin. Así que para forzar una colisión hace falta alguien
   que ya tiene permiso para editar usuarios — y con ese permiso hay caminos
   mucho más directos que este.

Estamos hablando de 40-50 alumnos por año en una comunidad conocida, no de un
registro público abierto. Pedir un trámite manual para cubrir un ataque que
necesita cómplice interno es cambiar comodidad real por seguridad que no aplica.

**Lo único que sí pido**, porque no cuesta fricción: que la vinculación quede
**registrada** de su lado —cuenta, `cead_uid`, fecha—. Si alguna vez algo sale
raro, la diferencia entre poder mirarlo y no poder es ese registro.

Y una opción, no un requisito: si una cuenta tiene permisos fuertes en el portal
(gestión de equipo, moderación), pueden pedir confirmación antes de vincular.
Son un puñado de cuentas y no afecta a ningún alumno. Si les parece de más,
sáltenlo.

### 6.2 A qué rol del portal mapea cada uno

¿`alumno_turismo` es `promotur_mini`? ¿`docente_turismo` es `promotur_promotor`,
o hace falta un rol nuevo con permisos de revisión? Es su decisión: díganme el
mapa y lo dejo escrito en la documentación de los dos lados.

### 6.3 ¿Panel `promotor` o un panel nuevo?

Entran al panel existente o conviene registrar uno aparte (`turismo_cead`) con
`caaguazu_register_panel()`. Si el alumnado va a ver una versión recortada del
portal, un panel propio les da más control y no ensucia los permisos de los
promotores reales.

## 7. Seguridad — lo que no conviene relajar

- **El código vive 2 minutos y se usa una vez.** El consumo tiene que ser
  atómico (un `UPDATE … WHERE consumed_at IS NULL` y mirar las filas afectadas),
  o dos pestañas simultáneas lo canjean dos veces.
- **Guarden el código hasheado**, igual que ya hacen con los tokens de sesión.
  Nosotros lo hacemos de nuestro lado.
- **Validen a dónde redirigen.** Si en algún momento agregan un parámetro de
  destino, que sea contra una lista blanca: si no, es un *open redirect* con
  sesión recién abierta, que es de lo peor que se puede regalar.
- **Sesiones de SSO más cortas.** El acceso vive del vínculo con el CEAD; si
  alguien deja el curso, su sesión abierta sigue siendo válida hasta que venza.
  Con `remember` largo eso puede ser meses. Sugiero la sesión corta por defecto,
  y que vuelvan a entrar por el botón — que para ellos es un clic.
- **Nunca acepten claims que vengan del navegador.** Si algún día aparece la
  tentación de mandar el rol en la URL «para ahorrar una llamada», ahí se cae
  todo el modelo: cualquiera edita esa URL.

## 8. Lo que NO entra en esta ronda

- **Gestión del portal desde el panel del CEAD.** La idea es que los docentes
  administren cosas del portal sin salir de nuestro panel. Eso necesita una API
  de lectura/escritura de su lado y es un proyecto aparte; primero que la gente
  pueda entrar.
- **Sincronizar bajas.** Hoy, si alguien deja el curso, pierde el botón y no
  puede pedir sesión nueva, pero la sesión abierta le dura lo que le dure. Si
  hace falta cortar en el momento, hablamos de un endpoint de revocación.
- **Empujar datos del CEAD al portal** (notas, asistencias). No está pedido y no
  creo que deba estarlo.

## 9. Lo que hacemos nosotros

Para que quede claro el reparto:

- Marcar en wp-admin qué curso participa del portal (una casilla por curso, para
  no depender de que el título diga «Servicios Turísticos»).
- Decidir quién es elegible y mostrar el botón solo a esa gente.
- Generar el código, guardarlo hasheado con sus claims y vencimiento.
- Exponer `/wp-json/cead-sso/v1/redeem`, validar firma y ventana de tiempo,
  consumir el código de forma atómica y devolver los claims.
- Auditar cada emisión y cada canje.

---

**Respuesta que necesito para arrancar**: §6.2 (mapa de roles) y §6.3 (panel).
La §6.1 ya está decidida: se vincula. Con eso implemento nuestra mitad.
