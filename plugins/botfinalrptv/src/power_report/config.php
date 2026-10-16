<?php

/**
 * Configuración del módulo Reporte Eléctrico.
 *
 * Todo lo que el administrador puede cambiar desde WordPress vive aquí, con su
 * valor por defecto. Los textos se resuelven en tres niveles, de mayor a menor
 * prioridad: opción guardada en el admin -> archivo .txt -> fallback en código.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Ajustes con su valor por defecto. */
function datav_power_settings_defaults()
{
    return [
        'datAv_power_enabled' => '1',
        'datAv_power_menu_label' => 'Reporte Eléctrico',
        // Portal público donde se ven todos los reportes. Vacío = no se envía el
        // enlace al cerrar el flujo.
        'datAv_power_portal_url' => 'https://larepublicaweb.com/reporte-electrico/',
        'datAv_power_keywords' => "luz\napagon\napagón\nsin luz\nreporte electrico\nreporte eléctrico\n8",
        // Caja de comentarios al cerrar un caso.
        'datAv_power_comments_enabled' => '1',
        'datAv_power_comment_minutes' => '60',
        'datAv_power_comments_public' => '1',
        'datAv_power_interval_minutes' => '30',
        'datAv_power_max_checks' => '5',
        'datAv_power_followup_content_sid' => '',
        // Vacío = cualquier origen puede consumir la API pública. El portal vive
        // en otro WordPress, así que restringirlo es opcional, no obligatorio.
        'datAv_power_api_allowed_origins' => '',
    ];
}

function datav_power_setting($key)
{
    $defaults = datav_power_settings_defaults();
    $default = isset($defaults[$key]) ? $defaults[$key] : '';

    $value = get_option($key, $default);

    // Una opción guardada en blanco debe volver al valor por defecto, no dejar el
    // flujo sin texto. La excepción son los ajustes que sí pueden ir vacíos.
    $may_be_empty = ['datAv_power_followup_content_sid', 'datAv_power_api_allowed_origins', 'datAv_power_portal_url'];

    if ($value === '' && !in_array($key, $may_be_empty, true)) {
        return $default;
    }

    return $value;
}

function datav_power_is_enabled()
{
    return datav_power_setting('datAv_power_enabled') === '1';
}

function datav_power_interval_minutes()
{
    return max(5, (int) datav_power_setting('datAv_power_interval_minutes'));
}

/** ¿Se le ofrece dejar un mensaje al cerrar el caso? */
function datav_power_comments_enabled()
{
    return datav_power_setting('datAv_power_comments_enabled') === '1';
}

/** ¿Los comentarios se publican en el portal o se quedan solo en el admin? */
function datav_power_comments_public()
{
    return datav_power_setting('datAv_power_comments_public') === '1';
}

/**
 * Cuánto tiempo sigue abierta la caja de comentarios.
 *
 * Pasado el plazo, lo que escriba la persona vuelve a ser un mensaje normal del
 * bot. Se acota a un día: más allá nadie relaciona su mensaje con el reporte.
 */
function datav_power_comment_minutes()
{
    return min(1440, max(5, (int) datav_power_setting('datAv_power_comment_minutes')));
}

function datav_power_max_checks()
{
    return max(1, (int) datav_power_setting('datAv_power_max_checks'));
}

/** Portal público con el mapa y el listado de todos los reportes. '' = desactivado. */
function datav_power_portal_url()
{
    return trim((string) datav_power_setting('datAv_power_portal_url'));
}

/** Palabras clave que activan el flujo, ya normalizadas. */
function datav_power_keywords()
{
    $raw = datav_power_setting('datAv_power_keywords');
    $lines = preg_split('/[\r\n]+/', (string) $raw);

    $keywords = [];
    foreach ($lines as $line) {
        $line = datav_power_normalize_text($line);
        if ($line !== '') {
            $keywords[] = $line;
        }
    }

    return $keywords;
}

/**
 * Minúsculas, sin acentos y sin espacios sobrantes.
 * Se usa tanto para las palabras clave como para comparar respuestas del usuario.
 */
function datav_power_normalize_text($text)
{
    $text = trim((string) $text);

    if ($text === '') {
        return '';
    }

    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

    $accents = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ];

    $text = strtr($text, $accents);

    return preg_replace('/\s+/', ' ', $text);
}

/**
 * Registro de los mensajes del flujo.
 * key => [ 'label' para el admin, 'file' relativo a src/messages/, 'fallback' ].
 */
