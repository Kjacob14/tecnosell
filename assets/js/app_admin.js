/**
 * app_admin.js
 *
 * Lógica asíncrona del panel administrativo.
 *
 * Proyecto:
 * Sistema Integral E-Commerce y Gestión de Inventario
 *
 * Reglas seguidas del Documento Maestro:
 * - Vanilla JavaScript nativo. Sin jQuery, sin Axios, sin frameworks.
 * - Todas las peticiones usan fetch() nativo.
 * - Cualquier 403 recibido desde /api/admin/ redirige a login.html.
 * - La creación de productos usa FormData() sin fijar Content-Type
 *   manualmente, para que el navegador establezca el boundary.
 */

'use strict';

/* =============================================================
 * CONFIGURACIÓN BASE
 * ============================================================= */

const API_BASE = '/tienda_electronica/api';

const ENDPOINTS = {
    logout: `${API_BASE}/auth/logout_controller.php`,
    categorias: `${API_BASE}/admin/categorias_controller.php`,
    productos: `${API_BASE}/admin/productos_controller.php`,
    proveedores: `${API_BASE}/admin/proveedores_controller.php`,
    pedidos: `${API_BASE}/admin/pedidos_admin_controller.php`
};

// Estado en memoria de categorías, usado para llenar el <select> de productos.
let categoriasCache = [];

// Proveedores en memoria: llenan el <select> de productos y el buscador.
let proveedoresCache = [];


/* =============================================================
 * HELPER CENTRAL DE PETICIONES
 * ============================================================= */

/**
 * Ejecuta un fetch() hacia el backend y homogeneiza el manejo
 * de errores.
 *
 * - Si la respuesta es 403 y la URL pertenece a /api/admin/,
 *   se interpreta como pérdida de sesión o falta de privilegios
 *   y se redirige de inmediato a login.html.
 * - Siempre intenta parsear JSON, incluso en respuestas de error,
 *   porque todos los controladores devuelven JSON consistente.
 *
 * @param {string} url
 * @param {RequestInit} opciones
 * @returns {Promise<{ok: boolean, status: number, data: any}>}
 */
async function peticionApi(url, opciones = {}) {
    let respuesta;

    try {
        respuesta = await fetch(url, {
            // Las cookies de sesión (PHPSESSID) deben viajar siempre,
            // incluso si en algún despliegue frontend y backend
            // quedan en orígenes distintos.
            credentials: 'same-origin',
            ...opciones
        });
    } catch (errorRed) {
        // Fallo de red (servidor caído, sin conexión, CORS, etc.)
        return {
            ok: false,
            status: 0,
            data: {
                success: false,
                message: 'No fue posible contactar al servidor. Verifique su conexión.'
            }
        };
    }

    // Regla de protección de rutas: 403 en /tienda_electronica/api/admin/ -> login.html
    if (respuesta.status === 403 && url.includes('/tienda_electronica/api/admin/')) {
        window.location.href = 'login.html';
        // Devolvemos una promesa que nunca se resuelve para el
        // llamador, ya que estamos abandonando la página.
        return new Promise(() => {});
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

/**
 * Muestra un mensaje dentro de un contenedor de alerta Bootstrap.
 *
 * @param {HTMLElement} contenedor
 * @param {string} mensaje
 * @param {'danger'|'success'|'warning'} tipo
 */
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

/**
 * Activa o desactiva el estado de "cargando" de un botón,
 * mostrando su spinner interno si existe.
 *
 * @param {HTMLButtonElement} boton
 * @param {HTMLElement|null} spinner
 * @param {boolean} cargando
 */
function alternarCargaBoton(boton, spinner, cargando) {
    if (!boton) {
        return;
    }

    boton.disabled = cargando;

    if (spinner) {
        spinner.classList.toggle('d-none', !cargando);
    }
}

function escaparHtml(texto) {
    const div = document.createElement('div');
    div.textContent = texto ?? '';
    return div.innerHTML;
}

function formatearMoneda(valor) {
    const numero = Number(valor);

    if (Number.isNaN(numero)) {
        return valor;
    }

    return numero.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN'
    });
}


