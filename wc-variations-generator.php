<?php 
/**
 * Plugin Name: Variations Generator & Mass Edit for WooCommerce
 * Description: Generate and bulk edit variations for your Woocommerce products
 * Author: Hesco
 * Version: 2.0.1
 * Text Domain: wc-variations-generator
 * Domain Path: languages/
 *
 * Copyright: (c) 2020 Antoine Hessemans
 *
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

// must include plugin.php to use is_plugin_active()
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
	$GLOBALS['woocommerce_variations_generator'] = new WCVariationsGenerator();
}

else {
	/* Deactivate the plugin, and display our error notification */
	deactivate_plugins( '/wc-variations-generator/wc-variations-generator.php' );
	add_action( 'admin_notices' , function() {
		printf( '<div class="error"><p>%s</p></div>', __('Variations generator for Woocommerce could not be activated because WooCommerce is not installed and active.', 'woocommerce-variations-generator'));
	});
}

class WCVariationsGenerator {

	const TEXT_DOMAIN = 'wc-variations-generator';
	const SECURITY_NONCE = 'wvg_ajax';

	public function __construct() {

		// Add tab
		add_action( 'woocommerce_product_data_tabs', [ $this, 'add_admin_tab' ] );

		// Add tab panel
		add_action('woocommerce_product_data_panels', [ $this, 'add_admin_tab_panel' ] );

		// Add stylesheet and JS
		add_action( 'admin_enqueue_scripts', [ $this, 'load_assets' ] );

		// AJAX Actions
		add_action( 'wp_ajax_wvg_get_variations_count', [ $this, 'ajax_get_variations_count' ] );
		add_action( 'wp_ajax_wvg_get_admin_content', [ $this, 'ajax_get_admin_content' ] );

		add_action( 'wp_ajax_wvg_fix_variations', [ $this, 'ajax_fix_variations' ] );
		add_action( 'wp_ajax_wvg_delete_price_rule', [ $this, 'ajax_delete_price_rule' ] );
		add_action( 'wp_ajax_wvg_edit_variations', [ $this, 'ajax_edit_variations' ] );


		add_action('plugins_loaded', function() {
			load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		});

	}

	public function load_assets($hook) {
        if( in_array( $hook, [ 'post-new.php','post.php' ] ) && get_current_screen()->post_type == 'product' ) {
        	global $post;
        	wp_enqueue_style('wvg-styles', plugins_url('assets/styles.min.css', __FILE__) );
        	wp_enqueue_script('wvg', plugins_url('assets/scripts.js', __FILE__) );

        	wp_localize_script( 'wvg', 'wvg_vars', 
        		[
        			'post_id'	=> $post->ID,
        			'ajax_url'  => admin_url( 'admin-ajax.php' ),
        			'security'  => wp_create_nonce( self::SECURITY_NONCE ),
        			'il8n'		=> [
        				'generate_error' 		=> __( "There was an unexpected error during request: %s", self::TEXT_DOMAIN),
        				'generate_success'		=> __( "Done! In %s seconds", self::TEXT_DOMAIN),
        				'confirm_delete_rule'	=> __( "Delete this rule? Variations prices will be refreshed.", self::TEXT_DOMAIN),
        				'baseprice_error'		=> __( "Please type in a base price amount.", self::TEXT_DOMAIN)
        			]
        		]
        	);
        }
	}

	public function add_admin_tab($product_data_tabs) {
		$product_data_tabs['wvg_variations_generator'] = [
			'label' 	=> __('Variations generator', self::TEXT_DOMAIN),
			'class' 	=> 'wvg_variations_generator js-wvg-load-tab show_if_variable',
			'target' 	=> 'wvg_variations_generator',
			'priority' 	=> 55
		];
		
		return $product_data_tabs;
	}

	public function add_admin_tab_panel() {
		global $post;
		echo '<div id="wvg_variations_generator" class="panel woocommerce_options_panel wvg_variations"><div class="wvg_inner"></div></div>';
	}


	private function sanitize_price( $input, $positive = false ) {
		$price = (float) str_replace( ",", ".", $input );

		if( $positive ) {
			$price = max( 0, $price );
		}

		return $price;
	}

	private function showError($error) {
		printf( '<div id="message" class="inline error"><p>%s</p></div>', $error );
	}

