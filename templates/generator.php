<input type="hidden" name="post_id" value="<?= $this->post->ID ?>" />

<div class="options_group settings">

	<h3><?= __( 'Settings', self::TEXT_DOMAIN ) ?></h3>

	<div class="container">
		<p class="form-field">
			<label for="basePrice"><?=sprintf(__( 'Variations base price (%s)', self::TEXT_DOMAIN ), get_woocommerce_currency_symbol())?></label>
			<input type="number" required size="8" class="short" name="basePrice" id="basePrice" value="<?=$this->savedData['basePrice']?>" placeholder="0" />
		</p>

		<p class="info baseprice">
			<span class="dashicons dashicons-info"></span>
			<?=__( "This price will be the default price for every newly created variation." , self::TEXT_DOMAIN )?>
			<?php if( !empty( $this->savedData['massedit']['price'] ) ) : ?>
				<br /><?=__( "Changing the base price will also affect the price of variations that have a price rule defined below." , self::TEXT_DOMAIN )?>
			<?php endif; ?>
		</p>

		<p class="form-field">
			<label for="variations_status"><?=__( 'Variations check', self::TEXT_DOMAIN )?></label>
			<span class="status-<?= ( $this->variations_check ? 'success' : 'error' ) ?>"></span>
		</p>

		<?php if( !$this->variations_check ) : ?>

			<?php $this->showError( __( "Variations currently don't match product attributes.<br />
				This may have been caused by either editing product's attributes or manually deleting a variation.<br />
				It is recommended that you fix this by clicking below button.<br />
				Missing variations will be created and existing variations that don't match with product's attributes will be deleted.", self::TEXT_DOMAIN ) ); ?>
		<?php endif; ?>

		<input data-active-text="<?= __('processing...') ?>" data-action="fix" class="js-wvg-process button button-primary button-large wvg_fix_variations" type="button" value="<?=__("Save settings and process variations!", self::TEXT_DOMAIN)?>" />
	</div>


</div>


<?php if( !empty( $this->savedData['massedit']['price'] ) ) : ?>

<div class="options_group">

	<h3><?= __( 'Saved price modifications', self::TEXT_DOMAIN ) ?></h3>

	<div class="container">

		<ul>

		<?php
		
		foreach( array_filter( $this->savedData['massedit']['price'] ) as $prices ) : ?>

			<li>
				<?php
				$i = 0;
				$items = array_filter( $prices['attributes'] );
				foreach( $items as $attribute_name => $attribute_value) : ?>
				<?=$this->product_taxonomies[$attribute_name]->labels->singular_name?> : <b><?=!empty($attribute_value) ? get_term_by('slug', $attribute_value, $attribute_name)->name : __('All values', self::TEXT_DOMAIN)?></b> <?php if(++$i !== count($items)) : ?>-<?php endif; ?> <?php endforeach; ?> => <b><?=(bool)$prices['priceaddition'] ? __('Add', self::TEXT_DOMAIN) : __('Set to', self::TEXT_DOMAIN)?></b> <?=wc_price($prices['price'])?> <a class="js-delete-rule" data-rule="<?=rawurlencode( http_build_query( $prices['attributes'] ) )?>" href="#">[<?=__('Delete', self::TEXT_DOMAIN)?>]</a>
			</li>

		<?php endforeach; ?>

		</ul>

		<p><?= __( 'Note: price rules are cumulative, ie a variation matching multiple price rules will be applied each associated price modifications.', self::TEXT_DOMAIN ) ?></p>

	</div>

</div>

<?php endif; ?>

<div class="options_group">

	<h3><?= __( 'Edit variations', self::TEXT_DOMAIN ) ?></h3>

	<div class="select-attributes">
		<p class="title"><?=__("Select attributes of variations to edit", self::TEXT_DOMAIN)?> <?= wc_help_tip( __( "All variations matching the selected attributes will be edited with the below settings.", self::TEXT_DOMAIN ) )?></p>

	<?php
		foreach( $product_variation_attributes  as $attribute_name => $attribute_terms ) :
	?>
		
		<div class="block_attribute">

			<?php printf( '<p class="attribute_name">%s</p>', $this->product_taxonomies[$attribute_name]->labels->singular_name ); ?>

			<select class="js-select-attributes" name="attributes[<?=$attribute_name?>]">
				<option value=""><?=__('All values', self::TEXT_DOMAIN)?></option>
			
			<?php
				foreach( $attribute_terms as $t ) :
			?>

			<option value="<?=$t->slug?>"><?=$t->name?></option>

			<?php
				endforeach;
			?>

			</select>

		</div>

<?php
	endforeach;
?>

		<div class="clear">
			<p class="info edit-variations-count">
				<span class="dashicons dashicons-info"></span>
				<?=sprintf( __("%s variation(s) match your selection.", self::TEXT_DOMAIN), '<span class="count">' . $this->variations_count . '</span>' ) ?>
			</p>
		</div>

	</div>

	<div class="container">

		<hr />
		<div class="col2">
			<h4><input type="checkbox" class="js-toggle-editbox" id="edit_variations_price" name="edit_variations_price" value="1" /> <label for="edit_variations_price"><?=__('Edit variations price', self::TEXT_DOMAIN)?></label></h4>


			<div class="col-inner col-edit_variations_price" style="display:none">
				<p class="form-field">
					<label for="price"><?=sprintf(__("Add to base price (%s)", self::TEXT_DOMAIN), get_woocommerce_currency_symbol())?></label>
					<input type="number" placeholder="<?=__('Leave unchanged', self::TEXT_DOMAIN)?>" class="short" value="" id="price" name="price" />
					<?= wc_help_tip( __( "You can type a negative amount to substract from base price.", self::TEXT_DOMAIN ) )?>
				</p>
				<input type="hidden" name="priceaddition" value="1" />
				<!--
				<p class="form-field">
					<input type="radio" name="priceaddition" id="priceaddition_yes" value="1" checked />
					<label for="priceaddition_yes"><?=__('Add to base price', self::TEXT_DOMAIN)?> <?= wc_help_tip( __( "All variations matching the selected attributes will be edited with the below settings.", self::TEXT_DOMAIN ) )?></label>
					<br />
					<input type="radio" name="priceaddition" id="priceaddition_no" value="0">
					<label for="priceaddition_no"><?=__('Set price', self::TEXT_DOMAIN)?> <?= wc_help_tip( __( "All variations matching the selected attributes will be edited with the below settings.", self::TEXT_DOMAIN ) )?></label>
				</p>
				-->
			</div>
		</div>

		<hr />

		<div class="col2">
			<h4><input type="checkbox" class="js-toggle-editbox" id="edit_variations_stock" name="edit_variations_stock" value="1" /> <label for="edit_variations_stock"><?=__("Edit variations stock", self::TEXT_DOMAIN)?></label></h4>

			<div class="col-inner col-edit_variations_stock" style="display:none">

				<p class="form-field">
					<label for="manage_stock"><?=__('Manage stock?', self::TEXT_DOMAIN)?></label>
					<select name="manage_stock" id="manage_stock">
						<option value="1" selected><?=__("Manage stock", self::TEXT_DOMAIN)?></option>
						<option value="0"><?=__("Don't manage stock", self::TEXT_DOMAIN)?></option>
					</select>
				</p>

				<div class="variation_manage_stock">

					<p class="form-field">
						<label for="qty"><?=__('Quantity', self::TEXT_DOMAIN)?></label>
						<input type="number" class="short" placeholder="<?=__('Leave unchanged', self::TEXT_DOMAIN)?>" name="quantity" id="qty" />
					</p>

				</div>
			</div>
		</div>
	</div>

	<input data-text="<?=__("Edit %d matching variation(s)!", self::TEXT_DOMAIN)?>" data-active-text="<?= __('processing...') ?>" data-action="edit" class="js-wvg-process button button-primary button-large wvg_edit_variations" type="button" value="<?=sprintf(__("Edit %d variation(s)!", self::TEXT_DOMAIN), $this->variations_count )?>" />
</div>