/* =============================================================
 * LOGOUT (barra superior de admin.html)
 * ============================================================= */

function inicializarLogout() {
    const btnLogout = document.getElementById('btnLogout');

    if (!btnLogout) {
        return;
    }

    btnLogout.addEventListener('click', async () => {
        btnLogout.disabled = true;

        await peticionApi(ENDPOINTS.logout, { method: 'POST' });

        // Independientemente de la respuesta, regresamos al login:
        // si logout falló por sesión ya inexistente, el resultado
        // deseado (no seguir en el panel) es el mismo.
        window.location.href = 'login.html';
    });
}


/* =============================================================
 * CATEGORÍAS (tab-categorias)
 * ============================================================= */

async function cargarCategorias() {
    const tabla = document.getElementById('tablaCategorias');

    const { ok, data } = await peticionApi(ENDPOINTS.categorias, {
        method: 'GET'
    });

    if (!ok) {
        tabla.innerHTML = `
            <tr>
                <td colspan="3" class="text-center text-danger">
                    ${escaparHtml(data.message || 'No fue posible cargar las categorías.')}
                </td>
            </tr>
        `;
        return;
    }

    categoriasCache = data.categorias || [];
    renderizarTablaCategorias(categoriasCache);
    llenarSelectCategorias(categoriasCache);
}

function renderizarTablaCategorias(categorias) {
    const tabla = document.getElementById('tablaCategorias');

    if (categorias.length === 0) {
        tabla.innerHTML = `
            <tr>
                <td colspan="3" class="text-center text-muted">
                    No hay categorías registradas.
                </td>
            </tr>
        `;
        return;
    }

    tabla.innerHTML = categorias.map((categoria) => `
        <tr>
            <td>${categoria.id_categoria}</td>
            <td>${escaparHtml(categoria.nombre_categoria)}</td>
            <td class="text-end">
                <button
                    class="btn btn-sm btn-outline-primary btn-editar-categoria"
                    data-id="${categoria.id_categoria}"
                    data-nombre="${escaparHtml(categoria.nombre_categoria)}"
                >
                    Editar
                </button>
                <button
                    class="btn btn-sm btn-outline-danger btn-eliminar-categoria"
                    data-id="${categoria.id_categoria}"
                >
                    Eliminar
                </button>
            </td>
        </tr>
    `).join('');
}

function llenarSelectCategorias(categorias) {
    const select = document.getElementById('productoCategoria');

    if (!select) {
        return;
    }

    const valorActual = select.value;

    select.innerHTML = '<option value="">Sin categoría</option>' +
        categorias.map((categoria) => `
            <option value="${categoria.id_categoria}">
                ${escaparHtml(categoria.nombre_categoria)}
            </option>
        `).join('');

    select.value = valorActual;
}

