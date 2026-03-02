<?php
/**
 * WP-CLI Commands
 *
 * Provides command-line interface for Quick 2FA management.
 *
 * @package Quick_2FA
 * @since   0.6.0
 */

namespace Quick_2FA;

// Block direct access.
defined( 'ABSPATH' ) || die();

/**
 * Manage Quick 2FA user lock-out and security settings.
 *
 * @since 0.6.0
 */
class CLI_Commands {

	/**
	 * Lock a user account and terminate all active sessions.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email address.
	 *
	 * ## EXAMPLES
	 *
	 *     # Lock user by ID
	 *     $ wp quick-2fa lock 123
	 *     Success: User 'john_doe' has been locked out.
	 *
	 *     # Lock user by login
	 *     $ wp quick-2fa lock john_doe
	 *     Success: User 'john_doe' has been locked out.
	 *
	 * @since 0.6.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (unused, required by WP-CLI).
	 */
	public function lock( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WP-CLI signature.
		[$user_identifier] = $args;

		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		$security = new Account_Security_Handler( $user->ID );
		$security->lock_account( PHP_INT_MAX );
		$this->destroy_all_sessions( $user->ID );

		$security->log_event(
			LOG_ACCOUNT_LOCKED,
			array(
				'source' => 'wp_cli',
				'reason' => 'manual_lock',
			)
		);

		\WP_CLI::success( sprintf( "User '%s' has been locked out and all sessions terminated.", $user->user_login ) );
	}

	/**
	 * Unlock a user account.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email address.
	 *
	 * ## EXAMPLES
	 *
	 *     # Unlock user
	 *     $ wp quick-2fa unlock 123
	 *     Success: User 'john_doe' has been unlocked.
	 *
	 * @since 0.6.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (unused, required by WP-CLI).
	 */
	public function unlock( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WP-CLI signature.
		[$user_identifier] = $args;

		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		$security = new Account_Security_Handler( $user->ID );
		$security->unlock_account();
		update_user_meta( $user->ID, META_CODE_ATTEMPTS, 0 );

		$security->log_event(
			LOG_ACCOUNT_UNLOCKED,
			array(
				'source' => 'wp_cli',
				'reason' => 'manual_unlock',
			)
		);

		\WP_CLI::success( sprintf( "User '%s' has been unlocked.", $user->user_login ) );
	}

