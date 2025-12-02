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
	 * @param array $assoc_args Associative arguments.
	 */
	public function lock( $args, $assoc_args ) {
		list( $user_identifier ) = $args;

		// Get user.
		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		// Lock the account.
		$security = new Account_Security_Handler( $user->ID );
		$security->lock_account( PHP_INT_MAX );

		// Destroy all active sessions.
		$this->destroy_all_sessions( $user->ID );

		// Log event.
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
	 * @param array $assoc_args Associative arguments.
	 */
	public function unlock( $args, $assoc_args ) {
		list( $user_identifier ) = $args;

		// Get user.
		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		// Unlock the account.
		$security = new Account_Security_Handler( $user->ID );
		$security->unlock_account();

		// Reset attempt counter.
		update_user_meta( $user->ID, META_CODE_ATTEMPTS, 0 );

		// Log event.
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
	public function lock_all( $args, $assoc_args ) {
		$exclude = isset( $assoc_args['exclude'] ) ? $assoc_args['exclude'] : null;

		// Get excluded user if specified.
		$excluded_user_id = null;
		if ( $exclude ) {
			$user = $this->get_user( $exclude );
			if ( is_wp_error( $user ) ) {
				\WP_CLI::error( sprintf( 'Excluded user not found: %s', $user->get_error_message() ) );
			}
			$excluded_user_id = $user->ID;
		}

		// Confirm action.
		if ( $excluded_user_id ) {
			\WP_CLI::confirm( sprintf( "Are you sure you want to lock ALL users except '%s'?", $user->user_login ), $assoc_args );
		} else {
			\WP_CLI::confirm( 'Are you sure you want to lock ALL users?', $assoc_args );
		}

		// Get all users.
		$user_query = new \WP_User_Query(
			array(
				'fields' => 'ID',
			)
		);
		$user_ids   = $user_query->get_results();

		$locked_count   = 0;
		$excluded_count = 0;

		foreach ( $user_ids as $user_id ) {
			// Skip excluded user.
			if ( $excluded_user_id && $user_id === $excluded_user_id ) {
				++$excluded_count;
				continue;
			}

			// Lock the account.
			$security = new Account_Security_Handler( $user_id );
			$security->lock_account( PHP_INT_MAX );

			// Destroy all active sessions.
			$this->destroy_all_sessions( $user_id );

			// Log event.
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
	public function unlock_all( $args, $assoc_args ) {
		// Get all locked users.
		$user_query = new \WP_User_Query(
			array(
				'fields'     => 'ID',
				'meta_query' => array(
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

		// Confirm action.
		\WP_CLI::confirm( sprintf( 'Are you sure you want to unlock %d users?', count( $user_ids ) ), $assoc_args );

		$unlocked_count = 0;

		foreach ( $user_ids as $user_id ) {
			// Unlock the account.
			$security = new Account_Security_Handler( $user_id );
			$security->unlock_account();

			// Reset attempt counter.
			update_user_meta( $user_id, META_CODE_ATTEMPTS, 0 );

			// Log event.
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
	public function status( $args, $assoc_args ) {
		list( $user_identifier ) = $args;

		// Get user.
		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		$security = new Account_Security_Handler( $user->ID );

		// Get lock status.
		$locked = $security->is_locked();
		if ( $locked ) {
			$locked_until = get_user_meta( $user->ID, META_LOCKED_UNTIL, true );
			if ( $locked_until > time() + ( 100 * YEAR_IN_SECONDS ) ) {
				$lock_status = 'Locked (permanent)';
			} else {
				$lock_status = sprintf( 'Locked until %s', wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $locked_until ) );
			}
		} else {
			$lock_status = 'Unlocked';
		}

		// Get last verified.
		$last_verified         = get_user_meta( $user->ID, META_LAST_VERIFIED, true );
		$last_verified_display = $last_verified ? human_time_diff( $last_verified ) . ' ago' : 'Never';

		// Get trusted devices count.
		$trusted_devices       = get_user_meta( $user->ID, META_TRUSTED_DEVICES, true );
		$trusted_devices_count = is_array( $trusted_devices ) ? count( $trusted_devices ) : 0;

		// Get failed attempts.
		$failed_attempts = get_user_meta( $user->ID, META_CODE_ATTEMPTS, true );
		$failed_attempts = $failed_attempts ? $failed_attempts : 0;

		// Build status array.
		$status = array(
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

		// Output.
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
	public function list_locked( $args, $assoc_args ) {
		// Get all locked users.
		$user_query = new \WP_User_Query(
			array(
				'fields'     => 'all',
				'meta_query' => array(
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

			if ( $locked_until > time() + ( 100 * YEAR_IN_SECONDS ) ) {
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

		// Output.
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
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear_devices( $args, $assoc_args ) {
		list( $user_identifier ) = $args;

		// Get user.
		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		// Get device count before clearing.
		$trusted_devices = get_user_meta( $user->ID, META_TRUSTED_DEVICES, true );
		$device_count    = is_array( $trusted_devices ) ? count( $trusted_devices ) : 0;

		// Clear devices.
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
	private function get_user( $user_identifier ) {
		// Try as ID first.
		if ( is_numeric( $user_identifier ) ) {
			$user = get_userdata( (int) $user_identifier );
			if ( $user ) {
				return $user;
			}
		}

		// Try as login.
		$user = get_user_by( 'login', $user_identifier );
		if ( $user ) {
			return $user;
		}

		// Try as email.
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
	private function destroy_all_sessions( $user_id ) {
		$sessions = \WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();
	}
}