function inicializarFormularioCategoria() {
    const formCategoria = document.getElementById('formCategoria');

    if (!formCategoria) {
        return;
    }

    const modalElemento = document.getElementById('modalCategoria');
    const modalCategoria = new bootstrap.Modal(modalElemento);
    const alertaFormCategoria = document.getElementById('alertaFormCategoria');
    const btnGuardar = document.getElementById('btnGuardarCategoria');
    const spinner = document.getElementById('spinnerCategoria');

    // Nueva categoría: reinicia el formulario al modal.
    document.getElementById('btnNuevaCategoria').addEventListener('click', () => {
        formCategoria.reset();
        document.getElementById('categoriaId').value = '';
        document.getElementById('tituloModalCategoria').textContent = 'Nueva categoría';
        ocultarAlerta(alertaFormCategoria);
    });

    // Delegación de eventos para los botones "Editar" de la tabla,
    // ya que las filas se regeneran dinámicamente.
    document.getElementById('tablaCategorias').addEventListener('click', (evento) => {
        const boton = evento.target.closest('.btn-editar-categoria');

        if (!boton) {
            return;
        }

        document.getElementById('categoriaId').value = boton.dataset.id;
        document.getElementById('categoriaNombre').value = boton.dataset.nombre;
        document.getElementById('tituloModalCategoria').textContent = 'Editar categoría';
        ocultarAlerta(alertaFormCategoria);

        modalCategoria.show();
    });

    formCategoria.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        ocultarAlerta(alertaFormCategoria);

        const idCategoria = document.getElementById('categoriaId').value;
        const nombreCategoria = document.getElementById('categoriaNombre').value.trim();

        if (nombreCategoria === '') {
            mostrarAlerta(alertaFormCategoria, 'El nombre de la categoría es obligatorio.');
            return;
        }

        const esEdicion = idCategoria !== '';
        const url = esEdicion
            ? `${ENDPOINTS.categorias}?id=${encodeURIComponent(idCategoria)}`
            : ENDPOINTS.categorias;

        alternarCargaBoton(btnGuardar, spinner, true);

        const { ok, data } = await peticionApi(url, {
            method: esEdicion ? 'PUT' : 'POST',
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            },
            body: JSON.stringify({ nombre_categoria: nombreCategoria })
        });

        alternarCargaBoton(btnGuardar, spinner, false);

        if (!ok) {
            mostrarAlerta(alertaFormCategoria, data.message || 'No fue posible guardar la categoría.');
            return;
        }

        modalCategoria.hide();
        await cargarCategorias();
    });

    document.getElementById('tablaCategorias').addEventListener('click', (evento) => {
        const boton = evento.target.closest('.btn-eliminar-categoria');

        if (!boton) {
            return;
        }

        solicitarConfirmacion(
            '¿Eliminar esta categoría? Los productos asociados quedarán sin categoría.',
            async () => {
                const { ok, data } = await peticionApi(
                    `${ENDPOINTS.categorias}?id=${encodeURIComponent(boton.dataset.id)}`,
                    { method: 'DELETE' }
                );

                if (!ok) {
                    mostrarAlertaGlobal(data.message || 'No fue posible eliminar la categoría.', 'danger');
                    return;
                }

                mostrarAlertaGlobal('Categoría eliminada correctamente.', 'success');
                await cargarCategorias();
            }
        );
    });
}


/* =============================================================
 * PROVEEDORES (tab-proveedores)
 * ============================================================= */

async function cargarProveedores() {
    const tabla = document.getElementById('tablaProveedores');

    const { ok, data } = await peticionApi(ENDPOINTS.proveedores, {
        method: 'GET'
    });

    if (!ok) {
        tabla.innerHTML = `
            <tr>
                <td colspan="7" class="text-center text-danger">
                    ${escaparHtml(data.message || 'No fue posible cargar los proveedores.')}
                </td>
            </tr>
        `;
        return;
    }

    proveedoresCache = data.proveedores || [];
    aplicarFiltroProveedores();
    llenarSelectProveedores(proveedoresCache);
}

/** Filtra en memoria por empresa, contacto o correo (sin ir al servidor). */
function aplicarFiltroProveedores() {
    const buscador = document.getElementById('buscadorProveedores');
    const texto = buscador ? buscador.value.trim().toLowerCase() : '';

    const filtrados = texto === ''
        ? proveedoresCache
        : proveedoresCache.filter((proveedor) =>
            [proveedor.nombre_empresa, proveedor.contacto, proveedor.email]
                .some((campo) => (campo || '').toLowerCase().includes(texto))
        );

    renderizarTablaProveedores(filtrados, texto !== '');
}

function renderizarTablaProveedores(proveedores, hayBusqueda) {
    const tabla = document.getElementById('tablaProveedores');

    if (proveedores.length === 0) {
        tabla.innerHTML = `
            <tr>
                <td colspan="7" class="text-center text-muted">
                    ${hayBusqueda ? 'Ningún proveedor coincide con la búsqueda.' : 'No hay proveedores registrados.'}
                </td>
            </tr>
        `;
        return;
    }

    tabla.innerHTML = proveedores.map((proveedor) => `
        <tr>
            <td>${proveedor.id_proveedor}</td>
            <td>${escaparHtml(proveedor.nombre_empresa)}</td>
            <td>${escaparHtml(proveedor.contacto || '—')}</td>
            <td>${escaparHtml(proveedor.telefono || '—')}</td>
            <td>${escaparHtml(proveedor.email || '—')}</td>
            <td>
                <span class="estado-pedido ${proveedor.estado ? 'estado-pedido--activo' : ''}">
                    ${proveedor.estado ? 'Activo' : 'Inactivo'}
                </span>
            </td>
            <td class="text-end">
                <button
                    class="btn btn-sm btn-outline-primary btn-editar-proveedor"
                    data-id="${proveedor.id_proveedor}"
                >
                    Editar
                </button>
                <button
                    class="btn btn-sm btn-outline-danger btn-eliminar-proveedor"
                    data-id="${proveedor.id_proveedor}"
                >
                    Eliminar
                </button>
            </td>
        </tr>
    `).join('');
}

