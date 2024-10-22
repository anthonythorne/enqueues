<?php
/**
 * Block Editor setup.
 *
 * File Path: src/php/Controller/BlockEditorRegistrationController.php
 *
 * @package Enqueues
 */

// phpcs:disable WordPress.Files.FileName

namespace Enqueues\Controller;

use Enqueues\Base\Main\Controller;
use function Enqueues\asset_find_file_path;
use function Enqueues\is_local;
use function Enqueues\get_translation_domain;

/**
 * Controller responsible for Block Editor related functionality.
 */
class BlockEditorRegistrationController extends Controller {

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
	 * Register Gutenberg blocks by scanning the block directory.
	 */
	public function register_blocks() {
		$directory                  = get_template_directory();
		$block_editor_dist_dir_path = apply_filters( 'enqueues_block_editor_dist_dir', '/dist/block-editor/blocks' );
		$block_editor_dist_dir      = "{$directory}/{$block_editor_dist_dir_path}";

		if ( ! is_dir( $block_editor_dist_dir ) ) {
			if ( is_local() ) {
				wp_die( sprintf( 'Block Editor dist directory %s missing.', $block_editor_dist_dir ), E_USER_ERROR ); // phpcs:ignore
			}
			return;
		}

		$blocks_dirs       = array_filter( glob( "{$block_editor_dist_dir}/*" ), 'is_dir' );
		$block_name_prefix = apply_filters( 'enqueues_block_name_prefix', 'custom' );

		foreach ( $blocks_dirs as $block_dir ) {
			$block_name    = basename( $block_dir );
			$metadata_file = "{$block_editor_dist_dir}/{$block_name}/block.json";

			if ( ! file_exists( $metadata_file ) ) {
				if ( is_local() ) {
					wp_die( sprintf( 'Block metadata file %s is missing.', $metadata_file ), E_USER_ERROR ); // phpcs:ignore
				}
				continue;
			}

			$result = register_block_type( $metadata_file );

			if ( ! $result && is_local() ) {
				wp_die( sprintf( 'Block %s failed to register.', "{$block_name_prefix}/{$block_name}" ), E_USER_ERROR ); // phpcs:ignore
			}
		}
	}

	/**
	 * Register block categories.
	 */
	public function block_categories( $categories, $post ) {

		$directory = get_template_directory();

		$block_editor_categories = apply_filters( 'enqueues_block_editor_categories', [] );

		if ( ! $block_editor_categories ) {
			return $categories;
		}

		foreach ( $block_editor_categories as $category ) {
			// Skip if the category is not properly defined.
			if ( ! isset( $category['slug'] ) || ! isset( $category['title'] ) ) {
				continue;
			}

			if ( isset( $category['icon'] ) ) {
				$icon_path        = $directory . $category['icon'];
				$category['icon'] = get_encoded_svg_icon( $icon_path );
			}

			$category['title'] = __( $category['title'], get_translation_domain() );
			$categories        = array_merge( $categories, [ $category ] );
		}

		return $categories;
	}

	/**
	 * Enqueue assets for blocks
	 *
	 * @param string $type          The type of asset to enqueue (blocks, plugins, or extensions).
	 * @param string $context       The context in which to enqueue the asset (frontend, editor, or view).
	 * @param bool   $register_only Whether to only register the asset or also enqueue it.
	 *
	 * @return void
	 */
	private function enqueue_assets( $type, $context, $register_only = true ) {
		$directory             = get_template_directory();
		$directory_uri         = get_template_directory_uri();
		$block_editor_dist_dir = "{$directory}/dist/block-editor/{$type}";
		$enqueue_asset_dirs    = array_filter( glob( "{$block_editor_dist_dir}/*" ), 'is_dir' );

		foreach ( $enqueue_asset_dirs as $enqueue_asset_dir ) {
			$filename = basename( $enqueue_asset_dir );
			$name     = apply_filters( "enqueues_block_editor_name_{$type}", 'enqueues/' . $filename );

			// Enqueue the CSS bundle.
			$css_filetype = $this->get_filename_from_context( $context, 'css' );
			$css_path     = asset_find_file_path( "dist/block-editor/{$type}/{$filename}", $css_filetype, 'css', $directory );

			if ( $css_path ) {
				wp_register_style( "{$name}-{$css_filetype}", rtrim( $directory_uri, '/' ) . $css_path, [], filemtime( "{$directory}{$css_path}" ) );

				if ( ! $register_only ) {
					wp_enqueue_style( "{$name}-{$css_filetype}" );
				}
			}

			// Enqueue the JS bundle.
			$js_filetype = $this->get_filename_from_context( $context, 'js' );
			$js_path     = asset_find_file_path( "dist/block-editor/{$type}/{$filename}", $js_filetype, 'js', $directory );

			if ( $js_path ) {
				$enqueue_asset_path = "{$directory}/" . ltrim( str_replace( '.js', '.asset.php', $js_path ), '/' );

				if ( file_exists( $enqueue_asset_path ) ) {
					$assets = include $enqueue_asset_path;
					wp_register_script( "{$name}-{$js_filetype}", rtrim( $directory_uri, '/' ) . $js_path, $assets['dependencies'], $assets['version'], true );

					if ( ! $register_only ) {
						wp_enqueue_script( "{$name}-{$js_filetype}" );
					}

					$localized_data     = apply_filters( "enqueues_block_editor_localized_data_{$type}_{$filename}", [] );
					$localized_var_name = apply_filters( "enqueues_block_editor_localized_data_var_name_{$type}_{$filename}", 'customBlockEditor' . ucfirst( $type ) . 'Config' );

					if ( $localized_data ) {
						wp_localize_script( "{$name}-{$js_filetype}", $localized_var_name, $localized_data );
					}
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
