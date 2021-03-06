<?php
/**
 * Start: Include for phase 2
 * Block Directory REST API: WP_REST_Block_Directory_Controller class
 *
 * @since   5.5.0
 * @package gutenberg
 */

/**
 * Controller which provides REST endpoint for the blocks.
 *
 * This class can be removed when plugin support requires WordPress 5.5.0+.
 *
 * @since 5.5.0
 *
 * @see   WP_REST_Controller
 */
class WP_REST_Block_Directory_Controller extends WP_REST_Controller {

	/**
	 * Constructs the controller.
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
		$this->rest_base = 'block-directory';
	}

	/**
	 * Registers the necessary REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/search',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Checks whether a given request has permission to install and activate plugins.
	 *
	 * @since 5.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|bool True if the request has permission, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error(
				'rest_block_directory_cannot_view',
				__( 'Sorry, you are not allowed to browse the block directory.', 'gutenberg' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Search and retrieve blocks metadata
	 *
	 * @since 5.5.0
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$response = plugins_api(
			'query_plugins',
			array(
				'block'    => $request['term'],
				'per_page' => $request['per_page'],
				'page'     => $request['page'],
			)
		);

		if ( is_wp_error( $response ) ) {
			$response->add_data( array( 'status' => 500 ) );

			return $response;
		}

		$result = array();

		foreach ( $response->plugins as $plugin ) {
			if ( $this->find_plugin_for_slug( $plugin['slug'] ) ) {
				continue;
			}

			$data     = $this->prepare_item_for_response( $plugin, $request );
			$result[] = $this->prepare_response_for_collection( $data );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Parse block metadata for a block, and prepare it for an API repsonse.
	 *
	 * @since 5.5.0
	 *
	 * @param array           $plugin  The plugin metadata.
	 * @param WP_REST_Request $request Request object.
	 *
	 * @return WP_Error|WP_REST_Response Response object on success, or WP_Error object on failure.
	 */
	public function prepare_item_for_response( $plugin, $request ) {
		// There might be multiple blocks in a plugin. Only the first block is mapped.
		$block_data = reset( $plugin['blocks'] );

		// A data array containing the properties we'll return.
		$block = array(
			'name'                => $block_data['name'],
			'title'               => ( $block_data['title'] ? $block_data['title'] : $plugin['name'] ),
			'description'         => wp_trim_words( $plugin['description'], 30, '...' ),
			'id'                  => $plugin['slug'],
			'rating'              => $plugin['rating'] / 20,
			'rating_count'        => intval( $plugin['num_ratings'] ),
			'active_installs'     => intval( $plugin['active_installs'] ),
			'author_block_rating' => $plugin['author_block_rating'] / 20,
			'author_block_count'  => intval( $plugin['author_block_count'] ),
			'author'              => wp_strip_all_tags( $plugin['author'] ),
			'icon'                => ( isset( $plugin['icons']['1x'] ) ? $plugin['icons']['1x'] : 'block-default' ),
			'assets'              => array(),
			'last_updated'        => $plugin['last_updated'],
			'humanized_updated'   => sprintf(
				/* translators: %s: Human-readable time difference. */
				__( '%s ago', 'gutenberg' ),
				human_time_diff( strtotime( $plugin['last_updated'] ) )
			),
		);

		foreach ( $plugin['block_assets'] as $asset ) {
			// Allow for fully qualified URLs in future.
			if ( 'https' === wp_parse_url( $asset, PHP_URL_SCHEME ) && ! empty( wp_parse_url( $asset, PHP_URL_HOST ) ) ) {
				$block['assets'][] = esc_url_raw(
					$asset,
					array( 'https' )
				);
			} else {
				$block['assets'][] = esc_url_raw(
					add_query_arg( 'v', strtotime( $block['last_updated'] ), 'https://ps.w.org/' . $plugin['slug'] . $asset ),
					array( 'https' )
				);
			}
		}

		$this->add_additional_fields_to_object( $block, $request );

		$response = new WP_REST_Response( $block );
		$response->add_links( $this->prepare_links( $plugin ) );

		return $response;
	}

