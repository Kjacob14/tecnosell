/**
 * tema.js
 *
 * Tema claro/oscuro y tamaño de texto, compartido por todas las páginas
 * (index, admin, login y registro).
 *
 * Se carga dentro del <head> para aplicar la preferencia guardada antes de
 * que se pinte la página y evitar el parpadeo del tema equivocado.
 *
 * Botones que reconoce (en cualquier página):
 * - data-accion="alternar-tema"  -> cambia entre tema oscuro y claro.
 * - data-accion="tamano-texto"   -> recorre los tamaños de texto y vuelve al primero.
 *
 * La preferencia se guarda en localStorage. Si el navegador lo bloquea (por
 * ejemplo en una ventana privada), todo funciona igual, solo que no se
 * recuerda al recargar.
 */

(function () {
    'use strict';

    const CLAVE_TEMA = 'tecnosell_tema';
    const CLAVE_TEXTO = 'tecnosell_tamano_texto';

    // Porcentaje sobre los 16 px base del navegador (ver html en estilos.css).
    const NIVELES_TEXTO = [
        { porcentaje: 100, nombre: 'normal' },
        { porcentaje: 115, nombre: 'grande' },
        { porcentaje: 130, nombre: 'muy grande' }
    ];

    let temaActual = 'oscuro';
    let nivelTextoActual = 0;

    function leerPreferencia(clave) {
        try {
            return window.localStorage.getItem(clave);
        } catch (error) {
            return null;
        }
    }

    function guardarPreferencia(clave, valor) {
        try {
            window.localStorage.setItem(clave, valor);
        } catch (error) {
            // Sin almacenamiento: la preferencia vale solo para esta visita.
        }
    }

    /** Pone ícono y texto accesible en los botones de tema que haya en la página. */
    function actualizarBotonesTema() {
        const esClaro = temaActual === 'claro';
        const etiqueta = esClaro ? 'Cambiar a modo oscuro' : 'Cambiar a modo claro';

        document.querySelectorAll('[data-accion="alternar-tema"]').forEach((boton) => {
            const icono = boton.querySelector('i');

            // El ícono muestra a dónde se va a cambiar, no el tema actual.
            if (icono) {
                icono.className = esClaro ? 'bi bi-moon-stars' : 'bi bi-sun';
            }

            boton.title = etiqueta;
            boton.setAttribute('aria-label', etiqueta);
        });
    }

    function actualizarBotonesTexto() {
        const nivel = NIVELES_TEXTO[nivelTextoActual];
        const etiqueta = `Tamaño de texto: ${nivel.nombre}. Pulse para cambiarlo`;

        document.querySelectorAll('[data-accion="tamano-texto"]').forEach((boton) => {
            boton.title = etiqueta;
            boton.setAttribute('aria-label', etiqueta);
        });
    }

    function aplicarTema(tema) {
        temaActual = tema === 'claro' ? 'claro' : 'oscuro';

        // Bootstrap 5.3 usa data-bs-theme para sus propios componentes
        // (botón de cerrar, alertas, selects) y estilos.css lo usa para
        // redefinir la paleta en el tema claro.
        document.documentElement.setAttribute('data-bs-theme', temaActual === 'claro' ? 'light' : 'dark');
        actualizarBotonesTema();
    }

    function aplicarNivelTexto(nivel) {
        nivelTextoActual = nivel >= 0 && nivel < NIVELES_TEXTO.length ? nivel : 0;

        document.documentElement.style.setProperty(
            '--tamano-base',
            `${NIVELES_TEXTO[nivelTextoActual].porcentaje}%`
        );
        actualizarBotonesTexto();
    }

    // Preferencia guardada, aplicada de inmediato (antes de pintar).
    aplicarTema(leerPreferencia(CLAVE_TEMA));
    aplicarNivelTexto(Number.parseInt(leerPreferencia(CLAVE_TEXTO), 10));

    // Los botones aún no existen cuando corre este script en el <head>:
    // se actualizan cuando el documento termina de cargar.
    document.addEventListener('DOMContentLoaded', () => {
        actualizarBotonesTema();
        actualizarBotonesTexto();
    });

    document.addEventListener('click', (evento) => {
        const botonTema = evento.target.closest('[data-accion="alternar-tema"]');

        if (botonTema) {
            aplicarTema(temaActual === 'claro' ? 'oscuro' : 'claro');
            guardarPreferencia(CLAVE_TEMA, temaActual);
            return;
        }

        const botonTexto = evento.target.closest('[data-accion="tamano-texto"]');

        if (botonTexto) {
            aplicarNivelTexto((nivelTextoActual + 1) % NIVELES_TEXTO.length);
            guardarPreferencia(CLAVE_TEXTO, String(nivelTextoActual));

            // index.html y admin.html tienen su propio sistema de avisos.
            if (typeof mostrarAlertaGlobal === 'function') {
                mostrarAlertaGlobal(`Tamaño de texto: ${NIVELES_TEXTO[nivelTextoActual].nombre}.`, 'info');
            }
        }
    });
})();
