<?php

function get_message($filename)
{
  // Ruta base donde se encuentran los archivos de texto
  $basePath = plugin_dir_path(__FILE__) . '../messages/';

  // Ruta completa al archivo
  $filePath = $basePath . $filename;

  // Verificar si el archivo existe
  if (!file_exists($filePath)) {
    throw new Exception("El archivo $filePath no existe.");
  }

  // Leer el contenido del archivo
  $content = file_get_contents($filePath);

  // Verificar si la lectura fue exitosa
  if ($content === false) {
    throw new Exception("No se pudo leer el archivo $filePath.");
  }

  return $content;
}
