<?php

/** Documento completo del portal. No hereda nada del tema del sitio. */

if (!defined('ABSPATH')) {
    exit;
}

$endpoint = dvpe_client_endpoint();

$config = [
    'mode' => $endpoint['mode'],
    'base' => $endpoint['base'],
    'pollSeconds' => dvpe_poll_seconds(),
    'configured' => dvpe_is_configured(),
    'tiles' => dvpe_basemap_layers(),
    // Lo usa la imagen exportada, que se compone en el navegador.
    'brand' => [
        'title' => dvpe_setting('dvpe_title'),
        'credit' => dvpe_setting('dvpe_credit'),
        'accent' => dvpe_setting('dvpe_accent'),
        'logo' => DVPE_URL . 'assets/datavenger.png',
    ],
];

$mascot = DVPE_URL . 'assets/datavenger.png';

// Datos para buscadores y para la tarjeta que se ve al compartir el enlace.
$seo = dvpe_seo_data();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1" />
    <title><?php echo esc_html($seo['title']); ?></title>
    <meta name="description" content="<?php echo esc_attr($seo['description']); ?>" />
    <link rel="canonical" href="<?php echo esc_url($seo['url']); ?>" />
    <meta name="theme-color" content="#0f1522" />
    <meta name="author" content="<?php echo esc_attr($seo['site']); ?>" />

    <?php /* El icono del navegador y el de "añadir a inicio" en el móvil. */ ?>
    <link rel="icon" type="image/png" href="<?php echo esc_url($seo['icon']); ?>" />
    <link rel="apple-touch-icon" href="<?php echo esc_url($seo['icon']); ?>" />
    <link rel="shortcut icon" type="image/png" href="<?php echo esc_url($seo['icon']); ?>" />

    <?php /* Tarjeta al compartir en WhatsApp, X, Facebook, Telegram… */ ?>
    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="<?php echo esc_attr($seo['site']); ?>" />
    <meta property="og:locale" content="es_VE" />
    <meta property="og:title" content="<?php echo esc_attr($seo['title']); ?>" />
    <meta property="og:description" content="<?php echo esc_attr($seo['description']); ?>" />
    <meta property="og:url" content="<?php echo esc_url($seo['url']); ?>" />
    <meta property="og:image" content="<?php echo esc_url($seo['image']); ?>" />
    <meta property="og:image:alt" content="<?php echo esc_attr($seo['title']); ?>" />

    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?php echo esc_attr($seo['title']); ?>" />
    <meta name="twitter:description" content="<?php echo esc_attr($seo['description']); ?>" />
    <meta name="twitter:image" content="<?php echo esc_url($seo['image']); ?>" />

    <?php /* Datos estructurados: hace que el portal sea indexable como conjunto
             de datos abierto y puede aparecer en Google Dataset Search. */ ?>
    <script type="application/ld+json"><?php echo wp_json_encode($seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>

    <?php /* El portal pide los datos nada más cargar: adelantar el saludo al
             dominio del bot y al de las teselas ahorra unos cientos de ms. */ ?>
    <?php foreach ($seo['preconnect'] as $origin) : ?>
        <link rel="preconnect" href="<?php echo esc_url($origin); ?>" crossorigin />
    <?php endforeach; ?>

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="<?php echo esc_url(DVPE_URL . 'assets/portal.css?v=' . DVPE_VERSION); ?>" />
    <link rel="stylesheet" href="<?php echo esc_url(DVPE_URL . 'assets/informes.css?v=' . DVPE_VERSION); ?>" />

    <style>:root { --dvpe-accent: <?php echo esc_html(dvpe_setting('dvpe_accent')); ?>; }</style>
</head>
<body>

<div class="dvpe-app">

    <div class="dvpe-mapwrap">
        <div id="dvpe-map" class="dvpe-map" role="application" aria-label="Mapa de fallas eléctricas"></div>
        <div class="dvpe-legend" id="dvpe-legend-live">
            <span><span class="dvpe-legend__dot dvpe-legend__dot--gps"></span> Ubicación exacta</span>
            <span><span class="dvpe-legend__dot dvpe-legend__dot--approx"></span> Zona aproximada</span>
        </div>

        <div class="dvpe-legend dvpe-legend--heat" id="dvpe-legend-heat" hidden>
            <span class="dvpe-legend__scale" aria-hidden="true"></span>
            <span>menos</span>
            <span class="dvpe-legend__sep">·</span>
            <span>más</span>
        </div>
    </div>

    <header class="dvpe-header">
        <div class="dvpe-header__brand">
            <img class="dvpe-header__mascot" src="<?php echo esc_url($mascot); ?>" alt="DatAvenger" />
            <div>
                <h1 class="dvpe-title"><?php echo esc_html(dvpe_setting('dvpe_title')); ?></h1>
                <p class="dvpe-subtitle"><?php echo esc_html(dvpe_setting('dvpe_subtitle')); ?></p>
            </div>
        </div>
        <div class="dvpe-header__status">
            <?php if (dvpe_latest_informe()) : ?>
                <a class="dvpe-headlink" href="<?php echo esc_url(dvpe_informes_url()); ?>">
                    <span aria-hidden="true">📄</span> Informes
                </a>
            <?php endif; ?>
            <span class="dvpe-clock" id="dvpe-clock">--:--:-- VET</span>
            <span class="dvpe-live" id="dvpe-live-badge" data-state="loading">
                <span class="dvpe-live__dot" aria-hidden="true"></span>
                <span class="dvpe-live__text">Conectando…</span>
            </span>
        </div>
    </header>


    <?php /* Todo lo que gobierna la vista entera vive aquí, no dentro de una tarjeta. */ ?>
    <div class="dvpe-toolbar">
        <div class="dvpe-toolbar__left">
            <?php /* En móvil los seis presets no caben en una fila; ahí manda el
                     selector nativo, que además abre el picker del sistema. */ ?>
            <label class="dvpe-rangepick">
                <span class="dvpe-rangepick__label">Periodo</span>
                <select id="dvpe-range-select">
                    <option value="today">Hoy</option>
                    <option value="yesterday">Ayer</option>
                    <option value="24h">Últimas 24 h</option>
                    <option value="7d" selected>7 días</option>
                    <option value="30d">30 días</option>
                    <option value="month">Este mes</option>
                    <option value="custom">Personalizado…</option>
                </select>
            </label>

            <nav class="dvpe-seg dvpe-seg--range" aria-label="Periodo">
                <button type="button" class="dvpe-seg__btn" data-range="today">Hoy</button>
                <button type="button" class="dvpe-seg__btn" data-range="yesterday">Ayer</button>
                <button type="button" class="dvpe-seg__btn" data-range="24h">24 h</button>
                <button type="button" class="dvpe-seg__btn is-active" data-range="7d">7 días</button>
                <button type="button" class="dvpe-seg__btn" data-range="30d">30 días</button>
                <button type="button" class="dvpe-seg__btn" data-range="month">Este mes</button>
            </nav>

            <div class="dvpe-picker">
                <button type="button" class="dvpe-btn dvpe-btn--ghost" id="dvpe-custom-btn" data-range="custom"
                        aria-haspopup="true" aria-expanded="false">
                    Personalizado
                </button>
                <div class="dvpe-custom" id="dvpe-custom" hidden>
                    <label class="dvpe-custom__field">
                        <span>Desde</span>
                        <input type="datetime-local" id="dvpe-from" />
                    </label>
                    <label class="dvpe-custom__field">
                        <span>Hasta</span>
                        <input type="datetime-local" id="dvpe-to" />
                    </label>
                    <button type="button" class="dvpe-btn" id="dvpe-apply">Aplicar</button>
                </div>
            </div>

            <p class="dvpe-rangelabel" id="dvpe-rangelabel"></p>
        </div>

        <div class="dvpe-toolbar__right">
            <nav class="dvpe-seg dvpe-seg--map" aria-label="Vista del mapa">
                <button type="button" class="dvpe-seg__btn is-active" data-mapmode="live">En vivo</button>
                <button type="button" class="dvpe-seg__btn" data-mapmode="heat">Mapa de calor</button>
            </nav>

            <nav class="dvpe-seg dvpe-seg--weight" id="dvpe-weight" aria-label="Intensidad" hidden>
                <button type="button" class="dvpe-seg__btn is-active" data-weight="u">Tiempo con fallas</button>
                <button type="button" class="dvpe-seg__btn" data-weight="c">Nº de reportes</button>
            </nav>

            <div class="dvpe-export">
                <button type="button" class="dvpe-btn dvpe-btn--ghost" id="dvpe-export-btn" aria-haspopup="true" aria-expanded="false">
                    Descargar imagen
                </button>
                <div class="dvpe-export__menu" id="dvpe-export-menu" hidden>
                    <button type="button" data-export="social">
                        <strong>Pieza para redes</strong><span>1200 × 1200, con datos y marca</span>
                    </button>
                    <button type="button" data-export="clean">
                        <strong>Mapa limpio</strong><span>1600 × 900, solo el mapa</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="dvpe-rail dvpe-rail--left">

        <?php if (!$config['configured']) : ?>
            <div class="dvpe-alert">
                <strong>El portal todavía no está conectado.</strong>
                Un administrador debe indicar la dirección de la API de DatAvenger en
                <em>Ajustes &rsaquo; Portal Reporte Eléctrico</em>.
            </div>
        <?php endif; ?>

        <section class="dvpe-card dvpe-card--stats" aria-label="Resumen">
            <div class="dvpe-stats" id="dvpe-stats">
                <article class="dvpe-stat dvpe-stat--accent" id="dvpe-stat-active-box">
                    <span class="dvpe-stat__label" id="dvpe-stat-active-label">Fallas activas</span>
                    <strong class="dvpe-stat__value" id="dvpe-stat-active">—</strong>
                    <span class="dvpe-stat__hint" id="dvpe-stat-active-hint"></span>
                </article>
                <article class="dvpe-stat">
                    <span class="dvpe-stat__label">Reportes</span>
                    <strong class="dvpe-stat__value" id="dvpe-stat-reports">—</strong>
                </article>
                <article class="dvpe-stat" id="dvpe-stat-hours-box">
                    <span class="dvpe-stat__label">Tiempo con fallas</span>
                    <strong class="dvpe-stat__value" id="dvpe-stat-hours">—</strong>
                    <span class="dvpe-stat__hint" id="dvpe-stat-hours-hint"></span>
                </article>
                <article class="dvpe-stat">
                    <span class="dvpe-stat__label">Duración prom.</span>
                    <strong class="dvpe-stat__value dvpe-stat__value--small" id="dvpe-stat-avg">—</strong>
                    <svg id="dvpe-spark" viewBox="0 0 120 34" aria-label="Tendencia de reportes" hidden>
                        <polyline id="dvpe-spark-fill" points="" fill="rgba(43,125,233,.15)" stroke="none"></polyline>
                        <polyline id="dvpe-spark-line" points="" fill="none" stroke="var(--dvpe-accent)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>
                    </svg>
                </article>
            </div>
        </section>

        <section class="dvpe-card dvpe-card--chart">
            <h2 class="dvpe-card__title">
                Reportes por <span id="dvpe-chart-unit">día</span>
            </h2>
            <div class="dvpe-chart" id="dvpe-chart" role="group" aria-label="Reportes en el periodo"></div>
            <div class="dvpe-chart__axis" id="dvpe-chart-axis" aria-hidden="true"></div>
            <p class="dvpe-chart__foot" id="dvpe-chart-peak"></p>
        </section>

        <section class="dvpe-card dvpe-card--live">
            <h2 class="dvpe-card__title">Fallas en curso</h2>
            <ul class="dvpe-list" id="dvpe-live-list">
                <li class="dvpe-empty">Cargando…</li>
            </ul>
        </section>
    </div>

    <div class="dvpe-rail dvpe-rail--right">
        <section class="dvpe-card dvpe-card--rank">
            <h2 class="dvpe-card__title">Tiempo sin servicio</h2>
            <nav class="dvpe-seg" aria-label="Nivel">
                <button type="button" class="dvpe-seg__btn is-active" data-tab="estado">Estado</button>
                <button type="button" class="dvpe-seg__btn" data-tab="municipio">Municipio</button>
                <button type="button" class="dvpe-seg__btn" data-tab="parroquia">Parroquia</button>
            </nav>
            <ol class="dvpe-rank" id="dvpe-rank"></ol>
            <p class="dvpe-note">
                <?php echo esc_html(dvpe_setting('dvpe_credit')); ?>
                Los reportes son enviados voluntariamente por la ciudadanía y no incluyen
                datos que identifiquen a quien reporta.
                <span id="dvpe-updated"></span>
            </p>
        </section>
        <a class="dvpe-cta" href="https://wa.me/19152094653?text=Hola%20DatAvenger%2C%20quiero%20reportar%20una%20falla%20el%C3%A9ctrica" target="_blank" rel="noopener noreferrer">
            <img class="dvpe-cta__mascot" src="<?php echo esc_url($mascot); ?>" alt="" />
            <span class="dvpe-cta__bubble">
                <span class="dvpe-cta__tail"></span>
                <span class="dvpe-cta__wa">
                    <svg viewBox="0 0 448 512" aria-hidden="true"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>
                </span>
                <span class="dvpe-cta__text">
                    <strong>¿Sin luz en tu zona?</strong>
                    <span>Repórtalo por WhatsApp con DatAvenger</span>
                </span>
            </span>
        </a>
    </div>

    <div class="dvpe-ticker" id="dvpe-ticker" hidden>
        <span class="dvpe-ticker__label">
            <img src="<?php echo esc_url($mascot); ?>" alt="" />
            Últimos reportes
        </span>
        <div class="dvpe-ticker__viewport">
            <div class="dvpe-ticker__row" id="dvpe-ticker-row"></div>
        </div>
    </div>

    <div class="dvpe-detail" id="dvpe-detail" hidden>
        <div class="dvpe-detail__panel" role="dialog" aria-modal="true" aria-labelledby="dvpe-detail-title">
            <button type="button" class="dvpe-detail__close" id="dvpe-detail-close" aria-label="Cerrar">&times;</button>
            <div class="dvpe-detail__head">
                <img src="<?php echo esc_url($mascot); ?>" alt="" />
                <div id="dvpe-detail-heading"></div>
            </div>
            <div id="dvpe-detail-body"></div>
        </div>
    </div>

</div>

<?php echo dvpe_nudge_markup(); ?>

<script>window.DVPE = <?php echo wp_json_encode($config); ?>;</script>
<script>window.DVPE_NUDGE = <?php echo wp_json_encode(['delay' => (int) dvpe_setting('dvpe_nudge_delay')]); ?>;</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo esc_url(DVPE_URL . 'assets/heatmap.js?v=' . DVPE_VERSION); ?>"></script>
<script src="<?php echo esc_url(DVPE_URL . 'assets/card.js?v=' . DVPE_VERSION); ?>"></script>
<script src="<?php echo esc_url(DVPE_URL . 'assets/portal.js?v=' . DVPE_VERSION); ?>"></script>
<script src="<?php echo esc_url(DVPE_URL . 'assets/nudge.js?v=' . DVPE_VERSION); ?>" defer></script>

</body>
</html>
