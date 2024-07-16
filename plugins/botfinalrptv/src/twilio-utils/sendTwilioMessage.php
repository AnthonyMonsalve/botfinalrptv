<?php

function send_twilio_message($from, $to, $body, $client)
{
  $log_file = plugin_dir_path(__FILE__) . 'twilio_message_log.txt';

  try {
    $message = $client->messages->create(
      $to,
      [
        'from' => $from,
        'body' => $body
      ]
    );
    file_put_contents($log_file, 'Message sent: ' . $message->sid . "\n", FILE_APPEND);
    return true;
  } catch (Exception $e) {
    file_put_contents($log_file, 'Error sending message: ' . $e->getMessage() . "\n", FILE_APPEND);
    return new WP_REST_Response('Error sending message', 500);
  }
}
