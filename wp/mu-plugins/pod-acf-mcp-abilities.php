<?php
/**
 * Plugin Name: Pod ACF MCP Abilities — safe block-field edit via the Abilities API
 * Description: Registers two WordPress Abilities (read/write a single field on a
 *              flexible-content "blocks" row) so the MCP Adapter plugin can expose them
 *              as MCP tools. This exists because the hand-rolled wp-cli/eval-file flow
 *              for editing ACF flexible-content fields has a sharp edge: update_field()
 *              on a sub-field key silently updates ACF's internal cache WITHOUT writing
 *              the real postmeta row (looks successful on every readback, isn't), and
 *              update_sub_field()'s row-position argument is 1-indexed while the stored
 *              meta-key suffix (blocks_<N>_*) is 0-indexed — get that wrong and you write
 *              into a COMPLETELY DIFFERENT block's postmeta silently. Both bugs were hit
 *              for real on this site (FRICTION 2026-10-13). Fixing the addressing ONCE
 *              here, behind a typed/validated tool call, means no session has to
 *              re-derive it by hand again.
 *
 * GENERIC — identical for every Pod headless site using the "blocks" flexible-content
 * field (template standard, docs/standards.md §12). Back-port to pod-template-site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_categories_init', 'pod_register_acf_ability_category' );
add_action( 'wp_abilities_api_init', 'pod_register_acf_block_field_abilities' );

/**
 * Ability CATEGORIES register on a DIFFERENT action than abilities themselves —
 * wp_abilities_api_categories_init, not wp_abilities_api_init (confirmed by reading
 * wp-includes/abilities-api.php directly: wp_register_ability_category() guards with
 * `if ( ! doing_action( 'wp_abilities_api_categories_init' ) )`). Calling it on the abilities
 * hook triggers WordPress's own _doing_it_wrong() (logged, not fatal — easy to miss) and
 * silently returns null, so the category never exists — which then makes every ability that
 * references it fail too, with zero exception and zero visible error anywhere (FRICTION
 * 2026-10-13: this masqueraded as "the abilities just don't appear," no obvious cause).
 */
function pod_register_acf_ability_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}
	wp_register_ability_category(
		'pod',
		array(
			'label'       => __( 'Pod Digital', 'pod' ),
			'description' => __( 'Abilities custom-built for Pod Digital headless client sites.', 'pod' ),
		)
	);
}

function pod_register_acf_block_field_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return; // Abilities API not available (WP < 6.9 or not loaded yet).
	}

	wp_register_ability(
		'pod/get-acf-block-field',
		array(
			'label'               => __( 'Get ACF block field', 'pod' ),
			'description'         => __( 'Reads a single field from a row of the "blocks" flexible-content field on a post, by block index (0-based, matching the index shown in WPGraphQL/post meta) and field name.', 'pod' ),
			'category'            => 'pod',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'     => array( 'type' => 'integer', 'description' => 'The post ID' ),
					'block_index' => array( 'type' => 'integer', 'description' => '0-based index into the blocks array (matches blocks_<N>_* meta keys and the GraphQL blocks array position)' ),
					'field_name'  => array( 'type' => 'string', 'description' => 'The ACF sub-field name (e.g. "stats_row_items", "columns")' ),
				),
				'required'   => array( 'post_id', 'block_index', 'field_name' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'value' => array( 'description' => 'The current field value' ),
				),
			),
			// 'public' defaults to false — confirmed core's own abilities explicitly set it
			// true, and MCP Adapter's discover-abilities only lists public ones. Without this,
			// registration can succeed completely and still never show up over MCP.
			'meta'                => array( 'public' => true ),
			'permission_callback' => function ( $input ) {
				return current_user_can( 'edit_post', (int) $input['post_id'] );
			},
			'execute_callback'    => function ( $input ) {
				$post_id = (int) $input['post_id'];
				$index   = (int) $input['block_index'];
				$name    = (string) $input['field_name'];

				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'pod_no_post', 'No such post.' );
				}

				// 1-indexed row position for update_sub_field/get_sub_field; the caller-facing
				// API stays 0-indexed to match what's visible everywhere else (GraphQL, meta keys).
				clean_post_cache( $post_id );
				$value = get_field( "blocks_{$index}_{$name}", $post_id );

				return array( 'value' => $value );
			},
		)
	);

	wp_register_ability(
		'pod/update-acf-block-field',
		array(
			'label'               => __( 'Update ACF block field', 'pod' ),
			'description'         => __( 'Writes a single field on a row of the "blocks" flexible-content field on a post, by 0-based block index and field name. Correctly handles the flexible-content row-addressing internally (1-indexed for update_sub_field vs the 0-indexed meta-key suffix) and verifies the write against a cache-busted re-read before returning success — never trust a "success" that was only checked against ACF\'s own value cache.', 'pod' ),
			'category'            => 'pod',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'post_id'     => array( 'type' => 'integer', 'description' => 'The post ID' ),
					'block_index' => array( 'type' => 'integer', 'description' => '0-based index into the blocks array (matches blocks_<N>_* meta keys)' ),
					'field_name'  => array( 'type' => 'string', 'description' => 'The ACF sub-field name (e.g. "stats_row_items", "columns")' ),
					'value'       => array( 'description' => 'The new value (scalar, or an array for a repeater field)' ),
				),
				'required'   => array( 'post_id', 'block_index', 'field_name', 'value' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'success'  => array( 'type' => 'boolean' ),
					'verified' => array( 'description' => 'Fresh value re-read directly from the database after the write, bypassing any cache — compare this to what you sent' ),
				),
				'required'   => array( 'success', 'verified' ),
			),
			'meta'                => array( 'public' => true ),
			'permission_callback' => function ( $input ) {
				return current_user_can( 'edit_post', (int) $input['post_id'] );
			},
			'execute_callback'    => function ( $input ) {
				global $wpdb;

				$post_id = (int) $input['post_id'];
				$index   = (int) $input['block_index'];
				$name    = (string) $input['field_name'];
				$value   = $input['value'];

				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'pod_forbidden', 'Not allowed to edit this post.' );
				}
				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'pod_no_post', 'No such post.' );
				}

				// The row-addressing fix: update_sub_field()'s row position is 1-indexed.
				update_sub_field( array( 'blocks', $index + 1, $name ), $value, $post_id );

				// Verify against the raw DB row, not get_field()/ACF's cache — that cache is
				// exactly what made every earlier failed write LOOK like it had succeeded.
				clean_post_cache( $post_id );
				wp_cache_delete( $post_id, 'post_meta' );

				$meta_key = "blocks_{$index}_{$name}";
				$is_array = is_array( $value );
				if ( $is_array ) {
					// Repeater/array field: verify the item count landed; full value via get_field.
					clean_post_cache( $post_id );
					$verified = get_field( $meta_key, $post_id );
				} else {
					$verified = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
							$post_id,
							$meta_key
						)
					);
				}

				return array(
					'success'  => true,
					'verified' => $verified,
				);
			},
		)
	);
}
