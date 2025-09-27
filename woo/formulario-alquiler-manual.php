<?php
/**
 * Formulario de ingreso manual para alquileres
 * Con validación de disponibilidad y manejo de variaciones
 */

// Agregar menú de administración
add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',
        'Alquiler Manual',
        'Alquiler Manual',
        'manage_woocommerce',
        'alquiler-manual',
        'gala_rental_manual_form_page'
    );
});

// Endpoint AJAX para obtener variaciones
add_action('wp_ajax_get_product_variations', 'ajax_get_product_variations');
function ajax_get_product_variations() {
    check_ajax_referer('alquiler_manual_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $product = wc_get_product($product_id);
    
    $variations = array();
    
    if ($product && $product->is_type('variable')) {
        $available_variations = $product->get_available_variations();
        
        foreach ($available_variations as $variation_data) {
            $variation = wc_get_product($variation_data['variation_id']);
            $attributes = array();
            $periodo_detectado = null;
            
            foreach ($variation_data['attributes'] as $attr_name => $attr_value) {
                $attr_label = wc_attribute_label(str_replace('attribute_', '', $attr_name));
                
                // Detectar si el atributo contiene información de período
                if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                    $periodo_detectado = intval($matches[1]);
                    $attributes[] = $attr_label . ': ' . $attr_value;
                } else {
                    $attributes[] = $attr_label . ': ' . $attr_value;
                }
            }
            
            // También buscar período en el nombre de la variación
            if (!$periodo_detectado) {
                $nombre_variacion = $variation->get_name();
                if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                    $periodo_detectado = intval($matches[1]);
                }
            }
            
            // Construir el nombre de display
            $display_name = implode(', ', $attributes);
            if ($periodo_detectado) {
                $display_name .= ' (' . $periodo_detectado . ' días)';
            }
            
            // Obtener garantía (primero de la variación, luego del producto padre)
            $garantia = get_post_meta($variation_data['variation_id'], '_valor_garantia', true);
            if (!$garantia) {
                $garantia = get_post_meta($product_id, '_valor_garantia', true);
            }
            
            // Obtener días de gracia
            $dias_gracia = get_post_meta($variation_data['variation_id'], '_dias_gracia', true);
            if (!$dias_gracia) {
                $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
            }
            
            $variations[] = array(
                'id' => $variation_data['variation_id'],
                'display_name' => $display_name,
                'price' => $variation->get_price(),
                'garantia' => $garantia ?: 0,
                'dias_gracia' => $dias_gracia ?: 0,
                'periodo_dias' => $periodo_detectado,
                'stock_quantity' => $variation->get_stock_quantity(),
                'managing_stock' => $variation->managing_stock()
            );
        }
    } elseif ($product && $product->is_type('simple')) {
        $variations[] = array(
            'id' => $product_id,
            'display_name' => 'Producto simple',
            'price' => $product->get_price(),
            'garantia' => get_post_meta($product_id, '_valor_garantia', true) ?: 0,
            'dias_gracia' => get_post_meta($product_id, '_dias_gracia', true) ?: 0,
            'periodo_dias' => null,
            'stock_quantity' => $product->get_stock_quantity(),
            'managing_stock' => $product->managing_stock()
        );
    }
    
    wp_send_json_success($variations);
}

// Endpoint AJAX para verificar disponibilidad
add_action('wp_ajax_check_product_availability', 'ajax_check_product_availability');
function ajax_check_product_availability() {
    check_ajax_referer('alquiler_manual_nonce', 'nonce');
    
    $product_id = intval($_POST['product_id']);
    $variation_id = intval($_POST['variation_id']);
    $fecha_inicio = sanitize_text_field($_POST['fecha_inicio']);
    $fecha_fin = sanitize_text_field($_POST['fecha_fin']);
    $cantidad = intval($_POST['cantidad']) ?: 1;
    
    $disponible = verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad);
    
    wp_send_json_success(array('disponible' => $disponible));
}

// Página del formulario
function gala_rental_manual_form_page() {
    // Procesar formulario si se envió
    if (isset($_POST['crear_alquiler']) && wp_verify_nonce($_POST['alquiler_manual_nonce'], 'crear_alquiler_manual')) {
        $resultado = procesar_alquiler_manual($_POST);
        if ($resultado['success']) {
            echo '<div class="notice notice-success"><p>' . esc_html($resultado['message']) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html($resultado['message']) . '</p></div>';
        }
    }

    // Obtener productos de alquiler
    $productos_alquiler = get_productos_alquiler();
    ?>
    <div class="wrap">
        <h1>Crear Alquiler Manual</h1>
        
        <form method="post" action="" id="formulario-alquiler-manual">
            <?php wp_nonce_field('crear_alquiler_manual', 'alquiler_manual_nonce'); ?>
            
            <table class="form-table">
                <tbody>
                    <!-- Información del Cliente -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h2>Información del Cliente</h2>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="cliente_existente">Cliente Existente</label>
                        </th>
                        <td>
                            <select name="cliente_id" id="cliente_existente" style="width: 300px;">
                                <option value="">Seleccionar cliente existente...</option>
                                <?php
                                $clientes = get_users(array('role' => 'customer'));
                                foreach ($clientes as $cliente) {
                                    echo '<option value="' . esc_attr($cliente->ID) . '">' . 
                                         esc_html($cliente->display_name) . ' (' . esc_html($cliente->user_email) . ')</option>';
                                }
                                ?>
                            </select>
                            <p class="description">O crear un nuevo cliente abajo</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="nuevo_cliente_nombre">Nuevo Cliente - Nombre</label>
                        </th>
                        <td>
                            <input type="text" name="nuevo_cliente_nombre" id="nuevo_cliente_nombre" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="nuevo_cliente_email">Nuevo Cliente - Email</label>
                        </th>
                        <td>
                            <input type="email" name="nuevo_cliente_email" id="nuevo_cliente_email" class="regular-text" />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="nuevo_cliente_telefono">Nuevo Cliente - Teléfono</label>
                        </th>
                        <td>
                            <input type="tel" name="nuevo_cliente_telefono" id="nuevo_cliente_telefono" class="regular-text" />
                        </td>
                    </tr>
                    
                    <!-- Información del Alquiler -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h2>Información del Alquiler</h2>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="fecha_inicio">Fecha de Inicio</label>
                        </th>
                        <td>
                            <input type="date" name="fecha_inicio" id="fecha_inicio" required />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="fecha_fin">Fecha de Fin</label>
                        </th>
                        <td>
                            <input type="date" name="fecha_fin" id="fecha_fin" required />
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="evento_ocasion">Evento/Ocasión</label>
                        </th>
                        <td>
                            <input type="text" name="evento_ocasion" id="evento_ocasion" class="regular-text" 
                                   placeholder="Ej: Boda, Graduación, Cena de gala..." />
                        </td>
                    </tr>
                    
                    <!-- Productos -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h2>Productos a Alquilar</h2>
                        </th>
                    </tr>
                    
                    <tr>
                        <td colspan="2">
                            <div id="productos-alquiler">
                                <div class="producto-item" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 10px;">
                                    <div class="producto-row">
                                        <select name="productos[0][id]" class="producto-select" style="width: 300px;" required>
                                            <option value="">Seleccionar producto...</option>
                                            <?php foreach ($productos_alquiler as $producto): ?>
                                                <option value="<?php echo esc_attr($producto->ID); ?>" 
                                                        data-tipo="<?php echo esc_attr($producto->get_type()); ?>">
                                                    <?php echo esc_html($producto->get_name() . ' - ' . wc_price($producto->get_price())); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        
                                        <select name="productos[0][variation_id]" class="variacion-select" style="width: 400px; display: none;">
                                            <option value="">Seleccionar variación...</option>
                                        </select>
                                        
                                        <label>Cantidad:</label>
                                        <input type="number" name="productos[0][cantidad]" class="cantidad-input" value="1" min="1" style="width: 60px;" />
                                        
                                        <button type="button" class="button remove-producto" style="color: red;">Eliminar</button>
                                    </div>
                                    
                                    <div class="info-variacion" style="margin-top: 10px; padding: 8px; background: #f0f8ff; border-radius: 4px; display: none;">
                                        <div class="info-precio"><strong>Precio:</strong> <span class="precio-valor">-</span></div>
                                        <div class="info-garantia"><strong>Garantía:</strong> <span class="garantia-valor">-</span></div>
                                        <div class="info-periodo"><strong>Período:</strong> <span class="periodo-valor">-</span></div>
                                        <div class="info-stock"><strong>Stock:</strong> <span class="stock-valor">-</span></div>
                                    </div>
                                    
                                    <div class="disponibilidad-info" style="margin-top: 10px; padding: 10px; border-radius: 4px; display: none;">
                                        <span class="disponibilidad-texto"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="button" id="agregar-producto" class="button">Agregar Otro Producto</button>
                        </td>
                    </tr>
                    
                    <!-- Información Adicional -->
                    <tr>
                        <th scope="row" colspan="2">
                            <h2>Información Adicional</h2>
                        </th>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="descuento_porcentaje">Descuento (%)</label>
                        </th>
                        <td>
                            <input type="number" name="descuento_porcentaje" id="descuento_porcentaje" 
                                   min="0" max="100" step="0.01" />
                            <p class="description">Descuento en porcentaje sobre el total</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="garantia_total">Garantía Total</label>
                        </th>
                        <td>
                            <input type="number" name="garantia_total" id="garantia_total" step="0.01" readonly />
                            <p class="description">Se calculará automáticamente basado en los productos seleccionados</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="estado_alquiler">Estado del Alquiler</label>
                        </th>
                        <td>
                            <select name="estado_alquiler" id="estado_alquiler">
                                <option value="confirmed">Confirmado</option>
                                <option value="pending">Pendiente</option>
                                <option value="in-progress">En Progreso</option>
                                <option value="completed">Completado</option>
                                <option value="cancelled">Cancelado</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="metodo_pago">Método de Pago</label>
                        </th>
                        <td>
                            <select name="metodo_pago" id="metodo_pago">
                                <option value="efectivo">Efectivo</option>
                                <option value="transferencia">Transferencia</option>
                                <option value="tarjeta">Tarjeta</option>
                                <option value="izi_pay">IziPay</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="notas_adicionales">Notas Adicionales</label>
                        </th>
                        <td>
                            <textarea name="notas_adicionales" id="notas_adicionales" rows="4" cols="50"></textarea>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <p class="submit">
                <input type="submit" name="crear_alquiler" id="crear_alquiler" class="button-primary" value="Crear Alquiler Manual" />
            </p>
        </form>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        let contadorProductos = 1;
        
        // Agregar nuevo producto
        $('#agregar-producto').click(function() {
            const nuevoProducto = $('.producto-item:first').clone();
            nuevoProducto.find('select, input').each(function() {
                const nombre = $(this).attr('name');
                if (nombre) {
                    $(this).attr('name', nombre.replace('[0]', '[' + contadorProductos + ']'));
                    if ($(this).hasClass('producto-select')) {
                        $(this).val('');
                    } else if ($(this).hasClass('variacion-select')) {
                        $(this).empty().append('<option value="">Seleccionar variación...</option>').hide();
                    } else if ($(this).hasClass('cantidad-input')) {
                        $(this).val('1');
                    }
                }
            });
            // Resetear elementos de información
            nuevoProducto.find('.disponibilidad-info').hide();
            nuevoProducto.find('.info-variacion').hide();
            nuevoProducto.find('.info-variacion .precio-valor').text('-');
            nuevoProducto.find('.info-variacion .garantia-valor').text('-');
            nuevoProducto.find('.info-variacion .periodo-valor').text('-');
            nuevoProducto.find('.info-variacion .stock-valor').text('-');
            
            $('#productos-alquiler').append(nuevoProducto);
            contadorProductos++;
        });
        
        // Eliminar producto
        $(document).on('click', '.remove-producto', function() {
            if ($('.producto-item').length > 1) {
                $(this).closest('.producto-item').remove();
                calcularTotales();
            }
        });
        
        // Manejar cambio de producto para cargar variaciones
        $(document).on('change', '.producto-select', function() {
            const $this = $(this);
            const $variacionSelect = $this.siblings('.variacion-select');
            const $disponibilidadInfo = $this.closest('.producto-item').find('.disponibilidad-info');
            const productId = $this.val();
            
            $disponibilidadInfo.hide();
            
            if (productId) {
                // Cargar variaciones
                $.post(ajaxurl, {
                    action: 'get_product_variations',
                    product_id: productId,
                    nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        $variacionSelect.empty().append('<option value="">Seleccionar variación...</option>');
                        
                        $.each(response.data, function(index, variation) {
                            const optionText = variation.display_name + ' - ' + 
                                             'Precio: 
                        
                        if (response.data.length > 1) {
                            $variacionSelect.show();
                        } else if (response.data.length === 1) {
                            $variacionSelect.val(response.data[0].id).hide();
                            verificarDisponibilidad($this.closest('.producto-item'));
                        }
                        
                        calcularTotales();
                    }
                });
            } else {
                $variacionSelect.hide().empty();
                calcularTotales();
            }
        });
        
        // Manejar cambio de variación
        $(document).on('change', '.variacion-select', function() {
            const $this = $(this);
            const $infoVariacion = $this.closest('.producto-item').find('.info-variacion');
            const $selectedOption = $this.find('option:selected');
            
            if ($this.val() && $selectedOption.length) {
                // Mostrar información de la variación
                const precio = $selectedOption.data('precio');
                const garantia = $selectedOption.data('garantia');
                const periodo = $selectedOption.data('periodo');
                const stock = $selectedOption.data('stock');
                const managingStock = $selectedOption.data('managing-stock');
                
                $infoVariacion.find('.precio-valor').text('
        
        // Manejar cambio de cantidad
        $(document).on('change', '.cantidad-input', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de fechas
        $('#fecha_inicio, #fecha_fin').change(function() {
            $('.producto-item').each(function() {
                verificarDisponibilidad($(this));
            });
        });
        
        // Función para verificar disponibilidad
        function verificarDisponibilidad($productoItem) {
            const productId = $productoItem.find('.producto-select').val();
            const variationId = $productoItem.find('.variacion-select').val();
            const cantidad = $productoItem.find('.cantidad-input').val();
            const fechaInicio = $('#fecha_inicio').val();
            const fechaFin = $('#fecha_fin').val();
            const $disponibilidadInfo = $productoItem.find('.disponibilidad-info');
            
            if (!productId || !fechaInicio || !fechaFin) {
                $disponibilidadInfo.hide();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'check_product_availability',
                product_id: productId,
                variation_id: variationId || productId,
                cantidad: cantidad,
                fecha_inicio: fechaInicio,
                fecha_fin: fechaFin,
                nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    const $texto = $disponibilidadInfo.find('.disponibilidad-texto');
                    
                    if (response.data.disponible) {
                        $disponibilidadInfo.removeClass('error').addClass('success')
                            .css('background-color', '#d4edda').css('color', '#155724');
                        $texto.html('✅ Producto disponible para las fechas seleccionadas');
                    } else {
                        $disponibilidadInfo.removeClass('success').addClass('error')
                            .css('background-color', '#f8d7da').css('color', '#721c24');
                        $texto.html('❌ Producto NO disponible para las fechas seleccionadas');
                    }
                    
                    $disponibilidadInfo.show();
                }
            });
        }
        
        // Calcular totales
        function calcularTotales() {
            let garantiaTotal = 0;
            
            $('.producto-item').each(function() {
                const $productoSelect = $(this).find('.producto-select');
                const $variacionSelect = $(this).find('.variacion-select');
                const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                let garantia = 0;
                
                if ($variacionSelect.is(':visible') && $variacionSelect.val()) {
                    // Producto variable - obtener garantía de la variación seleccionada
                    garantia = parseFloat($variacionSelect.find('option:selected').data('garantia')) || 0;
                } else if ($productoSelect.val()) {
                    // Producto simple - obtener garantía del endpoint AJAX
                    // Esta información ya debería estar disponible desde la carga de variaciones
                    const productId = $productoSelect.val();
                    
                    // Si hay solo una opción en variaciones (producto simple), usar esa garantía
                    if ($variacionSelect.find('option').length === 2) { // "Seleccionar..." + 1 opción
                        const $option = $variacionSelect.find('option:last');
                        if ($option.length) {
                            garantia = parseFloat($option.data('garantia')) || 0;
                        }
                    }
                }
                
                garantiaTotal += garantia * cantidad;
            });
            
            $('#garantia_total').val(garantiaTotal.toFixed(2));
        }
        
        // Validar fechas
        $('#fecha_fin').change(function() {
            const fechaInicio = new Date($('#fecha_inicio').val());
            const fechaFin = new Date($(this).val());
            
            if (fechaFin <= fechaInicio) {
                alert('La fecha de fin debe ser posterior a la fecha de inicio');
                $(this).val('');
            }
        });
        
        // Validar formulario antes del envío
        $('#formulario-alquiler-manual').submit(function(e) {
            const clienteExistente = $('#cliente_existente').val();
            const nuevoClienteNombre = $('#nuevo_cliente_nombre').val();
            const nuevoClienteEmail = $('#nuevo_cliente_email').val();
            
            if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                e.preventDefault();
                alert('Debe seleccionar un cliente existente o proporcionar los datos de un nuevo cliente');
                return false;
            }
            
            // Validar que no haya productos no disponibles
            let hayProductosNoDisponibles = false;
            $('.disponibilidad-info.error:visible').each(function() {
                hayProductosNoDisponibles = true;
            });
            
            if (hayProductosNoDisponibles) {
                e.preventDefault();
                alert('Hay productos no disponibles para las fechas seleccionadas. Por favor revise la disponibilidad.');
                return false;
            }
        });
    });
    </script>

    <style>
    .producto-item {
        background: #fafafa;
    }
    .producto-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .producto-row select, .producto-row input {
        margin-right: 10px;
    }
    .form-table th {
        width: 200px;
    }
    h2 {
        color: #1d2327;
        margin: 20px 0 10px 0;
    }
    .disponibilidad-info {
        border: 1px solid;
        border-radius: 4px;
        font-weight: bold;
    }
    .disponibilidad-info.success {
        border-color: #c3e6cb;
    }
    .disponibilidad-info.error {
        border-color: #f5c6cb;
    }
    .info-variacion {
        background: #f0f8ff;
        border: 1px solid #d0e7ff;
        font-size: 12px;
    }
    .info-variacion div {
        margin: 2px 0;
        display: inline-block;
        margin-right: 15px;
    }
    .info-variacion strong {
        color: #2271b1;
    }
    </style>
    <?php
}

// Función para obtener productos de alquiler
function get_productos_alquiler() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
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
        $productos_wc[] = wc_get_product($producto->ID);
    }
    
    return $productos_wc;
}

