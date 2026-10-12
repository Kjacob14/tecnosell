/**
 * login.js
 *
 * Lógica exclusiva de la pantalla de acceso (login.html).
 *
 * Proyecto:
 * Sistema Integral E-Commerce y Gestión de Inventario
 *
 * Este archivo es independiente de app_admin.js y app_tienda.js:
 * login.html es un punto de entrada compartido por clientes y
 * administradores, así que no debe cargar la lógica completa de
 * ninguno de los dos paneles, solo el formulario de acceso y la
 * redirección según el rol devuelto por el backend.
 *
 * Reglas seguidas del Documento Maestro:
 * - Vanilla JavaScript nativo. Sin jQuery, sin Axios, sin frameworks.
 * - Todas las peticiones usan fetch() nativo.
 */

'use strict';

const API_BASE = '/tienda_electronica/api';
const ENDPOINT_LOGIN = `${API_BASE}/auth/login_controller.php`;


/* =============================================================
 * HELPER DE PETICIÓN (versión mínima, solo para login)
 * ============================================================= */

/**
 * @param {string} url
 * @param {RequestInit} opciones
 * @returns {Promise<{ok: boolean, status: number, data: any}>}
 */
async function peticionApi(url, opciones = {}) {
    let respuesta;

    try {
        respuesta = await fetch(url, {
            credentials: 'same-origin',
            ...opciones
        });
    } catch (errorRed) {
        return {
            ok: false,
            status: 0,
            data: {
                success: false,
                message: 'No fue posible contactar al servidor. Verifique su conexión.'
            }
        };
    }

    let data;

    try {
        data = await respuesta.json();
    } catch (errorParseo) {
        data = {
            success: false,
            message: 'El servidor devolvió una respuesta inesperada.'
        };
    }

    return {
        ok: respuesta.ok,
        status: respuesta.status,
        data
    };
}


/* =============================================================
 * UTILIDADES DE INTERFAZ
 * ============================================================= */

function mostrarAlerta(contenedor, mensaje, tipo = 'danger') {
    if (!contenedor) {
        return;
    }

    contenedor.className = `alert alert-${tipo}`;
    contenedor.textContent = mensaje;
    contenedor.classList.remove('d-none');
}

function ocultarAlerta(contenedor) {
    if (!contenedor) {
        return;
    }

    contenedor.classList.add('d-none');
    contenedor.textContent = '';
}

function alternarCargaBoton(boton, spinner, cargando) {
    if (!boton) {
        return;
    }

    boton.disabled = cargando;

    if (spinner) {
        spinner.classList.toggle('d-none', !cargando);
    }
}


/* =============================================================
 * LOGIN CON REDIRECCIÓN POR ROL
 * ============================================================= */

function inicializarLogin() {
    const formLogin = document.getElementById('formLogin');

    if (!formLogin) {
        return;
    }

    const alertaLogin = document.getElementById('alertaLogin');
    const btnLogin = document.getElementById('btnLogin');
    const spinnerLogin = document.getElementById('spinnerLogin');

    formLogin.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        ocultarAlerta(alertaLogin);

        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;

        if (email === '' || password === '') {
            mostrarAlerta(alertaLogin, 'Ingrese su correo y contraseña.');
            return;
        }

        alternarCargaBoton(btnLogin, spinnerLogin, true);

        const { ok, data } = await peticionApi(ENDPOINT_LOGIN, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            },
            body: JSON.stringify({ email, password })
        });

        alternarCargaBoton(btnLogin, spinnerLogin, false);

        if (!ok) {
            // 400 y 401 del login_controller.php traen "message" listo.
            mostrarAlerta(
                alertaLogin,
                data.message || 'No fue posible iniciar sesión.'
            );
            return;
        }

        /*
         * Punto de entrada único: login.html sirve tanto a
         * clientes como a administradores. El backend no
         * distingue quién puede iniciar sesión aquí (ambos
         * roles son válidos); es el frontend quien decide a
         * dónde redirigir según el rol devuelto.
         *
         * La seguridad real no depende de esta redirección:
         * cada controlador de /api/admin/ vuelve a validar
         * $_SESSION['rol'] === 'admin' y responde 403 si no
         * corresponde, así que un cliente que llegara a
         * admin.html manualmente no podría operar nada ahí.
         */
        if (data.usuario && data.usuario.rol === 'admin') {
            window.location.href = 'admin.html';
        } else {
            window.location.href = 'index.html';
        }
    });
}


document.addEventListener('DOMContentLoaded', inicializarLogin);