	private function checkNonce() {
		if ( ! check_ajax_referer( self::SECURITY_NONCE, 'security', false ) ) {
			wp_send_json_error( __( 'Invalid security token.' ) );
		}
	}

	// used in front end when selecting attributes to display matching variations count
	public function ajax_get_variations_count() {
		$this->checkNonce();

		if( isset( $_GET['post_id'] ) && isset( $_GET['attributes'] ) ) {
			$product = wc_get_product( $_GET['post_id'] );

			if( $product->get_type() != 'variable' ) {
				return;
			}

			parse_str( rawurldecode( $_GET['attributes'] ), $attributes );

			wp_send_json_success( [ 'count' => $this->findVariationsByAttributeValues( $attributes['attributes'], $product, true ) ] );

		}

		wp_send_json_error();
	}

	public function ajax_get_admin_content() {

		if(isset($_GET['post_id'])) {

			$this->post = get_post($_GET['post_id']); 
			$this->product = wc_get_product($this->post->ID);


			if($this->product->get_type() != 'variable') {
				$this->showError( __("Set product as variable and add attributes first!", self::TEXT_DOMAIN) );
				die();
			}

			else {
				$this->max_variations_count = 1;
				$this->variations_check = true;
				$this->variations_count = count( $this->product->get_children() );

				// Extract products attributes used for variations
				$available_variations_attributes = wc_list_pluck( array_filter( $this->product->get_attributes(), 'wc_attributes_array_filter_variation' ), 'get_slugs' );

				// Populate terms for front-end attribute filters

				$product_variation_attributes = $this->product->get_variation_attributes();

				foreach( $product_variation_attributes as $attribute_name => $terms ) {

					$this->max_variations_count = $this->max_variations_count * count( $terms );

					// check if current product variations match available variations. If not ($variations_check=false), product may require a "fix"
					if( !isset( $available_variations_attributes[$attribute_name] ) ) {
						$this->variations_check = false;
						//break; // arrays don't match
					}

					sort( $terms );

					$ava_options = $available_variations_attributes[$attribute_name];
					sort( $ava_options );

					if( $terms !=  $ava_options ) {
						$this->variations_check = false;
						//break; // arrays don't match
					}

					// replace terms slugs by object for <select>
					$product_variation_attributes[$attribute_name] = wp_get_object_terms( $this->post->ID, $attribute_name );

				}

				// Check variations means checking that variations count == max variations count (1)
				// AND that variations attributes array == array of product attributes used for variations (2)
				// If attribute is created (1) will be false but (2) true
				// If attribute is deleted (1) is true but (2) is false
				$this->variations_check = ( $this->variations_check && ( $this->variations_count == $this->max_variations_count ) );

				if( empty( $available_variations_attributes ) ) {
					$this->showError( __("No variation attribute found for this product. You must create product attributes first!", self::TEXT_DOMAIN) );
					die();
				}

				else {
					$this->cleanAttributesAndSortByPrice( $this->post->ID );
					$product_options = get_post_meta( $this->post->ID, '_wvg_options', true );
					$this->savedData = $product_options ? $product_options : [];
					$this->product_taxonomies = get_object_taxonomies( 'product', 'objects' );

					include( plugin_dir_path( __FILE__ ) . 'templates/generator.php' );

					die();
				}
			}
		}

		wp_send_json_error();
	}


	private function findVariationsByAttributeValues( $attributes, $parent_product, $count = true ) {

		if($parent_product instanceof WC_Product) {

			if(!$parent_product->has_child()) {
				return $count ? 0 : [];
			}

			// we will basically use this function several times in the same exec when editing both variations price AND stock so we store it in a Class variable
			$cache_key = md5( json_encode( [ $attributes, $parent_product->get_id(), $count ] ) );

			if( !isset( $this->productVariationsByAttributes[ $cache_key ] ) ) {

				$meta_query_args = [];
				
				foreach( $attributes as $tax_name => $term ) {

					if( !empty($term) ) {

						if( empty( $meta_query_args ) ) {
							$meta_query_args = [ 'relation'=>'AND' ];
						}

						$meta_query_args[] = [
							'key'     => sprintf( 'attribute_%s', $tax_name ),
							'field' => 'slug',
							'value'   => $term,
						];
					}	
				}

				$query = [
					'numberposts'	=> 0,
					'post_type' 	=> 'product_variation',
					'post_parent' 	=> $parent_product->get_id()
				];

				if( !empty( $meta_query_args ) ) {
					$query['meta_query'] 	= $meta_query_args;
				}

				if ( !$count ) {
					$query['no_found_rows'] = true;
					$query['numberposts'] = -1;
					$this->productVariationsByAttributes[ $cache_key ] = get_posts( $query );
				}

				else {
					$results = new WP_Query($query);
					$this->productVariationsByAttributes[ $cache_key ] = $results->found_posts;
				}
			}			
		}

		return $this->productVariationsByAttributes[ $cache_key ];
	}



