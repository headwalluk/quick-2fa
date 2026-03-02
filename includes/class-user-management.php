<?php
/**
 * User Management Handler
 *
 * Handles WordPress admin users table customization for lock-out management.
 *
 * @package Quick_2FA
 * @since   0.6.0
 */

namespace Quick_2FA;

// Block direct access.
defined( 'ABSPATH' ) || die();

/**
 * User Management Class
 *
 * Adds custom columns, filters, and row actions to the WordPress users table
 * for managing user lock-out status.
 *
 * @since 0.6.0
 */
class User_Management {

	/**
	 * Initialize and register hooks.
	 *
	 * @since 0.6.0
	 */
	public function run(): void {
		// Column display.
		add_filter( 'manage_users_columns', array( $this, 'add_lockout_column' ) );
		add_action( 'manage_users_custom_column', array( $this, 'render_lockout_column' ), 10, 3 );

		// Column sorting.
		add_filter( 'manage_users_sortable_columns', array( $this, 'make_column_sortable' ) );
		add_action( 'pre_get_users', array( $this, 'handle_column_sort' ) );

		// Filters.
		add_filter( 'views_users', array( $this, 'add_lockout_filters' ) );
		add_action( 'pre_get_users', array( $this, 'filter_by_lockout_status' ) );

		// Row actions.
		add_filter( 'user_row_actions', array( $this, 'add_lockout_actions' ), 10, 2 );

		// Action handlers.
		add_action( 'admin_action_quick2fa_lock', array( $this, 'handle_lock_user' ) );
		add_action( 'admin_action_quick2fa_unlock', array( $this, 'handle_unlock_user' ) );

		// Profile sections.
		add_action( 'show_user_profile', array( $this, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_section' ) );

		// Profile action handlers.
		add_action( 'admin_action_quick2fa_revoke_device', array( $this, 'handle_revoke_device' ) );
		add_action( 'admin_action_quick2fa_revoke_all_devices', array( $this, 'handle_revoke_all_devices' ) );
	}

	/**
	 * Add lock-out status column to users table.
	 *
	 * @since 0.6.0
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_lockout_column( array $columns ): array {
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'email' === $key ) {
				$new_columns['quick2fa_status'] = __( 'Lock Status', 'quick-2fa' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render lock-out status column content.
	 *
	 * @since 0.6.0
	 * @param string $output      Custom column output (empty by default).
	 * @param string $column_name Column name.
	 * @param int    $user_id     User ID.
	 * @return string Column content.
	 */
	public function render_lockout_column( string $output, string $column_name, int $user_id ): string {
		if ( 'quick2fa_status' !== $column_name ) {
			return $output;
		}

		$status = $this->get_user_lockout_status( $user_id );

		if ( 'locked' === $status ) {
			$locked_until_raw = get_user_meta( $user_id, META_LOCKED_UNTIL, true );
			$locked_until     = is_numeric( $locked_until_raw ) ? (int) min( (float) $locked_until_raw, PHP_INT_MAX ) : 0;
			$tooltip          = $this->format_lockout_expiry( $locked_until );

			return sprintf( '<span class="dashicons dashicons-lock" style="color: #d63638;" title="%s"></span>', esc_attr( $tooltip ) );
		}

		if ( 'unlocked' === $status ) {
			return '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" title="' . esc_attr__( 'Not locked out', 'quick-2fa' ) . '"></span>';
		}

		return '<span style="color: #dcdcde;">—</span>';
	}

	/**
	 * Make lock-out column sortable.
	 *
	 * @since 0.6.0
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns.
	 */
	public function make_column_sortable( array $columns ): array {
		$columns['quick2fa_status'] = 'quick2fa_locked';
		return $columns;
	}

	/**
	 * Handle column sorting in users query.
	 *
	 * @since 0.6.0
	 * @param \WP_User_Query $query User query object.
	 */
	public function handle_column_sort( \WP_User_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		if ( 'quick2fa_locked' === $orderby ) {
			$query->set( 'meta_key', META_LOCKED_UNTIL );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	/**
	 * Add lock-out filter links to users table.
	 *
	 * @since 0.6.0
	 * @param array $views Existing view links.
	 * @return array Modified view links.
	 */
	public function add_lockout_filters( array $views ): array {
		$locked_count = $this->count_locked_users();
		$total_count  = count_users();
		$total_users  = isset( $total_count['total_users'] ) && is_numeric( $total_count['total_users'] ) ? (int) $total_count['total_users'] : 0;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking filter state.
		$current_filter = isset( $_GET['quick2fa_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['quick2fa_filter'] ) ) : '';

		// Locked users filter.
		$locked_class             = 'locked' === $current_filter ? ' class="current"' : '';
		$locked_url               = add_query_arg( 'quick2fa_filter', 'locked', admin_url( 'users.php' ) );
		$views['quick2fa_locked'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $locked_url ),
			$locked_class,
			esc_html__( 'Locked Out', 'quick-2fa' ),
			$locked_count
		);

		// Not locked users filter.
		$unlocked_count             = $total_users - $locked_count;
		$unlocked_class             = 'unlocked' === $current_filter ? ' class="current"' : '';
		$unlocked_url               = add_query_arg( 'quick2fa_filter', 'unlocked', admin_url( 'users.php' ) );
		$views['quick2fa_unlocked'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $unlocked_url ),
			$unlocked_class,
			esc_html__( 'Not Locked Out', 'quick-2fa' ),
			$unlocked_count
		);

		return $views;
	}

	/**
	 * Filter users by lock-out status.
	 *
	 * @since 0.6.0
	 * @param \WP_User_Query $query User query object.
	 */
	public function filter_by_lockout_status( \WP_User_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking filter state.
		$filter = isset( $_GET['quick2fa_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['quick2fa_filter'] ) ) : '';

		if ( 'locked' === $filter ) {
			// Show only locked users.
			$query->set(
				'meta_query',
				array(
					'relation' => 'AND',
					array(
						'key'     => META_LOCKED_UNTIL,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => META_LOCKED_UNTIL,
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				)
			);
		} elseif ( 'unlocked' === $filter ) {
			// Show only non-locked users.
			$query->set(
				'meta_query',
				array(
					'relation' => 'OR',
					array(
						'key'     => META_LOCKED_UNTIL,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => META_LOCKED_UNTIL,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				)
			);
		}
	}

	/**
	 * Add lock/unlock row actions to users table.
	 *
	 * @since 0.6.0
	 * @param array    $actions Existing row actions.
	 * @param \WP_User $user    User object.
	 * @return array Modified row actions.
	 */
	public function add_lockout_actions( array $actions, \WP_User $user ): array {
		if ( ! current_user_can( 'edit_users' ) ) {
			return $actions;
		}

		if ( get_current_user_id() === $user->ID ) {
			return $actions;
		}

		$status = $this->get_user_lockout_status( $user->ID );

		if ( 'locked' === $status ) {
			$url                        = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'quick2fa_unlock',
						'user'   => $user->ID,
					),
					admin_url( 'users.php' )
				),
				'quick2fa_unlock_' . $user->ID
			);
			$actions['quick2fa_unlock'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Unlock', 'quick-2fa' ) );
		} else {
			$url                      = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'quick2fa_lock',
						'user'   => $user->ID,
					),
					admin_url( 'users.php' )
				),
				'quick2fa_lock_' . $user->ID
			);
			$actions['quick2fa_lock'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Lock Out', 'quick-2fa' ) );
		}

		return $actions;
	}

	/**
	 * Handle lock user action.
	 *
	 * @since 0.6.0
	 */
	public function handle_lock_user(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'quick2fa_lock_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quick-2fa' ) );
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'quick-2fa' ) );
		}