function datav_power_message_registry()
{
    return [
        'welcome' => [
            'label' => 'Bienvenida al flujo',
            'file' => 'power_report/bienvenida.txt',
            'fallback' => "⚡ *Reporte Eléctrico Nacional*\nAyúdanos a monitorear las fallas del servicio eléctrico en todo el país.\n\nEnvía *salir* en cualquier momento para volver al menú.",
        ],
        'ask_estado' => [
            'label' => 'Pedir estado',
            'file' => 'power_report/pedir-estado.txt',
            'fallback' => "📍 ¿En qué *estado* estás?\nResponde con el número.\n\nTambién puedes compartir tu ubicación 📎 y lo detectamos por ti.",
        ],
        'ask_municipio' => [
            'label' => 'Pedir municipio',
            'file' => 'power_report/pedir-municipio.txt',
            'fallback' => "🏙️ ¿En qué *municipio* de {estado}?\nResponde con el número.",
        ],
        'ask_parroquia' => [
            'label' => 'Pedir parroquia',
            'file' => 'power_report/pedir-parroquia.txt',
            'fallback' => "🏘️ ¿En qué *parroquia* de {municipio}?\nResponde con el número.",
        ],
        'ask_direccion' => [
            'label' => 'Pedir sector / dirección',
            'file' => 'power_report/pedir-direccion.txt',
            'fallback' => "✍️ Escribe tu *sector o dirección* para ubicar mejor la falla.\n\nEnvía *0* si prefieres omitirlo.",
        ],
        'ask_since' => [
            'label' => 'Pedir cuándo se fue la luz',
            'file' => 'power_report/pedir-hora.txt',
            'fallback' => "⏱️ ¿Cuándo se fue la luz?\n\nSi fue hoy, escribe la hora.\nEjemplo: 8 de la mañana.\n\nSi fue ayer, escribe \"ayer\" y la hora.\nEjemplo: ayer a las 9 de la noche.\n\n¿No recuerdas la hora? Escribe *0* para ver opciones.",
        ],
        'invalid_time' => [
            'label' => 'No se entendió la hora',
            'file' => 'power_report/hora-invalida.txt',
            'fallback' => "🤔 No entendí esa hora.\n\nEscríbela así: *3pm*, *15:30* o *ayer 9pm*.\nSi no la recuerdas, envía *0*.",
        ],
        'ask_since_range' => [
            'label' => 'Pedir el tiempo por rangos (si no recuerda la hora)',
            'file' => 'power_report/pedir-tiempo.txt',
            'fallback' => '⏱️ No hay problema. ¿Cuánto tiempo llevas sin luz, más o menos?',
        ],
        'time_saved' => [
            'label' => 'Confirmación de la hora entendida',
            'file' => 'power_report/hora-registrada.txt',
            'fallback' => '📌 Anotado: sin luz desde *{hora}* ({duracion}).',
        ],
        'ask_photo' => [
            'label' => 'Pedir foto',
            'file' => 'power_report/pedir-foto.txt',
            'fallback' => "📷 Si quieres, envía una *foto* de la zona sin luz.\n\nEnvía *0* para terminar sin foto.",
        ],
        'gps_suggest' => [
            'label' => 'Confirmar ubicación detectada por GPS',
            'file' => 'power_report/confirmar-gps.txt',
            'fallback' => "📍 Detectamos que estás cerca de *{municipio}, {estado}*.\n\n1. Sí, es correcto\n2. No, quiero elegirlo yo",
        ],
        'saved' => [
            'label' => 'Reporte registrado',
            'file' => 'power_report/reporte-registrado.txt',
            'fallback' => "✅ *Reporte registrado.* Gracias por sumarte.\n\n📍 {zona}\n⚡ Falla iniciada: {inicio}\n⌛ Tiempo transcurrido: {transcurrido}\n\n🔴 La falla sigue activa. Te escribiremos para comprobar si regresó el servicio.\n\nTu reporte ya aparece en el mapa nacional.",
        ],
        'invalid_option' => [
            'label' => 'Opción inválida',
            'file' => 'power_report/opcion-invalida.txt',
            'fallback' => '❌ No entendí esa opción. Responde con uno de los números de la lista.',
        ],
        'already_active' => [
            'label' => 'Ya tiene un reporte abierto',
            'file' => 'power_report/reporte-abierto.txt',
            'fallback' => "⚡ Ya tienes un reporte abierto en *{zona}*, sin servicio desde hace {duracion}.\n\n¿Ya volvió la luz?\n\n1. Sí, ya llegó\n2. No, sigue sin servicio",
        ],
        'followup' => [
            'label' => 'Seguimiento: ¿ya volvió la luz?',
            'file' => 'power_report/seguimiento.txt',
            'fallback' => "⚡ Seguimos monitoreando tu reporte en *{zona}* ({duracion} sin servicio).\n\n¿Ya volvió la luz?\n\n1. Sí, ya llegó\n2. No, sigue sin servicio",
        ],
        'ask_current_state' => [
            'label' => 'Preguntar si la luz ya regresó (cierre del formulario)',
            'file' => 'power_report/ya-regreso.txt',
            'fallback' => "💡 Una última cosa: ¿ya regresó la luz?\n\n1. Sí, ya regresó\n2. No, todavía seguimos sin servicio",
        ],
        'ask_restored_when' => [
            'label' => 'Preguntar cuándo regresó (acaba de llegar u otra hora)',
            'file' => 'power_report/cuando-regreso.txt',
            'fallback' => "🕐 ¿Cuándo regresó la luz?\n\n1. Acaba de regresar\n2. Regresó a otra hora",
        ],
        'ask_restored_at' => [
            'label' => 'Preguntar a qué hora volvió la luz',
            'file' => 'power_report/pedir-hora-regreso.txt',
            'fallback' => "🙌 ¡Qué bueno! ¿A qué hora volvió la luz?\n\nEscríbela como prefieras: *3pm*, *15:30*, *hace 20 minutos*.\n\nSi acaba de llegar ahorita, envía *0*.",
        ],
        'invalid_restored_time' => [
            'label' => 'No se entendió la hora en que volvió la luz',
            'file' => 'power_report/hora-regreso-invalida.txt',
            'fallback' => "🤔 No entendí esa hora. ¿A qué hora volvió la luz?\n\nEjemplos: *3pm*, *15:30*, *hace 20 minutos*.\nSi acaba de llegar, envía *0*.",
        ],
        'restored_too_early' => [
            'label' => 'La hora indicada es anterior al apagón',
            'file' => 'power_report/hora-regreso-imposible.txt',
            'fallback' => "⚠️ Esa hora es anterior al inicio de la falla ({inicio}), así que no puede ser.\n\n¿A qué hora volvió la luz?",
        ],
        'restored' => [
            'label' => 'Servicio restablecido',
            'file' => 'power_report/restablecido.txt',
            'fallback' => "✅ *Servicio restablecido.* Cerramos tu reporte.\n\n📍 {zona}\n⚡ Inicio: {inicio}\n💡 Restablecimiento: {hora}\n⏱️ Duración total: *{duracion}*\n\nGracias por ayudarnos a documentar el servicio eléctrico.",
        ],
        'still_out' => [
            'label' => 'Confirmó que sigue sin luz',
            'file' => 'power_report/sigue-sin-luz.txt',
            'fallback' => "🔴 La falla continúa activa.

📍 {zona}
⏱️ Ya acumula {duracion} sin servicio.

Seguiremos haciendo seguimiento y te volveremos a consultar más adelante.",
        ],
        'undetermined' => [
            'label' => 'Caso cerrado como indeterminado',
            'file' => 'power_report/indeterminado.txt',
            'fallback' => "⚠️ No pudimos confirmar si el servicio volvió, así que cerramos tu reporte de *{zona}*.\n\nSi sigues sin luz, envía *luz* para abrir uno nuevo.",
        ],
        'ask_comment' => [
            'label' => 'Invitar a dejar un mensaje (caja de comentarios)',
            'file' => 'power_report/pedir-comentario.txt',
            'fallback' => "💬 ¿Quieres dejarnos un mensaje sobre esta falla? Cuéntanos cómo la viviste y lo publicamos junto a tu reporte.\n\nEscríbelo en los próximos {minutos} minutos. Si prefieres no dejarlo, envía *0*.",
        ],
        'comment_saved' => [
            'label' => 'Mensaje del comentario guardado',
            'file' => 'power_report/comentario-guardado.txt',
            'fallback' => '🙏 ¡Gracias! Guardamos tu mensaje junto al reporte.',
        ],
        'comment_skipped' => [
            'label' => 'No quiso dejar mensaje',
            'file' => 'power_report/comentario-omitido.txt',
            'fallback' => '👍 Listo, sin problema. Gracias por reportar.',
        ],
        'comment_empty' => [
            'label' => 'El comentario llegó vacío (solo audio o imagen)',
            'file' => 'power_report/comentario-vacio.txt',
            'fallback' => "✍️ Escríbeme el mensaje en texto, por favor.\n\nSi prefieres no dejarlo, envía *0*.",
        ],
        'portal' => [
            'label' => 'Ver todos los reportes (portal)',
            'file' => 'power_report/ver-reportes.txt',
            'fallback' => "🗺️ Mira el *mapa nacional* con todos los reportes eléctricos en tiempo real:\n\n{url}",
        ],
        'exit' => [
            'label' => 'Salir del flujo',
            'file' => 'power_report/salir.txt',
            'fallback' => 'Saliste del Reporte Eléctrico. ¡Gracias!',
        ],
    ];
}

