# Añadir un servicio nuevo

Hoy el bot reporta **fallas eléctricas**. El objetivo es que reporte también
internet, agua, gas o aseo urbano sin duplicar el flujo entero cada vez.

Este documento es el plan. **Todavía no está implementado**: el módulo actual
asume "luz" en los nombres. Aquí está qué hay que hacer, en qué orden, y por qué
conviene hacerlo así.

## La observación de partida

El recorrido de una conversación es **idéntico para cualquier servicio público**:

```
zona  →  dirección  →  ¿cuándo empezó?  →  foto  →  ¿sigue?  →  seguimiento  →  comentario
```

Nada de eso es eléctrico. Lo único que cambia entre un apagón y una caída de
internet son los textos, las palabras que disparan el flujo, y como mucho una o
dos preguntas propias ("¿con qué proveedor?").

Por tanto: **un motor genérico y una definición declarativa por servicio.** No
un módulo nuevo copiado y pegado por cada servicio, que es el camino que acaba
con cuatro copias del mismo parser de horas.

## Qué se reutiliza tal cual

| Pieza | Por qué sirve igual |
|---|---|
| Máquina de pasos | El recorrido no cambia |
| Repositorios | Un reporte es un reporte |
| División territorial | La geografía es la misma |
| Parser de horas | "ayer a las 9" significa lo mismo |
| Unión de intervalos | Medir duración es medir duración |
| Cron de seguimiento | Preguntar "¿ya volvió?" vale para todo |
| API y portal | Añadiendo un filtro por servicio |
| Caja de comentarios | Igual |

Lo que cambia cabe en un archivo de configuración por servicio.

## La definición de un servicio

```php
// src/power_report/services/internet.php
return [
    'slug'     => 'internet',
    'nombre'   => 'Internet',
    'emoji'    => '📶',
    'color'    => '#7c5cff',              // para el mapa y las tarjetas
    'keywords' => ['internet', 'sin señal', 'no tengo internet', 'wifi', 'cantv'],

    // Preguntas propias, además de las del recorrido estándar.
    'pasos_extra' => [
        'proveedor' => [
            'despues_de' => 'direccion',
            'pregunta'   => '📶 ¿Con qué proveedor tienes el servicio?',
            'opciones'   => ['CANTV / ABA', 'Inter', 'Movistar', 'Digitel', 'Otro'],
            'guardar_en' => 'proveedor',   // va al campo meta del reporte
        ],
    ],

    // Solo los textos que se apartan del genérico.
    'mensajes' => [
        'welcome'   => '📶 *Reporte de Internet*\nAyúdanos a monitorear las fallas…',
        'ask_since' => '⏱️ ¿Cuándo se cayó el servicio?…',
    ],
];
```

El servicio eléctrico pasa a ser una definición más (`electricidad.php`), sin
privilegios. Los mensajes que no se declaran salen del juego genérico.

## Los cambios, en orden

### 1. Base de datos (aditivo, no rompe nada)

```sql
ALTER TABLE {prefijo}datav_power_reports
  ADD COLUMN service varchar(24) NOT NULL DEFAULT 'electricidad',
  ADD COLUMN meta text NULL,
  ADD INDEX idx_service (service, status);
```

`meta` guarda en JSON los campos propios de cada servicio (el proveedor, el
sector afectado) sin inventar una columna por servicio. Los reportes existentes
se quedan como `electricidad` solos, por el valor por defecto.

Subir `DATAV_POWER_DB_VERSION` para que la migración corra.

### 2. El motor deja de nombrar "luz"

Los repositorios y la máquina de pasos reciben el servicio como argumento:

- `datav_power_report_create_draft($phone, $profile, $service)`
- `datav_power_report_active_for_phone($phone, $service)` — alguien puede tener
  a la vez un apagón y una caída de internet, y son casos distintos
- Las consultas de estadísticas aceptan un filtro `service`

Los mensajes se resuelven en cuatro niveles en vez de tres: opción del admin →
archivo `.txt` del servicio → texto declarado en la definición → genérico.

### 3. Entrada al flujo

`isPowerReportInstruction()` pasa a ser `datav_detectar_servicio($mensaje)`, que
recorre las definiciones y devuelve cuál coincide, o `null`. La opción del menú
deja de ser una: **una fila por servicio** en el list-picker.

### 4. Pasos propios

La máquina de pasos ya es una tabla de `case`. Los pasos extra se insertan
leyendo `despues_de` de la definición: al terminar el paso estándar, se mira si
el servicio declaró alguno y se encadena antes de seguir.

### 5. API y portal

Todos los endpoints aceptan `service=electricidad|internet|…`; sin el parámetro
devuelven todos, para no romper a quien ya los consume.

`/power/meta` empieza a publicar la lista de servicios activos con su nombre,
emoji y color, y el portal pinta un selector arriba con esa lista en vez de
tenerla escrita a mano.

### 6. El renombrado

`datav_power_*` → `datav_report_*`, `power_report/` → `reports/`, y la tabla
`datav_power_reports` → `datav_reports`.

**Déjalo para el final y en un commit aparte**, cuando el segundo servicio ya
funcione. Es un cambio mecánico y ruidoso: mezclarlo con lógica nueva hace
imposible revisar ninguna de las dos cosas. Mientras tanto, lee `power` como "el
motor que nació con la luz".

## Cuánto trabajo es

| Paso | Dificultad | Riesgo |
|---|---|---|
| 1. Base de datos | Baja | Ninguno (aditivo) |
| 2. Motor con servicio | Media | Medio: toca el flujo vivo |
| 3. Entrada y menú | Baja | Bajo |
| 4. Pasos propios | Media | Bajo |
| 5. API y portal | Media | Bajo (compatible hacia atrás) |
| 6. Renombrado | Baja | Bajo, pero ruidoso |

El grueso está en el paso 2, y solo porque hay que tocar código que está
atendiendo conversaciones reales.

## Por dónde NO ir

**No dupliques el módulo.** Copiar `power_report/` a `internet_report/` funciona
el primer día y a los seis meses hay cuatro parsers de horas que arreglar por
separado cada vez que alguien escribe la hora de una forma nueva.

**No metas el servicio en el estado de la sesión.** El reporte asociado ya sabe
de qué servicio es; una columna más es un sitio más donde desincronizarse.

**No hagas una tabla por servicio.** El portal tendría que consultar N tablas
para pintar un mapa, y las estadísticas comparadas entre servicios —que es justo
lo interesante para un medio— se volverían un ejercicio de SQL.

## Lo que gana el proyecto

Con esto, añadir "agua" es escribir un archivo de definición y los textos.
Media hora, sin tocar el motor. Y como todos los servicios comparten tabla y
unidades de medida, el portal puede responder preguntas que hoy no puede:
cuántas horas sin servicios básicos acumula un municipio sumando luz, agua e
internet, o si las caídas de internet siguen a los apagones.
