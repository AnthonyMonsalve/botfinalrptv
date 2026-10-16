/**
 * Aviso del último informe que sube desde abajo.
 *
 * Aparece cuando la persona ya lleva un rato en la página —no de entrada, que
 * sería tapar el mapa antes de que lo vea— y si lo cierra no vuelve a salir en
 * todo el día. Se recuerda con una fecha en localStorage, no con un contador:
 * así "hoy" significa hoy de verdad aunque recargue veinte veces.
 */
(function () {
    'use strict';

    var cfg = window.DVPE_NUDGE || {};
    var CLAVE = 'dvpe_nudge_cerrado';

    function hoy() {
        var d = new Date();

        return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
    }

    /** El almacenamiento puede estar bloqueado (modo privado): no debe romper nada. */
    function cerradoHoy() {
        try {
            return window.localStorage.getItem(CLAVE) === hoy();
        } catch (e) {
            return false;
        }
    }

    function recordarCierre() {
        try {
            window.localStorage.setItem(CLAVE, hoy());
        } catch (e) {
            // Sin almacenamiento, el aviso reaparecerá en la próxima visita.
        }
    }

    function init() {
        var aviso = document.getElementById('dvpe-nudge');

        if (!aviso || cerradoHoy()) { return; }

        var cerrar = document.getElementById('dvpe-nudge-close');

        if (cerrar) {
            cerrar.addEventListener('click', function () {
                aviso.classList.remove('is-visible');
                recordarCierre();

                // Se retira del todo cuando termina la animación de bajada.
                window.setTimeout(function () { aviso.hidden = true; }, 500);
            });
        }

        // Al abrir el informe también se da por visto: no hace falta insistir.
        var enlace = aviso.querySelector('a');

        if (enlace) {
            enlace.addEventListener('click', recordarCierre);
        }

        var espera = Math.max(3, parseInt(cfg.delay, 10) || 45) * 1000;

        window.setTimeout(function () {
            aviso.hidden = false;

            // Dos cuadros para que el navegador aplique el estado inicial antes
            // de la transición; si no, aparece de golpe en vez de deslizarse.
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    aviso.classList.add('is-visible');
                });
            });
        }, espera);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