	/**
	 * Generates a list of links to include in the response for the plugin.
	 *
	 * @since 5.5.0
	 *
	 * @param array $plugin The plugin data from WordPress.org.
	 *
	 * @return array
	 */
	protected function prepare_links( $plugin ) {
		$links = array(
			'https://api.w.org/install-plugin' => array(
				'href' => add_query_arg( 'slug', urlencode( $plugin['slug'] ), rest_url( 'wp/v2/plugins' ) ),
			),
		);

		$plugin_file = $this->find_plugin_for_slug( $plugin['slug'] );

		if ( $plugin_file ) {
			$links['https://api.w.org/plugin'] = array(
				'href'       => rest_url( 'wp/v2/plugins/' . substr( $plugin_file, 0, - 4 ) ),
				'embeddable' => true,
			);
		}

		return $links;
	}

	/**
	 * Finds an installed plugin for the given slug.
	 *
	 * @since 5.5.0
	 *
	 * @param string $slug The WordPress.org directory slug for a plugin.
	 *
	 * @return string The plugin file found matching it.
	 */
	protected function find_plugin_for_slug( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_files = get_plugins( '/' . $slug );

		if ( ! $plugin_files ) {
			return '';
		}

		$plugin_files = array_keys( $plugin_files );

		return $slug . '/' . reset( $plugin_files );
	}

	/**
	 * Retrieves the theme's schema, conforming to JSON Schema.
	 *
	 * @since 5.5.0
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'block-directory-item',
			'type'       => 'object',
			'properties' => array(
				'name'                => array(
					'description' => __( 'The block name, in namespace/block-name format.', 'gutenberg' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'title'               => array(
					'description' => __( 'The block title, in human readable format.', 'gutenberg' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'description'         => array(
					'description' => __( 'A short description of the block, in human readable format.', 'gutenberg' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'id'                  => array(
					'description' => __( 'The block slug.', 'gutenberg' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'rating'              => array(
					'description' => __( 'The star rating of the block.', 'gutenberg' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
				),
				'rating_count'        => array(
					'description' => __( 'The number of ratings.', 'gutenberg' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
				),
				'active_installs'     => array(
					'description' => __( 'The number sites that have activated this block.', 'gutenberg' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'author_block_rating' => array(
					'description' => __( 'The average rating of blocks published by the same author.', 'gutenberg' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
				),
				'author_block_count'  => array(
					'description' => __( 'The number of blocks published by the same author.', 'gutenberg' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
				),
				'author'              => array(
					'description' => __( 'The WordPress.org username of the block author.', 'gutenberg' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'icon'                => array(
					'description' => __( 'The block icon.', 'gutenberg' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'view' ),
				),
				'humanized_updated'   => array(
					'description' => __( 'The date when the block was last updated, in fuzzy human readable format.', 'gutenberg' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
				),
				'assets'              => array(
					'description' => __( 'An object representing the block CSS and JavaScript assets.', 'gutenberg' ),
					'type'        => 'array',
					'context'     => array( 'view' ),
					'readonly'    => true,
					'items'       => array(
						'type'   => 'string',
						'format' => 'uri',
					),

				),

			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Retrieves the search params for the blocks collection.
	 *
	 * @since 5.5.0
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		$query_params = parent::get_collection_params();

		$query_params['context']['default'] = 'view';

		$query_params['term'] = array(
			'description' => __( 'Limit result set to blocks matching the search term.', 'gutenberg' ),
			'type'        => 'string',
			'required'    => true,
			'minLength'   => 1,
		);

		unset( $query_params['search'] );

		/**
		 * Filter collection parameters for the block directory controller.
		 *
		 * @since 5.5.0
		 *
		 * @param array $query_params JSON Schema-formatted collection parameters.
		 */
		return apply_filters( 'rest_block_directory_collection_params', $query_params );
	}
}