// Función para verificar disponibilidad de producto
function verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
    // Convertir fechas
    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);
    
    // Obtener días de gracia del producto (puede estar en variación o producto padre)
    $dias_gracia = 0;
    if ($variation_id && $variation_id != $product_id) {
        $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
        if (!$dias_gracia) {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
    } else {
        $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
    }
    $dias_gracia = $dias_gracia ?: 0;
    
    // Verificar si la variación tiene un período específico configurado
    $producto_actual = wc_get_product($variation_id ?: $product_id);
    $periodo_variacion = null;
    
    if ($producto_actual && $producto_actual->is_type('variation')) {
        // Buscar en los atributos de la variación si hay información de período
        $attributes = $producto_actual->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_value) {
            // Si encuentra algo como "4-dias", "8-dias", etc. en los atributos
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_variacion = intval($matches[1]);
                break;
            }
        }
        
        // También verificar en el nombre/título de la variación
        if (!$periodo_variacion) {
            $nombre_variacion = $producto_actual->get_name();
            if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                $periodo_variacion = intval($matches[1]);
            }
        }
    }
    
    // Buscar órdenes existentes que contengan este producto en el rango de fechas
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
        'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
    );
    
    $ordenes_alquiler = get_posts($args);
    
    foreach ($ordenes_alquiler as $orden_post) {
        $orden = wc_get_order($orden_post->ID);
        
        $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
        $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
        
        if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
        
        $inicio_orden = new DateTime($fecha_inicio_orden);
        $fin_orden = new DateTime($fecha_fin_orden);
        
        // Agregar días de gracia al final del alquiler existente
        if ($dias_gracia > 0) {
            $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
        }
        
        // Verificar si hay solapamiento de fechas
        if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
            // Hay solapamiento, verificar si es el mismo producto/variación
            foreach ($orden->get_items() as $item) {
                $producto_orden = $item->get_product();
                
                if (!$producto_orden) continue;
                
                // Verificar si es el mismo producto/variación específica
                $mismo_producto = false;
                
                if ($variation_id && $variation_id != $product_id) {
                    // Comparar variación específica
                    if ($producto_orden->get_id() == $variation_id) {
                        $mismo_producto = true;
                    }
                } else {
                    // Producto simple - comparar ID del producto padre
                    $producto_id_orden = $producto_orden->is_type('variation') ? 
                        $producto_orden->get_parent_id() : $producto_orden->get_id();
                    if ($producto_id_orden == $product_id) {
                        $mismo_producto = true;
                    }
                }
                
                if ($mismo_producto) {
                    // Verificar stock/cantidad disponible
                    $cantidad_orden = $item->get_quantity();
                    
                    // Si se maneja stock, verificar disponibilidad
                    if ($producto_actual && $producto_actual->managing_stock()) {
                        $stock_disponible = $producto_actual->get_stock_quantity();
                        if ($stock_disponible < $cantidad_solicitada) {
                            return false;
                        }
                    } else {
                        // Si no se maneja stock, asumir que solo hay 1 disponible por variación
                        return false;
                    }
                }
            }
        }
    }
    
    return true; // Disponible
}

// Función para procesar el formulario (actualizada para manejar variaciones)
function procesar_alquiler_manual($datos) {
    try {
        // Validar datos básicos
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            return array('success' => false, 'message' => 'Las fechas son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validar disponibilidad de todos los productos antes de crear la orden
        foreach ($datos['productos'] as $producto_data) {
            if (empty($producto_data['id'])) continue;
            
            $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : $producto_data['id'];
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            $disponible = verificar_disponibilidad_producto(
                $producto_data['id'], 
                $variation_id, 
                $datos['fecha_inicio'], 
                $datos['fecha_fin'], 
                $cantidad
            );
            
            if (!$disponible) {
                $producto = wc_get_product($variation_id);
                return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible para las fechas seleccionadas');
            }
        }
        
        // Obtener o crear cliente
        $cliente_id = obtener_o_crear_cliente($datos);
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
            
            // Obtener el producto (variación o simple)
            if ($variation_id && $variation_id != $product_id) {
                $producto = wc_get_product($variation_id);
                $producto_padre = wc_get_product($product_id);
            } else {
                $producto = wc_get_product($product_id);
                $producto_padre = null;
            }
            
            if (!$producto) continue;
            
            // Agregar producto a la orden
            $item = $order->add_product($producto, $cantidad);
            
            // Calcular garantía (primero buscar en la variación, luego en el producto padre)
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
                $total_antes_descuento = $order->get_total();
                $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                
                $coupon = new WC_Coupon();
                $coupon->set_code('DESCUENTO_MANUAL_' . time());
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($descuento_cantidad);
                $coupon->save();
                
                $order->apply_coupon($coupon->get_code());
            }
        }
        
        // Agregar metadatos de alquiler
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
        
        // Calcular totales y guardar
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

// Función para obtener o crear cliente (sin cambios)
function obtener_o_crear_cliente($datos) {
    // Si se seleccionó un cliente existente
    if (!empty($datos['cliente_id'])) {
        return intval($datos['cliente_id']);
    }
    
    // Si se proporcionaron datos para nuevo cliente
    if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
        $email = sanitize_email($datos['nuevo_cliente_email']);
        $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
        $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
        
        // Verificar si ya existe un usuario con ese email
        $usuario_existente = get_user_by('email', $email);
        if ($usuario_existente) {
            return $usuario_existente->ID;
        }
        
        // Crear nuevo usuario
        $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            // Asignar rol de customer
            $user = new WP_User($user_id);
            $user->set_role('customer');
            
            // Agregar metadatos
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

// Agregar columnas personalizadas en la lista de órdenes (sin cambios)
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['es_alquiler'] = 'Tipo';
            $new_columns['fechas_alquiler'] = 'Fechas Alquiler';
        }
    }
    
    return $new_columns;
});

// Mostrar contenido de las columnas personalizadas (sin cambios)
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'es_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            echo '<span style="color: #2271b1; font-weight: bold;">🏠 Alquiler</span>';
        } else {
            echo 'Venta';
        }
    }
    
    if ($column === 'fechas_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            $fecha_inicio = get_post_meta($post_id, '_fecha_inicio_alquiler', true);
            $fecha_fin = get_post_meta($post_id, '_fecha_fin_alquiler', true);
            
            if ($fecha_inicio && $fecha_fin) {
                echo date('d/m/Y', strtotime($fecha_inicio)) . '<br>a<br>' . date('d/m/Y', strtotime($fecha_fin));
            }
        } else {
            echo '-';
        }
    }
}, 10, 2);
?> + variation.price + 
                                             (variation.garantia > 0 ? ' - Garantía: 
                        
                        if (response.data.length > 1) {
                            $variacionSelect.show();
                        } else if (response.data.length === 1) {
                            $variacionSelect.val(response.data[0].id).hide();
                            verificarDisponibilidad($this.closest('.producto-item'));
                        }
                        
                        calcularTotales();
                    }
                });
            } else {
                $variacionSelect.hide().empty();
                calcularTotales();
            }
        });
        
        // Manejar cambio de variación
        $(document).on('change', '.variacion-select', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de cantidad
        $(document).on('change', '.cantidad-input', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de fechas
        $('#fecha_inicio, #fecha_fin').change(function() {
            $('.producto-item').each(function() {
                verificarDisponibilidad($(this));
            });
        });
        
        // Función para verificar disponibilidad
        function verificarDisponibilidad($productoItem) {
            const productId = $productoItem.find('.producto-select').val();
            const variationId = $productoItem.find('.variacion-select').val();
            const cantidad = $productoItem.find('.cantidad-input').val();
            const fechaInicio = $('#fecha_inicio').val();
            const fechaFin = $('#fecha_fin').val();
            const $disponibilidadInfo = $productoItem.find('.disponibilidad-info');
            
            if (!productId || !fechaInicio || !fechaFin) {
                $disponibilidadInfo.hide();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'check_product_availability',
                product_id: productId,
                variation_id: variationId || productId,
                cantidad: cantidad,
                fecha_inicio: fechaInicio,
                fecha_fin: fechaFin,
                nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    const $texto = $disponibilidadInfo.find('.disponibilidad-texto');
                    
                    if (response.data.disponible) {
                        $disponibilidadInfo.removeClass('error').addClass('success')
                            .css('background-color', '#d4edda').css('color', '#155724');
                        $texto.html('✅ Producto disponible para las fechas seleccionadas');
                    } else {
                        $disponibilidadInfo.removeClass('success').addClass('error')
                            .css('background-color', '#f8d7da').css('color', '#721c24');
                        $texto.html('❌ Producto NO disponible para las fechas seleccionadas');
                    }
                    
                    $disponibilidadInfo.show();
                }
            });
        }
        
        // Calcular totales
        function calcularTotales() {
            let garantiaTotal = 0;
            
            $('.producto-item').each(function() {
                const $variacionSelect = $(this).find('.variacion-select');
                const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                let garantia = 0;
                
                if ($variacionSelect.is(':visible') && $variacionSelect.val()) {
                    garantia = parseFloat($variacionSelect.find('option:selected').data('garantia')) || 0;
                } else if (!$variacionSelect.is(':visible') && $(this).find('.producto-select').val()) {
                    // Para productos simples, obtener la garantía del producto
                    const $productoSelect = $(this).find('.producto-select');
                    // Esta información debería venir del AJAX de variaciones
                }
                
                garantiaTotal += garantia * cantidad;
            });
            
            $('#garantia_total').val(garantiaTotal.toFixed(2));
        }
        
        // Validar fechas
        $('#fecha_fin').change(function() {
            const fechaInicio = new Date($('#fecha_inicio').val());
            const fechaFin = new Date($(this).val());
            
            if (fechaFin <= fechaInicio) {
                alert('La fecha de fin debe ser posterior a la fecha de inicio');
                $(this).val('');
            }
        });
        
        // Validar formulario antes del envío
        $('#formulario-alquiler-manual').submit(function(e) {
            const clienteExistente = $('#cliente_existente').val();
            const nuevoClienteNombre = $('#nuevo_cliente_nombre').val();
            const nuevoClienteEmail = $('#nuevo_cliente_email').val();
            
            if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                e.preventDefault();
                alert('Debe seleccionar un cliente existente o proporcionar los datos de un nuevo cliente');
                return false;
            }
            
            // Validar que no haya productos no disponibles
            let hayProductosNoDisponibles = false;
            $('.disponibilidad-info.error:visible').each(function() {
                hayProductosNoDisponibles = true;
            });
            
            if (hayProductosNoDisponibles) {
                e.preventDefault();
                alert('Hay productos no disponibles para las fechas seleccionadas. Por favor revise la disponibilidad.');
                return false;
            }
        });
    });
    </script>

    <style>
    .producto-item {
        background: #fafafa;
    }
    .producto-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .producto-row select, .producto-row input {
        margin-right: 10px;
    }
    .form-table th {
        width: 200px;
    }
    h2 {
        color: #1d2327;
        margin: 20px 0 10px 0;
    }
    .disponibilidad-info {
        border: 1px solid;
        border-radius: 4px;
        font-weight: bold;
    }
    .disponibilidad-info.success {
        border-color: #c3e6cb;
    }
    .disponibilidad-info.error {
        border-color: #f5c6cb;
    }
    </style>
    <?php
}

// Función para obtener productos de alquiler
function get_productos_alquiler() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
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
        $productos_wc[] = wc_get_product($producto->ID);
    }
    
    return $productos_wc;
}

