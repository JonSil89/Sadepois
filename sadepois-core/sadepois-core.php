<?php
/**
 * Plugin Name: Sadepois Core
 * Plugin URI: https://github.com/JonSil89/Sadepois
 * Description: Core plugin for Sadepois with NDA enforcement, audit logging, and partner isolation.
 * Version: 1.0.0
 * Author: JonSil89
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sadepois-core
 * Domain Path: /languages
 *
 * Sadepois Core is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Sadepois Core Plugin Class
 */
class SadePois_Core {

	/**
	 * Singleton instance.
	 *
	 * @var SadePois_Core|null
	 */
	private static $instance = null;

	/**
	 * Audit table name.
	 *
	 * @var string
	 */
	private $audit_table;

	/**
	 * Get singleton instance.
	 *
	 * @return SadePois_Core
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;

		$this->audit_table = $wpdb->prefix . 'sadepois_audit_log';

		$this->setup_hooks();
	}

	/**
	 * Setup WordPress hooks.
	 *
	 * @return void
	 */
	private function setup_hooks() {

		register_activation_hook( __FILE__, array( $this, 'activate' ) );

		// NDA gate.
		add_action( 'admin_init', array( $this, 'check_nda_acceptance' ) );

		// User profile fields.
		add_action( 'show_user_profile', array( $this, 'sp_user_profile_fields' ) );
		add_action( 'edit_user_profile', array( $this, 'sp_user_profile_fields' ) );

		add_action( 'personal_options_update', array( $this, 'sp_save_user_profile_fields' ) );
		add_action( 'edit_user_profile_update', array( $this, 'sp_save_user_profile_fields' ) );

		// User isolation.
		add_filter( 'pre_get_users', array( $this, 'sp_filter_users_list' ) );

		// Audit logging.
		add_action( 'wp_login', array( $this, 'audit_log_login' ), 10, 2 );

		// NDA admin notice.
		add_action( 'admin_notices', array( $this, 'nda_admin_notice' ) );
	}

	/**
	 * Plugin activation hook.
	 *
	 * @return void
	 */
	public function activate() {
		$this->create_tables();
	}

