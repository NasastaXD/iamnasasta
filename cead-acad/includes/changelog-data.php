<?php
/**
 * Las novedades del CEAD, versión por versión.
 *
 * ESTE ARCHIVO ES LA ÚNICA FUENTE: de acá salen la página «Novedades» del panel
 * (/panel/novedades), la de wp-admin (CEAD Académico → Novedades), el aviso del
 * inicio y las notas de cada Release de GitHub. No se copia a ningún otro lado.
 *
 * REGLA: cada versión nueva del plugin trae su entrada acá, arriba de todo. Hay
 * una prueba (ChangelogTest) que falla si la versión del plugin no coincide con
 * la primera entrada, así que no se puede publicar sin documentar.
 *
 * Cómo se escribe (lo lee el alumnado, las familias y los docentes, no un
 * programador):
 *   - En castellano simple, con voseo. Qué cambia PARA LA PERSONA, no cómo se hizo.
 *   - Sin nombres de clases, de endpoints ni de tablas. Eso va en la nota `admin`.
 *   - Un ítem = una cosa. Si hay que explicar dos, son dos ítems.
 *   - Lo que no ve el alumnado va con 'para' => 'equipo'.
 *
 * Cada entrada:
 *   version  '0.97.0'        la del plugin, tal cual está en cead-acad.php
 *   fecha    '2026-10-10'    día de publicación (Y-m-d)
 *   titulo   corto, es lo que se ve grande
 *   resumen  una o dos frases
 *   items    lista de:
 *     tipo    'nuevo' (algo que antes no existía) | 'mejora' (algo que ya había y
 *             ahora es mejor) | 'arreglo' (algo que andaba mal)
 *     area    opcional: dónde se nota ('App', 'Panel', 'Comunicados', …)
 *     titulo  lo que se lee primero
 *     texto   la explicación, en una o dos frases
 *     para    opcional: 'todos' (por defecto) | 'equipo' (dirección, secretaría,
 *             docentes, delegados/as y consejo; el alumnado no lo ve) | 'admin'
 *             (solo se muestra en wp-admin, nunca en el panel)
 *     admin   opcional: nota técnica, SOLO se muestra en wp-admin
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

return [

	[
		'version' => '0.97.0',
		'fecha'   => '2026-10-10',
		'titulo'  => 'Novedades: lo nuevo, a la vista',
		'resumen' => 'Cada vez que el CEAD suma algo al panel o a la app, queda explicado acá.',
		'items'   => [
			[
				'tipo'   => 'nuevo',
				'area'   => 'Panel',
				'titulo' => 'Esta página',
				'texto'  => 'Todo lo nuevo que llega al panel y a la app, contado en simple y por orden. Cuando hay algo que todavía no viste, el menú lo marca con un cartelito «Nuevo».',
				'admin'  => 'Las novedades salen de un solo archivo, cead-acad/includes/changelog-data.php. Cada versión del plugin tiene que sumar su entrada ahí (una prueba automática lo exige) y el texto también se usa en las notas de cada Release de GitHub.',
			],
			[
				'tipo'   => 'nuevo',
				'area'   => 'wp-admin',
				'titulo' => 'El mismo registro, con notas para quien administra',
				'texto'  => 'En wp-admin, CEAD Académico → Novedades muestra todo lo anterior y, en algunas novedades, una nota extra con lo que hay que hacer o configurar.',
				'para'   => 'admin',
			],
		],
	],

	[
		'version' => '0.96.0',
		'fecha'   => '2026-10-10',
		'titulo'  => 'La app del colegio, renovada',
		'resumen' => 'Todo el panel en el celular, y funciona sin internet. Llega con la nueva versión de la app, que se va publicando en Google Play y en la App Store.',
		'items'   => [
			[
				'tipo'   => 'nuevo',
				'area'   => 'App',
				'titulo' => 'Todo el panel, en la app',
				'texto'  => 'Horario, comunicados, tareas, calendario, boletín, recursos, encuestas, preguntas frecuentes, tu perfil, escribirle al colegio y CEADI. Lo que hacías en el panel de la web, ahora también desde el celular.',
				'admin'  => 'La app nueva es un solo código para Android e iPhone (carpeta app/ del repositorio). Se publica aparte, en las tiendas: hasta que se suba la versión nueva, estas novedades no se ven en los teléfonos. Guía en app/COMPILAR.md.',
			],
			[
				'tipo'   => 'nuevo',
				'area'   => 'App',
				'titulo' => 'Funciona sin internet',
				'texto'  => 'Lo último que se bajó se puede leer en el aula aunque no haya wifi: el horario, los comunicados completos, las tareas, el boletín. Y lo que hacés sin señal —marcar una tarea, responder una encuesta, mandar un mensaje— se ve hecho enseguida y se envía solo cuando vuelve la conexión.',
			],
			[
				'tipo'   => 'nuevo',
				'area'   => 'App',
				'titulo' => 'Se actualiza sola',
				'texto'  => 'Al volver a abrir la app trae lo nuevo, y si quedó algo esperando sin enviar lo vuelve a intentar cada minuto. En «Envíos pendientes» (se abre tocando el aviso que aparece arriba) se ve qué está esperando, y si algo no se pudo enviar queda ahí, a la vista, para reintentarlo.',
			],
			[
				'tipo'   => 'nuevo',
				'area'   => 'App',
				'titulo' => 'Gestión desde el celular',
				'texto'  => 'Según tu rol: publicar comunicados, cargar eventos y notas, publicar en el sitio, ver las tareas del curso y asignarlas, el buzón, las métricas, las invitaciones y el directorio de delegados/as. Se puede preparar en el aula sin señal y sale cuando vuelve internet.',
				'para'   => 'equipo',
			],
			[
				'tipo'   => 'mejora',
				'area'   => 'App',
				'titulo' => 'Lo delicado no se guarda en el teléfono',
				'texto'  => 'Los mensajes del buzón de coordinación, los teléfonos de los delegados y las notas de otras personas se miran solo con conexión y no quedan guardados. Y al cerrar sesión se borra todo lo de tu cuenta del teléfono.',
			],
			[
				'tipo'   => 'mejora',
				'area'   => 'Comunicados',
				'titulo' => 'Categoría al publicar desde la app',
				'texto'  => 'Al publicar un comunicado desde la app se puede marcar con su categoría (urgente, académico…), igual que en wp-admin.',
				'para'   => 'equipo',
			],
		],
	],

	[
		'version' => '0.95.0',
		'fecha'   => '2026-10-09',
		'titulo'  => 'Avisos en el teléfono',
		'resumen' => 'Un comunicado nuevo, una tarea o la respuesta del buzón te llegan como notificación, sin que tengas que abrir la app.',
		'items'   => [
			[
				'tipo'   => 'nuevo',
				'area'   => 'App',
				'titulo' => 'Notificaciones del colegio',
				'texto'  => 'Te avisamos cuando hay un comunicado, un evento, una tarea para tu curso, una nota nueva o una respuesta a lo que escribiste. En «Ajustes» elegís cuáles querés recibir y cuáles no.',
				'admin'  => 'Los avisos van por Firebase Cloud Messaging, que es gratis. Para activarlos hay que crear el proyecto de Firebase del colegio y dejar la clave en el servidor: los pasos están en cead-acad/docs/AVISOS-PUSH.md. Hasta entonces todo anda igual, solo que sin avisos. El botón «Probar avisos» de Ajustes (en la app) comprueba que quedó bien.',
			],
			[
				'tipo'   => 'mejora',
				'area'   => 'Privacidad',
				'titulo' => 'Con el teléfono bloqueado no se ve lo íntimo',
				'texto'  => 'Cuando te responden en el buzón, el aviso dice solo «Te respondieron»; nunca muestra la respuesta. Una nota nueva avisa la materia, nunca el número. Un reporte anónimo no genera aviso, porque el sistema no sabe de quién es.',
			],
			[
				'tipo'   => 'mejora',
				'area'   => 'Privacidad',
				'titulo' => 'Un teléfono, una cuenta',
				'texto'  => 'Si cerrás sesión, si se vence o si cambiás la contraseña, ese teléfono deja de recibir los avisos de tu cuenta. Un celular prestado o vendido no sigue mostrando lo tuyo.',
			],
			[
				'tipo'   => 'arreglo',
				'area'   => 'Comunicados',
				'titulo' => 'El aviso por email salía sin audiencia',
				'texto'  => 'Al publicar un comunicado por primera vez desde wp-admin, el email opcional a la audiencia podía salir sin destinatarios ni la casilla elegida. Ahora se envía recién cuando todo está guardado, y una sola vez por publicación.',
				'para'   => 'equipo',
			],
		],
	],

	[
		'version' => '0.94.0',
		'fecha'   => '2026-10-09',
		'titulo'  => 'Notas, invitaciones y tareas desde la app',
		'resumen' => 'El resto de lo que el personal hacía por WhatsApp, ahora en la app.',
		'items'   => [
			[
				'tipo'   => 'nuevo',
				'area'   => 'App',
				'titulo' => 'Cargar notas desde el celular',
				'texto'  => 'Docentes y dirección cargan las notas de su curso desde la app, aunque no haya señal: la nota se guarda y sale cuando vuelve internet. Al llegar se vuelve a comprobar que la persona siga en ese curso.',
				'para'   => 'equipo',
			],
			[
				'tipo'   => 'nuevo',
				'area'   => 'App',
				'titulo' => 'Invitaciones, notas del sitio, tareas y delegados',
				'texto'  => 'Generar links de invitación, publicar notas en el sitio, ver y asignar las tareas del curso, y consultar el directorio de delegados/as, todo desde la app.',
				'para'   => 'equipo',
			],
			[
				'tipo'   => 'arreglo',
				'area'   => 'Notas',
				'titulo' => 'Las notas de la Primera y Segunda Etapa rebotaban',
				'texto'  => 'Con las etapas del CEAD (Primera Etapa, Segunda Etapa, Final), toda nota que no era la final se rechazaba con «ese periodo no existe». Ya se pueden cargar todas.',
				'para'   => 'equipo',
			],
		],
	],

	[
		'version' => '0.93.0',
		'fecha'   => '2026-10-09',
		'titulo'  => 'Comunicados, eventos y buzón desde la app',
		'resumen' => 'Se deja de depender del bot de WhatsApp: lo que se hacía por ahí tiene su lugar en la app.',
		'items'   => [
			[
				'tipo'   => 'nuevo',
				'area'   => 'App',
				'titulo' => 'Publicar comunicados y cargar eventos',
				'texto'  => 'Los docentes se dirigen al alumnado; dirección y secretaría, a todo el colegio. Las reglas de quién puede publicarle a quién son las mismas que ya había, y no se pueden saltear armando el pedido a mano.',
				'para'   => 'equipo',
			],
			[
				'tipo'   => 'nuevo',
				'area'   => 'Buzón',
				'titulo' => 'Buzón y métricas en la app',
				'texto'  => 'Quienes atienden el buzón (reportes y sugerencias) lo hacen desde el celular, y quienes tienen acceso miran las métricas del colegio.',
				'para'   => 'equipo',
			],
			[
				'tipo'   => 'mejora',
				'area'   => 'Mensajes',
				'titulo' => 'Ver la respuesta en «Mis mensajes»',
				'texto'  => 'Cuando le escribís a Dirección, al Consejo o a Administración, la respuesta aparece en «Mis mensajes», en la web y en la app, sin necesidad de WhatsApp.',
			],
			[
				'tipo'   => 'mejora',
				'area'   => 'Reportes',
				'titulo' => 'Un reporte anónimo sigue siendo anónimo',
				'texto'  => 'Un reporte anónimo no guarda nada de quien lo manda. Para seguirlo usás su código (RPT-…), que te da la app al enviarlo.',
			],
			[
				'tipo'   => 'arreglo',
				'area'   => 'Métricas',
				'titulo' => 'La tasa de respuesta de la última encuesta',
				'texto'  => 'No contaba a las personas de las promociones elegidas como destinatarias. Ahora el porcentaje es el real.',
				'para'   => 'equipo',
			],
		],
	],

	[
		'version' => '0.92.0',
		'fecha'   => '2026-10-09',
		'titulo'  => 'Preparados para usar sin señal',
		'resumen' => 'Los datos de la persona se pueden bajar de una vez y consultarse en el aula, donde no hay wifi.',
		'items'   => [
			[
				'tipo'   => 'mejora',
				'area'   => 'App',
				'titulo' => 'Los datos se bajan de una vez',
				'texto'  => 'La app trae en un solo pedido el horario, los comunicados con su texto completo, el boletín, las tareas, el calendario, los recursos y las encuestas abiertas. Si no cambió nada desde la última vez, casi no gasta datos.',
			],
		],
	],

	[
		'version' => '0.88.0',
		'fecha'   => '2026-08-24',
		'titulo'  => 'Portal turístico',
		'resumen' => 'Un botón en el panel para pasar al portal turístico sin registrarse de nuevo.',
		'items'   => [
			[
				'tipo'   => 'nuevo',
				'area'   => 'Panel',
				'titulo' => 'Entrar a caaguazu.net desde el panel',
				'texto'  => 'El alumnado y los docentes de los cursos de turismo ven en el menú «Portal turístico» y entran directo, con su cuenta del CEAD, sin crear otra.',
				'admin'  => 'Qué cursos tienen el botón se elige con una casilla en cada curso. El contrato completo con el otro sitio está en cead-acad/docs/INTEGRACION-TURISMO.md.',
			],
		],
	],

	[
		'version' => '0.86.0',
		'fecha'   => '2026-08-24',
		'titulo'  => 'El login pasa a /ingresar',
		'resumen' => 'La dirección para entrar al panel cambió, y la vieja sigue funcionando.',
		'items'   => [
			[
				'tipo'   => 'mejora',
				'area'   => 'Panel',
				'titulo' => 'Se entra por /ingresar',
				'texto'  => 'La dirección /login se confundía con la de WordPress. Ahora es /ingresar. Si tenés guardada la anterior, sigue funcionando y te lleva a la nueva.',
			],
		],
	],

	[
		'version' => '0.75.0',
		'fecha'   => '2026-08-14',
		'titulo'  => 'El carné digital se puede apagar',
		'resumen' => 'El carné queda apagado hasta que el colegio decida usarlo, y su QR ya no sale del colegio.',
		'items'   => [
			[
				'tipo'   => 'nuevo',
				'area'   => 'wp-admin',
				'titulo' => 'Interruptor del carné',
				'texto'  => 'En CEAD Académico → Funciones se prende o apaga el carné digital. Apagado desaparece de todos lados a la vez: menú, perfil, atajos, la ruta del panel y la verificación pública.',
				'para'   => 'admin',
			],
			[
				'tipo'   => 'mejora',
				'area'   => 'Privacidad',
				'titulo' => 'El QR se dibuja en el servidor del colegio',
				'texto'  => 'Antes se pedía a un servicio de afuera mandándole el enlace de verificación de cada persona. Ahora ese dato no sale del colegio.',
			],
		],
	],

];