function llenarSelectProveedores(proveedores) {
    const select = document.getElementById('productoProveedor');

    if (!select) {
        return;
    }

    const valorActual = select.value;

    // Se listan todos: si un producto tiene un proveedor inactivo,
    // debe seguir apareciendo para no perderlo al guardar.
    select.innerHTML = '<option value="">Sin proveedor</option>' +
        proveedores.map((proveedor) => `
            <option value="${proveedor.id_proveedor}">
                ${escaparHtml(proveedor.nombre_empresa)}${proveedor.estado ? '' : ' (inactivo)'}
            </option>
        `).join('');

    select.value = valorActual;
}

function inicializarFormularioProveedor() {
    const formProveedor = document.getElementById('formProveedor');

    if (!formProveedor) {
        return;
    }

    const modalProveedor = new bootstrap.Modal(document.getElementById('modalProveedor'));
    const alertaForm = document.getElementById('alertaFormProveedor');
    const btnGuardar = document.getElementById('btnGuardarProveedor');
    const spinner = document.getElementById('spinnerProveedor');

    document.getElementById('buscadorProveedores').addEventListener('input', aplicarFiltroProveedores);

    document.getElementById('btnNuevoProveedor').addEventListener('click', () => {
        formProveedor.reset();
        document.getElementById('proveedorId').value = '';
        document.getElementById('tituloModalProveedor').textContent = 'Nuevo proveedor';
        ocultarAlerta(alertaForm);
    });

    document.getElementById('tablaProveedores').addEventListener('click', (evento) => {
        const botonEditar = evento.target.closest('.btn-editar-proveedor');

        if (botonEditar) {
            const proveedor = proveedoresCache.find(
                (item) => String(item.id_proveedor) === botonEditar.dataset.id
            );

            if (!proveedor) {
                return;
            }

            document.getElementById('proveedorId').value = proveedor.id_proveedor;
            document.getElementById('proveedorEmpresa').value = proveedor.nombre_empresa;
            document.getElementById('proveedorContacto').value = proveedor.contacto || '';
            document.getElementById('proveedorTelefono').value = proveedor.telefono || '';
            document.getElementById('proveedorEmail').value = proveedor.email || '';
            document.getElementById('proveedorDireccion').value = proveedor.direccion || '';
            document.getElementById('proveedorEstado').value = String(proveedor.estado);
            document.getElementById('tituloModalProveedor').textContent = 'Editar proveedor';
            ocultarAlerta(alertaForm);

            modalProveedor.show();
            return;
        }

        const botonEliminar = evento.target.closest('.btn-eliminar-proveedor');

        if (botonEliminar) {
            solicitarConfirmacion(
                '¿Eliminar este proveedor? Los productos asociados quedarán sin proveedor.',
                async () => {
                    const { ok, data } = await peticionApi(
                        `${ENDPOINTS.proveedores}?id=${encodeURIComponent(botonEliminar.dataset.id)}`,
                        { method: 'DELETE' }
                    );

                    if (!ok) {
                        mostrarAlertaGlobal(data.message || 'No fue posible eliminar el proveedor.', 'danger');
                        return;
                    }

                    mostrarAlertaGlobal('Proveedor eliminado correctamente.', 'success');
                    await cargarProveedores();
                    await cargarProductos();
                }
            );
        }
    });

    formProveedor.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        ocultarAlerta(alertaForm);

        const idProveedor = document.getElementById('proveedorId').value;
        const esEdicion = idProveedor !== '';

        const cuerpo = {
            nombre_empresa: document.getElementById('proveedorEmpresa').value.trim(),
            contacto: document.getElementById('proveedorContacto').value.trim(),
            telefono: document.getElementById('proveedorTelefono').value.trim(),
            email: document.getElementById('proveedorEmail').value.trim(),
            direccion: document.getElementById('proveedorDireccion').value.trim(),
            estado: Number(document.getElementById('proveedorEstado').value)
        };

        // Mismas reglas que el servidor; el servidor sigue siendo quien decide.
        if (cuerpo.nombre_empresa === '') {
            mostrarAlerta(alertaForm, 'El nombre de la empresa es obligatorio.');
            return;
        }

        if (cuerpo.email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cuerpo.email)) {
            mostrarAlerta(alertaForm, 'El correo no tiene un formato válido.');
            return;
        }

        if (cuerpo.telefono !== '' && !/^[0-9+()\-\s]{7,20}$/.test(cuerpo.telefono)) {
            mostrarAlerta(alertaForm, 'El teléfono solo puede tener números, espacios, guiones, paréntesis y +, con al menos 7 caracteres.');
            return;
        }

        const url = esEdicion
            ? `${ENDPOINTS.proveedores}?id=${encodeURIComponent(idProveedor)}`
            : ENDPOINTS.proveedores;

        alternarCargaBoton(btnGuardar, spinner, true);

        const { ok, data } = await peticionApi(url, {
            method: esEdicion ? 'PUT' : 'POST',
            headers: {
                'Content-Type': 'application/json; charset=utf-8'
            },
            body: JSON.stringify(cuerpo)
        });

        alternarCargaBoton(btnGuardar, spinner, false);

        if (!ok) {
            mostrarAlerta(alertaForm, data.message || 'No fue posible guardar el proveedor.');
            return;
        }

        modalProveedor.hide();
        await cargarProveedores();
        await cargarProductos();
    });
}


