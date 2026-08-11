# CEAD — Tema WordPress

Tema clásico (PHP) portado del HTML+PHP original. Sin Tailwind: todo CSS plano.

## Estructura

```
cead/
├── style.css                 Cabecera del tema (requerido por WP)
├── functions.php             Setup, enqueue, includes
├── header.php / footer.php   Layout base
├── front-page.php            Home (orquesta secciones)
├── index.php / page.php /
├── single.php / 404.php      Fallbacks
├── inc/
│   ├── cpt.php               CPTs (cead_division, cead_vida) + seeders
│   ├── customizer.php        Todos los settings/controls
│   ├── contact.php           Handler de formulario (wp_mail + nonce)
│   └── helpers.php           Funciones compartidas
├── template-parts/
│   ├── logo.php              Logo reutilizable
│   ├── nav.php               Header + mega menú
│   └── sections/
│       ├── intro.php         Overlay 'CEAD' (cada visita)
│       ├── hero.php
│       ├── values.php
│       ├── quote.php
│       ├── divisions.php     Lee del CPT cead_division
│       ├── marquee.php
│       ├── life.php          Lee del CPT cead_vida
│       └── admission.php
└── assets/
    ├── css/styles.css        CSS plano completo
    └── js/main.js            Nav, mega menú, reveals
```

## Instalación

1. Comprimí la carpeta `cead/` en un zip.
2. WP-Admin → Apariencia → Temas → Subir tema → Activar.
3. Al activar, se insertan automáticamente las 4 divisiones y los 4 items de vida con el contenido original.
4. Configurá Ajustes → Lectura → Tu portada muestra: **Una página estática** y elegí cualquier página vacía (o dejá "Tu última entrada" y `front-page.php` igual toma prioridad).

## Edición desde WP-Admin

### Customizer (Apariencia → Personalizar → CEAD — Contenido)
- Colores de marca
- Hero (eyebrow, título, bajada, botones, imagen)
- Valores (1–4: título, color, forma, texto)
- Cita Honor Code
- Marquesina (lista de items)
- Intros de Bachilleratos y Vida
- Admisión (texto, botones, imagen, stats)
- Footer/Contacto (email, redes sociales)

### CPT — Divisiones (menú lateral)
4 entradas creadas al activar el tema. Cada una con:
- Título / Contenido
- Subtítulo (ej: "Bachiller Científico")
- Color de acento
- Imagen (destacada o URL externa)

### CPT — Vida estudiantil (menú lateral)
Mismo patrón: 4 entradas precargadas, editables individualmente.

## Formulario de contacto

- Envía vía `wp_mail()`.
- Configurá el email de destino en Customizer → Footer/Contacto.
- Logs locales se guardan en `wp-content/uploads/cead-contact-log.txt` como respaldo.
- Para usar SMTP real, instalá WP Mail SMTP (o equivalente) — esto solo afecta cómo viaja el correo, no cómo lo recibís.

## Notas

- **No hay librerías de animación.** Todo el movimiento es CSS más un `IntersectionObserver` de catorce líneas (`.reveal`, en `assets/js/main.js`). GSAP y ScrollTrigger estuvieron enqueued desde un CDN durante un tiempo «por si acaso», sin que ningún código los usara: eran ~110 KB y dos conexiones extra en la portada, para nada. Si algún día hace falta, se suma junto con el código que la use.
- La intro `CEAD` aparece en cada visita (animación CSS con `animation-delay`).
- Si más adelante necesitás permalinks bonitos para divisiones (`/division/ciencias-basicas/`), entrá a Ajustes → Enlaces permanentes y volvé a guardar para refrescar las reglas de rewrite.
