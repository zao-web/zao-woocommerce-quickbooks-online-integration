<tr>
<?php if ( 'error' === $item['id'] ) { ?>
	<td colspan="2" class="error"><?php echo $item['name']; ?></td>
<?php } elseif ( ! empty( $item['taken'] ) ) { $wp_object = $this->get_wp_object( $item['taken'] ); ?>
	<td colspan="2"><strike><?php echo $item['name']; ?></strike> <?php printf( esc_attr( $this->text_result_error() ), '<a href="' . $this->get_wp_edit_url( $wp_object ) . '">' . $this->get_wp_name( $wp_object ) . '</a>' ); ?></td>
<?php } else { ?>
	<th scope="row" class="check-column">
		<label class="screen-reader-text" for="cb-select-<?php echo $item['id']; ?>"><?php echo $item['name']; ?></label>
		<input id="cb-select-<?php echo $item['id']; ?>" type="checkbox" class="zwqoi-result-item" name="<?php echo $this->import_query_var; ?>[]" value="<?php echo $item['id']; ?>">
	</th>
	<td><label for="cb-select-<?php echo $item['id']; ?>"><?php echo $item['name']; ?></label></td>
<?php } ?>
</tr>
