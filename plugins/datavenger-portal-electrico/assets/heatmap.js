/**
 * Motor del mapa de calor y de la exportación a imagen.
 *
 * Vive aparte de portal.js porque no sabe nada de Leaflet ni del DOM del portal:
 * proyecta coordenadas, pinta focos sobre un canvas y compone el PNG. El mismo
 * dibujo sirve para la capa que se ve en pantalla y para la imagen descargada,
 * así lo que se descarga es exactamente lo que se está viendo.
 *
 * La proyección es Web Mercator con teselas de 256 px, la misma que usa Leaflet,
 * para que la capa encaje pixel a pixel sobre el mapa.
 */
window.DVPEHeat = (function () {
    'use strict';

    var TILE = 256;

    /* --- Proyección ------------------------------------------------------ */

    function project(lat, lng, zoom) {
        var scale = TILE * Math.pow(2, zoom);
        var sin = Math.sin(lat * Math.PI / 180);

        // Se recorta el seno para que los polos no den infinito.
        sin = Math.max(-0.9999, Math.min(0.9999, sin));

        return {
            x: (lng + 180) / 360 * scale,
            y: (0.5 - Math.log((1 + sin) / (1 - sin)) / (4 * Math.PI)) * scale
        };
    }

    /** Zoom entero más cercano que mete el recuadro dentro de w x h. */
    function fitZoom(bbox, w, h, minZoom, maxZoom) {
        for (var z = maxZoom; z >= minZoom; z--) {
            var a = project(bbox.north, bbox.west, z);
            var b = project(bbox.south, bbox.east, z);

            if (Math.abs(b.x - a.x) <= w && Math.abs(b.y - a.y) <= h) {
                return z;
            }
        }

        return minZoom;
    }

    /** Recuadro que cubre todos los puntos, con margen en grados. */
    function boundsOf(points, margin) {
        if (!points || !points.length) { return null; }

        var north = -90, south = 90, east = -180, west = 180;

        points.forEach(function (p) {
            north = Math.max(north, p.lat);
            south = Math.min(south, p.lat);
            east = Math.max(east, p.lng);
            west = Math.min(west, p.lng);
        });

        margin = margin || 0.4;

        return {
            north: Math.min(90, north + margin),
            south: Math.max(-90, south - margin),
            east: Math.min(180, east + margin),
            west: Math.max(-180, west - margin)
        };
    }

    /* --- Dibujo de los focos --------------------------------------------- */

    var circleCache = {};

    /**
     * Disco con degradado radial que se estampa una vez por punto.
     * Se dibuja en negro: el color lo pone después la paleta, leyendo solo el
     * canal alfa. Así los focos que se solapan suman intensidad de forma natural.
     */
    function circle(radius) {
        if (circleCache[radius]) { return circleCache[radius]; }

        var canvas = document.createElement('canvas');
        var size = radius * 2;

        canvas.width = size;
        canvas.height = size;

        var ctx = canvas.getContext('2d');
        var gradient = ctx.createRadialGradient(radius, radius, radius * 0.15, radius, radius, radius);

        gradient.addColorStop(0, 'rgba(0,0,0,1)');
        gradient.addColorStop(1, 'rgba(0,0,0,0)');

        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, size, size);

        circleCache[radius] = canvas;

        return canvas;
    }

    var paletteCache = null;

    /** 256 colores del degradado, de frío a caliente. */
    function palette() {
        if (paletteCache) { return paletteCache; }

        var canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 256;

        var ctx = canvas.getContext('2d');
        var gradient = ctx.createLinearGradient(0, 0, 0, 256);

        gradient.addColorStop(0.00, 'rgba(43,125,233,0)');
        gradient.addColorStop(0.20, 'rgba(43,125,233,1)');
        gradient.addColorStop(0.40, 'rgba(46,196,182,1)');
        gradient.addColorStop(0.60, 'rgba(245,205,60,1)');
        gradient.addColorStop(0.80, 'rgba(240,130,40,1)');
        gradient.addColorStop(1.00, 'rgba(226,48,40,1)');

        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, 1, 256);

        paletteCache = ctx.getImageData(0, 0, 1, 256).data;

        return paletteCache;
    }

    /**
     * Pinta los focos sobre un contexto ya dimensionado.
     *
     * @param {Array} pts [{x, y, v}] en píxeles del canvas, v de 0 a 1.
     */
    function draw(ctx, width, height, pts, radius) {
        ctx.clearRect(0, 0, width, height);

        if (!pts.length) { return; }

        var disc = circle(radius);

        pts.forEach(function (p) {
            // Un mínimo visible: un punto con muy poco peso debe verse igual.
            ctx.globalAlpha = Math.min(1, Math.max(0.12, p.v));
            ctx.drawImage(disc, p.x - radius, p.y - radius);
        });

        ctx.globalAlpha = 1;

        var image = ctx.getImageData(0, 0, width, height);
        var data = image.data;
        var colors = palette();

        for (var i = 0; i < data.length; i += 4) {
            var alpha = data[i + 3];

            if (!alpha) { continue; }

            var offset = alpha * 4;

            data[i] = colors[offset];
            data[i + 1] = colors[offset + 1];
            data[i + 2] = colors[offset + 2];
            // El alfa original se mantiene suavizado para que el mapa se traspase.
            data[i + 3] = Math.round(alpha * 0.82);
        }

        ctx.putImageData(image, 0, 0);
    }

    /**
     * Peso de un punto normalizado entre 0 y 1.
     *
     * Raíz cuadrada en vez de proporción directa: con un solo apagón enorme, el
     * reparto lineal dejaría el resto del país invisible.
     */
    function intensity(value, max) {
        if (!max || value <= 0) { return 0; }

        return Math.sqrt(value / max);
    }

    /** Radio en píxeles según el zoom: los focos crecen al acercarse. */
    function radiusFor(zoom) {
        return Math.max(14, Math.min(70, Math.round(12 + (zoom - 5) * 7)));
    }

    /* --- Teselas --------------------------------------------------------- */

    function tileUrl(template, x, y, z) {
        return template
            .replace('{s}', 'a')
            .replace('{r}', '')
            .replace('{z}', z)
            .replace('{x}', x)
            .replace('{y}', y);
    }

    function loadImage(url) {
        return new Promise(function (resolve) {
            var img = new Image();

            // Sin esto el canvas queda "manchado" y toBlob() lanza error de
            // seguridad. Las teselas responden con Access-Control-Allow-Origin.
            img.crossOrigin = 'anonymous';
            img.onload = function () { resolve(img); };
            img.onerror = function () { resolve(null); };
            img.src = url;
        });
    }

    /**
     * Dibuja el mapa base en el canvas.
     *
     * @param origin Píxel (en el zoom dado) de la esquina superior izquierda.
     */
    function drawTiles(ctx, layers, origin, zoom, width, height) {
        var world = Math.pow(2, zoom);
        var jobs = [];

        var x0 = Math.floor(origin.x / TILE);
        var x1 = Math.floor((origin.x + width) / TILE);
        var y0 = Math.floor(origin.y / TILE);
        var y1 = Math.floor((origin.y + height) / TILE);

        layers.forEach(function (layer) {
            for (var x = x0; x <= x1; x++) {
                for (var y = y0; y <= y1; y++) {
                    if (y < 0 || y >= world) { continue; }

                    var tx = ((x % world) + world) % world;
                    var zoomForLayer = Math.min(zoom, layer.maxZoom || zoom);

                    // Si el proveedor no llega a este zoom se amplía su tesela.
                    var factor = Math.pow(2, zoom - zoomForLayer);
                    var sx = Math.floor(tx / factor);
                    var sy = Math.floor(y / factor);

                    jobs.push({
                        url: tileUrl(layer.url, sx, sy, zoomForLayer),
                        dx: x * TILE - origin.x,
                        dy: y * TILE - origin.y,
                        crop: factor > 1 ? {
                            size: TILE / factor,
                            ox: (tx % factor) * (TILE / factor),
                            oy: (y % factor) * (TILE / factor)
                        } : null
                    });
                }
            }
        });

        return Promise.all(jobs.map(function (job) {
            return loadImage(job.url).then(function (img) { return { job: job, img: img }; });
        })).then(function (results) {
            results.forEach(function (result) {
                if (!result.img) { return; }

                var job = result.job;

                if (job.crop) {
                    ctx.drawImage(
                        result.img,
                        job.crop.ox, job.crop.oy, job.crop.size, job.crop.size,
                        job.dx, job.dy, TILE, TILE
                    );
                } else {
                    ctx.drawImage(result.img, job.dx, job.dy, TILE, TILE);
                }
            });
        });
    }

    /* --- Exportación ----------------------------------------------------- */

    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    function ellipsis(ctx, value, maxWidth) {
        var text = String(value == null ? '' : value);

        if (ctx.measureText(text).width <= maxWidth) { return text; }

        while (text.length > 1 && ctx.measureText(text + '…').width > maxWidth) {
            text = text.slice(0, -1);
        }

        return text + '…';
    }

    /** Leyenda de intensidad, reutilizada por las dos salidas. */
    function drawLegend(ctx, x, y, width, label) {
        var height = 10;
        var gradient = ctx.createLinearGradient(x, 0, x + width, 0);

        gradient.addColorStop(0.00, 'rgba(43,125,233,1)');
        gradient.addColorStop(0.35, 'rgba(46,196,182,1)');
        gradient.addColorStop(0.65, 'rgba(245,205,60,1)');
        gradient.addColorStop(1.00, 'rgba(226,48,40,1)');

        ctx.fillStyle = gradient;
        roundRect(ctx, x, y, width, height, height / 2);
        ctx.fill();

        ctx.fillStyle = 'rgba(255,255,255,.72)';
        ctx.font = '500 15px "Instrument Sans", system-ui, sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('menos', x, y + 30);
        ctx.textAlign = 'right';
        ctx.fillText('más', x + width, y + 30);

        if (label) {
            ctx.textAlign = 'center';
            ctx.fillText(label, x + width / 2, y + 30);
        }

        ctx.textAlign = 'left';
    }

    /**
     * Compone el PNG.
     *
     * @param opts.format  'social' (1200x1200, con marca) | 'clean' (1600x900)
     * @param opts.points  [{lat, lng, c, s}]
     * @param opts.weight  's' | 'c'
     * @param opts.max     {c, s}
     * @param opts.tiles   capas del mapa base
     * @param opts.meta    {title, rangeLabel, reports, hours, active, top[], credit, generated, logo}
     * @returns Promise<Blob>
     */
    function exportImage(opts) {
        var social = opts.format !== 'clean';
        var width = social ? 1200 : 1600;
        var height = social ? 1200 : 900;

        var headerH = social ? 290 : 0;
        var footerH = social ? 300 : 0;
        var mapH = height - headerH - footerH;

        var canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        var ctx = canvas.getContext('2d');

        ctx.fillStyle = '#0b1020';
        ctx.fillRect(0, 0, width, height);

        var bbox = boundsOf(opts.points, 0.5) || {
            north: 12.5, south: 0.6, east: -59.5, west: -73.5 // Venezuela
        };

        var zoom = fitZoom(bbox, width, mapH, 4, 11);
        var center = project((bbox.north + bbox.south) / 2, (bbox.east + bbox.west) / 2, zoom);
        var origin = { x: center.x - width / 2, y: center.y - mapH / 2 };

        // El mapa se dibuja en su propio canvas y luego se pega en su franja: así
        // los focos no se salen sobre la cabecera ni el pie.
        var mapCanvas = document.createElement('canvas');
        mapCanvas.width = width;
        mapCanvas.height = mapH;

        var mapCtx = mapCanvas.getContext('2d');

        mapCtx.fillStyle = '#0b1020';
        mapCtx.fillRect(0, 0, width, mapH);

        return drawTiles(mapCtx, opts.tiles, origin, zoom, width, mapH).then(function () {
            var heatCanvas = document.createElement('canvas');
            heatCanvas.width = width;
            heatCanvas.height = mapH;

            var max = opts.max[opts.weight] || 0;

            var pts = opts.points.map(function (point) {
                var p = project(point.lat, point.lng, zoom);

                return {
                    x: p.x - origin.x,
                    y: p.y - origin.y,
                    v: intensity(point[opts.weight], max)
                };
            });

            draw(heatCanvas.getContext('2d'), width, mapH, pts, radiusFor(zoom) + 6);
            mapCtx.drawImage(heatCanvas, 0, 0);

            ctx.drawImage(mapCanvas, 0, headerH);

            return document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
        }).then(function () {
            var meta = opts.meta || {};

            if (!social) {
                drawCleanChrome(ctx, width, height, meta, opts.weight);
            } else {
                drawSocialChrome(ctx, width, height, headerH, footerH, meta, opts.weight);
            }

            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) { resolve(blob); }, 'image/png');
            });
        });
    }

    /** Cabecera y pie de la pieza para redes. */
    function drawSocialChrome(ctx, width, height, headerH, footerH, meta, weight) {
        var pad = 56;
        var accent = meta.accent || '#2b7de9';

        ctx.fillStyle = '#0b1020';
        ctx.fillRect(0, 0, width, headerH);
        ctx.fillRect(0, height - footerH, width, footerH);

        ctx.textBaseline = 'alphabetic';
        ctx.textAlign = 'left';

        // La mascota va arriba a la derecha, en la cabecera: abajo se comía el
        // valor de la segunda columna del top de zonas.
        var logoSize = 96;

        if (meta.logo) {
            ctx.drawImage(meta.logo, width - pad - logoSize, 34, logoSize, logoSize);
        }

        var titleWidth = width - pad * 2 - (meta.logo ? logoSize + 28 : 0);

        ctx.fillStyle = accent;
        ctx.font = '700 24px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText(ellipsis(ctx, (meta.kicker || '').toUpperCase(), titleWidth), pad, 62);

        ctx.fillStyle = '#ffffff';
        ctx.font = '700 50px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText(ellipsis(ctx, meta.title || 'Fallas del servicio eléctrico', titleWidth), pad, 118);

        ctx.fillStyle = 'rgba(255,255,255,.78)';
        ctx.font = '500 26px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText(ellipsis(ctx, meta.rangeLabel || '', titleWidth), pad, 158);

        // Cifras del periodo: etiqueta arriba y número debajo, en líneas
        // separadas. Antes iban en la misma línea y el número largo se comía su
        // propia etiqueta; así ya no depende de medir anchos.
        var stats = [
            { label: 'Reportes', value: meta.reports },
            // El periodo va en la etiqueta ("de 7 días") para que el tiempo con
            // fallas no se lea como una suma de horas de todos los reportes.
            { label: ('Tiempo con fallas ' + (meta.hoursHint || '')).trim(), value: meta.hours },
            { label: 'Zonas afectadas', value: meta.zones }
        ];

        var gap = 16;
        var boxW = (width - pad * 2 - gap * 2) / 3;
        var boxH = 82;
        var boxY = headerH - boxH - 22;

        stats.forEach(function (stat, index) {
            var x = pad + index * (boxW + gap);

            ctx.fillStyle = 'rgba(255,255,255,.06)';
            roundRect(ctx, x, boxY, boxW, boxH, 12);
            ctx.fill();

            ctx.fillStyle = 'rgba(255,255,255,.62)';
            ctx.font = '600 17px "Instrument Sans", system-ui, sans-serif';
            ctx.fillText(ellipsis(ctx, stat.label, boxW - 36), x + 18, boxY + 30);

            ctx.fillStyle = '#ffffff';
            ctx.font = '700 34px "Instrument Sans", system-ui, sans-serif';
            ctx.fillText(ellipsis(ctx, stat.value == null ? '—' : stat.value, boxW - 36), x + 18, boxY + 68);
        });

        // Pie: top de zonas en dos columnas.
        var footTop = height - footerH + 40;

        ctx.fillStyle = 'rgba(255,255,255,.72)';
        ctx.font = '600 21px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText(weight === 'c' ? 'Zonas con más reportes' : 'Zonas con más tiempo sin servicio', pad, footTop);

        var top = (meta.top || []).slice(0, 6);
        var colW = (width - pad * 2 - 40) / 2;

        top.forEach(function (row, index) {
            var col = index % 2;
            var line = Math.floor(index / 2);
            var x = pad + col * (colW + 40);
            var y = footTop + 44 + line * 36;

            ctx.fillStyle = 'rgba(255,255,255,.45)';
            ctx.font = '600 20px "IBM Plex Mono", monospace';
            ctx.fillText(String(index + 1), x, y);

            ctx.fillStyle = accent;
            ctx.font = '600 20px "IBM Plex Mono", monospace';
            ctx.textAlign = 'right';
            ctx.fillText(row.value, x + colW, y);

            // El nombre se recorta con el ancho que deja el valor, no con uno fijo.
            var valueWidth = ctx.measureText(row.value).width;

            ctx.textAlign = 'left';
            ctx.fillStyle = '#ffffff';
            ctx.font = '600 21px "Instrument Sans", system-ui, sans-serif';
            ctx.fillText(ellipsis(ctx, row.name, colW - valueWidth - 50), x + 28, y);
        });

        drawLegend(ctx, pad, height - 96, 260, null);

        ctx.fillStyle = 'rgba(255,255,255,.55)';
        ctx.font = '500 17px "Instrument Sans", system-ui, sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(meta.credit || '', width - pad, height - 72);
        ctx.fillText('Generado el ' + (meta.generated || ''), width - pad, height - 46);
        ctx.textAlign = 'left';
    }

    /** Salida limpia: solo el mapa, con leyenda y crédito discretos. */
    function drawCleanChrome(ctx, width, height, meta, weight) {
        var pad = 36;

        ctx.fillStyle = 'rgba(11,16,32,.72)';
        roundRect(ctx, pad, height - 118, 320, 82, 14);
        ctx.fill();

        ctx.fillStyle = 'rgba(255,255,255,.8)';
        ctx.font = '600 17px "Instrument Sans", system-ui, sans-serif';
        ctx.fillText(weight === 'c' ? 'Reportes' : 'Horas sin servicio', pad + 20, height - 88);

        drawLegend(ctx, pad + 20, height - 74, 280, null);

        ctx.fillStyle = 'rgba(255,255,255,.7)';
        ctx.font = '500 17px "Instrument Sans", system-ui, sans-serif';
        ctx.textAlign = 'right';
        ctx.fillText(meta.rangeLabel || '', width - pad, height - 60);
        ctx.fillText(meta.credit || '', width - pad, height - 36);
        ctx.textAlign = 'left';
    }

    return {
        project: project,
        fitZoom: fitZoom,
        boundsOf: boundsOf,
        draw: draw,
        intensity: intensity,
        radiusFor: radiusFor,
        exportImage: exportImage,
        // Piezas de dibujo que reutiliza la tarjeta de un reporte suelto.
        drawTiles: drawTiles,
        loadImage: loadImage,
        roundRect: roundRect,
        ellipsis: ellipsis
    };
})();