/**
 * Texto final de un mensaje, con los {marcadores} sustituidos.
 *
 * @param string $key          Clave del registro.
 * @param array  $replacements ['zona' => 'Maracaibo, Zulia', ...]
 */
function datav_power_message($key, $replacements = [])
{
    $registry = datav_power_message_registry();

    if (!isset($registry[$key])) {
        return '';
    }

    $entry = $registry[$key];
    $text = trim((string) get_option('datAv_power_msg_' . $key, ''));

    if ($text === '') {
        $text = datav_power_read_message_file($entry['file']);
    }

    if ($text === '') {
        $text = $entry['fallback'];
    }

    foreach ($replacements as $needle => $value) {
        $text = str_replace('{' . $needle . '}', (string) $value, $text);
    }

    return $text;
}

/**
 * Lee un .txt de src/messages/ devolviendo '' si no existe.
 * No se usa get_message() directamente porque lanza una excepción cuando el
 * archivo falta, y aquí un archivo ausente solo significa "usa el fallback".
 */
function datav_power_read_message_file($relative_path)
{
    $path = plugin_dir_path(__FILE__) . '../messages/' . $relative_path;

    if (!file_exists($path)) {
        return '';
    }

    $content = file_get_contents($path);

    return $content === false ? '' : trim($content);
}
