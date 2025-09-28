<?php
add_action( 'rest_api_init', function () {
	register_rest_route( 'gala/v1', '/remover-cupon-descuento/', array(
		'methods'             => 'POST',
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Cart' ) ) {
				return new WP_REST_Response( [ 'error' => 'WooCommerce no está activo' ], 500 );
			}

			// Inicializar sesión si es necesario
			if ( ! WC()->session ) {
				WC()->initialize_session();
			}

			if ( null === WC()->cart ) {
				wc_load_cart();
			}

			WC()->cart->get_cart(); // fuerza la carga real

			// Verifica si hay cupones aplicados
			if ( empty( WC()->cart->get_applied_coupons() ) ) {
				return new WP_REST_Response( [
					'success' => true,
					'message' => 'No hay cupones aplicados.',
				], 200 );
			}

			// Eliminar todos los cupones
			WC()->cart->remove_coupons();
			WC()->cart->calculate_totals();

			return new WP_REST_Response( [
				'success'   => true,
				'message'   => 'Todos los cupones han sido eliminados.',
				'total'     => WC()->cart->get_total(),
				'descuento' => '-' . wc_price( WC()->cart->get_discount_total() ),
			], 200 );
		},
		'permission_callback' => '__return_true',
	) );
} );


