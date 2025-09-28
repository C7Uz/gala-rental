<?php
/**
 * Formulario de alquiler manual MEJORADO - VERSIÓN SEGURA
 * Evita conflictos con código existente
 */

// Verificar que no existan funciones duplicadas antes de definirlas
if (!function_exists('gala_rental_form_mejorado_menu')) {
    
    // Agregar menú de administración
    add_action('admin_menu', 'gala_rental_form_mejorado_menu');
    function gala_rental_form_mejorado_menu() {
        add_submenu_page(
            'woocommerce',
            'Alquiler Manual v2',
            'Alquiler Manual v2',
            'manage_woocommerce',
            'alquiler-manual-v2',
            'gala_rental_form_v2_page'
        );
    }

    // Endpoint AJAX para obtener variaciones mejorado
    add_action('wp_ajax_get_variations_v2', 'ajax_get_variations_v2');
    function ajax_get_variations_v2() {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'alquiler_manual_nonce')) {
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
            $available_variations = $product->get_available_variations();
            
            foreach ($available_variations as $variation_data) {
                $variation = wc_get_product($variation_data['variation_id']);
                if (!$variation || !$variation->is_purchasable()) {
                    continue;
                }
                
                $attributes = array();
                $periodo_detectado = null;
                
                // Procesar atributos
                foreach ($variation_data['attributes'] as $attr_name => $attr_value) {
                    $attr_label = wc_attribute_label(str_replace('attribute_', '', $attr_name));
                    
                    if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                        $periodo_detectado = intval($matches[1]);
                    }
                    
                    $attributes[] = $attr_label . ': ' . $attr_value;
                }
                
                // Buscar período en el nombre
                if (!$periodo_detectado) {
                    $nombre_variacion = $variation->get_name();
                    if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                        $periodo_detectado = intval($matches[1]);
                    }
                }
                
                // Obtener metadatos
                $garantia = get_post_meta($variation_data['variation_id'], '_valor_garantia', true);
                if (!$garantia) {
                    $garantia = get_post_meta($product_id, '_valor_garantia', true);
                }
                
                $dias_gracia = get_post_meta($variation_data['variation_id'], '_dias_gracia', true);
                if (!$dias_gracia) {
                    $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
                }
                
                $variations[] = array(
                    'id' => $variation_data['variation_id'],
                    'formatted_name' => implode(', ', $attributes),
                    'price' => $variation->get_price(),
                    'price_formatted' => wc_price($variation->get_price()),
                    'garantia' => floatval($garantia ?: 0),
                    'dias_gracia' => intval($dias_gracia ?: 0),
                    'periodo_dias' => $periodo_detectado,
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'managing_stock' => $variation->managing_stock(),
                    'in_stock' => $variation->is_in_stock()
                );
            }
        } elseif ($product->is_type('simple')) {
            $garantia = get_post_meta($product_id, '_valor_garantia', true);
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
            
            $variations[] = array(
                'id' => $product_id,
                'formatted_name' => 'Producto simple',
                'price' => $product->get_price(),
                'price_formatted' => wc_price($product->get_price()),
                'garantia' => floatval($garantia ?: 0),
                'dias_gracia' => intval($dias_gracia ?: 0),
                'periodo_dias' => null,
                'stock_quantity' => $product->get_stock_quantity(),
                'managing_stock' => $product->managing_stock(),
                'in_stock' => $product->is_in_stock()
            );
        }
        
        wp_send_json_success($variations);
    }

    // Endpoint AJAX para verificar disponibilidad
    add_action('wp_ajax_check_availability_v2', 'ajax_check_availability_v2');
    function ajax_check_availability_v2() {
        if (!wp_verify_nonce($_POST['nonce'], 'alquiler_manual_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        $variation_id = intval($_POST['variation_id']);
        $fecha_inicio = sanitize_text_field($_POST['fecha_inicio']);
        $fecha_fin = sanitize_text_field($_POST['fecha_fin']);
        $cantidad = intval($_POST['cantidad']) ?: 1;
        
        $disponible = verificar_disponibilidad_v2($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad);
        
        wp_send_json_success($disponible);
    }

    // Función para verificar disponibilidad (versión simplificada y segura)
    function verificar_disponibilidad_v2($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
        try {
            $inicio = new DateTime($fecha_inicio);
            $fin = new DateTime($fecha_fin);
        } catch (Exception $e) {
            return array(
                'disponible' => false,
                'mensaje' => 'Fechas inválidas'
            );
        }
        
        // Verificar stock básico
        $producto_actual = wc_get_product($variation_id ?: $product_id);
        if ($producto_actual && $producto_actual->managing_stock()) {
            $stock_disponible = $producto_actual->get_stock_quantity();
            if ($stock_disponible < $cantidad_solicitada) {
                return array(
                    'disponible' => false,
                    'mensaje' => "Stock insuficiente. Disponible: {$stock_disponible}"
                );
            }
        }
        
        // Verificar conflictos con alquileres existentes (simplificado)
        $args = array(
            'post_type' => 'shop_order',
            'posts_per_page' => 50, // Limitar para evitar problemas de memoria
            'meta_query' => array(
                array(
                    'key' => '_es_alquiler',
                    'value' => 'yes',
                    'compare' => '='
                )
            ),
            'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
        );
        
        $ordenes_alquiler = get_posts($args);
        
        foreach ($ordenes_alquiler as $orden_post) {
            $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
            $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
            
            if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
            
            try {
                $inicio_orden = new DateTime($fecha_inicio_orden);
                $fin_orden = new DateTime($fecha_fin_orden);
                
                // Verificar solapamiento básico
                if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
                    $orden = wc_get_order($orden_post->ID);
                    if (!$orden) continue;
                    
                    foreach ($orden->get_items() as $item) {
                        $producto_orden = $item->get_product();
                        if (!$producto_orden) continue;
                        
                        $mismo_producto = false;
                        
                        if ($variation_id && $variation_id != $product_id) {
                            if ($producto_orden->get_id() == $variation_id) {
                                $mismo_producto = true;
                            }
                        } else {
                            $producto_id_orden = $producto_orden->is_type('variation') ? 
                                $producto_orden->get_parent_id() : $producto_orden->get_id();
                            if ($producto_id_orden == $product_id) {
                                $mismo_producto = true;
                            }
                        }
                        
                        if ($mismo_producto) {
                            return array(
                                'disponible' => false,
                                'mensaje' => 'Conflicto con orden #' . $orden->get_id()
                            );
                        }
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        return array(
            'disponible' => true,
            'mensaje' => 'Producto disponible'
        );
    }

    // Página del formulario
    function gala_rental_form_v2_page() {
        // Procesar formulario
        if (isset($_POST['crear_alquiler_v2']) && wp_verify_nonce($_POST['alquiler_manual_nonce'], 'crear_alquiler_manual')) {
            $resultado = procesar_alquiler_v2($_POST);
            if ($resultado['success']) {
                echo '<div class="notice notice-success"><p>' . esc_html($resultado['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($resultado['message']) . '</p></div>';
            }
        }

        // Obtener productos de alquiler (reutilizar función existente si existe)
        if (function_exists('get_productos_alquiler')) {
            $productos_alquiler = get_productos_alquiler();
        } else {
            $productos_alquiler = get_productos_alquiler_v2();
        }
        ?>
        <div class="wrap">
            <h1>🏠 Alquiler Manual v2</h1>
            
            <form method="post" action="" id="formulario-alquiler-v2">
                <?php wp_nonce_field('crear_alquiler_manual', 'alquiler_manual_nonce'); ?>
                
                <!-- Información del Cliente -->
                <div class="postbox">
                    <h2 class="hndle">👤 Cliente</h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Cliente Existente</th>
                                <td>
                                    <select name="cliente_id" id="cliente_existente" style="width: 400px;">
                                        <option value="">Seleccionar cliente...</option>
                                        <?php
                                        $clientes = get_users(array('role' => 'customer', 'number' => 50));
                                        foreach ($clientes as $cliente) {
                                            echo '<option value="' . esc_attr($cliente->ID) . '">' . 
                                                 esc_html($cliente->display_name) . ' (' . esc_html($cliente->user_email) . ')</option>';
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Nuevo Cliente</th>
                                <td>
                                    <input type="text" name="nuevo_cliente_nombre" placeholder="Nombre" class="regular-text" />
                                    <input type="email" name="nuevo_cliente_email" placeholder="Email" class="regular-text" />
                                    <input type="tel" name="nuevo_cliente_telefono" placeholder="Teléfono" class="regular-text" />
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Fechas -->
                <div class="postbox">
                    <h2 class="hndle">📅 Fechas</h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Fecha de Inicio</th>
                                <td><input type="date" name="fecha_inicio" id="fecha_inicio" required /></td>
                            </tr>
                            <tr>
                                <th scope="row">Fecha de Fin</th>
                                <td>
                                    <input type="date" name="fecha_fin" id="fecha_fin" required />
                                    <span id="duracion" class="description"></span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Evento/Ocasión</th>
                                <td><input type="text" name="evento_ocasion" placeholder="Ej: Boda, Graduación..." class="regular-text" /></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Productos -->
                <div class="postbox">
                    <h2 class="hndle">👗 Productos</h2>
                    <div class="inside">
                        <div id="productos-lista">
                            <div class="producto-fila" data-index="0">
                                <table class="widefat">
                                    <tr>
                                        <td style="width: 300px;">
                                            <select name="productos[0][id]" class="producto-select" required>
                                                <option value="">Seleccionar producto...</option>
                                                <?php foreach ($productos_alquiler as $producto): ?>
                                                    <option value="<?php echo esc_attr($producto->ID); ?>">
                                                        <?php echo esc_html($producto->get_name() . ' - ' . wc_price($producto->get_price())); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td style="width: 300px;">
                                            <div class="variaciones-container" style="display: none;"></div>
                                        </td>
                                        <td style="width: 80px;">
                                            <input type="number" name="productos[0][cantidad]" class="cantidad-input" value="1" min="1" />
                                        </td>
                                        <td>
                                            <button type="button" class="button remove-producto">Eliminar</button>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="4">
                                            <div class="info-producto" style="display: none;"></div>
                                            <div class="disponibilidad-check" style="display: none;"></div>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                        <button type="button" id="agregar-producto" class="button">Agregar Producto</button>
                    </div>
                </div>
                
                <!-- Información adicional -->
                <div class="postbox">
                    <h2 class="hndle">💰 Información Adicional</h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Descuento (%)</th>
                                <td><input type="number" name="descuento_porcentaje" min="0" max="100" step="0.01" /></td>
                            </tr>
                            <tr>
                                <th scope="row">Garantía Total</th>
                                <td><input type="number" name="garantia_total" id="garantia_total" readonly /></td>
                            </tr>
                            <tr>
                                <th scope="row">Estado</th>
                                <td>
                                    <select name="estado_alquiler">
                                        <option value="confirmed">Confirmado</option>
                                        <option value="pending">Pendiente</option>
                                        <option value="in-progress">En Progreso</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Método de Pago</th>
                                <td>
                                    <select name="metodo_pago">
                                        <option value="efectivo">Efectivo</option>
                                        <option value="transferencia">Transferencia</option>
                                        <option value="tarjeta">Tarjeta</option>
                                        <option value="izi_pay">IziPay</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Notas</th>
                                <td><textarea name="notas_adicionales" rows="3" class="large-text"></textarea></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="crear_alquiler_v2" class="button-primary" value="Crear Alquiler" />
                </p>
            </form>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            let contadorProductos = 1;
            
            // Agregar producto
            $('#agregar-producto').click(function() {
                const nuevaFila = $('.producto-fila:first').clone();
                nuevaFila.attr('data-index', contadorProductos);
                
                nuevaFila.find('[name]').each(function() {
                    const nombre = $(this).attr('name');
                    if (nombre) {
                        $(this).attr('name', nombre.replace('[0]', '[' + contadorProductos + ']'));
                        if ($(this).hasClass('producto-select')) {
                            $(this).val('');
                        } else if ($(this).hasClass('cantidad-input')) {
                            $(this).val('1');
                        }
                    }
                });
                
                nuevaFila.find('.variaciones-container').hide().empty();
                nuevaFila.find('.info-producto').hide().empty();
                nuevaFila.find('.disponibilidad-check').hide().empty();
                
                $('#productos-lista').append(nuevaFila);
                contadorProductos++;
            });
            
            // Eliminar producto
            $(document).on('click', '.remove-producto', function() {
                if ($('.producto-fila').length > 1) {
                    $(this).closest('.producto-fila').remove();
                    calcularTotales();
                }
            });
            
            // Cambio de producto
            $(document).on('change', '.producto-select', function() {
                const $fila = $(this).closest('.producto-fila');
                const productId = $(this).val();
                
                $fila.find('.variaciones-container').hide().empty();
                $fila.find('.info-producto').hide().empty();
                $fila.find('.disponibilidad-check').hide().empty();
                
                if (productId) {
                    $.post(ajaxurl, {
                        action: 'get_variations_v2',
                        product_id: productId,
                        nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
                    }, function(response) {
                        if (response.success && response.data.length > 0) {
                            mostrarVariaciones(response.data, $fila);
                        }
                    });
                }
            });
            
            function mostrarVariaciones(variaciones, $fila) {
                const $container = $fila.find('.variaciones-container');
                const index = $fila.attr('data-index');
                
                if (variaciones.length === 1) {
                    // Solo una variación, seleccionar automáticamente
                    const variacion = variaciones[0];
                    $fila.append('<input type="hidden" name="productos[' + index + '][variation_id]" value="' + variacion.id + '">');
                    mostrarInfoVariacion(variacion, $fila);
                } else {
                    // Múltiples variaciones, mostrar selector
                    let select = '<select name="productos[' + index + '][variation_id]" class="variacion-select">';
                    select += '<option value="">Seleccionar variación...</option>';
                    
                    variaciones.forEach(function(v) {
                        select += '<option value="' + v.id + '" data-info="' + encodeURIComponent(JSON.stringify(v)) + '">';
                        select += v.formatted_name + ' - ' + v.price_formatted;
                        select += '</option>';
                    });
                    
                    select += '</select>';
                    $container.html(select).show();
                }
            }
            
            // Cambio de variación
            $(document).on('change', '.variacion-select', function() {
                const $fila = $(this).closest('.producto-fila');
                const selectedOption = $(this).find(':selected');
                
                if (selectedOption.val()) {
                    const info = JSON.parse(decodeURIComponent(selectedOption.data('info')));
                    mostrarInfoVariacion(info, $fila);
                } else {
                    $fila.find('.info-producto').hide().empty();
                    $fila.find('.disponibilidad-check').hide().empty();
                }
            });
            
            function mostrarInfoVariacion(variacion, $fila) {
                let info = '<div style="background: #f0f8ff; padding: 10px; margin: 5px 0; border-radius: 4px;">';
                info += '<strong>Seleccionado:</strong> ' + variacion.formatted_name + '<br>';
                info += '<strong>Precio:</strong> ' + variacion.price_formatted + '<br>';
                info += '<strong>Garantía:</strong> $' + variacion.garantia + '<br>';
                if (variacion.periodo_dias) {
                    info += '<strong>Período:</strong> ' + variacion.periodo_dias + ' días<br>';
                }
                info += '<strong>Stock:</strong> ';
                if (variacion.managing_stock) {
                    info += (variacion.stock_quantity || 0) + ' unidades';
                } else {
                    info += 'No gestionado';
                }
                info += '</div>';
                
                $fila.find('.info-producto').html(info).show();
                verificarDisponibilidad($fila);
                calcularTotales();
            }
            
            function verificarDisponibilidad($fila) {
                const productId = $fila.find('.producto-select').val();
                const variationId = $fila.find('[name*="[variation_id]"]').val();
                const cantidad = $fila.find('.cantidad-input').val();
                const fechaInicio = $('#fecha_inicio').val();
                const fechaFin = $('#fecha_fin').val();
                
                if (!productId || !fechaInicio || !fechaFin) {
                    $fila.find('.disponibilidad-check').hide();
                    return;
                }
                
                $fila.find('.disponibilidad-check').html('<div style="color: #0073aa;">🔄 Verificando...</div>').show();
                
                $.post(ajaxurl, {
                    action: 'check_availability_v2',
                    product_id: productId,
                    variation_id: variationId || productId,
                    cantidad: cantidad,
                    fecha_inicio: fechaInicio,
                    fecha_fin: fechaFin,
                    nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        let html = '<div style="padding: 8px; border-radius: 4px; ';
                        if (response.data.disponible) {
                            html += 'background: #d4edda; color: #155724;">✅ ' + response.data.mensaje;
                        } else {
                            html += 'background: #f8d7da; color: #721c24;">❌ ' + response.data.mensaje;
                        }
                        html += '</div>';
                        $fila.find('.disponibilidad-check').html(html);
                    }
                });
            }
            
            // Cambio de cantidad
            $(document).on('change', '.cantidad-input', function() {
                verificarDisponibilidad($(this).closest('.producto-fila'));
                calcularTotales();
            });
            
            // Cambio de fechas
            $('#fecha_inicio, #fecha_fin').change(function() {
                calcularDuracion();
                $('.producto-fila').each(function() {
                    verificarDisponibilidad($(this));
                });
            });
            
            function calcularDuracion() {
                const inicio = $('#fecha_inicio').val();
                const fin = $('#fecha_fin').val();
                
                if (inicio && fin) {
                    const date1 = new Date(inicio);
                    const date2 = new Date(fin);
                    const diffTime = Math.abs(date2 - date1);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    $('#duracion').text('(' + diffDays + ' días)');
                }
            }
            
            function calcularTotales() {
                let garantiaTotal = 0;
                
                $('.producto-fila').each(function() {
                    const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                    const infoText = $(this).find('.info-producto').text();
                    const garantiaMatch = infoText.match(/Garantía:\s*\$(\d+(?:\.\d+)?)/);
                    
                    if (garantiaMatch) {
                        const garantia = parseFloat(garantiaMatch[1]) || 0;
                        garantiaTotal += garantia * cantidad;
                    }
                });
                
                $('#garantia_total').val(garantiaTotal.toFixed(2));
            }
            
            // Validar formulario
            $('#formulario-alquiler-v2').submit(function(e) {
                const clienteExistente = $('#cliente_existente').val();
                const nuevoClienteNombre = $('[name="nuevo_cliente_nombre"]').val();
                const nuevoClienteEmail = $('[name="nuevo_cliente_email"]').val();
                
                if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                    e.preventDefault();
                    alert('Debe seleccionar un cliente existente o crear uno nuevo');
                    return false;
                }
                
                // Verificar productos no disponibles
                let productosNoDisponibles = false;
                $('.disponibilidad-check').each(function() {
                    if ($(this).text().includes('❌')) {
                        productosNoDisponibles = true;
                    }
                });
                
                if (productosNoDisponibles) {
                    e.preventDefault();
                    alert('Hay productos no disponibles. Por favor revise la disponibilidad.');
                    return false;
                }
            });
        });
        </script>

        <style>
        .postbox {
            margin-bottom: 20px;
        }
        .postbox h2 {
            padding: 10px 15px;
            margin: 0;
            background: #f1f1f1;
            border-bottom: 1px solid #ddd;
        }
        .postbox .inside {
            padding: 15px;
        }
        .producto-fila {
            margin-bottom: 15px;
            padding: 10px;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .variaciones-container select {
            width: 100%;
        }
        </style>
        <?php
    }

    // Función auxiliar para obtener productos de alquiler
    function get_productos_alquiler_v2() {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 50,
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

    // Función para procesar alquiler (simplificada)
    function procesar_alquiler_v2($datos) {
        try {
            // Validaciones básicas
            if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
                return array('success' => false, 'message' => 'Las fechas son obligatorias');
            }
            
            if (empty($datos['productos']) || !is_array($datos['productos'])) {
                return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
            }
            
            // Obtener o crear cliente (reutilizar función existente si está disponible)
            $cliente_id = null;
            if (function_exists('obtener_o_crear_cliente')) {
                $cliente_id = obtener_o_crear_cliente($datos);
            } else {
                $cliente_id = obtener_o_crear_cliente_v2($datos);
            }
            
            if (!$cliente_id) {
                return array('success' => false, 'message' => 'Error al procesar el cliente');
            }
            
            // Crear la orden
            $order = wc_create_order();
            $order->set_customer_id($cliente_id);
            $order->set_status('processing');
            
            // Agregar productos
            $garantia_total = 0;
            foreach ($datos['productos'] as $producto_data) {
                if (empty($producto_data['id'])) continue;
                
                $product_id = $producto_data['id'];
                $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : null;
                $cantidad = intval($producto_data['cantidad']) ?: 1;
                
                // Obtener el producto
                if ($variation_id && $variation_id != $product_id) {
                    $producto = wc_get_product($variation_id);
                } else {
                    $producto = wc_get_product($product_id);
                }
                
                if (!$producto) continue;
                
                // Agregar a la orden
                $order->add_product($producto, $cantidad);
                
                // Calcular garantía
                $garantia_producto = 0;
                if ($variation_id && $variation_id != $product_id) {
                    $garantia_producto = get_post_meta($variation_id, '_valor_garantia', true);
                    if (!$garantia_producto) {
                        $garantia_producto = get_post_meta($product_id, '_valor_garantia', true);
                    }
                } else {
                    $garantia_producto = get_post_meta($product_id, '_valor_garantia', true);
                }
                
                $garantia_total += floatval($garantia_producto) * $cantidad;
            }
            
            // Aplicar descuento si existe
            if (!empty($datos['descuento_porcentaje'])) {
                $descuento = floatval($datos['descuento_porcentaje']);
                if ($descuento > 0 && $descuento <= 100) {
                    $order->calculate_totals();
                    $total_antes_descuento = $order->get_total();
                    $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                    
                    $coupon = new WC_Coupon();
                    $coupon->set_code('DESCUENTO_V2_' . time());
                    $coupon->set_discount_type('fixed_cart');
                    $coupon->set_amount($descuento_cantidad);
                    $coupon->save();
                    
                    $order->apply_coupon($coupon->get_code());
                }
            }
            
            // Agregar metadatos
            $order->add_meta_data('_es_alquiler', 'yes');
            $order->add_meta_data('_fecha_inicio_alquiler', sanitize_text_field($datos['fecha_inicio']));
            $order->add_meta_data('_fecha_fin_alquiler', sanitize_text_field($datos['fecha_fin']));
            $order->add_meta_data('_garantia_total', $garantia_total);
            $order->add_meta_data('_estado_alquiler', sanitize_text_field($datos['estado_alquiler']));
            $order->add_meta_data('_metodo_pago_manual', sanitize_text_field($datos['metodo_pago']));
            
            if (!empty($datos['evento_ocasion'])) {
                $order->add_meta_data('_evento_ocasion', sanitize_text_field($datos['evento_ocasion']));
            }
            
            if (!empty($datos['notas_adicionales'])) {
                $order->add_meta_data('_notas_alquiler', sanitize_text_field($datos['notas_adicionales']));
            }
            
            $order->calculate_totals();
            $order->save();
            
            return array(
                'success' => true,
                'message' => 'Alquiler creado exitosamente. Orden #' . $order->get_id(),
                'order_id' => $order->get_id()
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
        }
    }

    // Función auxiliar para crear cliente (si no existe la original)
    function obtener_o_crear_cliente_v2($datos) {
        if (!empty($datos['cliente_id'])) {
            return intval($datos['cliente_id']);
        }
        
        if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
            $email = sanitize_email($datos['nuevo_cliente_email']);
            $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
            $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
            
            $usuario_existente = get_user_by('email', $email);
            if ($usuario_existente) {
                return $usuario_existente->ID;
            }
            
            $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
            $password = wp_generate_password();
            
            $user_id = wp_create_user($username, $password, $email);
            
            if (!is_wp_error($user_id)) {
                $user = new WP_User($user_id);
                $user->set_role('customer');
                
                update_user_meta($user_id, 'first_name', $nombre);
                update_user_meta($user_id, 'display_name', $nombre);
                
                if ($telefono) {
                    update_user_meta($user_id, 'billing_phone', $telefono);
                }
                
                return $user_id;
            }
        }
        
        return false;
    }

} // Fin del if(!function_exists)
?>