	// Use base price and saved price editions to process prices by order of priority
	private function cleanAttributesAndSortByPrice( $product_id ) {

		$savedData = get_post_meta( $product_id, '_wvg_options', true );

		if( empty($savedData) || !isset($savedData['massedit']) || !isset($savedData['massedit']['price']) ) {
			return true;
		}

		$savedPrices = $savedData['massedit']['price'];

		usort( $savedPrices, function( $pricesa, $pricesb ) {

			if( count( $pricesa['attributes'] ) > count( $pricesb['attributes'] ) ) {
				return -1;
			}
			else if( count( $pricesa['attributes'] ) < count( $pricesb['attributes'] ) ) {
				return 1;
			}
			else if( count( $pricesa['attributes'] ) == count( $pricesb['attributes'] ) ) {
				return 0;
			}

		});

		$product = wc_get_product($product_id);
		$attributes = array_filter( $product->get_attributes(), 'wc_attributes_array_filter_variation' );

		// check attribute existence AND term existence
		foreach ( $savedPrices as $k => $price ) {

			foreach( $price['attributes'] as $attribute => $term_slug ) {

				if ( isset( $attributes[ $attribute ] ) ) {

					$attribute_object = $attributes[ $attribute ];

					$terms = $attribute_object->get_terms();

					// now check terms
					$term_exists = false;

					if( $terms && !empty( $terms ) ) {

						// loop through product attribute terms and check that saved attribute still exists
						foreach($terms as $t) {
							if( $term_slug == $t->slug ) {
								$term_exists = true;
								break;
							}
						}
					}

					// term no longer exists, remove specific price
					if( !$term_exists ) {
						unset( $savedPrices[$k] );
						break;
					}
				}

				// attribute no longer exist or used for variations remove specific price
				else {
					unset( $savedPrices[$k] );
					break;
				}
			}
		}

		/** Some values have been cleaned, save reprocess prices **/
		if($savedPrices != $savedData['massedit']['price']) {
			$savedData['massedit']['price'] = $savedPrices;
			update_post_meta( $product_id, '_wvg_options', $savedData );
			$this->showError( "Some attributes that were used in price rules have been deleted. Associated price rules were deleted and variations prices reprocessed." );
			$this->processProductPrices( $product_id );
		}

		return $savedPrices;
	}


	private function processProductPrices( $parent_product_id ) {

		$parent_product = wc_get_product( $parent_product_id );

		if( empty($parent_product) ) {
			return;
		}

		$product_prices = $this->cleanAttributesAndSortByPrice( $parent_product_id );

		// list of variations to process
		$all_variations_ids = $parent_product->get_children();

		// product base price
		$savedData = get_post_meta( $parent_product_id, '_wvg_options', true );
		$basePrice = $this->sanitize_price( $savedData['basePrice'], true );
		$price_modifications = [];

		if( !empty($product_prices) ) {


			// Parse each specific price rule
			foreach ( $product_prices as $specific_price ) {

				if( (int)$specific_price['priceaddition'] != 1 ) {
					continue;
				}

				// and query associated product by attributes
				$variations_posts = $this->findVariationsByAttributeValues( $specific_price['attributes'], $parent_product, false );

				// Retrieve variations matching selected criteria
				foreach( $variations_posts as $variation_post ) {

					if( !isset( $price_modifications[ $variation_post->ID ] ) ) {
						$price_modifications[ $variation_post->ID ] = $this->sanitize_price( $specific_price['price'] );
					}

					else {
						$price_modifications[ $variation_post->ID ] += $this->sanitize_price( $specific_price['price'] );
					}
				}
			}
		}

		// Set price to all variations
		foreach( $all_variations_ids as $vid ) {

			$product_variation = wc_get_product( $vid );

			if( $product_variation ) {

				// Default variation price is basePrice
				$variation_price = $basePrice;

				// We had previously processed price modifications
				if( isset( $price_modifications[ $vid ] ) ) {
					$variation_price += $price_modifications[ $vid ];
				}

				$variation_price = $this->sanitize_price( $variation_price, true );

				$product_variation->set_price( $variation_price );
				$product_variation->set_regular_price( $variation_price );
				$product_variation->save();
			}	
		}

		WC_Product_Variable::sync( $parent_product );
	}