// Función para verificar disponibilidad de producto
function verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
    // Convertir fechas
    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);
    
    // Obtener días de gracia del producto (puede estar en variación o producto padre)
    $dias_gracia = 0;
    if ($variation_id && $variation_id != $product_id) {
        $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
        if (!$dias_gracia) {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
    } else {
        $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
    }
    $dias_gracia = $dias_gracia ?: 0;
    
    // Verificar si la variación tiene un período específico configurado
    $producto_actual = wc_get_product($variation_id ?: $product_id);
    $periodo_variacion = null;
    
    if ($producto_actual && $producto_actual->is_type('variation')) {
        // Buscar en los atributos de la variación si hay información de período
        $attributes = $producto_actual->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_value) {
            // Si encuentra algo como "4-dias", "8-dias", etc. en los atributos
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_variacion = intval($matches[1]);
                break;
            }
        }
        
        // También verificar en el nombre/título de la variación
        if (!$periodo_variacion) {
            $nombre_variacion = $producto_actual->get_name();
            if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                $periodo_variacion = intval($matches[1]);
            }
        }
    }
    
    // Buscar órdenes existentes que contengan este producto en el rango de fechas
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
        'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
    );
    
    $ordenes_alquiler = get_posts($args);
    
    foreach ($ordenes_alquiler as $orden_post) {
        $orden = wc_get_order($orden_post->ID);
        
        $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
        $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
        
        if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
        
        $inicio_orden = new DateTime($fecha_inicio_orden);
        $fin_orden = new DateTime($fecha_fin_orden);
        
        // Agregar días de gracia al final del alquiler existente
        if ($dias_gracia > 0) {
            $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
        }
        
        // Verificar si hay solapamiento de fechas
        if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
            // Hay solapamiento, verificar si es el mismo producto/variación
            foreach ($orden->get_items() as $item) {
                $producto_orden = $item->get_product();
                
                if (!$producto_orden) continue;
                
                // Verificar si es el mismo producto/variación específica
                $mismo_producto = false;
                
                if ($variation_id && $variation_id != $product_id) {
                    // Comparar variación específica
                    if ($producto_orden->get_id() == $variation_id) {
                        $mismo_producto = true;
                    }
                } else {
                    // Producto simple - comparar ID del producto padre
                    $producto_id_orden = $producto_orden->is_type('variation') ? 
                        $producto_orden->get_parent_id() : $producto_orden->get_id();
                    if ($producto_id_orden == $product_id) {
                        $mismo_producto = true;
                    }
                }
                
                if ($mismo_producto) {
                    // Verificar stock/cantidad disponible
                    $cantidad_orden = $item->get_quantity();
                    
                    // Si se maneja stock, verificar disponibilidad
                    if ($producto_actual && $producto_actual->managing_stock()) {
                        $stock_disponible = $producto_actual->get_stock_quantity();
                        if ($stock_disponible < $cantidad_solicitada) {
                            return false;
                        }
                    } else {
                        // Si no se maneja stock, asumir que solo hay 1 disponible por variación
                        return false;
                    }
                }
            }
        }
    }
    
    return true; // Disponible
}

// Función para procesar el formulario (actualizada para manejar variaciones)
function procesar_alquiler_manual($datos) {
    try {
        // Validar datos básicos
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            return array('success' => false, 'message' => 'Las fechas son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validar disponibilidad de todos los productos antes de crear la orden
        foreach ($datos['productos'] as $producto_data) {
            if (empty($producto_data['id'])) continue;
            
            $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : $producto_data['id'];
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            $disponible = verificar_disponibilidad_producto(
                $producto_data['id'], 
                $variation_id, 
                $datos['fecha_inicio'], 
                $datos['fecha_fin'], 
                $cantidad
            );
            
            if (!$disponible) {
                $producto = wc_get_product($variation_id);
                return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible para las fechas seleccionadas');
            }
        }
        
        // Obtener o crear cliente
        $cliente_id = obtener_o_crear_cliente($datos);
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
            
            // Obtener el producto (variación o simple)
            if ($variation_id && $variation_id != $product_id) {
                $producto = wc_get_product($variation_id);
                $producto_padre = wc_get_product($product_id);
            } else {
                $producto = wc_get_product($product_id);
                $producto_padre = null;
            }
            
            if (!$producto) continue;
            
            // Agregar producto a la orden
            $item = $order->add_product($producto, $cantidad);
            
            // Calcular garantía (primero buscar en la variación, luego en el producto padre)
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
                $total_antes_descuento = $order->get_total();
                $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                
                $coupon = new WC_Coupon();
                $coupon->set_code('DESCUENTO_MANUAL_' . time());
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($descuento_cantidad);
                $coupon->save();
                
                $order->apply_coupon($coupon->get_code());
            }
        }
        
        // Agregar metadatos de alquiler
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
        
        // Calcular totales y guardar
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

// Función para obtener o crear cliente (sin cambios)
function obtener_o_crear_cliente($datos) {
    // Si se seleccionó un cliente existente
    if (!empty($datos['cliente_id'])) {
        return intval($datos['cliente_id']);
    }
    
    // Si se proporcionaron datos para nuevo cliente
    if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
        $email = sanitize_email($datos['nuevo_cliente_email']);
        $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
        $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
        
        // Verificar si ya existe un usuario con ese email
        $usuario_existente = get_user_by('email', $email);
        if ($usuario_existente) {
            return $usuario_existente->ID;
        }
        
        // Crear nuevo usuario
        $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            // Asignar rol de customer
            $user = new WP_User($user_id);
            $user->set_role('customer');
            
            // Agregar metadatos
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

// Agregar columnas personalizadas en la lista de órdenes (sin cambios)
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['es_alquiler'] = 'Tipo';
            $new_columns['fechas_alquiler'] = 'Fechas Alquiler';
        }
    }
    
    return $new_columns;
});

// Mostrar contenido de las columnas personalizadas (sin cambios)
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'es_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            echo '<span style="color: #2271b1; font-weight: bold;">🏠 Alquiler</span>';
        } else {
            echo 'Venta';
        }
    }
    
    if ($column === 'fechas_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            $fecha_inicio = get_post_meta($post_id, '_fecha_inicio_alquiler', true);
            $fecha_fin = get_post_meta($post_id, '_fecha_fin_alquiler', true);
            
            if ($fecha_inicio && $fecha_fin) {
                echo date('d/m/Y', strtotime($fecha_inicio)) . '<br>a<br>' . date('d/m/Y', strtotime($fecha_fin));
            }
        } else {
            echo '-';
        }
    }
}, 10, 2);
?> + variation.garantia : '') +
                                             (variation.periodo_dias ? ' - Período: ' + variation.periodo_dias + ' días' : '');
                            
                            $variacionSelect.append('<option value="' + variation.id + '" ' +
                                'data-precio="' + variation.price + '" ' +
                                'data-garantia="' + variation.garantia + '" ' +
                                'data-dias-gracia="' + variation.dias_gracia + '" ' +
                                'data-periodo="' + (variation.periodo_dias || '') + '" ' +
                                'data-stock="' + (variation.stock_quantity || '') + '" ' +
                                'data-managing-stock="' + (variation.managing_stock ? '1' : '0') + '">' + 
                                optionText + '</option>');
                        });
                        
                        if (response.data.length > 1) {
                            $variacionSelect.show();
                        } else if (response.data.length === 1) {
                            $variacionSelect.val(response.data[0].id).hide();
                            verificarDisponibilidad($this.closest('.producto-item'));
                        }
                        
                        calcularTotales();
                    }
                });
            } else {
                $variacionSelect.hide().empty();
                calcularTotales();
            }
        });
        
        // Manejar cambio de variación
        $(document).on('change', '.variacion-select', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de cantidad
        $(document).on('change', '.cantidad-input', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de fechas
        $('#fecha_inicio, #fecha_fin').change(function() {
            $('.producto-item').each(function() {
                verificarDisponibilidad($(this));
            });
        });
        
        // Función para verificar disponibilidad
        function verificarDisponibilidad($productoItem) {
            const productId = $productoItem.find('.producto-select').val();
            const variationId = $productoItem.find('.variacion-select').val();
            const cantidad = $productoItem.find('.cantidad-input').val();
            const fechaInicio = $('#fecha_inicio').val();
            const fechaFin = $('#fecha_fin').val();
            const $disponibilidadInfo = $productoItem.find('.disponibilidad-info');
            
            if (!productId || !fechaInicio || !fechaFin) {
                $disponibilidadInfo.hide();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'check_product_availability',
                product_id: productId,
                variation_id: variationId || productId,
                cantidad: cantidad,
                fecha_inicio: fechaInicio,
                fecha_fin: fechaFin,
                nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    const $texto = $disponibilidadInfo.find('.disponibilidad-texto');
                    
                    if (response.data.disponible) {
                        $disponibilidadInfo.removeClass('error').addClass('success')
                            .css('background-color', '#d4edda').css('color', '#155724');
                        $texto.html('✅ Producto disponible para las fechas seleccionadas');
                    } else {
                        $disponibilidadInfo.removeClass('success').addClass('error')
                            .css('background-color', '#f8d7da').css('color', '#721c24');
                        $texto.html('❌ Producto NO disponible para las fechas seleccionadas');
                    }
                    
                    $disponibilidadInfo.show();
                }
            });
        }
        
        // Calcular totales
        function calcularTotales() {
            let garantiaTotal = 0;
            
            $('.producto-item').each(function() {
                const $variacionSelect = $(this).find('.variacion-select');
                const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                let garantia = 0;
                
                if ($variacionSelect.is(':visible') && $variacionSelect.val()) {
                    garantia = parseFloat($variacionSelect.find('option:selected').data('garantia')) || 0;
                } else if (!$variacionSelect.is(':visible') && $(this).find('.producto-select').val()) {
                    // Para productos simples, obtener la garantía del producto
                    const $productoSelect = $(this).find('.producto-select');
                    // Esta información debería venir del AJAX de variaciones
                }
                
                garantiaTotal += garantia * cantidad;
            });
            
            $('#garantia_total').val(garantiaTotal.toFixed(2));
        }
        
        // Validar fechas
        $('#fecha_fin').change(function() {
            const fechaInicio = new Date($('#fecha_inicio').val());
            const fechaFin = new Date($(this).val());
            
            if (fechaFin <= fechaInicio) {
                alert('La fecha de fin debe ser posterior a la fecha de inicio');
                $(this).val('');
            }
        });
        
        // Validar formulario antes del envío
        $('#formulario-alquiler-manual').submit(function(e) {
            const clienteExistente = $('#cliente_existente').val();
            const nuevoClienteNombre = $('#nuevo_cliente_nombre').val();
            const nuevoClienteEmail = $('#nuevo_cliente_email').val();
            
            if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                e.preventDefault();
                alert('Debe seleccionar un cliente existente o proporcionar los datos de un nuevo cliente');
                return false;
            }
            
            // Validar que no haya productos no disponibles
            let hayProductosNoDisponibles = false;
            $('.disponibilidad-info.error:visible').each(function() {
                hayProductosNoDisponibles = true;
            });
            
            if (hayProductosNoDisponibles) {
                e.preventDefault();
                alert('Hay productos no disponibles para las fechas seleccionadas. Por favor revise la disponibilidad.');
                return false;
            }
        });
    });
    </script>

    <style>
    .producto-item {
        background: #fafafa;
    }
    .producto-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .producto-row select, .producto-row input {
        margin-right: 10px;
    }
    .form-table th {
        width: 200px;
    }
    h2 {
        color: #1d2327;
        margin: 20px 0 10px 0;
    }
    .disponibilidad-info {
        border: 1px solid;
        border-radius: 4px;
        font-weight: bold;
    }
    .disponibilidad-info.success {
        border-color: #c3e6cb;
    }
    .disponibilidad-info.error {
        border-color: #f5c6cb;
    }
    </style>
    <?php
}

// Función para obtener productos de alquiler
function get_productos_alquiler() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
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
        $productos_wc[] = wc_get_product($producto->ID);
    }
    
    return $productos_wc;
}

// Función para verificar disponibilidad de producto
function verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
    // Convertir fechas
    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);
    
    // Obtener días de gracia del producto (puede estar en variación o producto padre)
    $dias_gracia = 0;
    if ($variation_id && $variation_id != $product_id) {
        $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
        if (!$dias_gracia) {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
    } else {
        $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
    }
    $dias_gracia = $dias_gracia ?: 0;
    
    // Verificar si la variación tiene un período específico configurado
    $producto_actual = wc_get_product($variation_id ?: $product_id);
    $periodo_variacion = null;
    
    if ($producto_actual && $producto_actual->is_type('variation')) {
        // Buscar en los atributos de la variación si hay información de período
        $attributes = $producto_actual->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_value) {
            // Si encuentra algo como "4-dias", "8-dias", etc. en los atributos
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_variacion = intval($matches[1]);
                break;
            }
        }
        
        // También verificar en el nombre/título de la variación
        if (!$periodo_variacion) {
            $nombre_variacion = $producto_actual->get_name();
            if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                $periodo_variacion = intval($matches[1]);
            }
        }
    }
    
    // Buscar órdenes existentes que contengan este producto en el rango de fechas
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
        'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
    );
    
    $ordenes_alquiler = get_posts($args);
    
    foreach ($ordenes_alquiler as $orden_post) {
        $orden = wc_get_order($orden_post->ID);
        
        $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
        $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
        
        if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
        
        $inicio_orden = new DateTime($fecha_inicio_orden);
        $fin_orden = new DateTime($fecha_fin_orden);
        
        // Agregar días de gracia al final del alquiler existente
        if ($dias_gracia > 0) {
            $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
        }
        
        // Verificar si hay solapamiento de fechas
        if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
            // Hay solapamiento, verificar si es el mismo producto/variación
            foreach ($orden->get_items() as $item) {
                $producto_orden = $item->get_product();
                
                if (!$producto_orden) continue;
                
                // Verificar si es el mismo producto/variación específica
                $mismo_producto = false;
                
                if ($variation_id && $variation_id != $product_id) {
                    // Comparar variación específica
                    if ($producto_orden->get_id() == $variation_id) {
                        $mismo_producto = true;
                    }
                } else {
                    // Producto simple - comparar ID del producto padre
                    $producto_id_orden = $producto_orden->is_type('variation') ? 
                        $producto_orden->get_parent_id() : $producto_orden->get_id();
                    if ($producto_id_orden == $product_id) {
                        $mismo_producto = true;
                    }
                }
                
                if ($mismo_producto) {
                    // Verificar stock/cantidad disponible
                    $cantidad_orden = $item->get_quantity();
                    
                    // Si se maneja stock, verificar disponibilidad
                    if ($producto_actual && $producto_actual->managing_stock()) {
                        $stock_disponible = $producto_actual->get_stock_quantity();
                        if ($stock_disponible < $cantidad_solicitada) {
                            return false;
                        }
                    } else {
                        // Si no se maneja stock, asumir que solo hay 1 disponible por variación
                        return false;
                    }
                }
            }
        }
    }
    
    return true; // Disponible
}

