<?php

function is_valid_option_test($message)
{
  // Convertir el mensaje a minúsculas
  $message = strtolower($message);

  // Lista de mensajes válidos
  $valid_messages = ['a', 'b', 'c'];

  // Verificar si el mensaje es válido
  return in_array($message, $valid_messages);
}