	/**
	 * Lock all user accounts (emergency lockdown).
	 *
	 * ## OPTIONS
	 *
	 * [--exclude=<user>]
	 * : User ID, login, or email to exclude from lockdown (e.g., your admin account).
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Lock all users except admin
	 *     $ wp quick-2fa lock-all --exclude=admin
	 *     Success: Locked 142 users. Excluded 1 user.
	 *
	 *     # Lock all users (with confirmation)
	 *     $ wp quick-2fa lock-all
	 *     Are you sure you want to lock ALL users? [y/n] y
	 *     Success: Locked 143 users.
	 *
	 * @since 0.6.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function lock_all( array $args, array $assoc_args ): void {
		$exclude          = isset( $assoc_args['exclude'] ) ? $assoc_args['exclude'] : null;
		$excluded_user_id = null;
		if ( $exclude ) {
			$user = $this->get_user( $exclude );
			if ( is_wp_error( $user ) ) {
				\WP_CLI::error( sprintf( 'Excluded user not found: %s', $user->get_error_message() ) );
			}
			$excluded_user_id = $user->ID;
		}

		if ( $excluded_user_id ) {
			\WP_CLI::confirm( sprintf( "Are you sure you want to lock ALL users except '%s'?", $user->user_login ), $assoc_args );
		} else {
			\WP_CLI::confirm( 'Are you sure you want to lock ALL users?', $assoc_args );
		}

		$user_query = new \WP_User_Query(
			array(
				'fields' => 'ID',
			)
		);
		$user_ids   = $user_query->get_results();

		$locked_count   = 0;
		$excluded_count = 0;

		foreach ( $user_ids as $user_id ) {
			if ( $excluded_user_id && $user_id === $excluded_user_id ) {
				++$excluded_count;
				continue;
			}

			$security = new Account_Security_Handler( $user_id );
			$security->lock_account( PHP_INT_MAX );
			$this->destroy_all_sessions( $user_id );

			$security->log_event(
				LOG_ACCOUNT_LOCKED,
				array(
					'source' => 'wp_cli',
					'reason' => 'emergency_lockdown',
				)
			);

			++$locked_count;
		}

		if ( $excluded_count > 0 ) {
			\WP_CLI::success( sprintf( 'Locked %d users. Excluded %d user.', $locked_count, $excluded_count ) );
		} else {
			\WP_CLI::success( sprintf( 'Locked %d users.', $locked_count ) );
		}
	}

	/**
	 * Unlock all locked user accounts.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp quick-2fa unlock-all
	 *     Success: Unlocked 23 users.
	 *
	 * @since 0.6.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function unlock_all( array $args, array $assoc_args ): void {
		$user_query = new \WP_User_Query(
			array(
				'fields'     => 'ID',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- CLI command, not a frontend query.
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
			)
		);
		$user_ids   = $user_query->get_results();

		if ( empty( $user_ids ) ) {
			\WP_CLI::warning( 'No locked users found.' );
			return;
		}

		\WP_CLI::confirm( sprintf( 'Are you sure you want to unlock %d users?', count( $user_ids ) ), $assoc_args );

		$unlocked_count = 0;

		foreach ( $user_ids as $user_id ) {
			$security = new Account_Security_Handler( $user_id );
			$security->unlock_account();
			update_user_meta( $user_id, META_CODE_ATTEMPTS, 0 );

			$security->log_event(
				LOG_ACCOUNT_UNLOCKED,
				array(
					'source' => 'wp_cli',
					'reason' => 'bulk_unlock',
				)
			);

			++$unlocked_count;
		}

		\WP_CLI::success( sprintf( 'Unlocked %d users.', $unlocked_count ) );
	}

	/**
	 * Show lock-out status for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email address.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp quick-2fa status admin
	 *     +-----------------+-------------+
	 *     | Field           | Value       |
	 *     +-----------------+-------------+
	 *     | User            | admin       |
	 *     | Lock Status     | Unlocked    |
	 *     | Last Verified   | 2 hours ago |
	 *     | Trusted Devices | 1           |
	 *     +-----------------+-------------+
	 *
	 * @since 0.6.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		[$user_identifier] = $args;

		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		$security = new Account_Security_Handler( $user->ID );
		$locked   = $security->is_locked();
		if ( $locked ) {
			$locked_until = get_user_meta( $user->ID, META_LOCKED_UNTIL, true );
			if ( $locked_until > time() + 100 * YEAR_IN_SECONDS ) {
				$lock_status = 'Locked (permanent)';
			} else {
				$lock_status = sprintf( 'Locked until %s', wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $locked_until ) );
			}
		} else {
			$lock_status = 'Unlocked';
		}

		$last_verified         = get_user_meta( $user->ID, META_LAST_VERIFIED, true );
		$last_verified_display = $last_verified ? human_time_diff( $last_verified ) . ' ago' : 'Never';

		$trusted_devices       = get_user_meta( $user->ID, META_TRUSTED_DEVICES, true );
		$trusted_devices_count = is_array( $trusted_devices ) ? count( $trusted_devices ) : 0;

		$failed_attempts = get_user_meta( $user->ID, META_CODE_ATTEMPTS, true );
		$failed_attempts = $failed_attempts ? $failed_attempts : 0;
		$status          = array(
			array(
				'Field' => 'User',
				'Value' => $user->user_login,
			),
			array(
				'Field' => 'Email',
				'Value' => $user->user_email,
			),
			array(
				'Field' => 'Lock Status',
				'Value' => $lock_status,
			),
			array(
				'Field' => 'Last Verified',
				'Value' => $last_verified_display,
			),
			array(
				'Field' => 'Failed Attempts',
				'Value' => $failed_attempts,
			),
			array(
				'Field' => 'Trusted Devices',
				'Value' => $trusted_devices_count,
			),
		);

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $status, array( 'Field', 'Value' ) );
	}

	/**
	 * List all locked users.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp quick-2fa list-locked
	 *     +---------+------------+----------------------+---------------------+
	 *     | User ID | Login      | Email                | Locked Until        |
	 *     +---------+------------+----------------------+---------------------+
	 *     | 42      | john_doe   | john@example.com     | 2025-12-02 15:30:00 |
	 *     | 73      | jane_smith | jane@example.com     | Permanent           |
	 *     +---------+------------+----------------------+---------------------+
	 *
	 * @since 0.6.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_locked( array $args, array $assoc_args ): void {
		$user_query = new \WP_User_Query(
			array(
				'fields'     => 'all',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- CLI command, not a frontend query.
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
			)
		);
		$users      = $user_query->get_results();

		if ( empty( $users ) ) {
			\WP_CLI::warning( 'No locked users found.' );
			return;
		}

		$locked_users = array();
		foreach ( $users as $user ) {
			$locked_until = get_user_meta( $user->ID, META_LOCKED_UNTIL, true );

			if ( $locked_until > time() + 100 * YEAR_IN_SECONDS ) {
				$locked_until_display = 'Permanent';
			} else {
				$locked_until_display = wp_date( 'Y-m-d H:i:s', $locked_until );
			}

			$locked_users[] = array(
				'User ID'      => $user->ID,
				'Login'        => $user->user_login,
				'Email'        => $user->user_email,
				'Locked Until' => $locked_until_display,
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		\WP_CLI\Utils\format_items( $format, $locked_users, array( 'User ID', 'Login', 'Email', 'Locked Until' ) );
	}

	/**
	 * Clear all trusted devices for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email address.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp quick-2fa clear-devices admin
	 *     Success: Cleared 3 trusted devices for user 'admin'.
	 *
	 * @since 0.6.0
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (unused, required by WP-CLI).
	 */
	public function clear_devices( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WP-CLI signature.
		[$user_identifier] = $args;

		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		$trusted_devices = get_user_meta( $user->ID, META_TRUSTED_DEVICES, true );
		$device_count    = is_array( $trusted_devices ) ? count( $trusted_devices ) : 0;

		$security = new Account_Security_Handler( $user->ID );
		$security->clear_trusted_devices();

		\WP_CLI::success( sprintf( "Cleared %d trusted devices for user '%s'.", $device_count, $user->user_login ) );
	}

