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
		add_action( 'admin_post_uccm_save_blocking_rules', array( self::class, 'save_blocking_rules' ) );
		add_action( 'admin_post_uccm_save_scan_settings', array( self::class, 'save_scan_settings' ) );
		add_action( 'admin_post_uccm_run_scan', array( self::class, 'run_scan' ) );
		add_action( 'admin_post_uccm_cancel_scan', array( self::class, 'cancel_scan' ) );
		add_action( 'admin_post_uccm_resume_scan', array( self::class, 'resume_scan' ) );
		add_action( 'wp_ajax_uccm_browser_scan_observations', array( self::class, 'browser_scan_observations' ) );
		add_action( 'admin_post_uccm_review_scan_finding', array( self::class, 'review_scan_finding' ) );
		add_action( 'admin_post_uccm_save_inventory', array( self::class, 'save_inventory' ) );
		add_action( 'admin_post_uccm_export_inventory', array( self::class, 'export_inventory' ) );
	}

	/**
	 * Return the nine-screen administration contract.
	 *
	 * @return array<string, array{title: string, capability: string, callback: callable}>
	 */
	public static function screens(): array {
		return array(
			self::MENU_SLUG   => array(
				'title'      => __( 'Overview', 'uk-cookie-consent-manager' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_overview' ),
			),
			'uccm-banner'     => array(
				'title'      => __( 'Banner', 'uk-cookie-consent-manager' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_banner' ),
			),
			'uccm-categories' => array(
				'title'      => __( 'View Categories', 'uk-cookie-consent-manager' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_categories' ),
			),
			'uccm-blocking'   => array(
				'title'      => __( 'Script Blocking', 'uk-cookie-consent-manager' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_blocking' ),
			),
			'uccm-inventory'  => array(
				'title'      => __( 'Cookie Inventory', 'uk-cookie-consent-manager' ),
				'capability' => 'manage_uccm_inventory',
				'callback'   => array( self::class, 'render_inventory' ),
			),
			'uccm-scans'      => array(
				'title'      => __( 'Scans', 'uk-cookie-consent-manager' ),
				'capability' => 'run_uccm_scans',
				'callback'   => array( self::class, 'render_scans' ),
			),
			'uccm-consents'   => array(
				'title'      => __( 'Consent Records', 'uk-cookie-consent-manager' ),
				'capability' => 'view_uccm_consents',
				'callback'   => array( self::class, 'render_consents' ),
			),
			'uccm-privacy'    => array(
				'title'      => __( 'Privacy', 'uk-cookie-consent-manager' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_privacy' ),
			),
			'uccm-advanced'   => array(
				'title'      => __( 'Advanced', 'uk-cookie-consent-manager' ),
				'capability' => 'manage_uccm_settings',
				'callback'   => array( self::class, 'render_advanced' ),
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
			__( 'UK Cookie Consent Manager', 'uk-cookie-consent-manager' ),
			__( 'Cookie Consent', 'uk-cookie-consent-manager' ),
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

		if ( 'banner' === $section ) {
			Settings::update(
				array(
					'consent_lifetime_days'  => $submitted['consent_lifetime_days'] ?? 180,
					'consent_policy_version' => $submitted['consent_policy_version'] ?? Consent_State::POLICY_VERSION,
				)
			);
		} elseif ( 'privacy' === $section ) {
			Settings::update(
				array(
					'retention_days'      => $submitted['retention_days'] ?? 365,
					'store_full_ip'       => $submitted['store_full_ip'] ?? false,
					'trust_proxy_headers' => $submitted['trust_proxy_headers'] ?? false,
					'trusted_proxy_ips'   => $submitted['trusted_proxy_ips'] ?? '',
				)
			);
		} elseif ( 'advanced' === $section ) {
			Settings::update(
				array(
					'update_manifest_url' => $submitted['update_manifest_url'] ?? '',
					'update_public_key'   => $submitted['update_public_key'] ?? '',
					'auto_update'         => $submitted['auto_update'] ?? false,
				)
			);

			if ( ! empty( $submitted['clear_update_credential'] ) ) {
				Secure_Updater::clear_credential();
			} elseif ( isset( $submitted['update_credential'] ) && '' !== trim( (string) $submitted['update_credential'] ) ) {
				$credential_result = Secure_Updater::save_credential( (string) $submitted['update_credential'] );

				if ( is_wp_error( $credential_result ) ) {
					wp_die( esc_html( $credential_result->get_error_message() ), '', array( 'response' => 400 ) );
				}
			}

			update_option( 'uccm_delete_data_on_uninstall', ! empty( $submitted['delete_data_on_uninstall'] ), false );
		} else {
			wp_die( esc_html__( 'The settings section is invalid.', 'uk-cookie-consent-manager' ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-' . $section, 'saved' );
	}

	/**
	 * Persist explicitly declared resource rules from JSON.
	 */
	public static function save_blocking_rules(): void {
		self::require_capability( 'manage_uccm_settings' );
		check_admin_referer( 'uccm_save_blocking' );
		$json  = self::request_value( $_POST, 'rules' );
		$rules = self::sanitize_blocking_rules( $json );

		if ( is_wp_error( $rules ) ) {
			wp_die( esc_html( $rules->get_error_message() ), '', array( 'response' => 400 ) );
		}

		update_option( Resource_Rules::OPTION_NAME, $rules, false );
		self::redirect( 'uccm-blocking', 'saved' );
	}

	/**
	 * Validate explicit resource rules submitted through administration.
	 *
	 * @param string $json JSON rule map.
	 * @return array<string, array<string, string>>|\WP_Error
	 */
	public static function sanitize_blocking_rules( string $json ): array|\WP_Error {
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'uccm_invalid_rules_json', __( 'Blocking rules must be a JSON object.', 'uk-cookie-consent-manager' ) );
		}

		$rules = array();

		foreach ( $decoded as $rule_id => $rule ) {
			$id       = sanitize_key( (string) $rule_id );
			$type     = is_array( $rule ) ? sanitize_key( (string) ( $rule['type'] ?? '' ) ) : '';
			$category = is_array( $rule ) ? sanitize_key( (string) ( $rule['category'] ?? '' ) ) : '';
			$handle   = is_array( $rule ) ? sanitize_key( (string) ( $rule['handle'] ?? '' ) ) : '';
			$source   = is_array( $rule ) ? esc_url_raw( (string) ( $rule['source'] ?? '' ) ) : '';
			$title    = is_array( $rule ) ? sanitize_text_field( (string) ( $rule['title'] ?? '' ) ) : '';

			if ( '' === $id || ! in_array( $type, array( 'script', 'iframe', 'embed', 'pixel' ), true ) || ! in_array( $category, array( 'functional', 'analytics', 'marketing' ), true ) ) {
				return new \WP_Error( 'uccm_invalid_blocking_rule', __( 'Each blocking rule needs a valid ID, type and optional category.', 'uk-cookie-consent-manager' ) );
			}

			if ( ( 'script' === $type && '' === $handle && '' === $source ) || ( 'script' !== $type && '' === $source ) ) {
				return new \WP_Error( 'uccm_invalid_blocking_source', __( 'Each blocking rule needs an applicable handle or HTTPS source.', 'uk-cookie-consent-manager' ) );
			}

			$rules[ $id ] = compact( 'type', 'category', 'handle', 'source', 'title' );
		}

		return $rules;
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

		Settings::update(
			array(
				'scan_urls'           => $urls,
				'scan_excluded_paths' => $submitted['scan_excluded_paths'] ?? Crawler::DEFAULT_EXCLUDED_PATHS,
				'scan_page_limit'     => $submitted['scan_page_limit'] ?? Scanner::MAX_TARGETS,
				'scan_batch_size'     => $submitted['scan_batch_size'] ?? Scanner::DEFAULT_BATCH_SIZE,
			)
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
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'The scan could not be cancelled.', 'uk-cookie-consent-manager' );
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
			$message = is_wp_error( $result ) ? $result->get_error_message() : __( 'The scan could not be resumed.', 'uk-cookie-consent-manager' );
			wp_die( esc_html( $message ), '', array( 'response' => 400 ) );
		}

		self::redirect( 'uccm-scans', 'scan-resumed' );
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
		self::open_page( __( 'Cookie Consent Overview', 'uk-cookie-consent-manager' ) );
		echo '<p>' . esc_html__( 'Configure consent, review observed storage and inspect privacy-preserving evidence from this menu.', 'uk-cookie-consent-manager' ) . '</p>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Cookie scans run in resumable background batches. Detected changes remain review findings until an administrator explicitly updates the curated inventory.', 'uk-cookie-consent-manager' ) . '</p></div>';
		self::close_page();
	}

	/**
	 * Render banner settings.
	 */
	public static function render_banner(): void {
		self::require_capability( 'manage_uccm_settings' );
		$settings = Settings::current();
		self::open_page( __( 'Banner', 'uk-cookie-consent-manager' ) );
		self::saved_notice();
		self::form_open( 'uccm_save_settings', 'uccm_save_banner' );
		echo '<input type="hidden" name="section" value="banner">';
		self::number_field( 'consent_lifetime_days', __( 'Consent lifetime (days)', 'uk-cookie-consent-manager' ), (int) $settings['consent_lifetime_days'], 1, 730 );
		self::text_field( 'consent_policy_version', __( 'Consent policy version', 'uk-cookie-consent-manager' ), (string) $settings['consent_policy_version'] );
		submit_button( __( 'Save banner settings', 'uk-cookie-consent-manager' ) );
		self::form_close();
		self::close_page();
	}

	/**
	 * Render the category contract.
	 */
	public static function render_categories(): void {
		self::require_capability( 'manage_uccm_settings' );
		self::open_page( __( 'View Categories', 'uk-cookie-consent-manager' ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Category', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Purpose', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Required', 'uk-cookie-consent-manager' ) . '</th></tr></thead><tbody>';

		foreach ( Consent_State::categories() as $category ) {
			echo '<tr><td>' . esc_html( $category['label'] ) . '</td><td>' . esc_html( $category['description'] ) . '</td><td>' . esc_html( $category['required'] ? __( 'Yes', 'uk-cookie-consent-manager' ) : __( 'No', 'uk-cookie-consent-manager' ) ) . '</td></tr>';
		}

		echo '</tbody></table>';
		self::close_page();
	}

	/**
	 * Render explicit blocking rule management.
	 */
	public static function render_blocking(): void {
		self::require_capability( 'manage_uccm_settings' );
		$rules   = get_option( Resource_Rules::OPTION_NAME, array() );
		$encoded = wp_json_encode( is_array( $rules ) ? $rules : array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		self::open_page( __( 'Script Blocking', 'uk-cookie-consent-manager' ) );
		self::saved_notice();
		echo '<p>' . esc_html__( 'Declare only known optional resources. Invalid or incomplete JSON is rejected.', 'uk-cookie-consent-manager' ) . '</p>';
		self::form_open( 'uccm_save_blocking_rules', 'uccm_save_blocking' );
		echo '<textarea class="large-text code" rows="18" name="rules">' . esc_textarea( false === $encoded ? '{}' : $encoded ) . '</textarea>';
		submit_button( __( 'Save blocking rules', 'uk-cookie-consent-manager' ) );
		self::form_close();
		self::close_page();
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
		self::open_page( __( 'Cookie Inventory', 'uk-cookie-consent-manager' ) );
		self::saved_notice();

		if ( is_wp_error( $records ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $records->get_error_message() ) . '</p></div>';
			self::close_page();
			return;
		}

		self::render_inventory_filters( $filters );
		/* translators: %d: number of reviewed inventory items. */
		echo '<p><strong>' . esc_html( sprintf( _n( '%d reviewed item', '%d reviewed items', $records['total'], 'uk-cookie-consent-manager' ), $records['total'] ) ) . '</strong></p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Provider / domain', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Type', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Purpose', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Category', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Duration', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Review', 'uk-cookie-consent-manager' ) . '</th></tr></thead><tbody>';

		if ( array() === $records['items'] ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No inventory items match the current filters.', 'uk-cookie-consent-manager' ) . '</td></tr>';
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
		$settings       = Settings::current();
		$urls           = is_array( $settings['scan_urls'] ?? null ) ? implode( "\n", $settings['scan_urls'] ) : '';
		$excluded_paths = is_array( $settings['scan_excluded_paths'] ?? null ) ? implode( "\n", $settings['scan_excluded_paths'] ) : '';
		$page_limit     = (int) ( $settings['scan_page_limit'] ?? Scanner::MAX_TARGETS );
		$batch_size     = (int) ( $settings['scan_batch_size'] ?? Scanner::DEFAULT_BATCH_SIZE );
		$runs           = Scanner::recent_runs( 20 );
		$next           = wp_next_scheduled( Scanner::HOOK );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded filter and notice state.
		$scan_id = max( 0, (int) self::request_value( $_GET, 'scan_id' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bounded filter and notice state.
		$notice = self::request_value( $_GET, 'uccm_notice' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only value is escaped before display.
		$rejected_url = substr( sanitize_text_field( self::request_value( $_GET, 'uccm_rejected_url' ) ), 0, 200 );
		$findings     = Scan_Findings::records( $scan_id, 100 );
		$runner_run   = null;

		if ( is_array( $runs ) && 0 < $scan_id ) {
			foreach ( $runs as $candidate_run ) {
				if ( $scan_id === (int) $candidate_run['id'] ) {
					$runner_run = $candidate_run;
					break;
				}
			}
		}

		if ( is_array( $runner_run ) && 'completed' === (string) $runner_run['status'] ) {
			$runner_pages   = json_decode( (string) $runner_run['pages_visited'], true );
			$runner_pages   = is_array( $runner_pages ) ? $runner_pages : array();
			$runner_targets = array();

			foreach ( array_slice( $runner_pages, 0, Scanner::BROWSER_MAX_TARGETS ) as $runner_page ) {
				if ( is_array( $runner_page ) && 0 < (int) ( $runner_page['status'] ?? 0 ) && ! empty( $runner_page['url'] ) ) {
					$runner_targets[] = (string) $runner_page['url'];
				}
			}

			wp_enqueue_script( 'uccm-scan-runner', UCCM_PLUGIN_URL . 'assets/js/scan-runner.js', array(), UCCM_VERSION, true );
			wp_localize_script(
				'uccm-scan-runner',
				'UCCMScanRunner',
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'uccm_browser_scan' ),
					'runId'      => $scan_id,
					'targets'    => array_values( array_unique( $runner_targets ) ),
					'maxTargets' => Scanner::BROWSER_MAX_TARGETS,
				)
			);
		}

		self::open_page( __( 'Scans', 'uk-cookie-consent-manager' ) );

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Scan settings saved.', 'uk-cookie-consent-manager' ) . '</p></div>';
		} elseif ( 'scan-started' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The scan was queued. WordPress will process it in resumable background batches.', 'uk-cookie-consent-manager' ) . '</p></div>';
		} elseif ( 'scan-cancelled' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The scan was cancelled and its evidence was retained.', 'uk-cookie-consent-manager' ) . '</p></div>';
		} elseif ( 'scan-resumed' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The interrupted scan was requeued from its saved frontier.', 'uk-cookie-consent-manager' ) . '</p></div>';
		} elseif ( 'finding-reviewed' === $notice ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The scan finding review outcome was saved. The curated inventory was not changed.', 'uk-cookie-consent-manager' ) . '</p></div>';
		}

		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Scans are bounded observations of configured public pages and are never exhaustive. Authenticated, personalised and geographically varied journeys may differ.', 'uk-cookie-consent-manager' ) . '</p></div>';
		echo '<p><strong>' . esc_html__( 'Methods:', 'uk-cookie-consent-manager' ) . '</strong> ' . esc_html__( 'asynchronous same-origin crawling with Set-Cookie inspection, plus an administrator-run browser pass for cookies, local storage, scripts, iframes and pixels.', 'uk-cookie-consent-manager' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Next monthly run:', 'uk-cookie-consent-manager' ) . '</strong> ' . esc_html( false === $next ? __( 'Not scheduled', 'uk-cookie-consent-manager' ) : gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' ) . '</p>';

		self::form_open( 'uccm_save_scan_settings', 'uccm_save_scan_settings' );
		echo '<h2>' . esc_html__( 'Public scan URLs', 'uk-cookie-consent-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'The homepage is always scanned. Add up to 1,023 same-origin public URLs, one per line.', 'uk-cookie-consent-manager' ) . '</p>';

		if ( 'scan-url-error' === $notice ) {
			$message = '' === $rejected_url
				? __( 'The scan URLs were not saved. Enter only same-origin public URLs without credentials or fragments.', 'uk-cookie-consent-manager' )
				: sprintf(
					/* translators: %s: rejected scan URL. */
					__( 'The scan URL “%s” was not saved. Enter only same-origin public URLs without credentials or fragments.', 'uk-cookie-consent-manager' ),
					$rejected_url
				);
			echo '<div id="uccm-scan-url-error" class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
		}

		$textarea_attributes = 'scan-url-error' === $notice ? ' aria-invalid="true" aria-describedby="uccm-scan-url-error"' : '';
		echo '<textarea id="uccm-scan-urls" class="large-text code" rows="7" name="uccm[scan_urls]"' . $textarea_attributes . '>' . esc_textarea( $urls ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute fragment is a fixed allowlisted string.
		echo '<h3>' . esc_html__( 'Crawler limits', 'uk-cookie-consent-manager' ) . '</h3>';
		self::number_field( 'scan_page_limit', __( 'Maximum unique pages per scan', 'uk-cookie-consent-manager' ), $page_limit, 1, Scanner::MAX_TARGETS );
		self::number_field( 'scan_batch_size', __( 'Pages per background batch', 'uk-cookie-consent-manager' ), $batch_size, 1, 25 );
		echo '<p><label for="uccm-scan-excluded-paths"><strong>' . esc_html__( 'Excluded path patterns', 'uk-cookie-consent-manager' ) . '</strong></label><br>';
		echo '<textarea id="uccm-scan-excluded-paths" class="large-text code" rows="5" name="uccm[scan_excluded_paths]">' . esc_textarea( $excluded_paths ) . '</textarea><br>';
		echo '<span class="description">' . esc_html__( 'One path pattern per line. Use * as a wildcard. WordPress administration, login, REST and feed paths are always excluded.', 'uk-cookie-consent-manager' ) . '</span></p>';
		submit_button( __( 'Save scan settings', 'uk-cookie-consent-manager' ) );
		self::form_close();

		self::form_open( 'uccm_run_scan', 'uccm_run_scan' );
		echo '<p>' . esc_html__( 'Starting a scan returns immediately. WP-Cron processes a saved same-origin crawl frontier in bounded batches up to the configured limit; the page does not need to remain open.', 'uk-cookie-consent-manager' ) . '</p>';
		submit_button( __( 'Run scan now', 'uk-cookie-consent-manager' ), 'primary' );
		self::form_close();

		echo '<h2>' . esc_html__( 'Recent scan runs', 'uk-cookie-consent-manager' ) . '</h2>';

		if ( is_wp_error( $runs ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $runs->get_error_message() ) . '</p></div>';
		} elseif ( array() === $runs ) {
			echo '<p>' . esc_html__( 'No scan runs have been recorded yet.', 'uk-cookie-consent-manager' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Run', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Status', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Started (UTC)', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Crawl progress', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Browser', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Findings', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Warnings', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Action', 'uk-cookie-consent-manager' ) . '</th></tr></thead><tbody>';

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
				$browser_status   = (string) ( $coverage['browser_status'] ?? 'not-run' );
				echo '<tr><td><a href="' . esc_url( $run_url ) . '">' . esc_html( (string) $run['id'] ) . '</a></td><td>' . esc_html( (string) $run['status'] ) . '</td><td>' . esc_html( (string) $run['started_at'] ) . '</td>';
				/* translators: 1: visited pages, 2: discovered pages, 3: remaining pages. */
				echo '<td>' . esc_html( sprintf( __( '%1$d visited / %2$d discovered; %3$d remaining', 'uk-cookie-consent-manager' ), $visited_count, $discovered_count, $remaining_count ) ) . '</td>';
				echo '<td>' . esc_html( $browser_status ) . '</td><td>' . esc_html( (string) (int) ( $summary['findings'] ?? 0 ) ) . '</td><td>' . esc_html( (string) count( $warnings ) ) . '</td><td>';

				if ( in_array( (string) $run['status'], array( 'queued', 'running', 'failed' ), true ) ) {
					if ( 'failed' === (string) $run['status'] ) {
						self::form_open( 'uccm_resume_scan', 'uccm_resume_scan' );
						echo '<input type="hidden" name="scan_id" value="' . esc_attr( (string) $run['id'] ) . '">';
						submit_button( __( 'Resume', 'uk-cookie-consent-manager' ), 'secondary small', '', false );
						self::form_close();
					}

					self::form_open( 'uccm_cancel_scan', 'uccm_cancel_scan' );
					echo '<input type="hidden" name="scan_id" value="' . esc_attr( (string) $run['id'] ) . '">';
					submit_button( __( 'Cancel', 'uk-cookie-consent-manager' ), 'secondary small', '', false );
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
			echo '<h2>' . esc_html__( 'Browser observations for scan ', 'uk-cookie-consent-manager' ) . esc_html( (string) $scan_id ) . '</h2>';
			echo '<p>' . esc_html__( 'This administrator-run pass opens up to 100 successfully crawled same-origin pages in hidden frames and observes accessible cookie names, local-storage keys, scripts, iframes and pixels. It cannot read HttpOnly cookie values and may be limited by page framing policy.', 'uk-cookie-consent-manager' ) . '</p>';
			echo '<p><strong>' . esc_html__( 'Browser status:', 'uk-cookie-consent-manager' ) . '</strong> ' . esc_html( (string) ( $runner_coverage['browser_status'] ?? 'not-run' ) ) . '</p>';
			echo '<button type="button" class="button button-secondary" id="uccm-run-browser-observations">' . esc_html__( 'Run browser observations', 'uk-cookie-consent-manager' ) . '</button>';
			echo '<p id="uccm-browser-observation-status" aria-live="polite"></p>';
			echo '<div id="uccm-browser-observation-frames" hidden></div>';
		}

		echo '<h2>' . esc_html__( 'Scan findings requiring human review', 'uk-cookie-consent-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'Detections never publish, recategorise or otherwise change the curated inventory automatically.', 'uk-cookie-consent-manager' ) . '</p>';

		if ( 0 < $scan_id ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=uccm-scans' ) ) . '">' . esc_html__( 'Show findings from all scans', 'uk-cookie-consent-manager' ) . '</a></p>';
		}

		if ( is_wp_error( $findings ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $findings->get_error_message() ) . '</p></div>';
		} elseif ( array() === $findings ) {
			echo '<p>' . esc_html__( 'No scan findings match this view.', 'uk-cookie-consent-manager' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Finding', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Observation', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Material diff', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Status', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Review', 'uk-cookie-consent-manager' ) . '</th></tr></thead><tbody>';

			foreach ( $findings as $finding ) {
				$before = json_decode( (string) $finding['before_data'], true );
				$after  = json_decode( (string) $finding['after_data'], true );
				$before = is_array( $before ) ? $before : array();
				$after  = is_array( $after ) ? $after : array();
				echo '<tr><td>#' . esc_html( (string) $finding['id'] ) . '<br><small>' . esc_html( (string) $finding['finding_type'] ) . ' · ' . esc_html__( 'scan', 'uk-cookie-consent-manager' ) . ' ' . esc_html( (string) $finding['scan_run_id'] ) . '</small></td>';
				echo '<td><strong>' . esc_html( (string) $finding['storage_key'] ) . '</strong><br>' . esc_html( (string) $finding['domain'] ) . '</td>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes all values and returns only fixed markup.
				echo '<td>' . self::finding_diff_html( $before, $after ) . '</td>';
				echo '<td>' . esc_html( (string) $finding['status'] ) . '</td><td>';

				if ( 'pending' === $finding['status'] && current_user_can( 'manage_uccm_inventory' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability granted by UCCM.
					$outcomes = array(
						'reviewed' => __( 'Mark reviewed', 'uk-cookie-consent-manager' ),
						'ignored'  => __( 'Ignore', 'uk-cookie-consent-manager' ),
						'resolved' => __( 'Resolve', 'uk-cookie-consent-manager' ),
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
		self::open_page( __( 'Consent Records', 'uk-cookie-consent-manager' ) );

		if ( is_wp_error( $records ) || array() === $records ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No consent records are available to display.', 'uk-cookie-consent-manager' ) . '</p></div>';
			self::close_page();
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Receipt', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Time (UTC)', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Action', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Policy', 'uk-cookie-consent-manager' ) . '</th><th>' . esc_html__( 'Masked IP', 'uk-cookie-consent-manager' ) . '</th></tr></thead><tbody>';

		foreach ( $records as $record ) {
			echo '<tr><td><code>' . esc_html( (string) $record['receipt_id'] ) . '</code></td><td>' . esc_html( (string) $record['occurred_at'] ) . '</td><td>' . esc_html( (string) $record['action'] ) . '</td><td>' . esc_html( (string) $record['policy_version'] ) . '</td><td>' . esc_html( (string) $record['ip_masked'] ) . '</td></tr>';
		}

		echo '</tbody></table>';
		self::close_page();
	}

	/**
	 * Render privacy and retention settings.
	 */
	public static function render_privacy(): void {
		self::require_capability( 'manage_uccm_settings' );
		$settings = Settings::current();
		self::open_page( __( 'Privacy', 'uk-cookie-consent-manager' ) );
		self::saved_notice();
		self::form_open( 'uccm_save_settings', 'uccm_save_privacy' );
		echo '<input type="hidden" name="section" value="privacy">';
		self::number_field( 'retention_days', __( 'Consent retention (days)', 'uk-cookie-consent-manager' ), (int) $settings['retention_days'], 1, 3650 );
		self::checkbox_field( 'store_full_ip', __( 'Store encrypted complete IP addresses', 'uk-cookie-consent-manager' ), ! empty( $settings['store_full_ip'] ), __( 'This increases privacy risk and is not required for normal consent evidence.', 'uk-cookie-consent-manager' ) );
		self::checkbox_field( 'trust_proxy_headers', __( 'Trust forwarded IP headers', 'uk-cookie-consent-manager' ), ! empty( $settings['trust_proxy_headers'] ), __( 'Enable only when every trusted reverse proxy is listed below.', 'uk-cookie-consent-manager' ) );
		$proxies = is_array( $settings['trusted_proxy_ips'] ) ? implode( "\n", $settings['trusted_proxy_ips'] ) : '';
		echo '<p><label for="uccm-trusted-proxies"><strong>' . esc_html__( 'Trusted proxy IPs', 'uk-cookie-consent-manager' ) . '</strong></label><br><textarea id="uccm-trusted-proxies" class="large-text code" rows="5" name="uccm[trusted_proxy_ips]">' . esc_textarea( $proxies ) . '</textarea></p>';
		submit_button( __( 'Save privacy settings', 'uk-cookie-consent-manager' ) );
		self::form_close();
		self::close_page();
	}

	/**
	 * Render destructive-data lifecycle settings.
	 */
	public static function render_advanced(): void {
		self::require_capability( 'manage_uccm_settings' );
		$settings = Settings::current();
		self::open_page( __( 'Advanced', 'uk-cookie-consent-manager' ) );
		self::saved_notice();
		self::form_open( 'uccm_save_settings', 'uccm_save_advanced' );
		echo '<input type="hidden" name="section" value="advanced">';
		echo '<h2>' . esc_html__( 'Secure private-repository updates', 'uk-cookie-consent-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'Updates are offered only when an HTTPS manifest has a valid Ed25519 signature and the downloaded ZIP matches its SHA-256 checksum.', 'uk-cookie-consent-manager' ) . '</p>';
		echo '<p><label for="uccm-update-manifest"><strong>' . esc_html__( 'Manifest URL', 'uk-cookie-consent-manager' ) . '</strong></label><br><input id="uccm-update-manifest" class="large-text code" type="url" required name="uccm[update_manifest_url]" value="' . esc_attr( (string) $settings['update_manifest_url'] ) . '"></p>';
		echo '<p><label for="uccm-update-key"><strong>' . esc_html__( 'Ed25519 public key (base64)', 'uk-cookie-consent-manager' ) . '</strong></label><br><input id="uccm-update-key" class="large-text code" type="text" autocomplete="off" name="uccm[update_public_key]" value="' . esc_attr( (string) $settings['update_public_key'] ) . '"></p>';
		echo '<p><label for="uccm-update-credential"><strong>' . esc_html__( 'Site-specific download credential', 'uk-cookie-consent-manager' ) . '</strong></label><br><input id="uccm-update-credential" class="regular-text" type="password" autocomplete="new-password" name="uccm[update_credential]" value="" placeholder="' . esc_attr__( 'Leave blank to keep the stored credential', 'uk-cookie-consent-manager' ) . '"><br><small>' . esc_html( Secure_Updater::has_credential() ? __( 'An encrypted credential is configured.', 'uk-cookie-consent-manager' ) : __( 'No credential is configured.', 'uk-cookie-consent-manager' ) ) . '</small></p>';
		self::checkbox_field( 'clear_update_credential', __( 'Remove the stored update credential', 'uk-cookie-consent-manager' ), false, __( 'The credential is never displayed after saving.', 'uk-cookie-consent-manager' ) );
		self::checkbox_field( 'auto_update', __( 'Automatically install authenticated UCCM updates', 'uk-cookie-consent-manager' ), ! empty( $settings['auto_update'] ), __( 'Opt in only after testing your backup and recovery process.', 'uk-cookie-consent-manager' ) );
		echo '<hr>';
		self::checkbox_field( 'delete_data_on_uninstall', __( 'Delete all UCCM data when the plugin is uninstalled', 'uk-cookie-consent-manager' ), true === get_option( 'uccm_delete_data_on_uninstall', false ), __( 'Leave disabled to retain settings and evidence by default.', 'uk-cookie-consent-manager' ) );
		submit_button( __( 'Save advanced settings', 'uk-cookie-consent-manager' ) );
		self::form_close();
		self::close_page();
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
			'duration'   => __( 'Duration', 'uk-cookie-consent-manager' ),
			'domain'     => __( 'Domain', 'uk-cookie-consent-manager' ),
			'source_url' => __( 'Source URL', 'uk-cookie-consent-manager' ),
			'category'   => __( 'Category candidate', 'uk-cookie-consent-manager' ),
		);

		foreach ( Scan_Findings::material_fields() as $field ) {
			if ( ! array_key_exists( $field, $after ) ) {
				continue;
			}

			$old     = (string) ( $before[ $field ] ?? __( 'not recorded', 'uk-cookie-consent-manager' ) );
			$new     = (string) $after[ $field ];
			$parts[] = '<strong>' . esc_html( (string) ( $labels[ $field ] ?? $field ) ) . ':</strong> ' . esc_html( $old ) . ' &rarr; ' . esc_html( $new );
		}

		if ( array() === $parts ) {
			return esc_html__( 'New observation', 'uk-cookie-consent-manager' );
		}

		return implode( '<br>', $parts );
	}

	/**
	 * Render inventory filters and export control.
	 *
	 * @param array<string, string> $filters Current filters.
	 */
	private static function render_inventory_filters( array $filters ): void {
		echo '<form method="get"><input type="hidden" name="page" value="uccm-inventory"><label class="screen-reader-text" for="uccm-search">' . esc_html__( 'Search inventory', 'uk-cookie-consent-manager' ) . '</label><input id="uccm-search" type="search" name="s" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search inventory', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<select name="uccm_category"><option value="">' . esc_html__( 'All categories', 'uk-cookie-consent-manager' ) . '</option>';
		self::options( array( 'necessary', 'functional', 'analytics', 'marketing' ), $filters['category'] );
		echo '</select> <select name="uccm_status"><option value="">' . esc_html__( 'All statuses', 'uk-cookie-consent-manager' ) . '</option>';
		self::options( array( 'known', 'new', 'changed', 'ignored', 'resolved' ), $filters['status'] );
		echo '</select> ';
		submit_button( __( 'Filter', 'uk-cookie-consent-manager' ), 'secondary', '', false );
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
		echo '<p><a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export current filters as CSV', 'uk-cookie-consent-manager' ) . '</a></p>';
	}

	/**
	 * Render the create/update inventory form.
	 */
	private static function render_inventory_form(): void {
		echo '<hr><h2>' . esc_html__( 'Add or update an inventory item', 'uk-cookie-consent-manager' ) . '</h2>';
		self::form_open( 'uccm_save_inventory', 'uccm_save_inventory' );
		echo '<input type="number" min="0" name="uccm[id]" value="0" aria-label="' . esc_attr__( 'Existing database ID, or zero for a new item', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<input required maxlength="191" name="uccm[storage_key]" placeholder="' . esc_attr__( 'Cookie or storage key', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<input maxlength="191" name="uccm[provider]" placeholder="' . esc_attr__( 'Provider', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<input maxlength="191" name="uccm[domain]" placeholder="' . esc_attr__( 'Domain', 'uk-cookie-consent-manager' ) . '"><br><br>';
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
		echo '<textarea required class="large-text" rows="3" name="uccm[purpose]" placeholder="' . esc_attr__( 'Purpose', 'uk-cookie-consent-manager' ) . '"></textarea>';
		echo '<input class="regular-text" maxlength="100" name="uccm[duration]" placeholder="' . esc_attr__( 'Duration', 'uk-cookie-consent-manager' ) . '"> ';
		echo '<input class="regular-text" type="url" name="uccm[source_url]" placeholder="https://">';
		submit_button( __( 'Save inventory item', 'uk-cookie-consent-manager' ) );
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
				'prev_text' => __( 'Previous', 'uk-cookie-consent-manager' ),
				'next_text' => __( 'Next', 'uk-cookie-consent-manager' ),
			)
		);

		echo '<nav aria-label="' . esc_attr__( 'Inventory pages', 'uk-cookie-consent-manager' ) . '">' . wp_kses_post( $links ) . '</nav>';
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
	 * Enforce a dedicated capability before rendering or mutating.
	 *
	 * @param string $capability Required capability.
	 */
	private static function require_capability( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to access this Cookie Consent screen.', 'uk-cookie-consent-manager' ), '', array( 'response' => 403 ) );
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
		if ( 'saved' === self::request_value( $_GET, 'uccm_notice' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'uk-cookie-consent-manager' ) . '</p></div>';
		}
	}

	/**
	 * Render a number field.
	 *
	 * @param string $name  Field name.
	 * @param string $label Visible label.
	 * @param int    $value Current value.
	 * @param int    $min   Minimum value.
	 * @param int    $max   Maximum value.
	 */
	private static function number_field( string $name, string $label, int $value, int $min, int $max ): void {
		echo '<p><label><strong>' . esc_html( $label ) . '</strong><br><input type="number" name="uccm[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '"></label></p>';
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
	 * Render a checkbox with explanatory text.
	 *
	 * @param string $name        Field name.
	 * @param string $label       Visible label.
	 * @param bool   $checked     Whether the checkbox is selected.
	 * @param string $description Explanatory text.
	 */
	private static function checkbox_field( string $name, string $label, bool $checked, string $description ): void {
		echo '<p><label><input type="checkbox" name="uccm[' . esc_attr( $name ) . ']" value="1" ' . checked( $checked, true, false ) . '> <strong>' . esc_html( $label ) . '</strong></label><br><span class="description">' . esc_html( $description ) . '</span></p>';
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
