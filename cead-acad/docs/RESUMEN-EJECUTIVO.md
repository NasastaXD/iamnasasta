# CEAD Académico — El proyecto en una página

> **Centro Educativo de Alto Desempeño "Félix de Guarania"** · Caaguazú · Plugin v0.31.0

## El problema

Una institución educativa necesita **comunicar, gestionar y acompañar** (notas, horarios, eventos, trámites, reportes) — pero su comunidad vive en el **celular y en WhatsApp**, no en sistemas administrativos complejos.

## La solución

Una **plataforma propia, hecha a medida**, que centraliza todo en un solo lugar y se usa por donde cada persona prefiera: **web, app o WhatsApp**.

## Las tres caras del sistema

| Cara | Qué es | Para quién |
|------|--------|-----------|
| 🖥️ **Panel web** (`/panel`) | Comunicados, calendario, horarios, tareas, recursos, carné digital, boletín | Toda la comunidad, según su rol |
| 📱 **App (PWA)** | El mismo panel, instalable en el celular, con notificaciones y modo offline | Alumnado y familias |
| 💬 **CEADI (bot de WhatsApp)** | Atiende por WhatsApp con **lenguaje natural (IA)** y **notas de voz** | Quien prefiere no entrar a la web |

## En números

- **17 módulos** (comunicados, encuestas, horarios, recursos, calificaciones, tareas, carné, importadores, bot…)
- **7 roles** con permisos propios (dirección, secretaría, docente, delegado, alumno, familia, consejo)
- **18 tablas** de base de datos + **6 tipos de contenido** propios
- **1 bot** conversacional con IA, voz y reportes cifrados
- **0 dependencias pesadas**: sin frameworks de build; liviano y mantenible

## Cómo está construido (una línea)

**WordPress (PHP 8.1+)** con un **tema** (la web pública) y un **plugin modular** (toda la aplicación); el bot usa un pequeño **bridge en Node.js** para hablar con WhatsApp. Se **actualiza solo** desde GitHub.

## Qué lo hace distinto

- **A medida**, no un sistema genérico: roles, permisos y audiencias pensados para el flujo real del colegio.
- **Audiencias inteligentes**: un comunicado puede ir a un rol, un curso, un año o una persona — con una sola lógica reutilizable.
- **Multicanal de verdad**: lo que se envía desde el bot aparece también en la web, y viceversa.
- **Seguro por diseño**: tokens hasheados, permisos verificados en cada acción, reportes cifrados.
- **Se mantiene solo**: actualizaciones con un clic desde el panel de WordPress.

---

*Resumen para presentación · CEAD Félix de Guarania.*
