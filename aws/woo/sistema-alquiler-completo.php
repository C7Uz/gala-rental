<?php
/**
 * Sistema de Alquiler Mejorado - Versión Corregida v2
 * CORRECCIÓN: Problema con campos variation_id y validación de productos
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Inicializar el sistema solo después de que WordPress y los plugins estén cargados
 */
add_action('plugins_loaded', 'inicializar_sistema_alquiler', 20);

function inicializar_sistema_alquiler() {
    // Verificar WooCommerce de forma correcta
    if (!function_exists('WC') || !class_exists('WooCommerce')) {
        add_action('admin_notices', 'mostrar_aviso_woocommerce');
        return;
    }
    
    // WooCommerce está disponible, cargar el sistema
    cargar_sistema_alquiler();
}

function mostrar_aviso_woocommerce() {
    if (current_user_can('activate_plugins')) {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Sistema de Alquiler:</strong> ';
        echo 'Requiere que WooCommerce esté instalado y activado para funcionar. ';
        echo '<a href="' . admin_url('plugin-install.php?s=woocommerce&tab=search&type=term') . '">Instalar WooCommerce</a>';
        echo '</p></div>';
    }
}

function cargar_sistema_alquiler() {
    
    /**
     * ================================================================
     * 1. CONFIGURACIÓN DE MENÚS
     * ================================================================
     */
    
    add_action('admin_menu', 'setup_alquiler_menus');
    
    function setup_alquiler_menus() {
        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Menú principal
        add_menu_page(
            'Gestión de Alquileres',
            'Alquileres',
            'manage_woocommerce',
            'gestion-alquileres',
            'mostrar_lista_alquileres',
            'dashicons-calendar-alt',
            56
        );
        
        // Submenús
        add_submenu_page(
            'gestion-alquileres',
            'Lista de Alquileres',
            'Ver Alquileres',
            'manage_woocommerce',
            'gestion-alquileres',
            'mostrar_lista_alquileres'
        );
        
        add_submenu_page(
            'gestion-alquileres',
            'Crear Alquiler',
            'Crear Alquiler',
            'manage_woocommerce',
            'crear-alquiler-nuevo',
            'mostrar_formulario_crear_alquiler'
        );
    }
    
    /**
     * ================================================================
     * 2. PÁGINA DE LISTA DE ALQUILERES
     * ================================================================
     */
    
    function mostrar_lista_alquileres() {
        // Verificar WooCommerce una vez más por seguridad
        if (!function_exists('wc_get_order')) {
            echo '<div class="notice notice-error"><p>Error: WooCommerce no está completamente cargado.</p></div>';
            return;
        }
        
        // Procesar acciones
        if (isset($_GET['action']) && isset($_GET['order_id']) && wp_verify_nonce($_GET['_nonce'] ?? '', 'cambiar_estado_' . $_GET['order_id'])) {
            $order_id = intval($_GET['order_id']);
            $action = sanitize_text_field($_GET['action']);
            
            switch ($action) {
                case 'marcar_entregado':
                    update_post_meta($order_id, '_estado_alquiler', 'entregado');
                    echo '<div class="notice notice-success"><p>✅ Alquiler marcado como entregado</p></div>';
                    break;
                case 'marcar_devuelto':
                    update_post_meta($order_id, '_estado_alquiler', 'devuelto');
                    echo '<div class="notice notice-success"><p>✅ Alquiler marcado como devuelto</p></div>';
                    break;
            }
        }
        
        // Obtener filtros
        $estado_filtro = sanitize_text_field($_GET['estado'] ?? '');
        $buscar = sanitize_text_field($_GET['s'] ?? '');
        
        // Consulta de alquileres
        $args = array(
            'post_type' => 'shop_order',
            'posts_per_page' => 50,
            'meta_query' => array(
                array(
                    'key' => '_es_alquiler',
                    'value' => 'yes',
                    'compare' => '='
                )
            ),
            'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending')
        );
        
        if ($estado_filtro) {
            $args['meta_query'][] = array(
                'key' => '_estado_alquiler',
                'value' => $estado_filtro,
                'compare' => '='
            );
        }
        
        if ($buscar) {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_billing_first_name',
                    'value' => $buscar,
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_billing_email',
                    'value' => $buscar,
                    'compare' => 'LIKE'
                )
            );
        }
        
        $alquileres = get_posts($args);
        ?>
        
        <div class="wrap">
            <h1 class="wp-heading-inline">🏠 Gestión de Alquileres</h1>
            <a href="<?php echo admin_url('admin.php?page=crear-alquiler-nuevo'); ?>" class="page-title-action">➕ Crear Alquiler</a>
            
            <!-- Estado del sistema -->
            <div style="background: #d1ecf1; padding: 10px; margin: 15px 0; border-radius: 5px;">
                <strong>Estado del sistema:</strong>
                ✅ WooCommerce: <?php echo WC()->version; ?>
                | ✅ Productos de alquiler: <?php echo count(obtener_productos_alquiler()); ?>
                | ✅ Total alquileres: <?php echo count($alquileres); ?>
            </div>
            
            <!-- Filtros -->
            <div class="tablenav top">
                <form method="get" style="float: left; margin-right: 20px;">
                    <input type="hidden" name="page" value="gestion-alquileres">
                    <select name="estado" onchange="this.form.submit()">
                        <option value="">Todos los estados</option>
                        <option value="confirmed" <?php selected($estado_filtro, 'confirmed'); ?>>✅ Confirmado</option>
                        <option value="entregado" <?php selected($estado_filtro, 'entregado'); ?>>📦 Entregado</option>
                        <option value="devuelto" <?php selected($estado_filtro, 'devuelto'); ?>>🔄 Devuelto</option>
                        <option value="cancelled" <?php selected($estado_filtro, 'cancelled'); ?>>❌ Cancelado</option>
                    </select>
                </form>
                
                <form method="get" class="search-form">
                    <input type="hidden" name="page" value="gestion-alquileres">
                    <input type="search" name="s" value="<?php echo esc_attr($buscar); ?>" placeholder="Buscar por cliente...">
                    <button type="submit" class="button">🔍 Buscar</button>
                </form>
            </div>
            
            <!-- Tabla de alquileres -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Orden</th>
                        <th>Cliente</th>
                        <th>Productos</th>
                        <th>Fechas</th>
                        <th>Estado</th>
                        <th>Total</th>
                        <th>Garantía</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($alquileres)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                <p>📭 No hay alquileres registrados</p>
                                <a href="<?php echo admin_url('admin.php?page=crear-alquiler-nuevo'); ?>" class="button button-primary">Crear primer alquiler</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($alquileres as $alquiler_post): ?>
                            <?php
                            $order = wc_get_order($alquiler_post->ID);
                            if (!$order) continue;
                            
                            $fecha_inicio = get_post_meta($alquiler_post->ID, '_fecha_inicio_alquiler', true);
                            $fecha_fin = get_post_meta($alquiler_post->ID, '_fecha_fin_alquiler', true);
                            $estado_alquiler = get_post_meta($alquiler_post->ID, '_estado_alquiler', true) ?: 'pending';
                            $garantia = get_post_meta($alquiler_post->ID, '_garantia_total', true) ?: 0;
                            $evento = get_post_meta($alquiler_post->ID, '_evento_ocasion', true);
                            
                            // Obtener productos con detalles
                            $productos_info = array();
                            foreach ($order->get_items() as $item) {
                                $product = $item->get_product();
                                if ($product) {
                                    $talla = $item->get_meta('Talla', true);
                                    $dias = $item->get_meta('Dias de alquiler', true) ?: $item->get_meta('Periodo', true);
                                    
                                    $info = $product->get_name();
                                    if ($talla) $info .= " (Talla: $talla)";
                                    if ($dias) $info .= " - $dias";
                                    
                                    $productos_info[] = $info;
                                }
                            }
                            
                            // Estados y colores
                            $estados_config = array(
                                'confirmed' => array('label' => '✅ Confirmado', 'color' => '#28a745'),
                                'pending' => array('label' => '⏳ Pendiente', 'color' => '#ffc107'),
                                'entregado' => array('label' => '📦 Entregado', 'color' => '#17a2b8'),
                                'devuelto' => array('label' => '🔄 Devuelto', 'color' => '#6c757d'),
                                'cancelled' => array('label' => '❌ Cancelado', 'color' => '#dc3545')
                            );
                            
                            $estado_config = $estados_config[$estado_alquiler] ?? $estados_config['pending'];
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php echo $order->get_edit_order_url(); ?>" target="_blank">#<?php echo $order->get_id(); ?></a></strong>
                                    <br><small><?php echo $order->get_date_created()->format('d/m/Y H:i'); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></strong>
                                    <br><small><?php echo esc_html($order->get_billing_email()); ?></small>
                                    <?php if ($order->get_billing_phone()): ?>
                                        <br><small>📞 <?php echo esc_html($order->get_billing_phone()); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php foreach ($productos_info as $info): ?>
                                        <div style="margin-bottom: 4px;">• <?php echo esc_html($info); ?></div>
                                    <?php endforeach; ?>
                                    <?php if ($evento): ?>
                                        <br><small><strong>Evento:</strong> <?php echo esc_html($evento); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($fecha_inicio): ?>
                                        <strong>Inicio:</strong> <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?><br>
                                    <?php endif; ?>
                                    <?php if ($fecha_fin): ?>
                                        <strong>Fin:</strong> <?php echo date('d/m/Y', strtotime($fecha_fin)); ?><br>
                                    <?php endif; ?>
                                    <?php if ($fecha_inicio && $fecha_fin): ?>
                                        <?php
                                        $diff = (new DateTime($fecha_fin))->diff(new DateTime($fecha_inicio));
                                        $dias_total = $diff->days + 1;
                                        ?>
                                        <small><?php echo $dias_total; ?> días</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="background: <?php echo $estado_config['color']; ?>; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                        <?php echo $estado_config['label']; ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo $order->get_formatted_order_total(); ?></strong>
                                </td>
                                <td>
                                    <strong>S/. <?php echo number_format(floatval($garantia), 2); ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $nonce = wp_create_nonce('cambiar_estado_' . $order->get_id());
                                    $base_url = admin_url('admin.php?page=gestion-alquileres&order_id=' . $order->get_id() . '&_nonce=' . $nonce);
                                    ?>
                                    
                                    <?php if ($estado_alquiler === 'confirmed'): ?>
                                        <a href="<?php echo $base_url . '&action=marcar_entregado'; ?>" 
                                           class="button button-small" 
                                           onclick="return confirm('¿Marcar como entregado?')">
                                            📦 Entregar
                                        </a>
                                    <?php elseif ($estado_alquiler === 'entregado'): ?>
                                        <a href="<?php echo $base_url . '&action=marcar_devuelto'; ?>" 
                                           class="button button-small" 
                                           onclick="return confirm('¿Marcar como devuelto?')">
                                            🔄 Marcar Devuelto
                                        </a>
                                    <?php endif; ?>
                                    
                                    <br><br>
                                    <a href="<?php echo $order->get_edit_order_url(); ?>" 
                                       class="button button-small" target="_blank">
                                        ✏️ Editar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Resumen -->
            <div style="margin-top: 20px; background: #f9f9f9; padding: 15px; border-radius: 6px;">
                <h3>📊 Resumen</h3>
                <?php $stats = obtener_estadisticas_alquileres(); ?>
                <div style="display: flex; gap: 20px;">
                    <div><strong>Total alquileres:</strong> <?php echo $stats['total']; ?></div>
                    <div><strong>Activos:</strong> <?php echo $stats['activos']; ?></div>
                    <div><strong>Pendientes:</strong> <?php echo $stats['pendientes']; ?></div>
                    <div><strong>Completados:</strong> <?php echo $stats['completados']; ?></div>
                </div>
            </div>
        </div>
        
        <style>
        .search-form { float: right; }
        .search-form input[type="search"] { margin-right: 5px; }
        .tablenav.top { margin-bottom: 10px; overflow: hidden; }
        </style>
        <?php
    }
    
    /**
     * ================================================================
     * 3. PÁGINA DE CREAR ALQUILER (CORREGIDA)
     * ================================================================
     */
    
    function mostrar_formulario_crear_alquiler() {
        // Verificar WooCommerce
        if (!function_exists('wc_create_order')) {
            echo '<div class="notice notice-error"><p>Error: WooCommerce no está completamente cargado.</p></div>';
            return;
        }
        
        // Procesar formulario
        if (isset($_POST['crear_alquiler_nuevo']) && wp_verify_nonce($_POST['alquiler_nonce'], 'crear_alquiler_nuevo')) {
            $resultado = procesar_nuevo_alquiler($_POST);
            if ($resultado['success']) {
                echo '<div class="notice notice-success"><p>' . esc_html($resultado['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($resultado['message']) . '</p></div>';
            }
        }

        $productos_alquiler = obtener_productos_alquiler();
        
        // Verificar que hay productos
        if (empty($productos_alquiler)) {
            echo '<div class="wrap">';
            echo '<h1>🏠 Crear Nuevo Alquiler</h1>';
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>No hay productos de alquiler configurados.</strong><br>';
            echo 'Para usar este sistema, necesitas productos configurados como alquiler.<br>';
            echo '<a href="' . admin_url('edit.php?post_type=product') . '" class="button button-primary">Ir a Productos</a> ';
            echo 'y en la pestaña "Avanzado" marca "Es un producto de alquiler".';
            echo '</p></div>';
            echo '</div>';
            return;
        }
        ?>
        
        <div class="wrap">
            <h1>🏠 Crear Nuevo Alquiler</h1>
            <a href="<?php echo admin_url('admin.php?page=gestion-alquileres'); ?>" class="page-title-action">← Volver a la lista</a>
            
            <!-- Estado del sistema -->
            <div style="background: #d4edda; padding: 10px; margin: 15px 0; border-radius: 5px;">
                <strong>✅ Sistema listo:</strong>
                WooCommerce <?php echo WC()->version; ?> | 
                <?php echo count($productos_alquiler); ?> productos de alquiler disponibles
            </div>
            
            <form method="post" action="" id="form-crear-alquiler">
                <?php wp_nonce_field('crear_alquiler_nuevo', 'alquiler_nonce'); ?>
                
                <!-- CLIENTE -->
                <div class="postbox">
                    <h2 class="hndle">👤 Información del Cliente</h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Cliente Existente</th>
                                <td>
                                    <select name="cliente_id" id="cliente_existente" style="width: 100%; max-width: 400px;">
                                        <option value="">Seleccionar cliente existente...</option>
                                        <?php
                                        $clientes = get_users(array('role' => 'customer', 'number' => 100));
                                        foreach ($clientes as $cliente) {
                                            printf(
                                                '<option value="%d">%s (%s)</option>',
                                                $cliente->ID,
                                                esc_html($cliente->display_name),
                                                esc_html($cliente->user_email)
                                            );
                                        }
                                        ?>
                                    </select>
                                    <p class="description">O crear un nuevo cliente abajo</p>
                                </td>
                            </tr>
                        </table>
                        
                        <div id="nuevo-cliente-section" style="display: none; background: #f0f8ff; padding: 15px; border-radius: 6px; margin-top: 15px;">
                            <h4>Datos del Nuevo Cliente</h4>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Nombre Completo *</th>
                                    <td><input type="text" name="nuevo_cliente_nombre" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Email *</th>
                                    <td><input type="email" name="nuevo_cliente_email" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Teléfono</th>
                                    <td><input type="tel" name="nuevo_cliente_telefono" class="regular-text" /></td>
                                </tr>
                            </table>
                        </div>
                        
                        <button type="button" id="toggle-nuevo-cliente" class="button">➕ Crear Nuevo Cliente</button>
                    </div>
                </div>
                
                <!-- FECHAS -->
                <div class="postbox">
                    <h2 class="hndle">📅 Fechas del Alquiler</h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Fecha de Inicio *</th>
                                <td>
                                    <input type="date" name="fecha_inicio" id="fecha_inicio" required />
                                    <p class="description">Fecha en que el cliente recibirá el producto</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Fecha de Fin *</th>
                                <td>
                                    <input type="date" name="fecha_fin" id="fecha_fin" required />
                                    <div id="duracion-calculada" style="margin-top: 8px;"></div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Evento/Ocasión</th>
                                <td>
                                    <input type="text" name="evento_ocasion" class="regular-text" placeholder="Ej: Boda, Graduación, Fiesta..." />
                                    <p class="description">Opcional: para qué evento es el alquiler</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- PRODUCTOS -->
                <div class="postbox">
                    <h2 class="hndle">👗 Selección de Productos</h2>
                    <div class="inside">
                        <div id="productos-container">
                            <div class="producto-item" data-index="0">
                                <div class="producto-header">
                                    <h4>Producto #1</h4>
                                    <button type="button" class="button remove-producto" style="float: right; color: red;">❌ Eliminar</button>
                                    <div style="clear: both;"></div>
                                </div>
                                
                                <div class="producto-content">
                                    <!-- Paso 1: Seleccionar Producto -->
                                    <div class="paso producto-paso-1">
                                        <h5>1️⃣ Seleccionar Producto</h5>
                                        <select name="productos[0][id]" class="producto-select" style="width: 100%;" required>
                                            <option value="">Seleccionar producto...</option>
                                            <?php foreach ($productos_alquiler as $producto): ?>
                                                <option value="<?php echo esc_attr($producto->get_id()); ?>" 
                                                        data-name="<?php echo esc_attr($producto->get_name()); ?>">
                                                    <?php echo esc_html($producto->get_name()); ?> - <?php echo $producto->get_price_html(); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Paso 2: Seleccionar Talla y Período -->
                                    <div class="paso producto-paso-2" style="display: none;">
                                        <h5>2️⃣ Seleccionar Talla y Período de Alquiler</h5>
                                        <div class="seleccion-avanzada">
                                            <div class="cargando-variaciones">🔄 Cargando opciones...</div>
                                            <div class="matriz-seleccion" style="display: none;"></div>
                                        </div>
                                    </div>
                                    
                                    <!-- Paso 3: Cantidad y Confirmación -->
                                    <div class="paso producto-paso-3" style="display: none;">
                                        <h5>3️⃣ Cantidad y Confirmación</h5>
                                        <div class="cantidad-confirmacion">
                                            <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 15px;">
                                                <div>
                                                    <label><strong>Cantidad:</strong></label>
                                                    <input type="number" name="productos[0][cantidad]" class="cantidad-input" value="1" min="1" style="width: 80px; margin-left: 10px;" />
                                                </div>
                                                <div class="resumen-seleccion"></div>
                                            </div>
                                            <div class="verificacion-disponibilidad"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" id="agregar-producto" class="button button-secondary">➕ Agregar Otro Producto</button>
                    </div>
                </div>
                
                <!-- CONFIGURACIÓN -->
                <div class="postbox">
                    <h2 class="hndle">⚙️ Configuración del Alquiler</h2>
                    <div class="inside">
                        <div id="resumen-total"></div>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">Descuento (%)</th>
                                <td>
                                    <input type="number" name="descuento_porcentaje" id="descuento_porcentaje" min="0" max="100" step="0.01" style="width: 100px;" />
                                    <span class="description">Descuento sobre el total del alquiler</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Garantía Total</th>
                                <td>
                                    <input type="number" name="garantia_total" id="garantia_total" readonly style="width: 150px; background: #f0f0f0;" />
                                    <span class="description">Calculado automáticamente</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Estado del Alquiler</th>
                                <td>
                                    <select name="estado_alquiler">
                                        <option value="confirmed">✅ Confirmado</option>
                                        <option value="pending">⏳ Pendiente de confirmación</option>
                                        <option value="in-progress">🔄 En progreso</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Método de Pago</th>
                                <td>
                                    <select name="metodo_pago">
                                        <option value="efectivo">💵 Efectivo</option>
                                        <option value="transferencia">🏦 Transferencia bancaria</option>
                                        <option value="tarjeta">💳 Tarjeta de crédito</option>
                                        <option value="izi_pay">💎 IziPay</option>
                                    </select>
                                </td>
            <tr>
                                <th scope="row">Notas Adicionales</th>
                                <td>
                                    <textarea name="notas_adicionales" rows="4" class="large-text" placeholder="Notas especiales, instrucciones, observaciones..."></textarea>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="crear_alquiler_nuevo" class="button-primary button-hero" value="🏠 Crear Alquiler Completo" />
                    <a href="<?php echo admin_url('admin.php?page=gestion-alquileres'); ?>" class="button" style="margin-left: 10px;">Cancelar</a>
                </p>
            </form>
        </div>
        
        <!-- JAVASCRIPT CORREGIDO -->
        <?php incluir_javascript_alquiler_corregido(); ?>
        
        <!-- CSS -->
        <?php incluir_css_alquiler(); ?>
        <?php
    }
    
    
    
    
    
    /**
     * ================================================================
     * 4. FUNCIÓN DE PROCESAMIENTO CORREGIDA
     * ================================================================
     */
    
    function procesar_nuevo_alquiler($datos) {
    try {
        error_log("=== PROCESANDO NUEVO ALQUILER (SIN CONFLICTOS) ===");
        
        // CRÍTICO: Desactivar hooks problemáticos temporalmente
        $hooks_desactivados = desactivar_hooks_conflictivos();
        
        // Validaciones básicas (mantener igual)
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            reactivar_hooks_conflictivos($hooks_desactivados);
            return array('success' => false, 'message' => 'Las fechas de inicio y fin son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            reactivar_hooks_conflictivos($hooks_desactivados);
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validación de productos (mantener igual)
        $productos_validos = array();
        
        foreach ($datos['productos'] as $index => $producto_data) {
            $product_id = !empty($producto_data['id']) ? intval($producto_data['id']) : 0;
            $variation_id = !empty($producto_data['variation_id']) ? intval($producto_data['variation_id']) : 0;
            $cantidad = !empty($producto_data['cantidad']) ? intval($producto_data['cantidad']) : 1;
            
            // Verificar producto simple
            if ($product_id > 0 && $variation_id === 0) {
                $producto = wc_get_product($product_id);
                if ($producto && $producto->is_type('simple')) {
                    $productos_validos[] = array(
                        'id' => $product_id,
                        'variation_id' => $product_id,
                        'cantidad' => $cantidad
                    );
                    continue;
                }
            }
            
            // Verificar producto variable
            if ($product_id > 0 && $variation_id > 0) {
                $productos_validos[] = array(
                    'id' => $product_id,
                    'variation_id' => $variation_id,
                    'cantidad' => $cantidad
                );
                continue;
            }
        }
        
        if (empty($productos_validos)) {
            reactivar_hooks_conflictivos($hooks_desactivados);
            return array(
                'success' => false, 
                'message' => 'No se encontraron productos válidos.'
            );
        }
        
        // Obtener cliente
        $cliente_id = obtener_o_crear_cliente($datos);
        if (!$cliente_id) {
            reactivar_hooks_conflictivos($hooks_desactivados);
            return array('success' => false, 'message' => 'Error al procesar cliente');
        }
        
        // Crear orden con configuración especial para alquileres
        $order = crear_orden_alquiler_segura($cliente_id);
        if (!$order || is_wp_error($order)) {
            reactivar_hooks_conflictivos($hooks_desactivados);
            return array('success' => false, 'message' => 'Error al crear la orden');
        }
        
        error_log("Orden creada: " . $order->get_id());
        
        // Agregar productos de forma segura
        $garantia_total = 0;
        $productos_agregados = 0;
        $fecha_fin_real = $datos['fecha_fin'];
        
        foreach ($productos_validos as $producto_data) {
            $resultado = agregar_producto_orden_seguro($order, $producto_data, $datos);
            
            if ($resultado['success']) {
                $productos_agregados++;
                $garantia_total += $resultado['garantia'];
                if (!empty($resultado['fecha_fin']) && $resultado['fecha_fin'] > $fecha_fin_real) {
                    $fecha_fin_real = $resultado['fecha_fin'];
                }
            }
        }
        
        if ($productos_agregados === 0) {
            $order->delete(true);
            reactivar_hooks_conflictivos($hooks_desactivados);
            return array('success' => false, 'message' => 'No se pudieron agregar productos');
        }
        
        // Configurar la orden como alquiler
        configurar_orden_alquiler($order, $datos, $garantia_total, $fecha_fin_real);
        
        // Calcular totales de forma segura
        $order->calculate_totals();
        $order->save();
        
        // Reactivar hooks
        reactivar_hooks_conflictivos($hooks_desactivados);
        
        error_log("✅ Orden guardada exitosamente: " . $order->get_id());
        
        return array(
            'success' => true,
            'message' => "🎉 Alquiler creado: Orden #{$order->get_id()} con {$productos_agregados} producto(s)",
            'order_id' => $order->get_id()
        );
        
    } catch (Exception $e) {
        if (isset($hooks_desactivados)) {
            reactivar_hooks_conflictivos($hooks_desactivados);
        }
        error_log("ERROR en procesar_nuevo_alquiler: " . $e->getMessage());
        return array('success' => false, 'message' => 'Error interno: ' . $e->getMessage());
    }
}
    
    function agregar_producto_orden($order, $producto_data, $datos_alquiler) {
        try {
            $product_id = intval($producto_data['id']);
            $variation_id = intval($producto_data['variation_id']);
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            error_log("Agregando producto - ID: $product_id, Variation: $variation_id, Cantidad: $cantidad");
            
            // Cargar producto (priorizar variación si existe)
            $producto = null;
            if ($variation_id && $variation_id !== $product_id) {
                $producto = wc_get_product($variation_id);
                error_log("Cargando como variación: " . ($producto ? 'OK' : 'FALLO'));
            }
            
            if (!$producto) {
                $producto = wc_get_product($product_id);
                error_log("Cargando como producto simple: " . ($producto ? 'OK' : 'FALLO'));
            }
            
            if (!$producto) {
                error_log("Producto no encontrado - ID: $product_id, Variation: $variation_id");
                return array('success' => false, 'mensaje' => 'Producto no encontrado');
            }
            
            if (!$producto->is_purchasable()) {
                error_log("Producto no es comprable: " . $producto->get_id());
                return array('success' => false, 'mensaje' => 'Producto no disponible para compra');
            }
            
            // Agregar a la orden
            $item_id = $order->add_product($producto, $cantidad);
            if (!$item_id) {
                error_log("Error agregando producto a la orden");
                return array('success' => false, 'mensaje' => 'Error agregando producto a la orden');
            }
            
            error_log("Producto agregado a la orden con item_id: $item_id");
            
            // Agregar metadatos del item
            $item = $order->get_item($item_id);
            if ($item) {
                $item->add_meta_data('fecha_alquiler_inicio', $datos_alquiler['fecha_inicio']);
                $item->add_meta_data('ID_Variacion', $variation_id);
                
                $fecha_fin_calculada = $datos_alquiler['fecha_fin'];
                
                // Procesar atributos si es variación
                if ($producto->is_type('variation')) {
                    $attributes = $producto->get_variation_attributes();
                    error_log("Atributos de variación: " . print_r($attributes, true));
                    
                    foreach ($attributes as $attr_name => $attr_value) {
                        if (stripos($attr_name, 'talla') !== false || stripos($attr_name, 'size') !== false) {
                            $item->add_meta_data('Talla', $attr_value);
                            error_log("Talla agregada: $attr_value");
                        } elseif (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                            $item->add_meta_data('Dias de alquiler', $attr_value);
                            error_log("Período agregado: $attr_value");
                            
                            // Calcular fecha fin basada en el período
                            $periodo_dias = intval($matches[1]);
                            try {
                                $inicio = new DateTime($datos_alquiler['fecha_inicio']);
                                $fin = clone $inicio;
                                $fin->add(new DateInterval('P' . ($periodo_dias - 1) . 'D'));
                                $fecha_fin_calculada = $fin->format('Y-m-d');
                                error_log("Fecha fin calculada: $fecha_fin_calculada");
                            } catch (Exception $e) {
                                error_log("Error calculando fecha fin: " . $e->getMessage());
                                $fecha_fin_calculada = $datos_alquiler['fecha_fin'];
                            }
                        } elseif (stripos($attr_name, 'color') === false) {
                            // Agregar otros atributos (excepto color)
                            $attr_label = wc_attribute_label(str_replace('attribute_', '', $attr_name));
                            $item->add_meta_data($attr_label, $attr_value);
                        }
                    }
                }
                
                $item->add_meta_data('fecha_alquiler_fin', $fecha_fin_calculada);
                $item->save_meta_data();
                
                error_log("Metadatos guardados para el item");
            }
            
            // Calcular garantía
            $garantia = get_post_meta($variation_id, '_valor_garantia', true);
            if (!$garantia && $variation_id !== $product_id) {
                $garantia = get_post_meta($product_id, '_valor_garantia', true);
            }
            $garantia_total = floatval($garantia ?: 0) * $cantidad;
            
            error_log("Garantía calculada: $garantia_total");
            
            return array(
                'success' => true,
                'garantia' => $garantia_total,
                'fecha_fin' => $fecha_fin_calculada ?? $datos_alquiler['fecha_fin']
            );
            
        } catch (Exception $e) {
            error_log("Excepción en agregar_producto_orden: " . $e->getMessage());
            return array('success' => false, 'mensaje' => 'Error: ' . $e->getMessage());
        }
    }
    
    function obtener_o_crear_cliente($datos) {
        if (!empty($datos['cliente_id'])) {
            return intval($datos['cliente_id']);
        }
        
        if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
            $email = sanitize_email($datos['nuevo_cliente_email']);
            $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
            $telefono = sanitize_text_field($datos['nuevo_cliente_telefono'] ?? '');
            
            // Verificar si el usuario ya existe
            $user = get_user_by('email', $email);
            if ($user) {
                return $user->ID;
            }
            
            // Crear nuevo usuario
            $username = sanitize_user(str_replace(' ', '', strtolower($nombre)) . '_' . time());
            $password = wp_generate_password();
            
            $user_id = wp_create_user($username, $password, $email);
            
            if (!is_wp_error($user_id)) {
                $user = new WP_User($user_id);
                $user->set_role('customer');
                
                // Actualizar información del usuario
                update_user_meta($user_id, 'first_name', $nombre);
                update_user_meta($user_id, 'display_name', $nombre);
                update_user_meta($user_id, 'billing_first_name', $nombre);
                update_user_meta($user_id, 'billing_email', $email);
                
                if ($telefono) {
                    update_user_meta($user_id, 'billing_phone', $telefono);
                }
                
                return $user_id;
            } else {
                error_log("Error creando usuario: " . $user_id->get_error_message());
            }
        }
        
        return false;
    }
    
    function aplicar_descuento_orden($order, $descuento_porcentaje) {
        if ($descuento_porcentaje > 0 && $descuento_porcentaje <= 100) {
            $order->calculate_totals();
            $total_antes = $order->get_total();
            $descuento_monto = $total_antes * ($descuento_porcentaje / 100);
            
            // Crear cupón temporal
            $coupon_code = 'DESCUENTO_' . $order->get_id() . '_' . time();
            $coupon = new WC_Coupon();
            $coupon->set_code($coupon_code);
            $coupon->set_discount_type('fixed_cart');
            $coupon->set_amount($descuento_monto);
            $coupon->set_usage_limit(1);
            $coupon->save();
            
            $order->apply_coupon($coupon_code);
            
            error_log("Descuento aplicado: $descuento_porcentaje% = S/. $descuento_monto");
        }
    }
    
    /**
     * ================================================================
     * 5. FUNCIONES AJAX CORREGIDAS
     * ================================================================
     */
    
  add_action('wp_ajax_obtener_variaciones_alquiler', 'ajax_obtener_variaciones_alquiler_corregida');
function ajax_obtener_variaciones_alquiler_corregida() {
    if (!wp_verify_nonce($_POST['nonce'], 'variaciones_alquiler_nonce')) {
        wp_send_json_error('Nonce inválido');
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    $product = wc_get_product($product_id);
    
    if (!$product) {
        wp_send_json_error('Producto no encontrado');
        return;
    }
    
    error_log("=== DEBUG VARIACIONES ===");
    error_log("Product ID: $product_id");
    error_log("Product Type: " . $product->get_type());
    error_log("Is Variable: " . ($product->is_type('variable') ? 'SÍ' : 'NO'));
    
    $variations = array();
    
    if ($product->is_type('variable')) {
        error_log("Procesando como producto VARIABLE");
        
        // Obtener variaciones disponibles
        $available_variations = $product->get_available_variations();
        error_log("Variaciones disponibles: " . count($available_variations));
        
        if (empty($available_variations)) {
            error_log("No hay variaciones disponibles - intentando método alternativo");
            
            // Método alternativo: obtener variaciones directamente
            $variation_ids = $product->get_children();
            error_log("IDs de variaciones: " . print_r($variation_ids, true));
            
            foreach ($variation_ids as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation || !$variation->is_purchasable()) {
                    continue;
                }
                
                $variation_data = procesar_variacion_individual($variation, $product);
                if ($variation_data) {
                    $variations[] = $variation_data;
                }
            }
        } else {
            // Método normal
            foreach ($available_variations as $variation_data) {
                $variation = wc_get_product($variation_data['variation_id']);
                if (!$variation || !$variation->is_purchasable()) {
                    continue;
                }
                
                $processed_variation = procesar_variacion_con_datos($variation_data, $variation, $product);
                if ($processed_variation) {
                    $variations[] = $processed_variation;
                }
            }
        }
        
    } elseif ($product->is_type('simple')) {
        error_log("Procesando como producto SIMPLE");
        
        $garantia = get_post_meta($product_id, '_valor_garantia', true);
        
        $variations[] = array(
            'id' => $product_id,
            'nombre_completo' => $product->get_name(),
            'talla' => '',
            'periodo_dias' => null,
            'price' => $product->get_price(),
            'price_formatted' => wc_price($product->get_price()),
            'garantia' => floatval($garantia ?: 0),
            'precio_por_dia' => 0
        );
    }
    
    error_log("Total variaciones procesadas: " . count($variations));
    error_log("Variaciones finales: " . print_r($variations, true));
    
    if (empty($variations)) {
        wp_send_json_error('No se encontraron variaciones válidas para este producto');
        return;
    }
    
    wp_send_json_success($variations);
}
/**
 * Procesa una variación individual (método alternativo)
 */
function procesar_variacion_individual($variation, $parent_product) {
    try {
        $variation_id = $variation->get_id();
        $attributes = $variation->get_variation_attributes();
        
        error_log("Procesando variación ID: $variation_id");
        error_log("Atributos: " . print_r($attributes, true));
        
        $talla = '';
        $periodo_dias = null;
        
        // Buscar talla y período en los atributos
        foreach ($attributes as $attr_name => $attr_value) {
            $attr_name_clean = str_replace('attribute_', '', $attr_name);
            
            error_log("Atributo: $attr_name_clean = $attr_value");
            
            // Detectar talla
            if (stripos($attr_name_clean, 'talla') !== false || 
                stripos($attr_name_clean, 'size') !== false ||
                stripos($attr_name_clean, 'pa_talla') !== false) {
                $talla = $attr_value;
                error_log("Talla detectada: $talla");
            }
            
            // Detectar período de días
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_dias = intval($matches[1]);
                error_log("Período detectado: $periodo_dias días");
            } elseif (stripos($attr_name_clean, 'dias') !== false || 
                      stripos($attr_name_clean, 'periodo') !== false ||
                      stripos($attr_name_clean, 'alquiler') !== false) {
                // Intentar extraer número del valor
                if (preg_match('/(\d+)/', $attr_value, $matches)) {
                    $periodo_dias = intval($matches[1]);
                    error_log("Período extraído: $periodo_dias");
                }
            }
        }
        
        // Obtener garantía
        $garantia = get_post_meta($variation_id, '_valor_garantia', true);
        if (!$garantia) {
            $garantia = get_post_meta($parent_product->get_id(), '_valor_garantia', true);
        }
        
        // Construir nombre completo
        $nombre_completo = $parent_product->get_name();
        $partes = array();
        if ($talla) $partes[] = 'Talla ' . $talla;
        if ($periodo_dias) $partes[] = $periodo_dias . ' días';
        
        if (!empty($partes)) {
            $nombre_completo .= ' (' . implode(', ', $partes) . ')';
        }
        
        $variation_data = array(
            'id' => $variation_id,
            'nombre_completo' => $nombre_completo,
            'talla' => $talla,
            'periodo_dias' => $periodo_dias,
            'price' => $variation->get_price(),
            'price_formatted' => wc_price($variation->get_price()),
            'garantia' => floatval($garantia ?: 0),
            'precio_por_dia' => $periodo_dias > 0 ? round($variation->get_price() / $periodo_dias, 2) : 0
        );
        
        error_log("Variación procesada: " . print_r($variation_data, true));
        return $variation_data;
        
    } catch (Exception $e) {
        error_log("Error procesando variación: " . $e->getMessage());
        return null;
    }
}

/**
 * Procesa variación con datos de WooCommerce (método normal)
 */
function procesar_variacion_con_datos($variation_data, $variation, $parent_product) {
    try {
        $variation_id = $variation_data['variation_id'];
        
        error_log("Procesando variación con datos WC: $variation_id");
        
        $talla = '';
        $periodo_dias = null;
        
        // Procesar atributos desde variation_data
        foreach ($variation_data['attributes'] as $attr_name => $attr_value) {
            $attr_name_clean = str_replace('attribute_', '', $attr_name);
            
            if (stripos($attr_name_clean, 'talla') !== false || 
                stripos($attr_name_clean, 'size') !== false ||
                stripos($attr_name_clean, 'pa_talla') !== false) {
                $talla = $attr_value;
            } elseif (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_dias = intval($matches[1]);
            }
        }
        
        // Si no se encontró en variation_data, buscar en el producto
        if (!$talla && !$periodo_dias) {
            $attributes = $variation->get_variation_attributes();
            foreach ($attributes as $attr_name => $attr_value) {
                $attr_name_clean = str_replace('attribute_', '', $attr_name);
                
                if (stripos($attr_name_clean, 'talla') !== false && !$talla) {
                    $talla = $attr_value;
                } elseif (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches) && !$periodo_dias) {
                    $periodo_dias = intval($matches[1]);
                }
            }
        }
        
        $garantia = get_post_meta($variation_id, '_valor_garantia', true);
        if (!$garantia) {
            $garantia = get_post_meta($parent_product->get_id(), '_valor_garantia', true);
        }
        
        $nombre_completo = $parent_product->get_name();
        $partes = array();
        if ($talla) $partes[] = 'Talla ' . $talla;
        if ($periodo_dias) $partes[] = $periodo_dias . ' días';
        
        if (!empty($partes)) {
            $nombre_completo .= ' (' . implode(', ', $partes) . ')';
        }
        
        return array(
            'id' => $variation_id,
            'nombre_completo' => $nombre_completo,
            'talla' => $talla,
            'periodo_dias' => $periodo_dias,
            'price' => $variation->get_price(),
            'price_formatted' => wc_price($variation->get_price()),
            'garantia' => floatval($garantia ?: 0),
            'precio_por_dia' => $periodo_dias > 0 ? round($variation->get_price() / $periodo_dias, 2) : 0
        );
        
    } catch (Exception $e) {
        error_log("Error en procesar_variacion_con_datos: " . $e->getMessage());
        return null;
    }
}

