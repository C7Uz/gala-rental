<?php
add_action( 'woocommerce_product_options_advanced', function () {
	echo '<div class="options_group">';
	woocommerce_wp_checkbox( array(
		'id'            => '_es_alquiler',
		'wrapper_class' => 'show_if_simple show_if_variable',
		'label'         => __( 'Es un producto de alquiler', 'woocommerce' ),
		'description'   => __( 'Marca esta casilla si el producto está disponible para alquiler.', 'woocommerce' ),
	) );
	echo '</div>';

	// Contenedor para los nuevos campos
	echo '<div class="options_group alquiler_options">';

	// Campo para la garantía
	woocommerce_wp_text_input( array(
		'id'          => '_valor_garantia',
		'label'       => __( 'Valor de la garantía', 'woocommerce' ),
		'description' => __( 'Monto que debe dejar el cliente como garantía por el producto.', 'woocommerce' ),
		'data_type'   => 'price', // Esto agrega validación para que solo acepte números
	) );

	// Campo para el período de gracia
	woocommerce_wp_text_input( array(
		'id'          => '_dias_gracia',
		'label'       => __( 'Días de gracia', 'woocommerce' ),
		'description' => __( 'Días que el producto estará no disponible después de un alquiler.', 'woocommerce' ),
		'data_type'   => 'decimal', // Acepta números
	) );

	echo '</div>';
} );

add_action( 'woocommerce_process_product_meta', function ( $post_id ) {
	$es_alquiler = isset( $_POST['_es_alquiler'] ) ? 'yes' : 'no';
	update_post_meta( $post_id, '_es_alquiler', $es_alquiler );

	// Guardar la garantía solo si la casilla de alquiler está marcada
	if ( $es_alquiler === 'yes' ) {
		$valor_garantia = sanitize_text_field( $_POST['_valor_garantia'] );
		update_post_meta( $post_id, '_valor_garantia', $valor_garantia );

		$dias_gracia = sanitize_text_field( $_POST['_dias_gracia'] );
		update_post_meta( $post_id, '_dias_gracia', $dias_gracia );
	} else {
		// Elimina los valores si se desmarca la casilla
		delete_post_meta( $post_id, '_valor_garantia' );
		delete_post_meta( $post_id, '_dias_gracia' );
	}
} );

add_action( 'admin_footer', function () {
	global $post;

	if ( get_post_type( $post ) === 'product' ) {
		?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Selecciona el contenedor de los campos de alquiler
                const alquiler_options_group = $('.alquiler_options');
                const es_alquiler = $('#_es_alquiler');

                // Función para mostrar/ocultar
                function toggle_alquiler_options() {
                    if (es_alquiler.is(':checked')) {
                        alquiler_options_group.show();
                    } else {
                        alquiler_options_group.hide();
                    }
                }

                // Ejecuta al cargar la página (en caso de que ya esté marcado)
                toggle_alquiler_options();

                // Detecta el cambio en el checkbox
                es_alquiler.on('change', function () {
                    toggle_alquiler_options();
                });
            });
        </script>
		<?php
	}
} );