// Función para procesar el formulario (actualizada para manejar variaciones)
function procesar_alquiler_manual($datos) {
    try {
        // Validar datos básicos
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            return array('success' => false, 'message' => 'Las fechas son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validar disponibilidad de todos los productos antes de crear la orden
        foreach ($datos['productos'] as $producto_data) {
            if (empty($producto_data['id'])) continue;
            
            $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : $producto_data['id'];
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            $disponible = verificar_disponibilidad_producto(
                $producto_data['id'], 
                $variation_id, 
                $datos['fecha_inicio'], 
                $datos['fecha_fin'], 
                $cantidad
            );
            
            if (!$disponible) {
                $producto = wc_get_product($variation_id);
                return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible para las fechas seleccionadas');
            }
        }
        
        // Obtener o crear cliente
        $cliente_id = obtener_o_crear_cliente($datos);
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
            
            // Obtener el producto (variación o simple)
            if ($variation_id && $variation_id != $product_id) {
                $producto = wc_get_product($variation_id);
                $producto_padre = wc_get_product($product_id);
            } else {
                $producto = wc_get_product($product_id);
                $producto_padre = null;
            }
            
            if (!$producto) continue;
            
            // Agregar producto a la orden
            $item = $order->add_product($producto, $cantidad);
            
            // Calcular garantía (primero buscar en la variación, luego en el producto padre)
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
                $total_antes_descuento = $order->get_total();
                $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                
                $coupon = new WC_Coupon();
                $coupon->set_code('DESCUENTO_MANUAL_' . time());
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($descuento_cantidad);
                $coupon->save();
                
                $order->apply_coupon($coupon->get_code());
            }
        }
        
        // Agregar metadatos de alquiler
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
        
        // Calcular totales y guardar
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

// Función para obtener o crear cliente (sin cambios)
function obtener_o_crear_cliente($datos) {
    // Si se seleccionó un cliente existente
    if (!empty($datos['cliente_id'])) {
        return intval($datos['cliente_id']);
    }
    
    // Si se proporcionaron datos para nuevo cliente
    if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
        $email = sanitize_email($datos['nuevo_cliente_email']);
        $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
        $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
        
        // Verificar si ya existe un usuario con ese email
        $usuario_existente = get_user_by('email', $email);
        if ($usuario_existente) {
            return $usuario_existente->ID;
        }
        
        // Crear nuevo usuario
        $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            // Asignar rol de customer
            $user = new WP_User($user_id);
            $user->set_role('customer');
            
            // Agregar metadatos
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

// Agregar columnas personalizadas en la lista de órdenes (sin cambios)
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['es_alquiler'] = 'Tipo';
            $new_columns['fechas_alquiler'] = 'Fechas Alquiler';
        }
    }
    
    return $new_columns;
});

// Mostrar contenido de las columnas personalizadas (sin cambios)
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'es_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            echo '<span style="color: #2271b1; font-weight: bold;">🏠 Alquiler</span>';
        } else {
            echo 'Venta';
        }
    }
    
    if ($column === 'fechas_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            $fecha_inicio = get_post_meta($post_id, '_fecha_inicio_alquiler', true);
            $fecha_fin = get_post_meta($post_id, '_fecha_fin_alquiler', true);
            
            if ($fecha_inicio && $fecha_fin) {
                echo date('d/m/Y', strtotime($fecha_inicio)) . '<br>a<br>' . date('d/m/Y', strtotime($fecha_fin));
            }
        } else {
            echo '-';
        }
    }
}, 10, 2);
?> + precio);
                $infoVariacion.find('.garantia-valor').text(garantia > 0 ? '
        
        // Manejar cambio de cantidad
        $(document).on('change', '.cantidad-input', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de fechas
        $('#fecha_inicio, #fecha_fin').change(function() {
            $('.producto-item').each(function() {
                verificarDisponibilidad($(this));
            });
        });
        
        // Función para verificar disponibilidad
        function verificarDisponibilidad($productoItem) {
            const productId = $productoItem.find('.producto-select').val();
            const variationId = $productoItem.find('.variacion-select').val();
            const cantidad = $productoItem.find('.cantidad-input').val();
            const fechaInicio = $('#fecha_inicio').val();
            const fechaFin = $('#fecha_fin').val();
            const $disponibilidadInfo = $productoItem.find('.disponibilidad-info');
            
            if (!productId || !fechaInicio || !fechaFin) {
                $disponibilidadInfo.hide();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'check_product_availability',
                product_id: productId,
                variation_id: variationId || productId,
                cantidad: cantidad,
                fecha_inicio: fechaInicio,
                fecha_fin: fechaFin,
                nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    const $texto = $disponibilidadInfo.find('.disponibilidad-texto');
                    
                    if (response.data.disponible) {
                        $disponibilidadInfo.removeClass('error').addClass('success')
                            .css('background-color', '#d4edda').css('color', '#155724');
                        $texto.html('✅ Producto disponible para las fechas seleccionadas');
                    } else {
                        $disponibilidadInfo.removeClass('success').addClass('error')
                            .css('background-color', '#f8d7da').css('color', '#721c24');
                        $texto.html('❌ Producto NO disponible para las fechas seleccionadas');
                    }
                    
                    $disponibilidadInfo.show();
                }
            });
        }
        
        // Calcular totales
        function calcularTotales() {
            let garantiaTotal = 0;
            
            $('.producto-item').each(function() {
                const $productoSelect = $(this).find('.producto-select');
                const $variacionSelect = $(this).find('.variacion-select');
                const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                let garantia = 0;
                
                if ($variacionSelect.is(':visible') && $variacionSelect.val()) {
                    // Producto variable - obtener garantía de la variación seleccionada
                    garantia = parseFloat($variacionSelect.find('option:selected').data('garantia')) || 0;
                } else if ($productoSelect.val()) {
                    // Producto simple - obtener garantía del endpoint AJAX
                    // Esta información ya debería estar disponible desde la carga de variaciones
                    const productId = $productoSelect.val();
                    
                    // Si hay solo una opción en variaciones (producto simple), usar esa garantía
                    if ($variacionSelect.find('option').length === 2) { // "Seleccionar..." + 1 opción
                        const $option = $variacionSelect.find('option:last');
                        if ($option.length) {
                            garantia = parseFloat($option.data('garantia')) || 0;
                        }
                    }
                }
                
                garantiaTotal += garantia * cantidad;
            });
            
            $('#garantia_total').val(garantiaTotal.toFixed(2));
        }
        
        // Validar fechas
        $('#fecha_fin').change(function() {
            const fechaInicio = new Date($('#fecha_inicio').val());
            const fechaFin = new Date($(this).val());
            
            if (fechaFin <= fechaInicio) {
                alert('La fecha de fin debe ser posterior a la fecha de inicio');
                $(this).val('');
            }
        });
        
        // Validar formulario antes del envío
        $('#formulario-alquiler-manual').submit(function(e) {
            const clienteExistente = $('#cliente_existente').val();
            const nuevoClienteNombre = $('#nuevo_cliente_nombre').val();
            const nuevoClienteEmail = $('#nuevo_cliente_email').val();
            
            if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                e.preventDefault();
                alert('Debe seleccionar un cliente existente o proporcionar los datos de un nuevo cliente');
                return false;
            }
            
            // Validar que no haya productos no disponibles
            let hayProductosNoDisponibles = false;
            $('.disponibilidad-info.error:visible').each(function() {
                hayProductosNoDisponibles = true;
            });
            
            if (hayProductosNoDisponibles) {
                e.preventDefault();
                alert('Hay productos no disponibles para las fechas seleccionadas. Por favor revise la disponibilidad.');
                return false;
            }
        });
    });
    </script>

    <style>
    .producto-item {
        background: #fafafa;
    }
    .producto-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .producto-row select, .producto-row input {
        margin-right: 10px;
    }
    .form-table th {
        width: 200px;
    }
    h2 {
        color: #1d2327;
        margin: 20px 0 10px 0;
    }
    .disponibilidad-info {
        border: 1px solid;
        border-radius: 4px;
        font-weight: bold;
    }
    .disponibilidad-info.success {
        border-color: #c3e6cb;
    }
    .disponibilidad-info.error {
        border-color: #f5c6cb;
    }
    </style>
    <?php
}

// Función para obtener productos de alquiler
function get_productos_alquiler() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
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
        $productos_wc[] = wc_get_product($producto->ID);
    }
    
    return $productos_wc;
}

// Función para verificar disponibilidad de producto
function verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
    // Convertir fechas
    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);
    
    // Obtener días de gracia del producto (puede estar en variación o producto padre)
    $dias_gracia = 0;
    if ($variation_id && $variation_id != $product_id) {
        $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
        if (!$dias_gracia) {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
    } else {
        $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
    }
    $dias_gracia = $dias_gracia ?: 0;
    
    // Verificar si la variación tiene un período específico configurado
    $producto_actual = wc_get_product($variation_id ?: $product_id);
    $periodo_variacion = null;
    
    if ($producto_actual && $producto_actual->is_type('variation')) {
        // Buscar en los atributos de la variación si hay información de período
        $attributes = $producto_actual->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_value) {
            // Si encuentra algo como "4-dias", "8-dias", etc. en los atributos
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_variacion = intval($matches[1]);
                break;
            }
        }
        
        // También verificar en el nombre/título de la variación
        if (!$periodo_variacion) {
            $nombre_variacion = $producto_actual->get_name();
            if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                $periodo_variacion = intval($matches[1]);
            }
        }
    }
    
    // Buscar órdenes existentes que contengan este producto en el rango de fechas
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
        'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
    );
    
    $ordenes_alquiler = get_posts($args);
    
    foreach ($ordenes_alquiler as $orden_post) {
        $orden = wc_get_order($orden_post->ID);
        
        $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
        $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
        
        if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
        
        $inicio_orden = new DateTime($fecha_inicio_orden);
        $fin_orden = new DateTime($fecha_fin_orden);
        
        // Agregar días de gracia al final del alquiler existente
        if ($dias_gracia > 0) {
            $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
        }
        
        // Verificar si hay solapamiento de fechas
        if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
            // Hay solapamiento, verificar si es el mismo producto/variación
            foreach ($orden->get_items() as $item) {
                $producto_orden = $item->get_product();
                
                if (!$producto_orden) continue;
                
                // Verificar si es el mismo producto/variación específica
                $mismo_producto = false;
                
                if ($variation_id && $variation_id != $product_id) {
                    // Comparar variación específica
                    if ($producto_orden->get_id() == $variation_id) {
                        $mismo_producto = true;
                    }
                } else {
                    // Producto simple - comparar ID del producto padre
                    $producto_id_orden = $producto_orden->is_type('variation') ? 
                        $producto_orden->get_parent_id() : $producto_orden->get_id();
                    if ($producto_id_orden == $product_id) {
                        $mismo_producto = true;
                    }
                }
                
                if ($mismo_producto) {
                    // Verificar stock/cantidad disponible
                    $cantidad_orden = $item->get_quantity();
                    
                    // Si se maneja stock, verificar disponibilidad
                    if ($producto_actual && $producto_actual->managing_stock()) {
                        $stock_disponible = $producto_actual->get_stock_quantity();
                        if ($stock_disponible < $cantidad_solicitada) {
                            return false;
                        }
                    } else {
                        // Si no se maneja stock, asumir que solo hay 1 disponible por variación
                        return false;
                    }
                }
            }
        }
    }
    
    return true; // Disponible
}

// Función para procesar el formulario (actualizada para manejar variaciones)
function procesar_alquiler_manual($datos) {
    try {
        // Validar datos básicos
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            return array('success' => false, 'message' => 'Las fechas son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validar disponibilidad de todos los productos antes de crear la orden
        foreach ($datos['productos'] as $producto_data) {
            if (empty($producto_data['id'])) continue;
            
            $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : $producto_data['id'];
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            $disponible = verificar_disponibilidad_producto(
                $producto_data['id'], 
                $variation_id, 
                $datos['fecha_inicio'], 
                $datos['fecha_fin'], 
                $cantidad
            );
            
            if (!$disponible) {
                $producto = wc_get_product($variation_id);
                return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible para las fechas seleccionadas');
            }
        }
        
        // Obtener o crear cliente
        $cliente_id = obtener_o_crear_cliente($datos);
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
            
            // Obtener el producto (variación o simple)
            if ($variation_id && $variation_id != $product_id) {
                $producto = wc_get_product($variation_id);
                $producto_padre = wc_get_product($product_id);
            } else {
                $producto = wc_get_product($product_id);
                $producto_padre = null;
            }
            
            if (!$producto) continue;
            
            // Agregar producto a la orden
            $item = $order->add_product($producto, $cantidad);
            
            // Calcular garantía (primero buscar en la variación, luego en el producto padre)
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
                $total_antes_descuento = $order->get_total();
                $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                
                $coupon = new WC_Coupon();
                $coupon->set_code('DESCUENTO_MANUAL_' . time());
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($descuento_cantidad);
                $coupon->save();
                
                $order->apply_coupon($coupon->get_code());
            }
        }
        
        // Agregar metadatos de alquiler
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
        
        // Calcular totales y guardar
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

// Función para obtener o crear cliente (sin cambios)
function obtener_o_crear_cliente($datos) {
    // Si se seleccionó un cliente existente
    if (!empty($datos['cliente_id'])) {
        return intval($datos['cliente_id']);
    }
    
    // Si se proporcionaron datos para nuevo cliente
    if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
        $email = sanitize_email($datos['nuevo_cliente_email']);
        $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
        $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
        
        // Verificar si ya existe un usuario con ese email
        $usuario_existente = get_user_by('email', $email);
        if ($usuario_existente) {
            return $usuario_existente->ID;
        }
        
        // Crear nuevo usuario
        $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            // Asignar rol de customer
            $user = new WP_User($user_id);
            $user->set_role('customer');
            
            // Agregar metadatos
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

// Agregar columnas personalizadas en la lista de órdenes (sin cambios)
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['es_alquiler'] = 'Tipo';
            $new_columns['fechas_alquiler'] = 'Fechas Alquiler';
        }
    }
    
    return $new_columns;
});