		if ( get_current_user_id() === $user_id ) {
			wp_die( esc_html__( 'You cannot lock out your own account.', 'quick-2fa' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'Invalid user.', 'quick-2fa' ) );
		}

		$security = new Account_Security_Handler( $user_id );
		$security->lock_account( PHP_INT_MAX );

		$sessions = \WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();

		$security->log_event(
			LOG_ACCOUNT_LOCKED,
			array(
				'admin_id' => get_current_user_id(),
				'reason'   => 'manual_lock',
			)
		);

		add_settings_error(
			'quick2fa_user_management',
			'user_locked',
			sprintf(
				/* translators: %s: Username */
				__( 'User %s has been locked out.', 'quick-2fa' ),
				$user->user_login
			),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( $this->get_redirect_url() );
		exit();
	}

	/**
	 * Handle unlock user action.
	 *
	 * @since 0.6.0
	 */
	public function handle_unlock_user(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'quick2fa_unlock_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quick-2fa' ) );
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'quick-2fa' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'Invalid user.', 'quick-2fa' ) );
		}

		$security = new Account_Security_Handler( $user_id );
		$security->unlock_account();
		$security->log_event(
			LOG_ACCOUNT_UNLOCKED,
			array(
				'admin_id' => get_current_user_id(),
				'reason'   => 'manual_unlock',
			)
		);

		update_user_meta( $user_id, META_CODE_ATTEMPTS, 0 );

		add_settings_error(
			'quick2fa_user_management',
			'user_unlocked',
			sprintf(
				/* translators: %s: Username */
				__( 'User %s has been unlocked.', 'quick-2fa' ),
				$user->user_login
			),
			'success'
		);
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( $this->get_redirect_url() );
		exit();
	}

	/**
	 * Get user lock-out status.
	 *
	 * @since 0.6.0
	 * @param int $user_id User ID.
	 * @return string Status: 'locked', 'unlocked', or 'never_verified'.
	 */
	private function get_user_lockout_status( int $user_id ): string {
		$locked_until = get_user_meta( $user_id, META_LOCKED_UNTIL, true );

		if ( empty( $locked_until ) || ! is_numeric( $locked_until ) ) {
			return 'unlocked';
		}

		// Handle PHP_INT_MAX stored as scientific notation string.
		$locked_until_int = (int) min( (float) $locked_until, PHP_INT_MAX );

		if ( $locked_until_int > time() ) {
			return 'locked';
		}

		return 'unlocked';
	}

	/**
	 * Format lock-out expiry timestamp for display.
	 *
	 * @since 0.6.0
	 * @param int $timestamp Lock expiry timestamp.
	 * @return string Formatted expiry message.
	 */
	private function format_lockout_expiry( int $timestamp ): string {
		// Check for permanent lock (PHP_INT_MAX or very large timestamp).
		if ( $timestamp > time() + 100 * YEAR_IN_SECONDS ) {
			return __( 'Locked out (manual)', 'quick-2fa' );
		}

		// Safely get date/time formats with fallbacks.
		$date_format = get_option( 'date_format' );
		$time_format = get_option( 'time_format' );
		$date_format = ! empty( $date_format ) ? $date_format : 'Y-m-d';
		$time_format = ! empty( $time_format ) ? $time_format : 'H:i:s';

		return sprintf(
			/* translators: %s: Formatted date and time */
			__( 'Locked out until %s', 'quick-2fa' ),
			wp_date( $date_format . ' ' . $time_format, $timestamp )
		);
	}

	/**
	 * Count locked users (with caching).
	 *
	 * @since 0.6.0
	 * @return int Number of locked users.
	 */
	private function count_locked_users(): int {
		$cache_key = 'quick2fa_locked_user_count';
		$count     = get_transient( $cache_key );

		if ( false !== $count ) {
			return (int) $count;
		}

		// Query locked users.
		$query = new \WP_User_Query(
			array(
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only cached query for locked user count badge.
					'relation' => 'AND',
					array(
						'key'     => META_LOCKED_UNTIL,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => META_LOCKED_UNTIL,
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
				'count_total' => true,
				'fields'      => 'ID',
			)
		);

		$count = $query->get_total();

		// Cache for 5 minutes.
		set_transient( $cache_key, $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Get redirect URL after action (preserves filters).
	 *
	 * @since 0.6.0
	 * @return string Redirect URL.
	 */
	private function get_redirect_url(): string {
		$redirect_url = admin_url( 'users.php' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading filter/pagination state for redirect, not processing actions.

		// Preserve current filter.
		if ( isset( $_GET['quick2fa_filter'] ) ) {
			$redirect_url = add_query_arg( 'quick2fa_filter', sanitize_text_field( wp_unslash( $_GET['quick2fa_filter'] ) ), $redirect_url );
		}

		// Preserve pagination.
		if ( isset( $_GET['paged'] ) ) {
			$redirect_url = add_query_arg( 'paged', absint( $_GET['paged'] ), $redirect_url );
		}

		// phpcs:enable

		return $redirect_url;
	}

	/**
	 * Render 2FA profile section on user edit pages.
	 *
	 * @since 0.6.1
	 * @param \WP_User $user User object.
	 */
	public function render_profile_section( \WP_User $user ): void {
		if ( get_option( OPTION_DISABLE_TRUSTED_DEVICES, DEFAULT_DISABLE_TRUSTED_DEVICES ) ) {
			return;
		}

		$security_handler = new Account_Security_Handler( $user->ID );
		$trusted_devices  = get_user_meta( $user->ID, META_TRUSTED_DEVICES, true );

		if ( ! is_array( $trusted_devices ) ) {
			$trusted_devices = array();
		}

		$security_handler->cleanup_expired_devices();

		$trusted_devices = get_user_meta( $user->ID, META_TRUSTED_DEVICES, true );

		if ( ! is_array( $trusted_devices ) ) {
			$trusted_devices = array();
		}

		$current_fingerprint = $security_handler->get_device_fingerprint();

		require QUICK_2FA_PATH . 'views/profile-trusted-devices.php';
	}

	/**
	 * Handle revoke single device action.
	 *
	 * @since 0.6.1
	 */
	public function handle_revoke_device(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$fingerprint = isset( $_GET['fingerprint'] ) ? sanitize_text_field( wp_unslash( $_GET['fingerprint'] ) ) : '';

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'quick2fa_revoke_device_' . $user_id . '_' . $fingerprint ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quick-2fa' ) );
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'quick-2fa' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'Invalid user.', 'quick-2fa' ) );
		}

		$trusted_devices = get_user_meta( $user_id, META_TRUSTED_DEVICES, true );
		if ( ! is_array( $trusted_devices ) ) {
			$trusted_devices = array();
		}

		if ( isset( $trusted_devices[ $fingerprint ] ) ) {
			unset( $trusted_devices[ $fingerprint ] );
			update_user_meta( $user_id, META_TRUSTED_DEVICES, $trusted_devices );

			$security = new Account_Security_Handler( $user_id );
			$security->log_event(
				'device_revoked',
				array(
					'admin_id'    => get_current_user_id(),
					'fingerprint' => $fingerprint,
				)
			);
		}

		$redirect_url = add_query_arg( 'updated', 'true', get_edit_user_link( $user_id ) );
		wp_safe_redirect( $redirect_url );
		exit();
	}

	/**
	 * Handle revoke all devices action.
	 *
	 * @since 0.6.1
	 */
	public function handle_revoke_all_devices(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'quick2fa_revoke_all_devices_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quick-2fa' ) );
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'quick-2fa' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'Invalid user.', 'quick-2fa' ) );
		}

		$trusted_devices = get_user_meta( $user_id, META_TRUSTED_DEVICES, true );
		$device_count    = is_array( $trusted_devices ) ? count( $trusted_devices ) : 0;

		$security = new Account_Security_Handler( $user_id );
		$security->clear_trusted_devices();

		$security->log_event(
			'all_devices_revoked',
			array(
				'admin_id'     => get_current_user_id(),
				'device_count' => $device_count,
			)
		);

		$redirect_url = add_query_arg( 'updated', 'true', get_edit_user_link( $user_id ) );
		wp_safe_redirect( $redirect_url );
		exit();
	}
}
