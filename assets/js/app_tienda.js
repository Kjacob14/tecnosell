/**
 * app_tienda.js
 *
 * Lógica asíncrona de la tienda pública (index.html).
 *
 * Proyecto:
 * Sistema Integral E-Commerce y Gestión de Inventario
 *
 * Estado del Sprint 3:
 * - catalogo_controller.php: CONSUMIDO (auditado y operativo).
 * - carrito_controller.php: CONSUMIDO (auditado y operativo).
 *   Requiere sesión activa (401 si no hay sesión); el frontend
 *   redirige a login.html ante ese código.
 * - checkout_controller.php: CONSUMIDO (auditado y operativo).
 *   POST sin cuerpo; puede responder 200 (éxito), 400 (carrito
 *   vacío), 401 (sin sesión) o 409 (conflicto de stock/estado
 *   con array productos_agotados).
 *
 * Reglas seguidas del Documento Maestro:
 * - Vanilla JavaScript nativo. Sin jQuery, sin Axios, sin frameworks.
 * - Todas las peticiones usan fetch() nativo.
 */

'use strict';

function crearRouter(rutas, rutaPorDefecto) {
    return {
        iniciar: () => {
            const procesarHash = () => {
                const hash = window.location.hash.substring(1);
                const partes = hash.split('/');
                const ruta = partes[0] || rutaPorDefecto;
                const param = partes[1] || '';
                if (rutas[ruta]) rutas[ruta](param);
                else rutas[rutaPorDefecto]('');
            };
            window.addEventListener('hashchange', procesarHash);
            procesarHash();
        },
        navegar: (ruta) => window.location.hash = ruta
    };
}
/* =============================================================
 * CONFIGURACIÓN BASE
 * ============================================================= */

const API_BASE = '/tienda_electronica/api';

const ENDPOINTS = {
    catalogo: `${API_BASE}/cliente/catalogo_controller.php`,
    carrito: `${API_BASE}/cliente/carrito_controller.php`,
    checkout: `${API_BASE}/cliente/checkout_controller.php`,
    pedidos: `${API_BASE}/cliente/pedidos_controller.php`,
    logout: `${API_BASE}/auth/logout_controller.php`
};

// Cache del catálogo completo (sin filtrar), para derivar las
// categorías disponibles sin necesitar un endpoint público aparte.
let catalogoCompleto = [];
let categoriaActiva = '';


/* =============================================================
 * HELPER CENTRAL DE PETICIONES
 * ============================================================= */

/**
 * Ejecuta un fetch() hacia el backend y homogeneiza el manejo
 * de errores.
 *
 * carrito_controller.php exige sesión activa y responde 401
 * cuando no la hay. Por defecto, cualquier 401 recibido desde
 * ese endpoint redirige de inmediato a login.html, ya que ante
 * una interacción explícita del usuario (agregar, incrementar,
 * eliminar, abrir el offcanvas) no tiene sentido seguir
 * mostrando un carrito sin usuario autenticado.
 *
 * El parámetro silentFail=true desactiva esa redirección para
 * un caso puntual: la carga automática del carrito al entrar
 * a index.html. Ahí, un 401 no es un error que deba interrumpir
 * al visitante, sino la simple confirmación de que no hay
 * sesión activa; la promesa se resuelve igual, con ok=false,
 * para que el llamador decida en silencio (contador en cero).
 *
 * catalogo_controller.php es público y nunca debería responder
 * 401/403; si lo hiciera, se trata como cualquier otro error.
 *
 * @param {string} url
 * @param {RequestInit} opciones
 * @param {boolean} silentFail - si es true, un 401 de carrito no redirige.
 * @returns {Promise<{ok: boolean, status: number, data: any}>}
 */
