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