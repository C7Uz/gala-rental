<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widget_Slider_Personalizado extends Widget_Base {

	public function get_name() {
		return 'slider-personalizado';
	}

	public function get_title() {
		return 'Slider Personalizado';
	}

	public function get_icon() {
		return 'eicon-slider-push';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	protected function register_controls() {
		// Encabezado doble
		$this->start_controls_section(
			'seccion_heading',
			[
				'label' => 'Encabezado',
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'heading_parte_1',
			[
				'label'   => 'Texto parte izquierda',
				'type'    => Controls_Manager::TEXT,
				'default' => 'Lo mejor de',
			]
		);

		$this->add_control(
			'heading_parte_2',
			[
				'label'   => 'Texto parte derecha (coloreada)',
				'type'    => Controls_Manager::TEXT,
				'default' => 'nuestros productos',
			]
		);

		$this->end_controls_section();

		// Repeater (slider items)
		$this->start_controls_section(
			'seccion_slider',
			[
				'label' => 'Items del Slider',
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'imagen',
			[
				'label'   => 'Imagen',
				'type'    => Controls_Manager::MEDIA,
				'default' => [
					'url' => 'https://via.placeholder.com/300x200',
				],
			]
		);

		$repeater->add_control(
			'texto',
			[
				'label'   => 'Texto',
				'type'    => Controls_Manager::TEXT,
				'default' => 'Nombre del ítem',
			]
		);

		$repeater->add_control(
			'enlace',
			[
				'label'         => 'Enlace',
				'type'          => Controls_Manager::URL,
				'show_external' => true,
				'placeholder'   => 'https://tusitio.com/',
				'default'       => [
					'url'         => '',
					'is_external' => false,
				],
			]
		);

		$this->add_control(
			'items_slider',
			[
				'label'       => 'Items',
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => [],
				'title_field' => '{{{ texto }}}',
			]
		);

		$this->end_controls_section();

		// Texto inferior
		$this->start_controls_section(
			'seccion_texto_inferior',
			[
				'label' => 'Texto inferior',
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'texto_inferior',
			[
				'label'   => 'Texto',
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 4,
				'default' => 'Este es un texto informativo al pie del slider.',
			]
		);

		$this->add_control(
			'enlace',
			[
				'label'         => 'Enlace botón',
				'type'          => Controls_Manager::URL,
				'show_external' => true,
				'placeholder'   => 'https://tusitio.com/',
				'default'       => [
					'url'         => '',
					'is_external' => false,
				],
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		$heading_1          = $settings['heading_parte_1'];
		$heading_2          = $settings['heading_parte_2'];
		$items              = $settings['items_slider'];
		$texto_inferior     = $settings['texto_inferior'];
		$enlace_url         = $settings['enlace']['url'];
		$is_enlace_external = $settings['enlace']['is_external'] ? ' target="_blank" rel="noopener"' : '';
		ob_start();
		?>
        <div class="slider-personalizado-widget">
            <div class="lado-izquierdo">
                <div class="swiper slider-items">
                    <div class="swiper-wrapper">
						<?php foreach ( $items as $item ) :
							$url = $item['enlace']['url'];
							$is_external = $item['enlace']['is_external'] ? ' target="_blank" rel="noopener"' : '';
							?>
                            <div class="swiper-slide">
                                <a href="<?php echo esc_url( $url ); ?>"<?php echo $is_external; ?>>
                                    <div class="imagen">
										<?= wp_get_attachment_image( $item['imagen']['id'], 'image_340_496', false, [ 'alt' => $item['texto'] ] ); ?>
                                    </div>
                                </a>
                            </div>
						<?php endforeach; ?>
                    </div>

                </div>
            </div>
            <div class="lado-derecho">
                <div class="encabezado">
					<?php echo esc_html( $heading_1 ); ?>
                    <span><?php echo esc_html( $heading_2 ); ?></span>
                </div>
                <div class="slider-bottom-text">
					<?php echo esc_html( $texto_inferior ); ?>
                </div>
				<?= render_secondary_button_link( 'Ver vestidos', $enlace_url ); ?>
            </div>
        </div>
		<?php
		echo ob_get_clean();
	}
}