async function peticionApi(url, opciones = {}, silentFail = false) {
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

    if (
        respuesta.status === 401 &&
        (
            url.includes('/tienda_electronica/api/cliente/carrito_controller.php') ||
            url.includes('/tienda_electronica/api/cliente/checkout_controller.php') ||
            url.includes('/tienda_electronica/api/cliente/pedidos_controller.php')
        ) &&
        !silentFail
    ) {
        redirigirALogin();
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

let redirigiendoALogin = false;

/**
 * Avisa que se necesita sesión y, tras un instante para que se lea el
 * aviso, lleva a login.html. La bandera evita avisos repetidos si
 * varias peticiones reciben 401 a la vez.
 */
function redirigirALogin() {
    if (redirigiendoALogin) {
        return;
    }

    redirigiendoALogin = true;
    mostrarAlertaGlobal('Inicia sesión para continuar.', 'warning');

    window.setTimeout(() => {
        window.location.href = 'login.html';
    }, 1200);
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

const ICONOS_TOAST = {
    success: 'bi-check-circle-fill',
    danger: 'bi-exclamation-octagon-fill',
    warning: 'bi-exclamation-triangle-fill',
    info: 'bi-info-circle-fill'
};
const MAX_TOASTS_VISIBLES = 4;
const DURACION_TOAST_MS = 4000;

/**
 * Muestra un aviso breve (toast) arriba a la derecha. Se cierra solo
 * a los 4 segundos o con su botón de cerrar.
 *
 * @param {string} mensaje
 * @param {'success'|'danger'|'warning'|'info'} tipo
 */
function mostrarAlertaGlobal(mensaje, tipo = 'danger') {
    const contenedor = document.getElementById('contenedorToasts');

    if (!contenedor) {
        return;
    }

    const tipoValido = ICONOS_TOAST[tipo] ? tipo : 'info';

    const toast = document.createElement('div');
    toast.className = `toast-tienda toast-tienda--${tipoValido}`;
    toast.setAttribute('role', tipoValido === 'danger' ? 'alert' : 'status');
    toast.innerHTML = `
        <i class="bi ${ICONOS_TOAST[tipoValido]} toast-tienda__icono"></i>
        <span class="toast-tienda__mensaje"></span>
        <button type="button" class="toast-tienda__cerrar" aria-label="Cerrar aviso">
            <i class="bi bi-x-lg"></i>
        </button>
    `;

    // textContent (no innerHTML): un mensaje que venga del servidor
    // nunca puede inyectar HTML en la página.
    toast.querySelector('.toast-tienda__mensaje').textContent = mensaje;

    const cerrar = () => {
        if (toast.classList.contains('toast-tienda--saliendo')) {
            return;
        }

        toast.classList.add('toast-tienda--saliendo');
        window.setTimeout(() => toast.remove(), 200);
    };

    toast.querySelector('.toast-tienda__cerrar').addEventListener('click', cerrar);

    contenedor.appendChild(toast);

    // Máximo de avisos a la vez: se descarta el más antiguo.
    while (contenedor.children.length > MAX_TOASTS_VISIBLES) {
        contenedor.firstElementChild.remove();
    }

    window.setTimeout(cerrar, DURACION_TOAST_MS);
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
 * CATÁLOGO
 * ============================================================= */

/**
 * Carga el catálogo completo una sola vez (sin filtro), lo
 * guarda en cache y deriva de él la lista de categorías
 * disponibles para pintar los botones de filtro.
 *
 * No se pide un endpoint de categorías aparte: categorias_controller.php
 * es exclusivo para administradores (403 sin sesión), así que las
 * categorías mostradas al público se derivan de los productos
 * realmente disponibles. Esto implica que una categoría sin stock
 * no aparecerá como filtro, lo cual es coherente con la regla de
 * negocio (el catálogo público solo expone productos con stock > 0).
 */
async function cargarCatalogoInicial() {
    const { ok, data } = await peticionApi(ENDPOINTS.catalogo, {
        method: 'GET'
    });

    if (!ok) {
        mostrarErrorCatalogo(data.message || 'No fue posible cargar el catálogo.');
        return;
    }

    catalogoCompleto = data.productos || [];
    renderizarFiltrosCategorias(catalogoCompleto);
    renderizarDropdownCategorias(catalogoCompleto);
    renderizarCatalogo(catalogoCompleto);
}

/**
 * Solicita el catálogo filtrado por categoría directamente al
 * backend (no se filtra en el frontend), ya que el contrato
 * de catalogo_controller.php soporta ?id_categoria= de forma
 * nativa y segura.
 *
 * @param {string} idCategoria - vacío significa "todas".
 */
async function cargarCatalogoPorCategoria(idCategoria) {
    const contenedor = document.getElementById('catalogoProductos');
    contenedor.innerHTML = `
        <div class="col-12 text-center text-muted py-5">
            Cargando catálogo...
        </div>
    `;

    const url = idCategoria
        ? `${ENDPOINTS.catalogo}?id_categoria=${encodeURIComponent(idCategoria)}`
        : ENDPOINTS.catalogo;

    const { ok, data } = await peticionApi(url, { method: 'GET' });

    if (!ok) {
        mostrarErrorCatalogo(data.message || 'No fue posible cargar el catálogo.');
        return;
    }

    renderizarCatalogo(data.productos || []);
}

function mostrarErrorCatalogo(mensaje) {
    const contenedor = document.getElementById('catalogoProductos');
    contenedor.innerHTML = `
        <div class="col-12 text-center text-danger py-5">
            ${escaparHtml(mensaje)}
        </div>
    `;
}

/**
 * Deriva el mapa de categorías únicas (id → nombre) presentes en
 * el catálogo recibido. Compartida entre renderizarFiltrosCategorias
 * (botones sobre el catálogo) y renderizarDropdownCategorias (menú
 * "Productos" de la navbar), para no calcularlo dos veces con
 * lógica duplicada que podría desincronizarse.
 *
 * @param {Array} productos
 * @returns {Map<number, string>}
 */
function obtenerCategoriasUnicas(productos) {
    const categoriasUnicas = new Map();

    productos.forEach((producto) => {
        if (producto.id_categoria !== null && !categoriasUnicas.has(producto.id_categoria)) {
            categoriasUnicas.set(producto.id_categoria, producto.nombre_categoria);
        }
    });

    return categoriasUnicas;
}

/**
 * Deriva las categorías únicas presentes en el catálogo recibido
 * y pinta los botones de filtro. Los productos sin categoría
 * (id_categoria === null) no generan un botón de filtro propio,
 * ya que no habría un id_categoria válido que enviar al backend.
 *
 * @param {Array} productos
 */
function renderizarFiltrosCategorias(productos) {
    const contenedor = document.getElementById('filtrosCategorias');
    const categoriasUnicas = obtenerCategoriasUnicas(productos);

    const botonesCategorias = Array.from(categoriasUnicas.entries())
        .map(([id, nombre]) => `
            <button
                class="filtro-categoria btn-filtro-categoria"
                data-id-categoria="${id}"
            >
                ${escaparHtml(nombre)}
            </button>
        `)
        .join('');

    contenedor.innerHTML = `
        <button
            class="filtro-categoria filtro-categoria--activo btn-filtro-categoria"
            data-id-categoria=""
        >
            Todas
        </button>
        ${botonesCategorias}
    `;
}

/**
 * Pinta el dropdown "Productos" de la navbar flotante, reutilizando
 * el mismo cálculo de categorías que los botones de filtro. Cada
 * ítem navega al catálogo con esa categoría ya aplicada.
 *
 * @param {Array} productos
 */
function renderizarDropdownCategorias(productos) {
    const menu = document.getElementById('menuProductosCategorias');

    if (!menu) {
        return;
    }

    const categoriasUnicas = obtenerCategoriasUnicas(productos);

    // Primer ítem fijo: lleva al catálogo completo, sin filtrar.
    const itemTodo = `
        <li>
            <button
                type="button"
                class="dropdown-item btn-dropdown-categoria"
                data-id-categoria=""
            >
                Todo el catálogo
            </button>
        </li>
    `;

    if (categoriasUnicas.size === 0) {
        menu.innerHTML = itemTodo;
        return;
    }

    menu.innerHTML = itemTodo + '<li><hr class="dropdown-divider"></li>' + Array.from(categoriasUnicas.entries())
        .map(([id, nombre]) => `
            <li>
                <button
                    type="button"
                    class="dropdown-item btn-dropdown-categoria"
                    data-id-categoria="${id}"
                >
                    ${escaparHtml(nombre)}
                </button>
            </li>
        `)
        .join('');
}

function inicializarDropdownCategorias() {
    const menu = document.getElementById('menuProductosCategorias');

    if (!menu) {
        return;
    }

    menu.addEventListener('click', (evento) => {
        const boton = evento.target.closest('.btn-dropdown-categoria');

        if (!boton) {
            return;
        }

        const idCategoria = boton.dataset.idCategoria;

        // Sincroniza también el estado visual de los botones de
        // filtro sobre el catálogo, para que ambos puntos de
        // entrada (dropdown y filtros) queden coherentes entre sí.
        document.querySelectorAll('.btn-filtro-categoria').forEach((btn) => {
            btn.classList.toggle(
                'filtro-categoria--activo',
                btn.dataset.idCategoria === idCategoria
            );
        });

        categoriaActiva = idCategoria;
        cargarCatalogoPorCategoria(idCategoria);

        // Desde Inicio o desde el detalle de un producto se cambia a la
        // vista del catálogo (el cambio de hash dispara el router). Si
        // ya se está en el catálogo, solo se sube al inicio de la lista.
        if (window.location.hash !== '#catalogo') {
            window.location.hash = 'catalogo';
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
}

/**
 * Pinta las tarjetas del catálogo en el DOM.
 *
 * @param {Array} productos
 */
function renderizarCatalogo(productos) {
    const contenedor = document.getElementById('catalogoProductos');

    if (productos.length === 0) {
        contenedor.innerHTML = `
            <div class="col-12 text-center text-muted py-5">
                No hay productos disponibles en esta categoría.
            </div>
        `;
        return;
    }

    contenedor.innerHTML = productos.map((producto) => `
        <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
            <div class="producto-card">

                ${producto.imagen
                    ? `<img
                        src="uploads/${escaparHtml(producto.imagen)}"
                        class="producto-card__imagen"
                        alt="${escaparHtml(producto.nombre)}"
                    >`
                    : `<div
                        class="producto-card__imagen d-flex align-items-center justify-content-center"
                    >
                        <span class="producto-card__stock">Sin imagen</span>
                    </div>`
                }

                <div class="producto-card__cuerpo d-flex flex-column">
                    <h6
                        class="producto-card__nombre"
                        title="${escaparHtml(producto.nombre)}"
                    >
                        ${escaparHtml(producto.nombre)}
                    </h6>

                    <p class="producto-card__categoria mb-1">
                        ${escaparHtml(producto.nombre_categoria || 'Sin categoría')}
                    </p>

                    <p class="producto-card__precio mb-1">
                        ${formatearMoneda(producto.precio)}
                    </p>

                    <p class="producto-card__stock ${producto.stock <= 3 ? 'producto-card__stock--bajo' : ''} mb-2">
                        ${producto.stock <= 3
                            ? `¡Solo quedan ${producto.stock}!`
                            : `Stock disponible: ${producto.stock}`
                        }
                    </p>

                    <div class="mt-auto d-grid gap-2">
                        <button
                            class="btn-base btn-base--fantasma btn-sm btn-ver-detalle"
                            data-id-producto="${producto.id_producto}"
                        >
                            Ver detalle
                        </button>
                        <button
                            class="btn-base btn-base--primario btn-sm btn-agregar-carrito"
                            data-id-producto="${producto.id_producto}"
                        >
                            Agregar al carrito
                        </button>
                    </div>
                </div>

            </div>
        </div>
    `).join('');
}

function inicializarFiltrosCategorias() {
    document.getElementById('filtrosCategorias').addEventListener('click', (evento) => {
        const boton = evento.target.closest('.btn-filtro-categoria');

        if (!boton) {
            return;
        }

        // Actualiza el estado visual "activo" del filtro.
        document.querySelectorAll('.btn-filtro-categoria').forEach((btn) => {
            btn.classList.remove('filtro-categoria--activo');
        });
        boton.classList.add('filtro-categoria--activo');

        categoriaActiva = boton.dataset.idCategoria;
        cargarCatalogoPorCategoria(categoriaActiva);
    });
}

/* =============================================================
 * ROUTER: VISTAS "INICIO", "CATÁLOGO" Y "PRODUCTO"
 *
 * Cada ruta es una pantalla propia del SPA (#inicio, #catalogo,
 * #producto/<id>). crearRouter() despacha por hash; aquí solo se
 * definen los handlers de cada ruta y las funciones que
 * muestran/ocultan las vistas correspondientes en el DOM.
 * ============================================================= */

// Referencia al intervalo de reintento de mostrarVistaProducto, para
// poder cancelarlo si el usuario navega a otra vista antes de que
// el catálogo termine de cargar (evita un intervalo huérfano
// ejecutándose en segundo plano indefinidamente).
let intervaloReintentoProducto = null;

/**
 * Oculta todas las vistas de nivel superior y muestra únicamente
 * la solicitada. Los IDs deben coincidir con los contenedores
 * "vista-*" del HTML.
 *
 * @param {string} idVista - 'vista-inicio', 'vista-catalogo', 'vista-producto'
 *   o 'vista-pedidos'
 */
function mostrarVista(idVista) {
    // Cualquier cambio de vista cancela un reintento pendiente de
    // mostrarVistaProducto, sin importar hacia dónde se navegue.
    if (intervaloReintentoProducto !== null) {
        clearInterval(intervaloReintentoProducto);
        intervaloReintentoProducto = null;
    }

    ['vista-inicio', 'vista-catalogo', 'vista-producto', 'vista-pedidos'].forEach((id) => {
        document.getElementById(id).classList.toggle('d-none', id !== idVista);
    });

    marcarEnlaceActivo(idVista);

    // Al cambiar de vista, se regresa el scroll al inicio — igual
    // que se esperaría al "abrir otra página".
    window.scrollTo({ top: 0, behavior: 'instant' });
}

/**
 * Resalta en la barra flotante "Home" o "Productos" según la vista
 * mostrada (el catálogo y el detalle cuentan como "Productos").
 *
 * @param {string} idVista
 */
function marcarEnlaceActivo(idVista) {
    const enInicio = idVista === 'vista-inicio';
    const enProductos = idVista === 'vista-catalogo' || idVista === 'vista-producto';
    const enlaceHome = document.getElementById('enlaceHome');
    const enlaceProductos = document.getElementById('enlaceProductos');

    if (enlaceHome) {
        enlaceHome.classList.toggle('active', enInicio);
    }

    if (enlaceProductos) {
        enlaceProductos.classList.toggle('active', enProductos);
    }
}

function mostrarVistaInicio() {
    mostrarVista('vista-inicio');
}

function mostrarVistaCatalogo() {
    mostrarVista('vista-catalogo');
}

function mostrarVistaPedidos() {
    mostrarVista('vista-pedidos');
    cargarPedidos();
}

/**
 * Handler de la ruta "producto/<id>". Busca el producto primero
 * en catalogoCompleto (ya cacheado); si el catálogo aún no ha
 * terminado de cargar (por ejemplo, se entró directo a esta URL
 * y DOMContentLoaded todavía no resuelve el fetch), reintenta
 * una vez que el catálogo esté disponible en vez de mostrar un
 * "no encontrado" prematuro.
 *
 * @param {string} resto - lo que sigue a "producto/" en el hash,
 *   ej. "15" para el hash "#producto/15".
 */
function mostrarVistaProducto(resto) {
    mostrarVista('vista-producto');

    const idProducto = Number(resto);
    const contenedor = document.getElementById('vista-producto__contenido');

    if (!idProducto) {
        contenedor.innerHTML = '<p class="text-danger text-center py-5">Producto no válido.</p>';
        return;
    }

    if (catalogoCompleto.length === 0) {
        contenedor.innerHTML = '<p class="text-muted text-center py-5">Cargando producto...</p>';
        // El catálogo aún no cargó — se reintenta cuando termine.
        // cargarCatalogoInicial() ya está en curso desde
        // DOMContentLoaded; solo hace falta esperar su resultado.
        // Se guarda en la variable de módulo (no local) para que
        // mostrarVista() pueda cancelarlo si el usuario navega a
        // otra vista antes de que el catálogo termine de cargar.
        intervaloReintentoProducto = setInterval(() => {
            if (catalogoCompleto.length > 0) {
                clearInterval(intervaloReintentoProducto);
                intervaloReintentoProducto = null;
                mostrarVistaProducto(resto);
            }
        }, 150);
        return;
    }

    const producto = catalogoCompleto.find((p) => p.id_producto === idProducto);

    if (!producto) {
        contenedor.innerHTML = '<p class="text-danger text-center py-5">Producto no encontrado o ya no disponible.</p>';
        return;
    }

    contenedor.innerHTML = `
        <div class="row g-5">
            <div class="col-lg-6">
                ${producto.imagen
                    ? `<img
                        src="uploads/${escaparHtml(producto.imagen)}"
                        class="img-fluid rounded-4"
                        alt="${escaparHtml(producto.nombre)}"
                    >`
                    : `<div class="producto-card__imagen d-flex align-items-center justify-content-center" style="height:320px;">
                        <span class="producto-card__stock">Sin imagen</span>
                    </div>`
                }
            </div>
            <div class="col-lg-6">
                <p class="producto-card__categoria mb-2">
                    ${escaparHtml(producto.nombre_categoria || 'Sin categoría')}
                </p>
                <h1 class="hero-section__titulo h2 mb-3">${escaparHtml(producto.nombre)}</h1>
                <p class="producto-card__precio mb-3" style="font-size:1.75rem;">
                    ${formatearMoneda(producto.precio)}
                </p>
                <p class="bento-card__texto mb-4">
                    ${escaparHtml(producto.descripcion || 'Sin descripción disponible.')}
                </p>
                <p class="producto-card__stock ${producto.stock <= 3 ? 'producto-card__stock--bajo' : ''} mb-4">
                    ${producto.stock <= 3
                        ? `¡Solo quedan ${producto.stock}!`
                        : `Stock disponible: ${producto.stock}`
                    }
                </p>
                <button
                    type="button"
                    class="btn-base btn-base--primario btn-agregar-carrito"
                    data-id-producto="${producto.id_producto}"
                >
                    Agregar al carrito
                </button>
            </div>
        </div>
    `;
}

function inicializarRouter() {
    const router = crearRouter({
        'inicio': mostrarVistaInicio,
        'catalogo': mostrarVistaCatalogo,
        'producto': mostrarVistaProducto,
        'pedidos': mostrarVistaPedidos
    }, 'inicio');

    router.iniciar();

    document.getElementById('btnVolverCatalogo').addEventListener('click', () => {
        router.navegar('catalogo');
    });
}

/**
 * Cambia los botones "Ver detalle" del catálogo para navegar a la
 * vista de producto en vez de abrir un modal. Delegación de
 * eventos sobre el contenedor del catálogo, igual que el resto de
 * interacciones de la grilla.
 */
function inicializarNavegacionADetalle() {
    document.getElementById('catalogoProductos').addEventListener('click', (evento) => {
        const boton = evento.target.closest('.btn-ver-detalle');

        if (!boton) {
            return;
        }

        window.location.hash = `producto/${boton.dataset.idProducto}`;
    });
}


/* =============================================================
 * CARRITO (persistente en base de datos)
 * ============================================================= */

/**
 * Solicita el estado actual del carrito al backend y lo pinta
 * en el offcanvas, además de actualizar el contador de la
 * barra de navegación.
 *
 * @param {boolean} silentFail - true para la carga automática al
 *   entrar al sitio: si no hay sesión (401), no redirige a
 *   login.html y simplemente deja el carrito/contador vacíos,
 *   sin mostrar mensaje de error al visitante anónimo.
 */
// Última respuesta del carrito: el asistente de pago la usa para el resumen.
let ultimoCarrito = { total_articulos: 0, subtotal: '0.00', carrito: [] };

async function cargarCarrito(silentFail = false) {
    const contenedor = document.getElementById('contenidoCarrito');

    if (!silentFail) {
        contenedor.innerHTML = '<p class="text-muted text-center">Cargando carrito...</p>';
    }

    const { ok, status, data } = await peticionApi(
        ENDPOINTS.carrito,
        { method: 'GET' },
        silentFail
    );

    if (!ok) {
        if (silentFail && status === 401) {
            // Visitante sin sesión: carrito vacío, sin mensaje de error.
            actualizarBarraSesion(false);
            renderizarCarrito({ total_articulos: 0, subtotal: 0, carrito: [] });
            return;
        }

        contenedor.innerHTML = `
            <p class="text-danger text-center">
                ${escaparHtml(data.message || 'No fue posible cargar el carrito.')}
            </p>
        `;
        return;
    }

    actualizarBarraSesion(true);
    renderizarCarrito(data);
}

/**
 * Muestra el botón "Iniciar sesión" o "Cerrar sesión" en la barra
 * de navegación según haya o no sesión activa. Se determina a
 * partir del resultado de la carga silenciosa del carrito
 * (401 = sin sesión), sin necesitar un endpoint dedicado de
 * "quién soy" que el proyecto no define.
 *
 * @param {boolean} haySesion
 */
function actualizarBarraSesion(haySesion) {
    document.getElementById('btnIniciarSesion').classList.toggle('d-none', haySesion);
    document.getElementById('btnCerrarSesion').classList.toggle('d-none', !haySesion);
    document.getElementById('btnMisPedidos').classList.toggle('d-none', !haySesion);
}

function inicializarLogout() {
    const btnCerrarSesion = document.getElementById('btnCerrarSesion');

    if (!btnCerrarSesion) {
        return;
    }

    btnCerrarSesion.addEventListener('click', async () => {
        btnCerrarSesion.disabled = true;

        await peticionApi(ENDPOINTS.logout, { method: 'POST' });

        // Tras cerrar sesión, se recarga la página: el catálogo
        // sigue siendo público, pero el carrito y la barra de
        // sesión deben reflejar el estado de visitante anónimo.
        window.location.reload();
    });
}

/**
 * Pinta el contenido del offcanvas y el contador de la barra
 * de navegación a partir de la respuesta de GET carrito.
 *
 * @param {{total_articulos: number, subtotal: string, carrito: Array}} data
 */
function renderizarCarrito(data) {
    ultimoCarrito = data;
    const contenedor = document.getElementById('contenidoCarrito');
    const totalCarrito = document.getElementById('totalCarrito');
    const contador = document.getElementById('contadorCarrito');
    const items = data.carrito || [];

    totalCarrito.textContent = formatearMoneda(data.subtotal || 0);

    if (data.total_articulos > 0) {
        contador.textContent = data.total_articulos;
        contador.classList.remove('d-none');
    } else {
        contador.classList.add('d-none');
    }

    if (items.length === 0) {
        contenedor.innerHTML = '<p class="text-muted text-center">Tu carrito está vacío.</p>';
        return;
    }

    contenedor.innerHTML = items.map((item) => `
        <div class="carrito-item">
            ${item.imagen
                ? `<img
                    src="uploads/${escaparHtml(item.imagen)}"
                    alt="${escaparHtml(item.nombre)}"
                    class="carrito-item__imagen"
                >`
                : `<div
                    class="carrito-item__imagen d-flex align-items-center justify-content-center"
                >
                    <span class="producto-card__stock" style="font-size:0.65rem;">Sin imagen</span>
                </div>`
            }

            <div class="flex-grow-1">
                <p class="carrito-item__nombre mb-1">${escaparHtml(item.nombre)}</p>

                ${!item.activo
                    ? '<p class="carrito-item__aviso carrito-item__aviso--critico mb-1">Este producto ya no está disponible.</p>'
                    : ''
                }

                <p class="carrito-item__precio-unitario mb-1">${formatearMoneda(item.precio)} c/u</p>

                <div class="carrito-item__controles">
                    <button
                        class="btn-base btn-base--fantasma btn-sm btn-carrito-decrementar"
                        data-id-producto="${item.id_producto}"
                        data-cantidad-actual="${item.cantidad}"
                        ${item.cantidad <= 1 ? 'disabled' : ''}
                    >
                        −
                    </button>
                    <span class="carrito-item__nombre" style="font-weight:400;">${item.cantidad}</span>
                    <button
                        class="btn-base btn-base--fantasma btn-sm btn-carrito-incrementar"
                        data-id-producto="${item.id_producto}"
                        data-cantidad-actual="${item.cantidad}"
                        data-stock-disponible="${item.stock_disponible}"
                        ${item.cantidad >= item.stock_disponible ? 'disabled' : ''}
                    >
                        +
                    </button>
                    <button
                        class="btn btn-sm btn-link text-danger ms-auto btn-carrito-eliminar"
                        data-id-producto="${item.id_producto}"
                    >
                        Eliminar
                    </button>
                </div>

                ${item.cantidad >= item.stock_disponible
                    ? '<p class="carrito-item__aviso mb-0">Alcanzó el máximo disponible.</p>'
                    : ''
                }
            </div>
        </div>
    `).join('');
}

/**
 * Agrega un producto al carrito (POST) desde las tarjetas del
 * catálogo, siempre con cantidad 1. Incrementos posteriores se
 * hacen desde el offcanvas con PUT.
 *
 * @param {string|number} idProducto
 */
async function agregarAlCarrito(idProducto) {
    const { ok, data } = await peticionApi(ENDPOINTS.carrito, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json; charset=utf-8'
        },
        body: JSON.stringify({ id_producto: Number(idProducto), cantidad: 1 })
    });

    if (!ok) {
        mostrarAlertaGlobal(data.message || 'No fue posible agregar el producto al carrito.');
        return;
    }

    const producto = catalogoCompleto.find((p) => p.id_producto === Number(idProducto));

    mostrarAlertaGlobal(
        producto
            ? `"${producto.nombre}" agregado al carrito.`
            : 'Producto agregado al carrito.',
        'success'
    );
    await cargarCarrito();
}

/**
 * Fija una cantidad absoluta para un producto ya presente en el
 * carrito, usando PUT. Se usa tanto para incrementar como para
 * decrementar desde el offcanvas.
 *
 * @param {string|number} idProducto
 * @param {number} nuevaCantidad
 */
async function actualizarCantidadCarrito(idProducto, nuevaCantidad) {
    const { ok, data } = await peticionApi(ENDPOINTS.carrito, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json; charset=utf-8'
        },
        body: JSON.stringify({ id_producto: Number(idProducto), cantidad: nuevaCantidad })
    });

    if (!ok) {
        mostrarAlertaGlobal(data.message || 'No fue posible actualizar la cantidad.');
        // Se recarga el carrito de todas formas para reflejar el
        // estado real del servidor, por si el límite cambió.
        await cargarCarrito();
        return;
    }

    await cargarCarrito();
}

/**
 * Elimina por completo un producto del carrito (DELETE). El
 * endpoint es idempotente, así que no es necesario verificar
 * previamente si el producto sigue presente.
 *
 * @param {string|number} idProducto
 */
async function eliminarDelCarrito(idProducto) {
    const { ok, data } = await peticionApi(
        `${ENDPOINTS.carrito}?id_producto=${encodeURIComponent(idProducto)}`,
        { method: 'DELETE' }
    );

    if (!ok) {
        mostrarAlertaGlobal(data.message || 'No fue posible eliminar el producto del carrito.');
        return;
    }

    mostrarAlertaGlobal('Producto eliminado del carrito.', 'info');
    await cargarCarrito();
}

function inicializarAgregarAlCarrito() {
    // Delegado en document.body (no solo en #catalogoProductos) para
    // que también funcione en la vista de producto del router, que
    // vive en un contenedor distinto y se regenera al navegar.
    document.body.addEventListener('click', (evento) => {
        const boton = evento.target.closest('.btn-agregar-carrito');

        if (!boton) {
            return;
        }

        agregarAlCarrito(boton.dataset.idProducto);
    });
}

function inicializarControlesCarrito() {
    document.getElementById('contenidoCarrito').addEventListener('click', (evento) => {
        const botonIncrementar = evento.target.closest('.btn-carrito-incrementar');

        if (botonIncrementar) {
            const cantidadActual = Number(botonIncrementar.dataset.cantidadActual);
            const stockDisponible = Number(botonIncrementar.dataset.stockDisponible);

            if (cantidadActual >= stockDisponible) {
                return;
            }

            actualizarCantidadCarrito(botonIncrementar.dataset.idProducto, cantidadActual + 1);
            return;
        }

        const botonDecrementar = evento.target.closest('.btn-carrito-decrementar');

        if (botonDecrementar) {
            const cantidadActual = Number(botonDecrementar.dataset.cantidadActual);

            if (cantidadActual <= 1) {
                return;
            }

            actualizarCantidadCarrito(botonDecrementar.dataset.idProducto, cantidadActual - 1);
            return;
        }

        const botonEliminar = evento.target.closest('.btn-carrito-eliminar');

        if (botonEliminar) {
            eliminarDelCarrito(botonEliminar.dataset.idProducto);
        }
    });

    // Se recarga el carrito cada vez que se abre el offcanvas, para
    // reflejar cambios de stock que hayan ocurrido mientras el
    // usuario navegaba el catálogo.
    document.getElementById('offcanvasCarrito').addEventListener('show.bs.offcanvas', () => {
        cargarCarrito();
    });
}


/* =============================================================
 * MIS PEDIDOS
 *
 * Vista #pedidos: historial del cliente con sesión iniciada.
 * pedidos_controller.php devuelve solo los pedidos del usuario de la
 * sesión; sin sesión responde 401 y peticionApi avisa y redirige a
 * login.html.
 * ============================================================= */

const ETIQUETAS_ESTADO_PEDIDO = {
    pendiente: 'Pendiente',
    pagado: 'Pagado',
    enviado: 'Enviado'
};

function etiquetaEstadoPedido(estado) {
    return ETIQUETAS_ESTADO_PEDIDO[estado] || estado || 'Sin estado';
}

/**
 * Convierte la fecha de MySQL ("2026-10-12 13:35:00") en un texto
 * legible en español de México.
 *
 * @param {string} valor
 * @returns {string}
 */
function formatearFecha(valor) {
    const fecha = new Date(String(valor).replace(' ', 'T'));

    if (Number.isNaN(fecha.getTime())) {
        return valor ?? '';
    }

    return fecha.toLocaleString('es-MX', { dateStyle: 'long', timeStyle: 'short' });
}

async function cargarPedidos() {
    const contenedor = document.getElementById('listaPedidos');

    contenedor.innerHTML = '<p class="text-muted text-center py-5">Cargando pedidos...</p>';

    const { ok, data } = await peticionApi(ENDPOINTS.pedidos, { method: 'GET' });

    if (!ok) {
        contenedor.innerHTML = `
            <p class="text-danger text-center py-5">
                ${escaparHtml(data.message || 'No fue posible cargar tus pedidos.')}
            </p>
        `;
        return;
    }

    renderizarPedidos(data.pedidos || []);
}

function renderizarPedidos(pedidos) {
    const contenedor = document.getElementById('listaPedidos');

    if (pedidos.length === 0) {
        contenedor.innerHTML = `
            <div class="bento-card p-5 text-center">
                <i class="bi bi-receipt bento-card__icono d-block mb-3"></i>
                <h4 class="bento-card__titulo h5 mb-2">Aún no tienes pedidos</h4>
                <p class="bento-card__texto small mb-4">
                    Cuando compres, aquí verás el estado de cada pedido.
                </p>
                <a href="#catalogo" class="btn-base btn-base--primario">
                    Explorar catálogo
                    <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        `;
        return;
    }

    contenedor.innerHTML = pedidos.map((pedido) => `
        <div class="pedido-card">
            <div class="pedido-card__cabecera">
                <div>
                    <p class="pedido-card__titulo">Pedido #${pedido.id_pedido}</p>
                    <p class="pedido-card__meta">
                        ${escaparHtml(formatearFecha(pedido.fecha_pedido))}
                        · ${pedido.total_articulos} ${pedido.total_articulos === 1 ? 'artículo' : 'artículos'}
                    </p>
                </div>
                <div class="text-end">
                    <span class="estado-pedido estado-pedido--${escaparHtml(pedido.estado_pedido)}">
                        ${escaparHtml(etiquetaEstadoPedido(pedido.estado_pedido))}
                    </span>
                    <p class="pedido-card__total">${formatearMoneda(pedido.total)}</p>
                </div>
            </div>

            <button
                type="button"
                class="btn-base btn-base--fantasma btn-sm btn-ver-pedido"
                data-id-pedido="${pedido.id_pedido}"
            >
                Ver productos
            </button>
            <div class="pedido-card__detalle d-none"></div>
        </div>
    `).join('');
}

/**
 * Renglones de productos de un pedido. Los precios son los congelados
 * al momento de la compra (precio_compra), no los actuales del catálogo.
 *
 * @param {Array} detalles
 * @returns {string}
 */
function renderizarLineasPedido(detalles) {
    if (detalles.length === 0) {
        return '<p class="text-muted small mb-0">Este pedido no tiene productos registrados.</p>';
    }

    return detalles.map((linea) => `
        <div class="pedido-card__linea">
            ${linea.imagen
                ? `<img src="uploads/${escaparHtml(linea.imagen)}" class="pedido-card__imagen" alt="">`
                : '<div class="pedido-card__imagen"></div>'
            }
            <div>
                <p class="pedido-card__producto">${escaparHtml(linea.nombre)}</p>
                <p class="pedido-card__producto-meta">
                    ${linea.cantidad} × ${formatearMoneda(linea.precio_compra)}
                </p>
            </div>
            <span class="pedido-card__subtotal">${formatearMoneda(linea.subtotal)}</span>
        </div>
    `).join('');
}

function inicializarPedidos() {
    const contenedor = document.getElementById('listaPedidos');

    if (!contenedor) {
        return;
    }

    contenedor.addEventListener('click', async (evento) => {
        const boton = evento.target.closest('.btn-ver-pedido');

        if (!boton) {
            return;
        }

        const detalle = boton.closest('.pedido-card').querySelector('.pedido-card__detalle');

        // Si el detalle ya se cargó, solo se muestra u oculta.
        if (detalle.dataset.cargado === 'true') {
            const oculto = detalle.classList.toggle('d-none');
            boton.textContent = oculto ? 'Ver productos' : 'Ocultar productos';
            return;
        }

        boton.disabled = true;

        const { ok, data } = await peticionApi(
            `${ENDPOINTS.pedidos}?id=${encodeURIComponent(boton.dataset.idPedido)}`,
            { method: 'GET' }
        );

        boton.disabled = false;

        if (!ok) {
            mostrarAlertaGlobal(data.message || 'No fue posible cargar el detalle del pedido.');
            return;
        }

        detalle.innerHTML = renderizarLineasPedido(data.pedido.detalles || []);
        detalle.dataset.cargado = 'true';
        detalle.classList.remove('d-none');
        boton.textContent = 'Ocultar productos';
    });
}


/* =============================================================
 * CHECKOUT
 * ============================================================= */

/**
 * Ejecuta el proceso de compra. checkout_controller.php extrae
 * los artículos directamente de la tabla carrito según la sesión
 * del servidor, así que la petición va sin cuerpo.
 *
 * Maneja tres desenlaces distintos:
 * - 200: compra exitosa, se muestra confirmación con id_pedido.
 * - 400: carrito vacío (validado también en servidor).
 * - 409: conflicto de stock/estado; se listan los productos
 *   afectados con su stock real para que el usuario decida.
 * - 401 lo intercepta el helper peticionApi y redirige a login.html.
 */
async function procesarCheckout(btnPagar, spinner) {

    btnPagar.disabled = true;
    spinner.classList.remove('d-none');

    const { ok, status, data } = await peticionApi(ENDPOINTS.checkout, {
        method: 'POST'
    });

    btnPagar.disabled = false;
    spinner.classList.add('d-none');

    // Se espera a que el asistente termine de cerrarse antes de abrir el
    // resultado, para no encimar dos modales de Bootstrap.
    await cerrarAsistentePago();

    if (ok) {
        mostrarResultadoCheckoutExito(data.pedido);
        return;
    }

    if (status === 409 && Array.isArray(data.productos_agotados)) {
        mostrarResultadoCheckoutConflicto(data.productos_agotados);
        return;
    }

    // 400 (carrito vacío) u otro error no contemplado.
    mostrarResultadoCheckoutError(data.message || 'No fue posible procesar la compra.');
}

function mostrarResultadoCheckoutExito(pedido) {
    document.getElementById('tituloModalCheckout').textContent = '¡Compra realizada!';

    document.getElementById('cuerpoModalCheckout').innerHTML = `
        <div class="text-center">
            <p class="fs-5 mb-1">Pedido #${pedido.id_pedido} confirmado</p>
            <p class="text-muted mb-0">Total: ${formatearMoneda(pedido.total)}</p>
            <p class="text-muted small">Estado: ${escaparHtml(pedido.estado_pedido)}</p>
        </div>
    `;

    document.getElementById('pieModalCheckout').innerHTML = `
        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal" id="btnCheckoutVerPedidos">
            Ver mis pedidos
        </button>
        <button type="button" class="btn btn-success flex-fill" data-bs-dismiss="modal" id="btnCheckoutCerrarExito">
            Continuar
        </button>
    `;

    // data-bs-dismiss cierra el modal; además se lleva al cliente a la
    // vista de pedidos (se usa un botón, no un enlace, porque Bootstrap
    // cancela la navegación de los enlaces con data-bs-dismiss).
    document.getElementById('btnCheckoutVerPedidos').addEventListener('click', () => {
        window.location.hash = 'pedidos';
    }, { once: true });

    // El carrito ya quedó vacío en el servidor tras el pago exitoso.
    cargarCarrito();

    // Cierra el offcanvas del carrito si estaba abierto, para no
    // mostrar dos superposiciones (offcanvas + modal) a la vez.
    const offcanvasCarrito = bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasCarrito'));
    if (offcanvasCarrito) {
        offcanvasCarrito.hide();
    }

    new bootstrap.Modal(document.getElementById('modalCheckout')).show();
}

/**
 * Muestra el conflicto de stock/estado devuelto en HTTP 409.
 * No se recarga el carrito automáticamente al cerrar este modal
 * con el botón principal: se deja que el usuario revise el
 * offcanvas para ajustar cantidades o eliminar productos, y ahí
 * sí se refresca contra el servidor.
 *
 * @param {Array} productosAgotados
 */
function mostrarResultadoCheckoutConflicto(productosAgotados) {
    document.getElementById('tituloModalCheckout').textContent = 'No fue posible completar la compra';

    const filas = productosAgotados.map((producto) => `
        <li class="mb-2">
            <strong>${escaparHtml(producto.nombre)}</strong><br>
            <span class="text-muted small">
                Solicitaste ${producto.cantidad_solicitada},
                ${producto.stock_disponible > 0
                    ? `solo quedan ${producto.stock_disponible} disponibles.`
                    : 'ya no está disponible.'
                }
            </span>
        </li>
    `).join('');

    document.getElementById('cuerpoModalCheckout').innerHTML = `
        <p>Estos productos cambiaron mientras estaban en tu carrito:</p>
        <ul class="list-unstyled">${filas}</ul>
        <p class="text-muted small mb-0">
            Ajusta las cantidades o elimínalos del carrito para continuar.
        </p>
    `;

    document.getElementById('pieModalCheckout').innerHTML = `
        <button type="button" class="btn btn-primary w-100" id="btnCheckoutRevisarCarrito">
            Revisar mi carrito
        </button>
    `;

    const modalCheckout = new bootstrap.Modal(document.getElementById('modalCheckout'));
    modalCheckout.show();

    document.getElementById('btnCheckoutRevisarCarrito').addEventListener('click', () => {
        modalCheckout.hide();
        cargarCarrito();
        new bootstrap.Offcanvas(document.getElementById('offcanvasCarrito')).show();
    }, { once: true });
}

function mostrarResultadoCheckoutError(mensaje) {
    document.getElementById('tituloModalCheckout').textContent = 'No fue posible completar la compra';

    document.getElementById('cuerpoModalCheckout').innerHTML = `
        <p class="text-danger mb-0">${escaparHtml(mensaje)}</p>
    `;

    document.getElementById('pieModalCheckout').innerHTML = `
        <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">
            Cerrar
        </button>
    `;

    new bootstrap.Modal(document.getElementById('modalCheckout')).show();
}

const METODOS_PAGO = {
    tarjeta: { nombre: 'Tarjeta de crédito o débito', detalle: 'Pagas al coordinar la entrega con la tienda.' },
    transferencia: { nombre: 'Transferencia bancaria', detalle: 'La tienda te enviará los datos para transferir.' },
    efectivo: { nombre: 'Efectivo contra entrega', detalle: 'Pagas en efectivo al recibir tu pedido.' }
};

const ETAPAS_PAGO = [
    { titulo: 'Revisa tu pedido', siguiente: 'Continuar' },
    { titulo: 'Elige cómo pagar', siguiente: 'Continuar' },
    { titulo: 'Confirma tu compra', siguiente: 'Confirmar y pagar' }
];

let etapaPago = 1;
let metodoPagoElegido = '';
let modalPago = null;

function etapaPagoHtml(etapa) {
    const items = ultimoCarrito.carrito || [];

    if (etapa === 1) {
        const filas = items.map((item) => `
            <div class="pago-resumen__fila">
                <span>${escaparHtml(item.nombre)} <span class="text-muted">× ${item.cantidad}</span></span>
                <span>${formatearMoneda(Number(item.precio) * item.cantidad)}</span>
            </div>
        `).join('');

        return `
            <h6 class="mb-3" tabindex="-1" id="pagoTituloEtapa">${ETAPAS_PAGO[0].titulo}</h6>
            ${filas}
            <div class="d-flex justify-content-between mt-3">
                <strong>Total</strong>
                <strong>${formatearMoneda(ultimoCarrito.subtotal || 0)}</strong>
            </div>
        `;
    }

    if (etapa === 2) {
        const opciones = Object.entries(METODOS_PAGO).map(([clave, metodo]) => `
            <label class="pago-metodo">
                <input
                    type="radio"
                    class="form-check-input mt-1"
                    name="metodoPago"
                    value="${clave}"
                    ${metodoPagoElegido === clave ? 'checked' : ''}
                >
                <span>
                    <strong class="d-block">${metodo.nombre}</strong>
                    <span class="text-muted small">${metodo.detalle}</span>
                </span>
            </label>
        `).join('');

        return `
            <h6 class="mb-3" tabindex="-1" id="pagoTituloEtapa">${ETAPAS_PAGO[1].titulo}</h6>
            ${opciones}
            <div id="pagoErrorEtapa" class="text-danger small mt-2 d-none" role="alert"></div>
        `;
    }

    return `
        <h6 class="mb-3" tabindex="-1" id="pagoTituloEtapa">${ETAPAS_PAGO[2].titulo}</h6>
        <div class="pago-resumen__fila">
            <span class="text-muted">Artículos</span>
            <span>${ultimoCarrito.total_articulos}</span>
        </div>
        <div class="pago-resumen__fila">
            <span class="text-muted">Método de pago</span>
            <span>${METODOS_PAGO[metodoPagoElegido].nombre}</span>
        </div>
        <div class="d-flex justify-content-between mt-3">
            <strong>Total a pagar</strong>
            <strong>${formatearMoneda(ultimoCarrito.subtotal || 0)}</strong>
        </div>
        <p class="text-muted small mt-3 mb-0">
            Al confirmar, tu pedido queda registrado como pendiente y se descuenta el inventario.
        </p>
    `;
}

function mostrarEtapaPago(etapa) {
    etapaPago = etapa;
    const total = ETAPAS_PAGO.length;
    const porcentaje = Math.round((etapa / total) * 100);

    document.getElementById('pagoContenido').innerHTML = etapaPagoHtml(etapa);

    const barra = document.getElementById('pagoProgresoBarra');
    barra.style.width = `${porcentaje}%`;
    document.getElementById('pagoProgresoContenedor').setAttribute('aria-valuenow', String(porcentaje));

    document.querySelectorAll('#pagoEtapas .pago-etapa').forEach((elemento) => {
        const numero = Number(elemento.dataset.etapa);
        elemento.classList.toggle('pago-etapa--activa', numero === etapa);
        elemento.classList.toggle('pago-etapa--completa', numero < etapa);

        if (numero === etapa) {
            elemento.setAttribute('aria-current', 'step');
        } else {
            elemento.removeAttribute('aria-current');
        }
    });

    document.getElementById('btnPagoAtras').classList.toggle('invisible', etapa === 1);
    document.getElementById('textoBtnPagoSiguiente').textContent = ETAPAS_PAGO[etapa - 1].siguiente;

    const titulo = document.getElementById('pagoTituloEtapa');
    if (titulo) {
        titulo.focus();
    }
}

function abrirAsistentePago() {
    const items = ultimoCarrito.carrito || [];

    if (items.length === 0) {
        mostrarAlertaGlobal('Tu carrito está vacío.', 'warning');
        return;
    }

    if (items.some((item) => !item.activo)) {
        mostrarAlertaGlobal('Quita del carrito los productos que ya no están disponibles para continuar.', 'warning');
        return;
    }

    metodoPagoElegido = '';

    const offcanvasCarrito = bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasCarrito'));
    if (offcanvasCarrito) {
        offcanvasCarrito.hide();
    }

    modalPago = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPago'));
    mostrarEtapaPago(1);
    modalPago.show();
}

function cerrarAsistentePago() {
    const elemento = document.getElementById('modalPago');

    if (!modalPago || !elemento.classList.contains('show')) {
        return Promise.resolve();
    }

    return new Promise((resolver) => {
        elemento.addEventListener('hidden.bs.modal', () => resolver(), { once: true });
        modalPago.hide();
    });
}

async function avanzarEtapaPago() {
    if (etapaPago === 2) {
        const elegido = document.querySelector('input[name="metodoPago"]:checked');

        if (!elegido) {
            const error = document.getElementById('pagoErrorEtapa');
            error.textContent = 'Elige un método de pago para continuar.';
            error.classList.remove('d-none');
            return;
        }

        metodoPagoElegido = elegido.value;
    }

    if (etapaPago < ETAPAS_PAGO.length) {
        mostrarEtapaPago(etapaPago + 1);
        return;
    }

    // Última etapa: se confirma la compra.
    const boton = document.getElementById('btnPagoSiguiente');
    const spinner = document.getElementById('spinnerPago');
    document.getElementById('btnPagoAtras').disabled = true;

    await procesarCheckout(boton, spinner);

    document.getElementById('btnPagoAtras').disabled = false;
}

function inicializarCheckout() {
    document.getElementById('btnIrPagar').addEventListener('click', abrirAsistentePago);
    document.getElementById('btnPagoSiguiente').addEventListener('click', avanzarEtapaPago);

    document.getElementById('btnPagoAtras').addEventListener('click', () => {
        if (etapaPago > 1) {
            mostrarEtapaPago(etapaPago - 1);
        }
    });
}


/* =============================================================
 * BUSCADOR
 *
 * Búsqueda por SERVIDOR contra catalogo_controller.php?q=texto
 * (contrato aprobado y ya implementado en backend). Se aplica
 * debounce (300ms) para no disparar una petición por cada tecla,
 * y un token de secuencia para descartar respuestas que lleguen
 * fuera de orden (una búsqueda vieja más lenta que sobrescriba
 * el resultado de una más reciente).
 * ============================================================= */

let temporizadorBusqueda = null;
let secuenciaBusquedaActual = 0;

function inicializarBuscador() {
    const btnAbrir = document.getElementById('btnAbrirBusqueda');
    const panel = document.getElementById('panelBusqueda');
    const input = document.getElementById('inputBusqueda');

    if (!btnAbrir || !panel) {
        return;
    }

    btnAbrir.addEventListener('click', (evento) => {
        evento.stopPropagation();
        panel.classList.toggle('d-none');

        if (!panel.classList.contains('d-none')) {
            input.focus();
        }
    });

    // Cierra el panel al hacer clic fuera de él, o en un resultado
    // (navegar a un producto no debería dejar el panel flotando).
    document.addEventListener('click', (evento) => {
        const esResultado = evento.target.closest('.buscador-panel__resultado');

        if (esResultado || (!panel.contains(evento.target) && evento.target !== btnAbrir)) {
            panel.classList.add('d-none');
        }
    });

    input.addEventListener('input', () => {
        const termino = input.value.trim();

        clearTimeout(temporizadorBusqueda);

        if (termino === '') {
            secuenciaBusquedaActual += 1; // invalida cualquier búsqueda en curso
            document.getElementById('resultadosBusqueda').innerHTML =
                '<p class="text-muted small text-center mb-0">Escribe para buscar en el catálogo.</p>';
            return;
        }

        temporizadorBusqueda = setTimeout(() => {
            buscarEnCatalogo(termino);
        }, 300);
    });
}

async function buscarEnCatalogo(termino) {
    const contenedor = document.getElementById('resultadosBusqueda');

    // Token de esta búsqueda concreta: si al resolver la petición
    // ya no es la más reciente disparada, su resultado se descarta
    // sin pintar nada (evita que una respuesta lenta y vieja
    // sobrescriba el resultado de una búsqueda más nueva).
    secuenciaBusquedaActual += 1;
    const miSecuencia = secuenciaBusquedaActual;

    contenedor.innerHTML = '<p class="text-muted small text-center mb-0">Buscando...</p>';

    const { ok, data } = await peticionApi(
        `${ENDPOINTS.catalogo}?q=${encodeURIComponent(termino)}`,
        { method: 'GET' }
    );

    if (miSecuencia !== secuenciaBusquedaActual) {
        return; // llegó una búsqueda más reciente mientras esperábamos
    }

    if (!ok) {
        contenedor.innerHTML = `<p class="text-danger small text-center mb-0">${escaparHtml(data.message || 'No fue posible buscar en el catálogo.')}</p>`;
        return;
    }

    const resultados = (data.productos || []).slice(0, 6);

    if (resultados.length === 0) {
        contenedor.innerHTML = `<p class="text-muted small text-center mb-0">Sin resultados para "${escaparHtml(termino)}".</p>`;
        return;
    }

    contenedor.innerHTML = resultados.map((producto) => `
        <a href="#producto/${producto.id_producto}" class="buscador-panel__resultado">
            ${producto.imagen
                ? `<img src="uploads/${escaparHtml(producto.imagen)}" class="buscador-panel__resultado-imagen" alt="">`
                : `<div class="buscador-panel__resultado-imagen"></div>`
            }
            <div>
                <p class="buscador-panel__resultado-nombre">${escaparHtml(producto.nombre)}</p>
                <p class="buscador-panel__resultado-precio">${formatearMoneda(producto.precio)}</p>
            </div>
        </a>
    `).join('');
}


/* =============================================================
 * PUNTO DE ENTRADA
 * ============================================================= */

document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('catalogoProductos')) {
        return;
    }

    inicializarFiltrosCategorias();
    inicializarDropdownCategorias();
    inicializarBuscador();
    inicializarNavegacionADetalle();
    inicializarRouter();
    inicializarAgregarAlCarrito();
    inicializarControlesCarrito();
    inicializarCheckout();
    inicializarPedidos();
    inicializarLogout();

    cargarCatalogoInicial();

    /*
     * Carga silenciosa: si hay sesión activa, el contador del
     * carrito refleja sus artículos desde el inicio. Si no hay
     * sesión (401), no se redirige a login.html ni se muestra
     * error — el visitante anónimo simplemente ve el contador
     * oculto y puede seguir navegando el catálogo público.
     * Cualquier interacción explícita posterior (agregar,
     * incrementar, abrir el offcanvas) sí redirige estrictamente.
     */
    cargarCarrito(true);
});
