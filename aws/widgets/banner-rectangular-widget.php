<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widget_Banner_Rectangular extends Widget_Base {

	public function get_name() {
		return 'banner-rectangular';
	}

	public function get_title() {
		return 'Banner Rectangular';
	}

	public function get_icon() {
		return 'eicon-banner';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	protected function register_controls() {
		$this->start_controls_section(
			'contenido',
			[
				'label' => 'Contenido del Banner',
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'fondo',
			[
				'label'   => 'Imagen de fondo',
				'type'    => Controls_Manager::MEDIA,
				'default' => [
					'url' => 'https://via.placeholder.com/1024x400',
				],
			]
		);

		$this->add_control(
			'etiqueta',
			[
				'label'   => 'Etiqueta',
				'type'    => Controls_Manager::TEXT,
				'default' => 'Nueva colección',
			]
		);

		$this->add_control(
			'titulo',
			[
				'label'   => 'Texto principal',
				'type'    => Controls_Manager::TEXTAREA,
				'default' => 'Descubre nuestra línea premium de vestidos.',
			]
		);

		$this->add_control(
			'enlace',
			[
				'label'         => 'Enlace del banner',
				'type'          => Controls_Manager::URL,
				'placeholder'   => 'https://tusitio.com/coleccion/',
				'show_external' => true,
				'default'       => [
					'url'         => '',
					'is_external' => false,
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings    = $this->get_settings_for_display();
		$bg_url      = $settings['fondo']['url'];
		$etiqueta    = $settings['etiqueta'];
		$titulo      = $settings['titulo'];
		$link        = $settings['enlace']['url'];
		$is_external = $settings['enlace']['is_external'] ? ' target="_blank" rel="noopener"' : '';
		ob_start();
		?>
        <a class="banner-rectangular-widget" href="<?php echo esc_url( $link ); ?>"<?php echo $is_external; ?>
           style="text-decoration: none; background-image: url('<?php echo esc_url( $bg_url ); ?>');">
            <span><?php echo esc_html( $etiqueta ); ?></span>

            <h6><?php echo $titulo ?></h6>
        </a>
		<?php
		echo ob_get_clean();
	}
}
