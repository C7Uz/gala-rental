<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widget_Atributo_Cuadro extends Widget_Base {

	public function get_name() {
		return 'atributo_cuadro';
	}

	public function get_title() {
		return 'Cuadro de Atributo';
	}

	public function get_icon() {
		return 'eicon-product-categories';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	protected function register_controls() {
		$this->start_controls_section(
			'contenido',
			[
				'label' => 'Contenido',
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'imagen',
			[
				'label'   => 'Imagen',
				'type'    => Controls_Manager::MEDIA,
				'default' => [
					'url' => 'https://via.placeholder.com/300x200',
				],
			]
		);

		$this->add_control(
			'titulo',
			[
				'label'   => 'Nombre del atributo',
				'type'    => Controls_Manager::TEXT,
				'default' => 'Manga larga',
			]
		);

		$this->add_control(
			'enlace',
			[
				'label'         => 'URL del atributo',
				'type'          => Controls_Manager::URL,
				'placeholder'   => 'https://tusitio.com/pa_manga/manga-larga/',
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
		$img_url     = $settings['imagen']['url'];
		$title       = $settings['titulo'];
		$link        = $settings['enlace']['url'];
		$is_external = $settings['enlace']['is_external'] ? ' target="_blank" rel="noopener"' : '';

		ob_start();
		?>
        <a class="atributo-cuadro-widget" href="<?php echo esc_url( $link ); ?>"<?php echo $is_external; ?>>
            <div class="imagen">
                <div class="image-bg" style="background-image: url(<?php echo esc_url( $img_url ); ?>)"></div>
                <div class="contenido">
                    <div class="nombre-categoria"><?php echo esc_html( $title ); ?></div>
                    <div class="ver-todos"><span>Ver todos</span></div>
                </div>
            </div>
        </a>
		<?php
		echo ob_get_clean();
	}
}