/**
 * MODIFICACIÓN: Agregar selector de días personalizado
 * Agregar estas funciones al sistema de alquiler existente
 */

/**
 * Función AJAX mejorada que permite selección de días personalizada
 */
add_action('wp_ajax_obtener_variaciones_alquiler_flexible', 'ajax_obtener_variaciones_alquiler_flexible');
function ajax_obtener_variaciones_alquiler_flexible() {
    if (!wp_verify_nonce($_POST['nonce'], 'variaciones_alquiler_nonce')) {
        wp_send_json_error('Nonce inválido');
        return;
    }
    
    $product_id = intval($_POST['product_id']);
    $product = wc_get_product($product_id);
    
    if (!$product) {
        wp_send_json_error('Producto no encontrado');
        return;
    }
    
    $variations = array();
    
    if ($product->is_type('variable')) {
        // Obtener solo las tallas disponibles (sin días fijos)
        $available_variations = $product->get_available_variations();
        $tallas_disponibles = array();
        
        foreach ($available_variations as $variation_data) {
            $variation = wc_get_product($variation_data['variation_id']);
            if (!$variation || !$variation->is_purchasable()) continue;
            
            // Extraer solo la talla
            $talla = '';
            foreach ($variation_data['attributes'] as $attr_name => $attr_value) {
                if (stripos($attr_name, 'talla') !== false) {
                    $talla = $attr_value;
                    break;
                }
            }
            
            // Agrupar por talla, no por días
            if (!isset($tallas_disponibles[$talla])) {
                $garantia = get_post_meta($variation_data['variation_id'], '_valor_garantia', true);
                if (!$garantia) {
                    $garantia = get_post_meta($product_id, '_valor_garantia', true);
                }
                
                $tallas_disponibles[$talla] = array(
                    'variation_id' => $variation_data['variation_id'], // Usar cualquiera como referencia
                    'talla' => $talla,
                    'price_base' => $variation->get_price(), // Precio base por día
                    'garantia' => floatval($garantia ?: 0),
                );
            }
        }
        
        // Convertir a formato esperado
        foreach ($tallas_disponibles as $talla_data) {
            $variations[] = array(
                'id' => $talla_data['variation_id'],
                'talla' => $talla_data['talla'],
                'price_base' => $talla_data['price_base'],
                'garantia' => $talla_data['garantia'],
                'tipo' => 'flexible' // Indicador de que es flexible
            );
        }
        
    } elseif ($product->is_type('simple')) {
        $garantia = get_post_meta($product_id, '_valor_garantia', true);
        
        $variations[] = array(
            'id' => $product_id,
            'talla' => '',
            'price_base' => $product->get_price(),
            'garantia' => floatval($garantia ?: 0),
            'tipo' => 'flexible'
        );
    }
    
    wp_send_json_success($variations);
}

