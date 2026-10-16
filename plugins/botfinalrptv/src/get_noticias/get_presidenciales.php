<?php 
function obtener_notas_presidenciales()
{
	$notas_string = "*#VzlaTieneVoz* | Recibe las noticias más destacadas del acontecer nacional 🇻🇪 \n\n";
	// Argumentos para la consulta de publicaciones
    $args = array(
        'post_type' => 'post',
        //'tag' => 'presidenciales-2024',
        'posts_per_page' => 5 // Obtener todas las publicaciones
    );

    // Consulta de publicaciones
    $query = new WP_Query($args);

    // Construir el string con el título, URL y la diferencia de tiempo de cada publicación
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $titulo = get_the_title();
            $url = get_permalink();
            $fecha = get_the_time('U');
            $hace_cuanto = '_Hace '.human_time_diff($fecha, current_time('timestamp')).'_';
            $notas_string .= "📰| *$titulo*\n $url\n$hace_cuanto\n\n";
        }
        wp_reset_postdata();
    } else {
        $notas_string = 'No se encontraron notas';
    }

    return $notas_string;
}
