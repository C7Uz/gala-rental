<?php
add_filter( 'woocommerce_payment_gateways', function ( $metodos ) {
	$metodos[] = 'WC_Gateway_IziPay';

	return $metodos;
} );


add_action( 'plugins_loaded', function (): void {
	if ( class_exists( 'WC_Payment_Gateway' ) ) {
		class WC_Gateway_IziPay extends WC_Payment_Gateway {

			public function __construct() {
				$this->id                 = 'izi_pay';
				$this->method_title       = 'IziPay Gala';
				$this->method_description = 'Un método de pago personalizado.';
				$this->has_fields         = false;

				$this->init_form_fields();
				$this->init_settings();

				$this->title       = $this->get_option( 'title' );
				$this->description = $this->get_option( 'description' );
				$this->enabled     = $this->get_option( 'enabled' );

				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
			}

			public function init_form_fields(): void {
				$this->form_fields = [
					'enabled'         => [
						'title'   => 'Activar/Desactivar',
						'type'    => 'checkbox',
						'label'   => 'Activar método de pago personalizado',
						'default' => 'yes'
					],
					'public_api_key'  => [
						'title' => 'Clave pública (Cliente JavaScript)',
						'type'  => 'text',
					],
					'merchant_code'   => [
						'title'    => 'Código de comercio / Usuario (API REST)',
						'type'     => 'text',
						'desc_tip' => true,
					],
					'private_api_key' => [
						'title' => 'Contraseña (API REST)',
						'type'  => 'text',
					],
				];
			}
		}
	}
} );


