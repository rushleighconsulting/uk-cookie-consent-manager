<?php
/**
 * WordPress administration experience.
 *
 * @package UCCM
 */

namespace UCCM;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Release 1 settings, inventory and evidence screens.
 */
final class Admin {

	/**
	 * Top-level menu slug.
	 */
	private const MENU_SLUG = 'uccm';

	/**
	 * Register administration hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_uccm_save_settings', array( self::class, 'save_settings' ) );
		add_action( 'admin_post_uccm_check_updates', array( self::class, 'check_updates' ) );
		add_action( 'admin_post_uccm_dismiss_operational_alert', array( self::class, 'dismiss_operational_alert' ) );
		add_action( 'admin_post_uccm_save_blocking_rules', array( self::class, 'save_blocking_rules' ) );
		add_action( 'admin_post_uccm_save_scan_settings', array( self::class, 'save_scan_settings' ) );
		add_action( 'admin_post_uccm_run_scan', array( self::class, 'run_scan' ) );
		add_action( 'admin_post_uccm_cancel_scan', array( self::class, 'cancel_scan' ) );
		add_action( 'admin_post_uccm_resume_scan', array( self::class, 'resume_scan' ) );
		add_action( 'wp_ajax_uccm_process_scan_batch', array( self::class, 'process_scan_batch' ) );
		add_action( 'wp_ajax_uccm_browser_scan_observations', array( self::class, 'browser_scan_observations' ) );
		add_action( 'wp_ajax_nopriv_uccm_post_password_bootstrap', array( self::class, 'post_password_bootstrap' ) );
		add_action( 'admin_post_uccm_review_scan_finding', array( self::class, 'review_scan_finding' ) );
		add_action( 'admin_post_uccm_save_inventory', array( self::class, 'save_inventory' ) );
		add_action( 'admin_post_uccm_export_inventory', array( self::class, 'export_inventory' ) );
	}

	/**
	 * Return the ten-screen administration contract.
	 *
	 * @return array<string, array{title: string, capability: string, callback: callable}>
	 */
	public static function screens(): array {
		return array(
			self::MENU_SLUG   => array(
				'title'      => __( 'Overview', 'rushleigh-cookie-choices' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_overview' ),
			),
			'uccm-banner'     => array(
				'title'      => __( 'Banner', 'rushleigh-cookie-choices' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_banner' ),
			),
			'uccm-categories' => array(
				'title'      => __( 'View Categories', 'rushleigh-cookie-choices' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_categories' ),
			),
			'uccm-blocking'   => array(
				'title'      => __( 'Script Blocking', 'rushleigh-cookie-choices' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_blocking' ),
			),
			'uccm-inventory'  => array(
				'title'      => __( 'Cookie Inventory', 'rushleigh-cookie-choices' ),
				'capability' => 'manage_uccm_inventory',
				'callback'   => array( self::class, 'render_inventory' ),
			),
			'uccm-scans'      => array(
				'title'      => __( 'Scans', 'rushleigh-cookie-choices' ),
				'capability' => 'run_uccm_scans',
				'callback'   => array( self::class, 'render_scans' ),
			),
			'uccm-consents'   => array(
				'title'      => __( 'Consent Records', 'rushleigh-cookie-choices' ),
				'capability' => 'view_uccm_consents',
				'callback'   => array( self::class, 'render_consents' ),
			),
			'uccm-privacy'    => array(
				'title'      => __( 'Privacy', 'rushleigh-cookie-choices' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_privacy' ),
			),
			'uccm-advanced'   => array(
				'title'      => __( 'Advanced', 'rushleigh-cookie-choices' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_advanced' ),
			),
			'uccm-help'       => array(
				'title'      => __( 'Help', 'rushleigh-cookie-choices' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_help' ),
			),
		);
	}

	/**
	 * Register the top-level menu and all screens.
	 */
	public static function register_menu(): void {
		$screens  = self::screens();
		$overview = $screens[ self::MENU_SLUG ];
		add_menu_page(
			__( 'Rushleigh Cookie Choices', 'rushleigh-cookie-choices' ),
			__( 'Cookie Consent', 'rushleigh-cookie-choices' ),
			$overview['capability'],
			self::MENU_SLUG,
			$overview['callback'],
			'dashicons-shield-alt',
			81
		);

		foreach ( $screens as $slug => $screen ) {
			add_submenu_page(
				self::MENU_SLUG,
				$screen['title'],
				$screen['title'],
				$screen['capability'],
				$slug,
				$screen['callback']
			);
		}
	}

	/**
	 * Persist banner, privacy or advanced settings.
	 */
	public static function save_settings(): void {
		self::require_capability( 'manage_uccm_settings' );
		$section = self::request_value( $_POST, 'section' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below.
		check_admin_referer( 'uccm_save_' . $section );
		$submitted = isset( $_POST['uccm'] ) && is_array( $_POST['uccm'] ) ? wp_unslash( $_POST['uccm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Field-level validation follows.
		$inherit   = self::submitted_inheritance();

		if ( 'banner' === $section ) {
			$style_input = array_intersect_key(
				$submitted,
				array_fill_keys( array_keys( Settings::banner_style_defaults() ), true )
			);

			if ( ! empty( $_POST['reset_banner_style'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
				$style_input = Settings::banner_style_defaults();
			}

			$validated_style = Settings::validate_banner_style( $style_input );

			if ( is_wp_error( $validated_style ) ) {
				wp_die( esc_html( $validated_style->get_error_message() ), '', array( 'response' => 400 ) );
			}

			Settings::update(
				array_merge(
					$validated_style,
					array(
						'consent_lifetime_days'  => $submitted['consent_lifetime_days'] ?? 180,
						'consent_policy_version' => $submitted['consent_policy_version'] ?? Consent_State::POLICY_VERSION,
						'default_content_locale' => $submitted['default_content_locale'] ?? 'en_GB',
						'language_content'       => $submitted['language_content'] ?? array(),
					)
				),
				$inherit
			);
		} elseif ( 'privacy' === $section ) {
			$privacy_settings = array(
				'retention_days'      => $submitted['retention_days'] ?? 365,
				'store_full_ip'       => $submitted['store_full_ip'] ?? false,
				'trust_proxy_headers' => $submitted['trust_proxy_headers'] ?? false,
			);

			if ( ! empty( $submitted['trust_proxy_headers'] ) && array_key_exists( 'trusted_proxy_ips', $submitted ) ) {
				$privacy_settings['trusted_proxy_ips'] = $submitted['trusted_proxy_ips'];
			}

			Settings::update( $privacy_settings, $inherit );
		} elseif ( 'advanced' === $section ) {
			Settings::update(
				array(
					'error_email_enabled'             => $submitted['error_email_enabled'] ?? false,
					'error_email_suppression_minutes' => $submitted['error_email_suppression_minutes'] ?? Settings::DEFAULT_ERROR_EMAIL_SUPPRESSION_MINUTES,
				),
				$inherit
			);

			if ( ! Multisite::is_network_active() ) {
				update_option( 'uccm_delete_data_on_uninstall', ! empty( $submitted['delete_data_on_uninstall'] ), false );
			}
		} else {
			wp_die( esc_html__( 'The settings section is invalid.', 'rushleigh-cookie-choices' ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-' . $section, 'saved' );
	}

	/**
	 * Run an authenticated update check immediately.
	 */
	public static function check_updates(): void {
		self::require_capability( 'update_plugins' );
		check_admin_referer( 'uccm_check_updates' );
		Secure_Updater::refresh();
		self::redirect( 'uccm-advanced', 'updates-checked' );
	}

	/**
	 * Dismiss one capability- and nonce-protected dashboard occurrence.
	 */
	public static function dismiss_operational_alert(): void {
		self::require_capability( 'manage_uccm_settings' );
		$alert_id = self::request_value( $_POST, 'alert_id' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below.
		check_admin_referer( 'uccm_dismiss_operational_alert_' . $alert_id );
		Operational_Alerts::dismiss( $alert_id );
		wp_safe_redirect( admin_url() );
		exit;
	}


	/**
	 * Persist explicitly declared resource rules from the guided editor or JSON.
	 */
	public static function save_blocking_rules(): void {
		self::require_capability( 'manage_uccm_settings' );
		check_admin_referer( 'uccm_save_blocking' );
		$submitted_rows = isset( $_POST['uccm_rules'] ) && is_array( $_POST['uccm_rules'] )
			? wp_unslash( $_POST['uccm_rules'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Field-level validation follows.
			: null;
		$rules          = is_array( $submitted_rows )
			? self::sanitize_blocking_rule_rows( $submitted_rows )
			: self::sanitize_blocking_rules( self::request_value( $_POST, 'rules' ) );

		if ( is_wp_error( $rules ) ) {
			wp_die( esc_html( $rules->get_error_message() ), '', array( 'response' => 400 ) );
		}

		update_option( Resource_Rules::OPTION_NAME, $rules, false );
		self::redirect( 'uccm-blocking', 'saved' );
	}

	/**
	 * Validate explicit resource rules submitted as a JSON object.
	 *
	 * @param string $json JSON rule map.
	 * @return array<string, array<string, string>>|\WP_Error
	 */
	public static function sanitize_blocking_rules( string $json ): array|\WP_Error {
		$object  = json_decode( $json );
		$decoded = json_decode( $json, true );

		if ( ! is_object( $object ) || ! is_array( $decoded ) ) {
			return new \WP_Error( 'uccm_invalid_rules_json', __( 'Advanced blocking rules must be a JSON object, such as {}.', 'rushleigh-cookie-choices' ) );
		}

		return self::sanitize_blocking_rule_map( $decoded );
	}

	/**
	 * Validate rows submitted by the guided rule editor.
	 *
	 * @param array<int|string, mixed> $submitted_rows Submitted rule rows.
	 * @return array<string, array<string, string>>|\WP_Error
	 */
	public static function sanitize_blocking_rule_rows( array $submitted_rows ): array|\WP_Error {
		$rule_map = array();

		foreach ( array_values( $submitted_rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				return self::blocking_rule_error(
					'uccm_invalid_blocking_rule',
					__( 'A blocking rule has an invalid format.', 'rushleigh-cookie-choices' ),
					$index,
					'rule'
				);
			}

			$id       = trim( (string) ( $row['id'] ?? '' ) );
			$type     = trim( (string) ( $row['type'] ?? '' ) );
			$category = trim( (string) ( $row['category'] ?? '' ) );
			$handle   = trim( (string) ( $row['handle'] ?? '' ) );
			$source   = trim( (string) ( $row['source'] ?? '' ) );
			$title    = trim( (string) ( $row['title'] ?? '' ) );

			if ( '' === $id && '' === $type && '' === $category && '' === $handle && '' === $source && '' === $title ) {
				continue;
			}

			if ( '' === $id ) {
				return self::blocking_rule_error(
					'uccm_missing_blocking_rule_id',
					__( 'Rule ID is required.', 'rushleigh-cookie-choices' ),
					$index,
					'id'
				);
			}

			$sanitized_id = sanitize_key( $id );

			if ( '' === $sanitized_id ) {
				return self::blocking_rule_error(
					'uccm_invalid_blocking_rule_id',
					__( 'Rule ID must contain letters, numbers, hyphens or underscores.', 'rushleigh-cookie-choices' ),
					$index,
					'id'
				);
			}

			if ( isset( $rule_map[ $sanitized_id ] ) ) {
				return self::blocking_rule_error(
					'uccm_duplicate_blocking_rule_id',
					__( 'Each Rule ID must be unique.', 'rushleigh-cookie-choices' ),
					$index,
					'id'
				);
			}

			$rule_map[ $sanitized_id ] = compact( 'type', 'category', 'handle', 'source', 'title' );
		}

		return self::sanitize_blocking_rule_map( $rule_map );
	}

	/**
	 * Validate and sanitise one keyed rule map.
	 *
	 * @param array<int|string, mixed> $decoded Rule map.
	 * @return array<string, array<string, string>>|\WP_Error
	 */
	private static function sanitize_blocking_rule_map( array $decoded ): array|\WP_Error {
		$rules = array();

		foreach ( $decoded as $rule_id => $rule ) {
			$id       = sanitize_key( (string) $rule_id );
			$type     = is_array( $rule ) ? sanitize_key( (string) ( $rule['type'] ?? '' ) ) : '';
			$category = is_array( $rule ) ? sanitize_key( (string) ( $rule['category'] ?? '' ) ) : '';
			$handle   = is_array( $rule ) ? sanitize_key( (string) ( $rule['handle'] ?? '' ) ) : '';
			$source   = is_array( $rule ) ? esc_url_raw( (string) ( $rule['source'] ?? '' ) ) : '';
			$title    = is_array( $rule ) ? sanitize_text_field( (string) ( $rule['title'] ?? '' ) ) : '';
			$index    = count( $rules );

			if ( '' === $id ) {
				return self::blocking_rule_error(
					'uccm_missing_blocking_rule_id',
					__( 'Rule ID is required.', 'rushleigh-cookie-choices' ),
					$index,
					'id'
				);
			}

			if ( ! in_array( $type, array( 'script', 'iframe', 'embed', 'pixel' ), true ) ) {
				return self::blocking_rule_error(
					'uccm_invalid_blocking_type',
					__( 'Choose Script, Iframe, Embed or Pixel.', 'rushleigh-cookie-choices' ),
					$index,
					'type'
				);
			}

			if ( ! in_array( $category, array( 'functional', 'analytics', 'marketing' ), true ) ) {
				return self::blocking_rule_error(
					'uccm_invalid_blocking_category',
					__( 'Choose Functional, Analytics or Marketing.', 'rushleigh-cookie-choices' ),
					$index,
					'category'
				);
			}

			if ( ( 'script' === $type && '' === $handle && '' === $source ) || ( 'script' !== $type && '' === $source ) ) {
				return self::blocking_rule_error(
					'uccm_invalid_blocking_source',
					'script' === $type
						? __( 'Enter a WordPress handle or an HTTPS source for this script.', 'rushleigh-cookie-choices' )
						: __( 'Enter an HTTPS source for this resource.', 'rushleigh-cookie-choices' ),
					$index,
					'script' === $type ? 'handle' : 'source'
				);
			}

			if ( '' !== $source && 'https' !== strtolower( (string) wp_parse_url( $source, PHP_URL_SCHEME ) ) ) {
				return self::blocking_rule_error(
					'uccm_insecure_blocking_source',
					__( 'Source must be a complete HTTPS URL.', 'rushleigh-cookie-choices' ),
					$index,
					'source'
				);
			}

			$rules[ $id ] = compact( 'type', 'category', 'handle', 'source', 'title' );
		}

		return $rules;
	}

	/**
	 * Return an error that identifies the invalid guided-editor field.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Field guidance.
	 * @param int    $index   Zero-based row index.
	 * @param string $field   Invalid field name.
	 */
	private static function blocking_rule_error( string $code, string $message, int $index, string $field ): \WP_Error {
		return new \WP_Error(
			$code,
			sprintf(
				/* translators: 1: rule number, 2: validation message. */
				__( 'Rule %1$d: %2$s', 'rushleigh-cookie-choices' ),
				$index + 1,
				$message
			),
			array(
				'index' => $index,
				'field' => $field,
			)
		);
	}

	/**
	 * Persist the bounded list of public scan URLs.
	 */
	public static function save_scan_settings(): void {
		self::require_capability( 'run_uccm_scans' );
		check_admin_referer( 'uccm_save_scan_settings' );
		$submitted = isset( $_POST['uccm'] ) && is_array( $_POST['uccm'] ) ? wp_unslash( $_POST['uccm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings validates every URL.
		$urls      = Settings::validate_scan_urls( $submitted['scan_urls'] ?? '' );

		if ( is_wp_error( $urls ) ) {
			$data = $urls->get_error_data();
			$url  = is_array( $data ) ? (string) ( $data['url'] ?? '' ) : '';
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'              => 'uccm-scans',
						'uccm_notice'       => 'scan-url-error',
						'uccm_rejected_url' => substr( $url, 0, 200 ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( ! empty( $submitted['remove_post_password'] ) ) {
			Post_Password_Access::clear_password();
		} elseif ( isset( $submitted['post_password'] ) && is_string( $submitted['post_password'] ) && '' !== $submitted['post_password'] ) {
			$password_result = Post_Password_Access::save_password( $submitted['post_password'] );

			if ( is_wp_error( $password_result ) ) {
				wp_die( esc_html( $password_result->get_error_message() ), '', array( 'response' => 400 ) );
			}
		}

		Settings::update(
			array(
				'scan_urls'                      => $urls,
				'scan_excluded_paths'            => $submitted['scan_excluded_paths'] ?? Crawler::DEFAULT_EXCLUDED_PATHS,
				'scan_page_limit'                => $submitted['scan_page_limit'] ?? Scanner::MAX_TARGETS,
				'scan_batch_size'                => $submitted['scan_batch_size'] ?? Scanner::DEFAULT_BATCH_SIZE,
				'scan_protected_content_enabled' => $submitted['scan_protected_content_enabled'] ?? false,
			),
			self::submitted_inheritance()
		);

		self::redirect( 'uccm-scans', 'saved' );
	}

	/**
	 * Run one capability- and nonce-gated manual scan.
	 */
	public static function run_scan(): void {
		self::require_capability( 'run_uccm_scans' );
		check_admin_referer( 'uccm_run_scan' );
		$result = Scanner::start();

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-scans', 'scan-started' );
	}

	/**
	 * Cancel one resumable scan while preserving its evidence.
	 */
	public static function cancel_scan(): void {
		self::require_capability( 'run_uccm_scans' );
		check_admin_referer( 'uccm_cancel_scan' );
		$run_id = isset( $_POST['scan_id'] ) ? (int) $_POST['scan_id'] : 0;
		$result = Scanner::cancel( $run_id );

		if ( is_wp_error( $result ) || false === $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'The scan could not be cancelled.', 'rushleigh-cookie-choices' );
			wp_die( esc_html( $message ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-scans', 'scan-cancelled' );
	}

	/**
	 * Resume one interrupted scan from its persisted frontier.
	 */
	public static function resume_scan(): void {
		self::require_capability( 'run_uccm_scans' );
		check_admin_referer( 'uccm_resume_scan' );
		$run_id = isset( $_POST['scan_id'] ) ? (int) $_POST['scan_id'] : 0;
		$result = Scanner::resume( $run_id );

		if ( is_wp_error( $result ) || false === $result ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'The scan could not be resumed.', 'rushleigh-cookie-choices' );
			wp_die( esc_html( $message ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-scans', 'scan-resumed' );
	}

	/**
	 * Process one bounded scan batch from the authenticated scans screen.
	 *
	 * This is an independent recovery path when a host cannot start WP-Cron's
	 * loopback request. The persisted batch lock keeps browser and cron workers
	 * from processing the same run concurrently.
	 */
	public static function process_scan_batch(): void {
		self::require_capability( 'run_uccm_scans' );
		check_ajax_referer( 'uccm_scan_progress', 'nonce' );
		$run_id = isset( $_POST['scan_id'] ) ? max( 0, (int) $_POST['scan_id'] ) : 0;
		$result = Scanner::process_batch( $run_id, null, false );

		if ( is_wp_error( $result ) && 'uccm_scan_batch_busy' !== $result->get_error_code() ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}

		$progress = Scanner::progress( $run_id );

		if ( is_wp_error( $progress ) ) {
			$data   = $progress->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400;
			wp_send_json_error( array( 'message' => $progress->get_error_message() ), $status );
		}

		$progress['busy'] = is_wp_error( $result );
		wp_send_json_success( $progress );
	}

	/**
	 * Enqueue the authenticated recovery worker for active scan runs.
	 *
	 * @param array<int, array<string, mixed>> $runs Recent scan records.
	 * @return int[] Active run identifiers.
	 */
	public static function enqueue_scan_progress( array $runs ): array {
		$active_runs = array();

		foreach ( $runs as $candidate_run ) {
			if ( in_array( (string) ( $candidate_run['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
				$active_runs[] = max( 0, (int) ( $candidate_run['id'] ?? 0 ) );
			}
		}
		$active_runs = array_values( array_filter( array_unique( $active_runs ) ) );

		if ( array() === $active_runs ) {
			return array();
		}

		wp_enqueue_script( 'uccm-scan-progress', plugin_dir_url( UCCM_PLUGIN_FILE ) . 'assets/js/scan-progress.js', array( 'wp-i18n' ), UCCM_VERSION, true );
		wp_set_script_translations( 'uccm-scan-progress', 'rushleigh-cookie-choices', plugin_dir_path( UCCM_PLUGIN_FILE ) . 'languages' );
		wp_localize_script(
			'uccm-scan-progress',
			'UCCMScanProgress',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'uccm_scan_progress' ),
				'runIds'  => $active_runs,
			)
		);

		return $active_runs;
	}


	/**
	 * Establish WordPress's native post-password cookie inside an isolated browser frame.
	 *
	 * The short-lived opaque token authorises only exact protected targets from one
	 * completed scan. Neither the password nor cookie value is returned as data.
	 */
	public static function post_password_bootstrap(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- A short-lived target-bounded opaque token authorises this credentialless iframe request.
		$token = self::request_value( $_POST, 'token' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The run ID is verified against the opaque token.
		$run_id = (int) self::request_value( $_POST, 'scan_id' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The exact target is verified against the opaque token and same-origin validator.
		$target = self::request_value( $_POST, 'target' );
		$target = Scanner::validate_target( $target );

		if ( is_wp_error( $target ) || ! Post_Password_Access::browser_token_allows( $token, $run_id, $target ) ) {
			status_header( 403 );
			exit;
		}

		$value = Post_Password_Access::cookie_value();

		if ( '' === $value ) {
			status_header( 403 );
			exit;
		}

		$options = array(
			'expires'  => time() + ( 20 * MINUTE_IN_SECONDS ),
			'path'     => defined( 'COOKIEPATH' ) && '' !== COOKIEPATH ? COOKIEPATH : '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);

		if ( defined( 'COOKIE_DOMAIN' ) && '' !== COOKIE_DOMAIN ) {
			$options['domain'] = COOKIE_DOMAIN;
		}

		setcookie( Post_Password_Access::cookie_name(), $value, $options );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'Protected page access prepared', 'rushleigh-cookie-choices' ) . '</title>';
		exit;
	}


	/**
	 * Receive one nonce- and capability-protected browser observation pass.
	 */
	public static function browser_scan_observations(): void {
		self::require_capability( 'run_uccm_scans' );
		check_ajax_referer( 'uccm_browser_scan', 'nonce' );
		$run_id  = isset( $_POST['scan_id'] ) ? (int) $_POST['scan_id'] : 0;
		$encoded = self::request_value( $_POST, 'payload' );
		$payload = json_decode( $encoded, true );
		$result  = Scanner::record_browser_observations( $run_id, is_array( $payload ) ? $payload : array() );

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 400 ) : 400;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}

		wp_send_json_success( array( 'counts' => $result ) );
	}

	/**
	 * Apply one explicit capability- and nonce-gated finding outcome.
	 */
	public static function review_scan_finding(): void {
		self::require_capability( 'manage_uccm_inventory' );
		check_admin_referer( 'uccm_review_scan_finding' );
		$finding_id = isset( $_POST['finding_id'] ) ? (int) $_POST['finding_id'] : 0;
		$status     = self::request_value( $_POST, 'finding_status' );
		$result     = Scan_Findings::review( $finding_id, $status );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-scans', 'finding-reviewed' );
	}

	/**
	 * Persist one capability-gated inventory edit.
	 */
	public static function save_inventory(): void {
		self::require_capability( 'manage_uccm_inventory' );
		check_admin_referer( 'uccm_save_inventory' );
		$submitted = isset( $_POST['uccm'] ) && is_array( $_POST['uccm'] ) ? wp_unslash( $_POST['uccm'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Inventory service validates each field.
		$result    = Cookie_Inventory::save( $submitted );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-inventory', 'saved' );
	}

	/**
	 * Send a filtered, capability-gated CSV export.
	 */
	public static function export_inventory(): void {
		self::require_capability( 'manage_uccm_inventory' );
		check_admin_referer( 'uccm_export_inventory' );
		$records = Cookie_Inventory::export_records( self::inventory_filters() );

		if ( is_wp_error( $records ) ) {
			wp_die( esc_html( $records->get_error_message() ), '', array( 'response' => 403 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="uccm-cookie-inventory.csv"' );
		echo Cookie_Inventory::csv( $records ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate CSV download with formula-neutralised cells.
		exit;
	}

	/**
	 * Render the administration overview.
	 */
	public static function render_overview(): void {
		self::require_capability( 'manage_uccm_settings' );
		self::open_page( __( 'Cookie Consent Overview', 'rushleigh-cookie-choices' ) );
		echo '<p>' . esc_html__( 'Set up your cookie banner, review what your site stores, and check visitors’ choices.', 'rushleigh-cookie-choices' ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Scans continue in the background. Anything new is listed for you to review before it is added to your cookie list.', 'rushleigh-cookie-choices' ) . '</p></div>';
		self::close_page();
	}

	/**
	 * Render support and private security-reporting guidance.
	 */
	public static function render_help(): void {
		self::require_capability( 'manage_uccm_settings' );
		$security_url   = 'https://github.com/rushleighconsulting/uk-cookie-consent-manager/security/advisories/new';
		$security_email = 'security@rushleighconsulting.co.uk';

		self::open_page( __( 'Help', 'rushleigh-cookie-choices' ) );
		echo '<h2>' . esc_html__( 'Report a security vulnerability privately', 'rushleigh-cookie-choices' ) . '</h2>';
		echo '<p>' . esc_html__( 'Do not post suspected vulnerabilities in a public support forum, ordinary bug report or social-media message.', 'rushleigh-cookie-choices' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $security_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open the private vulnerability form', 'rushleigh-cookie-choices' ) . '</a></p>';
		echo '<p>' . esc_html__( 'If the private form is unavailable, email:', 'rushleigh-cookie-choices' ) . ' <a href="' . esc_attr( 'mailto:' . $security_email ) . '">' . esc_html( $security_email ) . '</a></p>';
		echo '<p>' . esc_html__( 'Before sending evidence, remove consent records, cookie values, complete IP addresses, credentials, access tokens, database exports and unrelated personal data.', 'rushleigh-cookie-choices' ) . '</p>';
		echo '<p>' . esc_html__( 'Security reports are acknowledged separately from severity assessment, remediation, release and public disclosure.', 'rushleigh-cookie-choices' ) . '</p>';
		self::close_page();
	}

	/**
	 * Render banner settings.
	 */
	public static function render_banner(): void {
		self::require_capability( 'manage_uccm_settings' );
		$settings = Settings::current();

		wp_enqueue_style(
			'uccm-admin-banner',
			plugin_dir_url( UCCM_PLUGIN_FILE ) . 'assets/css/admin-banner.css',
			array(),
			UCCM_VERSION
		);
		wp_enqueue_script(
			'uccm-admin-banner',
			plugin_dir_url( UCCM_PLUGIN_FILE ) . 'assets/js/admin-banner.js',
			array(),
			UCCM_VERSION,
			true
		);
		self::open_page( __( 'Banner', 'rushleigh-cookie-choices' ) );
		self::saved_notice();
		echo '<p>' . esc_html__( 'Choose from the supported appearance options. UCCM keeps the main Accept and Reject choices equally prominent and checks colour contrast before saving.', 'rushleigh-cookie-choices' ) . '</p>';
		self::form_open( 'uccm_save_settings', 'uccm_save_banner' );
		echo '<input type="hidden" name="section" value="banner">';
		self::number_field( 'consent_lifetime_days', __( 'Consent lifetime (days)', 'rushleigh-cookie-choices' ), (int) $settings['consent_lifetime_days'], 1, 730, Settings::is_network_locked( 'consent_lifetime_days' ) );
		self::network_setting_control( 'consent_lifetime_days' );
		self::text_field( 'consent_policy_version', __( 'Consent policy version', 'rushleigh-cookie-choices' ), (string) $settings['consent_policy_version'] );
		self::text_field( 'default_content_locale', __( 'Default consent language', 'rushleigh-cookie-choices' ), (string) $settings['default_content_locale'] );
		echo '<p class="description">' . esc_html__( 'Use a WordPress locale such as en_GB, cy or ar. This language is used when the current page has no matching consent content.', 'rushleigh-cookie-choices' ) . '</p>';
		self::render_language_content_editor( $settings );
		echo '<h2>' . esc_html__( 'Appearance', 'rushleigh-cookie-choices' ) . '</h2>';
		echo '<div class="uccm-banner-editor" data-uccm-banner-editor>';
		echo '<div class="uccm-banner-editor__controls">';
		self::colour_field( 'banner_surface_color', __( 'Banner background', 'rushleigh-cookie-choices' ), (string) $settings['banner_surface_color'] );
		self::colour_field( 'banner_text_color', __( 'Heading text', 'rushleigh-cookie-choices' ), (string) $settings['banner_text_color'] );
		self::colour_field( 'banner_muted_color', __( 'Supporting text', 'rushleigh-cookie-choices' ), (string) $settings['banner_muted_color'] );
		self::colour_field( 'banner_button_color', __( 'Button background', 'rushleigh-cookie-choices' ), (string) $settings['banner_button_color'] );
		self::colour_field( 'banner_button_text_color', __( 'Button text', 'rushleigh-cookie-choices' ), (string) $settings['banner_button_text_color'] );
		self::select_field(
			'banner_font',
			__( 'Font', 'rushleigh-cookie-choices' ),
			array(
				'system' => __( 'UCCM system font', 'rushleigh-cookie-choices' ),
				'theme'  => __( 'Use the site theme font', 'rushleigh-cookie-choices' ),
			),
			(string) $settings['banner_font']
		);
		self::number_field( 'banner_corner_radius', __( 'Corner radius (pixels)', 'rushleigh-cookie-choices' ), (int) $settings['banner_corner_radius'], 0, 24 );
		self::select_field(
			'banner_position',
			__( 'Banner position', 'rushleigh-cookie-choices' ),
			array(
				'bottom' => __( 'Bottom', 'rushleigh-cookie-choices' ),
				'top'    => __( 'Top', 'rushleigh-cookie-choices' ),
			),
			(string) $settings['banner_position']
		);
		self::select_field(
			'icon_position',
			__( 'Cookie settings icon position', 'rushleigh-cookie-choices' ),
			array(
				'right' => __( 'Bottom right', 'rushleigh-cookie-choices' ),
				'left'  => __( 'Bottom left', 'rushleigh-cookie-choices' ),
			),
			(string) $settings['icon_position']
		);
		echo '</div>';
		self::render_banner_preview( $settings );
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'The preview demonstrates colour, font, corners and position. Your site layout may provide different surrounding content.', 'rushleigh-cookie-choices' ) . '</p>';
		submit_button( __( 'Save banner settings', 'rushleigh-cookie-choices' ) );
		echo '<p><button type="submit" class="button button-secondary" name="reset_banner_style" value="1" formnovalidate>' . esc_html__( 'Reset appearance to defaults', 'rushleigh-cookie-choices' ) . '</button></p>';
		self::form_close();
		self::close_page();
	}

	/**
	 * Render locally authored language variants and completeness diagnostics.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 */
	private static function render_language_content_editor( array $settings ): void {
		$stored      = Language_Content::sanitise_catalog( $settings['language_content'] ?? array() );
		$diagnostics = Language_Content::diagnostics();
		$rows        = array_values( $stored );

		foreach ( array_keys( $stored ) as $index => $locale ) {
			$rows[ $index ]['locale'] = $locale;
		}

		$rows[] = array( 'locale' => '' );
		echo '<h2>' . esc_html__( 'Consent languages', 'rushleigh-cookie-choices' ) . '</h2>';
		echo '<p>' . esc_html__( 'Add wording only for languages your site uses. Empty fields safely fall back to the default wording. UCCM never sends this content to an external translation service.', 'rushleigh-cookie-choices' ) . '</p>';

		foreach ( $diagnostics as $locale => $missing ) {
			echo '<div class="notice notice-warning inline"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: locale, 2: comma-separated field names. */
					__( '%1$s uses fallback wording for: %2$s', 'rushleigh-cookie-choices' ),
					$locale,
					implode( ', ', $missing )
				)
			);
			echo '</p></div>';
		}

		foreach ( $rows as $index => $content ) {
			$locale     = (string) ( $content['locale'] ?? '' );
			$categories = is_array( $content['categories'] ?? null ) ? $content['categories'] : array();
			$summary    = '' === $locale ? __( 'Add another language', 'rushleigh-cookie-choices' ) : $locale;
			$prefix     = 'uccm[language_content][' . $index . ']';
			echo '<details style="max-width:980px;margin:0 0 12px;border:1px solid #c3c4c7;padding:12px"' . ( '' === $locale ? '' : ' open' ) . '>';
			echo '<summary><strong>' . esc_html( $summary ) . '</strong></summary>';
			echo '<p><label><strong>' . esc_html__( 'Locale', 'rushleigh-cookie-choices' ) . '</strong><br><input class="regular-text" name="' . esc_attr( $prefix . '[locale]' ) . '" value="' . esc_attr( $locale ) . '" placeholder="cy"></label></p>';
			self::language_text_input( $prefix, 'wording_version', __( 'Wording version', 'rushleigh-cookie-choices' ), (string) ( $content['wording_version'] ?? '1' ), false );
			echo '<p><label><strong>' . esc_html__( 'Reading direction', 'rushleigh-cookie-choices' ) . '</strong><br><select name="' . esc_attr( $prefix . '[direction]' ) . '">';

			foreach (
				array(
					'auto' => __( 'Automatic', 'rushleigh-cookie-choices' ),
					'ltr'  => __( 'Left to right', 'rushleigh-cookie-choices' ),
					'rtl'  => __( 'Right to left', 'rushleigh-cookie-choices' ),
				) as $value => $label
			) {
				echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $content['direction'] ?? 'auto' ), $value, false ) . '>' . esc_html( $label ) . '</option>';
			}

			echo '</select></label></p>';
			echo '<p><label><strong>' . esc_html__( 'Cookie policy URL', 'rushleigh-cookie-choices' ) . '</strong><br><input type="url" class="large-text" name="' . esc_attr( $prefix . '[policy_url]' ) . '" value="' . esc_attr( (string) ( $content['policy_url'] ?? '' ) ) . '"></label></p>';

			foreach (
				array(
					'banner_title'       => __( 'Banner heading', 'rushleigh-cookie-choices' ),
					'banner_copy'        => __( 'Banner message', 'rushleigh-cookie-choices' ),
					'preferences_title'  => __( 'Preferences heading', 'rushleigh-cookie-choices' ),
					'preferences_intro'  => __( 'Preferences introduction', 'rushleigh-cookie-choices' ),
					'cookie_copy'        => __( 'Necessary-cookie explanation', 'rushleigh-cookie-choices' ),
					'accept_all'         => __( 'Accept button', 'rushleigh-cookie-choices' ),
					'reject_optional'    => __( 'Reject button', 'rushleigh-cookie-choices' ),
					'manage_preferences' => __( 'Manage button', 'rushleigh-cookie-choices' ),
					'save_choices'       => __( 'Save button', 'rushleigh-cookie-choices' ),
					'withdraw_consent'   => __( 'Withdraw button', 'rushleigh-cookie-choices' ),
					'close_preferences'  => __( 'Close-button screen-reader label', 'rushleigh-cookie-choices' ),
					'settings_label'     => __( 'Cookie-icon screen-reader label', 'rushleigh-cookie-choices' ),
					'policy_link_label'  => __( 'Cookie policy link label', 'rushleigh-cookie-choices' ),
				) as $field => $label
			) {
				self::language_text_input( $prefix, $field, $label, (string) ( $content[ $field ] ?? '' ), in_array( $field, array( 'banner_copy', 'preferences_intro', 'cookie_copy' ), true ) );
			}

			echo '<h3>' . esc_html__( 'Category wording', 'rushleigh-cookie-choices' ) . '</h3>';

			foreach ( array( 'necessary', 'functional', 'analytics', 'marketing' ) as $category ) {
				$values = is_array( $categories[ $category ] ?? null ) ? $categories[ $category ] : array();
				echo '<fieldset style="margin:0 0 12px"><legend><strong>' . esc_html( ucfirst( $category ) ) . '</strong></legend>';
				self::language_text_input( $prefix . '[categories][' . $category . ']', 'label', __( 'Label', 'rushleigh-cookie-choices' ), (string) ( $values['label'] ?? '' ), false );
				self::language_text_input( $prefix . '[categories][' . $category . ']', 'description', __( 'Description', 'rushleigh-cookie-choices' ), (string) ( $values['description'] ?? '' ), true );
				echo '</fieldset>';
			}

			echo '</details>';
		}
	}

	/**
	 * Render one plain-text language-content field.
	 *
	 * @param string $prefix    Nested form-field prefix.
	 * @param string $field     Content field name.
	 * @param string $label     Visible administrator label.
	 * @param string $value     Current field value.
	 * @param bool   $multiline Whether to render a textarea.
	 */
	private static function language_text_input( string $prefix, string $field, string $label, string $value, bool $multiline ): void {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br>';

		if ( $multiline ) {
			echo '<textarea class="large-text" rows="3" name="' . esc_attr( $prefix . '[' . $field . ']' ) . '">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input class="large-text" name="' . esc_attr( $prefix . '[' . $field . ']' ) . '" value="' . esc_attr( $value ) . '">';
		}

		echo '</label></p>';
	}

	/**
	 * Render the constrained banner appearance preview.
	 *
	 * @param array<string, mixed> $settings Current settings.
	 */
	private static function render_banner_preview( array $settings ): void {
		$style = sprintf(
			'--uccm-preview-surface:%1$s;--uccm-preview-ink:%2$s;--uccm-preview-muted:%3$s;--uccm-preview-accent:%4$s;--uccm-preview-button-text:%5$s;--uccm-preview-radius:%6$dpx',
			(string) $settings['banner_surface_color'],
			(string) $settings['banner_text_color'],
			(string) $settings['banner_muted_color'],
			(string) $settings['banner_button_color'],
			(string) $settings['banner_button_text_color'],
			(int) $settings['banner_corner_radius']
		);

		echo '<section class="uccm-banner-preview" data-uccm-banner-preview data-font="' . esc_attr( (string) $settings['banner_font'] ) . '" data-position="' . esc_attr( (string) $settings['banner_position'] ) . '" data-icon-position="' . esc_attr( (string) $settings['icon_position'] ) . '" style="' . esc_attr( $style ) . '" aria-label="' . esc_attr( __( 'Banner preview', 'rushleigh-cookie-choices' ) ) . '">';
		echo '<div class="uccm-banner-preview__page"><span></span><span></span><span></span></div>';
		echo '<div class="uccm-banner-preview__banner">';
		echo '<div><strong>' . esc_html__( 'Your cookie choices', 'rushleigh-cookie-choices' ) . '</strong><p>' . esc_html__( 'We use one necessary cookie to remember your choice. Optional cookies need your permission.', 'rushleigh-cookie-choices' ) . '</p></div>';
		echo '<div class="uccm-banner-preview__actions"><span>' . esc_html__( 'Accept all', 'rushleigh-cookie-choices' ) . '</span><span>' . esc_html__( 'Reject non-essential', 'rushleigh-cookie-choices' ) . '</span><span>' . esc_html__( 'Manage preferences', 'rushleigh-cookie-choices' ) . '</span></div>';
		echo '</div>';
		echo '<span class="uccm-banner-preview__icon" aria-hidden="true">◔</span>';
		echo '</section>';
	}

	/**
	 * Render the category contract.
	 */
	public static function render_categories(): void {
		self::require_capability( 'manage_uccm_settings' );
		self::open_page( __( 'View Categories', 'rushleigh-cookie-choices' ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Category', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Purpose', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Required', 'rushleigh-cookie-choices' ) . '</th></tr></thead><tbody>';

		foreach ( Consent_State::categories() as $category ) {
			echo '<tr><td>' . esc_html( $category['label'] ) . '</td><td>' . esc_html( $category['description'] ) . '</td><td>' . esc_html( $category['required'] ? __( 'Yes', 'rushleigh-cookie-choices' ) : __( 'No', 'rushleigh-cookie-choices' ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
		self::close_page();
	}

	/**
	 * Render explicit blocking rule management.
	 */
	public static function render_blocking(): void {
		self::require_capability( 'manage_uccm_settings' );
		$stored_rules = get_option( Resource_Rules::OPTION_NAME, array() );
		$rules        = is_array( $stored_rules ) ? $stored_rules : array();
		$encoded      = array() === $rules ? '{}' : wp_json_encode( $rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$encoded      = false === $encoded ? '{}' : $encoded;

		wp_enqueue_script(
			'uccm-blocking-editor',
			plugin_dir_url( UCCM_PLUGIN_FILE ) . 'assets/js/admin-blocking.js',
			array( 'wp-i18n' ),
			UCCM_VERSION,
			true
		);
		wp_set_script_translations( 'uccm-blocking-editor', 'rushleigh-cookie-choices', plugin_dir_path( UCCM_PLUGIN_FILE ) . 'languages' );
		wp_localize_script(
			'uccm-blocking-editor',
			'UCCMBlockingEditor',
			array(
				'newRule'        => __( 'New rule', 'rushleigh-cookie-choices' ),
				'ruleLabel'      => __( 'Rule', 'rushleigh-cookie-choices' ),
				'handleOrSource' => __( 'Enter a WordPress handle or an HTTPS source.', 'rushleigh-cookie-choices' ),
				'httpsSource'    => __( 'Enter a complete HTTPS source.', 'rushleigh-cookie-choices' ),
				'duplicateId'    => __( 'Each Rule ID must be unique.', 'rushleigh-cookie-choices' ),
			)
		);

		self::open_page( __( 'Script Blocking', 'rushleigh-cookie-choices' ) );
		self::saved_notice();
		echo '<p>' . esc_html__( 'Add only optional resources you recognise. UCCM blocks a configured resource until the visitor allows its category.', 'rushleigh-cookie-choices' ) . '</p>';
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Incorrect rules can stop site features from working. Test each rule before using it on a live site.', 'rushleigh-cookie-choices' ) . '</p></div>';
		self::form_open( 'uccm_save_blocking_rules', 'uccm_save_blocking' );
		echo '<div data-uccm-rule-editor>';
		echo '<div data-uccm-rule-list>';

		foreach ( $rules as $rule_id => $rule ) {
			if ( is_array( $rule ) ) {
				$rule['id'] = (string) $rule_id;
				self::render_blocking_rule_fields( $rule, (string) $rule_id );
			}
		}

		echo '</div>';
		echo '<p data-uccm-empty ' . ( array() === $rules ? '' : 'hidden' ) . '>' . esc_html__( 'No blocking rules have been added yet.', 'rushleigh-cookie-choices' ) . '</p>';
		echo '<p><button type="button" class="button button-secondary" data-uccm-add-rule>' . esc_html__( 'Add rule', 'rushleigh-cookie-choices' ) . '</button></p>';
		echo '<details><summary>' . esc_html__( 'Advanced JSON view', 'rushleigh-cookie-choices' ) . '</summary>';
		echo '<p class="description" id="uccm-blocking-json-description">' . esc_html__( 'This read-only view shows the validated object that will be saved. Use the fields above to make changes.', 'rushleigh-cookie-choices' ) . '</p>';
		echo '<textarea class="large-text code" rows="12" name="rules" data-uccm-rules-json aria-describedby="uccm-blocking-json-description" readonly>' . esc_textarea( $encoded ) . '</textarea>';
		echo '</details>';
		echo '<template data-uccm-rule-template>';
		self::render_blocking_rule_fields( array(), '__INDEX__' );
		echo '</template>';
		echo '</div>';
		submit_button( __( 'Save blocking rules', 'rushleigh-cookie-choices' ) );
		self::form_close();
		self::close_page();
	}

	/**
	 * Render one accessible blocking-rule editor row.
	 *
	 * @param array<string, mixed> $rule  Current values.
	 * @param string               $index Stable form index.
	 */
	private static function render_blocking_rule_fields( array $rule, string $index ): void {
		$id       = (string) ( $rule['id'] ?? '' );
		$type     = (string) ( $rule['type'] ?? 'script' );
		$category = (string) ( $rule['category'] ?? 'analytics' );
		$handle   = (string) ( $rule['handle'] ?? '' );
		$source   = (string) ( $rule['source'] ?? '' );
		$title    = (string) ( $rule['title'] ?? '' );
		$legend   = '' !== $title ? $title : ( '' !== $id ? $id : __( 'New rule', 'rushleigh-cookie-choices' ) );
		$prefix   = 'uccm_rules[' . $index . ']';
		$help_id  = 'uccm-rule-resource-' . $index;

		echo '<fieldset class="uccm-blocking-rule" data-uccm-rule style="border:1px solid #c3c4c7;padding:12px;margin:0 0 12px;max-width:980px">';
		echo '<legend><strong data-uccm-rule-legend>' . esc_html( $legend ) . '</strong></legend>';
		echo '<p><label><strong>' . esc_html__( 'Rule ID', 'rushleigh-cookie-choices' ) . '</strong><br>';
		echo '<input class="regular-text" name="' . esc_attr( $prefix . '[id]' ) . '" value="' . esc_attr( $id ) . '" pattern="[A-Za-z0-9_-]+" required data-uccm-field="id" aria-describedby="' . esc_attr( $help_id . '-id' ) . '"></label><br>';
		echo '<span class="description" id="' . esc_attr( $help_id . '-id' ) . '">' . esc_html__( 'A unique name using letters, numbers, hyphens or underscores; for example analytics-test.', 'rushleigh-cookie-choices' ) . '</span></p>';
		echo '<p><label><strong>' . esc_html__( 'Resource type', 'rushleigh-cookie-choices' ) . '</strong><br><select name="' . esc_attr( $prefix . '[type]' ) . '" data-uccm-field="type">';
		self::options( array( 'script', 'iframe', 'embed', 'pixel' ), $type );
		echo '</select></label></p>';
		echo '<p><label><strong>' . esc_html__( 'Consent category', 'rushleigh-cookie-choices' ) . '</strong><br><select name="' . esc_attr( $prefix . '[category]' ) . '" data-uccm-field="category">';
		self::options( array( 'functional', 'analytics', 'marketing' ), $category );
		echo '</select></label></p>';
		echo '<p><label><strong>' . esc_html__( 'WordPress script handle', 'rushleigh-cookie-choices' ) . '</strong><br>';
		echo '<input class="regular-text" name="' . esc_attr( $prefix . '[handle]' ) . '" value="' . esc_attr( $handle ) . '" data-uccm-field="handle" aria-describedby="' . esc_attr( $help_id . '-handle' ) . '"></label><br>';
		echo '<span class="description" id="' . esc_attr( $help_id . '-handle' ) . '">' . esc_html__( 'For scripts registered by WordPress. A script needs a handle, an HTTPS source, or both.', 'rushleigh-cookie-choices' ) . '</span></p>';
		echo '<p><label><strong>' . esc_html__( 'HTTPS source', 'rushleigh-cookie-choices' ) . '</strong><br>';
		echo '<input class="large-text" type="url" name="' . esc_attr( $prefix . '[source]' ) . '" value="' . esc_attr( $source ) . '" placeholder="https://example.com/resource.js" data-uccm-field="source" aria-describedby="' . esc_attr( $help_id . '-source' ) . '"></label><br>';
		echo '<span class="description" id="' . esc_attr( $help_id . '-source' ) . '">' . esc_html__( 'Required for iframes, embeds and pixels. Scripts may use this instead of a WordPress handle.', 'rushleigh-cookie-choices' ) . '</span></p>';
		echo '<p><label><strong>' . esc_html__( 'Title', 'rushleigh-cookie-choices' ) . '</strong><br>';
		echo '<input class="regular-text" name="' . esc_attr( $prefix . '[title]' ) . '" value="' . esc_attr( $title ) . '" data-uccm-field="title"></label><br>';
		echo '<span class="description">' . esc_html__( 'A recognisable administrator label, such as Analytics test script or Location map.', 'rushleigh-cookie-choices' ) . '</span></p>';
		echo '<p><button type="button" class="button-link-delete" data-uccm-remove-rule>' . esc_html__( 'Remove rule', 'rushleigh-cookie-choices' ) . '</button></p>';
		echo '</fieldset>';
	}

	/**
	 * Render filtered, paginated inventory and its edit form.
	 */
	public static function render_inventory(): void {
		self::require_capability( 'manage_uccm_inventory' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded pagination.
		$page    = max( 1, (int) self::request_value( $_GET, 'paged' ) );
		$filters = self::inventory_filters();
		$records = Cookie_Inventory::records( $filters, $page, 20 );
		self::open_page( __( 'Cookie Inventory', 'rushleigh-cookie-choices' ) );
		self::saved_notice();

		if ( is_wp_error( $records ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $records->get_error_message() ) . '</p></div>';
			self::close_page();
			return;
		}

		self::render_inventory_filters( $filters );
		/* translators: %d: number of reviewed inventory items. */
		echo '<p><strong>' . esc_html( sprintf( _n( '%d reviewed item', '%d reviewed items', $records['total'], 'rushleigh-cookie-choices' ), $records['total'] ) ) . '</strong></p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Provider / domain', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Type', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Purpose', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Category', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Duration', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Review', 'rushleigh-cookie-choices' ) . '</th></tr></thead><tbody>';

		if ( array() === $records['items'] ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No inventory items match the current filters.', 'rushleigh-cookie-choices' ) . '</td></tr>';
		}

		foreach ( $records['items'] as $item ) {
			echo '<tr><td><strong>' . esc_html( (string) $item['storage_key'] ) . '</strong><br><small>' . esc_html( (string) $item['party'] ) . '</small></td><td>' . esc_html( (string) $item['provider'] ) . '<br><small>' . esc_html( (string) $item['domain'] ) . '</small></td><td>' . esc_html( (string) $item['storage_type'] ) . '</td><td>' . esc_html( (string) $item['purpose'] ) . '</td><td>' . esc_html( (string) $item['category'] ) . '</td><td>' . esc_html( (string) $item['duration'] ) . '</td><td>' . esc_html( (string) $item['status'] ) . '<br><small>' . esc_html( (string) $item['last_reviewed_at'] ) . '</small></td></tr>';
		}

		echo '</tbody></table>';
		self::render_pagination( $records['page'], $records['pages'], $filters );
		self::render_inventory_form();
		self::close_page();
	}

	/**
	 * Render manual controls, coverage and recent scan evidence.
	 */
	public static function render_scans(): void {
		self::require_capability( 'run_uccm_scans' );
		$settings           = Settings::current();
		$urls               = is_array( $settings['scan_urls'] ?? null ) ? implode( "\n", $settings['scan_urls'] ) : '';
		$excluded_paths     = is_array( $settings['scan_excluded_paths'] ?? null ) ? implode( "\n", $settings['scan_excluded_paths'] ) : '';
		$page_limit         = (int) ( $settings['scan_page_limit'] ?? Scanner::MAX_TARGETS );
		$batch_size         = (int) ( $settings['scan_batch_size'] ?? Scanner::DEFAULT_BATCH_SIZE );
		$protected_enabled  = ! empty( $settings['scan_protected_content_enabled'] );
		$protected_password = Post_Password_Access::has_password();
		$runs               = Scanner::recent_runs( 20 );

		if ( is_array( $runs ) ) {
			$browser_recovery_checked = false;

			foreach ( $runs as $candidate_run ) {
				$candidate_coverage = json_decode( (string) ( $candidate_run['coverage'] ?? '' ), true );

				if ( is_array( $candidate_coverage ) && 'running' === (string) ( $candidate_coverage['browser_status'] ?? '' ) ) {
					Scanner::recover_browser_check( (int) $candidate_run['id'] );
					$browser_recovery_checked = true;
				}
			}

			if ( $browser_recovery_checked ) {
				$runs = Scanner::recent_runs( 20 );
			}
		}

		$next = wp_next_scheduled( Scanner::HOOK );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded filter and notice state.
		$scan_id = max( 0, (int) self::request_value( $_GET, 'scan_id' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded filter and notice state.
		$notice = self::request_value( $_GET, 'uccm_notice' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only value is escaped before display.
		$rejected_url = substr( sanitize_text_field( self::request_value( $_GET, 'uccm_rejected_url' ) ), 0, 200 );
		$findings     = Scan_Findings::records( $scan_id, 100 );
		$runner_run   = null;

		if ( is_array( $runs ) ) {
			self::enqueue_scan_progress( $runs );
		}

		if ( is_array( $runs ) && 0 < $scan_id ) {
			foreach ( $runs as $candidate_run ) {
				if ( $scan_id === (int) $candidate_run['id'] ) {
					$runner_run = $candidate_run;
					break;
				}
			}
		}

		if ( is_array( $runner_run ) && 'completed' === (string) $runner_run['status'] ) {
			$runner_pages      = json_decode( (string) $runner_run['pages_visited'], true );
			$runner_pages      = is_array( $runner_pages ) ? $runner_pages : array();
			$runner_targets    = Scanner::browser_targets( $runner_pages );
			$protected_targets = array_values( array_filter( $runner_targets, array( Post_Password_Access::class, 'target_is_unlocked' ) ) );
			$browser_token     = Post_Password_Access::issue_browser_token( $scan_id, $protected_targets );
			$consent_config    = Consent_State::configuration();

			wp_enqueue_script( 'uccm-scan-runner', plugin_dir_url( UCCM_PLUGIN_FILE ) . 'assets/js/scan-runner.js', array( 'wp-i18n' ), UCCM_VERSION, true );
			wp_set_script_translations( 'uccm-scan-runner', 'rushleigh-cookie-choices', plugin_dir_path( UCCM_PLUGIN_FILE ) . 'languages' );
			wp_localize_script(
				'uccm-scan-runner',
				'UCCMScanRunner',
				array(
					'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( 'uccm_browser_scan' ),
					'runId'             => $scan_id,
					'targets'           => $runner_targets,
					'protectedTargets'  => $protected_targets,
					'postPasswordToken' => $browser_token,
					'maxTargets'        => Scanner::BROWSER_MAX_TARGETS,
					'stepDelayMs'       => Scanner::BROWSER_STEP_DELAY_MS,
					'cookieName'        => (string) $consent_config['cookieName'],
					'cookiePath'        => (string) $consent_config['cookiePath'],
					'policyVersion'     => (string) $consent_config['policyVersion'],
					'pluginVersion'     => (string) $consent_config['pluginVersion'],
					'lifetimeDays'      => (int) $consent_config['lifetimeDays'],
				)
			);
		}

		self::open_page( __( 'Scans', 'rushleigh-cookie-choices' ) );

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Scan settings saved.', 'rushleigh-cookie-choices' ) . '</p></div>';
		} elseif ( 'scan-started' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The scan has started. This page will keep it moving if your site scheduler is delayed. You can leave and return without losing saved progress.', 'rushleigh-cookie-choices' ) . '</p></div>';
		} elseif ( 'scan-cancelled' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The scan was cancelled and its evidence was retained.', 'rushleigh-cookie-choices' ) . '</p></div>';
		} elseif ( 'scan-resumed' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The scan has restarted from its last saved point.', 'rushleigh-cookie-choices' ) . '</p></div>';
		} elseif ( 'finding-reviewed' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Your review decision was saved. The cookie inventory was not changed.', 'rushleigh-cookie-choices' ) . '</p></div>';
		}

		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'A scan checks public pages that this site can reach. Pages shown only after sign-in, personal choices or location checks may behave differently.', 'rushleigh-cookie-choices' ) . '</p></div>';
		echo '<p><strong>' . esc_html__( 'How it works:', 'rushleigh-cookie-choices' ) . '</strong> ' . esc_html__( 'The plugin checks public pages in the background first. When that finishes, you can run an optional browser check to look for cookies and other items loaded by those pages.', 'rushleigh-cookie-choices' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Next monthly run:', 'rushleigh-cookie-choices' ) . '</strong> ' . esc_html( false === $next ? __( 'Not scheduled', 'rushleigh-cookie-choices' ) : gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' ) . '</p>';

		self::form_open( 'uccm_save_scan_settings', 'uccm_save_scan_settings' );
		echo '<h2>' . esc_html__( 'Starting pages', 'rushleigh-cookie-choices' ) . '</h2>';
		echo '<p>' . esc_html__( 'The homepage is always checked. You can add other public pages on this website, one address per line. The scan follows links from these starting pages, up to the saved limit.', 'rushleigh-cookie-choices' ) . '</p>';

		if ( 'scan-url-error' === $notice ) {
			$message = '' === $rejected_url
				? __( 'The scan URLs were not saved. Enter only same-origin public URLs without credentials or fragments.', 'rushleigh-cookie-choices' )
				: sprintf(
					/* translators: %s: rejected scan URL. */
					__( 'The scan URL “%s” was not saved. Enter only same-origin public URLs without credentials or fragments.', 'rushleigh-cookie-choices' ),
					$rejected_url
				);
			echo '<div id="uccm-scan-url-error" class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
		}

		$textarea_attributes = 'scan-url-error' === $notice ? ' aria-invalid="true" aria-describedby="uccm-scan-url-error"' : '';
		echo '<textarea id="uccm-scan-urls" class="large-text code" rows="7" name="uccm[scan_urls]"' . $textarea_attributes . '>' . esc_textarea( $urls ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute fragment is a fixed allowlisted string.
		echo '<h3>' . esc_html__( 'Scan limits', 'rushleigh-cookie-choices' ) . '</h3>';
		self::number_field( 'scan_page_limit', __( 'Maximum pages per scan', 'rushleigh-cookie-choices' ), $page_limit, 1, Scanner::MAX_TARGETS, Settings::is_network_locked( 'scan_page_limit' ) );
		self::network_setting_control( 'scan_page_limit' );
		self::number_field( 'scan_batch_size', __( 'Pages checked at a time', 'rushleigh-cookie-choices' ), $batch_size, 1, 25, Settings::is_network_locked( 'scan_batch_size' ) );
		self::network_setting_control( 'scan_batch_size' );
		echo '<p><label for="uccm-scan-excluded-paths"><strong>' . esc_html__( 'Excluded path patterns', 'rushleigh-cookie-choices' ) . '</strong></label><br>';
		echo '<textarea id="uccm-scan-excluded-paths" class="large-text code" rows="5" name="uccm[scan_excluded_paths]">' . esc_textarea( $excluded_paths ) . '</textarea><br>';
		echo '<span class="description">' . esc_html__( 'One path pattern per line. Use * as a wildcard. WordPress administration, login, REST and feed paths are always excluded.', 'rushleigh-cookie-choices' ) . '</span></p>';
		echo '<h3>' . esc_html__( 'Password-protected pages', 'rushleigh-cookie-choices' ) . '</h3>';
		echo '<p>' . esc_html__( 'By default, WordPress post-password protected pages are not checked. You can opt in with one shared WordPress post password. This does not sign the scanner into WordPress or other services.', 'rushleigh-cookie-choices' ) . '</p>';
		self::checkbox_field( 'scan_protected_content_enabled', __( 'Check pages unlocked by the stored WordPress post password', 'rushleigh-cookie-choices' ), $protected_enabled, __( 'Only published pages and posts on this site that match the stored password become eligible.', 'rushleigh-cookie-choices' ) );
		echo '<p><label for="uccm-post-password"><strong>' . esc_html__( 'WordPress post password', 'rushleigh-cookie-choices' ) . '</strong></label><br><input id="uccm-post-password" class="regular-text" type="password" autocomplete="new-password" name="uccm[post_password]" value="" placeholder="' . esc_attr__( 'Leave blank to keep the stored password', 'rushleigh-cookie-choices' ) . '"><br><small>' . esc_html( $protected_password ? __( 'An encrypted post password is configured.', 'rushleigh-cookie-choices' ) : __( 'No usable post password is configured.', 'rushleigh-cookie-choices' ) ) . '</small></p>';
		self::checkbox_field( 'remove_post_password', __( 'Remove the stored post password', 'rushleigh-cookie-choices' ), false, __( 'The password is never displayed after saving. Removing it makes protected pages ineligible immediately.', 'rushleigh-cookie-choices' ) );
		submit_button( __( 'Save scan settings', 'rushleigh-cookie-choices' ) );
		self::form_close();

		self::form_open( 'uccm_run_scan', 'uccm_run_scan' );
		echo '<p>' . esc_html__( 'The scan checks public pages first. Keeping this page open helps it continue if your site scheduler is delayed, but you can leave and return without losing saved progress. When it finishes, you may run the optional browser check.', 'rushleigh-cookie-choices' ) . '</p>';
		submit_button( __( 'Run scan now', 'rushleigh-cookie-choices' ), 'primary' );
		self::form_close();

		echo '<h2>' . esc_html__( 'Recent scan runs', 'rushleigh-cookie-choices' ) . '</h2>';
		echo '<p id="uccm-scan-progress-status" aria-live="polite"></p>';

		if ( is_wp_error( $runs ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $runs->get_error_message() ) . '</p></div>';
		} elseif ( array() === $runs ) {
			echo '<p>' . esc_html__( 'No scan runs have been recorded yet.', 'rushleigh-cookie-choices' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Run', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Status', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Started (UTC)', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Scan coverage', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Browser check', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Items to review', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Problems', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Action', 'rushleigh-cookie-choices' ) . '</th></tr></thead><tbody>';

			foreach ( $runs as $run ) {
				$summary          = json_decode( (string) $run['summary'], true );
				$pages            = json_decode( (string) $run['pages_visited'], true );
				$coverage         = json_decode( (string) $run['coverage'], true );
				$summary          = is_array( $summary ) ? $summary : array();
				$pages            = is_array( $pages ) ? $pages : array();
				$coverage         = is_array( $coverage ) ? $coverage : array();
				$warnings         = is_array( $summary['warnings'] ?? null ) ? $summary['warnings'] : array();
				$run_url          = add_query_arg(
					array(
						'page'    => 'uccm-scans',
						'scan_id' => (int) $run['id'],
					),
					admin_url( 'admin.php' )
				);
				$visited_count    = (int) ( $coverage['visited_count'] ?? count( $pages ) );
				$discovered_count = (int) ( $coverage['discovered_count'] ?? count( $pages ) );
				$remaining_count  = (int) ( $coverage['remaining_count'] ?? 0 );
				$wordpress_count  = (int) ( $coverage['wordpress_content_count'] ?? 0 );
				$accepted_count   = (int) ( $coverage['accepted_link_count'] ?? max( 0, $discovered_count - (int) ( $coverage['target_count'] ?? 0 ) ) );
				$ignored_counts   = is_array( $coverage['ignored_counts'] ?? null ) ? $coverage['ignored_counts'] : array();
				$ignored_count    = array_sum( array_map( 'intval', $ignored_counts ) );
				$browser_status   = (string) ( $coverage['browser_status'] ?? 'not-run' );
				echo '<tr><td><a href="' . esc_url( $run_url ) . '">' . esc_html( (string) $run['id'] ) . '</a></td><td>' . esc_html( (string) $run['status'] ) . '</td><td>' . esc_html( (string) $run['started_at'] ) . '</td>';
				if ( array_key_exists( 'wordpress_content_count', $coverage ) ) {
					$progress = sprintf(
						/* translators: 1: eligible WordPress pages/posts, 2: other accepted links, 3: ignored links, 4: checked URLs, 5: remaining URLs. */
						__( '%1$d pages/posts; %2$d other links; %3$d ignored; %4$d checked; %5$d remaining', 'rushleigh-cookie-choices' ),
						$wordpress_count,
						$accepted_count,
						$ignored_count,
						$visited_count,
						$remaining_count
					);
				} else {
					/* translators: 1: checked URLs, 2: accepted URLs, 3: remaining URLs. */
					$progress = sprintf( __( '%1$d checked; %2$d accepted; %3$d remaining', 'rushleigh-cookie-choices' ), $visited_count, $discovered_count, $remaining_count );
				}
				echo '<td>' . esc_html( $progress ) . '</td>';
				echo '<td>' . esc_html( $browser_status ) . '</td><td>' . esc_html( (string) (int) ( $summary['findings'] ?? 0 ) ) . '</td><td>' . esc_html( (string) count( $warnings ) ) . '</td><td>';

				if ( in_array( (string) $run['status'], array( 'queued', 'running', 'failed' ), true ) ) {
					if ( 'failed' === (string) $run['status'] ) {
						self::form_open( 'uccm_resume_scan', 'uccm_resume_scan' );
						echo '<input type="hidden" name="scan_id" value="' . esc_attr( (string) $run['id'] ) . '">';
						submit_button( __( 'Resume', 'rushleigh-cookie-choices' ), 'secondary small', '', false );
						self::form_close();
					}

					self::form_open( 'uccm_cancel_scan', 'uccm_cancel_scan' );
					echo '<input type="hidden" name="scan_id" value="' . esc_attr( (string) $run['id'] ) . '">';
					submit_button( __( 'Cancel', 'rushleigh-cookie-choices' ), 'secondary small', '', false );
					self::form_close();
				} else {
					echo '&mdash;';
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		if ( is_array( $runner_run ) && 'completed' === (string) $runner_run['status'] ) {
			$runner_coverage = json_decode( (string) $runner_run['coverage'], true );
			$runner_coverage = is_array( $runner_coverage ) ? $runner_coverage : array();
			echo '<h2>' . esc_html__( 'Browser check for scan ', 'rushleigh-cookie-choices' ) . esc_html( (string) $scan_id ) . '</h2>';
			echo '<p>' . esc_html__( 'This optional check visits eligible public pages as a temporary visitor, tries the main cookie choices, and lists cookie names, browser storage, scripts, embedded content and tracking images. It does not use your administrator sign-in or saved browser choices.', 'rushleigh-cookie-choices' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Browser check status:', 'rushleigh-cookie-choices' ) . '</strong> ' . esc_html( (string) ( $runner_coverage['browser_status'] ?? 'not-run' ) ) . '</p>';

			if ( ! empty( $runner_coverage['browser_problem'] ) ) {
				$browser_problem = (string) $runner_coverage['browser_problem'];
				$browser_note    = 'isolated-context-unavailable' === $browser_problem
					? __( 'This browser cannot run the separate visitor check safely. Use a current Chrome, Edge or other Chromium browser.', 'rushleigh-cookie-choices' )
					: str_replace( '-', ' ', $browser_problem );
				echo '<p><strong>' . esc_html__( 'Browser check note:', 'rushleigh-cookie-choices' ) . '</strong> ' . esc_html( $browser_note ) . '</p>';
			}
			echo '<button type="button" class="button button-secondary" id="uccm-run-browser-observations" aria-describedby="uccm-browser-observation-status">' . esc_html__( 'Run browser check', 'rushleigh-cookie-choices' ) . '</button>';
			echo '<p id="uccm-browser-observation-status" aria-live="polite">' . esc_html__( 'For your privacy, this check needs a current Chrome, Edge or other Chromium browser. Safari and Firefox are not supported yet.', 'rushleigh-cookie-choices' ) . '</p>';
			echo '<div id="uccm-browser-observation-frames" hidden></div>';
		}

		echo '<h2>' . esc_html__( 'Items to review', 'rushleigh-cookie-choices' ) . '</h2>';
		echo '<p>' . esc_html__( 'The plugin never adds, changes or publishes cookie information automatically. Review each item before changing the cookie inventory.', 'rushleigh-cookie-choices' ) . '</p>';

		if ( 0 < $scan_id ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=uccm-scans' ) ) . '">' . esc_html__( 'Show findings from all scans', 'rushleigh-cookie-choices' ) . '</a></p>';
		}

		if ( is_wp_error( $findings ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $findings->get_error_message() ) . '</p></div>';
		} elseif ( array() === $findings ) {
			echo '<p>' . esc_html__( 'No scan findings match this view.', 'rushleigh-cookie-choices' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Finding', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Observation', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Material diff', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Status', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Review', 'rushleigh-cookie-choices' ) . '</th></tr></thead><tbody>';

			foreach ( $findings as $finding ) {
				$before = json_decode( (string) $finding['before_data'], true );
				$after  = json_decode( (string) $finding['after_data'], true );
				$before = is_array( $before ) ? $before : array();
				$after  = is_array( $after ) ? $after : array();
				echo '<tr><td>#' . esc_html( (string) $finding['id'] ) . '<br><small>' . esc_html( (string) $finding['finding_type'] ) . ' · ' . esc_html__( 'scan', 'rushleigh-cookie-choices' ) . ' ' . esc_html( (string) $finding['scan_run_id'] ) . '</small></td>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes all values and returns only fixed markup.
				echo '<td>' . self::finding_observation_html( $finding, $before, $after ) . '</td>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes all values and returns only fixed markup.
				echo '<td>' . self::finding_diff_html( $before, $after ) . '</td>';
				echo '<td>' . esc_html( (string) $finding['status'] ) . '</td><td>';

				if ( 'pending' === $finding['status'] && current_user_can( 'manage_uccm_inventory' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
					$outcomes = array(
						'reviewed' => __( 'Mark reviewed', 'rushleigh-cookie-choices' ),
						'ignored'  => __( 'Ignore', 'rushleigh-cookie-choices' ),
						'resolved' => __( 'Resolve', 'rushleigh-cookie-choices' ),
					);

					foreach ( $outcomes as $outcome => $label ) {
						echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 4px 4px 0">';
						echo '<input type="hidden" name="action" value="uccm_review_scan_finding">';
						echo '<input type="hidden" name="finding_id" value="' . esc_attr( (string) $finding['id'] ) . '">';
						echo '<input type="hidden" name="finding_status" value="' . esc_attr( $outcome ) . '">';
						wp_nonce_field( 'uccm_review_scan_finding' );
						submit_button( $label, 'secondary small', '', false );
						echo '</form>';
					}
				} else {
					echo '&mdash;';
				}

				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}

		self::close_page();
	}

	/**
	 * Render masked consent receipt evidence.
	 */
	public static function render_consents(): void {
		self::require_capability( 'view_uccm_consents' );
		$records = Consent_Receipts::records( 'view_uccm_consents', 50, false );
		self::open_page( __( 'Consent Records', 'rushleigh-cookie-choices' ) );

		if ( is_wp_error( $records ) || array() === $records ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No consent records are available to display.', 'rushleigh-cookie-choices' ) . '</p></div>';
			self::close_page();
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Receipt', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Time (UTC)', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Action', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Language', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Policy / wording', 'rushleigh-cookie-choices' ) . '</th><th>' . esc_html__( 'Masked IP', 'rushleigh-cookie-choices' ) . '</th></tr></thead><tbody>';

		foreach ( $records as $record ) {
			echo '<tr><td><code>' . esc_html( (string) $record['receipt_id'] ) . '</code></td><td>' . esc_html( (string) $record['occurred_at'] ) . '</td><td>' . esc_html( (string) $record['action'] ) . '</td><td>' . esc_html( (string) $record['language'] ) . '</td><td>' . esc_html( (string) $record['policy_version'] ) . ' / ' . esc_html( (string) $record['wording_version'] ) . '</td><td>' . esc_html( (string) $record['ip_masked'] ) . '</td></tr>';
		}

		echo '</tbody></table>';
		self::close_page();
	}

	/**
	 * Render privacy and retention settings.
	 */
	public static function render_privacy(): void {
		self::require_capability( 'manage_uccm_settings' );
		$settings            = Settings::current();
		$trust_proxy_headers = ! empty( $settings['trust_proxy_headers'] );

		wp_enqueue_script(
			'uccm-privacy-settings',
			plugin_dir_url( UCCM_PLUGIN_FILE ) . 'assets/js/admin-privacy.js',
			array(),
			UCCM_VERSION,
			true
		);

		self::open_page( __( 'Privacy', 'rushleigh-cookie-choices' ) );
		self::saved_notice();
		self::form_open( 'uccm_save_settings', 'uccm_save_privacy' );
		echo '<input type="hidden" name="section" value="privacy">';
		self::number_field( 'retention_days', __( 'Consent retention (days)', 'rushleigh-cookie-choices' ), (int) $settings['retention_days'], 1, 3650, Settings::is_network_locked( 'retention_days' ) );
		self::network_setting_control( 'retention_days' );
		self::checkbox_field( 'store_full_ip', __( 'Store encrypted complete IP addresses', 'rushleigh-cookie-choices' ), ! empty( $settings['store_full_ip'] ), __( 'This increases privacy risk and is not required for normal consent evidence.', 'rushleigh-cookie-choices' ) );
		echo '<p><label><input id="uccm-trust-proxy-headers" data-uccm-trust-proxy-headers type="checkbox" name="uccm[trust_proxy_headers]" value="1" aria-controls="uccm-trusted-proxies-settings" aria-expanded="' . esc_attr( $trust_proxy_headers ? 'true' : 'false' ) . '" ' . checked( $trust_proxy_headers, true, false ) . '> <strong>' . esc_html__( 'Trust forwarded IP headers', 'rushleigh-cookie-choices' ) . '</strong></label><br><span class="description">' . esc_html__( 'Enable only when every trusted reverse proxy is listed below.', 'rushleigh-cookie-choices' ) . '</span></p>';
		$proxies = is_array( $settings['trusted_proxy_ips'] ) ? implode( "\n", $settings['trusted_proxy_ips'] ) : '';
		echo '<div id="uccm-trusted-proxies-settings" data-uccm-trusted-proxies-settings' . ( $trust_proxy_headers ? '' : ' hidden' ) . '>';
		echo '<p><label for="uccm-trusted-proxies"><strong>' . esc_html__( 'Trusted proxy IPs', 'rushleigh-cookie-choices' ) . '</strong></label><br><textarea id="uccm-trusted-proxies" class="large-text code" rows="5" name="uccm[trusted_proxy_ips]" aria-describedby="uccm-trusted-proxies-description"' . ( $trust_proxy_headers ? '' : ' disabled aria-disabled="true"' ) . '>' . esc_textarea( $proxies ) . '</textarea><br><span class="description" id="uccm-trusted-proxies-description">' . esc_html__( 'Enter one proxy IP address per line. Only these proxies may supply a forwarded visitor address.', 'rushleigh-cookie-choices' ) . '</span></p>';
		echo '</div>';
		submit_button( __( 'Save privacy settings', 'rushleigh-cookie-choices' ) );
		self::form_close();
		self::close_page();
	}

	/**
	 * Render destructive-data lifecycle settings.
	 */
	public static function render_advanced(): void {
		self::require_capability( 'manage_uccm_settings' );
		$settings    = Settings::current();
		$status      = Secure_Updater::status();
		$diagnostics = Secure_Updater::diagnostics();
		$plugins_url = is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
		$free_bytes  = $diagnostics['free_bytes'] ?? null;

		if ( null === $free_bytes ) {
			$disk_message = __( 'Free disk space could not be measured.', 'rushleigh-cookie-choices' );
		} elseif ( ! empty( $diagnostics['disk_space_usable'] ) ) {
			$disk_message = sprintf(
				/* translators: %s: Human-readable free disk space. */
				__( '%s of free disk space is available.', 'rushleigh-cookie-choices' ),
				size_format( (int) $free_bytes )
			);
		} else {
			$disk_message = sprintf(
				/* translators: %s: Human-readable free disk space. */
				__( 'Only %s of free disk space is available; make more space before updating.', 'rushleigh-cookie-choices' ),
				size_format( (int) $free_bytes )
			);
		}

		if ( 'available' === (string) ( $diagnostics['loopback'] ?? '' ) ) {
			$loopback_message = __( 'The WordPress loopback check succeeded.', 'rushleigh-cookie-choices' );
		} elseif ( 'unavailable' === (string) ( $diagnostics['loopback'] ?? '' ) ) {
			$loopback_message = __( 'The WordPress loopback check failed; fatal-error rollback may not work.', 'rushleigh-cookie-choices' );
		} else {
			$loopback_message = __( 'Use “Check for updates now” to test the WordPress loopback connection.', 'rushleigh-cookie-choices' );
		}

		self::open_page( __( 'Advanced', 'rushleigh-cookie-choices' ) );
		self::saved_notice();

		echo '<h2>' . esc_html__( 'Updates', 'rushleigh-cookie-choices' ) . '</h2>';
		echo '<p>' . esc_html( (string) $status['channel_description'] ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Installed version', 'rushleigh-cookie-choices' ) . '</th><td><code>' . esc_html( (string) $status['installed_version'] ) . '</code></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Latest available version', 'rushleigh-cookie-choices' ) . '</th><td>' . esc_html( '' !== (string) $status['latest_version'] ? (string) $status['latest_version'] : __( 'Not checked yet', 'rushleigh-cookie-choices' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Update channel', 'rushleigh-cookie-choices' ) . '</th><td>' . esc_html( (string) $status['channel'] ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Last successful check (UTC)', 'rushleigh-cookie-choices' ) . '</th><td>' . esc_html( '' !== (string) $status['last_successful_check_at'] ? (string) $status['last_successful_check_at'] : __( 'Not checked yet', 'rushleigh-cookie-choices' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Last update outcome', 'rushleigh-cookie-choices' ) . '</th><td>' . esc_html( '' !== (string) $status['last_update_outcome'] ? (string) $status['last_update_outcome'] : __( 'No update recorded yet', 'rushleigh-cookie-choices' ) ) . '</td></tr>';
		echo '</tbody></table>';

		if ( '' !== (string) $status['last_error_code'] ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Last update check problem:', 'rushleigh-cookie-choices' ) . '</strong> ' . esc_html( (string) $status['last_error_message'] ) . ' <code>' . esc_html( (string) $status['last_error_code'] ) . '</code></p></div>';
		}

		if ( ! $status['rollout_eligible'] && '' !== (string) $status['latest_version'] && version_compare( (string) $status['latest_version'], UCCM_VERSION, '>' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'A newer release is being introduced gradually. This site will continue using its current version until its rollout group is enabled.', 'rushleigh-cookie-choices' ) . '</p></div>';
		}

		if ( current_user_can( 'update_plugins' ) ) {
			echo '<p><a class="button" href="' . esc_url( $plugins_url ) . '">' . esc_html__( 'Manage automatic updates in WordPress', 'rushleigh-cookie-choices' ) . '</a></p>';
			self::form_open( 'uccm_check_updates', 'uccm_check_updates' );
			submit_button( __( 'Check for updates now', 'rushleigh-cookie-choices' ), 'secondary', 'submit', false );
			self::form_close();
		} else {
			echo '<p>' . esc_html__( 'A WordPress administrator with plugin-update permission controls automatic updates and immediate checks.', 'rushleigh-cookie-choices' ) . '</p>';
		}

		echo '<h3>' . esc_html__( 'Update recovery readiness', 'rushleigh-cookie-choices' ) . '</h3><ul>';
		echo '<li>' . esc_html( ! empty( $diagnostics['rollback_supported'] ) ? __( 'WordPress fatal-error rollback is available.', 'rushleigh-cookie-choices' ) : __( 'This WordPress version does not provide the required plugin fatal-error rollback.', 'rushleigh-cookie-choices' ) ) . '</li>';
		echo '<li>' . esc_html( ! empty( $diagnostics['backup_writable'] ) ? __( 'The temporary backup location is writable.', 'rushleigh-cookie-choices' ) : __( 'The temporary backup location is not writable.', 'rushleigh-cookie-choices' ) ) . '</li>';
		echo '<li>' . esc_html( $disk_message ) . '</li>';
		echo '<li>' . esc_html( $loopback_message ) . '</li></ul>';

		self::form_open( 'uccm_save_settings', 'uccm_save_advanced' );
		echo '<input type="hidden" name="section" value="advanced">';
		echo '<hr><h2>' . esc_html__( 'Operational error notifications', 'rushleigh-cookie-choices' ) . '</h2>';
		self::checkbox_field( 'error_email_enabled', __( 'Email operational error notifications to the site administrator', 'rushleigh-cookie-choices' ), ! empty( $settings['error_email_enabled'] ), __( 'Disabled by default. Messages use WordPress email delivery and contain no consent records or credentials.', 'rushleigh-cookie-choices' ), Settings::is_network_locked( 'error_email_enabled' ) );
		self::network_setting_control( 'error_email_enabled' );
		$email_suppression_locked = Settings::is_network_locked( 'error_email_suppression_minutes' );
		echo '<p><label for="uccm-error-email-suppression"><strong>' . esc_html__( 'Repeat email suppression (minutes)', 'rushleigh-cookie-choices' ) . '</strong></label><br><input id="uccm-error-email-suppression" class="small-text" type="number" min="1" max="' . esc_attr( (string) Settings::MAX_ERROR_EMAIL_SUPPRESSION_MINUTES ) . '" step="1" name="uccm[error_email_suppression_minutes]" value="' . esc_attr( (string) $settings['error_email_suppression_minutes'] ) . '"' . ( $email_suppression_locked ? ' disabled aria-disabled="true"' : '' ) . '><br><small>' . esc_html__( 'Wait this many minutes before the same site, component and scan problem may send another email. Default: 360. Maximum: 1,440 (24 hours).', 'rushleigh-cookie-choices' ) . '</small></p>';
		self::network_setting_control( 'error_email_suppression_minutes' );
		echo '<hr>';
		$network_active = Multisite::is_network_active();
		self::checkbox_field(
			'delete_data_on_uninstall',
			__( 'Delete all UCCM data when the plugin is uninstalled', 'rushleigh-cookie-choices' ),
			! $network_active && true === get_option( 'uccm_delete_data_on_uninstall', false ),
			$network_active
				? __( 'Network-wide deletion can be approved only by a Network Administrator from Network Admin.', 'rushleigh-cookie-choices' )
				: __( 'Leave disabled to retain settings and evidence by default.', 'rushleigh-cookie-choices' ),
			$network_active
		);
		submit_button( __( 'Save advanced settings', 'rushleigh-cookie-choices' ) );
		self::form_close();
		self::close_page();
	}

	/**
	 * Render bounded site-wide browser evidence without values or page content.
	 *
	 * @param array<string, mixed> $finding Finding database row.
	 * @param array<string, mixed> $before  Previous curated values.
	 * @param array<string, mixed> $after   Observed values.
	 */
	private static function finding_observation_html( array $finding, array $before, array $after ): string {
		$storage_type   = (string) ( $after['storage_type'] ?? $before['storage_type'] ?? 'other' );
		$consent_states = is_array( $after['consent_states'] ?? null ) ? $after['consent_states'] : array();
		$source_urls    = is_array( $after['source_urls'] ?? null ) ? array_slice( $after['source_urls'], 0, 20 ) : array();
		$source_count   = min( Scanner::BROWSER_MAX_TARGETS, max( count( $source_urls ), (int) ( $after['source_count'] ?? 0 ) ) );
		$parts          = array(
			'<strong>' . esc_html( (string) $finding['storage_key'] ) . '</strong>',
			esc_html( (string) $finding['domain'] ),
			'<small>' . esc_html__( 'Storage type:', 'rushleigh-cookie-choices' ) . ' ' . esc_html( ucwords( str_replace( '_', ' ', $storage_type ) ) ) . '</small>',
		);

		if ( array() !== $consent_states ) {
			$parts[] = '<small>' . esc_html__( 'Seen with:', 'rushleigh-cookie-choices' ) . ' ' . esc_html( implode( ', ', array_map( static fn ( mixed $state ): string => str_replace( '-', ' ', sanitize_key( (string) $state ) ), $consent_states ) ) ) . '</small>';
		}

		if ( 0 < $source_count ) {
			$parts[] = '<small>' . esc_html(
				sprintf(
					/* translators: %d: number of affected pages. */
					_n( '%d affected page', '%d affected pages', $source_count, 'rushleigh-cookie-choices' ),
					$source_count
				)
			) . '</small>';
		}

		if ( array() !== $source_urls ) {
			$links   = array_map(
				static fn ( mixed $url ): string => '<a href="' . esc_url( (string) $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) $url ) . '</a>',
				$source_urls
			);
			$parts[] = '<details><summary>' . esc_html__( 'Show affected pages', 'rushleigh-cookie-choices' ) . '</summary>' . implode( '<br>', $links ) . '</details>';
		}

		return implode( '<br>', $parts );
	}

	/**
	 * Render a safe material-field diff.
	 *
	 * @param array<string, mixed> $before Previous curated values.
	 * @param array<string, mixed> $after  Observed values.
	 */
	private static function finding_diff_html( array $before, array $after ): string {
		$parts  = array();
		$labels = array(
			'duration'   => __( 'Duration', 'rushleigh-cookie-choices' ),
			'domain'     => __( 'Domain', 'rushleigh-cookie-choices' ),
			'source_url' => __( 'Source URL', 'rushleigh-cookie-choices' ),
			'category'   => __( 'Category candidate', 'rushleigh-cookie-choices' ),
		);

		foreach ( Scan_Findings::material_fields() as $field ) {
			if ( ! array_key_exists( $field, $after ) ) {
				continue;
			}

			$old     = (string) ( $before[ $field ] ?? __( 'not recorded', 'rushleigh-cookie-choices' ) );
			$new     = (string) $after[ $field ];
			$parts[] = '<strong>' . esc_html( (string) ( $labels[ $field ] ?? $field ) ) . ':</strong> ' . esc_html( $old ) . ' &rarr; ' . esc_html( $new );
		}

		if ( array() === $parts ) {
			return esc_html__( 'New observation', 'rushleigh-cookie-choices' );
		}

		return implode( '<br>', $parts );
	}

	/**
	 * Render inventory filters and export control.
	 *
	 * @param array<string, string> $filters Current filters.
	 */
	private static function render_inventory_filters( array $filters ): void {
		echo '<form method="get"><input type="hidden" name="page" value="uccm-inventory"><label class="screen-reader-text" for="uccm-search">' . esc_html__( 'Search inventory', 'rushleigh-cookie-choices' ) . '</label><input id="uccm-search" type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search inventory', 'rushleigh-cookie-choices' ) . '"> ';
		echo '<select name="uccm_category"><option value="">' . esc_html__( 'All categories', 'rushleigh-cookie-choices' ) . '</option>';
		self::options( array( 'necessary', 'functional', 'analytics', 'marketing' ), $filters['category'] );
		echo '</select> <select name="uccm_status"><option value="">' . esc_html__( 'All statuses', 'rushleigh-cookie-choices' ) . '</option>';
		self::options( array( 'known', 'new', 'changed', 'ignored', 'resolved' ), $filters['status'] );
		echo '</select> ';
		submit_button( __( 'Filter', 'rushleigh-cookie-choices' ), 'secondary', '', false );
		echo '</form>';
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'        => 'uccm_export_inventory',
					'uccm_category' => $filters['category'],
					'uccm_status'   => $filters['status'],
					's'             => $filters['search'],
				),
				admin_url( 'admin-post.php' )
			),
			'uccm_export_inventory'
		);
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export current filters as CSV', 'rushleigh-cookie-choices' ) . '</a></p>';
	}

	/**
	 * Render the create/update inventory form.
	 */
	private static function render_inventory_form(): void {
		echo '<hr><h2>' . esc_html__( 'Add or update an inventory item', 'rushleigh-cookie-choices' ) . '</h2>';
		self::form_open( 'uccm_save_inventory', 'uccm_save_inventory' );
		echo '<input type="number" min="0" name="uccm[id]" value="0" aria-label="' . esc_attr__( 'Existing database ID, or zero for a new item', 'rushleigh-cookie-choices' ) . '"> ';
		echo '<input required maxlength="191" name="uccm[storage_key]" placeholder="' . esc_attr__( 'Cookie or storage key', 'rushleigh-cookie-choices' ) . '"> ';
		echo '<input maxlength="191" name="uccm[provider]" placeholder="' . esc_attr__( 'Provider', 'rushleigh-cookie-choices' ) . '"> ';
		echo '<input maxlength="191" name="uccm[domain]" placeholder="' . esc_attr__( 'Domain', 'rushleigh-cookie-choices' ) . '"><br><br>';
		echo '<select name="uccm[party]">';
		self::options( array( 'first-party', 'third-party' ), 'first-party' );
		echo '</select> ';
		echo '<select name="uccm[storage_type]">';
		self::options( array( 'cookie', 'local_storage', 'session_storage', 'other' ), 'cookie' );
		echo '</select> ';
		echo '<select name="uccm[category]">';
		self::options( array( 'necessary', 'functional', 'analytics', 'marketing' ), 'necessary' );
		echo '</select> ';
		echo '<select name="uccm[status]">';
		self::options( array( 'known', 'new', 'changed', 'ignored', 'resolved' ), 'known' );
		echo '</select><br><br>';
		echo '<textarea required class="large-text" rows="3" name="uccm[purpose]" placeholder="' . esc_attr__( 'Purpose', 'rushleigh-cookie-choices' ) . '"></textarea>';
		echo '<input class="regular-text" maxlength="100" name="uccm[duration]" placeholder="' . esc_attr__( 'Duration', 'rushleigh-cookie-choices' ) . '"> ';
		echo '<input class="regular-text" type="url" name="uccm[source_url]" placeholder="https://">';
		submit_button( __( 'Save inventory item', 'rushleigh-cookie-choices' ) );
		self::form_close();
	}

	/**
	 * Render simple pagination.
	 *
	 * @param int                   $page    Current page.
	 * @param int                   $pages   Total pages.
	 * @param array<string, string> $filters Current filters.
	 */
	private static function render_pagination( int $page, int $pages, array $filters ): void {
		if ( 1 >= $pages ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'      => add_query_arg(
					array(
						'page'          => 'uccm-inventory',
						'uccm_category' => $filters['category'],
						'uccm_status'   => $filters['status'],
						's'             => $filters['search'],
						'paged'         => '%#%',
					),
					admin_url( 'admin.php' )
				),
				'current'   => $page,
				'total'     => $pages,
				'type'      => 'list',
				'prev_text' => __( 'Previous', 'rushleigh-cookie-choices' ),
				'next_text' => __( 'Next', 'rushleigh-cookie-choices' ),
			)
		);

		echo '<nav aria-label="' . esc_attr__( 'Inventory pages', 'rushleigh-cookie-choices' ) . '">' . wp_kses_post( $links ) . '</nav>';
	}

	/**
	 * Return current inventory filters.
	 *
	 * @return array{category: string, status: string, search: string}
	 */
	private static function inventory_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only allowlisted filters.
		$filters = array(
			'category' => sanitize_key( self::request_value( $_GET, 'uccm_category' ) ),
			'status'   => sanitize_key( self::request_value( $_GET, 'uccm_status' ) ),
			'search'   => sanitize_text_field( self::request_value( $_GET, 's' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return $filters;
	}

	/**
	 * Return a scalar request value after unslashing.
	 *
	 * @param array<string, mixed> $source Request source.
	 * @param string               $key    Field key.
	 */
	private static function request_value( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';
		return is_scalar( $value ) ? (string) wp_unslash( $value ) : '';
	}

	/**
	 * Return allowlisted network settings selected for inheritance.
	 *
	 * @return string[]
	 */
	private static function submitted_inheritance(): array {
		$submitted = isset( $_POST['uccm_inherit'] ) && is_array( $_POST['uccm_inherit'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['uccm_inherit'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Each caller verifies its form nonce before use.
		$submitted = array_map( 'strval', array_filter( $submitted, 'is_scalar' ) );

		return array_values( array_intersect( $submitted, Multisite::manageable_settings() ) );
	}

	/**
	 * Enforce a dedicated capability before rendering or mutating.
	 *
	 * @param string $capability Required capability.
	 */
	private static function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to access this Cookie Consent screen.', 'rushleigh-cookie-choices' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect back to a successful administration screen.
	 *
	 * @param string $page   Screen slug.
	 * @param string $notice Notice code.
	 */
	private static function redirect( string $page, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => $page,
					'uccm_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Open standard page markup.
	 *
	 * @param string $title Page title.
	 */
	private static function open_page( string $title ): void {
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
	}

	/**
	 * Close standard page markup.
	 */
	private static function close_page(): void {
		echo '</div>';
	}

	/**
	 * Open a protected admin-post form.
	 *
	 * @param string $action       Admin-post action.
	 * @param string $nonce_action Nonce action.
	 */
	private static function form_open( string $action, string $nonce_action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $nonce_action );
	}

	/**
	 * Close a form.
	 */
	private static function form_close(): void {
		echo '</form>';
	}

	/**
	 * Render a saved notice from a bounded query value.
	 */
	private static function saved_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded notice state.
		$notice = self::request_value( $_GET, 'uccm_notice' );

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'rushleigh-cookie-choices' ) . '</p></div>';
		} elseif ( 'updates-checked' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The update check finished. The latest result is shown below.', 'rushleigh-cookie-choices' ) . '</p></div>';
		}
	}

	/**
	 * Render network inheritance or lock information for one site setting.
	 *
	 * @param string $name Setting name.
	 */
	private static function network_setting_control( string $name ): void {
		if ( ! Multisite::is_network_active() || ! in_array( $name, Multisite::manageable_settings(), true ) ) {
			return;
		}

		if ( Settings::is_network_locked( $name ) ) {
			echo '<p class="description">' . esc_html__( 'This value is locked by the Network Administrator.', 'rushleigh-cookie-choices' ) . '</p>';
			return;
		}

		echo '<p class="description"><label><input type="checkbox" name="uccm_inherit[]" value="' . esc_attr( $name ) . '" ' . checked( Settings::is_network_inherited( $name ), true, false ) . '> ' . esc_html__( 'Use the network default for this site', 'rushleigh-cookie-choices' ) . '</label></p>';
	}

	/**
	 * Render a number field.
	 *
	 * @param string $name  Field name.
	 * @param string $label Visible label.
	 * @param int    $value Current value.
	 * @param int    $min   Minimum value.
	 * @param int    $max      Maximum value.
	 * @param bool   $disabled Whether the network has locked the value.
	 */
	private static function number_field( string $name, string $label, int $value, int $min, int $max, bool $disabled = false ): void {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><input type="number" name="uccm[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '"' . ( $disabled ? ' disabled aria-disabled="true"' : '' ) . '></label></p>';
	}

	/**
	 * Render a text field.
	 *
	 * @param string $name  Field name.
	 * @param string $label Visible label.
	 * @param string $value Current value.
	 */
	private static function text_field( string $name, string $label, string $value ): void {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><input class="regular-text" name="uccm[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '"></label></p>';
	}

	/**
	 * Render a six-digit colour field.
	 *
	 * @param string $name  Field name.
	 * @param string $label Visible label.
	 * @param string $value Current value.
	 */
	private static function colour_field( string $name, string $label, string $value ): void {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><input type="color" name="uccm[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '" data-uccm-style-field="' . esc_attr( $name ) . '"> <code>' . esc_html( $value ) . '</code></label></p>';
	}

	/**
	 * Render an allowlisted select field.
	 *
	 * @param string               $name    Field name.
	 * @param string               $label   Visible label.
	 * @param array<string,string> $choices Value and label pairs.
	 * @param string               $current Current value.
	 */
	private static function select_field( string $name, string $label, array $choices, string $current ): void {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><select name="uccm[' . esc_attr( $name ) . ']" data-uccm-style-field="' . esc_attr( $name ) . '">';

		foreach ( $choices as $value => $choice_label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $choice_label ) . '</option>';
		}

		echo '</select></label></p>';
	}

	/**
	 * Render a checkbox with explanatory text.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Visible label.
	 * @param bool   $checked     Whether the checkbox is selected.
	 * @param string $description Explanatory text.
	 * @param bool   $disabled    Whether the network has locked the value.
	 */
	private static function checkbox_field( string $name, string $label, bool $checked, string $description, bool $disabled = false ): void {
		echo '<p><label><input type="checkbox" name="uccm[' . esc_attr( $name ) . ']" value="1" ' . checked( $checked, true, false ) . ( $disabled ? ' disabled aria-disabled="true"' : '' ) . '> <strong>' . esc_html( $label ) . '</strong></label><br><span class="description">' . esc_html( $description ) . '</span></p>';
	}

	/**
	 * Render allowlisted select options.
	 *
	 * @param string[] $values  Option values.
	 * @param string   $current Selected value.
	 */
	private static function options( array $values, string $current ): void {
		foreach ( $values as $value ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( ucwords( str_replace( array( '-', '_' ), ' ', $value ) ) ) . '</option>';
		}
	}
}