	/**
	 * Create required database tables.
	 *
	 * @return void
	 */
	private function create_tables() {

		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->audit_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			event_type VARCHAR(100) NOT NULL,
			event_data LONGTEXT NULL,
			ip_address VARCHAR(100) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY event_type (event_type)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Show partner field in user profile.
	 *
	 * @param WP_User $user User object.
	 * @return void
	 */
	public function sp_user_profile_fields( $user ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$partner_id = $this->sp_get_user_partner_id( $user->ID );

		wp_nonce_field( 'sp_save_partner_id', 'sp_partner_nonce' );
		?>

		<h2><?php esc_html_e( 'Partner Settings', 'sadepois-core' ); ?></h2>

		<table class="form-table">
			<tr>
				<th>
					<label for="sp_partner_id">
						<?php esc_html_e( 'Partner ID', 'sadepois-core' ); ?>
					</label>
				</th>

				<td>
					<input
						type="text"
						name="sp_partner_id"
						id="sp_partner_id"
						value="<?php echo esc_attr( $partner_id ); ?>"
						class="regular-text"
					/>

					<p class="description">
						<?php esc_html_e( 'Unique identifier for partner organization.', 'sadepois-core' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php
	}

	/**
	 * Save partner ID from user profile.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function sp_save_user_profile_fields( $user_id ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if (
			! isset( $_POST['sp_partner_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['sp_partner_nonce'] ) ),
				'sp_save_partner_id'
			)
		) {
			return;
		}

		if ( isset( $_POST['sp_partner_id'] ) ) {

			$partner_id = sanitize_text_field(
				wp_unslash( $_POST['sp_partner_id'] )
			);

			$this->sp_set_user_partner_id( $user_id, $partner_id );
		}
	}

	/**
	 * Filter users list by partner ID.
	 *
	 * @param WP_User_Query $query User query.
	 * @return void
	 */
	public function sp_filter_users_list( $query ) {

		if ( is_admin() && ! current_user_can( 'list_users' ) ) {
			return;
		}

		$current_user = wp_get_current_user();

		// Admins can see everything.
		if ( user_can( $current_user, 'manage_options' ) ) {
			return;
		}

		$current_partner = $this->sp_get_user_partner_id( $current_user->ID );

		// Secure fail-safe.
		if ( empty( $current_partner ) ) {

			$query->set( 'include', array( -1 ) );

			return;
		}

		$meta_query = array(
			array(
				'key'   => 'sp_partner_id',
				'value' => $current_partner,
			),
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Get user partner ID.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function sp_get_user_partner_id( $user_id ) {

		$partner_id = get_user_meta( $user_id, 'sp_partner_id', true );

		return is_string( $partner_id ) ? $partner_id : '';
	}

	/**
	 * Set user partner ID.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $partner_id Partner ID.
	 * @return bool
	 */
	public function sp_set_user_partner_id( $user_id, $partner_id ) {

		$partner_id = sanitize_text_field( $partner_id );

		$old_value = $this->sp_get_user_partner_id( $user_id );

		$updated = update_user_meta(
			$user_id,
			'sp_partner_id',
			$partner_id
		);

		if ( $updated ) {

			$this->write_audit_log(
				get_current_user_id(),
				'partner_id_changed',
				array(
					'target_user_id' => $user_id,
					'old_partner_id' => $old_value,
					'new_partner_id' => $partner_id,
				)
			);
		}

		return (bool) $updated;
	}

	/**
	 * Check if two users belong to same partner.
	 *
	 * @param int $user_id_1 User ID 1.
	 * @param int $user_id_2 User ID 2.
	 * @return bool
	 */
	public function sp_is_same_partner( $user_id_1, $user_id_2 ) {

		$partner_1 = $this->sp_get_user_partner_id( $user_id_1 );
		$partner_2 = $this->sp_get_user_partner_id( $user_id_2 );

		if ( empty( $partner_1 ) || empty( $partner_2 ) ) {
			return false;
		}

		return $partner_1 === $partner_2;
	}

	/**
	 * Audit login event.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user User object.
	 * @return void
	 */
	public function audit_log_login( $user_login, $user ) {

		$this->write_audit_log(
			$user->ID,
			'user_login',
			array(
				'username' => $user_login,
			)
		);
	}

	/**
	 * Write audit log entry.
	 *
	 * @param int    $user_id User ID.
	 * @param string $event_type Event type.
	 * @param array  $event_data Event payload.
	 * @return void
	 */
	private function write_audit_log(
		$user_id,
		$event_type,
		$event_data = array()
	) {

		global $wpdb;

		$ip_address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		$wpdb->insert(
			$this->audit_table,
			array(
				'user_id'    => absint( $user_id ),
				'event_type' => sanitize_text_field( $event_type ),
				'event_data' => wp_json_encode( $event_data ),
				'ip_address' => $ip_address,
				'created_at' => current_time( 'mysql' ),
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
	}

	/**
	 * NDA acceptance check.
	 *
	 * @return void
	 */
	public function check_nda_acceptance() {

		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		// Handle acceptance.
		if (
			isset( $_POST['sp_accept_nda'] ) &&
			isset( $_POST['sp_nda_nonce'] )
		) {

			$nonce = sanitize_text_field(
				wp_unslash( $_POST['sp_nda_nonce'] )
			);

			if ( wp_verify_nonce( $nonce, 'sp_accept_nda_action' ) ) {

				update_user_meta( $user_id, 'sp_nda_accepted', 1 );

				$this->write_audit_log(
					$user_id,
					'nda_accepted',
					array()
				);
			}
		}
	}

	/**
	 * Show NDA notice.
	 *
	 * @return void
	 */
	public function nda_admin_notice() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$user_id = get_current_user_id();

		$accepted = get_user_meta(
			$user_id,
			'sp_nda_accepted',
			true
		);

		if ( $accepted ) {
			return;
		}

		?>

		<div class="notice notice-warning">
			<p>
				<strong>
					<?php esc_html_e(
						'NDA / Confidentiality Acceptance Required',
						'sadepois-core'
					); ?>
				</strong>
			</p>

			<p>
				<?php esc_html_e(
					'You must accept confidentiality terms before accessing partner data.',
					'sadepois-core'
				); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'sp_accept_nda_action', 'sp_nda_nonce' ); ?>

				<p>
					<input
						type="submit"
						name="sp_accept_nda"
						class="button button-primary"
						value="<?php esc_attr_e( 'Accept NDA', 'sadepois-core' ); ?>"
					/>
				</p>
			</form>
		</div>

		<?php
	}
}

/**
 * Initialize plugin.
 */
SadePois_Core::get_instance();

/**
 * Global helper: Get partner ID.
 *
 * @param int $user_id User ID.
 * @return string
 */
function sp_get_user_partner_id( $user_id ) {

	return SadePois_Core::get_instance()
		->sp_get_user_partner_id( $user_id );
}

/**
 * Global helper: Check same partner.
 *
 * @param int $user_id_1 User ID 1.
 * @param int $user_id_2 User ID 2.
 * @return bool
 */
function sp_is_same_partner( $user_id_1, $user_id_2 ) {

	return SadePois_Core::get_instance()
		->sp_is_same_partner( $user_id_1, $user_id_2 );
}

/**
 * Global helper: Set partner ID.
 *
 * @param int    $user_id User ID.
 * @param string $partner_id Partner ID.
 * @return bool
 */
function sp_set_user_partner_id( $user_id, $partner_id ) {

	return SadePois_Core::get_instance()
		->sp_set_user_partner_id( $user_id, $partner_id );
}
