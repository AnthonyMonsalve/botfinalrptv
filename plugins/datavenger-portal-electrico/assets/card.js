/**
 * Tarjeta de un reporte suelto, para compartir en redes.
 *
 * Compone un PNG de 1080 x 1350 (la proporción 4:5, que es la que no recortan
 * ni Instagram ni WhatsApp) con dos maquetas:
 *
 *   · con foto  → la foto manda y el mapa queda como recuadro de contexto
 *   · sin foto  → el mapa pasa a ser el protagonista, centrado en la zona
 *
 * Se apoya en DVPEHeat para las teselas y las utilidades de dibujo, así que la
 * tarjeta y el mapa de la pantalla usan exactamente la misma proyección.
 */
window.DVPECard = (function () {
    'use strict';

    var W = 1080;
    var H = 1350;
    var PAD = 56;

    var COLOR = {
        fondo: '#0b1020',
        panel: 'rgba(255,255,255,.06)',
        texto: '#ffffff',
        suave: 'rgba(255,255,255,.72)',
        tenue: 'rgba(255,255,255,.5)',
        activa: '#f0687f',
        cerrada: '#4ade80',
        pendiente: '#93a3bd'
    };

    /** Cómo se titula cada estado dentro de la tarjeta. */
    function estadoDe(report) {
        if (report.status === 'restored') {
            return { texto: 'Servicio restablecido', color: COLOR.cerrada, duracion: 'Duración total' };
        }

        if (report.status === 'undetermined') {
            return { texto: 'Pendiente de confirmación', color: COLOR.pendiente, duracion: 'Duración registrada' };
        }

        return { texto: 'Falla activa', color: COLOR.activa, duracion: 'Tiempo transcurrido' };
    }

    /** 'dd/mm/aaaa hh:mm' -> '27/09 a las 2:00 pm' */
    function momento(valor) {
        if (!valor) { return '—'; }

        var p = String(valor).split(/[- :]/);

        if (p.length < 5) { return valor; }

        var hora = parseInt(p[3], 10);
        var sufijo = hora >= 12 ? 'pm' : 'am';
        var doce = hora % 12 === 0 ? 12 : hora % 12;

        return p[2] + '/' + p[1] + ' a las ' + doce + ':' + p[4] + ' ' + sufijo;
    }

    function fuente(peso, tam) {
        return peso + ' ' + tam + 'px "Instrument Sans", system-ui, sans-serif';
    }

    /* --- Mapa de la tarjeta ---------------------------------------------- */

    /**
     * Dibuja un trozo de mapa centrado en el reporte, con su marca.
     * Devuelve una promesa porque las teselas llegan por la red.
     */
    function dibujarMapa(ctx, report, caja, zoom, tiles) {
        var canvas = document.createElement('canvas');
        canvas.width = caja.w;
        canvas.height = caja.h;

        var mapa = canvas.getContext('2d');

        mapa.fillStyle = '#16203a';
        mapa.fillRect(0, 0, caja.w, caja.h);

        var centro = DVPEHeat.project(report.lat, report.lng, zoom);
        var origen = { x: centro.x - caja.w / 2, y: centro.y - caja.h / 2 };

        return DVPEHeat.drawTiles(mapa, tiles, origen, zoom, caja.w, caja.h).then(function () {
            // La marca: halo, aro y punto, en el centro exacto del recorte.
            var cx = caja.w / 2;
            var cy = caja.h / 2;
            var exacta = report.geo_precision === 'gps';
            var color = exacta ? '#e8536b' : '#2b7de9';

            var halo = mapa.createRadialGradient(cx, cy, 4, cx, cy, 58);
            halo.addColorStop(0, exacta ? 'rgba(232,83,107,.45)' : 'rgba(43,125,233,.42)');
            halo.addColorStop(1, 'rgba(0,0,0,0)');

            mapa.fillStyle = halo;
            mapa.beginPath();
            mapa.arc(cx, cy, 58, 0, Math.PI * 2);
            mapa.fill();

            mapa.beginPath();
            mapa.arc(cx, cy, 15, 0, Math.PI * 2);
            mapa.fillStyle = color;
            mapa.fill();
            mapa.lineWidth = 5;
            mapa.strokeStyle = '#fff';
            mapa.stroke();

            ctx.save();
            DVPEHeat.roundRect(ctx, caja.x, caja.y, caja.w, caja.h, caja.r || 0);
            ctx.clip();
            ctx.drawImage(canvas, caja.x, caja.y);
            ctx.restore();

            if (caja.r) {
                ctx.strokeStyle = 'rgba(255,255,255,.14)';
                ctx.lineWidth = 2;
                DVPEHeat.roundRect(ctx, caja.x, caja.y, caja.w, caja.h, caja.r);
                ctx.stroke();
            }
        });
    }

    /** Degradado para que el texto se lea sobre la foto o el mapa. */
    function velo(ctx, x, y, w, h) {
        var g = ctx.createLinearGradient(0, y, 0, y + h);

        g.addColorStop(0, 'rgba(11,16,32,0)');
        g.addColorStop(0.55, 'rgba(11,16,32,.72)');
        g.addColorStop(1, 'rgba(11,16,32,.96)');

        ctx.fillStyle = g;
        ctx.fillRect(x, y, w, h);
    }

    /* --- Bloques de la tarjeta -------------------------------------------- */

    function distintivo(ctx, x, y, texto, color) {
        ctx.font = fuente(700, 25);

        var ancho = ctx.measureText(texto.toUpperCase()).width + 44;

        ctx.fillStyle = color;
        DVPEHeat.roundRect(ctx, x, y, ancho, 46, 23);
        ctx.fill();

        ctx.fillStyle = '#0b1020';
        ctx.textBaseline = 'middle';
        ctx.fillText(texto.toUpperCase(), x + 22, y + 24);
        ctx.textBaseline = 'alphabetic';

        return ancho;
    }

    var TITULO_ALTO = 70;

    /** Parte la zona en líneas (dos como mucho) sin dibujarla todavía. */
    function lineasTitulo(ctx, texto, ancho) {
        ctx.font = fuente(700, 62);

        var palabras = String(texto || 'Zona no especificada').split(' ');
        var lineas = [];
        var actual = '';

        palabras.forEach(function (palabra) {
            var prueba = actual ? actual + ' ' + palabra : palabra;

            if (ctx.measureText(prueba).width > ancho && actual) {
                lineas.push(actual);
                actual = palabra;
            } else {
                actual = prueba;
            }
        });

        lineas.push(actual);

        if (lineas.length > 2) {
            lineas = lineas.slice(0, 2);
            lineas[1] = DVPEHeat.ellipsis(ctx, lineas[1] + '…', ancho);
        }

        return lineas;
    }

    /**
     * Dibuja el título y devuelve dónde empieza.
     *
     * Se coloca por su ÚLTIMA línea: sobre la foto el texto va anclado abajo, y
     * si creciera hacia abajo una zona de nombre largo se saldría de la imagen.
     */
    function titulo(ctx, lineas, x, baseUltima) {
        var primera = baseUltima - (lineas.length - 1) * TITULO_ALTO;

        ctx.fillStyle = COLOR.texto;
        ctx.font = fuente(700, 62);

        lineas.forEach(function (linea, i) {
            ctx.fillText(linea, x, primera + i * TITULO_ALTO);
        });

        return primera;
    }

    /** Fila de dato: etiqueta arriba, valor debajo. Nunca se pisan. */
    function dato(ctx, x, y, ancho, etiqueta, valor, color) {
        ctx.fillStyle = COLOR.tenue;
        ctx.font = fuente(600, 24);
        ctx.fillText(etiqueta, x, y);

        ctx.fillStyle = color || COLOR.texto;
        ctx.font = fuente(700, 38);
        ctx.fillText(DVPEHeat.ellipsis(ctx, valor, ancho), x, y + 44);

        return y + 44;
    }

    /** Texto largo (la dirección) repartido en varias líneas. */
    function parrafo(ctx, texto, x, y, ancho, maxLineas) {
        ctx.fillStyle = COLOR.suave;
        ctx.font = fuente(500, 28);

        var palabras = String(texto).split(' ');
        var lineas = [];
        var actual = '';

        palabras.forEach(function (palabra) {
            var prueba = actual ? actual + ' ' + palabra : palabra;

            if (ctx.measureText(prueba).width > ancho && actual) {
                lineas.push(actual);
                actual = palabra;
            } else {
                actual = prueba;
            }
        });

        lineas.push(actual);

        if (lineas.length > maxLineas) {
            lineas = lineas.slice(0, maxLineas);
            lineas[maxLineas - 1] = DVPEHeat.ellipsis(ctx, lineas[maxLineas - 1] + '…', ancho);
        }

        lineas.forEach(function (linea, i) {
            ctx.fillText(linea, x, y + i * 36);
        });

        return y + (lineas.length - 1) * 36;
    }

    /** Pie común: mascota, marca y la invitación a reportar. */
    function pie(ctx, meta) {
        var y = H - 172;

        ctx.fillStyle = 'rgba(255,255,255,.07)';
        ctx.fillRect(PAD, y - 34, W - PAD * 2, 2);

        if (meta.logo) {
            ctx.drawImage(meta.logo, PAD, y - 6, 86, 86);
        }

        var x = PAD + (meta.logo ? 104 : 0);

        ctx.fillStyle = COLOR.texto;
        ctx.font = fuente(700, 32);
        ctx.fillText('¿Sin luz en tu zona?', x, y + 30);

        ctx.fillStyle = COLOR.suave;
        ctx.font = fuente(500, 26);
        ctx.fillText('Repórtalo por WhatsApp con DatAvenger', x, y + 68);

        ctx.fillStyle = COLOR.tenue;
        ctx.font = fuente(500, 22);
        ctx.textAlign = 'right';
        ctx.fillText(meta.credit || '', W - PAD, y + 30);
        ctx.fillText(meta.generated || '', W - PAD, y + 62);
        ctx.textAlign = 'left';
    }

    /* --- Composición ------------------------------------------------------ */

    /**
     * @param report  El reporte tal cual lo devuelve /power/report/{id}
     * @param opts    {tiles, meta:{logo, credit, generated, accent}, photo}
     * @returns Promise<Blob>
     */
    function build(report, opts) {
        var canvas = document.createElement('canvas');
        canvas.width = W;
        canvas.height = H;

        var ctx = canvas.getContext('2d');
        var estado = estadoDe(report);
        var meta = opts.meta || {};
        var tieneMapa = report.lat !== null && report.lng !== null;

        ctx.fillStyle = COLOR.fondo;
        ctx.fillRect(0, 0, W, H);
        ctx.textBaseline = 'alphabetic';
        ctx.textAlign = 'left';

        var trabajo = opts.photo
            ? conFoto(ctx, report, estado, opts)
            : conMapa(ctx, report, estado, opts, tieneMapa);

        return trabajo.then(function () {
            return document.fonts && document.fonts.ready ? document.fonts.ready : null;
        }).then(function () {
            pie(ctx, meta);

            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) { resolve(blob); }, 'image/png');
            });
        });
    }

    /** Maqueta con foto: la imagen ocupa la mitad superior y el mapa se encoge. */
    function conFoto(ctx, report, estado, opts) {
        var alturaFoto = 700;
        var foto = opts.photo;

        // Recorte "cover": se llena la caja sin deformar la foto.
        var escala = Math.max(W / foto.width, alturaFoto / foto.height);
        var w = foto.width * escala;
        var h = foto.height * escala;

        ctx.drawImage(foto, (W - w) / 2, (alturaFoto - h) / 2, w, h);
        velo(ctx, 0, alturaFoto - 300, W, 300);

        var lineas = lineasTitulo(ctx, report.zona, W - PAD * 2 - 20);
        var primera = titulo(ctx, lineas, PAD, alturaFoto - 64);

        // El distintivo va encima de la primera línea, siempre dentro de la foto.
        distintivo(ctx, PAD, primera - 118, estado.texto, estado.color);

        // Datos bajo la foto, con el mapa como recuadro a la derecha.
        var datosY = alturaFoto + 70;
        var anchoDatos = report.lat !== null ? W - PAD * 2 - 330 : W - PAD * 2;

        dato(ctx, PAD, datosY, anchoDatos, 'Sin servicio desde', momento(report.outage_since));
        dato(ctx, PAD, datosY + 110, anchoDatos, estado.duracion, report.duration_label, estado.color);

        if (report.direccion) {
            ctx.fillStyle = COLOR.tenue;
            ctx.font = fuente(600, 24);
            ctx.fillText('Dirección', PAD, datosY + 220);
            parrafo(ctx, report.direccion, PAD, datosY + 258, anchoDatos, 2);
        }

        if (report.lat === null || report.lng === null) {
            return Promise.resolve();
        }

        return dibujarMapa(ctx, report, {
            x: W - PAD - 300, y: datosY - 34, w: 300, h: 300, r: 18
        }, 11, opts.tiles || []);
    }

    /** Maqueta sin foto: el mapa es el protagonista. */
    function conMapa(ctx, report, estado, opts, tieneMapa) {
        var y = 110;

        distintivo(ctx, PAD, y - 34, estado.texto, estado.color);

        var lineas = lineasTitulo(ctx, report.zona, W - PAD * 2);

        titulo(ctx, lineas, PAD, y + 110 + (lineas.length - 1) * 70);

        y = y + 110 + (lineas.length - 1) * 70 + 40;

        var mapaY = y + 20;
        var mapaH = 470;

        var datos = function () {
            var base = mapaY + mapaH + 76;
            var ancho = (W - PAD * 2 - 40) / 2;

            dato(ctx, PAD, base, ancho, 'Sin servicio desde', momento(report.outage_since));
            dato(ctx, PAD + ancho + 40, base, ancho, estado.duracion, report.duration_label, estado.color);

            if (report.direccion) {
                ctx.fillStyle = COLOR.tenue;
                ctx.font = fuente(600, 24);
                ctx.fillText('Dirección', PAD, base + 110);
                parrafo(ctx, report.direccion, PAD, base + 148, W - PAD * 2, 2);
            }
        };

        if (!tieneMapa) {
            datos();

            return Promise.resolve();
        }

        return dibujarMapa(ctx, report, {
            x: PAD, y: mapaY, w: W - PAD * 2, h: mapaH, r: 22
        }, 12, opts.tiles || []).then(function () {
            // Una nota sobre el recuadro: la ubicación puede ser aproximada.
            ctx.fillStyle = 'rgba(11,16,32,.78)';
            DVPEHeat.roundRect(ctx, PAD + 18, mapaY + mapaH - 62, 330, 44, 12);
            ctx.fill();

            ctx.fillStyle = COLOR.suave;
            ctx.font = fuente(600, 23);
            ctx.fillText(
                report.geo_precision === 'gps' ? 'Ubicación exacta' : 'Ubicación aproximada por zona',
                PAD + 36,
                mapaY + mapaH - 33
            );

            datos();
        });
    }

    return {
        build: build,
        estadoDe: estadoDe
    };
})();