/**
 * Modificar la función JavaScript para manejar días flexibles
 */
function incluir_javascript_alquiler_flexible() {
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // ... código existente ...
        
        function mostrarMatrizSeleccionFlexible(variaciones, $item) {
            const $matriz = $item.find('.matriz-seleccion');
            const index = $item.attr('data-index');
            
            console.log('Mostrando matriz flexible:', variaciones);
            
            let html = '<div class="variaciones-matriz-flexible">';
            html += '<h6>Selecciona talla y días de alquiler:</h6>';
            
            variaciones.forEach(function(variacion, idx) {
                html += '<div class="talla-flexible-container" data-variation-base="' + variacion.id + '">';
                html += '<h6>Talla: ' + (variacion.talla || 'Única') + '</h6>';
                
                // Selector de días personalizable
                html += '<div class="selector-dias-personalizado">';
                html += '<div class="dias-preestablecidos">';
                html += '<label>Días de alquiler:</label><br>';
                
                // Opciones rápidas
                const diasComunes = [1, 3, 5, 7, 10, 15, 20, 30];
                diasComunes.forEach(function(dias) {
                    const precioTotal = (variacion.price_base * dias).toFixed(2);
                    html += '<div class="opcion-dias" data-dias="' + dias + '" data-variation-id="' + variacion.id + '">';
                    html += '<input type="radio" name="productos[' + index + '][variacion_seleccionada]" value="' + variacion.id + '|' + dias + '" id="dias_' + index + '_' + idx + '_' + dias + '" style="display:none;">';
                    html += '<label for="dias_' + index + '_' + idx + '_' + dias + '" class="dias-card">';
                    html += '<div class="dias-numero">' + dias + ' día' + (dias > 1 ? 's' : '') + '</div>';
                    html += '<div class="dias-precio">S/. ' + precioTotal + '</div>';
                    html += '<div class="precio-por-dia">S/. ' + variacion.price_base + ' /día</div>';
                    html += '</label>';
                    html += '</div>';
                });
                
                html += '</div>';
                
                // Selector personalizado
                html += '<div class="selector-personalizado">';
                html += '<label>O cantidad personalizada:</label>';
                html += '<div class="input-personalizado">';
                html += '<input type="number" class="dias-custom" min="1" max="365" placeholder="Días" data-price-base="' + variacion.price_base + '" data-variation-id="' + variacion.id + '">';
                html += '<button type="button" class="btn-aplicar-custom">Aplicar</button>';
                html += '</div>';
                html += '<div class="precio-custom-preview"></div>';
                html += '</div>';
                
                html += '</div>';
                html += '</div>';
            });
            
            html += '</div>';
            $matriz.html(html).show();
            
            // Manejar selección de días preestablecidos
            $matriz.find('.opcion-dias').click(function() {
                const dias = $(this).data('dias');
                const variationId = $(this).data('variation-id');
                const priceBase = parseFloat($(this).closest('.talla-flexible-container').find('.dias-custom').data('price-base'));
                
                $matriz.find('.opcion-dias').removeClass('selected');
                $matriz.find('.dias-custom').val('');
                $(this).addClass('selected');
                $(this).find('input[type="radio"]').prop('checked', true);
                
                mostrarResumenSeleccionFlexible({
                    variation_id: variationId,
                    talla: $(this).closest('.talla-flexible-container').find('h6').text().replace('Talla: ', ''),
                    dias: dias,
                    precio_total: priceBase * dias,
                    precio_por_dia: priceBase
                }, $item);
            });
            
            // Manejar días personalizados
            $matriz.find('.btn-aplicar-custom').click(function() {
                const $container = $(this).closest('.selector-personalizado');
                const $input = $container.find('.dias-custom');
                const dias = parseInt($input.val());
                const priceBase = parseFloat($input.data('price-base'));
                const variationId = $input.data('variation-id');
                
                if (!dias || dias < 1) {
                    alert('Por favor ingresa un número válido de días');
                    return;
                }
                
                const precioTotal = priceBase * dias;
                
                // Limpiar selecciones anteriores
                $matriz.find('.opcion-dias').removeClass('selected');
                $matriz.find('input[type="radio"]').prop('checked', false);
                
                // Crear input hidden para días personalizados
                const hiddenInputName = 'productos[' + $item.attr('data-index') + '][variacion_seleccionada]';
                $item.find('input[name="' + hiddenInputName + '"]').remove();
                $item.append('<input type="hidden" name="' + hiddenInputName + '" value="' + variationId + '|' + dias + '">');
                
                mostrarResumenSeleccionFlexible({
                    variation_id: variationId,
                    talla: $(this).closest('.talla-flexible-container').find('h6').text().replace('Talla: ', ''),
                    dias: dias,
                    precio_total: precioTotal,
                    precio_por_dia: priceBase
                }, $item);
            });
            
            // Preview en tiempo real para días personalizados
            $matriz.find('.dias-custom').on('input', function() {
                const dias = parseInt($(this).val()) || 0;
                const priceBase = parseFloat($(this).data('price-base'));
                const $preview = $(this).closest('.selector-personalizado').find('.precio-custom-preview');
                
                if (dias > 0) {
                    const total = (priceBase * dias).toFixed(2);
                    $preview.html('<strong>Total: S/. ' + total + '</strong>');
                } else {
                    $preview.html('');
                }
            });
        }
        
        function mostrarResumenSeleccionFlexible(seleccion, $item) {
            const $resumen = $item.find('.resumen-seleccion');
            
            let html = '<div class="seleccion-resumen">';
            html += '<h6>✅ Seleccionado:</h6>';
            if (seleccion.talla) {
                html += '<div><strong>Talla:</strong> ' + seleccion.talla + '</div>';
            }
            html += '<div><strong>Período:</strong> ' + seleccion.dias + ' día' + (seleccion.dias > 1 ? 's' : '') + '</div>';
            html += '<div><strong>Precio por día:</strong> S/. ' + seleccion.precio_por_dia.toFixed(2) + '</div>';
            html += '<div><strong>Precio total:</strong> S/. ' + seleccion.precio_total.toFixed(2) + '</div>';
            html += '</div>';
            
            $resumen.html(html);
            $item.find('.producto-paso-3').show();
            
            actualizarResumenTotal();
        }
        
        // Modificar la función de carga para usar el nuevo sistema
        function cargarVariacionesProductoFlexible(productId, $item) {
            // ... usar ajax_obtener_variaciones_alquiler_flexible ...
            $.post(ajaxurl, {
                action: 'obtener_variaciones_alquiler_flexible',
                product_id: productId,
                nonce: '<?php echo wp_create_nonce("variaciones_alquiler_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    mostrarMatrizSeleccionFlexible(response.data, $item);
                } else {
                    // Error handling
                }
            });
        }
    });
    </script>
    <?php
}

