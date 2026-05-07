<?php
/**
 * Plugin Name: Sadepois Core
 * Plugin URI: https://github.com/JonSil89/Sadepois
 * Description: Core plugin for Sadepois with NDA enforcement, audit logging, and partner isolation
 * Version: 1.0.0
 * Author: JonSil89
 * License: GPL-2.0+
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

    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->setup_hooks();
        $this->create_tables();
    }

    /**
     * Setup all hooks
     */
    private function setup_hooks() {
        // Plugin activation
        register_activation_hook( __FILE__, array( $this, 'activate' ) );

        // Admin init
        add_action( 'admin_init', array( $this, 'check_nda_acceptance' ) );

        // User profile fields
        add_action( 'show_user_profile', array( $this, 'sp_user_profile_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'sp_user_profile_fields' ) );
        add_action( 'personal_options_update', array( $this, 'sp_save_user_profile_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'sp_save_user_profile_fields' ) );

        // Users list filtering
        add_filter( 'pre_get_users', array( $this, 'sp_filter_users_list' ) );

        // Login audit logging
        add_action( 'wp_login', array( $this, 'audit_log_login' ), 10, 2 );
    }

    /**
     * Plugin activation hook
     */
    public function activate() {
        $this->create_tables();
    }

    /**
     * Create audit log table if not exists
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'sadepois_audit_log';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) != $table_name ) {
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                user_id bigint(20) NOT NULL,
                event_type varchar(50) NOT NULL,
                meta longtext DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY event_type (event_type)
            ) $charset_collate;";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );
        }
    }

    /**
     * Check NDA acceptance on admin access
     */
    public function check_nda_acceptance() {
        if ( ! is_admin() || wp_doing_ajax() ) {
            return;
        }

        $user = wp_get_current_user();
        if ( ! $user->ID ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nda_accepted = get_user_meta( $user->ID, 'sp_nda_accepted', true );

        if ( ! $nda_accepted ) {
            wp_safe_remote_post( admin_url( 'admin.php?page=sp-nda' ) );
        }
    }

    /**
     * Audit log user login
     */
    public function audit_log_login( $user_login, $user ) {
        $this->sp_audit_log( $user->ID, 'login', array( 'username' => $user_login ) );
    }

    /**
     * Generic audit log function
     * 
     * @param int $user_id
     * @param string $event_type
     * @param array $meta
     */
    private function sp_audit_log( $user_id, $event_type, $meta = array() ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'sadepois_audit_log';
        
        $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'event_type' => $event_type,
                'meta' => wp_json_encode( $meta ),
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s' )
        );
    }

    // ===== RBAC LITE: Partner Isolation =====

    /**
     * Get partner ID for a user
     * 
     * @param int $user_id
     * @return string|null
     */
    public function sp_get_user_partner_id( $user_id ) {
        $partner_id = get_user_meta( $user_id, 'sp_partner_id', true );
        return ! empty( $partner_id ) ? sanitize_text_field( $partner_id ) : null;
    }

    /**
     * Check if two users belong to same partner
     * 
     * @param int $user_id_1
     * @param int $user_id_2
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
     * Set partner ID for a user (with audit logging)
     * 
     * @param int $user_id
     * @param string $partner_id
     * @return bool
     */
    public function sp_set_user_partner_id( $user_id, $partner_id ) {
        $old_partner_id = $this->sp_get_user_partner_id( $user_id );
        $partner_id = sanitize_text_field( $partner_id );
        
        $updated = update_user_meta( $user_id, 'sp_partner_id', $partner_id );
        
        if ( $updated ) {
            $this->sp_audit_log_partner_update( $user_id, $old_partner_id, $partner_id );
        }
        
        return $updated;
    }

    /**
     * Audit log partner ID update
     * 
     * @param int $user_id
     * @param string|null $old_value
     * @param string $new_value
     */
    private function sp_audit_log_partner_update( $user_id, $old_value, $new_value ) {
        $this->sp_audit_log( $user_id, 'partner_update', array(
            'old_partner_id' => $old_value,
            'new_partner_id' => $new_value,
        ) );
    }

    /**
     * Add partner_id field to user profile
     * 
     * @param WP_User $user
     */
    public function sp_user_profile_fields( $user ) {
        // Only show to admins
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        $partner_id = $this->sp_get_user_partner_id( $user->ID );
        ?>
        <h3><?php esc_html_e( 'Partner Settings', 'sadepois-core' ); ?></h3>
        <table class="form-table">
            <tr>
                <th>
                    <label for="sp_partner_id"><?php esc_html_e( 'Partner ID', 'sadepois-core' ); ?></label>
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
                        <?php esc_html_e( 'Unique identifier for partner organization', 'sadepois-core' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save partner_id from user profile
     * 
     * @param int $user_id
     */
    public function sp_save_user_profile_fields( $user_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        if ( isset( $_POST['sp_partner_id'] ) ) {
            $this->sp_set_user_partner_id( $user_id, $_POST['sp_partner_id'] );
        }
    }

    /**
     * Filter users list to show only same-partner users (non-admin)
     * 
     * @param array $args
     * @return array
     */
    public function sp_filter_users_list( $args ) {
        $current_user = wp_get_current_user();
        
        // Admins see everything
        if ( $current_user->has_cap( 'manage_options' ) ) {
            return $args;
        }
        
        $current_partner = $this->sp_get_user_partner_id( $current_user->ID );
        
        // If user has no partner_id, don't filter (fail-safe)
        if ( empty( $current_partner ) ) {
            return $args;
        }
        
        // Get users with same partner_id
        $same_partner_users = get_users( array(
            'meta_key' => 'sp_partner_id',
            'meta_value' => $current_partner,
            'fields' => 'ID',
        ) );
        
        $args['include'] = ! empty( $same_partner_users ) ? $same_partner_users : array( -1 );
        
        return $args;
    }
}

// Initialize plugin
SadePois_Core::get_instance();

// ===== Global Helper Functions =====

/**
 * Global helper: Get user partner ID
 * 
 * @param int $user_id
 * @return string|null
 */
function sp_get_user_partner_id( $user_id ) {
    $sadepois = SadePois_Core::get_instance();
    return $sadepois->sp_get_user_partner_id( $user_id );
}

/**
 * Global helper: Check same partner
 * 
 * @param int $user_id_1
 * @param int $user_id_2
 * @return bool
 */
function sp_is_same_partner( $user_id_1, $user_id_2 ) {
    $sadepois = SadePois_Core::get_instance();
    return $sadepois->sp_is_same_partner( $user_id_1, $user_id_2 );
}

/**
 * Global helper: Set user partner ID
 * 
 * @param int $user_id
 * @param string $partner_id
 * @return bool
 */
function sp_set_user_partner_id( $user_id, $partner_id ) {
    $sadepois = SadePois_Core::get_instance();
    return $sadepois->sp_set_user_partner_id( $user_id, $partner_id );
}