// Mostrar contenido de las columnas personalizadas (sin cambios)
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'es_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            echo '<span style="color: #2271b1; font-weight: bold;">🏠 Alquiler</span>';
        } else {
            echo 'Venta';
        }
    }
    
    if ($column === 'fechas_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            $fecha_inicio = get_post_meta($post_id, '_fecha_inicio_alquiler', true);
            $fecha_fin = get_post_meta($post_id, '_fecha_fin_alquiler', true);
            
            if ($fecha_inicio && $fecha_fin) {
                echo date('d/m/Y', strtotime($fecha_inicio)) . '<br>a<br>' . date('d/m/Y', strtotime($fecha_fin));
            }
        } else {
            echo '-';
        }
    }
}, 10, 2);
?> + variation.price + 
                                             (variation.garantia > 0 ? ' - Garantía: 
                        
                        if (response.data.length > 1) {
                            $variacionSelect.show();
                        } else if (response.data.length === 1) {
                            $variacionSelect.val(response.data[0].id).hide();
                            verificarDisponibilidad($this.closest('.producto-item'));
                        }
                        
                        calcularTotales();
                    }
                });
            } else {
                $variacionSelect.hide().empty();
                calcularTotales();
            }
        });
        
        // Manejar cambio de variación
        $(document).on('change', '.variacion-select', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de cantidad
        $(document).on('change', '.cantidad-input', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de fechas
        $('#fecha_inicio, #fecha_fin').change(function() {
            $('.producto-item').each(function() {
                verificarDisponibilidad($(this));
            });
        });
        
        // Función para verificar disponibilidad
        function verificarDisponibilidad($productoItem) {
            const productId = $productoItem.find('.producto-select').val();
            const variationId = $productoItem.find('.variacion-select').val();
            const cantidad = $productoItem.find('.cantidad-input').val();
            const fechaInicio = $('#fecha_inicio').val();
            const fechaFin = $('#fecha_fin').val();
            const $disponibilidadInfo = $productoItem.find('.disponibilidad-info');
            
            if (!productId || !fechaInicio || !fechaFin) {
                $disponibilidadInfo.hide();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'check_product_availability',
                product_id: productId,
                variation_id: variationId || productId,
                cantidad: cantidad,
                fecha_inicio: fechaInicio,
                fecha_fin: fechaFin,
                nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    const $texto = $disponibilidadInfo.find('.disponibilidad-texto');
                    
                    if (response.data.disponible) {
                        $disponibilidadInfo.removeClass('error').addClass('success')
                            .css('background-color', '#d4edda').css('color', '#155724');
                        $texto.html('✅ Producto disponible para las fechas seleccionadas');
                    } else {
                        $disponibilidadInfo.removeClass('success').addClass('error')
                            .css('background-color', '#f8d7da').css('color', '#721c24');
                        $texto.html('❌ Producto NO disponible para las fechas seleccionadas');
                    }
                    
                    $disponibilidadInfo.show();
                }
            });
        }
        
        // Calcular totales
        function calcularTotales() {
            let garantiaTotal = 0;
            
            $('.producto-item').each(function() {
                const $variacionSelect = $(this).find('.variacion-select');
                const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                let garantia = 0;
                
                if ($variacionSelect.is(':visible') && $variacionSelect.val()) {
                    garantia = parseFloat($variacionSelect.find('option:selected').data('garantia')) || 0;
                } else if (!$variacionSelect.is(':visible') && $(this).find('.producto-select').val()) {
                    // Para productos simples, obtener la garantía del producto
                    const $productoSelect = $(this).find('.producto-select');
                    // Esta información debería venir del AJAX de variaciones
                }
                
                garantiaTotal += garantia * cantidad;
            });
            
            $('#garantia_total').val(garantiaTotal.toFixed(2));
        }
        
        // Validar fechas
        $('#fecha_fin').change(function() {
            const fechaInicio = new Date($('#fecha_inicio').val());
            const fechaFin = new Date($(this).val());
            
            if (fechaFin <= fechaInicio) {
                alert('La fecha de fin debe ser posterior a la fecha de inicio');
                $(this).val('');
            }
        });
        
        // Validar formulario antes del envío
        $('#formulario-alquiler-manual').submit(function(e) {
            const clienteExistente = $('#cliente_existente').val();
            const nuevoClienteNombre = $('#nuevo_cliente_nombre').val();
            const nuevoClienteEmail = $('#nuevo_cliente_email').val();
            
            if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                e.preventDefault();
                alert('Debe seleccionar un cliente existente o proporcionar los datos de un nuevo cliente');
                return false;
            }
            
            // Validar que no haya productos no disponibles
            let hayProductosNoDisponibles = false;
            $('.disponibilidad-info.error:visible').each(function() {
                hayProductosNoDisponibles = true;
            });
            
            if (hayProductosNoDisponibles) {
                e.preventDefault();
                alert('Hay productos no disponibles para las fechas seleccionadas. Por favor revise la disponibilidad.');
                return false;
            }
        });
    });
    </script>

    <style>
    .producto-item {
        background: #fafafa;
    }
    .producto-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .producto-row select, .producto-row input {
        margin-right: 10px;
    }
    .form-table th {
        width: 200px;
    }
    h2 {
        color: #1d2327;
        margin: 20px 0 10px 0;
    }
    .disponibilidad-info {
        border: 1px solid;
        border-radius: 4px;
        font-weight: bold;
    }
    .disponibilidad-info.success {
        border-color: #c3e6cb;
    }
    .disponibilidad-info.error {
        border-color: #f5c6cb;
    }
    </style>
    <?php
}

// Función para obtener productos de alquiler
function get_productos_alquiler() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
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
        $productos_wc[] = wc_get_product($producto->ID);
    }
    
    return $productos_wc;
}

// Función para verificar disponibilidad de producto
function verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
    // Convertir fechas
    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);
    
    // Obtener días de gracia del producto (puede estar en variación o producto padre)
    $dias_gracia = 0;
    if ($variation_id && $variation_id != $product_id) {
        $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
        if (!$dias_gracia) {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
    } else {
        $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
    }
    $dias_gracia = $dias_gracia ?: 0;
    
    // Verificar si la variación tiene un período específico configurado
    $producto_actual = wc_get_product($variation_id ?: $product_id);
    $periodo_variacion = null;
    
    if ($producto_actual && $producto_actual->is_type('variation')) {
        // Buscar en los atributos de la variación si hay información de período
        $attributes = $producto_actual->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_value) {
            // Si encuentra algo como "4-dias", "8-dias", etc. en los atributos
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_variacion = intval($matches[1]);
                break;
            }
        }
        
        // También verificar en el nombre/título de la variación
        if (!$periodo_variacion) {
            $nombre_variacion = $producto_actual->get_name();
            if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                $periodo_variacion = intval($matches[1]);
            }
        }
    }
    
    // Buscar órdenes existentes que contengan este producto en el rango de fechas
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
        'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
    );
    
    $ordenes_alquiler = get_posts($args);
    
    foreach ($ordenes_alquiler as $orden_post) {
        $orden = wc_get_order($orden_post->ID);
        
        $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
        $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
        
        if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
        
        $inicio_orden = new DateTime($fecha_inicio_orden);
        $fin_orden = new DateTime($fecha_fin_orden);
        
        // Agregar días de gracia al final del alquiler existente
        if ($dias_gracia > 0) {
            $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
        }
        
        // Verificar si hay solapamiento de fechas
        if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
            // Hay solapamiento, verificar si es el mismo producto/variación
            foreach ($orden->get_items() as $item) {
                $producto_orden = $item->get_product();
                
                if (!$producto_orden) continue;
                
                // Verificar si es el mismo producto/variación específica
                $mismo_producto = false;
                
                if ($variation_id && $variation_id != $product_id) {
                    // Comparar variación específica
                    if ($producto_orden->get_id() == $variation_id) {
                        $mismo_producto = true;
                    }
                } else {
                    // Producto simple - comparar ID del producto padre
                    $producto_id_orden = $producto_orden->is_type('variation') ? 
                        $producto_orden->get_parent_id() : $producto_orden->get_id();
                    if ($producto_id_orden == $product_id) {
                        $mismo_producto = true;
                    }
                }
                
                if ($mismo_producto) {
                    // Verificar stock/cantidad disponible
                    $cantidad_orden = $item->get_quantity();
                    
                    // Si se maneja stock, verificar disponibilidad
                    if ($producto_actual && $producto_actual->managing_stock()) {
                        $stock_disponible = $producto_actual->get_stock_quantity();
                        if ($stock_disponible < $cantidad_solicitada) {
                            return false;
                        }
                    } else {
                        // Si no se maneja stock, asumir que solo hay 1 disponible por variación
                        return false;
                    }
                }
            }
        }
    }
    
    return true; // Disponible
}

// Función para procesar el formulario (actualizada para manejar variaciones)
function procesar_alquiler_manual($datos) {
    try {
        // Validar datos básicos
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            return array('success' => false, 'message' => 'Las fechas son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validar disponibilidad de todos los productos antes de crear la orden
        foreach ($datos['productos'] as $producto_data) {
            if (empty($producto_data['id'])) continue;
            
            $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : $producto_data['id'];
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            $disponible = verificar_disponibilidad_producto(
                $producto_data['id'], 
                $variation_id, 
                $datos['fecha_inicio'], 
                $datos['fecha_fin'], 
                $cantidad
            );
            
            if (!$disponible) {
                $producto = wc_get_product($variation_id);
                return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible para las fechas seleccionadas');
            }
        }
        
        // Obtener o crear cliente
        $cliente_id = obtener_o_crear_cliente($datos);
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
            
            // Obtener el producto (variación o simple)
            if ($variation_id && $variation_id != $product_id) {
                $producto = wc_get_product($variation_id);
                $producto_padre = wc_get_product($product_id);
            } else {
                $producto = wc_get_product($product_id);
                $producto_padre = null;
            }
            
            if (!$producto) continue;
            
            // Agregar producto a la orden
            $item = $order->add_product($producto, $cantidad);
            
            // Calcular garantía (primero buscar en la variación, luego en el producto padre)
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
                $total_antes_descuento = $order->get_total();
                $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                
                $coupon = new WC_Coupon();
                $coupon->set_code('DESCUENTO_MANUAL_' . time());
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($descuento_cantidad);
                $coupon->save();
                
                $order->apply_coupon($coupon->get_code());
            }
        }
        
        // Agregar metadatos de alquiler
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
        
        // Calcular totales y guardar
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

// Función para obtener o crear cliente (sin cambios)
function obtener_o_crear_cliente($datos) {
    // Si se seleccionó un cliente existente
    if (!empty($datos['cliente_id'])) {
        return intval($datos['cliente_id']);
    }
    
    // Si se proporcionaron datos para nuevo cliente
    if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
        $email = sanitize_email($datos['nuevo_cliente_email']);
        $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
        $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
        
        // Verificar si ya existe un usuario con ese email
        $usuario_existente = get_user_by('email', $email);
        if ($usuario_existente) {
            return $usuario_existente->ID;
        }
        
        // Crear nuevo usuario
        $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            // Asignar rol de customer
            $user = new WP_User($user_id);
            $user->set_role('customer');
            
            // Agregar metadatos
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

// Agregar columnas personalizadas en la lista de órdenes (sin cambios)
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['es_alquiler'] = 'Tipo';
            $new_columns['fechas_alquiler'] = 'Fechas Alquiler';
        }
    }
    
    return $new_columns;
});

// Mostrar contenido de las columnas personalizadas (sin cambios)
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'es_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            echo '<span style="color: #2271b1; font-weight: bold;">🏠 Alquiler</span>';
        } else {
            echo 'Venta';
        }
    }
    
    if ($column === 'fechas_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            $fecha_inicio = get_post_meta($post_id, '_fecha_inicio_alquiler', true);
            $fecha_fin = get_post_meta($post_id, '_fecha_fin_alquiler', true);
            
            if ($fecha_inicio && $fecha_fin) {
                echo date('d/m/Y', strtotime($fecha_inicio)) . '<br>a<br>' . date('d/m/Y', strtotime($fecha_fin));
            }
        } else {
            echo '-';
        }
    }
}, 10, 2);
?> + variation.garantia : '') +
                                             (variation.periodo_dias ? ' - Período: ' + variation.periodo_dias + ' días' : '');
                            
                            $variacionSelect.append('<option value="' + variation.id + '" ' +
                                'data-precio="' + variation.price + '" ' +
                                'data-garantia="' + variation.garantia + '" ' +
                                'data-dias-gracia="' + variation.dias_gracia + '" ' +
                                'data-periodo="' + (variation.periodo_dias || '') + '" ' +
                                'data-stock="' + (variation.stock_quantity || '') + '" ' +
                                'data-managing-stock="' + (variation.managing_stock ? '1' : '0') + '">' + 
                                optionText + '</option>');
                        });
                        
                        if (response.data.length > 1) {
                            $variacionSelect.show();
                        } else if (response.data.length === 1) {
                            $variacionSelect.val(response.data[0].id).hide();
                            verificarDisponibilidad($this.closest('.producto-item'));
                        }
                        
                        calcularTotales();
                    }
                });
            } else {
                $variacionSelect.hide().empty();
                calcularTotales();
            }
        });
        
        // Manejar cambio de variación
        $(document).on('change', '.variacion-select', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de cantidad
        $(document).on('change', '.cantidad-input', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de fechas
        $('#fecha_inicio, #fecha_fin').change(function() {
            $('.producto-item').each(function() {
                verificarDisponibilidad($(this));
            });
        });
        
        // Función para verificar disponibilidad
        function verificarDisponibilidad($productoItem) {
            const productId = $productoItem.find('.producto-select').val();
            const variationId = $productoItem.find('.variacion-select').val();
            const cantidad = $productoItem.find('.cantidad-input').val();
            const fechaInicio = $('#fecha_inicio').val();
            const fechaFin = $('#fecha_fin').val();
            const $disponibilidadInfo = $productoItem.find('.disponibilidad-info');
            
            if (!productId || !fechaInicio || !fechaFin) {
                $disponibilidadInfo.hide();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'check_product_availability',
                product_id: productId,
                variation_id: variationId || productId,
                cantidad: cantidad,
                fecha_inicio: fechaInicio,
                fecha_fin: fechaFin,
                nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    const $texto = $disponibilidadInfo.find('.disponibilidad-texto');
                    
                    if (response.data.disponible) {
                        $disponibilidadInfo.removeClass('error').addClass('success')
                            .css('background-color', '#d4edda').css('color', '#155724');
                        $texto.html('✅ Producto disponible para las fechas seleccionadas');
                    } else {
                        $disponibilidadInfo.removeClass('success').addClass('error')
                            .css('background-color', '#f8d7da').css('color', '#721c24');
                        $texto.html('❌ Producto NO disponible para las fechas seleccionadas');
                    }
                    
                    $disponibilidadInfo.show();
                }
            });
        }
        
        // Calcular totales
        function calcularTotales() {
            let garantiaTotal = 0;
            
            $('.producto-item').each(function() {
                const $variacionSelect = $(this).find('.variacion-select');
                const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                let garantia = 0;
                
                if ($variacionSelect.is(':visible') && $variacionSelect.val()) {
                    garantia = parseFloat($variacionSelect.find('option:selected').data('garantia')) || 0;
                } else if (!$variacionSelect.is(':visible') && $(this).find('.producto-select').val()) {
                    // Para productos simples, obtener la garantía del producto
                    const $productoSelect = $(this).find('.producto-select');
                    // Esta información debería venir del AJAX de variaciones
                }
                
                garantiaTotal += garantia * cantidad;
            });
            
            $('#garantia_total').val(garantiaTotal.toFixed(2));
        }
        
        // Validar fechas
        $('#fecha_fin').change(function() {
            const fechaInicio = new Date($('#fecha_inicio').val());
            const fechaFin = new Date($(this).val());
            
            if (fechaFin <= fechaInicio) {
                alert('La fecha de fin debe ser posterior a la fecha de inicio');
                $(this).val('');
            }
        });
        
        // Validar formulario antes del envío
        $('#formulario-alquiler-manual').submit(function(e) {
            const clienteExistente = $('#cliente_existente').val();
            const nuevoClienteNombre = $('#nuevo_cliente_nombre').val();
            const nuevoClienteEmail = $('#nuevo_cliente_email').val();
            
            if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                e.preventDefault();
                alert('Debe seleccionar un cliente existente o proporcionar los datos de un nuevo cliente');
                return false;
            }
            
            // Validar que no haya productos no disponibles
            let hayProductosNoDisponibles = false;
            $('.disponibilidad-info.error:visible').each(function() {
                hayProductosNoDisponibles = true;
            });
            
            if (hayProductosNoDisponibles) {
                e.preventDefault();
                alert('Hay productos no disponibles para las fechas seleccionadas. Por favor revise la disponibilidad.');
                return false;
            }
        });
    });
    </script>

    <style>
    .producto-item {
        background: #fafafa;
    }
    .producto-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .producto-row select, .producto-row input {
        margin-right: 10px;
    }
    .form-table th {
        width: 200px;
    }
    h2 {
        color: #1d2327;
        margin: 20px 0 10px 0;
    }
    .disponibilidad-info {
        border: 1px solid;
        border-radius: 4px;
        font-weight: bold;
    }
    .disponibilidad-info.success {
        border-color: #c3e6cb;
    }
    .disponibilidad-info.error {
        border-color: #f5c6cb;
    }
    </style>
    <?php
}

