<?php
/**
 * Pestaña "Medidas por talla" en la edición de producto
 * - Muestra, por CADA atributo del producto, sus opciones (S, M, L… / Rojo, Azul…)
 * - Para cada opción, campos: busto, cintura, cadera, altura
 * - Guarda todo en _medidas_por_atributo (array por producto)
 */

/* 1) Añadir pestaña después de "Avanzado" */
add_filter( 'woocommerce_product_data_tabs', function ( $tabs ) {
	$tabs['medidas_tallas'] = [
		'label'    => __( 'Medidas por talla', 'tu-textdomain' ),
		'target'   => 'medidas_tallas_panel',
		'class'    => [ 'show_if_simple', 'show_if_variable' ], // muéstralo en simple y variable
		'priority' => 75, // después de "advanced" (70)
	];

	return $tabs;
}, 99 );

/* 2) Panel con los campos */
add_action( 'woocommerce_product_data_panels', function () {
	global $post;
	$product = wc_get_product( $post->ID );
	if ( ! $product ) {
		return;
	}

	$saved = get_post_meta( $post->ID, '_medidas_por_atributo', true );
	if ( ! is_array( $saved ) ) {
		$saved = [];
	}

	echo '<div id="medidas_tallas_panel" class="panel woocommerce_options_panel">';
	wp_nonce_field( 'medidas_tallas_nonce', 'medidas_tallas_nonce' );

	$attributes = $product->get_attributes();
	// 🔎 Qué atributo(s) aceptamos como “Talla”
	$allowedAttrKeys = [ 'pa_talla', 'talla' ];

	// Filtrar solo Talla
	$attributes = array_filter( $attributes, function ( $attr ) use ( $allowedAttrKeys ) {
		$slug = sanitize_title( $attr->get_name() );

		return in_array( $slug, $allowedAttrKeys, true );
	} );

	if ( empty( $attributes ) ) {
		echo '<p><em>' . esc_html__( 'Este producto no tiene el atributo “Talla”.', 'tu-textdomain' ) . '</em></p>';
		echo '</div>';

		return;
	}

	echo '<p>' . esc_html__( 'Introduce las medidas por talla para este producto.', 'tu-textdomain' ) . '</p>';

	foreach ( $attributes as $attribute ) {
		/** @var WC_Product_Attribute $attribute */
		$attr_name  = $attribute->get_name();              // 'pa_talla' o 'Talla'
		$attr_key   = sanitize_title( $attr_name );          // normalizamos: 'pa_talla' / 'talla'
		$attr_label = wc_attribute_label( $attr_name, $product );

		// Armar filas (opciones de talla)
		$rows = [];
		if ( $attribute->is_taxonomy() ) {
			$term_ids = $attribute->get_options();
			if ( ! empty( $term_ids ) ) {
				$terms = get_terms( [
					'taxonomy'   => $attr_name,
					'include'    => $term_ids,
					'hide_empty' => false,
					'orderby'    => 'include',
				] );
				foreach ( $terms as $t ) {
					$rows[] = [ 'key' => $t->slug, 'label' => $t->name ];
				}
			}
		} else {
			foreach ( $attribute->get_options() as $opt ) {
				$rows[] = [ 'key' => sanitize_title( (string) $opt ), 'label' => (string) $opt ];
			}
		}

		if ( empty( $rows ) ) {
			continue;
		}

		echo '<h3 style="margin-top:1em;">' . esc_html( $attr_label ) . '</h3>';
		echo '<table class="widefat striped" style="margin:8px 0 16px;">
                <thead><tr>
                    <th style="width:160px;">' . esc_html__( 'Talla', 'tu-textdomain' ) . '</th>
                    <th>' . esc_html__( 'Altura (cm)', 'tu-textdomain' ) . '</th>
                    <th>' . esc_html__( 'Busto (cm)', 'tu-textdomain' ) . '</th>
                    <th>' . esc_html__( 'Cintura (cm)', 'tu-textdomain' ) . '</th>
                    <th>' . esc_html__( 'Cadera (cm)', 'tu-textdomain' ) . '</th>
                </tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$k    = $r['key'];
			$lbl  = $r['label'];
			$vals = $saved[ $attr_key ][ $k ] ?? [ 'busto' => '', 'cintura' => '', 'cadera' => '', 'altura' => '' ];

			printf(
				'<tr><td><strong>%s</strong></td>
                 <td><input type="text" name="_medidas_por_atributo[%s][%s][altura]"   value="%s" class="short" /></td>
                 <td><input type="text" name="_medidas_por_atributo[%s][%s][busto]"   value="%s" class="short" /></td>
                 <td><input type="text" name="_medidas_por_atributo[%s][%s][cintura]" value="%s" class="short" /></td>
                 <td><input type="text" name="_medidas_por_atributo[%s][%s][cadera]"  value="%s" class="short" /></td></tr>',
				esc_html( $lbl ),
				esc_attr( $attr_key ), esc_attr( $k ), esc_attr( $vals['altura'] ),
				esc_attr( $attr_key ), esc_attr( $k ), esc_attr( $vals['busto'] ),
				esc_attr( $attr_key ), esc_attr( $k ), esc_attr( $vals['cintura'] ),
				esc_attr( $attr_key ), esc_attr( $k ), esc_attr( $vals['cadera'] )
			);
		}

		echo '</tbody></table>';
	}

	echo '</div>';
} );

/* 3) Guardar los datos */
add_action( 'woocommerce_admin_process_product_object', function ( WC_Product $product ) {
	if (
		! isset( $_POST['medidas_tallas_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['medidas_tallas_nonce'] ) ), 'medidas_tallas_nonce' )
	) {
		return;
	}

	$raw = $_POST['_medidas_por_atributo'] ?? [];
	if ( ! is_array( $raw ) ) {
		delete_post_meta( $product->get_id(), '_medidas_por_atributo' );

		return;
	}

	$clean = [];
	foreach ( $raw as $attr_key => $opts ) {
		// Aceptar solo “talla”
		$slug = sanitize_title( $attr_key );
		if ( ! in_array( $slug, [ 'pa_talla', 'talla' ], true ) || ! is_array( $opts ) ) {
			continue;
		}

		foreach ( $opts as $k => $vals ) {
			if ( ! is_array( $vals ) ) {
				continue;
			}
			$clean[ $slug ][ $k ] = [
				'altura'  => isset( $vals['altura'] ) ? wc_clean( wp_unslash( $vals['altura'] ) ) : '',
				'busto'   => isset( $vals['busto'] ) ? wc_clean( wp_unslash( $vals['busto'] ) ) : '',
				'cintura' => isset( $vals['cintura'] ) ? wc_clean( wp_unslash( $vals['cintura'] ) ) : '',
				'cadera'  => isset( $vals['cadera'] ) ? wc_clean( wp_unslash( $vals['cadera'] ) ) : '',
			];
		}
	}

	if ( ! empty( $clean ) ) {
		update_post_meta( $product->get_id(), '_medidas_por_atributo', $clean );
	} else {
		delete_post_meta( $product->get_id(), '_medidas_por_atributo' );
	}
}, 10, 1 );
