<?php

function obtener_notas_presidenciales()
{
    $notas_string = "#VzlaTieneVoz | Últimas noticias sobre Elecciones presidenciales 🇻🇪 \n\n";
    // Argumentos para la consulta de publicaciones
    $args = array(
        'post_type' => 'post',
        'tag' => 'presidenciales-2024',
        'posts_per_page' => 10 // Obtener 10 publicaciones
    );

    // Consulta de publicaciones
    $query = new WP_Query($args);

    // Verificar si hay publicaciones
    if (!$query->have_posts()) {
        return "No se encontraron notas sobre las elecciones presidenciales.";
    }

    // Construir el string con el título y la URL de cada publicación
    while ($query->have_posts()) {
        $query->the_post();
        $titulo = esc_html(get_the_title());
        $url = esc_url(get_permalink());
        $notas_string .= "📰| $titulo\n $url\n\n";
    }
    wp_reset_postdata();

    return $notas_string;
}