	/**
	 * Get user by ID, login, or email.
	 *
	 * @since 0.6.0
	 * @param string $user_identifier User ID, login, or email.
	 * @return \WP_User|\WP_Error User object on success, WP_Error on failure.
	 */
	private function get_user( string $user_identifier ): \WP_User|\WP_Error {
		if ( is_numeric( $user_identifier ) ) {
			$user = get_userdata( (int) $user_identifier );
			if ( $user ) {
				return $user;
			}
		}

		$user = get_user_by( 'login', $user_identifier );
		if ( $user ) {
			return $user;
		}

		$user = get_user_by( 'email', $user_identifier );
		if ( $user ) {
			return $user;
		}

		return new \WP_Error( 'user_not_found', sprintf( 'User not found: %s', $user_identifier ) );
	}

	/**
	 * Destroy all sessions for a user.
	 *
	 * @since 0.6.0
	 * @param int $user_id User ID.
	 */
	private function destroy_all_sessions( int $user_id ): void {
		$sessions = \WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();
	}

	/**
	 * Emergency disable Quick 2FA across all users.
	 *
	 * This command sets Quick 2FA to disabled mode, bypassing all 2FA checks.
	 * Use this only in emergency situations where administrators are locked out.
	 * Requires --yes flag to confirm the action.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Emergency disable with confirmation
	 *     wp quick-2fa emergency_disable
	 *
	 *     # Emergency disable without confirmation
	 *     wp quick-2fa emergency_disable --yes
	 *
	 * @since 0.8.0
	 * @param array<string,mixed> $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 */
	public function emergency_disable( array $args, array $assoc_args ): void {
		$current_mode = get_option( OPTION_MODE, DEFAULT_MODE );
		if ( MODE_DISABLED === $current_mode ) {
			\WP_CLI::warning( 'Quick 2FA is already in disabled mode.' );
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( 'This will disable Quick 2FA for all users. Continue?', $assoc_args );
		}

		update_option( OPTION_MODE, MODE_DISABLED );

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional: emergency action must be logged to server error log.
			sprintf(
				'[Quick 2FA] Emergency disable activated via WP-CLI at %s',
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		\WP_CLI::success( 'Quick 2FA has been emergency disabled.' );
		\WP_CLI::line( '' );
		\WP_CLI::line( 'IMPORTANT: Quick 2FA is now bypassed for all users.' );
		\WP_CLI::line( 'To re-enable, visit Settings > Quick 2FA in WordPress admin.' );
	}
}
