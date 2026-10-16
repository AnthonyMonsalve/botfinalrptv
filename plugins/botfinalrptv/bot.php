<?php
/*
Plugin Name: DATAVENGER Twilio Responder
Plugin URI: https://latvcalle.com
Description: Recibe mensajes de Twilio y responde automáticamente. Incluye el Reporte Eléctrico Nacional con su API pública y los mensajes de cierre.
Version: 2.0.0
Author: La TV Calle
Author URI: https://latvcalle.com
*/

use Twilio\Rest\Client;

require_once __DIR__ . '/src/get_noticias/get_presidenciales.php';

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/instructions_validators/is_menu_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_hello_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_interactive_course_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_exit_course_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_yes_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_valid_option_test.php';
require_once __DIR__ . '/src/instructions_validators/is_validate_information_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_elecciones_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_quiero_elegir_instruction.php';
require_once __DIR__ . '/src/instructions_validators/if_fake_news_generator_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_interactive_infomemes_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_memegenerator_instruction.php';

require_once __DIR__ . '/src/power_report/bootstrap.php';
require_once __DIR__ . '/src/followup_messages/bootstrap.php';

require_once __DIR__ . '/src/get_messages/get_message.php';

require_once __DIR__ . '/src/twilio-utils/send_twilio_message.php';
require_once __DIR__ . '/src/twilio-utils/image_url_replace.php';

require_once __DIR__ . '/src/course_database/started_course.php';
require_once __DIR__ . '/src/course_database/get_test_status.php';
require_once __DIR__ . '/src/course_database/get_fake_status.php';
require_once __DIR__ . '/src/course_database/logout_course.php';
require_once __DIR__ . '/src/course_database/create_database.php';
require_once __DIR__ . '/src/course_database/handle_test_course_progression.php';
require_once __DIR__ . '/src/course_database/update_status_fake.php';

require_once __DIR__ . '/src/course_database/infomemes/update_status_infomemes.php';
require_once __DIR__ . '/src/course_database/infomemes/started_infomemes.php';
require_once __DIR__ . '/src/course_database/infomemes/logout_infomemes.php';
require_once __DIR__ . '/src/course_database/infomemes/handle_test_infomemes_progression.php';
require_once __DIR__ . '/src/course_database/infomemes/get_infomemes_progression_status.php';

require_once __DIR__ . '/src/validate_database/get_validate_status.php';
require_once __DIR__ . '/src/validate_database/validation_session.php';
require_once __DIR__ . '/src/validate_database/started_validation.php';
require_once __DIR__ . '/src/validate_database/update_status_validation.php';
require_once __DIR__ . '/src/validate_database/fake_validation.php';

require_once __DIR__ . '/src/dashboard_respuestas/dashboard_respuestas.php';

require_once __DIR__ . '/src/dashboard_config/dashboard_config.php';

require_once __DIR__ . '/src/overlay_fake_news/overlay_fake_news.php';

require_once __DIR__ . '/src/certificate_infomemes/certificate_infomemes.php';
require_once __DIR__ . '/src/dashboard_memesDesca/dashboard_memedesca.php';

function register_twilio_responder_route()
{
  register_rest_route('twilio/v1', '/message/', array(
    'methods' => 'POST',
    'callback' => 'handle_twilio_message',
    'permission_callback' => '__return_true'
  ));
}

add_action('rest_api_init', 'register_twilio_responder_route');

// Crear la tabla en la activación del plugin
register_activation_hook(__FILE__, 'create_course_table');