// Función para obtener productos de alquiler
function get_productos_alquiler() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
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
        $productos_wc[] = wc_get_product($producto->ID);
    }
    
    return $productos_wc;
}

// Función para verificar disponibilidad de producto
function verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
    // Convertir fechas
    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);
    
    // Obtener días de gracia del producto (puede estar en variación o producto padre)
    $dias_gracia = 0;
    if ($variation_id && $variation_id != $product_id) {
        $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
        if (!$dias_gracia) {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
    } else {
        $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
    }
    $dias_gracia = $dias_gracia ?: 0;
    
    // Verificar si la variación tiene un período específico configurado
    $producto_actual = wc_get_product($variation_id ?: $product_id);
    $periodo_variacion = null;
    
    if ($producto_actual && $producto_actual->is_type('variation')) {
        // Buscar en los atributos de la variación si hay información de período
        $attributes = $producto_actual->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_value) {
            // Si encuentra algo como "4-dias", "8-dias", etc. en los atributos
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_variacion = intval($matches[1]);
                break;
            }
        }
        
        // También verificar en el nombre/título de la variación
        if (!$periodo_variacion) {
            $nombre_variacion = $producto_actual->get_name();
            if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                $periodo_variacion = intval($matches[1]);
            }
        }
    }
    
    // Buscar órdenes existentes que contengan este producto en el rango de fechas
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
        'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
    );
    
    $ordenes_alquiler = get_posts($args);
    
    foreach ($ordenes_alquiler as $orden_post) {
        $orden = wc_get_order($orden_post->ID);
        
        $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
        $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
        
        if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
        
        $inicio_orden = new DateTime($fecha_inicio_orden);
        $fin_orden = new DateTime($fecha_fin_orden);
        
        // Agregar días de gracia al final del alquiler existente
        if ($dias_gracia > 0) {
            $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
        }
        
        // Verificar si hay solapamiento de fechas
        if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
            // Hay solapamiento, verificar si es el mismo producto/variación
            foreach ($orden->get_items() as $item) {
                $producto_orden = $item->get_product();
                
                if (!$producto_orden) continue;
                
                // Verificar si es el mismo producto/variación específica
                $mismo_producto = false;
                
                if ($variation_id && $variation_id != $product_id) {
                    // Comparar variación específica
                    if ($producto_orden->get_id() == $variation_id) {
                        $mismo_producto = true;
                    }
                } else {
                    // Producto simple - comparar ID del producto padre
                    $producto_id_orden = $producto_orden->is_type('variation') ? 
                        $producto_orden->get_parent_id() : $producto_orden->get_id();
                    if ($producto_id_orden == $product_id) {
                        $mismo_producto = true;
                    }
                }
                
                if ($mismo_producto) {
                    // Verificar stock/cantidad disponible
                    $cantidad_orden = $item->get_quantity();
                    
                    // Si se maneja stock, verificar disponibilidad
                    if ($producto_actual && $producto_actual->managing_stock()) {
                        $stock_disponible = $producto_actual->get_stock_quantity();
                        if ($stock_disponible < $cantidad_solicitada) {
                            return false;
                        }
                    } else {
                        // Si no se maneja stock, asumir que solo hay 1 disponible por variación
                        return false;
                    }
                }
            }
        }
    }
    
    return true; // Disponible
}

// Función para procesar el formulario (actualizada para manejar variaciones)
function procesar_alquiler_manual($datos) {
    try {
        // Validar datos básicos
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            return array('success' => false, 'message' => 'Las fechas son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validar disponibilidad de todos los productos antes de crear la orden
        foreach ($datos['productos'] as $producto_data) {
            if (empty($producto_data['id'])) continue;
            
            $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : $producto_data['id'];
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            $disponible = verificar_disponibilidad_producto(
                $producto_data['id'], 
                $variation_id, 
                $datos['fecha_inicio'], 
                $datos['fecha_fin'], 
                $cantidad
            );
            
            if (!$disponible) {
                $producto = wc_get_product($variation_id);
                return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible para las fechas seleccionadas');
            }
        }
        
        // Obtener o crear cliente
        $cliente_id = obtener_o_crear_cliente($datos);
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
            
            // Obtener el producto (variación o simple)
            if ($variation_id && $variation_id != $product_id) {
                $producto = wc_get_product($variation_id);
                $producto_padre = wc_get_product($product_id);
            } else {
                $producto = wc_get_product($product_id);
                $producto_padre = null;
            }
            
            if (!$producto) continue;
            
            // Agregar producto a la orden
            $item = $order->add_product($producto, $cantidad);
            
            // Calcular garantía (primero buscar en la variación, luego en el producto padre)
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
                $total_antes_descuento = $order->get_total();
                $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                
                $coupon = new WC_Coupon();
                $coupon->set_code('DESCUENTO_MANUAL_' . time());
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($descuento_cantidad);
                $coupon->save();
                
                $order->apply_coupon($coupon->get_code());
            }
        }
        
        // Agregar metadatos de alquiler
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
        
        // Calcular totales y guardar
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

// Función para obtener o crear cliente (sin cambios)
function obtener_o_crear_cliente($datos) {
    // Si se seleccionó un cliente existente
    if (!empty($datos['cliente_id'])) {
        return intval($datos['cliente_id']);
    }
    
    // Si se proporcionaron datos para nuevo cliente
    if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
        $email = sanitize_email($datos['nuevo_cliente_email']);
        $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
        $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
        
        // Verificar si ya existe un usuario con ese email
        $usuario_existente = get_user_by('email', $email);
        if ($usuario_existente) {
            return $usuario_existente->ID;
        }
        
        // Crear nuevo usuario
        $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            // Asignar rol de customer
            $user = new WP_User($user_id);
            $user->set_role('customer');
            
            // Agregar metadatos
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

// Agregar columnas personalizadas en la lista de órdenes (sin cambios)
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['es_alquiler'] = 'Tipo';
            $new_columns['fechas_alquiler'] = 'Fechas Alquiler';
        }
    }
    
    return $new_columns;
});

// Mostrar contenido de las columnas personalizadas (sin cambios)
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'es_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            echo '<span style="color: #2271b1; font-weight: bold;">🏠 Alquiler</span>';
        } else {
            echo 'Venta';
        }
    }
    
    if ($column === 'fechas_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            $fecha_inicio = get_post_meta($post_id, '_fecha_inicio_alquiler', true);
            $fecha_fin = get_post_meta($post_id, '_fecha_fin_alquiler', true);
            
            if ($fecha_inicio && $fecha_fin) {
                echo date('d/m/Y', strtotime($fecha_inicio)) . '<br>a<br>' . date('d/m/Y', strtotime($fecha_fin));
            }
        } else {
            echo '-';
        }
    }
}, 10, 2);
?> + garantia : 'No configurada');
                $infoVariacion.find('.periodo-valor').text(periodo ? periodo + ' días' : 'No especificado');
                
                if (managingStock) {
                    $infoVariacion.find('.stock-valor').text(stock ? stock + ' unidades' : 'Sin stock');
                } else {
                    $infoVariacion.find('.stock-valor').text('Stock no gestionado');
                }
                
                $infoVariacion.show();
            } else {
                $infoVariacion.hide();
            }
            
            verificarDisponibilidad($this.closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de cantidad
        $(document).on('change', '.cantidad-input', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de fechas
        $('#fecha_inicio, #fecha_fin').change(function() {
            $('.producto-item').each(function() {
                verificarDisponibilidad($(this));
            });
        });
        
        // Función para verificar disponibilidad
        function verificarDisponibilidad($productoItem) {
            const productId = $productoItem.find('.producto-select').val();
            const variationId = $productoItem.find('.variacion-select').val();
            const cantidad = $productoItem.find('.cantidad-input').val();
            const fechaInicio = $('#fecha_inicio').val();
            const fechaFin = $('#fecha_fin').val();
            const $disponibilidadInfo = $productoItem.find('.disponibilidad-info');
            
            if (!productId || !fechaInicio || !fechaFin) {
                $disponibilidadInfo.hide();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'check_product_availability',
                product_id: productId,
                variation_id: variationId || productId,
                cantidad: cantidad,
                fecha_inicio: fechaInicio,
                fecha_fin: fechaFin,
                nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    const $texto = $disponibilidadInfo.find('.disponibilidad-texto');
                    
                    if (response.data.disponible) {
                        $disponibilidadInfo.removeClass('error').addClass('success')
                            .css('background-color', '#d4edda').css('color', '#155724');
                        $texto.html('✅ Producto disponible para las fechas seleccionadas');
                    } else {
                        $disponibilidadInfo.removeClass('success').addClass('error')
                            .css('background-color', '#f8d7da').css('color', '#721c24');
                        $texto.html('❌ Producto NO disponible para las fechas seleccionadas');
                    }
                    
                    $disponibilidadInfo.show();
                }
            });
        }
        
        // Calcular totales
        function calcularTotales() {
            let garantiaTotal = 0;
            
            $('.producto-item').each(function() {
                const $productoSelect = $(this).find('.producto-select');
                const $variacionSelect = $(this).find('.variacion-select');
                const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                let garantia = 0;
                
                if ($variacionSelect.is(':visible') && $variacionSelect.val()) {
                    // Producto variable - obtener garantía de la variación seleccionada
                    garantia = parseFloat($variacionSelect.find('option:selected').data('garantia')) || 0;
                } else if ($productoSelect.val()) {
                    // Producto simple - obtener garantía del endpoint AJAX
                    // Esta información ya debería estar disponible desde la carga de variaciones
                    const productId = $productoSelect.val();
                    
                    // Si hay solo una opción en variaciones (producto simple), usar esa garantía
                    if ($variacionSelect.find('option').length === 2) { // "Seleccionar..." + 1 opción
                        const $option = $variacionSelect.find('option:last');
                        if ($option.length) {
                            garantia = parseFloat($option.data('garantia')) || 0;
                        }
                    }
                }
                
                garantiaTotal += garantia * cantidad;
            });
            
            $('#garantia_total').val(garantiaTotal.toFixed(2));
        }
        
        // Validar fechas
        $('#fecha_fin').change(function() {
            const fechaInicio = new Date($('#fecha_inicio').val());
            const fechaFin = new Date($(this).val());
            
            if (fechaFin <= fechaInicio) {
                alert('La fecha de fin debe ser posterior a la fecha de inicio');
                $(this).val('');
            }
        });
        
        // Validar formulario antes del envío
        $('#formulario-alquiler-manual').submit(function(e) {
            const clienteExistente = $('#cliente_existente').val();
            const nuevoClienteNombre = $('#nuevo_cliente_nombre').val();
            const nuevoClienteEmail = $('#nuevo_cliente_email').val();
            
            if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                e.preventDefault();
                alert('Debe seleccionar un cliente existente o proporcionar los datos de un nuevo cliente');
                return false;
            }
            
            // Validar que no haya productos no disponibles
            let hayProductosNoDisponibles = false;
            $('.disponibilidad-info.error:visible').each(function() {
                hayProductosNoDisponibles = true;
            });
            
            if (hayProductosNoDisponibles) {
                e.preventDefault();
                alert('Hay productos no disponibles para las fechas seleccionadas. Por favor revise la disponibilidad.');
                return false;
            }
        });
    });
    </script>

    <style>
    .producto-item {
        background: #fafafa;
    }
    .producto-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .producto-row select, .producto-row input {
        margin-right: 10px;
    }
    .form-table th {
        width: 200px;
    }
    h2 {
        color: #1d2327;
        margin: 20px 0 10px 0;
    }
    .disponibilidad-info {
        border: 1px solid;
        border-radius: 4px;
        font-weight: bold;
    }
    .disponibilidad-info.success {
        border-color: #c3e6cb;
    }
    .disponibilidad-info.error {
        border-color: #f5c6cb;
    }
    </style>
    <?php
}

// Función para obtener productos de alquiler
function get_productos_alquiler() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
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
        $productos_wc[] = wc_get_product($producto->ID);
    }
    
    return $productos_wc;
}

// Función para verificar disponibilidad de producto
function verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
    // Convertir fechas
    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);
    
    // Obtener días de gracia del producto (puede estar en variación o producto padre)
    $dias_gracia = 0;
    if ($variation_id && $variation_id != $product_id) {
        $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
        if (!$dias_gracia) {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
    } else {
        $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
    }
    $dias_gracia = $dias_gracia ?: 0;
    
    // Verificar si la variación tiene un período específico configurado
    $producto_actual = wc_get_product($variation_id ?: $product_id);
    $periodo_variacion = null;
    
    if ($producto_actual && $producto_actual->is_type('variation')) {
        // Buscar en los atributos de la variación si hay información de período
        $attributes = $producto_actual->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_value) {
            // Si encuentra algo como "4-dias", "8-dias", etc. en los atributos
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_variacion = intval($matches[1]);
                break;
            }
        }
        
        // También verificar en el nombre/título de la variación
        if (!$periodo_variacion) {
            $nombre_variacion = $producto_actual->get_name();
            if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                $periodo_variacion = intval($matches[1]);
            }
        }
    }
    
    // Buscar órdenes existentes que contengan este producto en el rango de fechas
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
        'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
    );
    
    $ordenes_alquiler = get_posts($args);
    
    foreach ($ordenes_alquiler as $orden_post) {
        $orden = wc_get_order($orden_post->ID);
        
        $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
        $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
        
        if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
        
        $inicio_orden = new DateTime($fecha_inicio_orden);
        $fin_orden = new DateTime($fecha_fin_orden);
        
        // Agregar días de gracia al final del alquiler existente
        if ($dias_gracia > 0) {
            $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
        }
        
        // Verificar si hay solapamiento de fechas
        if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
            // Hay solapamiento, verificar si es el mismo producto/variación
            foreach ($orden->get_items() as $item) {
                $producto_orden = $item->get_product();
                
                if (!$producto_orden) continue;
                
                // Verificar si es el mismo producto/variación específica
                $mismo_producto = false;
                
                if ($variation_id && $variation_id != $product_id) {
                    // Comparar variación específica
                    if ($producto_orden->get_id() == $variation_id) {
                        $mismo_producto = true;
                    }
                } else {
                    // Producto simple - comparar ID del producto padre
                    $producto_id_orden = $producto_orden->is_type('variation') ? 
                        $producto_orden->get_parent_id() : $producto_orden->get_id();
                    if ($producto_id_orden == $product_id) {
                        $mismo_producto = true;
                    }
                }
                
                if ($mismo_producto) {
                    // Verificar stock/cantidad disponible
                    $cantidad_orden = $item->get_quantity();
                    
                    // Si se maneja stock, verificar disponibilidad
                    if ($producto_actual && $producto_actual->managing_stock()) {
                        $stock_disponible = $producto_actual->get_stock_quantity();
                        if ($stock_disponible < $cantidad_solicitada) {
                            return false;
                        }
                    } else {
                        // Si no se maneja stock, asumir que solo hay 1 disponible por variación
                        return false;
                    }
                }
            }
        }
    }
    
    return true; // Disponible
}