/* =============================================================
 * PRODUCTOS (tab-productos)
 * ============================================================= */

async function cargarProductos() {
    const tabla = document.getElementById('tablaProductos');

    const { ok, data } = await peticionApi(ENDPOINTS.productos, {
        method: 'GET'
    });

    if (!ok) {
        tabla.innerHTML = `
            <tr>
                <td colspan="9" class="text-center text-danger">
                    ${escaparHtml(data.message || 'No fue posible cargar los productos.')}
                </td>
            </tr>
        `;
        return;
    }

    renderizarTablaProductos(data.productos || []);
}

function renderizarTablaProductos(productos) {
    const tabla = document.getElementById('tablaProductos');

    if (productos.length === 0) {
        tabla.innerHTML = `
            <tr>
                <td colspan="9" class="text-center text-muted">
                    No hay productos registrados.
                </td>
            </tr>
        `;
        return;
    }

    tabla.innerHTML = productos.map((producto) => `
        <tr>
            <td>${producto.id_producto}</td>
            <td>
                ${producto.imagen
                    ? `<img src="uploads/${escaparHtml(producto.imagen)}" alt="${escaparHtml(producto.nombre)}" style="width:48px;height:48px;object-fit:cover;" class="rounded">`
                    : '<span class="text-muted">Sin imagen</span>'
                }
            </td>
            <td>${escaparHtml(producto.nombre)}</td>
            <td>${escaparHtml(producto.nombre_categoria || 'Sin categoría')}</td>
            <td>${escaparHtml(producto.nombre_proveedor || 'Sin proveedor')}</td>
            <td>${formatearMoneda(producto.precio)}</td>
            <td>${producto.stock}</td>
            <td>
                <span class="estado-pedido ${producto.estado ? 'estado-pedido--activo' : ''}">
                    ${producto.estado ? 'Activo' : 'Inactivo'}
                </span>
            </td>
            <td class="text-end">
                <button
                    class="btn btn-sm btn-outline-primary btn-editar-producto"
                    data-producto='${JSON.stringify(producto).replace(/'/g, '&apos;')}'
                >
                    Editar
                </button>
                <button
                    class="btn btn-sm btn-outline-danger btn-eliminar-producto"
                    data-id="${producto.id_producto}"
                >
                    Eliminar
                </button>
            </td>
        </tr>
    `).join('');
}