function handle_twilio_message($request)
{
  $params = $request->get_body_params(); // Obtiene los parámetros decodificados automáticamente

  $body = isset($params['Body']) ? $params['Body'] : '';
  $from = isset($params['From']) ? $params['From'] : '';
  $to = isset($params['To']) ? $params['To'] : '';
  $ProfileName = isset($params['ProfileName']) ? $params['ProfileName'] : '';
  $string = get_option('datAv_twilio_admins', '');
  // Convertir el string en un array
  $from_admin = explode(';', $string);
  $messageReceived = $body;

  $log_file = plugin_dir_path(__FILE__) . 'twilio.txt';

  // Iterar a través de todos los parámetros y guardarlos en el log
  foreach ($params as $key => $value) {
    // Escribir cada par clave-valor en el log
    file_put_contents($log_file, $key . ': ' . $value . "\n", FILE_APPEND);
  }

  $MediaUrl = isset($params['MediaUrl0']) ? $params['MediaUrl0'] : null;

  $sid = get_option('datAv_twilio_sid', '');
  $token = get_option('datAv_twilio_token', '');

  $client = new Client($sid, $token);

  $dontHaveError = true;

  if (!hasUserStartedCourse($from) && !hasUserStartedInfomemes($from) && !hasUserStartedValidation($from) && !hasUserStartedFake($from) && !hasUserStartedPowerReport($from)) {
    $dontHaveError = handle_main_menu_message($messageReceived, $from, $to, $client, $params, $from_admin);
  } else if (hasUserStartedValidation($from)) {
    // Salir del flujo de corresponsales: "salir" y sus variantes, pero también
    // cualquier forma de pedir el menú. El menú principal promete que escribir
    // "República" funciona en cualquier momento, y aquí no se cumplía.
    if (isExitCourseInstruction($messageReceived) || isMenuInstruction($messageReceived)) {
      datav_validate_close($from);
      send_twilio_message($to, $from, get_message('gracias_validate.txt'), $client);
      $dontHaveError = send_twilio_menu_message($to, $from, $client);
    } else if (isMenuSelection($params)) {
      // Tocó una opción del menú: no es información para los corresponsales, es
      // que quiere irse a otra cosa. Se cierra el flujo y se atiende su elección
      // en vez de reenviar un "3" suelto al equipo.
      datav_validate_close($from);
      $dontHaveError = handle_main_menu_message($messageReceived, $from, $to, $client, $params, $from_admin);
    } else {
      foreach ($from_admin as $admin) {
        $dontHaveError = send_twilio_message($to, $admin, '‼ *Quiero corroborar la veracidad de esta información:* (' . $ProfileName . ' - ' . $from . ') ' . $messageReceived, $client, $MediaUrl);
      }
      if ($dontHaveError) {
        // Cada envío renueva la vigencia: mientras siga mandando información la
        // conversación sigue abierta, y al dejar de escribir se cierra sola.
        datav_validate_touch($from);

        $body = str_replace('{minutos}', datav_validate_minutes(), get_message('recibido_validate.txt'));

        $dontHaveError = send_twilio_message($to, $from, $body, $client);
        datav_followup_send('validate_received', $from, $to, $client);
      }
    }
  } else if (hasUserStartedFake($from)) {
    if (isExitCourseInstruction($messageReceived)) {
      update_status_fake($from, 'not_started');
      $dontHaveError = send_twilio_menu_message($to, $from, $client);
    } else {
      $dontHaveError = send_twilio_message($to, $from, '🖼️ Su imagen se está generando...', $client);
      $log_file = plugin_dir_path(__FILE__) . 'validate_info_log.txt';
      $baseImagePath = download_image($MediaUrl);
      if (!$baseImagePath) {
        file_put_contents($log_file, "no hay image path\n", FILE_APPEND);
        return new WP_Error('download_failed', 'No se pudo descargar la imagen.');
      }
      // Obtener la fecha y hora actuales
      $fecha = date('Y-m-d H:i:s');

      // Reemplazar los espacios y los dos puntos por guiones
      $fecha_formateada = str_replace([' ', ':'], '-', $fecha);
      $outputImagePath = wp_upload_dir()['path'] . '/resultimage' . $fecha_formateada . '.jpg';

      image_overlay($baseImagePath, $outputImagePath);
      file_put_contents($log_file, "Superposición de imágenes completada. Imagen resultante guardada en: $outputImagePath", FILE_APPEND);

      $outputImageURL = convert_path_to_url($outputImagePath);
      $dontHaveError = send_twilio_message($to, $from, '🖼️ Tu imagen #FakeGenerator' . $messageReceived . '. Envía *salir* para volver al menu principal', $client, $outputImageURL);
    }
  } else if (hasUserStartedCourse($from)) {
    if (isExitCourseInstruction($messageReceived)) {
      logout_course($from);
      $dontHaveError = send_twilio_message($to, $from, 'Usted ha salido del curso 📚. ¡Hasta pronto!', $client);
      $dontHaveError = send_twilio_menu_message($to, $from, $client);
    } else {
      handle_test_course_progression($messageReceived, $from, $to, $client);
    }
  } else if (hasUserStartedInfomemes($from)) {
    if (isExitCourseInstruction($messageReceived)) {
      logout_infomemes($from);
      $dontHaveError = send_twilio_message($to, $from, 'Usted ha salido del *curso de infomemes* 📚. ¡Hasta pronto!', $client);
      $dontHaveError = send_twilio_menu_message($to, $from, $client);
    } else {
      handle_test_infomemes_progression($messageReceived, $from, $to, $client, $MediaUrl);
    }
  } else if (hasUserStartedPowerReport($from)) {
    // La salida del flujo se maneja dentro, porque aquí '0' y 'no' son respuestas
    // válidas y no deben interpretarse como isExitCourseInstruction().
    $dontHaveError = handle_power_report_flow($messageReceived, $from, $to, $client, $params);
  }

  if ($dontHaveError) {
    return new WP_REST_Response('Mensaje procesado y respondido', 200);
  }
}