// Función para procesar el formulario (actualizada para manejar variaciones)
function procesar_alquiler_manual($datos) {
    try {
        // Validar datos básicos
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            return array('success' => false, 'message' => 'Las fechas son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validar disponibilidad de todos los productos antes de crear la orden
        foreach ($datos['productos'] as $producto_data) {
            if (empty($producto_data['id'])) continue;
            
            $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : $producto_data['id'];
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            $disponible = verificar_disponibilidad_producto(
                $producto_data['id'], 
                $variation_id, 
                $datos['fecha_inicio'], 
                $datos['fecha_fin'], 
                $cantidad
            );
            
            if (!$disponible) {
                $producto = wc_get_product($variation_id);
                return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible para las fechas seleccionadas');
            }
        }
        
        // Obtener o crear cliente
        $cliente_id = obtener_o_crear_cliente($datos);
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
            
            // Obtener el producto (variación o simple)
            if ($variation_id && $variation_id != $product_id) {
                $producto = wc_get_product($variation_id);
                $producto_padre = wc_get_product($product_id);
            } else {
                $producto = wc_get_product($product_id);
                $producto_padre = null;
            }
            
            if (!$producto) continue;
            
            // Agregar producto a la orden
            $item = $order->add_product($producto, $cantidad);
            
            // Calcular garantía (primero buscar en la variación, luego en el producto padre)
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
                $total_antes_descuento = $order->get_total();
                $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                
                $coupon = new WC_Coupon();
                $coupon->set_code('DESCUENTO_MANUAL_' . time());
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($descuento_cantidad);
                $coupon->save();
                
                $order->apply_coupon($coupon->get_code());
            }
        }
        
        // Agregar metadatos de alquiler
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
        
        // Calcular totales y guardar
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

// Función para obtener o crear cliente (sin cambios)
function obtener_o_crear_cliente($datos) {
    // Si se seleccionó un cliente existente
    if (!empty($datos['cliente_id'])) {
        return intval($datos['cliente_id']);
    }
    
    // Si se proporcionaron datos para nuevo cliente
    if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
        $email = sanitize_email($datos['nuevo_cliente_email']);
        $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
        $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
        
        // Verificar si ya existe un usuario con ese email
        $usuario_existente = get_user_by('email', $email);
        if ($usuario_existente) {
            return $usuario_existente->ID;
        }
        
        // Crear nuevo usuario
        $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            // Asignar rol de customer
            $user = new WP_User($user_id);
            $user->set_role('customer');
            
            // Agregar metadatos
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

// Agregar columnas personalizadas en la lista de órdenes (sin cambios)
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['es_alquiler'] = 'Tipo';
            $new_columns['fechas_alquiler'] = 'Fechas Alquiler';
        }
    }
    
    return $new_columns;
});

// Mostrar contenido de las columnas personalizadas (sin cambios)
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'es_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            echo '<span style="color: #2271b1; font-weight: bold;">🏠 Alquiler</span>';
        } else {
            echo 'Venta';
        }
    }
    
    if ($column === 'fechas_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            $fecha_inicio = get_post_meta($post_id, '_fecha_inicio_alquiler', true);
            $fecha_fin = get_post_meta($post_id, '_fecha_fin_alquiler', true);
            
            if ($fecha_inicio && $fecha_fin) {
                echo date('d/m/Y', strtotime($fecha_inicio)) . '<br>a<br>' . date('d/m/Y', strtotime($fecha_fin));
            }
        } else {
            echo '-';
        }
    }
}, 10, 2);
?> + variation.price + 
                                             (variation.garantia > 0 ? ' - Garantía: 
                        
                        if (response.data.length > 1) {
                            $variacionSelect.show();
                        } else if (response.data.length === 1) {
                            $variacionSelect.val(response.data[0].id).hide();
                            verificarDisponibilidad($this.closest('.producto-item'));
                        }
                        
                        calcularTotales();
                    }
                });
            } else {
                $variacionSelect.hide().empty();
                calcularTotales();
            }
        });
        
        // Manejar cambio de variación
        $(document).on('change', '.variacion-select', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de cantidad
        $(document).on('change', '.cantidad-input', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de fechas
        $('#fecha_inicio, #fecha_fin').change(function() {
            $('.producto-item').each(function() {
                verificarDisponibilidad($(this));
            });
        });
        
        // Función para verificar disponibilidad
        function verificarDisponibilidad($productoItem) {
            const productId = $productoItem.find('.producto-select').val();
            const variationId = $productoItem.find('.variacion-select').val();
            const cantidad = $productoItem.find('.cantidad-input').val();
            const fechaInicio = $('#fecha_inicio').val();
            const fechaFin = $('#fecha_fin').val();
            const $disponibilidadInfo = $productoItem.find('.disponibilidad-info');
            
            if (!productId || !fechaInicio || !fechaFin) {
                $disponibilidadInfo.hide();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'check_product_availability',
                product_id: productId,
                variation_id: variationId || productId,
                cantidad: cantidad,
                fecha_inicio: fechaInicio,
                fecha_fin: fechaFin,
                nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    const $texto = $disponibilidadInfo.find('.disponibilidad-texto');
                    
                    if (response.data.disponible) {
                        $disponibilidadInfo.removeClass('error').addClass('success')
                            .css('background-color', '#d4edda').css('color', '#155724');
                        $texto.html('✅ Producto disponible para las fechas seleccionadas');
                    } else {
                        $disponibilidadInfo.removeClass('success').addClass('error')
                            .css('background-color', '#f8d7da').css('color', '#721c24');
                        $texto.html('❌ Producto NO disponible para las fechas seleccionadas');
                    }
                    
                    $disponibilidadInfo.show();
                }
            });
        }
        
        // Calcular totales
        function calcularTotales() {
            let garantiaTotal = 0;
            
            $('.producto-item').each(function() {
                const $variacionSelect = $(this).find('.variacion-select');
                const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                let garantia = 0;
                
                if ($variacionSelect.is(':visible') && $variacionSelect.val()) {
                    garantia = parseFloat($variacionSelect.find('option:selected').data('garantia')) || 0;
                } else if (!$variacionSelect.is(':visible') && $(this).find('.producto-select').val()) {
                    // Para productos simples, obtener la garantía del producto
                    const $productoSelect = $(this).find('.producto-select');
                    // Esta información debería venir del AJAX de variaciones
                }
                
                garantiaTotal += garantia * cantidad;
            });
            
            $('#garantia_total').val(garantiaTotal.toFixed(2));
        }
        
        // Validar fechas
        $('#fecha_fin').change(function() {
            const fechaInicio = new Date($('#fecha_inicio').val());
            const fechaFin = new Date($(this).val());
            
            if (fechaFin <= fechaInicio) {
                alert('La fecha de fin debe ser posterior a la fecha de inicio');
                $(this).val('');
            }
        });
        
        // Validar formulario antes del envío
        $('#formulario-alquiler-manual').submit(function(e) {
            const clienteExistente = $('#cliente_existente').val();
            const nuevoClienteNombre = $('#nuevo_cliente_nombre').val();
            const nuevoClienteEmail = $('#nuevo_cliente_email').val();
            
            if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                e.preventDefault();
                alert('Debe seleccionar un cliente existente o proporcionar los datos de un nuevo cliente');
                return false;
            }
            
            // Validar que no haya productos no disponibles
            let hayProductosNoDisponibles = false;
            $('.disponibilidad-info.error:visible').each(function() {
                hayProductosNoDisponibles = true;
            });
            
            if (hayProductosNoDisponibles) {
                e.preventDefault();
                alert('Hay productos no disponibles para las fechas seleccionadas. Por favor revise la disponibilidad.');
                return false;
            }
        });
    });
    </script>

    <style>
    .producto-item {
        background: #fafafa;
    }
    .producto-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .producto-row select, .producto-row input {
        margin-right: 10px;
    }
    .form-table th {
        width: 200px;
    }
    h2 {
        color: #1d2327;
        margin: 20px 0 10px 0;
    }
    .disponibilidad-info {
        border: 1px solid;
        border-radius: 4px;
        font-weight: bold;
    }
    .disponibilidad-info.success {
        border-color: #c3e6cb;
    }
    .disponibilidad-info.error {
        border-color: #f5c6cb;
    }
    </style>
    <?php
}

// Función para obtener productos de alquiler
function get_productos_alquiler() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
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
        $productos_wc[] = wc_get_product($producto->ID);
    }
    
    return $productos_wc;
}

// Función para verificar disponibilidad de producto
function verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
    // Convertir fechas
    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);
    
    // Obtener días de gracia del producto (puede estar en variación o producto padre)
    $dias_gracia = 0;
    if ($variation_id && $variation_id != $product_id) {
        $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
        if (!$dias_gracia) {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
    } else {
        $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
    }
    $dias_gracia = $dias_gracia ?: 0;
    
    // Verificar si la variación tiene un período específico configurado
    $producto_actual = wc_get_product($variation_id ?: $product_id);
    $periodo_variacion = null;
    
    if ($producto_actual && $producto_actual->is_type('variation')) {
        // Buscar en los atributos de la variación si hay información de período
        $attributes = $producto_actual->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_value) {
            // Si encuentra algo como "4-dias", "8-dias", etc. en los atributos
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_variacion = intval($matches[1]);
                break;
            }
        }
        
        // También verificar en el nombre/título de la variación
        if (!$periodo_variacion) {
            $nombre_variacion = $producto_actual->get_name();
            if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                $periodo_variacion = intval($matches[1]);
            }
        }
    }
    
    // Buscar órdenes existentes que contengan este producto en el rango de fechas
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
        'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
    );
    
    $ordenes_alquiler = get_posts($args);
    
    foreach ($ordenes_alquiler as $orden_post) {
        $orden = wc_get_order($orden_post->ID);
        
        $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
        $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
        
        if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
        
        $inicio_orden = new DateTime($fecha_inicio_orden);
        $fin_orden = new DateTime($fecha_fin_orden);
        
        // Agregar días de gracia al final del alquiler existente
        if ($dias_gracia > 0) {
            $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
        }
        
        // Verificar si hay solapamiento de fechas
        if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
            // Hay solapamiento, verificar si es el mismo producto/variación
            foreach ($orden->get_items() as $item) {
                $producto_orden = $item->get_product();
                
                if (!$producto_orden) continue;
                
                // Verificar si es el mismo producto/variación específica
                $mismo_producto = false;
                
                if ($variation_id && $variation_id != $product_id) {
                    // Comparar variación específica
                    if ($producto_orden->get_id() == $variation_id) {
                        $mismo_producto = true;
                    }
                } else {
                    // Producto simple - comparar ID del producto padre
                    $producto_id_orden = $producto_orden->is_type('variation') ? 
                        $producto_orden->get_parent_id() : $producto_orden->get_id();
                    if ($producto_id_orden == $product_id) {
                        $mismo_producto = true;
                    }
                }
                
                if ($mismo_producto) {
                    // Verificar stock/cantidad disponible
                    $cantidad_orden = $item->get_quantity();
                    
                    // Si se maneja stock, verificar disponibilidad
                    if ($producto_actual && $producto_actual->managing_stock()) {
                        $stock_disponible = $producto_actual->get_stock_quantity();
                        if ($stock_disponible < $cantidad_solicitada) {
                            return false;
                        }
                    } else {
                        // Si no se maneja stock, asumir que solo hay 1 disponible por variación
                        return false;
                    }
                }
            }
        }
    }
    
    return true; // Disponible
}

// Función para procesar el formulario (actualizada para manejar variaciones)
function procesar_alquiler_manual($datos) {
    try {
        // Validar datos básicos
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            return array('success' => false, 'message' => 'Las fechas son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validar disponibilidad de todos los productos antes de crear la orden
        foreach ($datos['productos'] as $producto_data) {
            if (empty($producto_data['id'])) continue;
            
            $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : $producto_data['id'];
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            $disponible = verificar_disponibilidad_producto(
                $producto_data['id'], 
                $variation_id, 
                $datos['fecha_inicio'], 
                $datos['fecha_fin'], 
                $cantidad
            );
            
            if (!$disponible) {
                $producto = wc_get_product($variation_id);
                return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible para las fechas seleccionadas');
            }
        }
        
        // Obtener o crear cliente
        $cliente_id = obtener_o_crear_cliente($datos);
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
            
            // Obtener el producto (variación o simple)
            if ($variation_id && $variation_id != $product_id) {
                $producto = wc_get_product($variation_id);
                $producto_padre = wc_get_product($product_id);
            } else {
                $producto = wc_get_product($product_id);
                $producto_padre = null;
            }
            
            if (!$producto) continue;
            
            // Agregar producto a la orden
            $item = $order->add_product($producto, $cantidad);
            
            // Calcular garantía (primero buscar en la variación, luego en el producto padre)
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
                $total_antes_descuento = $order->get_total();
                $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                
                $coupon = new WC_Coupon();
                $coupon->set_code('DESCUENTO_MANUAL_' . time());
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($descuento_cantidad);
                $coupon->save();
                
                $order->apply_coupon($coupon->get_code());
            }
        }
        
        // Agregar metadatos de alquiler
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
        
        // Calcular totales y guardar
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

// Función para obtener o crear cliente (sin cambios)
function obtener_o_crear_cliente($datos) {
    // Si se seleccionó un cliente existente
    if (!empty($datos['cliente_id'])) {
        return intval($datos['cliente_id']);
    }
    
    // Si se proporcionaron datos para nuevo cliente
    if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
        $email = sanitize_email($datos['nuevo_cliente_email']);
        $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
        $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
        
        // Verificar si ya existe un usuario con ese email
        $usuario_existente = get_user_by('email', $email);
        if ($usuario_existente) {
            return $usuario_existente->ID;
        }
        
        // Crear nuevo usuario
        $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            // Asignar rol de customer
            $user = new WP_User($user_id);
            $user->set_role('customer');
            
            // Agregar metadatos
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

// Agregar columnas personalizadas en la lista de órdenes (sin cambios)
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['es_alquiler'] = 'Tipo';
            $new_columns['fechas_alquiler'] = 'Fechas Alquiler';
        }
    }
    
    return $new_columns;
});

