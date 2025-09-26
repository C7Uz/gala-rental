<?php

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widget_Testimonio_Item extends Widget_Base {

	public function get_name() {
		return 'testimonio-item';
	}

	public function get_title() {
		return 'Testimonio (item)';
	}

	public function get_icon() {
		return 'eicon-testimonial';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	protected function register_controls() {
		$this->start_controls_section(
			'contenido',
			[
				'label' => 'Contenido del Testimonio',
				'tab'   => Controls_Manager::TAB_CONTENT,
			]
		);

		$this->add_control(
			'nombre',
			[
				'label'   => 'Nombre',
				'type'    => Controls_Manager::TEXT,
				'default' => 'Juan Pérez',
			]
		);

		$this->add_control(
			'testimonio',
			[
				'label'   => 'Testimonio',
				'type'    => Controls_Manager::TEXTAREA,
				'default' => 'Estoy muy satisfecho con el servicio recibido. Lo recomiendo totalmente.',
				'rows'    => 4,
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings   = $this->get_settings_for_display();
		$nombre     = $settings['nombre'];
		$testimonio = $settings['testimonio'];

		ob_start();
		?>
        <div class="testimonio-item-widget">
            <div>
                “<?php echo esc_html( $testimonio ); ?>”
            </div>
            <span>— <?php echo esc_html( $nombre ); ?></span>
        </div>
		<?php
		echo ob_get_clean();
	}
}
