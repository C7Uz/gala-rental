<?php
add_action( 'rest_api_init', function () {
	register_rest_route( 'gala/v1', '/agregar-cupon-descuento/', array(
		'methods'             => 'POST',
		'callback'            => function ( $request ): WP_REST_Response {

			if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Cart' ) ) {
				return new WP_REST_Response( [ 'error' => 'WooCommerce no está activo.' ], 500 );
			}

			$coupon_code = strtoupper( sanitize_text_field( $request->get_param( 'coupon' ) ) );

			if ( empty( $coupon_code ) ) {
				return new WP_REST_Response( [ 'error' => 'Se requiere el código de cupón.' ], 400 );
			}

			// Asegura que la sesión y el carrito estén listos
			if ( ! WC()->session ) {
				WC()->initialize_session();
			}
			if ( null === WC()->cart ) {
				wc_load_cart();
			}

			// Verificar si ya está aplicado
			if ( WC()->cart->has_discount( $coupon_code ) ) {
				return new WP_REST_Response( [ 'error' => 'Este cupón ya ha sido aplicado.' ], 400 );
			}

			// Cargar cupón
			try {
				$coupon = new WC_Coupon( $coupon_code );
			} catch ( Exception $e ) {
				return new WP_REST_Response( [ 'error' => 'Cupón inválido: ' . $e->getMessage() ], 400 );
			}

			if ( ! $coupon->get_id() ) {
				return new WP_REST_Response( [ 'error' => 'El cupón no existe.' ], 400 );
			}

			// Validar con WC_Discounts
			$discounts = new WC_Discounts( WC()->cart );
			$valid     = $discounts->is_coupon_valid( $coupon );

			if ( is_wp_error( $valid ) ) {
				return new WP_REST_Response( [ 'error' => $valid->get_error_message() ], 400 );
			}

			// Aplicar cupón
			WC()->cart->add_discount( $coupon_code );
			WC()->cart->calculate_totals();

			return new WP_REST_Response( [
				'success'     => true,
				'message'     => 'Cupón aplicado exitosamente.',
				'coupon_code' => $coupon_code,
				'coupon_desc' => $coupon->get_description(),
				'total'       => WC()->cart->get_total(),
				'descuento'   => wc_price( WC()->cart->get_discount_total() ),
			], 200 );

		},
		'permission_callback' => '__return_true',
	) );
} );



