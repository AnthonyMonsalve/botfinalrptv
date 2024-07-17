<?php

function send_twilio_message($from, $to, $body, $client, $MediaUrl0 = null)
{
  $log_file = plugin_dir_path(__FILE__) . 'twilio_message_log.txt';

  try {
    $messageData = [
      'from' => $from,
      'body' => $body
    ];

    // Agregar las URLs de las imágenes y el número total de medios
    if (($MediaUrl0 != null)) {
      $messageData['mediaUrl'] = $MediaUrl0;
    }

    file_put_contents($log_file, 'media sent: ' . $MediaUrl0 . "\n", FILE_APPEND);

    $message = $client->messages->create($to, $messageData);

    file_put_contents($log_file, 'Message sent: ' . $message->sid . "\n", FILE_APPEND);
    return true;
  } catch (Exception $e) {
    file_put_contents($log_file, 'Error sending message: ' . $e->getMessage() . "\n", FILE_APPEND);
    return new WP_REST_Response('Error sending message', 500);
  }
}
