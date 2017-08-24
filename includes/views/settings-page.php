<div class="wrap cmb2-options-page option-<?php echo self::KEY; ?>">
	<?php \Zao\WC_QBO_Integration\Services\UI_Base::admin_page_title(); ?>
	<?php if ( self::should_show_settings() ) : ?>
		<form class="cmb-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" id="<?php echo $hookup->cmb->cmb_id; ?>" enctype="multipart/form-data" encoding="multipart/form-data">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::KEY ); ?>">
			<?php $hookup->options_page_metabox(); ?>
			<?php submit_button( esc_attr( $hookup->cmb->prop( 'save_button' ) ), 'primary', 'submit-cmb' ); ?>
		</form>
	<?php else : ?>
		<p class="warning"><?php printf( __( 'Please connect to your QuickBooks App on the <a href="%s">API Connect page</a>.', 'zwqoi' ), self::api_settings_page_url() ); ?></p>
	<?php endif; ?>
</div>