	public function ajax_fix_variations() {

		$time_start = microtime(true);

		$this->checkNonce();

		if( isset( $_POST['data'] ) ) {

			parse_str( rawurldecode( $_POST['data'] ), $data );

			$parent_product = wc_get_product( (int)$data['post_id'] );

			if( !$parent_product || $parent_product->get_type() != 'variable' ) {
				wp_send_json_error( __("Product doesn't exist or is not variable!", self::TEXT_DOMAIN) );
			}

			else {

				$attributes = wc_list_pluck( array_filter( $parent_product->get_attributes(), 'wc_attributes_array_filter_variation' ), 'get_slugs' );
				$combinations = wc_array_cartesian( $attributes );

				// Collect created or updated variation ids
				$sane_variation_ids = [];
				
				$basePrice = $this->sanitize_price( $data['basePrice'], true );

				// Save baseprice value
				$savedData = get_post_meta($parent_product->get_id(), '_wvg_options', true) ? get_post_meta($parent_product->get_id(), '_wvg_options', true) : [];
				$savedData['basePrice'] = $basePrice;
				update_post_meta( $parent_product->get_id(), '_wvg_options', $savedData );

				@ini_set('max_execution_time', 0);

				// for each resulting combinations, check if variation already exists
				foreach( $combinations as $combination_attributes ) {

					$variation_posts = $this->findVariationsByAttributeValues( $combination_attributes, $parent_product, false );

					// Default values for variables
					$product_variation = false;

					// If variation doesn't exist, create it
					if( count($variation_posts) == 0 ) {
						$product_variation = new WC_Product_Variation;

						if( $product_variation ) {

							$product_variation->set_parent_id( $parent_product->get_id() );
							$product_variation->set_status( 'publish' );
							$product_variation->set_attributes( $combination_attributes );

							if( $processed_variation_id = $product_variation->save() ) {
								$sane_variation_ids[] = $processed_variation_id;
							}
						}
					}

					// fi variation exists we will update it
					else {
						if( wc_get_product( $variation_posts[0]->ID ) ) {
							$sane_variation_ids[] = $variation_posts[0]->ID;
						}
					}
				}

				// Compare processed variations with existing variations and delete ghost variations
				$remove_variations = array_diff( $parent_product->get_children(), $sane_variation_ids );

				if( !empty( $remove_variations ) ) {

					foreach( $remove_variations as $v_id ) {
						$variation = wc_get_product( $v_id );
						if( $variation && $variation->delete() && wp_delete_post( $v_id, true ) ) {
							//$count['delete']++;
						}
					}
				}

				$this->processProductPrices( $parent_product->get_id() );

				$time_end = microtime(true);

				$exec_time = round( $time_end - $time_start , 1);

				wp_send_json_success( [ 'time' => $exec_time ] );
			}
		}
		
		wp_send_json_error();
	}

	public function ajax_delete_price_rule() {

		$this->checkNonce();

		if( isset( $_POST['post_id'] ) && isset( $_POST['rule_attributes'] ) && ( $product = wc_get_product( (int)$_POST['post_id'] ) ) ) {

			$savedData = get_post_meta( $product->get_id(), '_wvg_options', true );

			if( empty($savedData) || !isset($savedData['massedit']) || !isset($savedData['massedit']['price']) ) {
				return true;
			}

			parse_str( rawurldecode( $_POST['rule_attributes'] ), $rule_attributes );
			$savedPrices = $savedData['massedit']['price'];

			// loop through price rules to remove selected one
			foreach ( $savedPrices as $k => $price ) {

				if( array_filter( $price['attributes'] ) == array_filter( $rule_attributes ) ) {
					
					unset( $savedPrices[$k] );
					$savedData['massedit']['price'] = $savedPrices;

					if( update_post_meta( $product->get_id(), '_wvg_options', $savedData ) ) {
						$this->processProductPrices( $product->get_id() );
						wp_send_json_success();
					}

					break;
				}
			}
		}

		wp_send_json_error();
	}