function inicializarFormularioProducto() {
    const formProducto = document.getElementById('formProducto');

    if (!formProducto) {
        return;
    }

    const modalElemento = document.getElementById('modalProducto');
    const modalProducto = new bootstrap.Modal(modalElemento);
    const alertaFormProducto = document.getElementById('alertaFormProducto');
    const btnGuardar = document.getElementById('btnGuardarProducto');
    const spinner = document.getElementById('spinnerProducto');
    const inputImagen = document.getElementById('productoImagen');
    const ayudaImagen = document.getElementById('ayudaImagenProducto');

    document.getElementById('btnNuevoProducto').addEventListener('click', () => {
        formProducto.reset();
        document.getElementById('productoId').value = '';
        document.getElementById('tituloModalProducto').textContent = 'Nuevo producto';
        inputImagen.disabled = false;
        ayudaImagen.textContent = '';
        ocultarAlerta(alertaFormProducto);
    });

    document.getElementById('tablaProductos').addEventListener('click', (evento) => {
        const botonEditar = evento.target.closest('.btn-editar-producto');

        if (botonEditar) {
            const producto = JSON.parse(botonEditar.dataset.producto.replace(/&apos;/g, "'"));

            document.getElementById('productoId').value = producto.id_producto;
            document.getElementById('productoNombre').value = producto.nombre;
            document.getElementById('productoDescripcion').value = producto.descripcion || '';
            document.getElementById('productoPrecio').value = producto.precio;
            document.getElementById('productoStock').value = producto.stock;
            document.getElementById('productoCategoria').value = producto.id_categoria || '';
            document.getElementById('productoProveedor').value = producto.id_proveedor || '';
            document.getElementById('productoEstado').value = String(producto.estado);
            document.getElementById('tituloModalProducto').textContent = 'Editar producto';

            /*
             * productos_controller.php ahora acepta reemplazo de
             * imagen también en edición: POST con multipart/form-data
             * e "id_producto" presente ejecuta un UPDATE. Si no se
             * selecciona un archivo nuevo, el backend conserva la
             * imagen actual.
             */
            inputImagen.value = '';
            inputImagen.disabled = false;
            ayudaImagen.textContent = 'Deje este campo vacío para conservar la imagen actual.';

            ocultarAlerta(alertaFormProducto);
            modalProducto.show();
            return;
        }

        const botonEliminar = evento.target.closest('.btn-eliminar-producto');

        if (botonEliminar) {
            solicitarConfirmacion(
                '¿Eliminar este producto? Esta acción no se puede deshacer.',
                async () => {
                    const { ok, data } = await peticionApi(
                        `${ENDPOINTS.productos}?id=${encodeURIComponent(botonEliminar.dataset.id)}`,
                        { method: 'DELETE' }
                    );

                    if (!ok) {
                        mostrarAlertaGlobal(
                            data.message || 'No fue posible eliminar el producto.',
                            'danger'
                        );
                        return;
                    }

                    mostrarAlertaGlobal('Producto eliminado correctamente.', 'success');
                    await cargarProductos();
                }
            );
        }
    });

    formProducto.addEventListener('submit', async (evento) => {
        evento.preventDefault();
        ocultarAlerta(alertaFormProducto);

        const idProducto = document.getElementById('productoId').value;
        const esEdicion = idProducto !== '';

        const nombre = document.getElementById('productoNombre').value.trim();
        const descripcion = document.getElementById('productoDescripcion').value.trim();
        const precio = document.getElementById('productoPrecio').value;
        const stock = document.getElementById('productoStock').value;
        const idCategoria = document.getElementById('productoCategoria').value;
        const idProveedor = document.getElementById('productoProveedor').value;
        const estado = document.getElementById('productoEstado').value;

        if (nombre === '' || precio === '' || stock === '') {
            mostrarAlerta(alertaFormProducto, 'Nombre, precio y stock son obligatorios.');
            return;
        }

        alternarCargaBoton(btnGuardar, spinner, true);

        /*
         * Tanto la creación como la edición se envían por POST
         * mediante FormData(), sin fijar Content-Type manualmente
         * (el navegador agrega el boundary correcto).
         *
         * El backend enruta internamente según la presencia de
         * "id_producto": si viene y es válido, ejecuta un UPDATE;
         * si no, ejecuta un INSERT. Todos los campos de validación
         * (nombre, precio, stock, etc.) son obligatorios en ambos
         * casos según el contrato actual del controlador, por lo
         * que siempre se envían completos, no solo los que cambiaron.
         */
        const formData = new FormData();

        if (esEdicion) {
            formData.append('id_producto', idProducto);
        }

        formData.append('nombre', nombre);
        formData.append('descripcion', descripcion);
        formData.append('precio', precio);
        formData.append('stock', stock);
        formData.append('estado', estado);

        if (idCategoria !== '') {
            formData.append('id_categoria', idCategoria);
        }

        // Sin proveedor: no se envía el campo y el backend guarda NULL.
        if (idProveedor !== '') {
            formData.append('id_proveedor', idProveedor);
        }

        // La imagen es opcional en ambos casos: en edición, si se
        // omite, el backend conserva la imagen ya existente.
        if (inputImagen.files[0]) {
            formData.append('imagen', inputImagen.files[0]);
        }

        const resultado = await peticionApi(ENDPOINTS.productos, {
            method: 'POST',
            body: formData
        });

        alternarCargaBoton(btnGuardar, spinner, false);

        if (!resultado.ok) {
            mostrarAlerta(
                alertaFormProducto,
                resultado.data.message || 'No fue posible guardar el producto.'
            );
            return;
        }

        modalProducto.hide();
        await cargarProductos();
    });
}


