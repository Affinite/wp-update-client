<?php
/**
 * Affinite plugin updater
 *
 * @package affinite-updater
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'AffiniteUpdater' ) ) {
	/**
	 * Affinite Updater class
	 */
	class AffiniteUpdater {
		/**
		 * Server URL
		 *
		 * @var ?string $repository_server Server URL.
		 */
		private ?string $repository_server = 'https://update.affinite.io/';

		/**
		 * Plugin slug
		 *
		 * @var ?string $plugin_slug Plugin slug.
		 */
		private ?string $plugin_slug = null;

		/**
		 * Plugin data
		 *
		 * @var array $plugin_data Plugin data.
		 */
		private array $plugin_data = array();

		/**
		 * Cache key
		 *
		 * @var string $cache_key Cache key.
		 */
		private string $cache_key = 'affinite-updater';

		/**
		 * Class constructor
		 *
		 * @param string  $plugin_slug Plugin slug.
		 * @param ?string $repository_server Repository server URL.
		 */
		public function __construct( string $plugin_slug, ?string $repository_server = null ) {
			$this->set_plugin_data( $plugin_slug );

			if ( null !== $repository_server ) {
				$this->repository_server = $repository_server;
			}

			$this->initialize();
		}

		/**
		 * Set plugin data
		 *
		 * @param string $plugin_slug Plugin slug.
		 *
		 * @throws \RuntimeException Throws RuntimeException on validation fail.
		 */
		public function set_plugin_data( string $plugin_slug ): void {
			if ( preg_match( '/[^a-z0-9_-]/', $plugin_slug ) ) {
				throw new \RuntimeException( esc_attr( sprintf( 'Plugin slug is not valid. Use only a-z, 0-9, _ and - characters. Given value: %s', $plugin_slug ) ) );
			}

			$this->plugin_slug = $plugin_slug;
			$this->cache_key   = sprintf( 'affinite-updater-%s', $this->plugin_slug );

			$this->init_plugin_data();
		}

		/**
		 * Init plugin data
		 *
		 * @return void
		 */
		private function init_plugin_data(): void {
			if ( null === $this->plugin_slug || ! is_admin() ) {
				$this->plugin_data = array();
			} else {
				if ( ! function_exists( 'get_plugin_data' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$this->plugin_data = get_plugin_data( $this->get_plugin_file() );
			}
		}

		/**
		 * Get plugin file from plugin slug
		 *
		 * @return string
		 */
		private function get_plugin_file(): string {
			return sprintf( '%s/%s/%s.php', WP_PLUGIN_DIR, $this->plugin_slug, $this->plugin_slug );
		}

		/**
		 * Initialize
		 *
		 * @return void
		 */
		public function initialize(): void {
			add_filter( 'plugins_api', array( $this, 'plugin_update_info' ), 20, 3 );
			add_filter( 'site_transient_update_plugins', array( $this, 'update_plugin' ) );

			add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
		}

		/**
		 * Retrieve data from update server
		 *
		 * @param false|object|array $result Result.
		 * @param string             $action Action.
		 * @param object             $args Arguments.
		 *
		 * @return object
		 */
		public function plugin_update_info( $result, string $action, object $args ): object {

			if ( 'plugin_information' !== $action || $this->plugin_slug !== $args->slug ) {
				return $result;
			}

			$data = $this->do_request();

			if ( ! $data ) {
				return $result;
			}

			return (object) array(
				'name'           => $data->name,
				'slug'           => $args->slug,
				'version'        => $data->version,
				'author'         => $data->author,
				'author_profile' => $data->author_profile,
				'requires'       => $data->requires,
				'tested'         => $data->tested,
				'requires_php'   => $data->requires_php,
				'download_link'  => $data->download_url,
				'sections'       => array(
					'description'  => $data->sections->description,
					'installation' => $data->sections->installation,
					'changelog'    => $data->sections->changelog,
					'test'         => 'test',
				),
				'banners'        => array(
					'low'  => $data->banners->low,
					'high' => $data->banners->high,
				),
				'external'       => true,
			);
		}

		/**
		 * Send request to update server
		 *
		 * @return ?object
		 */
		private function do_request(): ?object {
			$data = get_transient( $this->cache_key );

			if ( null !== $this->repository_server && false === $data ) {

				$response = wp_remote_get( sprintf( '%s?plugin=%s', $this->repository_server, $this->plugin_slug ) );

				if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || empty( wp_remote_retrieve_body( $response ) ) ) {
					return null;
				}

				try {
					$data = json_decode( wp_remote_retrieve_body( $response ), false, 512, JSON_THROW_ON_ERROR );
				} catch ( \JsonException $e ) {
					$data = array();
				}

				if ( empty( $data ) ) {
					return null;
				}

				set_transient( $this->cache_key, $data, HOUR_IN_SECONDS );
			}

			return $data;
		}

		/**
		 * Update plugin
		 *
		 * @param false|object $transient Transient.
		 *
		 * @return false|object
		 */
		public function update_plugin( $transient ): mixed {
			if ( empty( $transient->checked ) || ! isset( $this->plugin_data['Version'] ) ) {
				return $transient;
			}

			$remote = $this->do_request();

			if ( ! empty( $remote ) && ! isset( $remote->code ) && version_compare( $this->plugin_data['Version'], $remote->version, '<' ) && version_compare( $remote->requires_php, PHP_VERSION, '<=' ) && version_compare( $remote->requires, get_bloginfo( 'version' ), '<=' ) ) {
				$res = (object) array(
					'slug'             => $this->plugin_slug,
					'plugin'           => plugin_basename( $this->get_plugin_file() ),
					'new_version'      => $remote->version,
					'tested'           => $remote->tested,
					'package'          => $remote->download_url,
					'is_valid_license' => $remote->is_valid_license ?? null,
				);

				$transient->response[ $res->plugin ] = $res;
			}

			return $transient;
		}

		/**
		 * Clear cache
		 *
		 * @param \WP_Upgrader $upgrader WP Upgrader object.
		 * @param array        $options Options.
		 *
		 * @return void
		 */
		public function clear_cache( \WP_Upgrader $upgrader, array $options ): void {
			if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
				delete_transient( $this->cache_key );
			}
		}
	}
}
