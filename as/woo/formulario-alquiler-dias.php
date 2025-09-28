<?php
/**
 * Formulario de alquiler manual - OPTIMIZADO PARA VARIACIONES POR DÍAS
 * Maneja productos donde las variaciones representan períodos de alquiler
 */

if (!function_exists('gala_rental_dias_form_menu')) {
    
    // Agregar menú de administración
    add_action('admin_menu', 'gala_rental_dias_form_menu');
    function gala_rental_dias_form_menu() {
        add_submenu_page(
            'woocommerce',
            'Alquiler por Días',
            'Alquiler por Días',
            'manage_woocommerce',
            'alquiler-por-dias',
            'gala_rental_dias_form_page'
        );
    }

    // Endpoint AJAX para obtener variaciones por días
    add_action('wp_ajax_get_periodo_variations', 'ajax_get_periodo_variations');
    function ajax_get_periodo_variations() {
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
                $periodo_dias = null;
                $nombre_variacion = '';
                
                // Procesar atributos para encontrar los días
                foreach ($variation_data['attributes'] as $attr_name => $attr_value) {
                    $attr_label = wc_attribute_label(str_replace('attribute_', '', $attr_name));
                    
                    // Buscar días en el valor del atributo
                    if (preg_match('/(\d+)\s*d[ií]as?/i', $attr_value, $matches)) {
                        $periodo_dias = intval($matches[1]);
                        $nombre_variacion = $attr_value;
                    } else {
                        $attributes[] = array(
                            'label' => $attr_label,
                            'value' => $attr_value
                        );
                    }
                }
                
                // Si no encontramos días en atributos, buscar en el nombre de la variación
                if (!$periodo_dias) {
                    $full_name = $variation->get_name();
                    if (preg_match('/(\d+)\s*d[ií]as?/i', $full_name, $matches)) {
                        $periodo_dias = intval($matches[1]);
                        $nombre_variacion = $matches[0];
                    }
                }
                
                // Si aún no tenemos período, intentar extraer de otros atributos
                if (!$periodo_dias) {
                    foreach ($attributes as $attr) {
                        if (preg_match('/(\d+)/', $attr['value'], $matches)) {
                            $periodo_dias = intval($matches[1]);
                            $nombre_variacion = $attr['value'];
                            break;
                        }
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
                $display_name = $nombre_variacion ?: 'Variación ' . $variation_data['variation_id'];
                if ($periodo_dias) {
                    $display_name = $periodo_dias . ' días';
                }
                
                // Agregar otros atributos no relacionados con días
                $otros_attrs = array_filter($attributes, function($attr) {
                    return !preg_match('/d[ií]as?/i', $attr['value']);
                });
                
                if (!empty($otros_attrs)) {
                    $otros_nombres = array_column($otros_attrs, 'value');
                    $display_name .= ' - ' . implode(', ', $otros_nombres);
                }
                
                $variations[] = array(
                    'id' => $variation_data['variation_id'],
                    'display_name' => $display_name,
                    'periodo_dias' => $periodo_dias,
                    'price' => $variation->get_price(),
                    'price_formatted' => wc_price($variation->get_price()),
                    'garantia' => floatval($garantia ?: 0),
                    'dias_gracia' => intval($dias_gracia ?: 0),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'managing_stock' => $variation->managing_stock(),
                    'in_stock' => $variation->is_in_stock(),
                    'attributes' => $attributes,
                    'precio_por_dia' => $periodo_dias > 0 ? round($variation->get_price() / $periodo_dias, 2) : 0
                );
            }
            
            // Ordenar por período de días
            usort($variations, function($a, $b) {
                return ($a['periodo_dias'] ?: 999) - ($b['periodo_dias'] ?: 999);
            });
            
        } elseif ($product->is_type('simple')) {
            $garantia = get_post_meta($product_id, '_valor_garantia', true);
            $dias_gracia = get_post_meta($product_id, '_dias_gracia', true);
            
            $variations[] = array(
                'id' => $product_id,
                'display_name' => 'Producto simple',
                'periodo_dias' => null,
                'price' => $product->get_price(),
                'price_formatted' => wc_price($product->get_price()),
                'garantia' => floatval($garantia ?: 0),
                'dias_gracia' => intval($dias_gracia ?: 0),
                'stock_quantity' => $product->get_stock_quantity(),
                'managing_stock' => $product->managing_stock(),
                'in_stock' => $product->is_in_stock(),
                'attributes' => array(),
                'precio_por_dia' => 0
            );
        }
        
        wp_send_json_success($variations);
    }

    // Endpoint AJAX para calcular precio automático por fechas
    add_action('wp_ajax_calcular_precio_fechas', 'ajax_calcular_precio_fechas');
    function ajax_calcular_precio_fechas() {
        if (!wp_verify_nonce($_POST['nonce'], 'alquiler_manual_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }
        
        $fecha_inicio = sanitize_text_field($_POST['fecha_inicio']);
        $fecha_fin = sanitize_text_field($_POST['fecha_fin']);
        $variaciones = json_decode(stripslashes($_POST['variaciones']), true);
        
        if (!$fecha_inicio || !$fecha_fin || !$variaciones) {
            wp_send_json_error('Datos incompletos');
            return;
        }
        
        try {
            $inicio = new DateTime($fecha_inicio);
            $fin = new DateTime($fecha_fin);
            $dias_solicitados = $fin->diff($inicio)->days + 1; // +1 para incluir ambos días
            
            // Encontrar la variación más cercana
            $mejor_variacion = null;
            $menor_diferencia = PHP_INT_MAX;
            
            foreach ($variaciones as $variacion) {
                if (!isset($variacion['periodo_dias']) || !$variacion['periodo_dias']) {
                    continue;
                }
                
                $diferencia = abs($variacion['periodo_dias'] - $dias_solicitados);
                
                if ($diferencia < $menor_diferencia) {
                    $menor_diferencia = $diferencia;
                    $mejor_variacion = $variacion;
                }
            }
            
            $resultado = array(
                'dias_solicitados' => $dias_solicitados,
                'mejor_variacion' => $mejor_variacion,
                'recomendacion' => null
            );
            
            if ($mejor_variacion) {
                if ($mejor_variacion['periodo_dias'] == $dias_solicitados) {
                    $resultado['recomendacion'] = 'Coincidencia exacta: ' . $mejor_variacion['display_name'];
                } elseif ($mejor_variacion['periodo_dias'] > $dias_solicitados) {
                    $resultado['recomendacion'] = 'Recomendado: ' . $mejor_variacion['display_name'] . ' (cubre ' . $mejor_variacion['periodo_dias'] . ' días)';
                } else {
                    $resultado['recomendacion'] = 'Período más cercano: ' . $mejor_variacion['display_name'] . ' (solo ' . $mejor_variacion['periodo_dias'] . ' días)';
                }
            }
            
            wp_send_json_success($resultado);
            
        } catch (Exception $e) {
            wp_send_json_error('Error al calcular fechas: ' . $e->getMessage());
        }
    }

    // Endpoint para verificar disponibilidad
    add_action('wp_ajax_check_dias_availability', 'ajax_check_dias_availability');
    function ajax_check_dias_availability() {
        if (!wp_verify_nonce($_POST['nonce'], 'alquiler_manual_nonce')) {
            wp_send_json_error('Nonce inválido');
            return;
        }
        
        $product_id = intval($_POST['product_id']);
        $variation_id = intval($_POST['variation_id']);
        $fecha_inicio = sanitize_text_field($_POST['fecha_inicio']);
        $fecha_fin = sanitize_text_field($_POST['fecha_fin']);
        $cantidad = intval($_POST['cantidad']) ?: 1;
        
        $disponible = verificar_disponibilidad_dias($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad);
        
        wp_send_json_success($disponible);
    }

    // Función para verificar disponibilidad con días de gracia
    function verificar_disponibilidad_dias($product_id, $variation_id, $fecha_inicio, $fecha_fin, $cantidad_solicitada = 1) {
        try {
            $inicio = new DateTime($fecha_inicio);
            $fin = new DateTime($fecha_fin);
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
        
        // Verificar stock básico
        $producto_actual = wc_get_product($variation_id ?: $product_id);
        if ($producto_actual && $producto_actual->managing_stock()) {
            $stock_disponible = $producto_actual->get_stock_quantity();
            if ($stock_disponible < $cantidad_solicitada) {
                return array(
                    'disponible' => false,
                    'mensaje' => "Stock insuficiente. Disponible: {$stock_disponible}, Solicitado: {$cantidad_solicitada}"
                );
            }
        }
        
        // Buscar conflictos con alquileres existentes
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
        $conflictos = array();
        
        foreach ($ordenes_alquiler as $orden_post) {
            $fecha_inicio_orden = get_post_meta($orden_post->ID, '_fecha_inicio_alquiler', true);
            $fecha_fin_orden = get_post_meta($orden_post->ID, '_fecha_fin_alquiler', true);
            
            if (!$fecha_inicio_orden || !$fecha_fin_orden) continue;
            
            try {
                $inicio_orden = new DateTime($fecha_inicio_orden);
                $fin_orden = new DateTime($fecha_fin_orden);
                
                // Agregar días de gracia al final del alquiler existente
                if ($dias_gracia > 0) {
                    $fin_orden->add(new DateInterval('P' . $dias_gracia . 'D'));
                }
                
                // Verificar solapamiento
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
                            $conflictos[] = array(
                                'orden_id' => $orden->get_id(),
                                'fecha_inicio' => $fecha_inicio_orden,
                                'fecha_fin' => $fecha_fin_orden,
                                'cantidad' => $item->get_quantity(),
                                'cliente' => $orden->get_billing_first_name() . ' ' . $orden->get_billing_last_name()
                            );
                            
                            if (!$producto_actual->managing_stock()) {
                                return array(
                                    'disponible' => false,
                                    'mensaje' => 'Producto ocupado del ' . $fecha_inicio_orden . ' al ' . $fecha_fin_orden . 
                                               ($dias_gracia > 0 ? ' (+ ' . $dias_gracia . ' días de gracia)' : ''),
                                    'conflictos' => $conflictos
                                );
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        if (!empty($conflictos) && !$producto_actual->managing_stock()) {
            return array(
                'disponible' => false,
                'mensaje' => 'Conflicto con ' . count($conflictos) . ' alquiler(es) existente(s)',
                'conflictos' => $conflictos
            );
        }
        
        return array(
            'disponible' => true,
            'mensaje' => 'Producto disponible' . ($dias_gracia > 0 ? ' (con ' . $dias_gracia . ' días de gracia)' : ''),
            'conflictos' => array()
        );
    }

    // Página del formulario
    function gala_rental_dias_form_page() {
        // Procesar formulario
        if (isset($_POST['crear_alquiler_dias']) && wp_verify_nonce($_POST['alquiler_manual_nonce'], 'crear_alquiler_manual')) {
            $resultado = procesar_alquiler_dias($_POST);
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
            $productos_alquiler = get_productos_alquiler_dias();
        }
        ?>
        <div class="wrap">
            <h1>🏠 Alquiler por Días - Variaciones Inteligentes</h1>
            
            <form method="post" action="" id="formulario-alquiler-dias">
                <?php wp_nonce_field('crear_alquiler_manual', 'alquiler_manual_nonce'); ?>
                
                <!-- Cliente -->
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
                                        $clientes = get_users(array('role' => 'customer', 'number' => 100));
                                        foreach ($clientes as $cliente) {
                                            echo '<option value="' . esc_attr($cliente->ID) . '">' . 
                                                 esc_html($cliente->display_name) . ' (' . esc_html($cliente->user_email) . ')</option>';
                                        }
                                        ?>
                                    </select>
                                    <p class="description">O crear un nuevo cliente</p>
                                </td>
                            </tr>
                            <tr id="nuevo-cliente-fields" style="display: none;">
                                <th scope="row">Nuevo Cliente</th>
                                <td>
                                    <input type="text" name="nuevo_cliente_nombre" placeholder="Nombre completo" class="regular-text" />
                                    <input type="email" name="nuevo_cliente_email" placeholder="Email" class="regular-text" />
                                    <input type="tel" name="nuevo_cliente_telefono" placeholder="Teléfono" class="regular-text" />
                                </td>
                            </tr>
                        </table>
                        <button type="button" id="toggle-nuevo-cliente" class="button">➕ Crear Nuevo Cliente</button>
                    </div>
                </div>
                
                <!-- Fechas con calculadora inteligente -->
                <div class="postbox">
                    <h2 class="hndle">📅 Fechas del Alquiler</h2>
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
                                    <div id="calculo-dias" style="margin-top: 10px;"></div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Evento/Ocasión</th>
                                <td><input type="text" name="evento_ocasion" placeholder="Ej: Boda, Graduación..." class="large-text" /></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Productos con selector inteligente de períodos -->
                <div class="postbox">
                    <h2 class="hndle">👗 Productos y Períodos</h2>
                    <div class="inside">
                        <div id="productos-lista">
                            <div class="producto-row" data-index="0">
                                <div class="producto-card">
                                    <div class="producto-header">
                                        <h4>Producto #1</h4>
                                        <button type="button" class="button remove-producto" style="float: right; color: red;">❌ Eliminar</button>
                                    </div>
                                    
                                    <div class="producto-content">
                                        <div class="campo-row">
                                            <div class="campo-col">
                                                <label><strong>Producto:</strong></label>
                                                <select name="productos[0][id]" class="producto-select" style="width: 100%;" required>
                                                    <option value="">Seleccionar producto...</option>
                                                    <?php foreach ($productos_alquiler as $producto): ?>
                                                        <option value="<?php echo esc_attr($producto->ID); ?>"
                                                                data-name="<?php echo esc_attr($producto->get_name()); ?>">
                                                            <?php echo esc_html($producto->get_name()); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            
                                            <div class="campo-col">
                                                <label><strong>Cantidad:</strong></label>
                                                <input type="number" name="productos[0][cantidad]" class="cantidad-input" value="1" min="1" style="width: 80px;" />
                                            </div>
                                        </div>
                                        
                                        <div class="periodos-container" style="display: none;">
                                            <label><strong>Período de Alquiler:</strong></label>
                                            <div class="recomendacion-automatica"></div>
                                            <div class="periodos-grid"></div>
                                        </div>
                                        
                                        <div class="producto-info" style="display: none;"></div>
                                        <div class="disponibilidad-status" style="display: none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="agregar-producto" class="button button-secondary">➕ Agregar Otro Producto</button>
                    </div>
                </div>
                
                <!-- Resumen y configuración -->
                <div class="postbox">
                    <h2 class="hndle">💰 Resumen y Configuración</h2>
                    <div class="inside">
                        <div id="resumen-productos"></div>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">Descuento (%)</th>
                                <td>
                                    <input type="number" name="descuento_porcentaje" id="descuento_porcentaje" min="0" max="100" step="0.01" style="width: 100px;" />
                                    <span class="description">Descuento sobre el total</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Garantía Total</th>
                                <td>
                                    <input type="number" name="garantia_total" id="garantia_total" readonly style="width: 150px;" />
                                    <span class="description">Calculado automáticamente</span>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Estado del Alquiler</th>
                                <td>
                                    <select name="estado_alquiler">
                                        <option value="confirmed">✅ Confirmado</option>
                                        <option value="pending">⏳ Pendiente</option>
                                        <option value="in-progress">🔄 En Progreso</option>
                                        <option value="completed">✅ Completado</option>
                                        <option value="cancelled">❌ Cancelado</option>
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
                                <th scope="row">Notas Adicionales</th>
                                <td>
                                    <textarea name="notas_adicionales" rows="4" class="large-text" placeholder="Notas especiales del alquiler..."></textarea>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="crear_alquiler_dias" class="button-primary button-hero" value="🏠 Crear Alquiler por Días" />
                </p>
            </form>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            let contadorProductos = 1;
            let variacionesCache = {};
            
            // Toggle nuevo cliente
            $('#toggle-nuevo-cliente').click(function() {
                $('#nuevo-cliente-fields').toggle();
                $('#cliente_existente').val('');
            });
            
            // Cambio de cliente existente
            $('#cliente_existente').change(function() {
                if ($(this).val()) {
                    $('#nuevo-cliente-fields').hide();
                }
            });
            
            // Agregar producto
            $('#agregar-producto').click(function() {
                const nuevaFila = $('.producto-row:first').clone();
                nuevaFila.attr('data-index', contadorProductos);
                nuevaFila.find('.producto-header h4').text('Producto #' + (contadorProductos + 1));
                
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
                
                // Limpiar contenido
                nuevaFila.find('.periodos-container').hide();
                nuevaFila.find('.periodos-grid').empty();
                nuevaFila.find('.recomendacion-automatica').empty();
                nuevaFila.find('.producto-info').hide().empty();
                nuevaFila.find('.disponibilidad-status').hide().empty();
                
                $('#productos-lista').append(nuevaFila);
                contadorProductos++;
            });
            
            // Eliminar producto
            $(document).on('click', '.remove-producto', function() {
                if ($('.producto-row').length > 1) {
                    $(this).closest('.producto-row').remove();
                    actualizarResumen();
                }
            });
            
            // Cambio de producto
            $(document).on('change', '.producto-select', function() {
                const $row = $(this).closest('.producto-row');
                const productId = $(this).val();
                
                $row.find('.periodos-container').hide();
                $row.find('.producto-info').hide().empty();
                $row.find('.disponibilidad-status').hide().empty();
                
                if (productId) {
                    cargarVariaciones(productId, $row);
                }
            });
            
            function cargarVariaciones(productId, $row) {
                // Usar caché si está disponible
                if (variacionesCache[productId]) {
                    mostrarPeriodos(variacionesCache[productId], $row);
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'get_periodo_variations',
                    product_id: productId,
                    nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
                }, function(response) {
                    if (response.success && response.data.length > 0) {
                        variacionesCache[productId] = response.data;
                        mostrarPeriodos(response.data, $row);
                    }
                }).fail(function() {
                    alert('Error al cargar las variaciones del producto');
                });
            }
            
            function mostrarPeriodos(variaciones, $row) {
                const $container = $row.find('.periodos-grid');
                const $recomendacion = $row.find('.recomendacion-automatica');
                const index = $row.attr('data-index');
                
                $container.empty();
                $recomendacion.empty();
                
                // Calcular recomendación automática si hay fechas
                calcularRecomendacion(variaciones, $recomendacion);
                
                // Mostrar períodos como cards
                variaciones.forEach(function(variacion) {
                    const $card = $('<div class="periodo-card" data-variation-id="' + variacion.id + '">');
                    
                    let contenido = '<div class="periodo-content">';
                    contenido += '<div class="periodo-titulo">' + variacion.display_name + '</div>';
                    contenido += '<div class="periodo-precio">' + variacion.price_formatted;
                    
                    if (variacion.precio_por_dia > 0) {
                        contenido += '<br><small>(' + variacion.precio_por_dia.toFixed(2) + ' por día)</small>';
                    }
                    
                    contenido += '</div>';
                    
                    if (variacion.garantia > 0) {
                        contenido += '<div class="periodo-garantia">Garantía: S/. ' + variacion.garantia + '</div>';
                    }
                    
                    if (variacion.dias_gracia > 0) {
                        contenido += '<div class="periodo-gracia">Gracia: ' + variacion.dias_gracia + ' días</div>';
                    }
                    
                    contenido += '<div class="periodo-stock">';
                    if (variacion.managing_stock) {
                        contenido += 'Stock: ' + (variacion.stock_quantity || 0);
                    } else {
                        contenido += 'Stock no gestionado';
                    }
                    contenido += '</div>';
                    
                    contenido += '<input type="radio" name="productos[' + index + '][variation_id]" value="' + variacion.id + '" style="display: none;">';
                    contenido += '</div>';
                    
                    $card.html(contenido);
                    
                    // Manejar selección
                    $card.click(function() {
                        $container.find('.periodo-card').removeClass('selected');
                        $(this).addClass('selected');
                        $(this).find('input[type="radio"]').prop('checked', true);
                        
                        mostrarInfoSeleccionada(variacion, $row);
                    });
                    
                    $container.append($card);
                });
                
                $row.find('.periodos-container').show();
            }
            
            function calcularRecomendacion(variaciones, $recomendacion) {
                const fechaInicio = $('#fecha_inicio').val();
                const fechaFin = $('#fecha_fin').val();
                
                if (!fechaInicio || !fechaFin) {
                    $recomendacion.html('<div class="recomendacion-info">💡 Selecciona las fechas para ver recomendaciones automáticas</div>');
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'calcular_precio_fechas',
                    fecha_inicio: fechaInicio,
                    fecha_fin: fechaFin,
                    variaciones: JSON.stringify(variaciones),
                    nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        let html = '<div class="recomendacion-info">';
                        html += '<strong>🎯 ' + response.data.recomendacion + '</strong>';
                        html += '<br><small>Período solicitado: ' + response.data.dias_solicitados + ' días</small>';
                        html += '</div>';
                        $recomendacion.html(html);
                        
                        // Auto-seleccionar la mejor opción
                        if (response.data.mejor_variacion) {
                            const $card = $('.periodo-card[data-variation-id="' + response.data.mejor_variacion.id + '"]');
                            if ($card.length) {
                                $card.click();
                            }
                        }
                    }
                });
            }
            
            function mostrarInfoSeleccionada(variacion, $row) {
                let info = '<div class="info-seleccionada">';
                info += '<h4>✅ Período Seleccionado</h4>';
                info += '<div><strong>Período:</strong> ' + variacion.display_name + '</div>';
                info += '<div><strong>Precio:</strong> ' + variacion.price_formatted + '</div>';
                if (variacion.precio_por_dia > 0) {
                    info += '<div><strong>Precio por día:</strong> S/. ' + variacion.precio_por_dia.toFixed(2) + '</div>';
                }
                info += '<div><strong>Garantía:</strong> S/. ' + variacion.garantia + '</div>';
                if (variacion.dias_gracia > 0) {
                    info += '<div><strong>Días de gracia:</strong> ' + variacion.dias_gracia + '</div>';
                }
                info += '</div>';
                
                $row.find('.producto-info').html(info).show();
                
                verificarDisponibilidad($row);
                actualizarResumen();
            }
            
            function verificarDisponibilidad($row) {
                const productId = $row.find('.producto-select').val();
                const variationId = $row.find('input[name*="[variation_id]"]:checked').val();
                const cantidad = $row.find('.cantidad-input').val();
                const fechaInicio = $('#fecha_inicio').val();
                const fechaFin = $('#fecha_fin').val();
                const $status = $row.find('.disponibilidad-status');
                
                if (!productId || !variationId || !fechaInicio || !fechaFin) {
                    $status.hide();
                    return;
                }
                
                $status.html('<div class="checking">🔄 Verificando disponibilidad...</div>').show();
                
                $.post(ajaxurl, {
                    action: 'check_dias_availability',
                    product_id: productId,
                    variation_id: variationId,
                    cantidad: cantidad,
                    fecha_inicio: fechaInicio,
                    fecha_fin: fechaFin,
                    nonce: '<?php echo wp_create_nonce("alquiler_manual_nonce"); ?>'
                }, function(response) {
                    if (response.success) {
                        let html = '<div class="availability-result ';
                        html += response.data.disponible ? 'available' : 'unavailable';
                        html += '">';
                        html += '<div class="result-icon">';
                        html += response.data.disponible ? '✅' : '❌';
                        html += '</div>';
                        html += '<div class="result-message">' + response.data.mensaje + '</div>';
                        html += '</div>';
                        
                        $status.html(html);
                    }
                }).fail(function() {
                    $status.html('<div class="error">❌ Error al verificar disponibilidad</div>');
                });
            }
            
            // Cambio de cantidad
            $(document).on('change', '.cantidad-input', function() {
                verificarDisponibilidad($(this).closest('.producto-row'));
                actualizarResumen();
            });
            
            // Cambio de fechas
            $('#fecha_inicio, #fecha_fin').change(function() {
                calcularDuracionTotal();
                
                // Recalcular recomendaciones para todos los productos
                $('.producto-row').each(function() {
                    const $row = $(this);
                    const productId = $row.find('.producto-select').val();
                    
                    if (productId && variacionesCache[productId]) {
                        const $recomendacion = $row.find('.recomendacion-automatica');
                        calcularRecomendacion(variacionesCache[productId], $recomendacion);
                    }
                    
                    verificarDisponibilidad($row);
                });
            });
            
            function calcularDuracionTotal() {
                const fechaInicio = $('#fecha_inicio').val();
                const fechaFin = $('#fecha_fin').val();
                
                if (fechaInicio && fechaFin) {
                    const inicio = new Date(fechaInicio);
                    const fin = new Date(fechaFin);
                    const diferencia = Math.ceil((fin - inicio) / (1000 * 60 * 60 * 24)) + 1;
                    
                    let html = '<div id="duracion-total" style="background: #e8f4f8; padding: 10px; border-radius: 4px; margin: 10px 0;">';
                    html += '<strong>📅 Duración total del alquiler: ' + diferencia + ' días</strong>';
                    html += '<br><small>Desde ' + fechaInicio + ' hasta ' + fechaFin + '</small>';
                    html += '</div>';
                    
                    $('#calculo-dias').html(html);
                } else {
                    $('#calculo-dias').empty();
                }
            }
            
            function actualizarResumen() {
                let garantiaTotal = 0;
                let precioTotal = 0;
                let resumenHtml = '<div class="resumen-productos"><h4>📋 Resumen de Productos:</h4>';
                
                $('.producto-row').each(function() {
                    const $row = $(this);
                    const nombreProducto = $row.find('.producto-select option:selected').text();
                    const cantidad = parseInt($row.find('.cantidad-input').val()) || 1;
                    const variationId = $row.find('input[name*="[variation_id]"]:checked').val();
                    
                    if (nombreProducto && nombreProducto !== 'Seleccionar producto...' && variationId) {
                        const $info = $row.find('.producto-info');
                        const infoText = $info.text();
                        
                        // Extraer información
                        const precioMatch = infoText.match(/Precio:\s*S\/\.\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/);
                        const garantiaMatch = infoText.match(/Garantía:\s*S\/\.\s*(\d+(?:\.\d+)?)/);
                        
                        if (precioMatch && garantiaMatch) {
                            const precio = parseFloat(precioMatch[1].replace(',', ''));
                            const garantia = parseFloat(garantiaMatch[1]);
                            
                            precioTotal += precio * cantidad;
                            garantiaTotal += garantia * cantidad;
                            
                            resumenHtml += '<div class="resumen-item">';
                            resumenHtml += '<strong>' + nombreProducto + '</strong> (x' + cantidad + ')';
                            resumenHtml += '<br>Precio: S/. ' + (precio * cantidad).toFixed(2);
                            resumenHtml += ' | Garantía: S/. ' + (garantia * cantidad).toFixed(2);
                            resumenHtml += '</div>';
                        }
                    }
                });
                
                if (precioTotal > 0) {
                    resumenHtml += '<div class="resumen-total">';
                    resumenHtml += '<strong>💰 Total Precio: S/. ' + precioTotal.toFixed(2) + '</strong><br>';
                    resumenHtml += '<strong>🔒 Total Garantía: S/. ' + garantiaTotal.toFixed(2) + '</strong>';
                    resumenHtml += '</div>';
                }
                
                resumenHtml += '</div>';
                
                $('#resumen-productos').html(resumenHtml);
                $('#garantia_total').val(garantiaTotal.toFixed(2));
            }
            
            // Validar formulario
            $('#formulario-alquiler-dias').submit(function(e) {
                const clienteExistente = $('#cliente_existente').val();
                const nuevoClienteNombre = $('[name="nuevo_cliente_nombre"]').val();
                const nuevoClienteEmail = $('[name="nuevo_cliente_email"]').val();
                
                if (!clienteExistente && (!nuevoClienteNombre || !nuevoClienteEmail)) {
                    e.preventDefault();
                    alert('❌ Debe seleccionar un cliente existente o crear uno nuevo');
                    return false;
                }
                
                // Verificar que todos los productos tengan período seleccionado
                let productosIncompletos = false;
                $('.producto-row').each(function() {
                    const productId = $(this).find('.producto-select').val();
                    const variationId = $(this).find('input[name*="[variation_id]"]:checked').val();
                    
                    if (productId && !variationId) {
                        productosIncompletos = true;
                    }
                });
                
                if (productosIncompletos) {
                    e.preventDefault();
                    alert('❌ Por favor selecciona un período para todos los productos');
                    return false;
                }
                
                // Verificar disponibilidad
                let productosNoDisponibles = false;
                $('.availability-result.unavailable:visible').each(function() {
                    productosNoDisponibles = true;
                });
                
                if (productosNoDisponibles) {
                    e.preventDefault();
                    alert('❌ Hay productos no disponibles. Por favor revise la disponibilidad.');
                    return false;
                }
            });
        });
        </script>

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
            font-size: 16px;
        }
        .postbox .inside {
            padding: 20px;
        }
        .producto-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .producto-header {
            margin-bottom: 15px;
            overflow: hidden;
        }
        .produto-header h4 {
            margin: 0;
            color: #495057;
            float: left;
        }
        .campo-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }
        .campo-col {
            flex: 1;
        }
        .campo-col label {
            display: block;
            margin-bottom: 5px;
            color: #495057;
        }
        .recomendacion-automatica {
            margin: 10px 0;
        }
        .recomendacion-info {
            background: #fff3cd;
            color: #856404;
            padding: 10px;
            border-radius: 4px;
            border-left: 4px solid #ffc107;
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
            font-size: 16px;
            margin-bottom: 8px;
        }
        .periodo-precio {
            font-size: 18px;
            font-weight: bold;
            color: #28a745;
            margin-bottom: 8px;
        }
        .periodo-card.selected .periodo-precio {
            color: #fff;
        }
        .periodo-garantia, .periodo-gracia, .periodo-stock {
            font-size: 13px;
            margin-bottom: 5px;
            opacity: 0.8;
        }
        .info-seleccionada {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 6px;
            padding: 15px;
            margin-top: 15px;
        }
        .info-seleccionada h4 {
            margin-top: 0;
            color: #0c5460;
        }
        .availability-result {
            padding: 12px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
        }
        .availability-result.available {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .availability-result.unavailable {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .result-icon {
            font-size: 18px;
        }
        .checking {
            color: #667eea;
            font-style: italic;
        }
        .error {
            color: #dc3545;
            font-weight: bold;
        }
        .resumen-productos {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .resumen-item {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        .resumen-total {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #667eea;
            font-size: 16px;
        }
        #duracion-total {
            text-align: center;
        }
        </style>
        <?php
    }

    // Función auxiliar para obtener productos de alquiler
    function get_productos_alquiler_dias() {
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

    // Función para procesar alquiler
    function procesar_alquiler_dias($datos) {
        try {
            // Validaciones
            if (empty($datos['fecha_inicio']) || empty($datos['fecha_fin'])) {
                return array('success' => false, 'message' => 'Las fechas son obligatorias');
            }
            
            if (empty($datos['productos']) || !is_array($datos['productos'])) {
                return array('success' => false, 'message' => 'Debe seleccionar al menos un producto');
            }
            
            // Validar disponibilidad
            foreach ($datos['productos'] as $producto_data) {
                if (empty($producto_data['id']) || empty($producto_data['variation_id'])) continue;
                
                $cantidad = intval($producto_data['cantidad']) ?: 1;
                
                $disponible = verificar_disponibilidad_dias(
                    $producto_data['id'], 
                    $producto_data['variation_id'], 
                    $datos['fecha_inicio'], 
                    $datos['fecha_fin'], 
                    $cantidad
                );
                
                if (!$disponible['disponible']) {
                    $producto = wc_get_product($producto_data['variation_id']);
                    return array('success' => false, 'message' => 'El producto "' . $producto->get_name() . '" no está disponible: ' . $disponible['mensaje']);
                }
            }
            
            // Obtener cliente
            $cliente_id = null;
            if (function_exists('obtener_o_crear_cliente')) {
                $cliente_id = obtener_o_crear_cliente($datos);
            } else {
                $cliente_id = obtener_o_crear_cliente_dias($datos);
            }
            
            if (!$cliente_id) {
                return array('success' => false, 'message' => 'Error al procesar el cliente');
            }
            
            // Crear orden
            $order = wc_create_order();
            $order->set_customer_id($cliente_id);
            $order->set_status('processing');
            
            // Agregar productos
            $garantia_total = 0;
            foreach ($datos['productos'] as $producto_data) {
                if (empty($producto_data['id']) || empty($producto_data['variation_id'])) continue;
                
                $cantidad = intval($producto_data['cantidad']) ?: 1;
                $producto = wc_get_product($producto_data['variation_id']);
                
                if (!$producto) continue;
                
                $order->add_product($producto, $cantidad);
                
                // Calcular garantía
                $garantia_producto = get_post_meta($producto_data['variation_id'], '_valor_garantia', true);
                if (!$garantia_producto) {
                    $garantia_producto = get_post_meta($producto_data['id'], '_valor_garantia', true);
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
                    $coupon->set_code('DESCUENTO_DIAS_' . time());
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
                'message' => '🎉 Alquiler creado exitosamente. Orden #' . $order->get_id() . ' con períodos optimizados.',
                'order_id' => $order->get_id()
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Error: ' . $e->getMessage());
        }
    }

    // Función auxiliar para crear cliente
    function obtener_o_crear_cliente_dias($datos) {
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