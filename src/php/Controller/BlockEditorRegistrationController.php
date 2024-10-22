<?php
/**
 * Block Editor setup.
 *
 * File Path: src/php/Controller/BlockEditorRegistrationController.php
 *
 * @package Enqueues
 */

// phpcs:disable WordPress.Files.FileName

namespace CareToChange\Core\Controller;

use CareToChange\Core\Base\Main\Controller;
use function Enqueues\asset_find_file_path;
use function Enqueues\is_local;

/**
 * Controller responsible for Block Editor related functionality.
 */
class BlockEditorRegistrationController extends Controller {

	const TRANSLATION_DOMAIN = 'caretochange';

	const BLOCK_NAME_PREFIX = 'caretochange';

	const BLOCK_CATEGORIES = [ 
		[ 
			'slug'  => 'caretochange',
			'title' => 'Care to Change',
			'icon'  => 'dist/images/caretochange-white-logo.svg', // Path to icon or use dashicons.
		],
	];

	/**
	 * Register hooks.
	 */
	public function set_up() {
		// Hooks to register blocks, categories and plugins.
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_filter( 'block_categories_all', [ $this, 'block_categories' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_blocks_frontend' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_plugins_frontend' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_plugins_admin' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_extensions_frontend' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_extensions_admin' ] );
	}

	/**
	 * Register blocks.
	 */
	public function register_blocks() {

		$directory             = get_template_directory();
		$directory_uri         = get_template_directory_uri();
		$block_editor_dist_dir = $directory . '/dist/block-editor/blocks';

		if ( ! is_dir( $block_editor_dist_dir ) ) {
			if ( is_local() ) {
				wp_die( sprintf( 'Block Editor dist dir %s missing.', $block_editor_dist_dir ), E_USER_ERROR ); // phpcs:ignore
			}
			return;
		}

		$blocks_dirs = array_filter( glob( $block_editor_dist_dir . '/*' ), 'is_dir' );

		foreach ( $blocks_dirs as $block_dir ) {
			$block_name = self::BLOCK_NAME_PREFIX . '/' . basename( $block_dir );

			// Path to the block metadata file in the dist directory.
			$metadata_file = $block_editor_dist_dir . '/' . basename( $block_dir ) . '/block.json';

			if ( ! file_exists( $metadata_file ) ) {
				if ( is_local() ) {
					wp_die( sprintf( 'Block Editor block metadata file %s is missing.', $metadata_file ), E_USER_ERROR ); // phpcs:ignore
				}
				continue;
			}

			$result = register_block_type( $metadata_file );

			if ( ! $result && is_local() ) {
				wp_die( sprintf( 'Block Editor block failed to register %s.', $block_name ), E_USER_ERROR ); // phpcs:ignore
			}
		}
	}

	/**
	 * Register block categories.
	 */
	public function block_categories( $categories, $post ) {

		$directory = get_template_directory();
		$icon      = null;

		foreach ( self::BLOCK_CATEGORIES as $category ) {
			if ( isset( $category['icon'] ) ) {

				$icon = $category['icon'];

				$svg_file = $directory . $icon;

				if ( file_exists( $svg_file ) ) {

					// Load the contents of the SVG file
					$svg_content = file_get_contents( $svg_file ); // phpcs:ignore

					if ( $svg_content ) {

						// Base64 encode the contents
						$encoded_data = base64_encode( $svg_content ); // phpcs:ignore

						// Embed the base64 encoded data into an image tag
						$icon = '<img src="data:image/svg+xml;base64,' . $encoded_data . '" alt="Custom Icon" width="20" height="20">';
					}
				}

				$category['title'] = __( $category['title'], self::TRANSLATION_DOMAIN ); // phpcs:ignore
				$category['icon']  = $icon;
			}

			$categories = array_merge( $categories, [ $category ] );
		}

		return $categories;
	}

	/**
	 * Enqueue assets for a given type and context.
	 *
	 * @param string $type    The type of asset to enqueue (plugins or extensions).
	 * @param string $context The context in which to enqueue the asset (frontend or admin).
	 */
	private function enqueue_assets( $type, $context, $register_only = true ) {

		$directory             = get_template_directory();
		$directory_uri         = get_template_directory_uri();
		$block_editor_dist_dir = $directory . "/dist/block-editor/{$type}";
		$enqueue_asset_dirs    = array_filter( glob( $block_editor_dist_dir . '/*' ), 'is_dir' );

		foreach ( $enqueue_asset_dirs as $enqueue_asset_dir ) {

			$filename = basename( $enqueue_asset_dir );
			$name     = 'caretochange/' . $filename;

			// Enqueue the CSS bundle for the asset.
			$css_filetype = $this->get_filename_from_context( $context, 'css' );
			$css_path     = asset_find_file_path( "dist/block-editor/{$type}/{$filename}", $css_filetype, 'css', $directory );

			if ( $css_path ) {
				wp_register_style( "{$name}-{$css_filetype}", rtrim( $directory_uri, '/' ) . $css_path, 'all', filemtime( $directory . $css_path ) );

				if ( ! $register_only ) {
					wp_enqueue_script( "{$name}-{$css_filetype}" );
				}
			}

			// Enqueue the JS bundle for the asset.
			$js_filetype = $this->get_filename_from_context( $context, 'js' );
			$js_path     = asset_find_file_path( "dist/block-editor/{$type}/{$filename}", $js_filetype, 'js', $directory );

			if ( $js_path ) {
				$enqueue_asset_path = $directory . '/' . ltrim( str_replace( '.js', '.asset.php', $js_path ), '/' );

				if ( file_exists( $enqueue_asset_path ) ) {
					$assets = include $enqueue_asset_path;

					wp_register_script( "{$name}-{$js_filetype}", rtrim( $directory_uri, '/' ) . $js_path, $assets['dependencies'], $assets['version'], true );

					if ( ! $register_only ) {
						wp_enqueue_script( "{$name}-{$js_filetype}" );
					}

					$localized_data     = 'plugins' === $type ? $this->get_localized_plugin_params() : $this->get_localized_extension_params();
					$localized_var_name = 'customBlockEditor' . ucfirst( $type ) . 'Config';
					wp_localize_script( "{$name}-{$js_filetype}", $localized_var_name, $localized_data );
				} elseif ( is_local() ) {
					wp_die( sprintf( 'Run npm build for the Block Editor asset files, the %s file is missing.', $enqueue_asset_path ), E_USER_ERROR ); // phpcs:ignore
				}
			}
		}
	}

	/**
	 * Enqueue assets for blocks on the frontend.
	 */
	public function enqueue_blocks_frontend() {
		$this->enqueue_assets( 'blocks', 'frontend', true );
		$this->enqueue_assets( 'blocks', 'view', true );
	}

	/**
	 * Enqueue assets for plugins on the frontend.
	 */
	public function enqueue_plugins_frontend() {
		$this->enqueue_assets( 'plugins', 'frontend', true );
		$this->enqueue_assets( 'plugins', 'view', true );
	}

	/**
	 * Enqueue assets for plugins in the block editor.
	 */
	public function enqueue_plugins_admin() {
		$this->enqueue_assets( 'plugins', 'editor', false );
	}

	/**
	 * Enqueue assets for extensions on the frontend.
	 */
	public function enqueue_extensions_frontend() {
		$this->enqueue_assets( 'extensions', 'frontend', true );
		$this->enqueue_assets( 'extensions', 'view', true );
	}

	/**
	 * Enqueue assets for extensions in the block editor.
	 */
	public function enqueue_extensions_admin() {
		$this->enqueue_assets( 'extensions', 'editor', false );
	}

	/**
	 * Returns variables used within the JS.
	 *
	 * @return array
	 */
	public function get_localized_plugin_params() {

		$params = [];

		return $params;
	}

	/**
	 * Returns variables used within the JS.
	 *
	 * @return array
	 */
	public function get_localized_extension_params() {

		$params = [];

		return $params;
	}

	/**
	 * Get the filename based on the context and type.
	 *
	 * @param string $context The context in which to enqueue the asset (frontend or admin).
	 * @param string $type    The type of asset to enqueue (plugins or extensions).
	 *
	 * @return string
	 */
	protected function get_filename_from_context( $context, $type ) {

		switch ( $context ) {
			case 'editor':
				return 'index';
			case 'view':
				return 'view';
			default:
				return 'js' === $type ? 'script' : 'style';
		}
	}
}
