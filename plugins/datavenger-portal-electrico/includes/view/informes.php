<?php

/** Listado público de informes. Mismo documento autónomo que el portal. */

if (!defined('ABSPATH')) {
    exit;
}

$informes = dvpe_get_informes(50);
$mascot = DVPE_URL . 'assets/datavenger.png';
$seo = dvpe_seo_data();

$titulo = 'Informes · ' . dvpe_setting('dvpe_title');
$descripcion = 'Informes y análisis de DatAvenger sobre el servicio eléctrico en Venezuela, '
    . 'elaborados con los reportes que la ciudadanía envía por WhatsApp.';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="index, follow, max-image-preview:large" />
    <title><?php echo esc_html($titulo); ?></title>
    <meta name="description" content="<?php echo esc_attr($descripcion); ?>" />
    <link rel="canonical" href="<?php echo esc_url(dvpe_informes_url()); ?>" />
    <meta name="theme-color" content="#0f1522" />

    <link rel="icon" type="image/png" href="<?php echo esc_url($seo['icon']); ?>" />
    <link rel="apple-touch-icon" href="<?php echo esc_url($seo['icon']); ?>" />

    <meta property="og:type" content="website" />
    <meta property="og:title" content="<?php echo esc_attr($titulo); ?>" />
    <meta property="og:description" content="<?php echo esc_attr($descripcion); ?>" />
    <meta property="og:url" content="<?php echo esc_url(dvpe_informes_url()); ?>" />
    <meta property="og:image" content="<?php echo esc_url(!empty($informes) && $informes[0]['imagen'] ? $informes[0]['imagen'] : $seo['image']); ?>" />
    <meta name="twitter:card" content="summary_large_image" />

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" />
    <link rel="stylesheet" href="<?php echo esc_url(DVPE_URL . 'assets/portal.css?v=' . DVPE_VERSION); ?>" />
    <link rel="stylesheet" href="<?php echo esc_url(DVPE_URL . 'assets/informes.css?v=' . DVPE_VERSION); ?>" />

    <style>:root { --dvpe-accent: <?php echo esc_html(dvpe_setting('dvpe_accent')); ?>; }</style>
</head>
<body class="dvpe-informes-page">

<div class="dvpe-docwrap">

    <header class="dvpe-dochead">
        <a class="dvpe-dochead__back" href="<?php echo esc_url(dvpe_portal_url()); ?>">
            ← Volver al mapa en vivo
        </a>

        <div class="dvpe-dochead__brand">
            <img src="<?php echo esc_url($mascot); ?>" alt="DatAvenger" />
            <div>
                <span class="dvpe-dochead__kicker">⚡ DatAvenger</span>
                <h1>Informes del servicio eléctrico</h1>
                <p><?php echo esc_html($descripcion); ?></p>
            </div>
        </div>
    </header>

    <?php if (empty($informes)) : ?>
        <div class="dvpe-card dvpe-doc-empty">
            <p>Todavía no hay informes publicados. Vuelve pronto.</p>
            <a class="dvpe-btn" href="<?php echo esc_url(dvpe_portal_url()); ?>">Ver el mapa en vivo</a>
        </div>
    <?php else : ?>
        <div class="dvpe-docs">
            <?php foreach ($informes as $indice => $informe) : ?>
                <article class="dvpe-doc <?php echo $indice === 0 ? 'dvpe-doc--destacado' : ''; ?>">
                    <a class="dvpe-doc__media" href="<?php echo esc_url($informe['enlace']); ?>"
                       target="_blank" rel="noopener"
                       aria-label="Abrir «<?php echo esc_attr($informe['titulo']); ?>»">
                        <?php if ($informe['imagen']) : ?>
                            <img src="<?php echo esc_url($informe['imagen']); ?>"
                                 alt="Portada de <?php echo esc_attr($informe['titulo']); ?>" loading="lazy" />
                        <?php else : ?>
                            <span class="dvpe-doc__sinimagen">📄</span>
                        <?php endif; ?>

                        <?php if ($indice === 0) : ?>
                            <span class="dvpe-doc__badge">Último informe</span>
                        <?php endif; ?>
                    </a>

                    <div class="dvpe-doc__body">
                        <time datetime="<?php echo esc_attr($informe['fecha_iso']); ?>"><?php echo esc_html($informe['fecha']); ?></time>
                        <h2><?php echo esc_html($informe['titulo']); ?></h2>
                        <p><?php echo esc_html($informe['resumen']); ?></p>

                        <a class="dvpe-btn dvpe-doc__cta" href="<?php echo esc_url($informe['enlace']); ?>"
                           target="_blank" rel="noopener">
                            <?php echo $informe['pdf'] ? 'Leer el informe (PDF)' : 'Leer el informe'; ?>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <footer class="dvpe-docfoot">
        <img src="<?php echo esc_url($mascot); ?>" alt="" />
        <p>
            <?php echo esc_html(dvpe_setting('dvpe_credit')); ?>
            Los reportes son enviados voluntariamente por la ciudadanía y no incluyen
            datos que identifiquen a quien reporta.
        </p>
        <a class="dvpe-btn dvpe-btn--ghost" href="<?php echo esc_url(dvpe_portal_url()); ?>">Ver el mapa en vivo</a>
    </footer>

</div>

</body>
</html>
