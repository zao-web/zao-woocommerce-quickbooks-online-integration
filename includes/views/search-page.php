<style type="text/css">
	li.error {
		color: #dc3232;
	}
	.wp-core-ui .qb-import-button-wrap {
		margin: 10px 0 5px;
		display: block;
	}
</style>

<?php settings_errors( $this->admin_page_slug . '-notices' ); ?>

<div class="wrap qbo-search-wrap">
	<?php self::admin_page_title(); ?>

	<?php if ( self::$api ) { ?>
		<p class="qb-company-name"><?php printf( __( 'Your company: <em>%s</em> ', 'zwqoi' ), self::company_name() ); ?></p>
	<?php } ?>

	<?php if ( ! function_exists( 'qbo_connect_ui' ) || ! qbo_connect_ui()->settings ) { ?>

		<p><?php _e( 'Something went wrong. We cannot find the Quickbooks Connect UI plugin.', 'zwqoi' ); ?></p>

	<?php } elseif ( ! self::$api ) { ?>

		<p><?php printf( __( 'You need to <a href="%s">initate the Quickbooks connection</a>.', 'zwqoi' ), qbo_connect_ui()->settings->settings_url() ); ?></p>

	<?php } else { ?>

		<form method="POST" id="qbo-search-form" action="<?php echo esc_url( $this->settings_url() ); ?>">
			<?php wp_nonce_field( $this->admin_page_slug, $this->admin_page_slug ); ?>
			<?php do_action( 'zwqoi_search_page_form', $this ); ?>
			<input class="large-text" placeholder="<?php echo esc_attr( $this->get_text( 'search_placeholder' ) ); ?>" type="text" name="search_term" value="<?php echo esc_attr( self::_param( 'search_term' ) ); ?>">
			<p><?php _e( 'Search by:', 'zwqoi' ); ?>
				&nbsp;
				<label><input type="radio" name="search_type" value="name" <?php checked( ! isset( $_POST['search_type'] ) || self::_param_is( 'search_type', 'name' ) ); ?> /> <?php $this->get_text( 'object_single_name_name', true ); ?></label>
				&nbsp;
				<label><input type="radio" name="search_type" value="id" <?php checked( self::_param_is( 'search_type', 'id' ) ); ?>/> <?php $this->get_text( 'object_id_name', true ); ?></label>
			</p>
			<?php submit_button( $this->get_text( 'submit_button' ) ); ?>
		</form>

	<?php } ?>

	<?php if ( $this->has_search() ) { ?>
		<h3><?php printf( __( 'Search Results for &ldquo;%s&rdquo; (found <strong>%d</strong> result): ', 'zwqoi' ), esc_attr( wp_unslash( $_POST['search_term'] ) ), $this->results_count ); ?></h3>
		<p class="description"><?php $this->get_text( 'search_help', true ); ?></p>
		<ul>
			<?php foreach ( $this->search_results as $result ) { ?>
				<?php echo $this->output_result_item( $result ); ?>
			<?php } ?>
		</ul>
	<?php } ?>

</div>
