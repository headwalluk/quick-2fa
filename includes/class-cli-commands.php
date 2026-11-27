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
	 * [--dry-run]
	 * : Report what would be locked, without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     # Lock user by ID
	 *     $ wp quick-2fa lock 123
	 *     Success: User 'john_doe' has been locked out and all sessions terminated.
	 *
	 *     # Lock user by login
	 *     $ wp quick-2fa lock john_doe
	 *     Success: User 'john_doe' has been locked out and all sessions terminated.
	 *
	 *     # Check first
	 *     $ wp quick-2fa lock john_doe --dry-run
	 *     Success: Dry run: would lock user 'john_doe' permanently and end 2 active sessions. Lock status now: Unlocked.
	 *
	 * @since 0.6.0
	 * @since 1.9.0 Added --dry-run.
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function lock( array $args, array $assoc_args ): void {
		[$user_identifier] = $args;

		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		if ( $this->is_dry_run( $assoc_args ) ) {
			\WP_CLI::success(
				sprintf(
					"Dry run: would lock user '%s' permanently and end %d active sessions. Lock status now: %s.",
					$user->user_login,
					count( \WP_Session_Tokens::get_instance( $user->ID )->get_all() ),
					$this->describe_lock_status( $user->ID )
				)
			);
		} else {
			$result = $this->lock_user( $user->ID, 'manual_lock' );

			if ( $result['is_locked'] && $result['is_signed_out'] ) {
				\WP_CLI::success( sprintf( "User '%s' has been locked out and all sessions terminated.", $user->user_login ) );
			} elseif ( $result['is_locked'] ) {
				\WP_CLI::error( sprintf( "User '%s' has been locked out, but their sessions could not all be ended.", $user->user_login ) );
			} elseif ( $result['is_signed_out'] ) {
				\WP_CLI::error( sprintf( "User '%s' could not be locked: the lock wasn't saved. Their sessions were ended.", $user->user_login ) );
			} else {
				\WP_CLI::error( sprintf( "User '%s' could not be locked: the lock wasn't saved, and their sessions could not all be ended.", $user->user_login ) );
			}
		}
	}

	/**
	 * Unlock a user account.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email address.
	 *
	 * [--dry-run]
	 * : Report what would be unlocked, without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     # Unlock user
	 *     $ wp quick-2fa unlock 123
	 *     Success: User 'john_doe' has been unlocked.
	 *
	 * @since 0.6.0
	 * @since 1.9.0 Added --dry-run.
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function unlock( array $args, array $assoc_args ): void {
		[$user_identifier] = $args;

		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		if ( $this->is_dry_run( $assoc_args ) ) {
			\WP_CLI::success(
				sprintf(
					"Dry run: would unlock user '%s' and reset its failed-attempt count. Lock status now: %s.",
					$user->user_login,
					$this->describe_lock_status( $user->ID )
				)
			);
		} elseif ( $this->unlock_user( $user->ID, 'manual_unlock' ) ) {
			\WP_CLI::success( sprintf( "User '%s' has been unlocked.", $user->user_login ) );
		} else {
			\WP_CLI::error( sprintf( "User '%s' could not be unlocked: the lock couldn't be removed.", $user->user_login ) );
		}
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
	 * [--dry-run]
	 * : Report how many users would be locked, without asking or changing anything.
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
	 *     # Check the exclusion before locking anyone
	 *     $ wp quick-2fa lock-all --exclude=admin --dry-run
	 *     Success: Dry run: would lock 142 users and end their sessions. Would exclude user 1 ('admin').
	 *
	 * @subcommand lock-all
	 *
	 * @since 0.6.0
	 * @since 1.9.0 Added --dry-run.
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function lock_all( array $args, array $assoc_args ): void {
		$is_dry_run         = $this->is_dry_run( $assoc_args );
		$exclude            = $assoc_args['exclude'] ?? null;
		$excluded_user_id   = 0;
		$excluded_user_name = '';

		if ( $exclude ) {
			$excluded_user = $this->get_user( $exclude );

			if ( is_wp_error( $excluded_user ) ) {
				\WP_CLI::error( sprintf( 'Excluded user not found: %s', $excluded_user->get_error_message() ) );
			}

			$excluded_user_id   = $excluded_user->ID;
			$excluded_user_name = $excluded_user->user_login;
		}

		if ( $is_dry_run ) {
			// No prompt: a dry run changes nothing.
		} elseif ( $excluded_user_id > 0 ) {
			\WP_CLI::confirm( sprintf( "Are you sure you want to lock ALL users except '%s'?", $excluded_user_name ), $assoc_args );
		} else {
			\WP_CLI::confirm( 'Are you sure you want to lock ALL users?', $assoc_args );
		}

		$user_ids = $this->query_user_ids( array() );

		$locked_count            = 0;
		$excluded_count          = 0;
		$lock_failed_user_ids    = array();
		$session_failed_user_ids = array();

		foreach ( $user_ids as $user_id ) {
			if ( $excluded_user_id > 0 && (int) $user_id === $excluded_user_id ) {
				++$excluded_count;
				continue;
			}

			if ( $is_dry_run ) {
				++$locked_count;
				continue;
			}

			$result = $this->lock_user( (int) $user_id, 'emergency_lockdown' );

			if ( $result['is_locked'] ) {
				++$locked_count;
			} else {
				$lock_failed_user_ids[] = (int) $user_id;
			}

			if ( ! $result['is_signed_out'] ) {
				$session_failed_user_ids[] = (int) $user_id;
			}
		}

		$failures = array();
		if ( ! empty( $lock_failed_user_ids ) ) {
			$failures[] = sprintf( 'could not save the lock for %d (IDs %s)', count( $lock_failed_user_ids ), implode( ', ', $lock_failed_user_ids ) );
		}
		if ( ! empty( $session_failed_user_ids ) ) {
			$failures[] = sprintf( 'could not end the sessions of %d (IDs %s)', count( $session_failed_user_ids ), implode( ', ', $session_failed_user_ids ) );
		}

		if ( $is_dry_run && $excluded_count > 0 ) {
			\WP_CLI::success( sprintf( "Dry run: would lock %d users and end their sessions. Would exclude user %d ('%s').", $locked_count, $excluded_user_id, $excluded_user_name ) );
		} elseif ( $is_dry_run ) {
			\WP_CLI::success( sprintf( 'Dry run: would lock %d users and end their sessions.', $locked_count ) );
		} elseif ( ! empty( $failures ) ) {
			\WP_CLI::error( sprintf( 'Locked %d users, but %s.', $locked_count, implode( ', and ', $failures ) ) );
		} elseif ( $excluded_count > 0 ) {
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
	 * [--dry-run]
	 * : List the users who would be unlocked, without asking or changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp quick-2fa unlock-all
	 *     Are you sure you want to unlock 23 users? [y/n] y
	 *     Success: Unlocked 23 users.
	 *
	 *     $ wp quick-2fa unlock-all --dry-run
	 *     Success: Dry run: would unlock 2 users (IDs 42, 73).
	 *
	 * @subcommand unlock-all
	 *
	 * @since 0.6.0
	 * @since 1.9.0 Added --dry-run.
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function unlock_all( array $args, array $assoc_args ): void {
		$user_ids = $this->query_user_ids(
			array(
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

		if ( empty( $user_ids ) ) {
			\WP_CLI::warning( 'No locked users found.' );
		} elseif ( $this->is_dry_run( $assoc_args ) ) {
			\WP_CLI::success( sprintf( 'Dry run: would unlock %d users (IDs %s).', count( $user_ids ), implode( ', ', array_map( 'intval', $user_ids ) ) ) );
		} else {
			\WP_CLI::confirm( sprintf( 'Are you sure you want to unlock %d users?', count( $user_ids ) ), $assoc_args );

			$unlocked_count  = 0;
			$failed_user_ids = array();

			foreach ( $user_ids as $user_id ) {
				if ( $this->unlock_user( (int) $user_id, 'bulk_unlock' ) ) {
					++$unlocked_count;
				} else {
					$failed_user_ids[] = (int) $user_id;
				}
			}

			if ( ! empty( $failed_user_ids ) ) {
				\WP_CLI::error( sprintf( 'Unlocked %d users, but could not remove the lock for %d (IDs %s).', $unlocked_count, count( $failed_user_ids ), implode( ', ', $failed_user_ids ) ) );
			}

			\WP_CLI::success( sprintf( 'Unlocked %d users.', $unlocked_count ) );
		}
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
	 *     +-----------------+-------------------+
	 *     | Field           | Value             |
	 *     +-----------------+-------------------+
	 *     | User            | admin             |
	 *     | Email           | admin@example.com |
	 *     | Lock Status     | Unlocked          |
	 *     | Last Verified   | 2 hours ago       |
	 *     | Failed Attempts | 0                 |
	 *     | Trusted Devices | 1                 |
	 *     +-----------------+-------------------+
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

		$lock_status = $this->describe_lock_status( $user->ID );

		$last_verified         = (int) get_user_meta( $user->ID, META_LAST_VERIFIED, true );
		$last_verified_display = $last_verified > 0 ? human_time_diff( $last_verified ) . ' ago' : 'Never';

		$trusted_devices_count = $this->count_trusted_devices( $user->ID );

		$failed_attempts = (int) get_user_meta( $user->ID, META_CODE_ATTEMPTS, true );
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
	 * @subcommand list-locked
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
		} else {
			$locked_users = array();
			foreach ( $users as $user ) {
				$locked_until = (int) get_user_meta( $user->ID, META_LOCKED_UNTIL, true );

				if ( $locked_until > time() + PERMANENT_LOCK_THRESHOLD ) {
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
	}

	/**
	 * Clear all trusted devices for a user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : User ID, login, or email address.
	 *
	 * [--dry-run]
	 * : Report how many devices would be cleared, without changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp quick-2fa clear-devices admin
	 *     Success: Cleared 3 trusted devices for user 'admin'.
	 *
	 *     $ wp quick-2fa clear-devices admin --dry-run
	 *     Success: Dry run: would clear 3 trusted devices for user 'admin'.
	 *
	 * @subcommand clear-devices
	 *
	 * @since 0.6.0
	 * @since 1.9.0 Added --dry-run.
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear_devices( array $args, array $assoc_args ): void {
		[$user_identifier] = $args;

		$user = $this->get_user( $user_identifier );
		if ( is_wp_error( $user ) ) {
			\WP_CLI::error( $user->get_error_message() );
		}

		if ( $this->is_dry_run( $assoc_args ) ) {
			\WP_CLI::success( sprintf( "Dry run: would clear %d trusted devices for user '%s'.", $this->count_trusted_devices( $user->ID ), $user->user_login ) );
		} else {
			$security     = new Account_Security_Handler( $user->ID );
			$device_count = $security->clear_trusted_devices(
				array(
					'source' => 'wp_cli',
					'reason' => 'clear_devices',
				)
			);

			if ( is_wp_error( $device_count ) ) {
				\WP_CLI::error( sprintf( "Trusted devices for user '%s' could not be cleared: %s", $user->user_login, $device_count->get_error_message() ) );
			}

			\WP_CLI::success( sprintf( "Cleared %d trusted devices for user '%s'.", $device_count, $user->user_login ) );
		}
	}

	/**
	 * Get user by ID, login, or email.
	 *
	 * @since 0.6.0
	 * @param string $user_identifier User ID, login, or email.
	 * @return \WP_User|\WP_Error User object on success, WP_Error on failure.
	 */
	private function get_user( string $user_identifier ): \WP_User|\WP_Error {
		$user = null;

		// Numeric first, so a login that looks like a number is still reachable
		// by its login if no such ID exists.
		if ( is_numeric( $user_identifier ) ) {
			$user = get_userdata( (int) $user_identifier );
		}

		if ( ! $user instanceof \WP_User ) {
			$user = get_user_by( 'login', $user_identifier );
		}

		if ( ! $user instanceof \WP_User ) {
			$user = get_user_by( 'email', $user_identifier );
		}

		if ( ! $user instanceof \WP_User ) {
			$user = new \WP_Error( 'user_not_found', sprintf( 'User not found: %s', $user_identifier ) );
		}

		return $user;
	}

	/**
	 * Whether --dry-run was passed.
	 *
	 * @since 1.9.0
	 * @param array $assoc_args Associative arguments.
	 * @return bool True for a dry run.
	 */
	private function is_dry_run( array $assoc_args ): bool {
		return (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
	}

	/**
	 * Describe a user's lock state: unlocked, locked until a time, or permanent.
	 *
	 * @since 1.9.0
	 * @param int $user_id User ID.
	 * @return string Lock state for display.
	 */
	private function describe_lock_status( int $user_id ): string {
		$security    = new Account_Security_Handler( $user_id );
		$lock_status = 'Unlocked';

		if ( $security->is_locked() ) {
			$locked_until = (int) get_user_meta( $user_id, META_LOCKED_UNTIL, true );

			if ( $locked_until > time() + PERMANENT_LOCK_THRESHOLD ) {
				$lock_status = 'Locked (permanent)';
			} else {
				$lock_status = sprintf( 'Locked until %s', wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $locked_until ) );
			}
		}

		return $lock_status;
	}

	/**
	 * Count a user's stored trusted devices.
	 *
	 * @since 1.9.0
	 * @param int $user_id User ID.
	 * @return int Number of devices.
	 */
	private function count_trusted_devices( int $user_id ): int {
		$trusted_devices = get_user_meta( $user_id, META_TRUSTED_DEVICES, true );

		return is_array( $trusted_devices ) ? count( $trusted_devices ) : 0;
	}

	/**
	 * Read user IDs from the database, and stop the command with an error if the query failed.
	 *
	 * The result cache and the found-rows count are off, so the user query is the last query
	 * run and $wpdb->last_error, which each query resets, is its own.
	 *
	 * @since 1.9.0
	 * @param array $query_args WP_User_Query arguments; fields, cache_results and count_total are set here.
	 * @return int[] User IDs.
	 */
	private function query_user_ids( array $query_args ): array {
		global $wpdb;

		$user_query = new \WP_User_Query(
			array_merge(
				$query_args,
				array(
					'fields'        => 'ID',
					'cache_results' => false,
					'count_total'   => false,
				)
			)
		);

		if ( '' !== $wpdb->last_error ) {
			\WP_CLI::error( sprintf( 'The user list could not be read from the database, so nothing was changed: %s', $wpdb->last_error ) );
		}

		return array_map( 'intval', $user_query->get_results() );
	}

	/**
	 * Lock one account, end its sessions, and record why.
	 *
	 * @since 1.3.0
	 * @since 1.9.0 Returns whether the lock was saved and whether the sessions ended, separately.
	 * @param int    $user_id User to lock.
	 * @param string $reason  Reason recorded in the event log.
	 * @return array{is_locked: bool, is_signed_out: bool} Whether the lock was saved, and whether no session remains. Sessions are ended either way.
	 */
	private function lock_user( int $user_id, string $reason ): array {
		$security  = new Account_Security_Handler( $user_id );
		$is_locked = $security->lock_account(
			PERMANENT_LOCK_DURATION,
			array(
				'source' => 'wp_cli',
				'reason' => $reason,
			)
		);

		return array(
			'is_locked'     => $is_locked,
			'is_signed_out' => $security->end_all_sessions(),
		);
	}

	/**
	 * Unlock one account and record why.
	 *
	 * @since 1.3.0
	 * @param int    $user_id User to unlock.
	 * @param string $reason  Reason recorded in the event log.
	 * @return bool True if the lock was removed.
	 */
	private function unlock_user( int $user_id, string $reason ): bool {
		$security = new Account_Security_Handler( $user_id );

		return $security->unlock_account(
			array(
				'source' => 'wp_cli',
				'reason' => $reason,
			)
		);
	}

	/**
	 * Emergency disable Quick 2FA across all users.
	 *
	 * Sets Quick 2FA to disabled mode, bypassing all 2FA checks. Use this only when
	 * administrators can't complete verification. It doesn't remove account locks:
	 * a locked account still can't log in, so unlock it with `wp quick-2fa unlock`.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * [--dry-run]
	 * : Report the mode change, without asking or changing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     # Emergency disable with confirmation
	 *     $ wp quick-2fa emergency-disable
	 *     This will disable Quick 2FA for all users. Continue? [y/n] y
	 *     Success: Quick 2FA has been emergency disabled.
	 *
	 *     # Emergency disable without confirmation
	 *     $ wp quick-2fa emergency-disable --yes
	 *     Success: Quick 2FA has been emergency disabled.
	 *
	 *     IMPORTANT: Quick 2FA is now bypassed for all users.
	 *     To re-enable, visit Settings > Quick 2FA in WordPress admin.
	 *
	 * @subcommand emergency-disable
	 *
	 * @since 0.8.0
	 * @since 1.9.0 Added --dry-run.
	 * @param array<string,mixed> $args       Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 */
	public function emergency_disable( array $args, array $assoc_args ): void {
		$current_mode = Plugin::instance()->get_settings()->get_string( OPTION_MODE );

		if ( MODE_DISABLED === $current_mode ) {
			\WP_CLI::warning( 'Quick 2FA is already in disabled mode.' );
		} elseif ( $this->is_dry_run( $assoc_args ) ) {
			\WP_CLI::success( sprintf( "Dry run: would change the mode from '%s' to '%s', turning 2FA off for every user.", $current_mode, MODE_DISABLED ) );
		} else {
			\WP_CLI::confirm( 'This will disable Quick 2FA for all users. Continue?', $assoc_args );

			update_option( OPTION_MODE, MODE_DISABLED );

			// Settings reload after the write, so this reads what was stored.
			if ( MODE_DISABLED !== Plugin::instance()->get_settings()->get_string( OPTION_MODE ) ) {
				error_log( '[Quick 2FA] Emergency disable via WP-CLI failed: the mode could not be saved' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failed emergency action logs unconditionally.
				\WP_CLI::error( 'The mode could not be saved, so 2FA is still on. Check the database is writable, or set quick2fa_mode to disabled directly (see the troubleshooting docs).' );
			}

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
}
