# Registro de cambios

Lo último, arriba. Cada entrada dice qué cambió y, cuando importa, por qué.

Los dos plugins llevan numeración propia. La **API** también, porque el portal
puede estar en una versión distinta a la del bot y necesita saber con qué habla.

---

## 2026-10-15 · Repositorio

**Monorepo.** Los dos plugins pasan a `plugins/` dentro de un solo repositorio,
con la historia del bot importada bajo su nueva ruta (9 commits, ninguno
perdido). Antes vivían en carpetas sueltas sin forma de versionarlos a la vez,
y cada cambio del contrato de la API toca a los dos.

**`build.ps1`.** Un comando que empaqueta, sube la versión y etiqueta:

```powershell
.\build.ps1 portal -Bump patch
```

Escribe la versión nueva en la cabecera **y** en la constante del plugin, que es
la que rompe la caché del navegador. Venía haciéndose a mano y era el error más
repetido del proyecto. Los `.zip` salen en `dist/`, fuera del repositorio.

**Documentación.** `CLAUDE.md` para el contexto del agente, `docs/ARQUITECTURA.md`,
`docs/NUEVO-SERVICIO.md` y este registro.

---

## Portal

### 1.9.1 · Iconos de redes
Los botones de compartir pasan a iconos circulares con el color de cada marca.
Copiar enlace confirma con un check verde: un icono que no cambia no confirma
nada. En táctil los colores se ven siempre y los botones crecen a 44 px.

### 1.9.0 · Compartir un reporte
Tarjeta descargable de 1080 × 1350 por reporte, con dos maquetas: con foto, la
imagen manda y el mapa queda de contexto; sin foto, el mapa es el protagonista.
En móvil se comparte la imagen con el menú nativo del sistema.

Las fotos pasan por un proxy del propio dominio (`/photo?id=`), porque una
imagen de otro origen inutiliza el `<canvas>` para descargar. El proxy recibe
solo el id, nunca una URL.

Los enlaces compartidos (`?report=412`) abren esa ficha y su vista previa se
resuelve en el servidor, porque Facebook y X no ejecutan JavaScript.

*De paso:* el proxy no reenviaba el parámetro `level`, así que la agregación por
zona del mapa nunca llegaba en producción.

### 1.8.0 · Informes
Sección de informes con su panel de carga (tipo de contenido propio: título,
texto, imagen previa y PDF), listado público en `/reporte-electrico/informes/`,
aviso deslizante del último informe —que no vuelve a aparecer en el día si se
cierra— y shortcode `[datavenger_informes]` para el resto del sitio.

### 1.7.0 · Presentación y SEO
Barras de desplazamiento propias y `color-scheme: dark`, que también arregla los
selectores de fecha del sistema. Favicon de DatAvenger. Canonical, Open Graph,
Twitter Card, `preconnect` y datos estructurados **como `Dataset`**, para poder
aparecer en Google Dataset Search. El portal entra en el sitemap del sitio.

### 1.6.1 · El estado del reporte era mentira
El panel de detalle tenía "Falla activa" escrito a fuego: lo mostraba igual en
reportes ya cerrados. Ahora lee el estado real y distingue *tiempo transcurrido*
de *duración total*. Además se refresca con el ciclo: antes era una foto fija
del momento en que se abrió.

### 1.6.0 · El mapa y el ranking dicen lo mismo
Los focos se agregan por zona, al mismo nivel que la tabla. Agrupando por
coordenada, un estado con reportes repartidos salía pálido aunque encabezara el
ranking. Ahora el foco más caliente **es** el número uno de la lista.

### 1.5.2 · El selector de periodo no hacía nada
Al elegir un periodo, el manejador cerraba antes el desplegable de fechas, y eso
resincronizaba el `<select>` al valor viejo, que se leía justo después. El valor
se captura ahora antes de tocar nada.

### 1.5.1 · Desplegables en móvil
El menú de descarga se anclaba por la derecha y se salía de la pantalla. Abrir
uno cierra el otro.

