<?php
/**
 * A module to check changes before theme updates.
 *
 * @link       https://themeisle.com
 * @since      1.0.0
 *
 * @package    Theme_Update_Check_OBFX_Module
 */

/**
 * The class defines a new module to be used by Orbit Fox plugin.
 *
 * @package    Theme_Update_Check_OBFX_Module
 * @author     Themeisle <friends@themeisle.com>
 */
class Theme_Update_Check_OBFX_Module extends Orbit_Fox_Module_Abstract {

	/**
	 * @var string ThemeCheck api endpoint.
	 */
	private $themecheck_url = 'http://localhost/wp-minions/api/themecheck/check';

	/**
	 * Test_OBFX_Module constructor.
	 *
	 * @since   1.0.0
	 * @access  public
	 */
	public function __construct() {
		parent::__construct();
		$this->name           = __( 'Theme Update Check', 'themeisle-companion' );
		$this->description    = __( 'A module to notify you how a theme update will impact your web page.', 'themeisle-companion' );
	}

	/**
	 * Method to determine if the module is enabled or not.
	 *
	 * @since   1.0.0
	 * @access  public
	 * @return bool
	 */
	public function enable_module() {
		return true;
	}

	/**
	 * The method for the module load logic.
	 *
	 * @since   1.0.0
	 * @access  public
	 * @return mixed
	 */
	public function load() {
		return;
	}

	/**
	 * Method that returns an array of scripts and styles to be loaded
	 * for the front end part.
	 *
	 * @since   1.0.0
	 * @access  public
	 * @return array
	 */
	public function public_enqueue() {
		return array();
	}

	/**
	 * Method that returns an array of scripts and styles to be loaded
	 * for the admin part.
	 *
	 * @since   1.0.0
	 * @access  public
	 * @return array
	 */
	public function admin_enqueue() {
		return array();
	}

	/**
	 * Method to define the options fields for the module
	 *
	 * @since   1.0.0
	 * @access  public
	 * @return array
	 */
	public function options() {
		return array(
			array(
				'name' => 'checks',
				'default' => array()
			)
		);
	}

	/**
	 * Method to define actions and filters needed for the module.
	 *
	 * @codeCoverageIgnore
	 *
	 * @since   1.0.0
	 * @access  public
	 */
	public function hooks() {
		$this->loader->add_filter( 'pre_set_site_transient_update_themes', $this, 'check_for_update_filter' );
		$key = $this->get_local_slug();
		add_filter( "wp_prepare_themes_for_js" , array( $this, 'theme_update_message' ) );
	}

	public function theme_update_message( $themes ) {
		$info = $this->is_update_available( get_site_transient('update_themes') );

		if( $info !== false ) {
			//var_dump( $themes[$info['theme']] );
			$transient = get_site_transient('update_themes');
			$version_info = sprintf(
				'<p><strong>' . __( 'There is a new version of %1$s available. <a href="%2$s" %3$s>View version %4$s details</a>.' ) . '</strong></p>',
				$themes[$info['theme']]['name'],
				$transient->response[$info['theme']]['url'],
				sprintf( 'class="thickbox open-plugin-details-modal" aria-label="%s"',
					/* translators: 1: theme name, 2: version number */
					esc_attr( sprintf( __( 'View %1$s version %2$s details' ), $themes[$info['theme']]['name'], $transient->response[$info['theme']]['new_version'] ) )
				),
				$transient->response[$info['theme']]['new_version']
			);

			$changes = $transient->response[$info['theme']]['changes'];
			$changes_info = '';
			if ( $changes['status_code'] === '200' ) {
				$changes_info = sprintf(
					'<p><strong>' . __( 'There is a difference of %1$s%% when updating to version %2$s from %3$s. <a href="%4$s" target="_blank">View changes details</a>.' ) . '</strong> -- auto generated by OrbitFox</p>',
					$changes['data']['global_diff'],
					$transient->response[$info['theme']]['new_version'],
					$themes[$info['theme']]['version'],
					$changes['data']['gallery']
				);
			}

			$themes[$info['theme']]['update'] = $version_info . $changes_info;
		}
		return $themes;
	}

	/**
	 * The filter that checks if there are updates to the theme or plugin
	 * using the WP License Manager API.
	 *
	 * @since   1.0.0
	 * @access  public
	 * @param   mixed $transient    The transient used for WordPress
	 *                              theme / plugin updates.
	 * @return mixed        The transient with our (possible) additions.
	 */
	public function check_for_update_filter( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$info = $this->is_update_available( $transient );
		if ( $info !== false ) {
			$changes = $this->changes_check( $info );
			$transient->response[$this->get_local_slug()]['changes'] = $changes;
		}

		return $transient;
	}

	private function changes_check( $info ) {

		$request_data = array(
			'theme' => $info['theme'],
			'current_ver' => $this->get_local_version(),
			'next_ver' => $info['new_version'],
		);

		$payload_sha = hash_hmac( 'sha256', json_encode( $request_data ), $this->themecheck_url );

		$checks = $this->get_option( 'checks' );
		if ( ! empty( $checks ) ) {
			if( isset( $checks[$payload_sha] ) && ! empty( $checks[$payload_sha] ) ) {
				return $checks[$payload_sha];
			}
		}

		$response = wp_remote_post( $this->themecheck_url, array(
				'method' => 'POST',
				'timeout' => 45,
				'body' => $request_data,
			)
		);

		$response_data = json_decode( $response['body'], true );

		if ( $request_data['status_code'] === '200' ) {
			$option_data = array(
				$payload_sha => $response_data
			);

			$this->set_option( 'checks', $option_data );
		}

		return $response_data;
	}

	private function is_update_available( $transient ) {
		$slug = $this->get_local_slug();
		if ( version_compare( $transient->response[$slug]['new_version'], $this->get_local_version(), '>' ) ) {
			return $transient->response[$slug];
		}

		return false;
	}

	private function get_local_slug() {
		$theme_data = wp_get_theme();
		return  $theme_data->get_stylesheet();
	}

	private function get_local_version() {
		$theme_data = wp_get_theme();
		return $theme_data->Version;
	}
}