# DatAvenger · Reporte Eléctrico Nacional

Monitoreo ciudadano del servicio eléctrico en Venezuela. La gente reporta sus
apagones por WhatsApp y el mapa público los muestra en tiempo real.

Iniciativa de **La República TV** y la **Red de DDHH en Acción**.

## Qué hay aquí

Dos plugins de WordPress que viven en sitios distintos y se hablan por una API:

```
plugins/
├── botfinalrptv/                  El bot de WhatsApp (Twilio)
└── datavenger-portal-electrico/   La web pública del mapa
```

| | Bot | Portal |
|---|---|---|
| Qué hace | Atiende las conversaciones, guarda los reportes y hace el seguimiento | Dibuja el mapa, las estadísticas y los informes |
| Dónde vive | El WordPress conectado a Twilio | `larepublicaweb.com` |
| Datos | Dueño de la base de datos | No guarda nada: todo lo pide a la API |

El portal **nunca** toca la base de datos del bot. Habla con él por la API
pública `datav/v1`, que jamás devuelve el teléfono ni el nombre de quien reporta.

### La API entre los dos

```
GET /wp-json/datav/v1/power/meta       identidad y estado del servicio
GET /wp-json/datav/v1/power/live       fallas activas ahora mismo
GET /wp-json/datav/v1/power/stats      totales y ranking de un periodo
GET /wp-json/datav/v1/power/points     focos del mapa de calor
GET /wp-json/datav/v1/power/timeline   reportes por hora o por día
GET /wp-json/datav/v1/power/report/42  detalle de un reporte
GET /wp-json/datav/v1/power/comments   mensajes que deja la gente al cerrar
```

Todas aceptan `range=today|yesterday|24h|7d|30d|month` o un `from`/`to` a medida.

Si el portal está en **modo proxy** (Ajustes → Portal Reporte Eléctrico), esas
consultas salen del propio WordPress del portal en vez del navegador del
visitante, y pasan por `datavenger-portal/v1/proxy?path=…`.

## Empaquetar y publicar

```powershell
.\build.ps1                      # empaqueta los dos con su versión actual
.\build.ps1 portal               # solo el portal
.\build.ps1 bot -Bump patch      # sube la versión del bot y empaqueta
.\build.ps1 -Bump minor -Tag     # sube ambos, empaqueta y etiqueta en git
.\build.ps1 -Limpiar             # borra dist/ antes de empezar
```

Los `.zip` salen en `dist/`, listos para **Plugins → Añadir → Subir plugin**.

Qué hace `-Bump` además de renombrar el archivo: escribe la versión nueva en la
cabecera que lee WordPress **y** en las constantes del plugin que repiten esa
misma versión (`DVPE_VERSION`). Eso importa porque esa constante es la que
rompe la caché del navegador: si se queda atrás, el visitante sigue viendo el
CSS y el JavaScript viejos aunque el plugin esté actualizado.

Las constantes con numeración propia —la versión de la API o la del esquema de
base de datos— no se tocan, porque solo se actualiza lo que coincide exactamente
con la versión anterior del plugin.

### Qué no viaja en el zip

Los registros que el bot escribe en producción (`twilio.txt`, `*_log.txt`), la
carpeta `.git` y los propios `.zip`. Si se colaran, cada despliegue pisaría los
registros del servidor con los de la máquina de quien publica.

El `vendor/` del bot **sí** va dentro: son las 2.600 clases del SDK de Twilio y
el servidor no ejecuta composer. Por eso no está en el repositorio pero sí en el
paquete.

## Trabajar en esto

No hace falta compilar nada: PHP, CSS y JavaScript se escriben a mano y se
suben tal cual. Para el bot sí hace falta `vendor/`; si clonas el repositorio
desde cero:

```bash
cd plugins/botfinalrptv && composer install --no-dev
```

### Después de subir

- **Bot:** las migraciones de base de datos corren solas al cargar WordPress.
- **Portal:** recarga con Ctrl+F5. Las reglas de URL se regeneran automáticamente
  cuando cambia la versión, así que no hay que tocar los enlaces permanentes.

## Un detalle de los datos que conviene no olvidar

Hay dos formas de medir el tiempo sin luz y significan cosas distintas:

- **Tiempo con fallas** — la unión de los reportes. Si veinte vecinos reportan el
  mismo apagón de 3 h, son 3 h. Esto se lee como duración.
- **Horas acumuladas** — la suma de todos los reportes. Esos mismos veinte
  vecinos dan 60 h. Esto mide impacto y participación, no tiempo de reloj.

El portal muestra la primera en los indicadores y el ranking, y usa la segunda
solo como intensidad del mapa de calor. Al redactar informes, conviene decir
cuál de las dos es cada número.