	public function ajax_edit_variations() {

		$time_start = microtime(true);

		$this->checkNonce();

		@ini_set('max_execution_time', 0);

		if( isset( $_POST['data'] ) ) {

			parse_str( rawurldecode( $_POST['data'] ), $data );

			$parent_product = wc_get_product( (int)$data['post_id'] );

			if( !$parent_product || $parent_product->get_type() != 'variable' ) {
				wp_send_json_error( __("Product doesn't exist or is not variable!", self::TEXT_DOMAIN) );
			}

			else {

				$variation_new_values = [];

				// PRICE
				if( isset($data['edit_variations_price']) && $data['edit_variations_price'] == 1 ) {

					if( isset( $data['price'] ) && strlen( $data['price'] ) > 0 && isset( $data['priceaddition'] ) ) {

						$savedData = get_post_meta($parent_product->get_id(), '_wvg_options', true) ? get_post_meta($parent_product->get_id(), '_wvg_options', true) : [];

						// Save basePrice
						$savedData['basePrice'] = $this->sanitize_price( $data['basePrice'] );
						update_post_meta( $parent_product->get_id(), '_wvg_options', $savedData );

						if( (int)$data['priceaddition'] == 1 && $this->sanitize_price( $data['price'] ) != 0 ) {

							// save price
							$attributes_sorted = array_filter( $data['attributes'] );

							asort( $attributes_sorted ); // Sort and clear empty attribute values

							$new_price_array = [
								'attributes'	=>	$attributes_sorted,
								'priceaddition'	=> 	isset($data['priceaddition']) ? (int) $data['priceaddition'] : 0,
								'price'			=>	$this->sanitize_price( $data['price'] )
							];

							$match_found = false;


							// Init prices array if first time
							if( !isset( $savedData['massedit'] ) || !isset( $savedData['massedit']['price'] ) ) {
								$savedData['massedit']['price'] = [];
							}


							// check if a price was previously saved for these attributes, in order to overwrite it
							foreach( $savedData['massedit']['price'] as $k => $prices ) {
								if( array_filter( $prices['attributes'] ) == $attributes_sorted ) {
									$match_found = true;
									$savedData['massedit']['price'][$k] = $new_price_array;
									break;
								}
							}

							// if not just save price as new array entry 
							if(!$match_found) {
								$savedData['massedit']['price'][] = $new_price_array;
							}

							// Persist savedData to meta before processing prices
							update_post_meta( $parent_product->get_id(), '_wvg_options', $savedData );

							$this->processProductPrices( $parent_product->get_id() );

						}

						elseif( (int)$data['priceaddition'] == 0 ) {
							$variation_new_values['price'] = $this->sanitize_price( $data['price'] );
						}
					}
				}

				// STOCK
				if( isset($data['edit_variations_stock']) && $data['edit_variations_stock'] == 1 ) {

					if( isset( $data['manage_stock'] ) ) {

						// and query associated product by attributes
						$variations_posts = $this->findVariationsByAttributeValues( $data['attributes'], $parent_product, false );

						// Retrieve variations matching selected criteria
						foreach( $variations_posts as $variation_post ) {

							$product_variation = wc_get_product( $variation_post->ID );

							if( !empty( $product_variation ) ) {
								
								$manage_stock_bool = (bool) $data['manage_stock'];

								$product_variation->set_manage_stock( $manage_stock_bool );

								if( $manage_stock_bool && isset( $data['quantity'] ) && strlen( $data['quantity'] ) > 0 ) {
									$product_variation->set_stock_quantity( (int)$data['quantity'] );
								}

								$product_variation->save();
							}
						}

						WC_Product_Variable::sync( $parent_product );

					}
				}

				$time_end = microtime(true);

				$exec_time = round( $time_end - $time_start , 1);

				wp_send_json_success( [ 'time' => $exec_time ] );
			}
		}
		wp_send_json_error();
	}
}