### 1.5.0 · Tiempo con fallas
El indicador de horas era una suma de reportes: veinte vecinos reportando el
mismo apagón de 3 h daban 60 h. Ahora se calcula la **unión de los intervalos**
y la cifra se puede leer como duración. El ranking tenía el mismo defecto.

### 1.4.2 · La maqueta de la imagen
La mascota pasa a la cabecera: abajo se comía el valor de la segunda columna.
Las cifras van en dos líneas porque el ancho del número se medía con la fuente
equivocada y la etiqueta caía encima.

### 1.4.1 · Periodo en móvil
Los seis presets no caben en 390 px: ahí manda un selector nativo.

### 1.4.0 · Barra de control
Todo lo que gobierna la vista sale de las tarjetas a una barra propia. Los
controles del mapa chocaban con la cabecera y "Fallas en curso" quedaba fuera de
pantalla.

### 1.3.0 · Filtros y mapa de calor
Rango libre de fecha y hora con el periodo en la URL, mapa de calor con selector
de peso, histograma por hora o por día, y exportación de imagen en dos formatos.

### 1.2.0 · Mapa base sin clave
CARTO dejó de servir sus mapas sin registro y estampaba "API KEY REQUIRED" sobre
las teselas. Se pasa a Esri Dark Gray, configurable desde los ajustes.

---

## Bot

El plugin sigue en **2.0.0**: no se subió durante este ciclo. Convendría
empezar a moverlo con `build.ps1 bot -Bump`.

### Flujo eléctrico
- **Estado del servicio al cerrar el formulario.** Antes se daba por hecho que
  la falla seguía activa; quien reportaba un apagón de ayer ya resuelto entraba
  al seguimiento con el contador corriendo. Ahora se pregunta, y si ya regresó
  se pide cuándo (dos opciones, y la hora solo a quien la necesita).
- **Seguimiento escalonado.** El intervalo se dobla en cada intento con tope de
  12 h: 45 min → 1 h 30 → 3 h → 6 h → 12 h. Seis mensajes cubren día y medio de
  falla donde antes hacían falta cuarenta y siete.
- **Hora de inicio exacta.** "¿Cuándo se fue la luz?" con parser propio: entiende
  `3pm`, `15:30`, `ayer a las 9 de la noche`, `hace 2 horas`. De las lecturas
  posibles toma la más reciente ya ocurrida. Quien no la recuerde responde `0` y
  elige un rango.
- **Comentarios.** Al cerrarse un caso se ofrece dejar un mensaje; la sesión
  caduca en 60 minutos para que nadie quede atrapado.
- **Enlace al portal** al registrar y al cerrar el reporte.

### Flujo de corresponsales
La sesión no caducaba nunca: quien enviaba un reporte y no escribía "salir"
quedaba dentro para siempre, y semanas después un "hola" suyo llegaba al equipo
como reporte nuevo. Ahora caduca, reconoce cualquier palabra de menú, y tocar
una opción del menú sale del flujo y la atiende en vez de reenviarla.

### Menú
El Reporte Eléctrico pasa a primera opción con ⚡ y se añade al menú de texto,
donde no estaba. El saludo suelto desaparece: el propio menú ya abre con "¡Hola!
bienvenido a DatAvenger".

---

## API `datav/v1`

| Versión | Cambio |
|---|---|
| **1.4.0** | `/power/points` acepta `level` y agrega por zona |
| **1.3.0** | Cobertura (unión de intervalos) en totales y ranking |
| **1.2.0** | Rango libre `from`/`to`, `/power/points`, `/power/timeline` |
| **1.1.0** | `/power/comments` y los comentarios en el detalle |
| **1.0.0** | `meta`, `live`, `stats`, `zones`, `report/{id}` |

## Base de datos del bot

| Versión | Cambio |
|---|---|
| **1.2.0** | Comentarios en los reportes y caducidad de sesión |
| **1.1.0** | Tablas de reportes y zonas, columnas de sesión |
