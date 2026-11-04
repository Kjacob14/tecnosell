/**
 * imagen_respaldo.js
 *
 * Si la foto de un producto no carga (archivo que no existe en la carpeta
 * uploads, nombre mal escrito, etc.), la reemplaza por un marcador gris con
 * un ícono, en lugar del ícono de imagen rota del navegador.
 *
 * Se incluye en el <head> de index.html y admin.html. Usa un escuchador en
 * fase de captura porque el evento "error" de las imágenes no sube por el DOM,
 * así que también cubre las imágenes que se crean después desde JavaScript.
 */

(function () {
    'use strict';

    const MARCADOR = 'data:image/svg+xml;utf8,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120">' +
        '<rect width="120" height="120" fill="none"/>' +
        '<g fill="none" stroke="#8b93a7" stroke-width="5" stroke-linecap="round" stroke-linejoin="round">' +
        '<rect x="22" y="28" width="76" height="64" rx="8"/>' +
        '<circle cx="45" cy="52" r="7"/>' +
        '<path d="M22 80l24-22 18 16 12-10 22 20"/>' +
        '</g></svg>'
    );

    function usarRespaldo(imagen) {
        // Si el propio marcador fallara, no se reintenta (evita un ciclo infinito).
        if (imagen.dataset.respaldoAplicado === '1') {
            return;
        }

        imagen.dataset.respaldoAplicado = '1';
        imagen.classList.add('imagen-respaldo');
        imagen.src = MARCADOR;
    }

    document.addEventListener('error', (evento) => {
        if (evento.target instanceof HTMLImageElement) {
            usarRespaldo(evento.target);
        }
    }, true);

    // Imágenes que ya habían fallado antes de que este script estuviera listo.
    window.addEventListener('load', () => {
        document.querySelectorAll('img').forEach((imagen) => {
            if (imagen.complete && imagen.naturalWidth === 0 && imagen.getAttribute('src')) {
                usarRespaldo(imagen);
            }
        });
    });
})();
