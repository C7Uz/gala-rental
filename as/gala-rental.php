<?php
/**
 * Plugin Name: Gala Rental
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
	require_once( __DIR__ . '/widgets/categoria-cuadro-widget.php' );
	require_once( __DIR__ . '/widgets/atributo-cuadro-widget.php' );
	require_once( __DIR__ . '/widgets/banner-rectangular-widget.php' );
	$widgets_manager->register( new \Widget_Categoria_Cuadro() );
	$widgets_manager->register( new \Widget_Atributo_Cuadro() );
	$widgets_manager->register( new \Widget_Banner_Rectangular() );

	require_once( __DIR__ . '/widgets/testimonio-item-widget.php' );
	$widgets_manager->register( new \Widget_Testimonio_Item() );

	require_once( __DIR__ . '/widgets/slider-personalizado-widget.php' );
	$widgets_manager->register( new \Widget_Slider_Personalizado() );


} );

include 'woo/rest-api/agrega-cupon-descuento.php';
include 'woo/rest-api/remover-cupon.php';
include 'woo/metodo-de-pago-izi-pay.php';
include 'woo/medidas-talla.php';
include 'woo/opciones-edicion-producto.php';
 
include 'woo/formulario-alquiler-automatico.php';