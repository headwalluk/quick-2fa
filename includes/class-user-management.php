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
	 * Add lock-out status column to users table.
	 *
	 * @since 0.6.0
	 * @param mixed $columns Existing columns.
	 * @return mixed Columns with ours added, or $columns unchanged if it is not an array.
	 */
	public function add_lockout_column( mixed $columns ): mixed {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$new_columns = array();

		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;

			if ( 'email' === $key ) {
				$new_columns[ COLUMN_LOCK_STATUS ] = __( 'Lock Status', 'quick-2fa' );
			}
		}

		// Preferred position is after the email column, but that column is not
		// ours to rely on — another plugin may have removed it. Append rather
		// than let our column disappear without trace.
		if ( ! isset( $new_columns[ COLUMN_LOCK_STATUS ] ) ) {
			$new_columns[ COLUMN_LOCK_STATUS ] = __( 'Lock Status', 'quick-2fa' );
		}

		return $new_columns;
	}

	/**
	 * Render lock-out status column content.
	 *
	 * @since 0.6.0
	 * @param mixed $output      Custom column output (empty by default).
	 * @param mixed $column_name Column name.
	 * @param mixed $user_id     User ID.
	 * @return mixed Our column's markup, or $output unchanged for any other column.
	 */
	public function render_lockout_column( mixed $output, mixed $column_name, mixed $user_id ): mixed {
		if ( COLUMN_LOCK_STATUS !== $column_name || ! is_numeric( $user_id ) ) {
			return $output;
		}

		$user_id = (int) $user_id;
		$status  = $this->get_user_lockout_status( $user_id );

		if ( 'locked' === $status ) {
			$locked_until_raw = get_user_meta( $user_id, META_LOCKED_UNTIL, true );
			$locked_until     = is_numeric( $locked_until_raw ) ? (int) min( (float) $locked_until_raw, PHP_INT_MAX ) : 0;
			$tooltip          = $this->format_lockout_expiry( $locked_until );
			$markup           = sprintf( '<span class="dashicons dashicons-lock" style="color: #d63638;" title="%s"></span>', esc_attr( $tooltip ) );
		} elseif ( 'unlocked' === $status ) {
			$markup = '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" title="' . esc_attr__( 'Not locked out', 'quick-2fa' ) . '"></span>';
		} else {
			// Unrecognised status.
			$markup = '<span style="color: #dcdcde;">—</span>';
		}

		return $markup;
	}

	/**
	 * Make lock-out column sortable.
	 *
	 * @since 0.6.0
	 * @param mixed $columns Sortable columns.
	 * @return mixed Sortable columns with ours added, or $columns unchanged if it is not an array.
	 */
	public function make_column_sortable( mixed $columns ): mixed {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$columns[ COLUMN_LOCK_STATUS ] = SORT_KEY_LOCKED;
		return $columns;
	}

	/**
	 * Add lock-out filter links to users table.
	 *
	 * @since 0.6.0
	 * @param mixed $views Existing view links.
	 * @return mixed View links with ours added, or $views unchanged if it is not an array.
	 */
	public function add_lockout_filters( mixed $views ): mixed {
		if ( ! is_array( $views ) ) {
			return $views;
		}

		$locked_count = $this->count_locked_users();
		$total_count  = count_users();
		$total_users  = isset( $total_count['total_users'] ) && is_numeric( $total_count['total_users'] ) ? (int) $total_count['total_users'] : 0;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking filter state.
		$current_filter = isset( $_GET[ QUERY_ARG_FILTER ] ) ? sanitize_text_field( wp_unslash( $_GET[ QUERY_ARG_FILTER ] ) ) : '';

		// Locked users filter.
		$locked_class             = 'locked' === $current_filter ? ' class="current"' : '';
		$locked_url               = add_query_arg( QUERY_ARG_FILTER, 'locked', admin_url( 'users.php' ) );
		$views[ SORT_KEY_LOCKED ] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $locked_url ),
			$locked_class,
			esc_html__( 'Locked Out', 'quick-2fa' ),
			$locked_count
		);

		// Not locked users filter.
		$unlocked_count             = $total_users - $locked_count;
		$unlocked_class             = 'unlocked' === $current_filter ? ' class="current"' : '';
		$unlocked_url               = add_query_arg( QUERY_ARG_FILTER, 'unlocked', admin_url( 'users.php' ) );
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
	 * Apply the lock-status filter and sort to the Users list table's query.
	 *
	 * @since 1.8.1
	 * @param mixed $args WP_User_Query arguments for the list table.
	 * @return mixed Arguments with the filter and sort applied, or $args unchanged if it is not an array.
	 */
	public function apply_lock_status_query( mixed $args ): mixed {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking filter state.
		$filter     = isset( $_GET[ QUERY_ARG_FILTER ] ) && is_string( $_GET[ QUERY_ARG_FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ QUERY_ARG_FILTER ] ) ) : '';
		$is_sorted  = isset( $args['orderby'] ) && SORT_KEY_LOCKED === $args['orderby'];
		$now        = time();
		$lock_query = array();

		// The sort orders by the named clause. Under OR, only a NOT EXISTS clause's join is
		// restricted to the lock key, so its column holds the lock time, or NULL when unset.
		if ( 'locked' === $filter ) {
			$lock_query = array(
				META_CLAUSE_LOCKED_UNTIL => array(
					'key'     => META_LOCKED_UNTIL,
					'value'   => $now,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			);
		} elseif ( 'unlocked' === $filter ) {
			$lock_query = array(
				'relation'               => 'OR',
				META_CLAUSE_LOCKED_UNTIL => array(
					'key'     => META_LOCKED_UNTIL,
					'compare' => 'NOT EXISTS',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => META_LOCKED_UNTIL,
					'value'   => $now,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			);
		} elseif ( $is_sorted ) {
			// Sort only: keep users who have never been locked.
			$lock_query = array(
				'relation'               => 'OR',
				META_CLAUSE_LOCKED_UNTIL => array(
					'key'     => META_LOCKED_UNTIL,
					'compare' => 'NOT EXISTS',
					'type'    => 'NUMERIC',
				),
				array(
					'key'     => META_LOCKED_UNTIL,
					'compare' => 'EXISTS',
				),
			);
		} else {
			// No filter and no lock-status sort; the query is left unchanged.
		}

		if ( ! empty( $lock_query ) ) {
			$existing_meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

			if ( ! empty( $existing_meta_query ) ) {
				$lock_query = array(
					'relation' => 'AND',
					$existing_meta_query,
					$lock_query,
				);
			} else {
				// No conditions from elsewhere to keep.
			}

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The Users screen filters on lock meta by design.
			$args['meta_query'] = $lock_query;
		}

		if ( $is_sorted ) {
			$args['orderby'] = META_CLAUSE_LOCKED_UNTIL;
		}

		return $args;
	}

	/**
	 * Add lock/unlock row actions to users table.
	 *
	 * @since 0.6.0
	 * @param mixed $actions Existing row actions.
	 * @param mixed $user    User object.
	 * @return mixed Row actions with ours added, or $actions unchanged.
	 */
	public function add_lockout_actions( mixed $actions, mixed $user ): mixed {
		if ( ! is_array( $actions ) || ! $user instanceof \WP_User || ! current_user_can( 'edit_users' ) ) {
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
			$actions['quick2fa_unlock'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html_x( 'Unlock', 'verb; user row action', 'quick-2fa' ) );
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
			$actions['quick2fa_lock'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html_x( 'Lock Out', 'verb; user row action', 'quick-2fa' ) );
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

		if ( ! verify_admin_action_nonce( 'quick2fa_lock_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quick-2fa' ) );
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'quick-2fa' ) );
		}

		if ( get_current_user_id() === $user_id ) {
			wp_die( esc_html__( 'You cannot lock out your own account.', 'quick-2fa' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			wp_die( esc_html__( 'Invalid user.', 'quick-2fa' ) );
		}

		$security  = new Account_Security_Handler( $user_id );
		$is_locked = $security->lock_account(
			PERMANENT_LOCK_DURATION,
			array(
				'source'   => 'admin_ui',
				'reason'   => 'manual_lock',
				'admin_id' => get_current_user_id(),
			)
		);

		$sessions = \WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();

		if ( $is_locked ) {
			$this->queue_notice(
				'success',
				sprintf(
					/* translators: %s: Username */
					__( 'User %s has been locked out.', 'quick-2fa' ),
					$user->user_login
				)
			);
		} else {
			$this->queue_notice(
				'error',
				sprintf(
					/* translators: %s: Username */
					__( 'User %s could not be locked out, because the lock could not be saved. Their sessions have been ended. Please try again.', 'quick-2fa' ),
					$user->user_login
				)
			);
		}

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

		if ( ! verify_admin_action_nonce( 'quick2fa_unlock_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quick-2fa' ) );
		}

		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'quick-2fa' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			wp_die( esc_html__( 'Invalid user.', 'quick-2fa' ) );
		}

		$security    = new Account_Security_Handler( $user_id );
		$is_unlocked = $security->unlock_account(
			array(
				'source'   => 'admin_ui',
				'reason'   => 'manual_unlock',
				'admin_id' => get_current_user_id(),
			)
		);

		if ( $is_unlocked ) {
			$this->queue_notice(
				'success',
				sprintf(
					/* translators: %s: Username */
					__( 'User %s has been unlocked.', 'quick-2fa' ),
					$user->user_login
				)
			);
		} else {
			$this->queue_notice(
				'error',
				sprintf(
					/* translators: %s: Username */
					__( 'User %s could not be unlocked, because the lock could not be removed. Please try again.', 'quick-2fa' ),
					$user->user_login
				)
			);
		}

		wp_safe_redirect( $this->get_redirect_url() );
		exit();
	}

	/**
	 * Keep a notice for the current administrator, shown once on their next Users or profile screen.
	 *
	 * @since 1.9.0
	 * @param string $type    'success' or 'error'.
	 * @param string $message Notice text.
	 */
	private function queue_notice( string $type, string $message ): void {
		set_transient(
			TRANSIENT_ADMIN_NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			ADMIN_NOTICE_TTL
		);
	}

	/**
	 * Show and discard the current administrator's queued notice, on the Users and profile screens.
	 *
	 * @since 1.9.0
	 */
	public function render_queued_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen instanceof \WP_Screen || ! in_array( $screen->id, array( 'users', 'profile', 'user-edit' ), true ) ) {
			return;
		}

		$transient_name = TRANSIENT_ADMIN_NOTICE . get_current_user_id();
		$notice         = get_transient( $transient_name );

		if ( false !== $notice ) {
			delete_transient( $transient_name );
		}

		$is_valid = is_array( $notice )
			&& isset( $notice['type'], $notice['message'] )
			&& in_array( $notice['type'], array( 'success', 'error' ), true )
			&& is_string( $notice['message'] );

		if ( $is_valid ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
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
		$status       = 'unlocked';

		if ( ! empty( $locked_until ) && is_numeric( $locked_until ) ) {
			// Handle PHP_INT_MAX stored as scientific notation string.
			$locked_until_int = (int) min( (float) $locked_until, PHP_INT_MAX );

			if ( $locked_until_int > time() ) {
				$status = 'locked';
			}
		}

		return $status;
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
		if ( $timestamp > time() + PERMANENT_LOCK_THRESHOLD ) {
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
		$count = get_transient( TRANSIENT_LOCKED_COUNT );

		if ( false === $count ) {
			$query = new \WP_User_Query(
				array(
					'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Admin-only cached query for the locked-user badge.
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

			set_transient( TRANSIENT_LOCKED_COUNT, $count, LOCKED_COUNT_CACHE_TTL );
		}

		return (int) $count;
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
		if ( isset( $_GET[ QUERY_ARG_FILTER ] ) ) {
			$redirect_url = add_query_arg( QUERY_ARG_FILTER, sanitize_text_field( wp_unslash( $_GET[ QUERY_ARG_FILTER ] ) ), $redirect_url );
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
	 * @param mixed $user User object.
	 */
	public function render_profile_section( mixed $user ): void {
		if ( ! $user instanceof \WP_User || ! are_trusted_devices_enabled() ) {
			return;
		}

		$security_handler = new Account_Security_Handler( $user->ID );
		$security_handler->cleanup_expired_devices();

		$trusted_devices = get_user_meta( $user->ID, META_TRUSTED_DEVICES, true );

		if ( ! is_array( $trusted_devices ) ) {
			$trusted_devices = array();
		}

		// Key identifying the browser viewing this page, so the list can flag
		// the current device. Empty when this browser holds no trust cookie.
		$current_device_key = $security_handler->get_current_device_key();

		require QUICK_2FA_PATH . 'views/profile-trusted-devices.php';
	}

	/**
	 * Enqueue the trusted-devices table styles on the profile and user-edit screens.
	 *
	 * @since 1.7.0
	 * @param mixed $hook Current admin page hook.
	 */
	public function enqueue_profile_styles( mixed $hook ): void {
		if ( ! in_array( $hook, array( 'profile.php', 'user-edit.php' ), true ) || ! are_trusted_devices_enabled() ) {
			return;
		}

		wp_enqueue_style(
			'quick-2fa-profile',
			QUICK_2FA_URL . 'assets/css/admin-profile.css',
			array(),
			QUICK_2FA_VERSION
		);
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
		$device_key = isset( $_GET['device_key'] ) ? sanitize_text_field( wp_unslash( $_GET['device_key'] ) ) : '';

		if ( ! verify_admin_action_nonce( 'quick2fa_revoke_device_' . $user_id . '_' . $device_key ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quick-2fa' ) );
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'quick-2fa' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			wp_die( esc_html__( 'Invalid user.', 'quick-2fa' ) );
		}

		$security = new Account_Security_Handler( $user_id );

		if ( $security->revoke_device( $device_key ) ) {
			$security->log_event(
				LOG_DEVICE_REVOKED,
				array(
					'admin_id'   => get_current_user_id(),
					'device_key' => $device_key,
				)
			);
			$this->queue_notice( 'success', __( 'The trusted device has been revoked.', 'quick-2fa' ) );
		} else {
			$this->queue_notice( 'error', __( 'The trusted device could not be revoked, or had already been removed. Check the list and try again.', 'quick-2fa' ) );
		}

		wp_safe_redirect( get_edit_user_link( $user_id ) );
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

		if ( ! verify_admin_action_nonce( 'quick2fa_revoke_all_devices_' . $user_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'quick-2fa' ) );
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'quick-2fa' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			wp_die( esc_html__( 'Invalid user.', 'quick-2fa' ) );
		}

		$security     = new Account_Security_Handler( $user_id );
		$device_count = $security->clear_trusted_devices(
			array(
				'source'   => 'admin_ui',
				'reason'   => 'revoke_all',
				'admin_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $device_count ) ) {
			$this->queue_notice( 'error', $device_count->get_error_message() );
		} else {
			$this->queue_notice(
				'success',
				sprintf(
					/* translators: %d: number of trusted devices revoked */
					_n( '%d trusted device has been revoked.', '%d trusted devices have been revoked.', $device_count, 'quick-2fa' ),
					$device_count
				)
			);
		}

		wp_safe_redirect( get_edit_user_link( $user_id ) );
		exit();
	}
}