/**
 * CSS para el selector flexible
 */
function incluir_css_selector_flexible() {
    ?>
    <style>
    .variaciones-matriz-flexible {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin: 15px 0;
    }
    
    .talla-flexible-container {
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 15px;
        margin-bottom: 15px;
    }
    
    .dias-preestablecidos {
        margin-bottom: 20px;
    }
    
    .opcion-dias {
        display: inline-block;
        margin: 5px;
        cursor: pointer;
    }
    
    .dias-card {
        display: block;
        background: white;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        padding: 10px 15px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        min-width: 80px;
    }
    
    .dias-card:hover {
        border-color: #667eea;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    
    .opcion-dias.selected .dias-card {
        border-color: #667eea;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    
    .dias-numero {
        font-weight: bold;
        font-size: 14px;
    }
    
    .dias-precio {
        font-size: 16px;
        font-weight: bold;
        color: #28a745;
        margin: 5px 0;
    }
    
    .opcion-dias.selected .dias-precio {
        color: white;
    }
    
    .precio-por-dia {
        font-size: 11px;
        opacity: 0.8;
    }
    
    .selector-personalizado {
        border-top: 1px solid #e9ecef;
        padding-top: 15px;
        background: #f1f3f5;
        padding: 15px;
        border-radius: 6px;
    }
    
    .input-personalizado {
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 10px;
    }
    
    .dias-custom {
        width: 80px;
        padding: 8px;
        border: 1px solid #ccc;
        border-radius: 4px;
    }
    
    .btn-aplicar-custom {
        background: #667eea;
        color: white;
        border: none;
        padding: 8px 15px;
        border-radius: 4px;
        cursor: pointer;
    }
    
    .btn-aplicar-custom:hover {
        background: #5a67d8;
    }
    
    .precio-custom-preview {
        margin-top: 10px;
        color: #28a745;
        font-weight: bold;
    }
    </style>
    <?php
}



/**
 * FUNCIÓN DE DEBUG ADICIONAL
 * Agregar esta función para debugging manual
 */
function debug_producto_variaciones($product_id) {
    $product = wc_get_product($product_id);
    
    echo "<h3>DEBUG Producto ID: $product_id</h3>";
    echo "<p><strong>Tipo:</strong> " . $product->get_type() . "</p>";
    echo "<p><strong>Nombre:</strong> " . $product->get_name() . "</p>";
    
    if ($product->is_type('variable')) {
        echo "<h4>Variaciones:</h4>";
        $variations = $product->get_available_variations();
        
        foreach ($variations as $variation_data) {
            echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 5px;'>";
            echo "<strong>ID:</strong> " . $variation_data['variation_id'] . "<br>";
            echo "<strong>Precio:</strong> " . wc_price($variation_data['display_price']) . "<br>";
            echo "<strong>Atributos:</strong><br>";
            
            foreach ($variation_data['attributes'] as $attr => $value) {
                echo "&nbsp;&nbsp;$attr: $value<br>";
            }
            echo "</div>";
        }
        
        // También mostrar children IDs
        echo "<h4>Children IDs:</h4>";
        $children = $product->get_children();
        foreach ($children as $child_id) {
            $child = wc_get_product($child_id);
            echo "<p>ID: $child_id - " . $child->get_name() . " - " . wc_price($child->get_price()) . "</p>";
        }
    }
}
    add_action('wp_ajax_verificar_disponibilidad_alquiler', 'ajax_verificar_disponibilidad_alquiler');
    function ajax_verificar_disponibilidad_alquiler() {
        if (!wp_verify_nonce($_POST['nonce'], 'disponibilidad_alquiler_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        $variation_id = intval($_POST['variation_id']);
        $cantidad = intval($_POST['cantidad']) ?: 1;
        
        // Verificación básica de stock
        $producto = wc_get_product($variation_id ?: $product_id);
        if (!$producto) {
            wp_send_json_success(array(
                'disponible' => false,
                'mensaje' => 'Producto no encontrado'
            ));
            return;
        }
        
        if ($producto->managing_stock()) {
            $stock = $producto->get_stock_quantity();
            if ($stock < $cantidad) {
                wp_send_json_success(array(
                    'disponible' => false,
                    'mensaje' => "Stock insuficiente. Disponible: {$stock}"
                ));
                return;
            }
        }
        
        wp_send_json_success(array(
            'disponible' => true,
            'mensaje' => 'Producto disponible'
        ));
    }
    
    /**
     * ================================================================
     * 6. FUNCIONES AUXILIARES
     * ================================================================
     */
    
    function obtener_productos_alquiler() {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 100,
            'meta_query' => array(
                array(
                    'key' => '_es_alquiler',
                    'value' => 'yes',
                    'compare' => '='
                )
            )
        );
        
        $productos = get_posts($args);
        $productos_wc = array();
        
        foreach ($productos as $producto) {
            $producto_wc = wc_get_product($producto->ID);
            if ($producto_wc) {
                $productos_wc[] = $producto_wc;
            }
        }
        
        return $productos_wc;
    }
    
    function obtener_estadisticas_alquileres() {
        $args = array(
            'post_type' => 'shop_order',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_es_alquiler',
                    'value' => 'yes',
                    'compare' => '='
                )
            ),
            'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending')
        );
        
        $alquileres = get_posts($args);
        
        $stats = array(
            'total' => count($alquileres),
            'activos' => 0,
            'pendientes' => 0,
            'completados' => 0
        );
        
        foreach ($alquileres as $alquiler) {
            $estado = get_post_meta($alquiler->ID, '_estado_alquiler', true) ?: 'pending';
            
            switch ($estado) {
                case 'confirmed':
                case 'entregado':
                    $stats['activos']++;
                    break;
                case 'pending':
                    $stats['pendientes']++;
                    break;
                case 'devuelto':
                    $stats['completados']++;
                    break;
            }
        }
        
        return $stats;
    }
    
    /**
     * ================================================================
     * 7. JAVASCRIPT CORREGIDO
     * ================================================================
     */
    
    function incluir_javascript_alquiler_corregido() {
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            let contadorProductos = 1;
            let variacionesCache = {};
            
            // Toggle nuevo cliente
            $('#toggle-nuevo-cliente').click(function() {
                $('#nuevo-cliente-section').toggle();
                $('#cliente_existente').val('');
            });
            
            $('#cliente_existente').change(function() {
                if ($(this).val()) {
                    $('#nuevo-cliente-section').hide();
                }
            });
            
            // Agregar producto
            $('#agregar-producto').click(function() {
                const nuevoItem = $('.producto-item:first').clone();
                nuevoItem.attr('data-index', contadorProductos);
                nuevoItem.find('.producto-header h4').text('Producto #' + (contadorProductos + 1));
                
                // Actualizar names con el nuevo índice
                nuevoItem.find('[name]').each(function() {
                    const nombre = $(this).attr('name');
                    if (nombre) {
                        const nuevoNombre = nombre.replace('[0]', '[' + contadorProductos + ']');
                        $(this).attr('name', nuevoNombre);
                        console.log('Actualizado name:', nombre, '->', nuevoNombre);
                        
                        if ($(this).hasClass('producto-select')) {
                            $(this).val('');
                        } else if ($(this).hasClass('cantidad-input')) {
                            $(this).val('1');
                        }
                    }
                });
                
                // Resetear estados
                nuevoItem.find('.producto-paso-2, .producto-paso-3').hide();
                nuevoItem.find('.matriz-seleccion').hide().empty();
                nuevoItem.find('.cargando-variaciones').show();
                nuevoItem.find('.resumen-seleccion').empty();
                nuevoItem.find('.verificacion-disponibilidad').empty();
                
                $('#productos-container').append(nuevoItem);
                contadorProductos++;
                actualizarResumenTotal();
            });
            
            // Eliminar producto
            $(document).on('click', '.remove-producto', function() {
                if ($('.producto-item').length > 1) {
                    $(this).closest('.producto-item').remove();
                    actualizarResumenTotal();
                }
            });
            
            // Cambio de producto
            $(document).on('change', '.producto-select', function() {
                const $item = $(this).closest('.producto-item');
                const productId = $(this).val();
                
                console.log('Producto seleccionado:', productId);
                
                $item.find('.producto-paso-2, .producto-paso-3').hide();
                
                if (productId) {
                    cargarVariacionesProducto(productId, $item);
                }
            });
            
            function cargarVariacionesProducto(productId, $item) {
                const $paso2 = $item.find('.producto-paso-2');
                const $cargando = $item.find('.cargando-variaciones');
                const $matriz = $item.find('.matriz-seleccion');
                
                $paso2.show();
                $cargando.show();
                $matriz.hide().empty();
                
                console.log('Cargando variaciones para producto:', productId);
                
                if (variacionesCache[productId]) {
                    mostrarMatrizSeleccion(variacionesCache[productId], $item);
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'obtener_variaciones_alquiler',
                    product_id: productId,
                    nonce: '<?php echo wp_create_nonce("variaciones_alquiler_nonce"); ?>'
                }, function(response) {
                    $cargando.hide();
                    
                    console.log('Respuesta variaciones:', response);
                    
                    if (response.success && response.data.length > 0) {
                        variacionesCache[productId] = response.data;
                        mostrarMatrizSeleccion(response.data, $item);
                    } else {
                        $matriz.html('<div class="error">❌ No se pudieron cargar las variaciones</div>').show();
                    }
                }).fail(function(xhr, status, error) {
                    $cargando.hide();
                    console.error('Error AJAX:', error);
                    $matriz.html('<div class="error">❌ Error de conexión: ' + error + '</div>').show();
                });
            }
            
            function mostrarMatrizSeleccion(variaciones, $item) {
                const $matriz = $item.find('.matriz-seleccion');
                const index = $item.attr('data-index');
                
                console.log('Mostrando matriz para índice:', index);
                console.log('Variaciones:', variaciones);
                
                // Organizar por talla y período
                let tallas = new Set();
                let periodos = new Set();
                let matrizData = {};
                
                variaciones.forEach(function(v) {
                    tallas.add(v.talla || 'Sin talla');
                    periodos.add(v.periodo_dias || 'Simple');
                    
                    const key = (v.talla || 'Sin talla') + '|' + (v.periodo_dias || 'Simple');
                    matrizData[key] = v;
                });
                
                tallas = Array.from(tallas).sort();
                periodos = Array.from(periodos).sort((a, b) => {
                    if (a === 'Simple') return -1;
                    if (b === 'Simple') return 1;
                    return parseInt(a) - parseInt(b);
                });
                
                let html = '<div class="variaciones-matriz">';
                html += '<h6>Selecciona la combinación deseada:</h6>';
                
                if (tallas.length === 1 && tallas[0] !== 'Sin talla') {
                    // Una sola talla
                    html += '<p><strong>Talla:</strong> ' + tallas[0] + '</p>';
                    html += '<div class="periodos-grid">';
                    
                    periodos.forEach(function(periodo) {
                        const key = tallas[0] + '|' + periodo;
                        const variacion = matrizData[key];
                        
                        if (variacion) {
                            html += '<div class="periodo-card" data-variation-id="' + variacion.id + '">';
                            html += '<div class="periodo-titulo">' + (periodo === 'Simple' ? 'Producto Simple' : periodo + ' días') + '</div>';
                            html += '<div class="periodo-precio">' + variacion.price_formatted + '</div>';
                            if (variacion.precio_por_dia > 0) {
                                html += '<div class="precio-dia">S/. ' + variacion.precio_por_dia.toFixed(2) + ' por día</div>';
                            }
                            if (variacion.garantia > 0) {
                                html += '<div class="garantia">Garantía: S/. ' + variacion.garantia + '</div>';
                            }
                            html += '<input type="radio" name="productos[' + index + '][variation_id]" value="' + variacion.id + '" style="display: none;">';
                            html += '</div>';
                        }
                    });
                    
                    html += '</div>';
                } else {
                    // Matriz completa
                    html += '<table class="variaciones-table">';
                    html += '<thead><tr><th>Talla / Período</th>';
                    
                    periodos.forEach(function(periodo) {
                        html += '<th>' + (periodo === 'Simple' ? 'Simple' : periodo + ' días') + '</th>';
                    });
                    
                    html += '</tr></thead><tbody>';
                    
                    tallas.forEach(function(talla) {
                        html += '<tr><td><strong>' + talla + '</strong></td>';
                        
                        periodos.forEach(function(periodo) {
                            const key = talla + '|' + periodo;
                            const variacion = matrizData[key];
                            
                            html += '<td>';
                            if (variacion) {
                                html += '<div class="variacion-option" data-variation-id="' + variacion.id + '">';
                                html += '<div class="precio">' + variacion.price_formatted + '</div>';
                                if (variacion.precio_por_dia > 0) {
                                    html += '<div class="precio-dia">S/. ' + variacion.precio_por_dia.toFixed(2) + '/día</div>';
                                }
                                html += '<input type="radio" name="productos[' + index + '][variation_id]" value="' + variacion.id + '" style="display: none;">';
                                html += '</div>';
                            } else {
                                html += '<span class="no-disponible">-</span>';
                            }
                            html += '</td>';
                        });
                        
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table>';
                }
                
                html += '</div>';
                
                $matriz.html(html).show();
                
                // CORREGIR: Manejar selección de variación
                $matriz.find('.periodo-card, .variacion-option').click(function() {
                    const variationId = $(this).data('variation-id');
                    const variacion = variaciones.find(v => v.id == variationId);
                    
                    console.log('Variación seleccionada:', variationId, variacion);
                    
                    $matriz.find('.periodo-card, .variacion-option').removeClass('selected');
                    $(this).addClass('selected');
                    
                    // CRÍTICO: Asegurar que el input radio se marca correctamente
                    const $radioInput = $(this).find('input[type="radio"]');
                    $radioInput.prop('checked', true);
                    
                    console.log('Input radio marcado:', $radioInput.attr('name'), '=', $radioInput.val());
                    
                    mostrarResumenSeleccion(variacion, $item);
                });
            }
            
            function mostrarResumenSeleccion(variacion, $item) {
                const $resumen = $item.find('.resumen-seleccion');
                
                let html = '<div class="seleccion-resumen">';
                html += '<h6>✅ Seleccionado:</h6>';
                html += '<div><strong>Producto:</strong> ' + variacion.nombre_completo + '</div>';
                if (variacion.talla) {
                    html += '<div><strong>Talla:</strong> ' + variacion.talla + '</div>';
                }
                if (variacion.periodo_dias) {
                    html += '<div><strong>Período:</strong> ' + variacion.periodo_dias + ' días</div>';
                }
                html += '<div><strong>Precio:</strong> ' + variacion.price_formatted + '</div>';
                html += '<div><strong>Garantía:</strong> S/. ' + variacion.garantia + '</div>';
                html += '</div>';
                
                $resumen.html(html);
                $item.find('.producto-paso-3').show();
                
                verificarDisponibilidad($item);
                actualizarResumenTotal();
            }
            
            function verificarDisponibilidad($item) {
                const productId = $item.find('.producto-select').val();
                const variationId = $item.find('input[name*="[variation_id]"]:checked').val();
                const cantidad = $item.find('.cantidad-input').val();
                const $verificacion = $item.find('.verificacion-disponibilidad');
                
                console.log('Verificando disponibilidad:', {productId, variationId, cantidad});
                
                if (!productId || !variationId) {
                    $verificacion.hide();
                    return;
                }
                
                $verificacion.html('<div class="checking">🔄 Verificando disponibilidad...</div>').show();
                
                $.post(ajaxurl, {
                    action: 'verificar_disponibilidad_alquiler',
                    product_id: productId,
                    variation_id: variationId,
                    cantidad: cantidad,
                    nonce: '<?php echo wp_create_nonce("disponibilidad_alquiler_nonce"); ?>'
                }, function(response) {
                    let html = '<div class="disponibilidad-resultado ';
                    html += response.success && response.data.disponible ? 'disponible' : 'no-disponible';
                    html += '">';
                    html += response.success && response.data.disponible ? '✅ ' : '❌ ';
                    html += response.success ? response.data.mensaje : 'Error al verificar';
                    html += '</div>';
                    
                    $verificacion.html(html);
                }).fail(function(xhr, status, error) {
                    console.error('Error verificando disponibilidad:', error);
                    $verificacion.html('<div class="disponibilidad-resultado no-disponible">❌ Error de conexión</div>');
                });
            }
            
            // Cambio de cantidad
            $(document).on('change', '.cantidad-input', function() {
                verificarDisponibilidad($(this).closest('.producto-item'));
                actualizarResumenTotal();
            });
            
            // Cambio de fechas
            $('#fecha_inicio, #fecha_fin').change(function() {
                calcularDuracion();
                
                $('.producto-item').each(function() {
                    verificarDisponibilidad($(this));
                });
            });
            
            function calcularDuracion() {
                const fechaInicio = $('#fecha_inicio').val();
                const fechaFin = $('#fecha_fin').val();
                
                if (fechaInicio && fechaFin) {
                    const inicio = new Date(fechaInicio);
                    const fin = new Date(fechaFin);
                    const diffTime = Math.abs(fin - inicio);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                    
                    $('#duracion-calculada').html(
                        '<div style="background: #e8f4fd; padding: 8px; border-radius: 4px;">' +
                        '<strong>📅 Duración total: ' + diffDays + ' días</strong>' +
                        '</div>'
                    );
                } else {
                    $('#duracion-calculada').empty();
                }
            }
            
            function actualizarResumenTotal() {
                let totalPrecio = 0;
                let totalGarantia = 0;
                let productos = [];
                
                $('.producto-item').each(function() {
                    const $item = $(this);
                    const nombreProducto = $item.find('.producto-select option:selected').text();
                    const cantidad = parseInt($item.find('.cantidad-input').val()) || 1;
                    const variationId = $item.find('input[name*="[variation_id]"]:checked').val();
                    
                    console.log('Calculando resumen - Producto:', {nombreProducto, cantidad, variationId});
                    
                    if (nombreProducto && nombreProducto !== 'Seleccionar producto...' && variationId) {
                        const $resumen = $item.find('.seleccion-resumen');
                        const resumenText = $resumen.text();
                        
                        // Buscar precio y garantía en el texto del resumen
                        const precioMatch = resumenText.match(/Precio:\s*S\/\.\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/);
                        const garantiaMatch = resumenText.match(/Garantía:\s*S\/\.\s*(\d+(?:\.\d+)?)/);
                        
                        if (precioMatch && garantiaMatch) {
                            const precio = parseFloat(precioMatch[1].replace(',', ''));
                            const garantia = parseFloat(garantiaMatch[1]);
                            
                            totalPrecio += precio * cantidad;
                            totalGarantia += garantia * cantidad;
                            
                            productos.push({
                                nombre: nombreProducto.split(' - ')[0],
                                cantidad: cantidad,
                                precio: precio,
                                garantia: garantia
                            });
                        }
                    }
                });
                
                console.log('Resumen total:', {productos, totalPrecio, totalGarantia});
                
                let html = '<div class="resumen-completo">';
                html += '<h4>📋 Resumen del Alquiler</h4>';
                
                if (productos.length > 0) {
                    productos.forEach(function(p) {
                        html += '<div class="producto-resumen">';
                        html += '<strong>' + p.nombre + '</strong> (x' + p.cantidad + ')';
                        html += ' - S/. ' + (p.precio * p.cantidad).toFixed(2);
                        html += ' | Garantía: S/. ' + (p.garantia * p.cantidad).toFixed(2);
                        html += '</div>';
                    });
                    
                    html += '<div class="totales">';
                    html += '<div><strong>💰 Total del Alquiler: S/. ' + totalPrecio.toFixed(2) + '</strong></div>';
                    html += '<div><strong>🔒 Total Garantía: S/. ' + totalGarantia.toFixed(2) + '</strong></div>';
                    html += '</div>';
                } else {
                    html += '<p>No hay productos seleccionados</p>';
                }
                
                html += '</div>';
                
                $('#resumen-total').html(html);
                $('#garantia_total').val(totalGarantia.toFixed(2));
            }
            
            // VALIDACIÓN MEJORADA DEL FORMULARIO
            $('#form-crear-alquiler').submit(function(e) {
                console.log('=== VALIDANDO FORMULARIO ANTES DE ENVÍO ===');
                
                // Validar cliente
                const clienteExistente = $('#cliente_existente').val();
                const nuevoClienteNombre = $('[name="nuevo_cliente_nombre"]').val();
                const nuevoClienteEmail = $('[name="nuevo_cliente_email"]').val();
                
                if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                    e.preventDefault();
                    alert('❌ Debe seleccionar un cliente existente o completar los datos del nuevo cliente');
                    return false;
                }
                
                // Validar productos
                let productosValidos = 0;
                let productosIncompletos = [];
                
                $('.producto-item').each(function(index) {
                    const $item = $(this);
                    const productId = $item.find('.producto-select').val();
                    const variationId = $item.find('input[name*="[variation_id]"]:checked').val();
                    const cantidad = $item.find('.cantidad-input').val();
                    
                    console.log(`Producto ${index + 1}:`, {productId, variationId, cantidad});
                    
                    if (productId && variationId && cantidad > 0) {
                        productosValidos++;
                    } else {
                        productosIncompletos.push(index + 1);
                    }
                });
                
                console.log('Productos válidos:', productosValidos);
                console.log('Productos incompletos:', productosIncompletos);
                
                if (productosValidos === 0) {
                    e.preventDefault();
                    alert('❌ Debe seleccionar al menos un producto completo (con talla/período si aplica)');
                    return false;
                }
                
                if (productosIncompletos.length > 0) {
                    if (!confirm(`⚠️ Los productos #${productosIncompletos.join(', ')} están incompletos y no se incluirán. ¿Continuar?`)) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                // Validar disponibilidad
                let productosNoDisponibles = [];
                $('.disponibilidad-resultado.no-disponible:visible').each(function() {
                    const productoNum = $(this).closest('.producto-item').find('.producto-header h4').text();
                    productosNoDisponibles.push(productoNum);
                });
                
                if (productosNoDisponibles.length > 0) {
                    e.preventDefault();
                    alert(`❌ Los siguientes productos no están disponibles: ${productosNoDisponibles.join(', ')}. Por favor revise antes de continuar.`);
                    return false;
                }
                
                console.log('✅ Formulario válido, enviando...');
                
                // Mostrar indicador de carga
                $('input[type="submit"]').val('⏳ Creando alquiler...').prop('disabled', true);
            });
            
            // DEBUG: Mostrar información del formulario en tiempo real
            if (window.location.search.indexOf('debug=1') !== -1) {
                setInterval(function() {
                    const formData = new FormData(document.getElementById('form-crear-alquiler'));
                    let debugInfo = '<strong>Estado actual:</strong><br>';
                    let productoCount = 0;
                    
                    for (let [key, value] of formData.entries()) {
                        if (key.includes('productos[')) {
                            if (key.includes('[variation_id]') && value) {
                                productoCount++;
                            }
                            debugInfo += `${key}: ${value}<br>`;
                        }
                    }
                    
                    debugInfo += `<br><strong>Productos válidos: ${productoCount}</strong>`;
                    
                    if (!document.getElementById('debug-panel')) {
                        $('body').append('<div id="debug-panel" style="position: fixed; top: 50px; right: 20px; width: 300px; background: yellow; padding: 10px; z-index: 9999; font-size: 11px; max-height: 300px; overflow-y: auto;"></div>');
                    }
                    
                    document.getElementById('debug-panel').innerHTML = debugInfo;
                }, 2000);
            }
        });
        </script>
        <?php
    }
    
    /**
     * ================================================================
     * 8. CSS MEJORADO
     * ================================================================
     */
    
    function incluir_css_alquiler() {
        ?>
        <style>
        .postbox {
            margin-bottom: 20px;
        }
        .postbox h2.hndle {
            padding: 12px 15px;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom: none;
        }
        .postbox .inside {
            padding: 20px;
        }
        .producto-item {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .producto-header {
            margin-bottom: 15px;
            border-bottom: 1px solid #e9ecef;
            padding-bottom: 10px;
        }
        .paso {
            margin-bottom: 20px;
            padding: 15px;
            border-left: 4px solid #667eea;
            background: #f8f9ff;
            border-radius: 0 6px 6px 0;
        }
        .paso h5 {
            margin-top: 0;
            color: #495057;
        }
        .periodos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .periodo-card {
            background: #fff;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        .periodo-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }
        .periodo-card.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .periodo-titulo {
            font-weight: bold;
            margin-bottom: 8px;
        }
        .periodo-precio {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 5px;
        }
        .periodo-card.selected .periodo-precio {
            color: white;
        }
        .precio-dia, .garantia {
            font-size: 12px;
            opacity: 0.8;
        }
        .variaciones-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .variaciones-table th, .variaciones-table td {
            border: 1px solid #dee2e6;
            padding: 10px;
            text-align: center;
        }
        .variaciones-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        .variacion-option {
            background: #fff;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .variacion-option:hover {
            border-color: #667eea;
        }
        .variacion-option.selected {
            border-color: #667eea;
            background: #667eea;
            color: white;
        }
        .no-disponible {
            color: #6c757d;
            font-style: italic;
        }
        .seleccion-resumen {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 6px;
            padding: 15px;
        }
        .seleccion-resumen h6 {
            margin-top: 0;
            color: #0c5460;
        }
        .verificacion-disponibilidad {
            margin-top: 15px;
        }
        .disponibilidad-resultado {
            padding: 10px;
            border-radius: 6px;
            font-weight: bold;
        }
        .disponibilidad-resultado.disponible {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .disponibilidad-resultado.no-disponible {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .checking {
            color: #667eea;
            font-style: italic;
        }
        .error {
            color: #dc3545;
            padding: 10px;
            background: #f8d7da;
            border-radius: 4px;
        }
        .resumen-completo {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .producto-resumen {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .totales {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #667eea;
            font-size: 16px;
        }
        .cargando-variaciones {
            text-align: center;
            color: #667eea;
            font-style: italic;
            padding: 20px;
        }
        /* Estilos adicionales para debug */
        #debug-panel {
            border: 2px solid #ff6b6b !important;
            border-radius: 6px;
        }
        </style>
        <?php
    }
    
    function desactivar_hooks_conflictivos() {
    global $wp_filter;
    
    $hooks_desactivados = array();
    
    // Lista de hooks problemáticos conocidos
    $hooks_problematicos = array(
        'woocommerce_new_order',
        'woocommerce_checkout_create_order',
        'woocommerce_store_api_checkout_order_processed',
        'woocommerce_order_status_changed',
        'woocommerce_calculate_totals',
    );
    
    foreach ($hooks_problematicos as $hook) {
        if (isset($wp_filter[$hook])) {
            // Guardar callbacks del plugin problemático
            $callbacks = $wp_filter[$hook]->callbacks ?? array();
            
            foreach ($callbacks as $priority => $functions) {
                foreach ($functions as $function_key => $function_data) {
                    // Verificar si es del plugin problemático
                    $callback = $function_data['function'];
                    
                    if (is_array($callback) && is_object($callback[0])) {
                        $class = get_class($callback[0]);
                        if (stripos($class, 'costos') !== false || stripos($class, 'envio') !== false) {
                            $hooks_desactivados[$hook][$priority][$function_key] = $function_data;
                            unset($wp_filter[$hook]->callbacks[$priority][$function_key]);
                        }
                    } elseif (is_string($callback) && (stripos($callback, 'costos') !== false || stripos($callback, 'envio') !== false)) {
                        $hooks_desactivados[$hook][$priority][$function_key] = $function_data;
                        unset($wp_filter[$hook]->callbacks[$priority][$function_key]);
                    }
                }
            }
        }
    }
    
    error_log("🔇 Hooks conflictivos desactivados temporalmente");
    return $hooks_desactivados;
}

/**
 * Reactiva los hooks desactivados
 */
function reactivar_hooks_conflictivos($hooks_desactivados) {
    global $wp_filter;
    
    foreach ($hooks_desactivados as $hook => $priorities) {
        foreach ($priorities as $priority => $functions) {
            foreach ($functions as $function_key => $function_data) {
                $wp_filter[$hook]->callbacks[$priority][$function_key] = $function_data;
            }
        }
    }
    
    error_log("🔊 Hooks reactivados");
}

/**
 * Crea una orden específicamente para alquileres, evitando triggers problemáticos
 */
function crear_orden_alquiler_segura($cliente_id) {
    // Crear orden con configuración mínima
    $order = new WC_Order();
    
    // Configuración básica sin triggers
    $order->set_customer_id($cliente_id);
    $order->set_status('pending', '', false); // false = no trigger hooks
    $order->set_currency(get_woocommerce_currency());
    $order->set_prices_include_tax(wc_prices_include_tax());
    
    // Configurar direcciones básicas del cliente
    if ($cliente_id > 0) {
        $customer = new WC_Customer($cliente_id);
        
        $order->set_billing_first_name($customer->get_billing_first_name());
        $order->set_billing_last_name($customer->get_billing_last_name());
        $order->set_billing_email($customer->get_billing_email());
        $order->set_billing_phone($customer->get_billing_phone());
        
        // Direcciones de envío iguales a facturación para alquileres
        $order->set_shipping_first_name($customer->get_billing_first_name());
        $order->set_shipping_last_name($customer->get_billing_last_name());
    }
    
    // Guardar orden básica
    $order->save();
    
    return $order;
}

/**
 * Agrega productos de forma segura evitando conflictos con plugins de envío
 */
function agregar_producto_orden_seguro($order, $producto_data, $datos_alquiler) {
    try {
        $product_id = intval($producto_data['id']);
        $variation_id = intval($producto_data['variation_id']);
        $cantidad = intval($producto_data['cantidad']) ?: 1;
        
        // Cargar producto de forma segura
        $producto = null;
        if ($variation_id && $variation_id !== $product_id) {
            $producto = wc_get_product($variation_id);
        }
        
        if (!$producto) {
            $producto = wc_get_product($product_id);
        }
        
        if (!$producto) {
            return array('success' => false, 'mensaje' => 'Producto no encontrado');
        }
        
        // Verificar que el producto sea válido
        if (!$producto->exists() || !$producto->is_purchasable()) {
            return array('success' => false, 'mensaje' => 'Producto no válido');
        }
        
        // Crear item de orden manualmente para evitar hooks
        $item = new WC_Order_Item_Product();
        $item->set_product($producto);
        $item->set_quantity($cantidad);
        $item->set_subtotal($producto->get_price() * $cantidad);
        $item->set_total($producto->get_price() * $cantidad);
        
        // Agregar metadatos de alquiler
        $item->add_meta_data('fecha_alquiler_inicio', $datos_alquiler['fecha_inicio'], true);
        $item->add_meta_data('ID_Variacion', $variation_id, true);
        
        $fecha_fin_calculada = $datos_alquiler['fecha_fin'];
        
        // Procesar atributos de variación si aplica
        if ($producto->is_type('variation')) {
            $attributes = $producto->get_variation_attributes();
            
            foreach ($attributes as $attr_name => $attr_value) {
                if (stripos($attr_name, 'talla') !== false) {
                    $item->add_meta_data('Talla', $attr_value, true);
                } elseif (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                    $item->add_meta_data('Dias de alquiler', $attr_value, true);
                    
                    // Calcular fecha fin
                    $periodo_dias = intval($matches[1]);
                    try {
                        $inicio = new DateTime($datos_alquiler['fecha_inicio']);
                        $fin = clone $inicio;
                        $fin->add(new DateInterval('P' . ($periodo_dias - 1) . 'D'));
                        $fecha_fin_calculada = $fin->format('Y-m-d');
                    } catch (Exception $e) {
                        $fecha_fin_calculada = $datos_alquiler['fecha_fin'];
                    }
                }
            }
        }
        
        $item->add_meta_data('fecha_alquiler_fin', $fecha_fin_calculada, true);
        
        // Agregar item a la orden
        $order->add_item($item);
        
        // Calcular garantía
        $garantia = get_post_meta($variation_id, '_valor_garantia', true);
        if (!$garantia && $variation_id !== $product_id) {
            $garantia = get_post_meta($product_id, '_valor_garantia', true);
        }
        
        return array(
            'success' => true,
            'garantia' => floatval($garantia ?: 0) * $cantidad,
            'fecha_fin' => $fecha_fin_calculada
        );
        
    } catch (Exception $e) {
        error_log("Error en agregar_producto_orden_seguro: " . $e->getMessage());
        return array('success' => false, 'mensaje' => 'Error: ' . $e->getMessage());
    }
}

/**
 * Configura los metadatos de la orden de alquiler
 */
function configurar_orden_alquiler($order, $datos, $garantia_total, $fecha_fin_real) {
    // Agregar metadatos del alquiler
    $order->add_meta_data('_es_alquiler', 'yes', true);
    $order->add_meta_data('_fecha_inicio_alquiler', sanitize_text_field($datos['fecha_inicio']), true);
    $order->add_meta_data('_fecha_fin_alquiler', $fecha_fin_real, true);
    $order->add_meta_data('_garantia_total', $garantia_total, true);
    $order->add_meta_data('_estado_alquiler', sanitize_text_field($datos['estado_alquiler'] ?? 'confirmed'), true);
    $order->add_meta_data('_metodo_pago_manual', sanitize_text_field($datos['metodo_pago'] ?? 'efectivo'), true);
    
    if (!empty($datos['evento_ocasion'])) {
        $order->add_meta_data('_evento_ocasion', sanitize_text_field($datos['evento_ocasion']), true);
    }
    
    if (!empty($datos['notas_adicionales'])) {
        $order->add_meta_data('_notas_alquiler', sanitize_text_field($datos['notas_adicionales']), true);
    }
    
    // Aplicar descuento si existe
    if (!empty($datos['descuento_porcentaje'])) {
        $descuento_porcentaje = floatval($datos['descuento_porcentaje']);
        if ($descuento_porcentaje > 0 && $descuento_porcentaje <= 100) {
            $order->add_meta_data('_descuento_aplicado', $descuento_porcentaje, true);
        }
    }
    
    // Cambiar estado final
    $order->set_status('processing', 'Alquiler creado desde admin', false);
    
    // Agregar nota
    $nota = "Alquiler del {$datos['fecha_inicio']} al {$fecha_fin_real}";
    if (!empty($datos['evento_ocasion'])) {
        $nota .= " para: {$datos['evento_ocasion']}";
    }
    $order->add_order_note($nota, false, false); // No notificar al cliente
}


} // Fin de cargar_sistema_alquiler()






?>