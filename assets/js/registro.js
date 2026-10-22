'use strict';

const API_BASE = '/tienda_electronica/api';
const ENDPOINT_REGISTRO = `${API_BASE}/auth/registro_controller.php`;

async function peticionApi(url, opciones = {}) {
    let respuesta;
    try {
        respuesta = await fetch(url, {
            credentials: 'same-origin',
            ...opciones
        });
    } catch (errorRed) {
        return { ok: false, status: 0, data: { success: false, message: 'Fallo de red. Verifique su conexión.' } };
    }

    let data;
    try {
        data = await respuesta.json();
    } catch (e) {
        data = { success: false, message: 'Respuesta inesperada del servidor.' };
    }
    return { ok: respuesta.ok, status: respuesta.status, data };
}

function mostrarAlerta(contenedor, mensaje, tipo = 'danger') {
    if (!contenedor) return;
    contenedor.className = `alert alert-${tipo} mb-4`;
    contenedor.textContent = mensaje;
    contenedor.classList.remove('d-none');
}

document.addEventListener('DOMContentLoaded', () => {
    const formRegistro = document.getElementById('formRegistro');
    if (!formRegistro) return;

    const alertaRegistro = document.getElementById('alertaRegistro');
    const btnRegistro = document.getElementById('btnRegistro');
    const spinnerRegistro = document.getElementById('spinnerRegistro');

    // Requisitos de contraseña. Deben coincidir con requisitosFaltantes()
    // de api/auth/registro_controller.php: aquí solo se muestra el avance,
    // quien decide es el servidor.
    const REQUISITOS_PASSWORD = {
        longitud: (p) => Array.from(p).length >= 8,
        mayuscula: (p) => /\p{Lu}/u.test(p),
        numero: (p) => /\d/.test(p),
        especial: (p) => /[^\p{L}\p{N}\s]/u.test(p)
    };

    const inputPassword = document.getElementById('password');
    const listaRequisitos = document.getElementById('requisitosPassword');

    /**
     * Marca cada requisito como cumplido o pendiente según lo escrito.
     * @returns {boolean} true si se cumplen todos.
     */
    function actualizarRequisitos() {
        if (!listaRequisitos) {
            return true;
        }

        const valor = inputPassword.value;
        let todosCumplidos = true;

        listaRequisitos.querySelectorAll('li[data-requisito]').forEach((item) => {
            const cumple = REQUISITOS_PASSWORD[item.dataset.requisito](valor);

            todosCumplidos = todosCumplidos && cumple;
            item.classList.toggle('requisito--cumplido', cumple);
            item.querySelector('i').className = cumple ? 'bi bi-check-circle-fill' : 'bi bi-circle';
            item.querySelector('.estado-requisito').textContent = cumple ? '(cumplido)' : '(pendiente)';
        });

        return todosCumplidos;
    }

    inputPassword.addEventListener('input', actualizarRequisitos);

    formRegistro.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        alertaRegistro.classList.add('d-none');

        const nombre = document.getElementById('nombre').value.trim();
        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;

        if (nombre === '' || email === '' || password === '') {
            mostrarAlerta(alertaRegistro, 'Todos los campos son obligatorios.', 'danger');
            return;
        }

        if (!actualizarRequisitos()) {
            mostrarAlerta(alertaRegistro, 'La contraseña no cumple todos los requisitos de seguridad.', 'danger');
            inputPassword.focus();
            return;
        }

        btnRegistro.disabled = true;
        spinnerRegistro.classList.remove('d-none');

        const { ok, data } = await peticionApi(ENDPOINT_REGISTRO, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=utf-8' },
            body: JSON.stringify({ nombre, email, password })
        });

        btnRegistro.disabled = false;
        spinnerRegistro.classList.add('d-none');

        if (!ok) {
            mostrarAlerta(alertaRegistro, data.message || 'No fue posible crear la cuenta.', 'danger');
            return;
        }

        mostrarAlerta(alertaRegistro, '¡Cuenta creada con éxito! Redirigiendo...', 'success');
        
        // Redirigir al login después de 2 segundos
        setTimeout(() => {
            window.location.href = 'login.html';
        }, 2000);
    });
});