<?php
// Función para agregar el menú de administración
function certificate_generator_menu()
{
  add_menu_page(
    'Certificados Generados', // Título de la página
    'Certificados Infomemes', // Texto del menú
    'manage_options', // Capacidad
    'certificate-generator', // Slug de la página
    'certificate_generator_page', // Función que muestra el contenido de la página
    'dashicons-welcome-write-blog', // Icono del menú
    150 // Posición en el menú
  );
}
add_action('admin_menu', 'certificate_generator_menu');

function certificate_generator_page()
{
  echo '<div class="wrap">';
  echo '<h1>Certificados Generados</h1>';
  echo '<table class="wp-list-table widefat fixed striped">';
  echo '<thead><tr><th>Nombre del Certificado</th><th>Enlace</th></tr></thead>';
  echo '<tbody>';

  // Obtener la ruta del directorio de uploads
  $upload_dir = wp_upload_dir();
  $certificates_dir = $upload_dir['basedir'] . '/certificates/';

  // Verificar que el directorio de certificados exista
  if (!is_dir($certificates_dir)) {
    echo '<tr><td colspan="2">No se han generado certificados aún.</td></tr>';
  } else {
    // Obtener todos los archivos de certificados en el directorio
    $certificates = glob($certificates_dir . 'certificate-*.jpg');

    // Listar todos los certificados generados
    if ($certificates) {
      foreach ($certificates as $certificate) {
        $certificate_name = basename($certificate);
        $certificate_url = $upload_dir['baseurl'] . '/certificates/' . $certificate_name;
        echo '<tr>';
        echo '<td>' . esc_html($certificate_name) . '</td>';
        echo '<td><a href="' . esc_url($certificate_url) . '" target="_blank">Ver Certificado</a></td>';
        echo '</tr>';
      }
    } else {
      echo '<tr><td colspan="2">No se han generado certificados aún.</td></tr>';
    }
  }

  echo '</tbody>';
  echo '</table>';
  echo '</div>';
}
