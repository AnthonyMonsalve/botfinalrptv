/**
 * Portal Reporte Eléctrico — cliente en tiempo real.
 *
 * Consulta la API de DatAvenger cada N segundos. No guarda nada: cada ciclo
 * repinta con lo que devuelve la API, que es la única fuente de verdad.
 */
(function () {
    'use strict';

    var cfg = window.DVPE || {};
    var PRESETS = ['today', 'yesterday', '24h', '7d', '30d', 'month'];

    // Los datos del periodo (focos e histograma) cambian despacio y pesan mucho
    // más que el pulso en vivo, así que se refrescan como mucho cada 2 minutos.
    var PERIOD_TTL = 120000;

    var state = {
        range: '7d',
        from: '',
        to: '',
        rangeLabel: '',
        tab: 'estado',
        // 'u' = tiempo real con fallas (lo mismo que ordena el ranking), 'c' = reportes.
        weight: 'u',
        mapMode: 'live',
        detailId: null,
        reports: [],
        stats: null,
        points: [],
        pointsMax: { c: 0, s: 0, u: 0 },
        timeline: null,
        periodAt: 0,
        map: null,
        markers: null,
        heatCanvas: null,
        heatRaf: null,
        failures: 0,
        timer: null,
        fittedOnce: false,
        counters: { active: 0, reports: 0, hours: 0 },
        raf: null
    };

    /* --- Acceso a la API ------------------------------------------------ */

    /**
     * En modo proxy todo va al mismo endpoint con ?path=…; en modo directo cada
     * ruta es una URL distinta. El resto del código no necesita saber cuál es.
     */
    function apiUrl(path, params) {
        var url;
        var query = params ? Object.assign({}, params) : {};

        // Rompe cachés intermedias y CDN, que es justo lo que este portal no quiere.
        query._ = Date.now();

        if (cfg.mode === 'proxy') {
            url = new URL(cfg.base, window.location.origin);
            query.path = path;
        } else {
            url = new URL(cfg.base.replace(/\/$/, '') + '/' + path);
        }

        Object.keys(query).forEach(function (key) {
            url.searchParams.set(key, query[key]);
        });

        return url.toString();
    }

    function fetchJson(path, params) {
        return fetch(apiUrl(path, params), {
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    /**
     * Como fetchJson pero sin romper el ciclo si falla.
     *
     * Los focos y el histograma son extras: si el sitio del bot todavía no tiene
     * la versión de la API que los sirve, el portal debe seguir funcionando con
     * el mapa en vivo en vez de quedarse en blanco.
     */
    function softFetch(path, params) {
        return fetchJson(path, params).catch(function (error) {
            if (window.console) {
                console.warn('Portal Reporte Eléctrico (' + path + '):', error.message);
            }

            return null;
        });
    }

    /* --- Utilidades ----------------------------------------------------- */

    function $(id) { return document.getElementById(id); }

    function text(node, value) {
        if (node) { node.textContent = value; }
    }

    function formatNumber(value) {
        return new Intl.NumberFormat('es-VE').format(value || 0);
    }

    /**
     * El servidor manda 'YYYY-MM-DD HH:MM:SS' en la zona horaria del sitio del
     * bot. Se reformatea sin convertir a la zona del visitante: una falla en
     * Maracaibo debe leerse en hora de Venezuela aunque la mires desde Madrid.
     */
    function formatDateTime(value) {
        if (!value) { return '—'; }
        var parts = value.split(/[- :]/);
        if (parts.length < 5) { return value; }
        return parts[2] + '/' + parts[1] + '/' + parts[0] + ' ' + parts[3] + ':' + parts[4];
    }

    function timeOnly(value) {
        if (!value) { return ''; }
        var parts = value.split(/[- :]/);
        return parts.length >= 5 ? parts[3] + ':' + parts[4] : '';
    }

    /**
     * Fecha y hora de Venezuela como 'YYYY-MM-DDTHH:MM'.
     *
     * El portal se lee desde cualquier parte del mundo, pero el rango que se
     * escribe siempre significa hora venezolana, que es la del sitio del bot.
     * El locale sueco se usa porque formatea justo en ese orden.
     */
    var vetFmt = new Intl.DateTimeFormat('sv-SE', {
        timeZone: 'America/Caracas',
        year: 'numeric', month: '2-digit', day: '2-digit',
        hour: '2-digit', minute: '2-digit', hour12: false
    });

    function vetLocal(offsetMs) {
        return vetFmt.format(new Date(Date.now() + (offsetMs || 0))).replace(' ', 'T');
    }

    /* --- Periodo consultado ---------------------------------------------- */

    /** Lo que se manda a la API: un preset con nombre o un from/to a medida. */
    function rangeParams() {
        if (state.range === 'custom' && (state.from || state.to)) {
            return { from: state.from, to: state.to };
        }

        return { range: state.range };
    }

    /**
     * Los focos se piden al mismo nivel que está leyendo el ranking, para que el
     * más caliente del mapa sea el primero de la tabla y no dos cosas distintas.
     */
    function pointParams() {
        var params = rangeParams();

        params.level = state.tab;

        return params;
    }

    /** Peso de un punto, cayendo a la suma si la API todavía no manda la unión. */
    function pointValue(point) {
        if (state.weight === 'c') { return point.c; }

        return point.u === undefined ? point.s : point.u;
    }

    /** El rango vive también en la URL, para poder compartir una vista concreta. */
    function readUrl() {
        var params = new URLSearchParams(window.location.search);
        var from = params.get('from');
        var to = params.get('to');
        var range = params.get('range');

        if (from || to) {
            state.range = 'custom';
            state.from = from || '';
            state.to = to || '';
            return;
        }

        if (range && PRESETS.indexOf(range) >= 0) {
            state.range = range;
        }
    }

    function writeUrl() {
        if (!window.history || !window.history.replaceState) { return; }

        var params = new URLSearchParams(window.location.search);

        params.delete('from');
        params.delete('to');
        params.delete('range');

        if (state.range === 'custom') {
            if (state.from) { params.set('from', state.from); }
            if (state.to) { params.set('to', state.to); }
        } else if (state.range !== '7d') {
            params.set('range', state.range);
        }

        var query = params.toString();

        window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
    }

    function setStatus(state_, message) {
        var badge = $('dvpe-live-badge');
        if (!badge) { return; }
        badge.setAttribute('data-state', state_);
        var label = badge.querySelector('.dvpe-live__text');
        text(label, message);
    }

    /* --- Reloj (hora de Venezuela) ---------------------------------------- */

    var clockFmt = new Intl.DateTimeFormat('es-VE', {
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false, timeZone: 'America/Caracas'
    });

    function startClock() {
        var el = $('dvpe-clock');
        if (!el) { return; }
        window.setInterval(function () {
            el.textContent = clockFmt.format(new Date()) + ' VET';
        }, 1000);
    }

    /* --- Contadores animados ----------------------------------------------- */

    /**
     * Anima active/reports/hours desde su último valor mostrado al nuevo.
     * Un solo requestAnimationFrame para los tres: menos trabajo por frame.
     */
    function animateCounters(to) {
        var from = {
            active: state.counters.active,
            reports: state.counters.reports,
            hours: state.counters.hours
        };
        var t0 = performance.now();
        var dur = 800;

        if (state.raf) { cancelAnimationFrame(state.raf); }

        function step(t) {
            var p = Math.min(1, (t - t0) / dur);
            var e = 1 - Math.pow(1 - p, 3);

            state.counters.active = Math.round(from.active + (to.active - from.active) * e);
            state.counters.reports = Math.round(from.reports + (to.reports - from.reports) * e);
            state.counters.hours = Math.round(from.hours + (to.hours - from.hours) * e);

            text($('dvpe-stat-active'), formatNumber(state.counters.active));
            text($('dvpe-stat-reports'), formatNumber(state.counters.reports));
            text($('dvpe-stat-hours'), formatNumber(state.counters.hours) + ' h');

            if (p < 1) { state.raf = requestAnimationFrame(step); }
        }

        state.raf = requestAnimationFrame(step);
    }

    /* --- Sparkline ----------------------------------------------------------- */

    /**
     * Dibuja la tendencia si la API la ofrece (stats.daily o stats.trend como
     * lista de números o de objetos con .count). Si no existe, queda oculta.
     */
    function renderSpark(stats) {
        var svg = $('dvpe-spark');
        if (!svg) { return; }

        var raw = stats.daily || stats.trend;
        if (!raw || !raw.length) { svg.hidden = true; return; }

        var values = raw.map(function (v) {
            return typeof v === 'number' ? v : (v.count || v.reports || 0);
        });

        var max = Math.max.apply(null, values) || 1;
        var stepX = 120 / Math.max(values.length - 1, 1);
        var points = values.map(function (v, i) {
            return (i * stepX).toFixed(1) + ',' + (30 - (v / max) * 26).toFixed(1);
        }).join(' ');

        $('dvpe-spark-line').setAttribute('points', points);
        $('dvpe-spark-fill').setAttribute('points', '0,30 ' + points + ' 120,30');
        svg.hidden = false;
    }

    /* --- Mapa ------------------------------------------------------------ */

    function initMap() {
        if (state.map || typeof L === 'undefined') { return; }

        state.map = L.map('dvpe-map', { scrollWheelZoom: true })
            .setView([8.6, -66.5], 6); // Venezuela completa

        state.map.zoomControl.setPosition('bottomright');

        // Las capas del mapa base vienen de los ajustes: el proveedor por defecto
        // no pide clave, pero se puede cambiar sin tocar este archivo.
        var tiles = (cfg.tiles && cfg.tiles.length) ? cfg.tiles : [{
            url: 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}',
            attribution: 'Teselas &copy; Esri · &copy; OpenStreetMap',
            maxZoom: 16
        }];

        tiles.forEach(function (layer) {
            L.tileLayer(layer.url, {
                maxZoom: 18,
                // El proveedor puede no llegar a zoom 18: hasta ahí se amplía la
                // última tesela disponible en vez de dejar el fondo en blanco.
                maxNativeZoom: layer.maxZoom || 18,
                attribution: layer.attribution || ''
            }).addTo(state.map);
        });

        state.markers = L.layerGroup().addTo(state.map);

        initHeatLayer();
    }

    /* --- Capa de calor ---------------------------------------------------- */

    /**
     * Un canvas propio encima del mapa en vez de un plugin: el mismo código
     * pinta la capa que se ve y la imagen que se descarga, así lo exportado
     * coincide exactamente con lo que hay en pantalla.
     */
    function initHeatLayer() {
        var canvas = document.createElement('canvas');
        canvas.className = 'dvpe-heat';

        state.map.getContainer().appendChild(canvas);
        state.heatCanvas = canvas;

        state.map.on('move resize viewreset', scheduleHeat);

        // Durante la animación de zoom las coordenadas todavía son las de antes:
        // repintar ahí haría "saltar" los focos, así que se ocultan y vuelven.
        state.map.on('zoomstart', function () { canvas.style.opacity = '0'; });
        state.map.on('zoomend', function () {
            canvas.style.opacity = '1';
            renderHeat();
        });
    }

    function scheduleHeat() {
        if (state.heatRaf) { return; }

        state.heatRaf = window.requestAnimationFrame(function () {
            state.heatRaf = null;
            renderHeat();
        });
    }

    function renderHeat() {
        var canvas = state.heatCanvas;

        if (!canvas || !state.map) { return; }

        var size = state.map.getSize();

        if (canvas.width !== size.x || canvas.height !== size.y) {
            canvas.width = size.x;
            canvas.height = size.y;
        }

        var ctx = canvas.getContext('2d');

        if (state.mapMode !== 'heat' || !state.points.length) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            return;
        }

        var max = state.pointsMax[state.weight] || 0;
        var radius = DVPEHeat.radiusFor(state.map.getZoom());
        var pts = [];

        state.points.forEach(function (point) {
            var p = state.map.latLngToContainerPoint([point.lat, point.lng]);

            // Fuera del lienzo no aporta nada y estampar el disco cuesta.
            if (p.x < -radius || p.y < -radius || p.x > size.x + radius || p.y > size.y + radius) {
                return;
            }

            pts.push({ x: p.x, y: p.y, v: DVPEHeat.intensity(pointValue(point), max) });
        });

        DVPEHeat.draw(ctx, canvas.width, canvas.height, pts, radius);
    }

    /** Alterna entre los pines en vivo y el mapa de calor del periodo. */
    function setMapMode(mode) {
        if (mode === state.mapMode) { return; }

        state.mapMode = mode;

        document.querySelectorAll('[data-mapmode]').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-mapmode') === mode);
        });

        var weight = $('dvpe-weight');
        if (weight) { weight.hidden = mode !== 'heat'; }

        var liveLegend = $('dvpe-legend-live');
        var heatLegend = $('dvpe-legend-heat');
        if (liveLegend) { liveLegend.hidden = mode === 'heat'; }
        if (heatLegend) { heatLegend.hidden = mode !== 'heat'; }

        if (mode === 'heat') {
            if (state.markers) { state.markers.clearLayers(); }
            fitToPoints();
        } else {
            renderMap(state.reports);
        }

        renderHeat();
    }

    function setWeight(weight) {
        if (weight === state.weight) { return; }

        state.weight = weight;

        document.querySelectorAll('[data-weight]').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-weight') === weight);
        });

        renderHeat();
    }

    /** Encuadra el mapa sobre los focos del periodo. */
    function fitToPoints() {
        if (!state.map || !state.points.length) { return; }

        var bounds = DVPEHeat.boundsOf(state.points, 0.3);

        state.map.fitBounds(
            [[bounds.south, bounds.west], [bounds.north, bounds.east]],
            { padding: [30, 30], maxZoom: 9 }
        );
    }

    function renderMap(reports) {
        if (!state.map) { return; }

        state.markers.clearLayers();

        var bounds = [];

        reports.forEach(function (report) {
            if (report.lat === null || report.lng === null) { return; }

            var exact = report.geo_precision === 'gps';
            var cls = exact ? 'dvpe-pin dvpe-pin--gps' : 'dvpe-pin dvpe-pin--approx';

            var marker = L.marker([report.lat, report.lng], {
                icon: L.divIcon({
                    className: '',
                    html: '<span class="' + cls + '"><b></b><i></i></span>',
                    iconSize: [14, 14],
                    iconAnchor: [7, 7]
                })
            });

            marker.bindTooltip(report.zona + ' — ' + report.duration_label);
            marker.on('click', function () { openDetail(report.id); });

            state.markers.addLayer(marker);
            bounds.push([report.lat, report.lng]);
        });

        // Solo se encuadra la primera vez: reencuadrar en cada ciclo movería el
        // mapa bajo el cursor de alguien que está explorando una zona.
        if (!state.fittedOnce && bounds.length) {
            state.map.fitBounds(bounds, { padding: [40, 40], maxZoom: 9 });
            state.fittedOnce = true;
        }
    }

    /* --- Listas ---------------------------------------------------------- */

    function renderLiveList(reports) {
        var list = $('dvpe-live-list');
        if (!list) { return; }

        list.innerHTML = '';

        if (!reports.length) {
            var empty = document.createElement('li');
            empty.className = 'dvpe-empty';
            empty.textContent = 'No hay fallas activas reportadas en este momento.';
            list.appendChild(empty);
            return;
        }

        reports.forEach(function (report) {
            var item = document.createElement('li');
            item.className = 'dvpe-list__item';
            item.tabIndex = 0;

            var left = document.createElement('div');
            var zone = document.createElement('span');
            zone.className = 'dvpe-list__zone';
            zone.textContent = report.zona || 'Zona no especificada';
            left.appendChild(zone);

            var extras = [];
            if (report.direccion) { extras.push(report.direccion); }
            if (report.has_photo) { extras.push('con foto'); }
            if (extras.length) {
                var addr = document.createElement('span');
                addr.className = 'dvpe-list__addr';
                addr.textContent = extras.join(' · ');
                left.appendChild(addr);
            }

            var time = document.createElement('span');
            time.className = 'dvpe-list__time';
            time.textContent = report.duration_label;

            item.appendChild(left);
            item.appendChild(time);
            item.addEventListener('click', function () { openDetail(report.id); });
            item.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openDetail(report.id);
                }
            });

            list.appendChild(item);
        });
    }

    /* --- Ticker ------------------------------------------------------------ */

    /**
     * Fila duplicada: la animación desplaza el 50% del ancho y al terminar el
     * segundo juego queda exactamente donde empezó el primero, así el bucle es
     * invisible.
     */
    function renderTicker(reports) {
        var ticker = $('dvpe-ticker');
        var row = $('dvpe-ticker-row');
        if (!ticker || !row) { return; }

        if (!reports.length) { ticker.hidden = true; return; }

        row.innerHTML = '';

        var items = reports.slice(0, 10);
        [items, items].forEach(function (group) {
            group.forEach(function (report) {
                var span = document.createElement('span');
                span.className = 'dvpe-ticker__item';

                var when = timeOnly(report.outage_since);
                if (when) {
                    var t = document.createElement('time');
                    t.textContent = when + ' ';
                    span.appendChild(t);
                }

                var zone = document.createElement('strong');
                zone.textContent = report.zona || 'Zona no especificada';
                span.appendChild(zone);

                span.appendChild(document.createTextNode(' · '));

                var dur = document.createElement('em');
                dur.textContent = report.duration_label;
                span.appendChild(dur);

                row.appendChild(span);
            });
        });

        ticker.hidden = false;
    }

    /**
     * ¿El periodo consultado llega hasta ahora mismo?
     *
     * Se compara con las horas del servidor, no con el reloj del visitante: el
     * portal se mira desde otros husos y 'to' viene en hora de Venezuela.
     */
    function rangeIncludesNow(stats) {
        if (!stats || !stats.to || !stats.generated_at) { return true; }

        var fin = Date.parse(stats.to.replace(' ', 'T'));
        var ahora = Date.parse(stats.generated_at.replace(' ', 'T'));

        if (isNaN(fin) || isNaN(ahora)) { return true; }

        // Dos minutos de margen: 'to' se calcula un instante antes de responder.
        return fin >= ahora - 120000;
    }

    /**
     * La primera tarjeta cambia de significado con el periodo.
     *
     * "Fallas activas" es un dato del presente; junto a las cifras de un periodo
     * cerrado se lee como si pertenecieran al mismo intervalo, y no es así. En
     * esos periodos se muestra cuántas se restablecieron, que sí es del periodo.
     */
    function renderActiveTile(incluyeAhora, totals) {
        var box = $('dvpe-stat-active-box');

        text($('dvpe-stat-active-label'), incluyeAhora ? 'Fallas activas' : 'Fallas restablecidas');

        if (box) {
            box.classList.toggle('dvpe-stat--accent', incluyeAhora);
            box.title = incluyeAhora
                ? 'Fallas sin resolver en este momento, en todo el país.'
                : 'Casos de este periodo que ya fueron restablecidos. Las fallas activas no se muestran porque son del presente, no del periodo consultado.';
        }

        var hint = $('dvpe-stat-active-hint');

        text(hint, incluyeAhora ? 'ahora mismo' : 'de ' + formatNumber(totals.reports || 0) + ' reportes');
    }

    /**
     * Contexto del tiempo con fallas: cuánto duraba el periodo consultado y, en
     * el tooltip, la suma de reportes, que es otra cosa y conviene no mezclar.
     */
    function renderCoverageHint(stats) {
        var totals = stats.totals || {};
        var hint = $('dvpe-stat-hours-hint');
        var box = $('dvpe-stat-hours-box');

        if (totals.coverage_seconds === undefined) {
            // API antigua: se sigue mostrando la suma, sin prometer otra cosa.
            text(hint, '');
            if (box) { box.title = ''; }
            return;
        }

        var share = totals.coverage_ratio ? Math.round(totals.coverage_ratio * 100) : 0;

        text(hint, 'de ' + (stats.range_label || '—') + (share ? ' · ' + share + '%' : ''));

        if (box) {
            box.title = 'Tiempo real con al menos una falla activa. '
                + 'La suma de todos los reportes es ' + (totals.outage_label || '—') + '.';
        }
    }

    /* --- Histograma --------------------------------------------------------- */

    /**
     * Barras de reportes por hora o por día.
     *
     * Se cuenta por el momento en que empezó cada falla, así el pico marca
     * cuándo se fue la luz, no cuándo la gente contestó el seguimiento.
     */
    function renderChart() {
        var box = $('dvpe-chart');
        var data = state.timeline;

        if (!box) { return; }

        box.innerHTML = '';

        if (!data || !data.buckets || !data.buckets.length) {
            box.innerHTML = '<p class="dvpe-empty">Sin reportes en este periodo.</p>';
            text($('dvpe-chart-peak'), '');
            return;
        }

        text($('dvpe-chart-unit'), data.bucket === 'hour' ? 'hora' : 'día');

        var max = data.peak && data.peak.reports ? data.peak.reports : 1;

        data.buckets.forEach(function (bucket) {
            // Botones y no <span>: en el móvil no hay cursor que pasar por encima,
            // así que cada barra tiene que poder tocarse y recibir el foco.
            var bar = document.createElement('button');
            bar.type = 'button';
            bar.className = 'dvpe-chart__bar';
            bar.style.setProperty('--h', Math.max(2, (bucket.reports / max) * 100) + '%');

            if (data.peak && bucket.t === data.peak.t && bucket.reports > 0) {
                bar.classList.add('is-peak');
            }

            var etiqueta = bucketLabel(bucket, data.bucket);

            bar.title = etiqueta;
            bar.setAttribute('aria-label', etiqueta);

            bar.addEventListener('click', function (event) {
                event.stopPropagation();
                selectBucket(bar, etiqueta);
            });

            // Con teclado o lector de pantalla, recorrer las barras también cuenta.
            bar.addEventListener('focus', function () { selectBucket(bar, etiqueta); });

            box.appendChild(bar);
        });

        renderChartAxis(data);
        showChartPeak();
    }

    /** '01/10 20:00 · 4 reportes' / '28/08 · 12 reportes' */
    function bucketLabel(bucket, unit) {
        var cuando = unit === 'hour'
            ? formatDateTime(bucket.t)
            : formatDateTime(bucket.t).split(' ')[0];

        return cuando + ' · ' + formatNumber(bucket.reports) +
            (bucket.reports === 1 ? ' reporte' : ' reportes');
    }

    /** Extremos del eje, para situarse sin tener que tocar nada. */
    function renderChartAxis(data) {
        var axis = $('dvpe-chart-axis');

        if (!axis) { return; }

        var primero = data.buckets[0];
        var ultimo = data.buckets[data.buckets.length - 1];

        var corto = function (bucket) {
            var partes = formatDateTime(bucket.t).split(' ');

            return data.bucket === 'hour' ? partes[1] : partes[0].slice(0, 5);
        };

        axis.innerHTML = '';

        [corto(primero), corto(ultimo)].forEach(function (valor) {
            var span = document.createElement('span');
            span.textContent = valor;
            axis.appendChild(span);
        });
    }

    /** Marca la barra tocada y cuenta lo suyo bajo el gráfico. */
    function selectBucket(bar, etiqueta) {
        var box = $('dvpe-chart');

        if (box) {
            Array.prototype.forEach.call(box.children, function (otra) {
                otra.classList.toggle('is-selected', otra === bar);
            });
        }

        text($('dvpe-chart-peak'), etiqueta);
    }

    /** Vuelve al resumen del periodo: el pico. */
    function showChartPeak() {
        var data = state.timeline;
        var box = $('dvpe-chart');

        if (box) {
            Array.prototype.forEach.call(box.children, function (bar) {
                bar.classList.remove('is-selected');
            });
        }

        if (!data || !data.peak || !data.peak.reports) {
            text($('dvpe-chart-peak'), '');
            return;
        }

        text($('dvpe-chart-peak'), 'Pico: ' + bucketLabel(data.peak, data.bucket));
    }

    /**
     * Muestra u oculta lo que depende de los endpoints del periodo.
     * Con una API antigua el portal se queda en su versión anterior, sin errores.
     */
    function setPeriodFeatures(hasPoints, hasTimeline) {
        var chart = document.querySelector('.dvpe-card--chart');
        if (chart) { chart.hidden = !hasTimeline; }

        var mapModes = document.querySelector('.dvpe-seg--map');
        if (mapModes) { mapModes.hidden = !hasPoints; }

        var exportBox = document.querySelector('.dvpe-export');
        if (exportBox) { exportBox.hidden = !hasPoints; }

        if (!hasPoints && state.mapMode === 'heat') {
            setMapMode('live');
        }
    }

    /* --- Exportación de imagen ---------------------------------------------- */

    function loadLogo() {
        var brand = cfg.brand || {};

        if (!brand.logo) { return Promise.resolve(null); }

        return new Promise(function (resolve) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () { resolve(img); };
            img.onerror = function () { resolve(null); };
            img.src = brand.logo;
        });
    }

    /** Datos que acompañan al mapa dentro de la imagen. */
    function exportMeta() {
        var brand = cfg.brand || {};
        var totals = (state.stats && state.stats.totals) || {};
        var rows = ((state.stats && state.stats['by_' + state.tab]) || []).slice();

        // El top de la imagen se ordena por lo mismo que está midiendo el mapa.
        if (state.weight === 'c') {
            rows.sort(function (a, b) { return b.reports - a.reports; });
        }

        return {
            // El antetítulo dice qué se está midiendo; repetir aquí el título del
            // portal dejaba el mismo texto dos veces seguidas en la imagen.
            kicker: '⚡ Mapa de calor · ' + (state.weight === 'c' ? 'Nº de reportes' : 'Tiempo con fallas'),
            title: brand.title || 'Fallas del servicio eléctrico',
            accent: brand.accent || '#2b7de9',
            credit: brand.credit || '',
            rangeLabel: state.rangeLabel || '',
            // La unidad va en la etiqueta, no pegada al número.
            reports: formatNumber(totals.reports || 0),
            // Tiempo real con fallas, con el periodo al lado para que se entienda
            // de qué es ese número ("18 h de 24 h").
            hours: totals.coverage_label || totals.outage_label || '—',
            hoursHint: state.stats && state.stats.range_label ? 'de ' + state.stats.range_label : '',
            zones: formatNumber(rows.length),
            generated: vetLocal().replace('T', ' ') + ' VET',
            top: rows.slice(0, 6).map(function (row) {
                return {
                    name: row.name,
                    value: state.weight === 'c'
                        ? formatNumber(row.reports) + ' rep.'
                        : (row.coverage_label || row.outage_label)
                };
            })
        };
    }

    function downloadBlob(blob, name) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');

        link.href = url;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        // Se libera después del clic: revocarla antes cancela la descarga.
        window.setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }

    function runExport(format) {
        var button = $('dvpe-export-btn');
        var label = button ? button.textContent : '';

        if (button) {
            button.disabled = true;
            button.textContent = 'Generando…';
        }

        function finish() {
            if (button) {
                button.disabled = false;
                button.textContent = label;
            }
        }

        // Si todavía no hay focos cargados (por ejemplo, si se pulsa nada más
        // abrir el portal) se piden antes de componer la imagen.
        var ready = state.points.length
            ? Promise.resolve()
            : fetchJson('power/points', pointParams()).then(function (data) {
                state.points = data.points || [];
                state.pointsMax = data.max || { c: 0, s: 0, u: 0 };
            });

        ready.then(loadLogo).then(function (logo) {
            var meta = exportMeta();
            meta.logo = logo;

            return DVPEHeat.exportImage({
                format: format,
                points: state.points,
                weight: state.weight,
                max: state.pointsMax,
                tiles: cfg.tiles || [],
                meta: meta
            });
        }).then(function (blob) {
            if (!blob) { throw new Error('sin imagen'); }

            downloadBlob(blob, 'reporte-electrico-' + vetLocal().replace(/[:T-]/g, '') + '.png');
            finish();
        }).catch(function (error) {
            finish();
            window.alert('No se pudo generar la imagen. Inténtalo de nuevo.');

            if (window.console) {
                console.warn('Portal Reporte Eléctrico (exportación):', error.message);
            }
        });
    }

    function toggleExportMenu(force) {
        var menu = $('dvpe-export-menu');
        var button = $('dvpe-export-btn');

        if (!menu) { return; }

        var open = typeof force === 'boolean' ? force : menu.hidden;

        // En móvil los dos desplegables caen en el mismo sitio, bajo la barra:
        // abrir uno tiene que cerrar el otro.
        if (open) { toggleCustom(false); }

        menu.hidden = !open;

        if (button) { button.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    }

    /* --- Ranking ------------------------------------------------------------- */

    function renderRank() {
        var list = $('dvpe-rank');
        if (!list || !state.stats) { return; }

        var rows = state.stats['by_' + state.tab] || [];

        list.innerHTML = '';

        if (!rows.length) {
            var empty = document.createElement('li');
            empty.className = 'dvpe-empty';
            empty.textContent = 'Sin datos en este periodo.';
            list.appendChild(empty);
            return;
        }

        // Se mide con el tiempo real de la zona (intervalos unidos) y solo se cae
        // a la suma si la API todavía no lo manda.
        var metric = function (row) {
            return row.coverage_seconds === null || row.coverage_seconds === undefined
                ? row.outage_seconds
                : row.coverage_seconds;
        };

        var label = function (row) {
            return row.coverage_label || row.outage_label;
        };

        var max = metric(rows[0]) || 1;

        rows.slice(0, 10).forEach(function (row) {
            var item = document.createElement('li');
            item.className = 'dvpe-rank__item';

            var line = document.createElement('div');
            line.className = 'dvpe-rank__row';

            var name = document.createElement('span');
            name.className = 'dvpe-rank__name';
            name.textContent = row.name;

            var parentBits = [];
            if (row.parent) { parentBits.push(row.parent); }
            if (row.reports) { parentBits.push(formatNumber(row.reports) + ' reportes'); }
            if (parentBits.length) {
                var parent = document.createElement('span');
                parent.className = 'dvpe-rank__parent';
                parent.textContent = parentBits.join(' · ');
                name.appendChild(parent);
            }

            var value = document.createElement('span');
            value.className = 'dvpe-rank__value';
            value.textContent = label(row);

            if (row.coverage_label) {
                item.title = row.name + ': ' + row.coverage_label + ' con fallas · '
                    + formatNumber(row.reports) + ' reportes suman ' + row.outage_label;
            }

            var bar = document.createElement('span');
            bar.className = 'dvpe-rank__bar';
            bar.style.width = Math.max(3, (metric(row) / max) * 100) + '%';

            line.appendChild(name);
            line.appendChild(value);
            item.appendChild(line);
            item.appendChild(bar);
            list.appendChild(item);
        });
    }

    /* --- Detalle --------------------------------------------------------- */

    /**
     * Cómo se presenta cada estado. El panel leía 'Falla activa' escrito a fuego
     * y lo mostraba también en reportes ya cerrados, que es justo lo contrario
     * de lo que dice el dato.
     */
    function detailState(status) {
        if (status === 'restored') {
            return { kicker: 'Servicio restablecido', duracion: 'Duración total', cls: 'is-restored' };
        }

        if (status === 'undetermined') {
            return { kicker: 'Pendiente de confirmación', duracion: 'Duración registrada', cls: 'is-undetermined' };
        }

        // Una falla en curso no tiene duración cerrada: el contador sigue.
        return { kicker: 'Falla activa', duracion: 'Tiempo transcurrido', cls: 'is-active' };
    }

    function openDetail(id) {
        var modal = $('dvpe-detail');
        var body = $('dvpe-detail-body');
        if (!modal || !body) { return; }

        state.detailId = id;
        $('dvpe-detail-heading').innerHTML = '';
        body.innerHTML = '<p>Cargando…</p>';
        modal.hidden = false;

        loadDetail(id);
    }

    /** Vuelve a pedir el detalle abierto, para que no se quede congelado. */
    function refreshDetail() {
        var modal = $('dvpe-detail');

        if (state.detailId && modal && !modal.hidden) {
            loadDetail(state.detailId);
        }
    }

    function loadDetail(id) {
        var heading = $('dvpe-detail-heading');
        var body = $('dvpe-detail-body');

        fetchJson('power/report/' + id).then(function (report) {
            // Entre la petición y la respuesta pudo cerrarse o abrirse otro.
            if (state.detailId !== id) { return; }

            heading.innerHTML = '';
            body.innerHTML = '';

            var estado = detailState(report.status);

            var kicker = document.createElement('span');
            kicker.className = 'dvpe-detail__kicker ' + estado.cls;
            kicker.textContent = estado.kicker + ' · reporte #' + report.id;
            heading.appendChild(kicker);

            var title = document.createElement('h2');
            title.id = 'dvpe-detail-title';
            title.textContent = report.zona || 'Reporte #' + report.id;
            heading.appendChild(title);

            var dl = document.createElement('dl');

            var filas = [
                ['Sin servicio desde', formatDateTime(report.outage_since)]
            ];

            if (report.restored_at) {
                filas.push(['Servicio restablecido', formatDateTime(report.restored_at)]);
            }

            filas.push([estado.duracion, report.duration_label]);
            filas.push(['Dirección', report.direccion || '—']);
            filas.push(['Ubicación', report.geo_precision === 'gps' ? 'Exacta (GPS)' : 'Aproximada por zona']);
            filas.push(['Confirmaciones', String(report.checks_sent || 0)]);

            filas.forEach(function (pair) {
                var dt = document.createElement('dt');
                dt.textContent = pair[0];
                var dd = document.createElement('dd');
                dd.textContent = pair[1];
                dl.appendChild(dt);
                dl.appendChild(dd);
            });

            body.appendChild(dl);
            body.appendChild(buildShareBar(report));

            if (report.photo) {
                var img = document.createElement('img');
                img.className = 'dvpe-detail__photo';
                img.src = report.photo;
                img.alt = 'Foto enviada con el reporte de ' + (report.zona || '');
                img.loading = 'lazy';
                body.appendChild(img);
            }
        }).catch(function () {
            body.innerHTML = '<p>No se pudo cargar el detalle de este reporte.</p>';
        });
    }

    /* --- Compartir un reporte ---------------------------------------------- */

    /** Enlace que abre el portal con este reporte ya desplegado. */
    function reportUrl(id) {
        return window.location.origin + window.location.pathname + '?report=' + id;
    }

    function shareText(report) {
        var estado = DVPECard.estadoDe(report);

        return estado.texto + ' en ' + (report.zona || 'Venezuela') + ' · ' +
            report.duration_label + ' sin servicio. Reporte ciudadano vía DatAvenger.';
    }

    /**
     * La foto se pide a este mismo dominio aunque viva en el sitio del bot: una
     * imagen de otro origen "mancha" el canvas y la tarjeta no se podría bajar.
     */
    function loadReportPhoto(report) {
        if (!report.photo) { return Promise.resolve(null); }

        var url = cfg.mode === 'proxy'
            ? cfg.base.replace(/\/proxy$/, '/photo') + '?id=' + report.id
            : report.photo;

        return new Promise(function (resolve) {
            var img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function () { resolve(img); };
            // Sin foto utilizable se usa la maqueta alternativa, con el mapa grande.
            img.onerror = function () { resolve(null); };
            img.src = url;
        });
    }

    function buildCard(report) {
        return loadReportPhoto(report).then(function (foto) {
            return loadLogo().then(function (logo) {
                return DVPECard.build(report, {
                    tiles: cfg.tiles || [],
                    photo: foto,
                    meta: {
                        logo: logo,
                        credit: (cfg.brand && cfg.brand.credit) || '',
                        generated: vetLocal().replace('T', ' ') + ' VET',
                        accent: (cfg.brand && cfg.brand.accent) || '#2b7de9'
                    }
                });
            });
        });
    }

    /** Botones de la ficha: compartir y descargar la tarjeta. */
    function buildShareBar(report) {
        var barra = document.createElement('div');
        barra.className = 'dvpe-share';

        var compartir = document.createElement('button');
        compartir.type = 'button';
        compartir.className = 'dvpe-btn dvpe-share__main';
        compartir.textContent = 'Compartir';

        var descargar = document.createElement('button');
        descargar.type = 'button';
        descargar.className = 'dvpe-btn dvpe-btn--ghost';
        descargar.textContent = 'Descargar tarjeta';

        compartir.addEventListener('click', function () { shareReport(report, compartir); });
        descargar.addEventListener('click', function () { downloadCard(report, descargar); });

        barra.appendChild(compartir);
        barra.appendChild(descargar);
        barra.appendChild(buildNetworkLinks(report));

        return barra;
    }

    /**
     * Marcas de cada red, dibujadas en línea.
     *
     * Van como SVG y no como tipografía de iconos ni imágenes: pesan unos bytes,
     * se ven nítidas en cualquier pantalla y heredan el color del botón, así que
     * el estado de "copiado" se resuelve con CSS.
     */
    var ICONOS = {
        whatsapp: {
            viewBox: '0 0 448 512',
            path: 'M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z'
        },
        x: {
            viewBox: '0 0 512 512',
            path: 'M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L272.9 180.9 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z'
        },
        facebook: {
            viewBox: '0 0 320 512',
            path: 'M80 299.3V512h116V299.3h86.5l18-97.8H196v-34.6c0-51.7 20.3-71.5 72.7-71.5c16.3 0 29.4 .4 37 1.2V7.9C291.4 4 256.4 0 236.2 0C129.3 0 80 50.5 80 159.4v42.1H14v97.8H80z'
        },
        telegram: {
            viewBox: '0 0 496 512',
            path: 'M248 8C111 8 0 119 0 256S111 504 248 504 496 393 496 256 385 8 248 8zM363 176.7c-3.7 39.2-19.9 134.4-28.1 178.3-3.5 18.6-10.3 24.8-16.9 25.4-14.4 1.3-25.3-9.5-39.3-18.7-21.8-14.3-34.2-23.2-55.3-37.2-24.5-16.1-8.6-25 5.3-39.5 3.7-3.8 67.1-61.5 68.3-66.7 .2-.7 .3-3.1-1.2-4.4s-3.6-.8-5.1-.5q-3.3 .7-104.6 69.1-14.8 10.2-26.9 9.9c-8.9-.2-25.9-5-38.6-9.1-15.5-5-27.9-7.7-26.8-16.3q.8-6.7 18.5-13.7 108.4-47.2 144.6-62.3c68.9-28.6 83.2-33.6 92.5-33.8 2.1 0 6.6 .5 9.6 2.9a10.5 10.5 0 0 1 3.5 6.7A43.8 43.8 0 0 1 363 176.7z'
        },
        enlace: {
            viewBox: '0 0 640 512',
            path: 'M579.8 267.7c56.5-56.5 56.5-148 0-204.5c-50-50-128.8-56.5-186.3-15.4l-1.6 1.1c-14.4 10.3-17.7 30.3-7.4 44.6s30.3 17.7 44.6 7.4l1.6-1.1c32.1-22.9 76-19.3 103.8 8.6c31.5 31.5 31.5 82.5 0 114L422.3 334.8c-31.5 31.5-82.5 31.5-114 0c-27.9-27.9-31.5-71.8-8.6-103.8l1.1-1.6c10.3-14.4 6.9-34.4-7.4-44.6s-34.4-6.9-44.6 7.4l-1.1 1.6C206.5 251.2 213 330 263 380c56.5 56.5 148 56.5 204.5 0L579.8 267.7zM60.2 244.3c-56.5 56.5-56.5 148 0 204.5c50 50 128.8 56.5 186.3 15.4l1.6-1.1c14.4-10.3 17.7-30.3 7.4-44.6s-30.3-17.7-44.6-7.4l-1.6 1.1c-32.1 22.9-76 19.3-103.8-8.6C74 372 74 321 105.5 289.5L217.7 177.2c31.5-31.5 82.5-31.5 114 0c27.9 27.9 31.5 71.8 8.6 103.9l-1.1 1.6c-10.3 14.4-6.9 34.4 7.4 44.6s34.4 6.9 44.6-7.4l1.1-1.6C433.5 260.8 427 182 377 132c-56.5-56.5-148-56.5-204.5 0L60.2 244.3z'
        },
        hecho: {
            viewBox: '0 0 448 512',
            path: 'M438.6 105.4c12.5 12.5 12.5 32.8 0 45.3l-256 256c-12.5 12.5-32.8 12.5-45.3 0l-128-128c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0L160 338.7 393.4 105.4c12.5-12.5 32.8-12.5 45.3 0z'
        }
    };

    function iconSvg(nombre) {
        var icono = ICONOS[nombre];

        return '<svg viewBox="' + icono.viewBox + '" aria-hidden="true" focusable="false">' +
            '<path d="' + icono.path + '"/></svg>';
    }

    /** Botones de cada red, para el escritorio y como respaldo del móvil. */
    function buildNetworkLinks(report) {
        var caja = document.createElement('div');
        caja.className = 'dvpe-share__nets';

        var url = encodeURIComponent(reportUrl(report.id));
        var texto = encodeURIComponent(shareText(report));

        var redes = [
            ['whatsapp', 'WhatsApp', 'https://wa.me/?text=' + texto + '%20' + url],
            ['x', 'X', 'https://twitter.com/intent/tweet?text=' + texto + '&url=' + url],
            ['facebook', 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' + url],
            ['telegram', 'Telegram', 'https://t.me/share/url?url=' + url + '&text=' + texto]
        ];

        redes.forEach(function (red) {
            var a = document.createElement('a');

            a.className = 'dvpe-net dvpe-net--' + red[0];
            a.href = red[2];
            a.target = '_blank';
            a.rel = 'noopener';
            a.title = 'Compartir en ' + red[1];
            a.setAttribute('aria-label', 'Compartir en ' + red[1]);
            a.innerHTML = iconSvg(red[0]);

            caja.appendChild(a);
        });

        caja.appendChild(buildCopyButton(report));

        return caja;
    }

    function buildCopyButton(report) {
        var copiar = document.createElement('button');

        copiar.type = 'button';
        copiar.className = 'dvpe-net dvpe-net--enlace';
        copiar.title = 'Copiar enlace';
        copiar.setAttribute('aria-label', 'Copiar enlace del reporte');
        copiar.innerHTML = iconSvg('enlace');

        function confirmar() {
            copiar.classList.add('is-done');
            copiar.innerHTML = iconSvg('hecho');
            copiar.title = '¡Enlace copiado!';

            window.setTimeout(function () {
                copiar.classList.remove('is-done');
                copiar.innerHTML = iconSvg('enlace');
                copiar.title = 'Copiar enlace';
            }, 2000);
        }

        copiar.addEventListener('click', function () {
            var destino = reportUrl(report.id);

            if (navigator.clipboard) {
                navigator.clipboard.writeText(destino).then(confirmar).catch(function () {
                    window.prompt('Copia el enlace:', destino);
                });
            } else {
                window.prompt('Copia el enlace:', destino);
            }
        });

        return copiar;
    }

    function cardName(report) {
        return 'datavenger-reporte-' + report.id + '.png';
    }

    function withBusy(boton, trabajo) {
        var etiqueta = boton.textContent;

        boton.disabled = true;
        boton.textContent = 'Generando…';

        return trabajo().catch(function (error) {
            if (window.console) {
                console.warn('Portal Reporte Eléctrico (tarjeta):', error && error.message);
            }

            if (!error || error.name !== 'AbortError') {
                window.alert('No se pudo generar la tarjeta. Inténtalo de nuevo.');
            }
        }).then(function () {
            boton.disabled = false;
            boton.textContent = etiqueta;
        });
    }

    function downloadCard(report, boton) {
        return withBusy(boton, function () {
            return buildCard(report).then(function (blob) {
                if (!blob) { throw new Error('sin imagen'); }

                downloadBlob(blob, cardName(report));
            });
        });
    }

    /**
     * En el móvil se comparte la imagen directamente con el menú del sistema; en
     * el escritorio, que no lo soporta, se descarga y quedan los enlaces por red.
     */
    function shareReport(report, boton) {
        return withBusy(boton, function () {
            return buildCard(report).then(function (blob) {
                if (!blob) { throw new Error('sin imagen'); }

                var archivo = new File([blob], cardName(report), { type: 'image/png' });

                if (navigator.canShare && navigator.canShare({ files: [archivo] })) {
                    return navigator.share({
                        files: [archivo],
                        title: report.zona || 'Reporte eléctrico',
                        text: shareText(report)
                    });
                }

                downloadBlob(blob, cardName(report));
            });
        });
    }

    function closeDetail() {
        var modal = $('dvpe-detail');
        if (modal) { modal.hidden = true; }

        state.detailId = null;
    }

    /* --- Ciclo de actualización ------------------------------------------ */

    function refresh() {
        if (!cfg.configured) {
            setStatus('error', 'Sin configurar');
            return Promise.resolve();
        }

        // Los focos y el histograma solo se vuelven a pedir cuando toca: pesan
        // mucho más que el pulso en vivo y cambian bastante más despacio.
        var withPeriod = (Date.now() - state.periodAt) > PERIOD_TTL;

        var jobs = [
            fetchJson('power/live'),
            fetchJson('power/stats', rangeParams())
        ];

        if (withPeriod) {
            jobs.push(softFetch('power/points', pointParams()));
            jobs.push(softFetch('power/timeline', rangeParams()));
        }

        return Promise.all(jobs).then(function (results) {
            var live = results[0];
            var stats = results[1];

            state.reports = live.reports || [];
            state.stats = stats;
            state.rangeLabel = stats.label || '';
            state.failures = 0;

            if (withPeriod) {
                state.points = (results[2] && results[2].points) || [];
                state.pointsMax = (results[2] && results[2].max) || { c: 0, s: 0, u: 0 };
                state.timeline = results[3] || null;
                state.periodAt = Date.now();

                // Sin estos endpoints (API del bot desactualizada) se ocultan sus
                // paneles en vez de dejar huecos con "sin datos" que confunden.
                setPeriodFeatures(results[2] !== null, results[3] !== null);

                renderChart();
                renderHeat();
            }

            text($('dvpe-rangelabel'), stats.label || '');

            var totals = stats.totals || {};

            // 'Tiempo con fallas' es la unión de los reportes, no su suma: veinte
            // vecinos reportando el mismo apagón de 3 h son 3 h, no 60.
            var covered = totals.coverage_hours;

            // Un periodo cerrado no puede encabezarse con un número del presente.
            var ahora = rangeIncludesNow(stats);

            renderActiveTile(ahora, totals);

            animateCounters({
                active: ahora ? (live.active_count || 0) : (totals.restored || 0),
                reports: totals.reports || 0,
                hours: Math.round(covered === undefined ? (totals.outage_hours || 0) : covered)
            });

            renderCoverageHint(stats);

            text($('dvpe-stat-avg'), totals.avg_label || '—');

            renderSpark(stats);
            renderLiveList(state.reports);
            renderTicker(state.reports);

            // En modo calor los pines estorbarían: el mapa lo ocupa la capa.
            if (state.mapMode === 'live') {
                renderMap(state.reports);
            }

            renderRank();
            refreshDetail();

            var note = $('dvpe-updated');
            if (note) { note.textContent = ' Actualizado: ' + formatDateTime(live.generated_at) + '.'; }
            setStatus('ok', 'En vivo');
        }).catch(function (error) {
            state.failures++;
            setStatus('error', 'Sin conexión (reintentando)');
            if (window.console) {
                console.warn('Portal Reporte Eléctrico:', error.message);
            }
        });
    }

    /**
     * Reintento con espera creciente: si la API está caída, seguir pidiendo cada
     * 20 s solo añade carga. Se estira hasta 5 minutos y vuelve al ritmo normal
     * en cuanto responde.
     */
    function scheduleNext() {
        var base = cfg.pollSeconds * 1000;
        var delay = state.failures > 0
            ? Math.min(base * Math.pow(2, state.failures), 300000)
            : base;

        state.timer = window.setTimeout(tick, delay);
    }

    function tick() {
        // Con la pestaña en segundo plano no tiene sentido consultar: se espera
        // al siguiente ciclo, y al volver el foco se refresca de inmediato.
        if (document.hidden) {
            scheduleNext();
            return;
        }

        refresh().then(function () {
            if (compartido) { openDetail(parseInt(compartido, 10)); }

            scheduleNext();
        });
    }

    /**
     * El fundido de 180 ms al cambiar de periodo evita el "salto" brusco de
     * cifras: se apaga, se piden datos y se enciende con los valores nuevos.
     */
    function setRange(range, from, to) {
        var custom = range === 'custom';

        if (!custom && range === state.range) { return; }

        state.range = range;
        state.from = custom ? (from || '') : '';
        state.to = custom ? (to || '') : '';

        // Un periodo cerrado —ayer o uno a medida— es una consulta histórica: el
        // mapa pasa solo a calor, porque los pines en vivo son del presente y
        // junto a cifras de otro intervalo se leen mal.
        if ((custom || range === 'yesterday') && state.mapMode !== 'heat') {
            setMapMode('heat');
        }

        document.querySelectorAll('[data-range]').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-range') === range);
        });

        syncRangeSelect();
        markPeriodStale();
        writeUrl();

        var stats = $('dvpe-stats');
        if (stats) { stats.classList.add('is-fading'); }

        window.setTimeout(function () {
            refresh().then(function () {
                if (stats) { stats.classList.remove('is-fading'); }

                if (state.mapMode === 'heat') { fitToPoints(); }
            });
        }, 180);
    }

    /** Fuerza que el siguiente refresco vuelva a traer focos e histograma. */
    function markPeriodStale() {
        state.periodAt = 0;
    }

    /** El selector móvil y los botones de escritorio muestran siempre lo mismo. */
    function syncRangeSelect() {
        var select = $('dvpe-range-select');

        if (select && select.value !== state.range) {
            select.value = state.range;
        }
    }

    /** Abre o cierra el desplegable de fechas, rellenándolo con las últimas 24 h. */
    function toggleCustom(open) {
        var box = $('dvpe-custom');
        var button = $('dvpe-custom-btn');
        var from = $('dvpe-from');
        var to = $('dvpe-to');

        if (!box) { return; }

        if (open) { toggleExportMenu(false); }

        box.hidden = !open;

        if (button) { button.setAttribute('aria-expanded', open ? 'true' : 'false'); }

        // Al cerrarlo sin aplicar, el selector vuelve al periodo que sí está puesto.
        if (!open) { syncRangeSelect(); }

        if (!open || !from || !to) { return; }

        if (!from.value) { from.value = state.from || vetLocal(-24 * 3600 * 1000); }
        if (!to.value) { to.value = state.to || vetLocal(); }
    }

    function applyCustom() {
        var from = $('dvpe-from');
        var to = $('dvpe-to');

        if (!from || !to || (!from.value && !to.value)) { return; }

        setRange('custom', from.value, to.value);
    }

    function setTab(tab) {
        if (tab === state.tab) { return; }
        state.tab = tab;

        document.querySelectorAll('[data-tab]').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-tab') === tab);
        });

        var list = $('dvpe-rank');
        if (list) {
            list.classList.add('is-fading');
            window.setTimeout(function () {
                renderRank();
                list.classList.remove('is-fading');
            }, 180);
        }

        // El mapa de calor se agrupa por el mismo nivel que la tabla: al cambiar
        // de pestaña hay que volver a pedir los focos, ya reagrupados.
        if (state.mapMode === 'heat') {
            markPeriodStale();
            refresh();
        }
    }

    /* --- Arranque -------------------------------------------------------- */

    function init() {
        readUrl();

        // Un enlace compartido abre su reporte: ?report=412
        var compartido = new URLSearchParams(window.location.search).get('report');

        initMap();
        startClock();

        // El rango puede venir en la URL: se refleja en los botones y, si es a
        // medida, se abren los campos ya rellenos con lo que se está viendo.
        document.querySelectorAll('[data-range]').forEach(function (button) {
            var value = button.getAttribute('data-range');

            button.classList.toggle('is-active', value === state.range);

            button.addEventListener('click', function () {
                if (value === 'custom') {
                    toggleCustom($('dvpe-custom') ? $('dvpe-custom').hidden : true);
                    return;
                }

                toggleCustom(false);
                setRange(value);
            });
        });

        if (state.range === 'custom') {
            var fromInput = $('dvpe-from');
            var toInput = $('dvpe-to');

            if (fromInput) { fromInput.value = state.from; }
            if (toInput) { toInput.value = state.to; }

            toggleCustom(true);
            setMapMode('heat');
        }

        var select = $('dvpe-range-select');
        if (select) {
            select.value = state.range;

            select.addEventListener('change', function () {
                // El valor se lee ANTES de tocar nada: al cerrar el desplegable
                // de fechas se resincroniza el select con el periodo vigente, y
                // leerlo después devolvía el valor viejo y no cambiaba nada.
                var chosen = select.value;

                if (chosen === 'custom') {
                    toggleCustom(true);
                    return;
                }

                toggleCustom(false);
                setRange(chosen);
            });
        }

        var apply = $('dvpe-apply');
        if (apply) { apply.addEventListener('click', applyCustom); }

        document.querySelectorAll('[data-mapmode]').forEach(function (button) {
            button.addEventListener('click', function () {
                setMapMode(button.getAttribute('data-mapmode'));
            });
        });

        document.querySelectorAll('[data-weight]').forEach(function (button) {
            button.addEventListener('click', function () {
                setWeight(button.getAttribute('data-weight'));
            });
        });

        var exportBtn = $('dvpe-export-btn');
        if (exportBtn) {
            exportBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                toggleExportMenu();
            });
        }

        document.querySelectorAll('[data-export]').forEach(function (button) {
            button.addEventListener('click', function () {
                toggleExportMenu(false);
                runExport(button.getAttribute('data-export'));
            });
        });

        // Un clic fuera cierra los desplegables. El de fechas solo si el clic no
        // cayó dentro de él: si no, elegir una fecha lo cerraría a media faena.
        document.addEventListener('click', function (event) {
            toggleExportMenu(false);

            // El selector móvil cuenta como "dentro": elegir Personalizado en él
            // es justo lo que acaba de abrir el desplegable.
            var inside = event.target && event.target.closest &&
                event.target.closest('.dvpe-picker, .dvpe-rangepick');

            if (!inside) { toggleCustom(false); }

            // Tocar fuera de las barras devuelve el resumen del periodo.
            if (!(event.target && event.target.closest && event.target.closest('.dvpe-chart'))) {
                showChartPeak();
            }
        });

        document.querySelectorAll('[data-tab]').forEach(function (button) {
            button.addEventListener('click', function () {
                setTab(button.getAttribute('data-tab'));
            });
        });

        var close = $('dvpe-detail-close');
        if (close) { close.addEventListener('click', closeDetail); }

        var modal = $('dvpe-detail');
        if (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target === modal) { closeDetail(); }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { closeDetail(); }
        });

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { refresh(); }
        });

        refresh().then(function () {
            if (compartido) { openDetail(parseInt(compartido, 10)); }

            scheduleNext();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
