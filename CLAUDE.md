# Contexto para el agente

Lee esto antes de tocar nada. Si algo de aquí contradice al código, gana el
código: avísalo y corrige este archivo.

## Qué es esto

Monitoreo ciudadano de servicios públicos en Venezuela. La gente reporta por
WhatsApp y una web pública muestra el mapa en tiempo real. Son **dos plugins de
WordPress que viven en sitios distintos** y se hablan por una API:

| Carpeta | Qué es | Dónde corre |
|---|---|---|
| `plugins/botfinalrptv/` | El bot de WhatsApp (Twilio) | El WordPress del bot |
| `plugins/datavenger-portal-electrico/` | La web pública del mapa | `larepublicaweb.com` |

El portal **nunca** toca la base de datos del bot: todo lo pide a `datav/v1`.

## Por dónde empezar

| Si vas a tocar… | Empieza por |
|---|---|
| El flujo de conversación del bot | `plugins/botfinalrptv/src/power_report/bot/handle_power_report_flow.php` |
| Los textos que envía el bot | `plugins/botfinalrptv/src/power_report/config.php` (registro) y `src/messages/power_report/*.txt` |
| La API | `plugins/botfinalrptv/src/power_report/public/rest_api.php` |
| Las consultas y estadísticas | `plugins/botfinalrptv/src/power_report/db/stats_repository.php` |
| El mapa, los filtros, la interfaz | `plugins/datavenger-portal-electrico/assets/portal.js` |
| El mapa de calor o las imágenes descargables | `assets/heatmap.js` (motor) y `assets/card.js` (tarjeta) |
| Añadir un servicio nuevo (internet, agua…) | `docs/NUEVO-SERVICIO.md` |

Documentación: `docs/ARQUITECTURA.md` (capas y desacople), `CHANGELOG.md`
(qué se hizo y cuándo), `README.md` (uso y publicación).

## Reglas que ya costaron caro

**Sumar horas no es medir tiempo.** Si veinte vecinos reportan el mismo apagón
de 3 h, la suma dice 60 h y la realidad son 3. Se publican las dos cifras y
nunca se confunden: `coverage_*` es la unión de intervalos (duración real) y
`outage_*` la suma (impacto acumulado). Al escribir textos, di cuál es cuál.

**Nunca escribas un PHP con BOM.** Un solo byte antes de `<?php` rompe las
cabeceras HTTP de WordPress. Si editas con PowerShell, usa
`[System.IO.File]::WriteAllText` con `UTF8Encoding($false)`.

**La versión del plugin vive en dos sitios** (cabecera y constante
`DVPE_VERSION`). Si se desincronizan, el navegador sigue sirviendo el CSS y el
JS viejos. Usa `.\build.ps1 <plugin> -Bump patch` y no lo edites a mano.

**El estado de un reporte nunca se supone.** `active`, `restored` y
`undetermined` solo se asignan con una respuesta explícita de la persona o al
agotarse los intentos. El silencio no significa ni que volvió la luz ni que
sigue sin ella.

**Nadie puede quedar atrapado en un flujo.** Todo paso que abra sesión necesita
salida: palabra de menú, toque de una opción del menú y caducidad por tiempo.

**El proxy tiene lista blanca.** Si añades un parámetro o una ruta a la API,
añádelos también en `plugins/datavenger-portal-electrico/includes/api_client.php`
o en modo proxy no llegarán.

## Convenciones

- **Idioma:** código y comentarios en español, igual que el resto del proyecto.
- **Comentarios:** explican *por qué*, no *qué*. Si el código ya lo dice, sobra.
- **Prefijos:** `datav_power_*` en el bot, `dvpe_*` en el portal.
- **Sin compilación:** PHP, CSS y JS se escriben a mano y se suben tal cual.
- **Nada de dependencias nuevas** sin hablarlo: el portal no usa ni un paquete
  npm y el mapa de calor está escrito a mano por eso.

## Cómo se prueba

No hay PHP ni WordPress en la máquina de desarrollo. Lo que sí se puede hacer,
y conviene hacer siempre:

- **JavaScript:** `node --check archivo.js`.
- **Lógica delicada** (parsers, fechas, uniones de intervalos): replicarla en
  Python y pasarle una batería de casos. Así se encontraron los fallos de la
  hora ambigua y de la etiqueta sin año.
- **PHP:** balance de llaves, funciones duplicadas y llamadas sin definir con un
  script; la sintaxis real solo se valida al subirlo.
- **La API en producción** responde y es pública:
  `https://larepublicaweb.com/wp-json/datavenger-portal/v1/proxy?path=power/live`

## Al terminar un cambio

1. Anótalo en `CHANGELOG.md`, arriba del todo.
2. Sube la versión del plugin tocado con `build.ps1 -Bump`.
3. Di qué archivos hay que subir y si hace falta migración o purgar caché.