// Mostrar contenido de las columnas personalizadas (sin cambios)
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'es_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            echo '<span style="color: #2271b1; font-weight: bold;">🏠 Alquiler</span>';
        } else {
            echo 'Venta';
        }
    }
    
    if ($column === 'fechas_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            $fecha_inicio = get_post_meta($post_id, '_fecha_inicio_alquiler', true);
            $fecha_fin = get_post_meta($post_id, '_fecha_fin_alquiler', true);
            
            if ($fecha_inicio && $fecha_fin) {
                echo date('d/m/Y', strtotime($fecha_inicio)) . '<br>a<br>' . date('d/m/Y', strtotime($fecha_fin));
            }
        } else {
            echo '-';
        }
    }
}, 10, 2);
?> + variation.garantia : '') +
                                             (variation.periodo_dias ? ' - Período: ' + variation.periodo_dias + ' días' : '');
                            
                            $variacionSelect.append('<option value="' + variation.id + '" ' +
                                'data-precio="' + variation.price + '" ' +
                                'data-garantia="' + variation.garantia + '" ' +
                                'data-dias-gracia="' + variation.dias_gracia + '" ' +
                                'data-periodo="' + (variation.periodo_dias || '') + '" ' +
                                'data-stock="' + (variation.stock_quantity || '') + '" ' +
                                'data-managing-stock="' + (variation.managing_stock ? '1' : '0') + '">' + 
                                optionText + '</option>');
                        });
                        
                        if (response.data.length > 1) {
                            $variacionSelect.show();
                        } else if (response.data.length === 1) {
                            $variacionSelect.val(response.data[0].id).hide();
                            verificarDisponibilidad($this.closest('.producto-item'));
                        }
                        
                        calcularTotales();
                    }
                });
            } else {
                $variacionSelect.hide().empty();
                calcularTotales();
            }
        });
        
        // Manejar cambio de variación
        $(document).on('change', '.variacion-select', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de cantidad
        $(document).on('change', '.cantidad-input', function() {
            verificarDisponibilidad($(this).closest('.producto-item'));
            calcularTotales();
        });
        
        // Manejar cambio de fechas
        $('#fecha_inicio, #fecha_fin').change(function() {
            $('.producto-item').each(function() {
                verificarDisponibilidad($(this));
            });
        });
        
        // Función para verificar disponibilidad
        function verificarDisponibilidad($productoItem) {
            const productId = $productoItem.find('.producto-select').val();
            const variationId = $productoItem.find('.variacion-select').val();
            const cantidad = $productoItem.find('.cantidad-input').val();
            const fechaInicio = $('#fecha_inicio').val();
            const fechaFin = $('#fecha_fin').val();
            const $disponibilidadInfo = $productoItem.find('.disponibilidad-info');
            
            if (!productId || !fechaInicio || !fechaFin) {
                $disponibilidadInfo.hide();
                return;
            }
            
            $.post(ajaxurl, {
                action: 'check_product_availability',
                product_id: productId,
                variation_id: variationId || productId,
                cantidad: cantidad,
                fecha_inicio: fechaInicio,
                fecha_fin: fechaFin,
                nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    const $texto = $disponibilidadInfo.find('.disponibilidad-texto');
                    
                    if (response.data.disponible) {
                        $disponibilidadInfo.removeClass('error').addClass('success')
                            .css('background-color', '#d4edda').css('color', '#155724');
                        $texto.html('✅ Producto disponible para las fechas seleccionadas');
                    } else {
                        $disponibilidadInfo.removeClass('success').addClass('error')
                            .css('background-color', '#f8d7da').css('color', '#721c24');
                        $texto.html('❌ Producto NO disponible para las fechas seleccionadas');
                    }
                    
                    $disponibilidadInfo.show();
                }
            });
        }
        
        // Calcular totales
        function calcularTotales() {
            let garantiaTotal = 0;
            
            $('.producto-item').each(function() {
                const $variacionSelect = $(this).find('.variacion-select');
                const cantidad = parseInt($(this).find('.cantidad-input').val()) || 1;
                let garantia = 0;
                
                if ($variacionSelect.is(':visible') && $variacionSelect.val()) {
                    garantia = parseFloat($variacionSelect.find('option:selected').data('garantia')) || 0;
                } else if (!$variacionSelect.is(':visible') && $(this).find('.producto-select').val()) {
                    // Para productos simples, obtener la garantía del producto
                    const $productoSelect = $(this).find('.producto-select');
                    // Esta información debería venir del AJAX de variaciones
                }
                
                garantiaTotal += garantia * cantidad;
            });
            
            $('#garantia_total').val(garantiaTotal.toFixed(2));
        }
        
        // Validar fechas
        $('#fecha_fin').change(function() {
            const fechaInicio = new Date($('#fecha_inicio').val());
            const fechaFin = new Date($(this).val());
            
            if (fechaFin <= fechaInicio) {
                alert('La fecha de fin debe ser posterior a la fecha de inicio');
                $(this).val('');
            }
        });
        
        // Validar formulario antes del envío
        $('#formulario-alquiler-manual').submit(function(e) {
            const clienteExistente = $('#cliente_existente').val();
            const nuevoClienteNombre = $('#nuevo_cliente_nombre').val();
            const nuevoClienteEmail = $('#nuevo_cliente_email').val();
            
            if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                e.preventDefault();
                alert('Debe seleccionar un cliente existente o proporcionar los datos de un nuevo cliente');
                return false;
            }
            
            // Validar que no haya productos no disponibles
            let hayProductosNoDisponibles = false;
            $('.disponibilidad-info.error:visible').each(function() {
                hayProductosNoDisponibles = true;
            });
            
            if (hayProductosNoDisponibles) {
                e.preventDefault();
                alert('Hay productos no disponibles para las fechas seleccionadas. Por favor revise la disponibilidad.');
                return false;
            }
        });
    });
    </script>

    <style>
    .producto-item {
        background: #fafafa;
    }
    .producto-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .producto-row select, .producto-row input {
        margin-right: 10px;
    }
    .form-table th {
        width: 200px;
    }
    h2 {
        color: #1d2327;
        margin: 20px 0 10px 0;
    }
    .disponibilidad-info {
        border: 1px solid;
        border-radius: 4px;
        font-weight: bold;
    }
    .disponibilidad-info.success {
        border-color: #c3e6cb;
    }
    .disponibilidad-info.error {
        border-color: #f5c6cb;
    }
    </style>
    <?php
}

// Función para obtener productos de alquiler
function get_productos_alquiler() {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
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
        $productos_wc[] = wc_get_product($producto->ID);
    }
    
    return $productos_wc;
}

// Función para verificar disponibilidad de producto
function verificar_disponibilidad_producto($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
    // Convertir fechas
    $inicio = new DateTime($fecha_inicio);
    $fin = new DateTime($fecha_fin);
    
    // Obtener días de gracia del producto (puede estar en variación o producto padre)
    $dias_gracia = 0;
    if ($variation_id && $variation_id != $product_id) {
        $dias_gracia = get_post_meta($variation_id, '_dias_gracia', true);
        if (!$dias_gracia) {
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
        }
    } else {
        $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
    }
    $dias_gracia = $dias_gracia ?: 0;
    
    // Verificar si la variación tiene un período específico configurado
    $producto_actual = wc_get_product($variation_id ?: $product_id);
    $periodo_variacion = null;
    
    if ($producto_actual && $producto_actual->is_type('variation')) {
        // Buscar en los atributos de la variación si hay información de período
        $attributes = $producto_actual->get_variation_attributes();
        foreach ($attributes as $attr_name => $attr_value) {
            // Si encuentra algo como "4-dias", "8-dias", etc. en los atributos
            if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                $periodo_variacion = intval($matches[1]);
                break;
            }
        }
        
        // También verificar en el nombre/título de la variación
        if (!$periodo_variacion) {
            $nombre_variacion = $producto_actual->get_name();
            if (preg_match('/(\d+)\s*d[ií]as?/i', $nombre_variacion, $matches)) {
                $periodo_variacion = intval($matches[1]);
            }
        }
    }
    
    // Buscar órdenes existentes que contengan este producto en el rango de fechas
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
        'post_status' => array('wc-processing', 'wc-completed', 'wc-on-hold')
    );
    
    $ordenes_alquiler = get_posts($args);
    
    foreach ($ordenes_alquiler as $orden_post) {
        $orden = wc_get_order($orden_post->ID);
        
        $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
        $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
        
        if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
        
        $inicio_orden = new DateTime($fecha_inicio_orden);
        $fin_orden = new DateTime($fecha_fin_orden);
        
        // Agregar días de gracia al final del alquiler existente
        if ($dias_gracia > 0) {
            $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
        }
        
        // Verificar si hay solapamiento de fechas
        if ($inicio <= $fin_orden && $fin >= $inicio_orden) {
            // Hay solapamiento, verificar si es el mismo producto/variación
            foreach ($orden->get_items() as $item) {
                $producto_orden = $item->get_product();
                
                if (!$producto_orden) continue;
                
                // Verificar si es el mismo producto/variación específica
                $mismo_producto = false;
                
                if ($variation_id && $variation_id != $product_id) {
                    // Comparar variación específica
                    if ($producto_orden->get_id() == $variation_id) {
                        $mismo_producto = true;
                    }
                } else {
                    // Producto simple - comparar ID del producto padre
                    $producto_id_orden = $producto_orden->is_type('variation') ? 
                        $producto_orden->get_parent_id() : $producto_orden->get_id();
                    if ($producto_id_orden == $product_id) {
                        $mismo_producto = true;
                    }
                }
                
                if ($mismo_producto) {
                    // Verificar stock/cantidad disponible
                    $cantidad_orden = $item->get_quantity();
                    
                    // Si se maneja stock, verificar disponibilidad
                    if ($producto_actual && $producto_actual->managing_stock()) {
                        $stock_disponible = $producto_actual->get_stock_quantity();
                        if ($stock_disponible < $cantidad_solicitada) {
                            return false;
                        }
                    } else {
                        // Si no se maneja stock, asumir que solo hay 1 disponible por variación
                        return false;
                    }
                }
            }
        }
    }
    
    return true; // Disponible
}

// Función para procesar el formulario (actualizada para manejar variaciones)
function procesar_alquiler_manual($datos) {
    try {
        // Validar datos básicos
        if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
            return array('success' => false, 'message' => 'Las fechas son obligatorias');
        }
        
        if (empty($datos['productos']) || !is_array($datos['productos'])) {
            return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
        }
        
        // Validar disponibilidad de todos los productos antes de crear la orden
        foreach ($datos['productos'] as $producto_data) {
            if (empty($producto_data['id'])) continue;
            
            $variation_id = !empty($producto_data['variation_id']) ? $producto_data['variation_id'] : $producto_data['id'];
            $cantidad = intval($producto_data['cantidad']) ?: 1;
            
            $disponible = verificar_disponibilidad_producto(
                $producto_data['id'], 
                $variation_id, 
                $datos['fecha_inicio'], 
                $datos['fecha_fin'], 
                $cantidad
            );
            
            if (!$disponible) {
                $producto = wc_get_product($variation_id);
                return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible para las fechas seleccionadas');
            }
        }
        
        // Obtener o crear cliente
        $cliente_id = obtener_o_crear_cliente($datos);
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
            
            // Obtener el producto (variación o simple)
            if ($variation_id && $variation_id != $product_id) {
                $producto = wc_get_product($variation_id);
                $producto_padre = wc_get_product($product_id);
            } else {
                $producto = wc_get_product($product_id);
                $producto_padre = null;
            }
            
            if (!$producto) continue;
            
            // Agregar producto a la orden
            $item = $order->add_product($producto, $cantidad);
            
            // Calcular garantía (primero buscar en la variación, luego en el producto padre)
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
                $total_antes_descuento = $order->get_total();
                $descuento_cantidad = $total_antes_descuento * ($descuento / 100);
                
                $coupon = new WC_Coupon();
                $coupon->set_code('DESCUENTO_MANUAL_' . time());
                $coupon->set_discount_type('fixed_cart');
                $coupon->set_amount($descuento_cantidad);
                $coupon->save();
                
                $order->apply_coupon($coupon->get_code());
            }
        }
        
        // Agregar metadatos de alquiler
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
        
        // Calcular totales y guardar
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

// Función para obtener o crear cliente (sin cambios)
function obtener_o_crear_cliente($datos) {
    // Si se seleccionó un cliente existente
    if (!empty($datos['cliente_id'])) {
        return intval($datos['cliente_id']);
    }
    
    // Si se proporcionaron datos para nuevo cliente
    if (!empty($datos['nuevo_cliente_nombre']) && !empty($datos['nuevo_cliente_email'])) {
        $email = sanitize_email($datos['nuevo_cliente_email']);
        $nombre = sanitize_text_field($datos['nuevo_cliente_nombre']);
        $telefono = sanitize_text_field($datos['nuevo_cliente_telefono']);
        
        // Verificar si ya existe un usuario con ese email
        $usuario_existente = get_user_by('email', $email);
        if ($usuario_existente) {
            return $usuario_existente->ID;
        }
        
        // Crear nuevo usuario
        $username = sanitize_user(strtolower(str_replace(' ', '', $nombre)) . '_' . time());
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (!is_wp_error($user_id)) {
            // Asignar rol de customer
            $user = new WP_User($user_id);
            $user->set_role('customer');
            
            // Agregar metadatos
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

// Agregar columnas personalizadas en la lista de órdenes (sin cambios)
add_filter('manage_edit-shop_order_columns', function($columns) {
    $new_columns = array();
    
    foreach ($columns as $key => $column) {
        $new_columns[$key] = $column;
        
        if ($key === 'order_status') {
            $new_columns['es_alquiler'] = 'Tipo';
            $new_columns['fechas_alquiler'] = 'Fechas Alquiler';
        }
    }
    
    return $new_columns;
});

// Mostrar contenido de las columnas personalizadas (sin cambios)
add_action('manage_shop_order_posts_custom_column', function($column, $post_id) {
    if ($column === 'es_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            echo '<span style="color: #2271b1; font-weight: bold;">🏠 Alquiler</span>';
        } else {
            echo 'Venta';
        }
    }
    
    if ($column === 'fechas_alquiler') {
        $es_alquiler = get_post_meta($post_id, '_es_alquiler', true);
        if ($es_alquiler === 'yes') {
            $fecha_inicio = get_post_meta($post_id, '_fecha_inicio_alquiler', true);
            $fecha_fin = get_post_meta($post_id, '_fecha_fin_alquiler', true);
            
            if ($fecha_inicio && $fecha_fin) {
                echo date('d/m/Y', strtotime($fecha_inicio)) . '<br>a<br>' . date('d/m/Y', strtotime($fecha_fin));
            }
        } else {
            echo '-';
        }
    }
}, 10, 2);
?>