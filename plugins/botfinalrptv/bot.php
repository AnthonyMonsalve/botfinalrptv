<?php
/*
Plugin Name: Simple Twilio Responder
Plugin URI: https://latvcalle.com
Description: Recibe mensajes de Twilio y responde automáticamente.
Version: 1.0
Author: Tu Nombre
Author URI: https://latvcalle.com
*/

use Twilio\Rest\Client;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/instructions_validators/is_menu_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_hello_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_bulletin_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_social_media_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_interactive_course_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_exit_course_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_yes_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_valid_option_test.php';
require_once __DIR__ . '/src/instructions_validators/is_validate_information_instruction.php';
require_once __DIR__ . '/src/instructions_validators/is_elecciones_instruction.php';

require_once __DIR__ . '/src/get_messages/get_message.php';

require_once __DIR__ . '/src/twilio-utils/send_twilio_message.php';
require_once __DIR__ . '/src/get_noticias/get_presidenciales.php';

require_once __DIR__ . '/src/course_database/started_course.php';
require_once __DIR__ . '/src/course_database/get_test_status.php';
require_once __DIR__ . '/src/course_database/logout_course.php';
require_once __DIR__ . '/src/course_database/create_database.php';
require_once __DIR__ . '/src/course_database/handle_test_course_progression.php';

require_once __DIR__ . '/src/validate_database/get_validate_status.php';
require_once __DIR__ . '/src/validate_database/started_validation.php';
require_once __DIR__ . '/src/validate_database/update_status_validation.php';

require_once __DIR__ . '/src/dashboard_respuestas/dashboard_respuestas.php';

require_once __DIR__ . '/src/dashboard_config/dashboard_config.php';

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

  if (!hasUserStartedCourse($from) && !hasUserStartedValidation($from)) {
    if (isMenuInstruction($messageReceived)) {
      $messageReceived = get_message('menu.txt');
    } else if (isHelloInstruction($messageReceived)) {
      $messageReceived = get_message('hello.txt');
    } else if (isEleccionesInstruction($messageReceived)) {
      $messageReceived = obtener_notas_presidenciales();
    } else if (isBulletinInstruction($messageReceived)) {
      $messageReceived = get_message('bulletin.txt');
    } else if (isSocialMediaInstruction($messageReceived)) {
      $messageReceived = get_message('social_media.txt');
    } else if (isInteractiveCourseInstruction($messageReceived)) {
      update_course_status($from, 'started');
      $messageReceived = get_message('start_course.txt');
    } else if (isValidateInformationInstruction($messageReceived)) {
      update_validation_status($from, 'started');
      $messageReceived = get_message('start_validate_instruction.txt');;
    } else {
      $messageReceived = 'Hola, ' . $ProfileName . '. ¿En qué puedo ayudarte? Envia *"Republica"* para ver el menú principal.';
    }
    $dontHaveError = send_twilio_message($to, $from, $messageReceived, $client);
  } else if (hasUserStartedValidation($from)) {
    if (isExitCourseInstruction($messageReceived)) {
      update_validation_status($from, 'not_started');
      $dontHaveError = send_twilio_message($to, $from, get_message('gracias_validate.txt'), $client);
      $dontHaveError = send_twilio_message($to, $from, get_message('menu.txt'), $client);
    } else {
      foreach ($from_admin as $admin) {
        $dontHaveError = send_twilio_message($to, $admin, '‼ *Quiero corroborar la veracidad de esta información:* (' . $ProfileName . ' - ' . $from . ') ' . $messageReceived, $client, $MediaUrl);
      }
      if ($dontHaveError) {
        $dontHaveError = send_twilio_message($to, $from, get_message('recibido_validate.txt'), $client);
      }
    }
  } else if (hasUserStartedCourse($from)) {
    if (isExitCourseInstruction($messageReceived)) {
      logout_course($from);
      $dontHaveError = send_twilio_message($to, $from, 'Usted ha salido del curso 📚. ¡Hasta pronto!', $client);
      $dontHaveError = send_twilio_message($to, $from, get_message('menu.txt'), $client);
    } else {
      handle_test_course_progression($messageReceived, $from, $to, $client);
    }
  }

  if ($dontHaveError) {
    return new WP_REST_Response('Mensaje procesado y respondido', 200);
  }
}