/* =============================================================
 * PEDIDOS (tab-pedidos)
 * ============================================================= */

let idPedidoAbierto = null;

async function cargarPedidos() {
    const tabla = document.getElementById('tablaPedidos');

    const { ok, data } = await peticionApi(ENDPOINTS.pedidos, {
        method: 'GET'
    });

    if (!ok) {
        tabla.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-danger">
                    ${escaparHtml(data.message || 'No fue posible cargar los pedidos.')}
                </td>
            </tr>
        `;
        return;
    }

    renderizarTablaPedidos(data.pedidos || []);
}

function renderizarTablaPedidos(pedidos) {
    const tabla = document.getElementById('tablaPedidos');

    if (pedidos.length === 0) {
        tabla.innerHTML = `
            <tr>
                <td colspan="6" class="text-center text-muted">
                    No hay pedidos registrados.
                </td>
            </tr>
        `;
        return;
    }

    tabla.innerHTML = pedidos.map((pedido) => `
        <tr>
            <td>${pedido.id_pedido}</td>
            <td>${escaparHtml(pedido.nombre)}</td>
            <td>${formatearMoneda(pedido.total)}</td>
            <td>
                <span class="estado-pedido estado-pedido--${escaparHtml(pedido.estado_pedido)}">
                    ${escaparHtml(pedido.estado_pedido)}
                </span>
            </td>
            <td>${escaparHtml(pedido.fecha_pedido)}</td>
            <td class="text-end">
                <button
                    class="btn btn-sm btn-outline-primary btn-ver-pedido"
                    data-id="${pedido.id_pedido}"
                >
                    Ver detalle
                </button>
            </td>
        </tr>
    `).join('');
}

function inicializarPedidos() {
    const tabla = document.getElementById('tablaPedidos');

    if (!tabla) {
        return;
    }

    const modalDetalle = new bootstrap.Modal(document.getElementById('modalDetallePedido'));
    const contenido = document.getElementById('contenidoDetallePedido');
    const selectEstado = document.getElementById('selectEstadoPedido');

    tabla.addEventListener('click', async (evento) => {
        const boton = evento.target.closest('.btn-ver-pedido');

        if (!boton) {
            return;
        }

        idPedidoAbierto = boton.dataset.id;
        contenido.innerHTML = '<p class="text-muted text-center">Cargando...</p>';
        modalDetalle.show();

        const { ok, data } = await peticionApi(
            `${ENDPOINTS.pedidos}?id=${encodeURIComponent(idPedidoAbierto)}`,
            { method: 'GET' }
        );

        if (!ok) {
            contenido.innerHTML = `
                <p class="text-danger text-center">
                    ${escaparHtml(data.message || 'No fue posible cargar el pedido.')}
                </p>
            `;
            return;
        }

        const pedido = data.pedido;
        selectEstado.value = pedido.estado_pedido;

        const filasDetalle = (pedido.detalles || []).map((detalle) => `
            <tr>
                <td>${escaparHtml(detalle.nombre_producto)}</td>
                <td>${detalle.cantidad}</td>
                <td>${formatearMoneda(detalle.precio_compra)}</td>
                <td>${formatearMoneda(detalle.precio_compra * detalle.cantidad)}</td>
            </tr>
        `).join('');

        contenido.innerHTML = `
            <p>
                <strong>Cliente:</strong> ${escaparHtml(pedido.nombre)}
                (${escaparHtml(pedido.email)})
            </p>
            <p>
                <strong>Total del pedido:</strong> ${formatearMoneda(pedido.total)}
            </p>
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Cantidad</th>
                        <th>Precio unitario</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>${filasDetalle}</tbody>
            </table>
        `;
    });

    document.getElementById('btnActualizarEstadoPedido').addEventListener('click', async () => {
        if (!idPedidoAbierto) {
            return;
        }

        const nuevoEstado = selectEstado.value;

        const { ok, data } = await peticionApi(
            `${ENDPOINTS.pedidos}?id=${encodeURIComponent(idPedidoAbierto)}`,
            {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json; charset=utf-8'
                },
                body: JSON.stringify({ estado_pedido: nuevoEstado })
            }
        );

        if (!ok) {
            mostrarAlertaGlobal(data.message || 'No fue posible actualizar el estado.', 'danger');
            return;
        }

        modalDetalle.hide();
        mostrarAlertaGlobal('Estado del pedido actualizado correctamente.', 'success');
        await cargarPedidos();
    });
}


/* =============================================================
 * CONFIRMACIÓN GENÉRICA Y ALERTA GLOBAL
 * ============================================================= */

let accionConfirmacionPendiente = null;

function solicitarConfirmacion(mensaje, accion) {
    document.getElementById('mensajeConfirmacion').textContent = mensaje;
    accionConfirmacionPendiente = accion;

    const modal = new bootstrap.Modal(document.getElementById('modalConfirmacion'));
    modal.show();
}

function inicializarConfirmacionGenerica() {
    const btnConfirmar = document.getElementById('btnConfirmarAccion');

    if (!btnConfirmar) {
        return;
    }

    btnConfirmar.addEventListener('click', async () => {
        const modalElemento = document.getElementById('modalConfirmacion');
        const modal = bootstrap.Modal.getInstance(modalElemento);

        if (accionConfirmacionPendiente) {
            await accionConfirmacionPendiente();
        }

        accionConfirmacionPendiente = null;
        modal.hide();
    });
}

function mostrarAlertaGlobal(mensaje, tipo = 'danger') {
    const contenedor = document.getElementById('alertaGlobal');

    if (!contenedor) {
        return;
    }

    mostrarAlerta(contenedor, mensaje, tipo);

    window.setTimeout(() => {
        ocultarAlerta(contenedor);
    }, 4000);
}


/* =============================================================
 * PUNTO DE ENTRADA
 * ============================================================= */

document.addEventListener('DOMContentLoaded', () => {
    // admin.html
    if (document.getElementById('tablaProductos')) {
        inicializarLogout();
        inicializarFormularioCategoria();
        inicializarFormularioProducto();
        inicializarFormularioProveedor();
        inicializarPedidos();
        inicializarConfirmacionGenerica();

        cargarCategorias();
        cargarProveedores();
        cargarProductos();
        cargarPedidos();
    }
});
