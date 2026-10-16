# Arquitectura

Cómo está armado el proyecto hoy, qué partes dependen de WordPress y cuáles no,
y qué habría que hacer el día que convenga salir de WordPress.

## El mapa general

```
   Ciudadano                   Bot (WordPress A)              Portal (WordPress B)
   ─────────                   ─────────────────              ───────────────────
   WhatsApp  ──Twilio──▶  webhook  /twilio/v1/message
                               │
                               ├─▶ flujo de conversación
                               ├─▶ base de datos ──┐
                               └─▶ cron seguimiento│
                                                   │
                                   API datav/v1 ◀──┘
                                        │
                                        ├──────────────▶ mapa, estadísticas
                                        └──────────────▶ informes, imágenes
```

Dos plugins, dos sitios, una API en medio. El portal no tiene base de datos
propia salvo los informes (que son contenido de WordPress, no datos del bot).

## Las piezas del bot

```
plugins/botfinalrptv/
├── bot.php                    Webhook de Twilio y enrutador del menú
├── src/
│   ├── instructions_validators/   ¿Qué quiere decir este mensaje?
│   ├── messages/                  Los textos, en .txt editables
│   ├── twilio-utils/              Envío de mensajes y plantillas
│   ├── course_database/           Curso interactivo e infomemes
│   ├── validate_database/         Flujo de corresponsales
│   ├── followup_messages/         Mensajes de seguimiento transversales
│   └── power_report/              ◀── el módulo de reportes de servicio
│       ├── config.php                 Ajustes y registro de mensajes
│       ├── bot/                       Máquina de pasos de la conversación
│       ├── db/                        Repositorios (reportes, sesión, zonas, stats)
│       ├── data/                      División territorial y geolocalización
│       ├── cron/                      Seguimiento automático
│       ├── admin/                     Pantallas de WordPress
│       └── public/                    API REST
└── vendor/                    SDK de Twilio (no versionado, sí empaquetado)
```

### Flujo de una conversación

`bot.php` decide a qué flujo pertenece cada mensaje. Solo hay dos estados
posibles: o la persona está dentro de un flujo con sesión abierta, o no.

```
¿tiene sesión abierta?
├── no  → handle_main_menu_message()   enruta por palabras clave
└── sí  → el manejador de ese flujo    curso / infomemes / corresponsales / reporte
```

La sesión vive en la tabla `{prefijo}twilio`, una fila por teléfono, con una
columna de estado por flujo. El reporte de servicio añade además el paso actual
(`power_step`), el reporte asociado y una caducidad opcional.

### Las tablas

| Tabla | Qué guarda |
|---|---|
| `{prefijo}twilio` | Estado conversacional de cada teléfono |
| `{prefijo}datav_power_reports` | Un registro por caso reportado |
| `{prefijo}datav_power_zones` | División político-territorial editable |
| `{prefijo}datav_followup_log` | Control de frecuencia de los mensajes de seguimiento |

Las migraciones corren solas al cargar WordPress y solo actúan si cambió la
versión guardada (`datAv_power_db_version`). Nunca se usa el hook de activación:
el plugin ya está activo en producción y ese hook no volvería a dispararse.

## Las piezas del portal

```
plugins/datavenger-portal-electrico/
├── includes/
│   ├── config.php        Ajustes y capas del mapa base
│   ├── api_client.php    Cliente de la API + proxy + proxy de fotos
│   ├── route.php         Rutas propias (/reporte-electrico/ y /informes/)
│   ├── seo.php           Metadatos, datos estructurados, sitemap
│   ├── informes.php      Tipo de contenido de los informes
│   ├── shortcode.php     Banda horizontal y aviso deslizante
│   └── view/             Los dos documentos HTML completos
└── assets/
    ├── portal.js         Cliente: ciclo de datos, mapa, filtros, compartir
    ├── heatmap.js        Proyección, teselas y composición de imágenes
    ├── card.js           Tarjeta de un reporte suelto
    ├── nudge.js          Aviso del último informe
    └── portal.css        Todo el estilo
```

El portal se sirve **fuera del tema**: su propia ruta y su propio documento
HTML. Así se ve igual en cualquier sitio donde se instale y no pasa por la caché
de página del tema.

### Directo o por proxy

Dos modos, elegibles en los ajustes:

- **Directo:** el navegador del visitante llama a la API del bot. Más ligero
  para el servidor del portal; necesita que el bot permita ese dominio.
- **Proxy:** el WordPress del portal consulta y reenvía. Útil cuando un CDN o un
  cortafuegos bloquea las llamadas entre dominios. **Es el modo en producción.**

