<?php
/**
 * Formulario de alquiler manual - VERSIÓN CORREGIDA CON METADATOS
 * Agrega productos correctamente con todos los metadatos como en la imagen
 */

if (!function_exists('gala_rental_corregido_menu')) {
    
    // Agregar menú de administración
    add_action('admin_menu', 'gala_rental_corregido_menu');
    function gala_rental_corregido_menu() {
        add_submenu_page(
            'woocommerce',
            'Alquiler Corregido',
            'Alquiler Corregido',
            'manage_woocommerce',
            'alquiler-corregido',
            'gala_rental_corregido_page'
        );
    }

    // Endpoint AJAX para obtener variaciones completas (talla + días)
    add_action('wp_ajax_get_variaciones_completas', 'ajax_get_variaciones_completas');
    function ajax_get_variaciones_completas() {
        if (!wp_verify_nonce($_POST['nonce'], 'alquiler_manual_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        $fecha_inicio = sanitize_text_field($_POST['fecha_inicio']);
        
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
                
                // Obtener todos los atributos de la variación
                $attributes = array();
                $periodo_dias = null;
                $talla = '';
                
                foreach ($variation_data['attributes'] as $attr_name => $attr_value) {
                    $attr_label = wc_attribute_label(str_replace('attribute_', '', $attr_name));
                    
                    // Detectar si es un atributo de días
                    if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                        $periodo_dias = intval($matches[1]);
                        $attributes['dias'] = array(
                            'label' => $attr_label,
                            'value' => $attr_value,
                            'dias' => $periodo_dias
                        );
                    } else {
                        // Asumir que otros atributos son talla/color/etc
                        $talla = $attr_value;
                        $attributes['talla'] = array(
                            'label' => $attr_label,
                            'value' => $attr_value
                        );
                    }
                }
                
                // Si no encontramos días en atributos, buscar en el nombre
                if (!$periodo_dias) {
                    $full_name = $variation->get_name();
                    if (preg_match('/(\d+)\s*d[ií]as?/i', $full_name, $matches)) {
                        $periodo_dias = intval($matches[1]);
                    }
                }
                
                // Calcular fecha de entrega
                $fecha_entrega = null;
                if ($fecha_inicio && $periodo_dias) {
                    try {
                        $inicio = new DateTime($fecha_inicio);
                        $entrega = clone $inicio;
                        $entrega->add(new DateInterval('P' . ($periodo_dias - 1) . 'D'));
                        $fecha_entrega = $entrega->format('Y-m-d');
                    } catch (Exception $e) {
                        $fecha_entrega = null;
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
                
                // Construir nombre descriptivo
                $display_name = '';
                if ($talla) {
                    $display_name .= $talla;
                }
                if ($periodo_dias) {
                    if ($display_name) $display_name .= ' - ';
                    $display_name .= $periodo_dias . ' días';
                }
                if (!$display_name) {
                    $display_name = 'Variación ' . $variation_data['variation_id'];
                }
                
                $variations[] = array(
                    'id' => $variation_data['variation_id'],
                    'display_name' => $display_name,
                    'talla' => $talla,
                    'periodo_dias' => $periodo_dias,
                    'fecha_entrega' => $fecha_entrega,
                    'price' => $variation->get_price(),
                    'price_formatted' => wc_price($variation->get_price()),
                    'garantia' => floatval($garantia ?: 0),
                    'dias_gracia' => intval($dias_gracia ?: 0),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'managing_stock' => $variation->managing_stock(),
                    'in_stock' => $variation->is_in_stock(),
                    'precio_por_dia' => $periodo_dias > 0 ? round($variation->get_price() / $periodo_dias, 2) : 0,
                    'attributes' => $attributes,
                    'sku' => $variation->get_sku()
                );
            }
            
            // Ordenar por talla y luego por período
            usort($variations, function($a, $b) {
                if ($a['talla'] === $b['talla']) {
                    return ($a['periodo_dias'] ?: 999) - ($b['periodo_dias'] ?: 999);
                }
                return strcmp($a['talla'], $b['talla']);
            });
            
        } elseif ($product->is_type('simple')) {
            $garantia = get_post_meta($product_id, '_valor_garantia', true);
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
            
            $variations[] = array(
                'id' => $product_id,
                'display_name' => 'Producto simple',
                'talla' => '',
                'periodo_dias' => 1,
                'fecha_entrega' => $fecha_inicio,
                'price' => $product->get_price(),
                'price_formatted' => wc_price($product->get_price()),
                'garantia' => floatval($garantia ?: 0),
                'dias_gracia' => intval($dias_gracia ?: 0),
                'stock_quantity' => $product->get_stock_quantity(),
                'managing_stock' => $product->managing_stock(),
                'in_stock' => $product->is_in_stock(),
                'precio_por_dia' => $product->get_price(),
                'attributes' => array(),
                'sku' => $product->get_sku()
            );
        }
        
        wp_send_json_success($variations);
    }

    // Endpoint para verificar disponibilidad
    add_action('wp_ajax_check_disponibilidad_corregida', 'ajax_check_disponibilidad_corregida');
    function ajax_check_disponibilidad_corregida() {
        if (!wp_verify_nonce($_POST['nonce'], 'alquiler_manual_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        $variation_id = intval($_POST['variation_id']);
        $fecha_inicio = sanitize_text_field($_POST['fecha_inicio']);
        $fecha_entrega = sanitize_text_field($_POST['fecha_entrega']);
        $cantidad = intval($_POST['cantidad']) ?: 1;
        
        $disponible = verificar_disponibilidad_corregida($product_id, $variation_id, $fecha_inicio, $fecha_entrega, $cantidad);
        
        wp_send_json_success($disponible);
    }

    // Función para verificar disponibilidad
    function verificar_disponibilidad_corregida($product_id, $variation_id, $fecha_inicio, $fecha_entrega, $cantidad_solicitada = 1) {
        try {
            $inicio = new DateTime($fecha_inicio);
            $entrega = new DateTime($fecha_entrega);
        } catch (Exception $e) {
            return array(
                'disponible' => false,
                'mensaje' => 'Fechas inválidas'
            );
        }
        
        // Obtener días de gracia
        $dias_gracia = 0;
        if ($variation_id && $variation_id != $product_id) {
            $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
            if (!$dias_gracia) {
                $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
            }
        } else {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
        $dias_gracia = intval($dias_gracia ?: 0);
        
        // Verificar stock
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
        
        // Buscar conflictos
        $args = array(
            'post_type' => 'shop_order',
            'posts_per_page' => 100,
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
                
                if ($dias_gracia > 0) {
                    $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
                }
                
                if ($inicio <= $fin_orden && $entrega >= $inicio_orden) {
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
                        
                        if ($mismo_producto && !$producto_actual->managing_stock()) {
                            return array(
                                'disponible' => false,
                                'mensaje' => 'Ocupado del ' . $fecha_inicio_orden . ' al ' . $fecha_fin_orden
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
            'mensaje' => 'Disponible del ' . $fecha_inicio . ' al ' . $fecha_entrega
        );
    }

    // Página del formulario
    function gala_rental_corregido_page() {
        // Procesar formulario
        if (isset($_POST['crear_alquiler_corregido']) && wp_verify_nonce($_POST['alquiler_manual_nonce'], 'crear_alquiler_manual')) {
            $resultado = procesar_alquiler_corregido($_POST);
            if ($resultado['success']) {
                echo '<div class="notice notice-success"><p>' . esc_html($resultado['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($resultado['message']) . '</p></div>';
            }
        }

        // Obtener productos de alquiler
        if (function_exists('get_productos_alquiler')) {
            $productos_alquiler = get_productos_alquiler();
        } else {
            $productos_alquiler = get_productos_alquiler_corregido();
        }
        ?>
        <div class="wrap">
            <h1>🏠 Alquiler Corregido - Con Metadatos Completos</h1>
            <p class="description">Formulario que agrega correctamente los productos con todos sus metadatos.</p>
            
            <form method="post" action="" id="formulario-alquiler-corregido">
                <?php wp_nonce_field('crear_alquiler_manual', 'alquiler_manual_nonce'); ?>
                
                <!-- Cliente -->
                <div class="postbox">
                    <h2 class="hndle">👤 Cliente</h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Cliente</th>
                                <td>
                                    <select name="cliente_id" id="cliente_existente" style="width: 400px;">
                                        <option value="">Seleccionar cliente existente...</option>
                                        <?php
                                        $clientes = get_users(array('role' => 'customer', 'number' => 100));
                                        foreach ($clientes as $cliente) {
                                            echo '<option value="' . esc_attr($cliente->ID) . '">' . 
                                                 esc_html($cliente->display_name) . ' (' . esc_html($cliente->user_email) . ')</option>';
                                        }
                                        ?>
                                    </select>
                                    <button type="button" id="toggle-nuevo-cliente" class="button" style="margin-left: 10px;">➕ Nuevo Cliente</button>
                                </td>
                            </tr>
                            <tr id="nuevo-cliente-row" style="display: none;">
                                <th scope="row">Datos del Nuevo Cliente</th>
                                <td>
                                    <input type="text" name="nuevo_cliente_nombre" placeholder="Nombre completo" class="regular-text" style="margin-right: 10px;" />
                                    <input type="email" name="nuevo_cliente_email" placeholder="Email" class="regular-text" style="margin-right: 10px;" />
                                    <input type="tel" name="nuevo_cliente_telefono" placeholder="Teléfono" class="regular-text" />
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Fecha de Inicio -->
                <div class="postbox">
                    <h2 class="hndle">📅 Fecha de Inicio del Alquiler</h2>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th scope="row">Fecha de Inicio</th>
                                <td>
                                    <input type="date" name="fecha_inicio" id="fecha_inicio" required style="margin-right: 15px;" />
                                    <span class="description">Selecciona la fecha de inicio y el sistema calculará automáticamente las fechas de entrega</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Evento/Ocasión</th>
                                <td>
                                    <input type="text" name="evento_ocasion" placeholder="Ej: Boda, Graduación..." class="large-text" />
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Productos -->
                <div class="postbox">
                    <h2 class="hndle">👗 Seleccionar Productos y Variaciones</h2>
                    <div class="inside">
                        <div id="productos-container-corregido">
                            <div class="producto-corregido-item" data-index="0">
                                <div class="producto-corregido-header">
                                    <h4>Producto #1</h4>
                                    <button type="button" class="button remove-producto-corregido" style="float: right; color: red;">❌ Eliminar</button>
                                    <div class="clearfix"></div>
                                </div>
                                
                                <div class="producto-corregido-content">
                                    <!-- Paso 1: Seleccionar Producto -->
                                    <div class="paso-1">
                                        <h5>1️⃣ Seleccionar Producto</h5>
                                        <select name="productos[0][id]" class="producto-corregido-select" style="width: 100%;" required>
                                            <option value="">Seleccionar producto...</option>
                                            <?php foreach ($productos_alquiler as $producto): ?>
                                                <option value="<?php echo esc_attr($producto->ID); ?>">
                                                    <?php echo esc_html($producto->get_name()); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <!-- Paso 2: Seleccionar Variación -->
                                    <div class="paso-2" style="display: none;">
                                        <h5>2️⃣ Seleccionar Talla y Período de Alquiler</h5>
                                        <div class="variaciones-corregidas-grid"></div>
                                    </div>
                                    
                                    <!-- Paso 3: Cantidad y Resumen -->
                                    <div class="paso-3" style="display: none;">
                                        <h5>3️⃣ Cantidad y Confirmación</h5>
                                        <div class="cantidad-resumen-row">
                                            <div class="cantidad-section">
                                                <label><strong>Cantidad:</strong></label>
                                                <input type="number" name="productos[0][cantidad]" class="cantidad-corregida-input" value="1" min="1" style="width: 80px; margin-left: 10px;" />
                                            </div>
                                            <div class="resumen-selection-corregida"></div>
                                        </div>
                                        <div class="disponibilidad-corregida-check"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="agregar-producto-corregido" class="button button-secondary">➕ Agregar Otro Producto</button>
                    </div>
                </div>
                
                <!-- Resumen Total -->
                <div class="postbox">
                    <h2 class="hndle">💰 Resumen del Alquiler</h2>
                    <div class="inside">
                        <div id="resumen-total-corregido"></div>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">Descuento (%)</th>
                                <td>
                                    <input type="number" name="descuento_porcentaje" min="0" max="100" step="0.01" style="width: 100px;" />
                                    <span class="description">Descuento sobre el total</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Garantía Total</th>
                                <td>
                                    <input type="number" name="garantia_total" id="garantia_total_corregido" readonly style="width: 150px; background: #f0f0f0;" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Estado</th>
                                <td>
                                    <select name="estado_alquiler">
                                        <option value="confirmed">✅ Confirmado</option>
                                        <option value="pending">⏳ Pendiente</option>
                                        <option value="in-progress">🔄 En Progreso</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Método de Pago</th>
                                <td>
                                    <select name="metodo_pago">
                                        <option value="efectivo">💵 Efectivo</option>
                                        <option value="transferencia">🏦 Transferencia</option>
                                        <option value="tarjeta">💳 Tarjeta</option>
                                        <option value="izi_pay">💎 IziPay</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Notas</th>
                                <td>
                                    <textarea name="notas_adicionales" rows="4" class="large-text"></textarea>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="crear_alquiler_corregido" class="button-primary button-hero" value="🏠 Crear Alquiler con Metadatos" />
                </p>
            </form>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            let contadorProductos = 1;
            
            // Toggle nuevo cliente
            $('#toggle-nuevo-cliente').click(function() {
                $('#nuevo-cliente-row').toggle();
                $('#cliente_existente').val('');
            });
            
            // Agregar producto
            $('#agregar-producto-corregido').click(function() {
                const nuevoItem = $('.producto-corregido-item:first').clone();
                nuevoItem.attr('data-index', contadorProductos);
                nuevoItem.find('.producto-corregido-header h4').text('Producto #' + (contadorProductos + 1));
                
                nuevoItem.find('[name]').each(function() {
                    const nombre = $(this).attr('name');
                    if (nombre) {
                        $(this).attr('name', nombre.replace('[0]', '[' + contadorProductos + ']'));
                        if ($(this).hasClass('producto-corregido-select')) {
                            $(this).val('');
                        } else if ($(this).hasClass('cantidad-corregida-input')) {
                            $(this).val('1');
                        }
                    }
                });
                
                nuevoItem.find('.paso-2, .paso-3').hide();
                nuevoItem.find('.variaciones-corregidas-grid').empty();
                nuevoItem.find('.resumen-selection-corregida').empty();
                nuevoItem.find('.disponibilidad-corregida-check').empty();
                
                $('#productos-container-corregido').append(nuevoItem);
                contadorProductos++;
            });
            
            // Eliminar producto
            $(document).on('click', '.remove-producto-corregido', function() {
                if ($('.producto-corregido-item').length > 1) {
                    $(this).closest('.producto-corregido-item').remove();
                    actualizarResumenTotal();
                }
            });
            
            // Cambio de producto
            $(document).on('change', '.producto-corregido-select', function() {
                const $item = $(this).closest('.producto-corregido-item');
                const productId = $(this).val();
                
                $item.find('.paso-2, .paso-3').hide();
                
                if (productId) {
                    const fechaInicio = $('#fecha_inicio').val();
                    if (!fechaInicio) {
                        alert('⚠️ Por favor selecciona primero la fecha de inicio');
                        $(this).val('');
                        return;
                    }
                    
                    cargarVariacionesCompletas(productId, fechaInicio, $item);
                }
            });
            
            // Cambio de fecha
            $('#fecha_inicio').change(function() {
                $('.producto-corregido-item').each(function() {
                    const $item = $(this);
                    const productId = $item.find('.producto-corregido-select').val();
                    
                    if (productId) {
                        $item.find('.paso-2, .paso-3').hide();
                        cargarVariacionesCompletas(productId, $(this).val(), $item);
                    }
                });
            });
            
            function cargarVariacionesCompletas(productId, fechaInicio, $item) {
                $.post(ajaxurl, {
                    action: 'get_variaciones_completas',
                    product_id: productId,
                    fecha_inicio: fechaInicio,
                    nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
                }, function(response) {
                    if (response.success && response.data.length > 0) {
                        mostrarVariacionesCompletas(response.data, $item);
                    }
                });
            }
            
            function mostrarVariacionesCompletas(variaciones, $item) {
                const $grid = $item.find('.variaciones-corregidas-grid');
                const index = $item.attr('data-index');
                
                $grid.empty();
                
                variaciones.forEach(function(variacion) {
                    const $card = $('<div class="variacion-corregida-card" data-variation-id="' + variacion.id + '">');
                    
                    let contenido = '<div class="variacion-corregida-content">';
                    contenido += '<div class="variacion-corregida-titulo">' + variacion.display_name + '</div>';
                    
                    if (variacion.talla) {
                        contenido += '<div class="variacion-corregida-talla">👕 Talla: ' + variacion.talla + '</div>';
                    }
                    
                    if (variacion.periodo_dias) {
                        contenido += '<div class="variacion-corregida-periodo">📅 ' + variacion.periodo_dias + ' días</div>';
                    }
                    
                    if (variacion.fecha_entrega) {
                        const fechaInicio = $('#fecha_inicio').val();
                        contenido += '<div class="variacion-corregida-fechas">Del ' + fechaInicio + ' al ' + variacion.fecha_entrega + '</div>';
                    }
                    
                    contenido += '<div class="variacion-corregida-precio">' + variacion.price_formatted;
                    if (variacion.precio_por_dia > 0) {
                        contenido += '<br><small>S/. ' + variacion.precio_por_dia.toFixed(2) + ' por día</small>';
                    }
                    contenido += '</div>';
                    
                    if (variacion.garantia > 0) {
                        contenido += '<div class="variacion-corregida-garantia">🔒 Garantía: S/. ' + variacion.garantia + '</div>';
                    }
                    
                    contenido += '<input type="radio" name="productos[' + index + '][variation_id]" value="' + variacion.id + '" style="display: none;">';
                    contenido += '<input type="hidden" name="productos[' + index + '][fecha_entrega]" value="' + (variacion.fecha_entrega || '') + '">';
                    contenido += '<input type="hidden" name="productos[' + index + '][talla]" value="' + (variacion.talla || '') + '">';
                    contenido += '<input type="hidden" name="productos[' + index + '][periodo_dias]" value="' + (variacion.periodo_dias || '') + '">';
                    contenido += '<input type="hidden" name="productos[' + index + '][precio_por_dia]" value="' + (variacion.precio_por_dia || '') + '">';
                    contenido += '</div>';
                    
                    $card.html(contenido);
                    
                    $card.click(function() {
                        $grid.find('.variacion-corregida-card').removeClass('selected');
                        $(this).addClass('selected');
                        $(this).find('input[type="radio"]').prop('checked', true);
                        
                        mostrarResumenCorregido(variacion, $item);
                    });
                    
                    $grid.append($card);
                });
                
                $item.find('.paso-2').show();
            }
            
            function mostrarResumenCorregido(variacion, $item) {
                const $resumen = $item.find('.resumen-selection-corregida');
                const fechaInicio = $('#fecha_inicio').val();
                
                let resumen = '<div class="selection-summary-corregida">';
                resumen += '<h5>✅ Variación Seleccionada:</h5>';
                resumen += '<div><strong>Producto:</strong> ' + variacion.display_name + '</div>';
                
                if (variacion.talla) {
                    resumen += '<div><strong>Talla:</strong> ' + variacion.talla + '</div>';
                }
                
                if (variacion.periodo_dias) {
                    resumen += '<div><strong>Período:</strong> ' + variacion.periodo_dias + ' días</div>';
                }
                
                if (variacion.fecha_entrega) {
                    resumen += '<div><strong>Fechas:</strong> ' + fechaInicio + ' al ' + variacion.fecha_entrega + '</div>';
                }
                
                resumen += '<div><strong>Precio:</strong> ' + variacion.price_formatted;
                if (variacion.precio_por_dia > 0) {
                    resumen += ' (S/. ' + variacion.precio_por_dia.toFixed(2) + '/día)';
                }
                resumen += '</div>';
                
                resumen += '<div><strong>Garantía:</strong> S/. ' + variacion.garantia + '</div>';
                resumen += '<div><strong>ID Variación:</strong> ' + variacion.id + '</div>';
                resumen += '</div>';
                
                $resumen.html(resumen);
                $item.find('.paso-3').show();
                
                verificarDisponibilidadCorregida($item);
                actualizarResumenTotal();
            }
            
            function verificarDisponibilidadCorregida($item) {
                const productId = $item.find('.producto-corregido-select').val();
                const variationId = $item.find('input[name*="[variation_id]"]:checked').val();
                const fechaEntrega = $item.find('input[name*="[fecha_entrega]"]').val();
                const cantidad = $item.find('.cantidad-corregida-input').val();
                const fechaInicio = $('#fecha_inicio').val();
                const $check = $item.find('.disponibilidad-corregida-check');
                
                if (!productId || !variationId || !fechaInicio || !fechaEntrega) {
                    $check.hide();
                    return;
                }
                
                $check.html('<div class="checking">🔄 Verificando disponibilidad...</div>').show();
                
                $.post(ajaxurl, {
                    action: 'check_disponibilidad_corregida',
                    product_id: productId,
                    variation_id: variationId,
                    fecha_inicio: fechaInicio,
                    fecha_entrega: fechaEntrega,
                    cantidad: cantidad,
                    nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        let html = '<div class="availability-corregida-result ';
                        html += response.data.disponible ? 'available' : 'unavailable';
                        html += '">';
                        html += response.data.disponible ? '✅ ' : '❌ ';
                        html += response.data.mensaje;
                        html += '</div>';
                        
                        $check.html(html);
                    }
                });
            }
            
            // Cambio de cantidad
            $(document).on('change', '.cantidad-corregida-input', function() {
                verificarDisponibilidadCorregida($(this).closest('.producto-corregido-item'));
                actualizarResumenTotal();
            });
            
            function actualizarResumenTotal() {
                let precioTotal = 0;
                let garantiaTotal = 0;
                let resumenHtml = '<div class="resumen-total-corregido"><h4>📋 Resumen Completo:</h4>';
                
                $('.producto-corregido-item').each(function() {
                    const $item = $(this);
                    const nombreProducto = $item.find('.producto-corregido-select option:selected').text();
                    const cantidad = parseInt($item.find('.cantidad-corregida-input').val()) || 1;
                    const variationId = $item.find('input[name*="[variation_id]"]:checked').val();
                    
                    if (nombreProducto && nombreProducto !== 'Seleccionar producto...' && variationId) {
                        const $resumen = $item.find('.selection-summary-corregida');
                        const resumenText = $resumen.text();
                        
                        const precioMatch = resumenText.match(/Precio:\s*S\/\.\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/);
                        const garantiaMatch = resumenText.match(/Garantía:\s*S\/\.\s*(\d+(?:\.\d+)?)/);
                        
                        if (precioMatch && garantiaMatch) {
                            const precio = parseFloat(precioMatch[1].replace(',', ''));
                            const garantia = parseFloat(garantiaMatch[1]);
                            
                            precioTotal += precio * cantidad;
                            garantiaTotal += garantia * cantidad;
                            
                            const fechaInicio = $('#fecha_inicio').val();
                            const fechaEntrega = $item.find('input[name*="[fecha_entrega]"]').val();
                            const talla = $item.find('input[name*="[talla]"]').val();
                            const periodoDias = $item.find('input[name*="[periodo_dias]"]').val();
                            
                            resumenHtml += '<div class="resumen-corregido-item">';
                            resumenHtml += '<strong>' + nombreProducto + '</strong>';
                            if (talla) resumenHtml += ' - Talla: ' + talla;
                            if (periodoDias) resumenHtml += ' - ' + periodoDias + ' días';
                            resumenHtml += ' (x' + cantidad + ')';
                            resumenHtml += '<br>📅 Del ' + fechaInicio + ' al ' + fechaEntrega;
                            resumenHtml += '<br>💰 Total: S/. ' + (precio * cantidad).toFixed(2);
                            resumenHtml += ' | 🔒 Garantía: S/. ' + (garantia * cantidad).toFixed(2);
                            resumenHtml += '<br><small>ID Variación: ' + variationId + '</small>';
                            resumenHtml += '</div>';
                        }
                    }
                });
                
                if (precioTotal > 0) {
                    resumenHtml += '<div class="resumen-corregido-total">';
                    resumenHtml += '<strong>💰 TOTAL ALQUILER: S/. ' + precioTotal.toFixed(2) + '</strong><br>';
                    resumenHtml += '<strong>🔒 TOTAL GARANTÍA: S/. ' + garantiaTotal.toFixed(2) + '</strong>';
                    resumenHtml += '</div>';
                }
                
                resumenHtml += '</div>';
                
                $('#resumen-total-corregido').html(resumenHtml);
                $('#garantia_total_corregido').val(garantiaTotal.toFixed(2));
            }
            
            // Validar formulario
            $('#formulario-alquiler-corregido').submit(function(e) {
                const clienteExistente = $('#cliente_existente').val();
                const nuevoClienteNombre = $('[name="nuevo_cliente_nombre"]').val();
                const nuevoClienteEmail = $('[name="nuevo_cliente_email"]').val();
                
                if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                    e.preventDefault();
                    alert('❌ Debe seleccionar un cliente o crear uno nuevo');
                    return false;
                }
                
                let productosIncompletos = false;
                $('.producto-corregido-item').each(function() {
                    const productId = $(this).find('.producto-corregido-select').val();
                    const variationId = $(this).find('input[name*="[variation_id]"]:checked').val();
                    
                    if (productId && !variationId) {
                        productosIncompletos = true;
                    }
                });
                
                if (productosIncompletos) {
                    e.preventDefault();
                    alert('❌ Selecciona una variación para todos los productos');
                    return false;
                }
                
                let productosNoDisponibles = false;
                $('.availability-corregida-result.unavailable:visible').each(function() {
                    productosNoDisponibles = true;
                });
                
                if (productosNoDisponibles) {
                    e.preventDefault();
                    alert('❌ Hay productos no disponibles');
                    return false;
                }
            });
        });
        </script>

        <style>
        .postbox h2.hndle {
            padding: 12px 15px;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            border-bottom: none;
        }
        .producto-corregido-item {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .paso-1, .paso-2, .paso-3 {
            margin-bottom: 20px;
            padding: 15px;
            border-left: 4px solid #ff6b6b;
            background: #fff5f5;
        }
        .paso-1 h5, .paso-2 h5, .paso-3 h5 {
            margin-top: 0;
            color: #ee5a24;
        }
        .variaciones-corregidas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .variacion-corregida-card {
            background: #fff;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .variacion-corregida-card:hover {
            border-color: #ff6b6b;
            box-shadow: 0 4px 8px rgba(255, 107, 107, 0.1);
            transform: translateY(-2px);
        }
        .variacion-corregida-card.selected {
            border-color: #ff6b6b;
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
        }
        .variacion-corregida-titulo {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 8px;
        }
        .variacion-corregida-talla, .variacion-corregida-periodo, .variacion-corregida-fechas {
            font-size: 14px;
            margin-bottom: 5px;
        }
        .variacion-corregida-precio {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
            margin: 10px 0;
        }
        .variacion-corregida-card.selected .variacion-corregida-precio {
            color: #fff;
        }
        .variacion-corregida-garantia {
            font-size: 13px;
            opacity: 0.9;
        }
        .cantidad-resumen-row {
            display: flex;
            gap: 20px;
            align-items: flex-start;
        }
        .selection-summary-corregida {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 6px;
            padding: 15px;
            flex: 1;
        }
        .selection-summary-corregida h5 {
            margin-top: 0;
            color: #1565c0;
        }
        .availability-corregida-result {
            padding: 12px;
            border-radius: 6px;
            margin-top: 15px;
            font-weight: bold;
        }
        .availability-corregida-result.available {
            background: #d4edda;
            color: #155724;
        }
        .availability-corregida-result.unavailable {
            background: #f8d7da;
            color: #721c24;
        }
        .resumen-total-corregido {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .resumen-corregido-item {
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .resumen-corregido-total {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 3px solid #ff6b6b;
            font-size: 18px;
            text-align: center;
        }
        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }
        </style>
        <?php
    }

    // Función para obtener productos de alquiler
    function get_productos_alquiler_corregido() {
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

    // FUNCIÓN CORREGIDA PARA AGREGAR PRODUCTOS CON METADATOS
    function procesar_alquiler_corregido($datos) {
        try {
            // Validaciones
            if (empty($datos['fecha_inicio'])) {
                return array('success' => false, 'message' => 'La fecha de inicio es obligatoria');
            }
            
            if (empty($datos['productos']) || !is_array($datos['productos'])) {
                return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
            }
            
            // Obtener cliente
            $cliente_id = null;
            if (function_exists('obtener_o_crear_cliente')) {
                $cliente_id = obtener_o_crear_cliente($datos);
            } else {
                $cliente_id = obtener_o_crear_cliente_corregido($datos);
            }
            
            if (!$cliente_id) {
                return array('success' => false, 'message' => 'Error al procesar el cliente');
            }
            
            // Crear orden
            $order = wc_create_order();
            $order->set_customer_id($cliente_id);
            $order->set_status('processing');
            
            // AGREGAR PRODUCTOS CON METADATOS CORRECTAMENTE
            $garantia_total = 0;
            $fecha_fin_mas_tardia = $datos['fecha_inicio'];
            
            foreach ($datos['productos'] as $producto_data) {
                if (empty($producto_data['id']) || empty($producto_data['variation_id'])) continue;
                
                $cantidad = intval($producto_data['cantidad']) ?: 1;
                $variation_id = $producto_data['variation_id'];
                $product_id = $producto_data['id'];
                
                // Obtener el producto
                $producto = wc_get_product($variation_id);
                
                if (!$producto) {
                    error_log("No se pudo cargar la variación: " . $variation_id);
                    continue;
                }
                
                // AGREGAR PRODUCTO A LA ORDEN
                $item_id = $order->add_product($producto, $cantidad);
                
                if (!$item_id) {
                    error_log("No se pudo agregar producto a la orden: " . $variation_id);
                    continue;
                }
                
                // ✅ AGREGAR METADATOS AL ITEM DE LA ORDEN (COMO EN TU IMAGEN)
                $item = $order->get_item($item_id);
                
                if ($item) {
                    // Metadatos principales
                    if (!empty($producto_data['periodo_dias'])) {
                        $item->add_meta_data('Dias de alquiler', $producto_data['periodo_dias'] . ' días');
                    }
                    
                    $item->add_meta_data('fecha_alquiler_inicio', $datos['fecha_inicio']);
                    
                    if (!empty($producto_data['fecha_entrega'])) {
                        $item->add_meta_data('fecha_alquiler_fin', $producto_data['fecha_entrega']);
                        
                        // Actualizar fecha fin más tardía
                        if ($producto_data['fecha_entrega'] > $fecha_fin_mas_tardia) {
                            $fecha_fin_mas_tardia = $producto_data['fecha_entrega'];
                        }
                    }
                    
                    $item->add_meta_data('ID Variación', $variation_id);
                    
                    if (!empty($producto_data['talla'])) {
                        $item->add_meta_data('Talla', $producto_data['talla']);
                    }
                    
                    if (!empty($producto_data['precio_por_dia'])) {
                        $item->add_meta_data('Precio por día', 'S/. ' . number_format($producto_data['precio_por_dia'], 2));
                    }
                    
                    // Guardar metadatos del item
                    $item->save_meta_data();
                }
                
                // Calcular garantía
                $garantia_producto = get_post_meta($variation_id, '_valor_garantia', true);
                if (!$garantia_producto) {
                    $garantia_producto = get_post_meta($product_id, '_valor_garantia', true);
                }
                
                $garantia_total += floatval($garantia_producto) * $cantidad;
            }
            
            // Aplicar descuento
            if (!empty($datos['descuento_porcentaje'])) {
                $descuento = floatval($datos['descuento_porcentaje']);
                if ($descuento > 0 && $descuento <= 100) {
                    $order->calculate_totals();
                    $total_antes_descuento = $order->get_total();
                    $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                    
                    $coupon = new WC_Coupon();
                    $coupon->set_code('DESCUENTO_CORREGIDO_' . time());
                    $coupon->set_discount_type('fixed_cart');
                    $coupon->set_amount($descuento_cantidad);
                    $coupon->save();
                    
                    $order->apply_coupon($coupon->get_code());
                }
            }
            
            // Agregar metadatos a la orden
            $order->add_meta_data('_es_alquiler', 'yes');
            $order->add_meta_data('_fecha_inicio_alquiler', sanitize_text_field($datos['fecha_inicio']));
            $order->add_meta_data('_fecha_fin_alquiler', $fecha_fin_mas_tardia);
            $order->add_meta_data('_garantia_total', $garantia_total);
            $order->add_meta_data('_estado_alquiler', sanitize_text_field($datos['estado_alquiler']));
            $order->add_meta_data('_metodo_pago_manual', sanitize_text_field($datos['metodo_pago']));
            
            if (!empty($datos['evento_ocasion'])) {
                $order->add_meta_data('_evento_ocasion', sanitize_text_field($datos['evento_ocasion']));
            }
            
            if (!empty($datos['notas_adicionales'])) {
                $order->add_meta_data('_notas_alquiler', sanitize_text_field($datos['notas_adicionales']));
            }
            
            // Calcular totales y guardar
            $order->calculate_totals();
            $order->save();
            
            return array(
                'success' => true,
                'message' => '🎉 Alquiler creado con metadatos completos. Orden #' . $order->get_id(),
                'order_id' => $order->get_id()
            );
            
        } catch (Exception $e) {
            error_log("Error en procesar_alquiler_corregido: " . $e->getMessage());
            return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
        }
    }

    // Función auxiliar para crear cliente
    function obtener_o_crear_cliente_corregido($datos) {
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