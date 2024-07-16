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
require_once __DIR__ . '/src/instructions_validators/isMenuInstruction.php';
require_once __DIR__ . '/src/instructions_validators/isHelloInstruction.php';
require_once __DIR__ . '/src/instructions_validators/isBulletinInstruction.php';
require_once __DIR__ . '/src/instructions_validators/isSocialMediaInstruction.php';
require_once __DIR__ . '/src/instructions_validators/isInteractiveCourseInstruction.php';
require_once __DIR__ . '/src/instructions_validators/isExitCourseInstruction.php';
require_once __DIR__ . '/src/instructions_validators/isYesInstruction.php';
require_once __DIR__ . '/src/instructions_validators/isValidOptionTest.php';

require_once __DIR__ . '/src/get_messages/get_message.php';

require_once __DIR__ . '/src/course_database/startedCourse.php';
require_once __DIR__ . '/src/course_database/getTestStatus.php';
require_once __DIR__ . '/src/course_database/logoutCourse.php';
require_once __DIR__ . '/src/course_database/createDatabase.php';

require_once __DIR__ . '/src/twilio-utils/sendTwilioMessage.php';


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


function handle_test_course_progression($messageReceived, $from, $to, $client)
{
  $tests = [
    'test1' => ['response' => 'test2.txt', 'correct' => '', 'incorrect' => ''],
    'test2' => ['response' => 'test3.txt', 'correct' => '', 'incorrect' => ''],
    'test3' => [
      'response' => 'test4.txt',
      'correct' => '✅ *¡Correcto!* Toda información falsa presentada como verdadera es *desinformación.*',
      'incorrect' => '❌ *Incorrecto.* La opción *B* corresponde a _información errónea_, y la opción *C* se trata de _información maliciosa_',
      'correct_option' => 'a'
    ],
    'test4' => [
      'response' => 'test5.txt',
      'correct' => '✅ *¡Correcto!* Recuerda que es importante verificar la *fuente* para asegurarnos de que _la información es fiable._',
      'incorrect' => '❌ *Incorrecto.* Recuerda que buscar la noticia en varias fuentes confiables puede ayudarte a asegurar que una información es veraz.',
      'correct_option' => 'b'
    ],
    'test5' => [
      'response' => 'test6.txt',
      'correct' => '✅ *¡Correcto!* Los memes son *formatos creativos para presentar hechos*, pero no olvides que es fundamental investigar por tu cuenta para contrastar las afirmaciones.',
      'incorrect' => '❌ *Incorrecto.* Algunos memes _pueden ayudar a informar_. Ya sabes lo que dicen: la gente *aprende mientras se ríe* :).',
      'correct_option' => 'c'
    ],
    'test6' => [
      'response' => 'test7.txt',
      'correct' => '✅ *¡Correcto!* Todos debemos verificar las informaciones que nos llegan, independientemente del tema o nuestra edad.',
      'incorrect' => '❌ *Incorrecto.* La respuesta correcta es A: los titulares o contenidos sensacionalistas y capaces de llamar nuestra atención pueden ser una distorsión de la realidad. ¡Estemos *atentos*! 🔋',
      'correct_option' => 'a'
    ],
    'test7' => [
      'response' => 'certificado.txt',
      'correct' => '✅ *¡Correcto!* Puedes verificar la autenticidad de una imagen o video en Google Imágenes y TinEye *(al final te compartiremos el link)*',
      'incorrect' => '❌ *Incorrecto.* Te recomendamos usar Google Imágenes y TinyEye para verificar imágenes y videos.',
      'correct_option' => 'b',
      'final_message' => 'testFinal.txt'
    ]
  ];

  foreach ($tests as $test => $data) {
    if (!get_test_status($from, $test)) {
      if ($test == 'test1' || $test == 'test2' || is_valid_option_test($messageReceived)) {
        update_test_status($from, $test, 1);

        if (isset($data['correct_option']) && strtolower($messageReceived) == $data['correct_option']) {
          $dontHaveError = send_twilio_message($to, $from, $data['correct'], $client);
        } elseif (isset($data['correct_option'])) {
          $dontHaveError = send_twilio_message($to, $from, $data['incorrect'], $client);
        }

        $dontHaveError = send_twilio_message($to, $from, get_message($data['response']), $client);

        if (isset($data['final_message'])) {
          $dontHaveError = send_twilio_message($to, $from, get_message($data['final_message']), $client);
          logout_course($from);
        }
        return;
      }
    }
  }

  $dontHaveError = send_twilio_message($to, $from, 'Selecciona una opción válida. Si deseas salir del curso, escribe *"Salir"*.', $client);

  return $dontHaveError;
}

function handle_twilio_message($request)
{
  $params = $request->get_body_params(); // Obtiene los parámetros decodificados automáticamente

  $body = isset($params['Body']) ? $params['Body'] : '';
  $from = isset($params['From']) ? $params['From'] : '';
  $to = isset($params['To']) ? $params['To'] : '';

  $messageReceived = $body;

  error_log('Sending message to: ' . $to);

  $sid = 'ACa7bed47ec54f13a3325b2088b4c00f47';
  $token = 'abb1f0fd49af3bffd6dc4d7951c98ddd';
  $client = new Client($sid, $token);

  $dontHaveError = true;

  if (!hasUserStartedCourse($from)) {
    if (isMenuInstruction($messageReceived)) {
      $messageReceived = get_message('menu.txt');
    } else if (isHelloInstruction($messageReceived)) {
      $messageReceived = get_message('hello.txt');
    } else if (isBulletinInstruction($messageReceived)) {
      $messageReceived = get_message('bulletin.txt');
    } else if (isSocialMediaInstruction($messageReceived)) {
      $messageReceived = get_message('social_media.txt');
    } else if (isInteractiveCourseInstruction($messageReceived)) {
      update_course_status($from, 'started');
      $messageReceived = get_message('start_course.txt');
    } else {
      $messageReceived = 'Hola, ¿en qué puedo ayudarte? Envia *"Republica"* para ver el menú principal.';
    }
    $dontHaveError = send_twilio_message($to, $from, $messageReceived, $client);
  } else {
    if (isExitCourseInstruction($messageReceived)) {
      logout_course($from);
      $dontHaveError = send_twilio_message($to, $from, 'Usted ha salido del curso. ¡Hasta pronto!', $client);
    } else {
      handle_test_course_progression($messageReceived, $from, $to, $client);
    }
  }

  if ($dontHaveError) {
    return new WP_REST_Response('Mensaje procesado y respondido', 200);
  }
}