El proxy tiene lista blanca de rutas y de parámetros. Toda ruta o parámetro
nuevo de la API hay que añadirlo también ahí.

## Qué depende de WordPress y qué no

Esta es la parte importante para el futuro. Hoy la lógica de negocio y la
plataforma están mezcladas en los mismos archivos, pero **no están enredadas**:
buena parte ya son funciones puras a las que solo les falta dejar de leer la
hora y los ajustes por su cuenta.

### Ya es lógica pura (no sabe nada de WordPress)

| Función | Qué resuelve |
|---|---|
| `datav_power_parse_moment()` y auxiliares | "ayer a las 9 de la noche" → marca de tiempo |
| `datav_power_union_seconds()` | Unión de intervalos: duración real de una falla |
| `datav_power_format_duration()` | Segundos → "4 h 12 min" |
| `datav_power_pick_option()`, `compare_key()` | Interpretar respuestas del usuario |
| `datav_power_coords_for()`, `geo_lookup` | Zona → coordenada, coordenada → zona |
| Todo `heatmap.js` y `card.js` | Proyección y composición de imágenes |

Solo dependen de WordPress en un punto: llaman a `current_time('mysql')` para
saber qué hora es. Pasar "ahora" como argumento las vuelve 100 % portables.

### Depende de WordPress

| Qué | Dónde |
|---|---|
| Consultas (`$wpdb`) | `db/*.php` |
| Ajustes (`get_option`) | `config.php` |
| Rutas REST | `public/rest_api.php` |
| Cron | `cron/followup_scheduler.php` |
| Pantallas del admin | `admin/*.php` |
| Envío por Twilio | `src/twilio-utils/` |

### La regla para no empeorarlo

> Las funciones que **deciden** no leen la base de datos ni los ajustes: reciben
> lo que necesitan y devuelven un resultado. Las que **hablan con el mundo**
> (`$wpdb`, `get_option`, Twilio, REST) no deciden nada.

Si se respeta, el día de la migración se mueve `db/` y `public/`, y el resto
viaja tal cual.

## El día que haya que salir de WordPress

No hay que migrar nada ahora. Esto es para que las decisiones de hoy no lo
compliquen mañana.

**Qué sería lo primero.** Separar el módulo en tres carpetas dentro del propio
plugin, sin cambiar de tecnología:

```
src/power_report/
├── nucleo/        decisiones puras: pasos, parsers, cálculos      ← portable
├── datos/         repositorios                                     ← se reescribe
└── plataforma/    REST, cron, admin, Twilio                        ← se reescribe
```

Ese movimiento se puede hacer en cualquier momento, es mecánico y no cambia el
comportamiento. A partir de ahí, lo portable ya está aislado.

**Qué se reescribiría de verdad.** Los repositorios (4 archivos, SQL plano sin
nada exótico de WordPress), el registro de rutas, el cron y el admin. La API
pública ya está diseñada como contrato estable: el portal consume JSON y no le
importa quién lo sirve, así que **un backend nuevo que responda el mismo JSON
no obliga a tocar el portal**.

**Qué sobrevive intacto.** El parser de tiempo, la unión de intervalos, la
máquina de pasos, los textos, la división territorial, y todo el cliente del
portal: mapa, filtros, mapa de calor, tarjetas e informes.

**Lo que conviene no hacer mientras tanto:** meter consultas dentro de las
funciones de decisión, usar `get_option()` en medio de un cálculo, o apoyarse en
tipos de contenido de WordPress para datos del dominio. Para los informes sí se
usó un tipo de contenido, y es la decisión correcta: eso **es** contenido
editorial, no datos del dominio.

## Decisiones que conviene conocer

**Por qué el mapa de calor está escrito a mano.** Se necesitaba el mismo dibujo
en pantalla y dentro del PNG descargable. Con una librería habría que replicar
su render; con código propio, la misma función sirve para las dos cosas y lo que
se descarga es exactamente lo que se ve.

**Por qué las fotos pasan por un proxy.** La tarjeta se compone en un `<canvas>`
y una imagen de otro dominio sin CORS lo inutiliza para descargar. El proxy
recibe solo el id del reporte, nunca una URL, para que no sirva de puerta a la
red interna.

**Por qué el mapa agrega por zona.** Agrupando por coordenada, un estado con
reportes repartidos salía pálido aunque encabezara la tabla. Ahora el mapa y el
ranking agregan por la misma unidad, así que el foco más caliente **es** el
número uno de la lista.

**Por qué el seguimiento es escalonado.** Preguntar cada 45 minutos durante una
falla de 15 horas son veinte mensajes y un bot silenciado. El intervalo se dobla
en cada intento con tope de 12 horas.
