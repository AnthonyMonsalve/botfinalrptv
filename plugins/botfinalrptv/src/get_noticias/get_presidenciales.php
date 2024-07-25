<?php 

function obtener_notas_presidenciales()
{
    $notas_string = "#VzlaTieneVoz | Últimas noticias sobre Elecciones presidenciales 🇻🇪 \n\n";
    // Argumentos para la consulta de publicaciones
    $args = array(
        'post_type' => 'post',
        'tag' => 'presidenciales-2024',
        'posts_per_page' => 10 // Obtener todas las publicaciones
    );

    // Consulta de publicaciones
    $query = new WP_Query($args);

    // Construir el string con el título y la URL de cada publicación
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

    return $notas_string;
}
