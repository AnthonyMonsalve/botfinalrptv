<?php

/**
 * Mensajes de cierre: un texto que se envía por separado después de que el
 * usuario completa una acción, en vez de ocupar una opción del menú.
 *
 * Nace de las redes sociales, pero el mecanismo es genérico: se registra un
 * evento en cada punto donde un flujo termina y aquí se decide qué mandar,
 * cuándo y cada cuánto.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Puntos del bot donde se puede enviar el mensaje.
 *
 * 'default' marca cuáles vienen activados de fábrica. Se registran más eventos
 * de los que se usan por defecto para que activarlos sea marcar una casilla y
 * no tocar código.
 */
function datav_followup_events()
{
    return [
        'news_viewed' => [
            'label' => 'Al consultar las noticias (#VzlaTieneVoz)',
            'hint' => 'Después del listado y del canal de difusión.',
            'default' => 1,
        ],
        'validate_started' => [
            'label' => 'Al elegir "Enviar un reporte"',
            'hint' => 'Se envía nada más pulsar la opción, antes de que escriba el reporte.',
            'default' => 0,
        ],
        'validate_received' => [
            'label' => 'Al enviar un reporte de información',
            'hint' => 'Cuando ya mandó el texto o la imagen a verificar.',
            'default' => 1,
        ],
        'power_first_report' => [
            'label' => 'Al registrar su PRIMER reporte eléctrico',
            'hint' => 'Solo la primera vez que esa persona reporta una falla.',
            'default' => 1,
        ],
        'power_report' => [
            'label' => 'Al registrar cualquier reporte eléctrico',
            'hint' => 'Todas las veces. Si también marcas el anterior, se envía una sola vez.',
            'default' => 0,
        ],
        'course_completed' => [
            'label' => 'Al terminar el curso de desinformación',
            'hint' => 'Después del certificado.',
            'default' => 1,
        ],
        'infomemes_completed' => [
            'label' => 'Al terminar el curso de infomemes',
            'hint' => 'Después del cierre del módulo 6.',
            'default' => 1,
        ],
    ];
}

function datav_followup_defaults()
{
    return [
        'datav_followup_enabled' => '1',
        'datav_followup_message' => '',
        'datav_followup_cooldown_days' => '3',
        'datav_followup_delay_seconds' => '2',
    ];
}

function datav_followup_setting($key)
{
    $defaults = datav_followup_defaults();
    $default = isset($defaults[$key]) ? $defaults[$key] : '';

    $value = get_option($key, $default);

    // El mensaje sí puede quedar vacío: significa "usa social_media.txt".
    if ($value === '' && $key !== 'datav_followup_message') {
        return $default;
    }

    return $value;
}

function datav_followup_is_enabled()
{
    return datav_followup_setting('datav_followup_enabled') === '1';
}

function datav_followup_event_is_enabled($event)
{
    $events = datav_followup_events();

    if (!isset($events[$event])) {
        return false;
    }

    return get_option('datav_followup_event_' . $event, (string) $events[$event]['default']) === '1';
}

/** Días que deben pasar antes de volver a enviarle el mensaje a la misma persona. */
function datav_followup_cooldown_days()
{
    return max(0, (int) datav_followup_setting('datav_followup_cooldown_days'));
}

/**
 * Pausa entre el mensaje del flujo y el de cierre.
 *
 * Antes era un sleep(2) repetido en cuatro sitios. Centralizarlo permite
 * bajarlo a 0 si el webhook empieza a ir justo de tiempo.
 */
function datav_message_delay()
{
    return max(0, (int) datav_followup_setting('datav_followup_delay_seconds'));
}

/** Texto a enviar: el configurado en el admin o, si está vacío, social_media.txt. */
function datav_followup_message_text()
{
    $custom = trim((string) datav_followup_setting('datav_followup_message'));

    if ($custom !== '') {
        return $custom;
    }

    $path = plugin_dir_path(__FILE__) . '../messages/social_media.txt';

    if (!file_exists($path)) {
        return '';
    }

    $content = file_get_contents($path);

    return $content === false ? '' : trim($content);
}