/**
 * Mensajes de quien no está dentro de ningún flujo: el enrutador del menú.
 *
 * Vive en su propia función porque también se usa DESDE dentro del flujo de
 * corresponsales, cuando la persona toca una opción del menú en lugar de
 * escribir "salir": así su elección se atiende de verdad, en vez de acabar
 * reenviada al equipo como si fuera un reporte.
 */
function handle_main_menu_message($messageReceived, $from, $to, $client, $params, $from_admin)
{
  if (isMenuInstruction($messageReceived)) {
    return send_twilio_menu_message($to, $from, $client);
  }

  // El saludo suelto sobraba: el propio menú ya abre con "¡Hola! bienvenido
  // a DatAvenger", así que se responde con un solo mensaje.
  if (isHelloInstruction($messageReceived)) {
    return send_twilio_menu_message($to, $from, $client);
  }

  if (isQuieroElegirInstruction($messageReceived)) {
    send_twilio_message($to, $from, get_message('quiero_elegir_1.txt'), $client);
    datav_safe_sleep(datav_message_delay());

    return send_twilio_message($to, $from, get_message('quiero_elegir_2.txt'), $client);
  }

  if (isEleccionesInstruction($messageReceived)) {
    $sent = send_twilio_message($to, $from, obtener_notas_presidenciales(), $client);

    // El enlace del canal ya no va suelto tras cada listado: vive dentro del
    // mensaje de redes sociales, que respeta el tope de frecuencia.
    datav_followup_send('news_viewed', $from, $to, $client);

    return $sent;
  }

  if (isFakenewsGeneratorInstruction($messageReceived) && in_array($from, $from_admin)) {
    update_status_fake($from, 'started');

    return send_twilio_message($to, $from, 'Envia una imagen para combatir la desinformación', $client);
  }

  if (isInteractiveCourseInstruction($messageReceived)) {
    update_course_status($from, 'started');

    return send_twilio_message($to, $from, get_message('start_course.txt'), $client);
  }

  if (isMemegeneratorInstruction($messageReceived)) {
    return send_twilio_message($to, $from, '🎨 *Memegenerator*' . "\n" . '¡Crea tu propio meme con nosotros! Entra al siguiente link para crear tu meme:' . "\n\n" . 'https://larepublicaweb.com/meme-generator-infomemes/', $client);
  }

  if (isInteractiveInfomemesInstruction($messageReceived)) {
    update_course_infomemes($from, 'started');

    send_twilio_message($to, $from, get_message('infomemes/introduccion/intro-amena.txt'), $client);
    datav_safe_sleep(datav_message_delay());

    return send_twilio_message($to, $from, get_message('infomemes/introduccion/intro.txt'), $client);
  }

  if (isValidateInformationInstruction($messageReceived)) {
    update_validation_status($from, 'started');
    datav_validate_touch($from);

    $sent = send_twilio_message($to, $from, get_message('start_validate_instruction.txt'), $client);
    datav_followup_send('validate_started', $from, $to, $client);

    return $sent;
  }

  if (isPowerReportInstruction($messageReceived)) {
    return datav_power_start_flow($from, $to, $client, $params);
  }

  return send_twilio_menu_message($to, $from, $client);
}

add_action('save_post', 'check_and_save_presidenciales_post', 10, 3);

function check_and_save_presidenciales_post($post_id, $post, $update)
{
  // Verifica si es una revisión para evitar el bucle infinito
  if (wp_is_post_revision($post_id)) {
    return;
  }

  // Verifica si la etiqueta 'presidenciales-2024' está asignada al post
  $tags = wp_get_post_tags($post_id);
  foreach ($tags as $tag) {
    if ($tag->slug === 'presidenciales-2024') {
      save_presidenciales_posts_to_txt();
      break;
    }
  }
}

function save_presidenciales_posts_to_txt()
{
  $args = array(
    'post_type' => 'post',
    'tag' => 'presidenciales-2024',
    'posts_per_page' => 10
  );

  $query = new WP_Query($args);
  $notas_string = "#VzlaTieneVoz | Últimas noticias sobre Elecciones presidenciales 🇻🇪 \n\n";

  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();
      $titulo = get_the_title();
      $url = get_permalink();
      $notas_string .= "📰| $titulo\n $url\n\n";
    }
    wp_reset_postdata();
  } else {
    $notas_string = 'No se encontraron notas';
  }

  // Guardar en un archivo txt
  $file = plugin_dir_path(__FILE__) . '/src/messages/presidenciales_noticias.txt';
  file_put_contents($file, $notas_string);
}
