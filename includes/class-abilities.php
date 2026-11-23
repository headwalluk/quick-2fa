<?php
/**
 * Abilities API
 *
 * Read-only abilities that let an authorised agent query Quick 2FA's per-user
 * security state, its configuration and a redacted security event log.
 *
 * @package Quick_2FA
 * @since 1.8.0
 */

namespace Quick_2FA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Registers and runs Quick 2FA's abilities.
 *
 * Nothing here writes: every value is read from stored meta, never through the
 * handler methods that seed or prune it. See docs/developers/abilities.md.
 *
 * @since 1.8.0
 */
class Abilities {

	/**
	 * Event fields returned from a log entry's data, mapped to their output names.
	 *
	 * @var array<string,string>
	 */
	private const EVENT_DETAIL_FIELDS = array(
		'source'       => 'source',
		'reason'       => 'reason',
		'attempts'     => 'attempts',
		'success'      => 'success',
		'device_count' => 'device_count',
		'locked_until' => 'locked_until',
		'admin_id'     => 'actor_user_id',
	);

	/**
	 * Check whether the abilities are switched on in the settings.
	 *
	 * @since 1.8.0
	 * @return bool
	 */
	public function is_enabled(): bool {
		return Plugin::instance()->get_settings()->get_bool( OPTION_ABILITIES_ENABLED );
	}

	/**
	 * Register the Quick 2FA ability category.
	 *
	 * @since 1.8.0
	 */
	public function register_category(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		wp_register_ability_category(
			ABILITY_CATEGORY,
			array(
				'label'       => PLUGIN_NAME,
				'description' => __( 'Read-only queries about two-factor authentication, passwords and account security.', 'quick-2fa' ),
			)
		);
	}

	/**
	 * Register every Quick 2FA ability.
	 *
	 * @since 1.8.0
	 */
	public function register_abilities(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$user_record_schema = $this->get_user_record_schema();
		$user_input         = array(
			'type'        => array( 'integer', 'string' ),
			'description' => __( 'The user: an ID, login name or email address.', 'quick-2fa' ),
		);

		wp_register_ability(
			ABILITY_GET_USER_SECURITY_STATUS,
			array(
				'label'               => __( 'Get a user\'s security status', 'quick-2fa' ),
				'description'         => __( 'Returns when a user last changed their password, logged in and passed two-factor verification, whether two-factor authentication applies to them, whether their account is locked, and how many trusted devices they have. A null date means none has been recorded since Quick 2FA was installed.', 'quick-2fa' ),
				'category'            => ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'user' => $user_input ),
					'required'             => array( 'user' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $user_record_schema,
				'execute_callback'    => array( $this, 'get_user_security_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => $this->get_read_only_meta(),
			)
		);

		wp_register_ability(
			ABILITY_LIST_USER_SECURITY,
			array(
				'label'               => __( 'List users\' security status', 'quick-2fa' ),
				'description'         => __( 'Lists the security status of many users, such as administrators whose password is older than a year, or staff who have not logged in for 90 days. Without roles, lists users who can edit posts (staff), not customers or subscribers.', 'quick-2fa' ),
				'category'            => ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'roles'                    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Only users with one of these role slugs, such as administrator or shop_manager.', 'quick-2fa' ),
						),
						'password_older_than_days' => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Only users whose password is older than this many days. A password with no recorded change is aged from the user\'s registration.', 'quick-2fa' ),
						),
						'no_login_for_days'        => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => __( 'Only users with no recorded login in this many days, including users with no recorded login at all.', 'quick-2fa' ),
						),
						'locked_only'              => array(
							'type'        => 'boolean',
							'description' => __( 'Only users whose account is locked.', 'quick-2fa' ),
						),
						'limit'                    => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => ABILITIES_LIST_LIMIT_MAX,
							'default' => ABILITIES_LIST_LIMIT_DEFAULT,
						),
						'offset'                   => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total'  => array(
							'type'        => 'integer',
							'description' => __( 'How many users matched, before limit and offset.', 'quick-2fa' ),
						),
						'offset' => array( 'type' => 'integer' ),
						'users'  => array(
							'type'  => 'array',
							'items' => $user_record_schema,
						),
					),
				),
				'execute_callback'    => array( $this, 'list_user_security' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => $this->get_read_only_meta(),
			)
		);

		wp_register_ability(
			ABILITY_GET_CONFIGURATION,
			array(
				'label'               => __( 'Get the two-factor authentication configuration', 'quick-2fa' ),
				'description'         => __( 'Returns how Quick 2FA is configured: who must use two-factor authentication, how long verifications and trusted devices last, lockout duration and password reminder settings.', 'quick-2fa' ),
				'category'            => ABILITY_CATEGORY,
				'output_schema'       => $this->get_configuration_schema(),
				'execute_callback'    => array( $this, 'get_configuration' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => $this->get_read_only_meta(),
			)
		);

		wp_register_ability(
			ABILITY_GET_SECURITY_EVENTS,
			array(
				'label'               => __( 'Get a user\'s security events', 'quick-2fa' ),
				'description'         => __( 'Returns a user\'s recent security events, newest first: verification codes sent, failed and successful verifications, account locks, password changes and trusted-device revocations. IP addresses and browser details are never returned.', 'quick-2fa' ),
				'category'            => ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'user'        => $user_input,
						'limit'       => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => LOG_MAX_ENTRIES,
							'default' => ABILITIES_EVENTS_LIMIT_DEFAULT,
						),
						'event_types' => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => $this->get_event_types(),
							),
							'description' => __( 'Only events of these types.', 'quick-2fa' ),
						),
					),
					'required'             => array( 'user' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->get_events_schema(),
				'execute_callback'    => array( $this, 'get_security_events' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => $this->get_read_only_meta(),
			)
		);
	}

	/**
	 * Allow a caller with the required capability and, outside Application Passwords and WP-CLI, a verified session.
	 *
	 * @since 1.8.0
	 * @param mixed $input The ability input (unused).
	 * @return bool|\WP_Error True if allowed, or why not.
	 */
	public function check_permission( mixed $input = null ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by the permission_callback signature.
		/**
		 * Filter the capability a user needs to run Quick 2FA's abilities.
		 *
		 * @since 1.8.0
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$capability = apply_filters( 'quick2fa_abilities_capability', ABILITIES_DEFAULT_CAPABILITY );
		$capability = is_string( $capability ) && '' !== $capability ? $capability : ABILITIES_DEFAULT_CAPABILITY;

		$is_session_exempt = did_action( 'application_password_did_authenticate' ) || ( defined( 'WP_CLI' ) && WP_CLI );

		if ( ! current_user_can( $capability ) ) {
			$result = new \WP_Error( 'quick2fa_ability_forbidden', __( 'You are not allowed to query Quick 2FA.', 'quick-2fa' ), array( 'status' => rest_authorization_required_code() ) );
		} elseif ( ! $this->is_session_verified( $is_session_exempt ) ) {
			$result = new \WP_Error( 'quick2fa_ability_unverified', __( 'Complete two-factor verification before querying Quick 2FA.', 'quick-2fa' ), array( 'status' => 403 ) );
		} else {
			$result = true;
		}

		return $result;
	}

	/**
	 * Check that a cookie-authenticated caller's login session has passed 2FA.
	 *
	 * @since 1.8.0
	 * @param bool $is_session_exempt True for Application Password and WP-CLI requests, which have no browser session.
	 * @return bool
	 */
	private function is_session_verified( bool $is_session_exempt ): bool {
		return $is_session_exempt || ! Plugin::instance()->user_needs_verification();
	}

	/**
	 * Run quick-2fa/get-user-security-status.
	 *
	 * @since 1.8.0
	 * @param mixed $input Validated input with a "user" identifier.
	 * @return array|\WP_Error The user's security record, or an error if there is no such user.
	 */
	public function get_user_security_status( mixed $input = null ): array|\WP_Error {
		$user = $this->resolve_user( is_array( $input ) ? ( $input['user'] ?? null ) : null );

		return $user instanceof \WP_User ? $this->build_user_record( $user ) : $this->get_user_not_found_error();
	}

	/**
	 * Run quick-2fa/list-user-security.
	 *
	 * @since 1.8.0
	 * @param mixed $input Validated filters, limit and offset.
	 * @return array|\WP_Error The matching records, or an error if too many users would be scanned.
	 */
	public function list_user_security( mixed $input = null ): array|\WP_Error {
		$input             = is_array( $input ) ? $input : array();
		$roles             = isset( $input['roles'] ) && is_array( $input['roles'] ) ? array_values( array_filter( $input['roles'], 'is_string' ) ) : array();
		$password_max_days = isset( $input['password_older_than_days'] ) ? max( 0, (int) $input['password_older_than_days'] ) : null;
		$login_max_days    = isset( $input['no_login_for_days'] ) ? max( 0, (int) $input['no_login_for_days'] ) : null;
		$is_locked_only    = (bool) filter_var( $input['locked_only'] ?? false, FILTER_VALIDATE_BOOLEAN );
		$limit             = min( ABILITIES_LIST_LIMIT_MAX, max( 1, (int) ( $input['limit'] ?? ABILITIES_LIST_LIMIT_DEFAULT ) ) );
		$offset            = max( 0, (int) ( $input['offset'] ?? 0 ) );

		$query_args = array(
			'fields'  => 'ID',
			'number'  => ABILITIES_LIST_SCAN_MAX + 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		);

		if ( empty( $roles ) ) {
			$query_args['capability'] = ABILITIES_STAFF_CAPABILITY;
		} else {
			$query_args['role__in'] = $roles;
		}

		$user_ids = array_map( 'intval', get_users( $query_args ) );

		if ( count( $user_ids ) > ABILITIES_LIST_SCAN_MAX ) {
			$result = new \WP_Error(
				'quick2fa_too_many_users',
				sprintf(
					/* translators: %d: the maximum number of users one query may cover */
					__( 'More than %d users match. Narrow the query with roles.', 'quick-2fa' ),
					ABILITIES_LIST_SCAN_MAX
				),
				array( 'status' => 400 )
			);
		} else {
			cache_users( $user_ids );

			$now     = time();
			$records = array();

			foreach ( $user_ids as $user_id ) {
				$user = get_userdata( $user_id );

				if ( ! $user instanceof \WP_User ) {
					continue;
				}

				$record          = $this->build_user_record( $user );
				$password_since  = $this->get_password_baseline( $user );
				$last_login_time = (int) get_user_meta( $user_id, META_LAST_LOGIN, true );

				$is_match = true;

				if ( null !== $password_max_days && ( $now - $password_since ) <= $password_max_days * DAY_IN_SECONDS ) {
					$is_match = false;
				}

				if ( null !== $login_max_days && $last_login_time > 0 && ( $now - $last_login_time ) <= $login_max_days * DAY_IN_SECONDS ) {
					$is_match = false;
				}

				if ( $is_locked_only && ! $record['is_locked'] ) {
					$is_match = false;
				}

				if ( $is_match ) {
					$records[] = $record;
				}
			}

			$result = array(
				'total'  => count( $records ),
				'offset' => $offset,
				'users'  => array_slice( $records, $offset, $limit ),
			);
		}

		return $result;
	}

	/**
	 * Run quick-2fa/get-configuration.
	 *
	 * @since 1.8.0
	 * @return array The settings an agent needs to interpret the other abilities.
	 */
	public function get_configuration(): array {
		$settings              = Plugin::instance()->get_settings();
		$login_recording_since = (int) get_option( OPTION_LAST_LOGIN_SINCE, 0 );

		return array(
			'plugin_version'                  => QUICK_2FA_VERSION,
			'mode'                            => $settings->get_string( OPTION_MODE ),
			'protected_roles'                 => array_values( $settings->get_array( OPTION_PROTECTED_ROLES ) ),
			'verification_period_days'        => $settings->get_int( OPTION_VERIFICATION_PERIOD ),
			'code_expiry_minutes'             => $settings->get_int( OPTION_CODE_EXPIRY ),
			'trusted_devices_enabled'         => are_trusted_devices_enabled(),
			'trusted_device_expiry_days'      => $settings->get_int( OPTION_TRUSTED_DEVICE_EXPIRY ),
			'lockout_duration_minutes'        => $settings->get_int( OPTION_LOCKOUT_DURATION ),
			'password_reminders_enabled'      => $settings->get_bool( OPTION_PASSWORD_REMINDERS_ENABLED ),
			'password_reminder_period_days'   => $settings->get_int( OPTION_PASSWORD_REMINDER_PERIOD ),
			'password_reminder_cooldown_days' => $settings->get_int( OPTION_PASSWORD_REMINDER_COOLDOWN ),
			'last_login_recorded_since'       => $this->format_time( $login_recording_since ),
		);
	}

	/**
	 * Run quick-2fa/get-security-events.
	 *
	 * @since 1.8.0
	 * @param mixed $input Validated input: user, and optional limit and event types.
	 * @return array|\WP_Error The redacted events, or an error if there is no such user.
	 */
	public function get_security_events( mixed $input = null ): array|\WP_Error {
		$input = is_array( $input ) ? $input : array();
		$user  = $this->resolve_user( $input['user'] ?? null );

		if ( ! $user instanceof \WP_User ) {
			return $this->get_user_not_found_error();
		}

		$limit       = min( LOG_MAX_ENTRIES, max( 1, (int) ( $input['limit'] ?? ABILITIES_EVENTS_LIMIT_DEFAULT ) ) );
		$event_types = isset( $input['event_types'] ) && is_array( $input['event_types'] ) ? $input['event_types'] : array();
		$log_entries = get_user_meta( $user->ID, META_LOGS, true );
		$events      = array();

		foreach ( is_array( $log_entries ) ? $log_entries : array() as $log_entry ) {
			$event_type = is_array( $log_entry ) ? (string) ( $log_entry['event_type'] ?? '' ) : '';

			if ( '' === $event_type || ( ! empty( $event_types ) && ! in_array( $event_type, $event_types, true ) ) ) {
				continue;
			}

			$events[] = array(
				'event_type'  => $event_type,
				'time'        => $this->format_time( (int) ( $log_entry['timestamp'] ?? 0 ) ),
				'details'     => $this->redact_event_data( $log_entry['data'] ?? array() ),
				'ip_metadata' => null,
			);

			if ( count( $events ) >= $limit ) {
				break;
			}
		}

		return array(
			'user_id' => $user->ID,
			'events'  => $events,
		);
	}

	/**
	 * Build one user's security record from stored meta, without writing anything.
	 *
	 * @since 1.8.0
	 * @param \WP_User $user The user.
	 * @return array The record, shaped as get_user_record_schema().
	 */
	private function build_user_record( \WP_User $user ): array {
		$now               = time();
		$registered_time   = $this->get_registered_time( $user );
		$password_changed  = (int) get_user_meta( $user->ID, META_PASSWORD_LAST_CHANGED, true );
		$locked_until      = (int) get_user_meta( $user->ID, META_LOCKED_UNTIL, true );
		$is_locked         = $locked_until > $now;
		$is_lock_permanent = $is_locked && $locked_until > $now + PERMANENT_LOCK_THRESHOLD;
		$trusted_devices   = get_user_meta( $user->ID, META_TRUSTED_DEVICES, true );
		$device_count      = 0;

		// A stamp equal to the registration time was seeded by get_password_age(), not a real change.
		$is_change_recorded = $password_changed > 0 && $password_changed !== $registered_time;

		foreach ( is_array( $trusted_devices ) ? $trusted_devices : array() as $device_expiry ) {
			if ( (int) $device_expiry > $now ) {
				++$device_count;
			}
		}

		return array(
			'user_id'                  => $user->ID,
			'user_login'               => $user->user_login,
			'display_name'             => $user->display_name,
			'roles'                    => array_values( (array) $user->roles ),
			'registered'               => $this->format_time( $registered_time ),
			'is_2fa_required'          => Plugin::instance()->user_role_requires_2fa( $user->ID ),
			'password_last_changed'    => $is_change_recorded ? $this->format_time( $password_changed ) : null,
			'password_age_days'        => (int) floor( ( $now - $this->get_password_baseline( $user ) ) / DAY_IN_SECONDS ),
			'is_password_reminder_due' => $this->is_password_reminder_due( $user ),
			'last_login'               => $this->format_time( (int) get_user_meta( $user->ID, META_LAST_LOGIN, true ) ),
			'last_2fa_verification'    => $this->format_time( (int) get_user_meta( $user->ID, META_LAST_VERIFIED, true ) ),
			'is_locked'                => $is_locked,
			'is_lock_permanent'        => $is_lock_permanent,
			'locked_until'             => $is_locked && ! $is_lock_permanent ? $this->format_time( $locked_until ) : null,
			'trusted_device_count'     => $device_count,
		);
	}

	/**
	 * Get the time a user's password age counts from: the last change, or their registration.
	 *
	 * @since 1.8.0
	 * @param \WP_User $user The user.
	 * @return int Unix timestamp.
	 */
	private function get_password_baseline( \WP_User $user ): int {
		$password_changed = (int) get_user_meta( $user->ID, META_PASSWORD_LAST_CHANGED, true );

		return $password_changed > 0 ? $password_changed : $this->get_registered_time( $user );
	}

	/**
	 * Get a user's registration time, or now for an invalid date, as get_password_age() does.
	 *
	 * @since 1.8.0
	 * @param \WP_User $user The user.
	 * @return int Unix timestamp.
	 */
	private function get_registered_time( \WP_User $user ): int {
		$registered_at = strtotime( $user->user_registered );

		return ( false === $registered_at || $registered_at < 0 ) ? time() : $registered_at;
	}

	/**
	 * Check whether the user would be sent to the password reminder page, as Password_Reminder_Handler::needs_reminder() decides.
	 *
	 * @since 1.8.0
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	private function is_password_reminder_due( \WP_User $user ): bool {
		$settings    = Plugin::instance()->get_settings();
		$period_days = $settings->get_int( OPTION_PASSWORD_REMINDER_PERIOD );
		$age_days    = (int) floor( ( time() - $this->get_password_baseline( $user ) ) / DAY_IN_SECONDS );

		return MODE_DISABLED !== $settings->get_string( OPTION_MODE )
			&& $settings->get_bool( OPTION_PASSWORD_REMINDERS_ENABLED )
			&& $age_days > $period_days
			&& ! ( new Password_Reminder_Handler( $user->ID ) )->is_in_cooldown();
	}

	/**
	 * Copy the allowed fields of a log entry's data; IP addresses, email addresses and device keys are dropped.
	 *
	 * @since 1.8.0
	 * @param mixed $event_data The stored event data.
	 * @return object The allowed fields, as an object so an empty set encodes as {}.
	 */
	private function redact_event_data( mixed $event_data ): object {
		$details = array();

		foreach ( is_array( $event_data ) ? $event_data : array() as $field => $value ) {
			$output_name = self::EVENT_DETAIL_FIELDS[ $field ] ?? '';

			if ( '' === $output_name || ! is_scalar( $value ) ) {
				continue;
			}

			if ( 'locked_until' === $field ) {
				$details[ $output_name ] = (int) $value > time() + PERMANENT_LOCK_THRESHOLD ? null : $this->format_time( (int) $value );
			} elseif ( in_array( $field, array( 'attempts', 'device_count', 'admin_id' ), true ) ) {
				$details[ $output_name ] = (int) $value;
			} elseif ( 'success' === $field ) {
				$details[ $output_name ] = (bool) $value;
			} else {
				// source and reason; a WP-CLI reason is free text typed by an operator.
				$details[ $output_name ] = mb_substr( (string) $value, 0, ABILITIES_EVENT_REASON_MAX_LENGTH );
			}
		}

		return (object) $details;
	}

	/**
	 * Find a user of this site by ID, email address or login name.
	 *
	 * @since 1.8.0
	 * @since 1.8.1 On multisite, a user of another site counts as not found, unless the caller can manage network users.
	 * @param mixed $identifier The identifier from the ability input.
	 * @return ?\WP_User The user, or null if none matches.
	 */
	private function resolve_user( mixed $identifier ): ?\WP_User {
		$user = false;

		if ( is_int( $identifier ) || ( is_string( $identifier ) && ctype_digit( $identifier ) ) ) {
			$user = get_userdata( (int) $identifier );
		} elseif ( is_string( $identifier ) && is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		} elseif ( is_string( $identifier ) && '' !== $identifier ) {
			$user = get_user_by( 'login', $identifier );
		} else {
			// No usable identifier; $user stays false.
		}

		// User lookups are network-wide; a subsite administrator sees only this site's users.
		$is_visible = $user instanceof \WP_User && ( ! is_multisite() || is_user_member_of_blog( $user->ID ) || current_user_can( 'manage_network_users' ) );

		return $is_visible ? $user : null;
	}

	/**
	 * Build the error returned for an unknown user.
	 *
	 * @since 1.8.0
	 * @return \WP_Error
	 */
	private function get_user_not_found_error(): \WP_Error {
		return new \WP_Error( 'quick2fa_user_not_found', __( 'No user matches that ID, login name or email address.', 'quick-2fa' ), array( 'status' => 404 ) );
	}

	/**
	 * Format a Unix timestamp as an ISO 8601 UTC date, or null for none.
	 *
	 * @since 1.8.0
	 * @param int $timestamp Unix timestamp; 0 or less means none.
	 * @return ?string
	 */
	private function format_time( int $timestamp ): ?string {
		return $timestamp > 0 ? gmdate( DATE_ATOM, $timestamp ) : null;
	}

	/**
	 * List every event type the security log records.
	 *
	 * @since 1.8.0
	 * @return string[]
	 */
	private function get_event_types(): array {
		return array(
			LOG_CODE_GENERATED,
			LOG_CODE_SENT,
			LOG_VERIFICATION_SUCCESS,
			LOG_VERIFICATION_FAILED,
			LOG_ACCOUNT_LOCKED,
			LOG_ACCOUNT_UNLOCKED,
			LOG_PASSWORD_CHANGED,
			LOG_PASSWORD_REMINDER_DISMISSED,
			LOG_DEVICE_REVOKED,
			LOG_ALL_DEVICES_REVOKED,
		);
	}

	/**
	 * Build the meta shared by every ability: read-only annotations, shown in REST.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	private function get_read_only_meta(): array {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
		);
	}

	/**
	 * Build the output schema for one user's security record.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	private function get_user_record_schema(): array {
		$date_or_null = array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);

		return array(
			'type'       => 'object',
			'properties' => array(
				'user_id'                  => array( 'type' => 'integer' ),
				'user_login'               => array( 'type' => 'string' ),
				'display_name'             => array( 'type' => 'string' ),
				'roles'                    => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'registered'               => $date_or_null,
				'is_2fa_required'          => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this user must pass two-factor verification to reach the admin area.', 'quick-2fa' ),
				),
				'password_last_changed'    => array_merge(
					$date_or_null,
					array( 'description' => __( 'When the password was last changed, or null if no change has been recorded since Quick 2FA was installed.', 'quick-2fa' ) )
				),
				'password_age_days'        => array(
					'type'        => 'integer',
					'description' => __( 'Days since the last recorded change, or since registration if none is recorded.', 'quick-2fa' ),
				),
				'is_password_reminder_due' => array( 'type' => 'boolean' ),
				'last_login'               => array_merge(
					$date_or_null,
					array( 'description' => __( 'The last recorded login, or null if none has been recorded; see last_login_recorded_since in the configuration.', 'quick-2fa' ) )
				),
				'last_2fa_verification'    => $date_or_null,
				'is_locked'                => array( 'type' => 'boolean' ),
				'is_lock_permanent'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the lock lasts until an administrator removes it.', 'quick-2fa' ),
				),
				'locked_until'             => $date_or_null,
				'trusted_device_count'     => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * Build the output schema for quick-2fa/get-configuration.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	private function get_configuration_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'plugin_version'                  => array( 'type' => 'string' ),
				'mode'                            => array(
					'type'        => 'string',
					'enum'        => array( MODE_ALL, MODE_ROLES, MODE_DISABLED ),
					'description' => __( 'Who must use two-factor authentication: every user, the protected roles only, or nobody.', 'quick-2fa' ),
				),
				'protected_roles'                 => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'The roles that must use two-factor authentication when the mode is "roles".', 'quick-2fa' ),
				),
				'verification_period_days'        => array( 'type' => 'integer' ),
				'code_expiry_minutes'             => array( 'type' => 'integer' ),
				'trusted_devices_enabled'         => array( 'type' => 'boolean' ),
				'trusted_device_expiry_days'      => array( 'type' => 'integer' ),
				'lockout_duration_minutes'        => array( 'type' => 'integer' ),
				'password_reminders_enabled'      => array( 'type' => 'boolean' ),
				'password_reminder_period_days'   => array( 'type' => 'integer' ),
				'password_reminder_cooldown_days' => array( 'type' => 'integer' ),
				'last_login_recorded_since'       => array(
					'type'        => array( 'string', 'null' ),
					'format'      => 'date-time',
					'description' => __( 'When Quick 2FA began recording logins; earlier logins are unknown.', 'quick-2fa' ),
				),
			),
		);
	}

	/**
	 * Build the output schema for quick-2fa/get-security-events.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	private function get_events_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'user_id' => array( 'type' => 'integer' ),
				'events'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'event_type'  => array(
								'type' => 'string',
								'enum' => $this->get_event_types(),
							),
							'time'        => array(
								'type'   => array( 'string', 'null' ),
								'format' => 'date-time',
							),
							'details'     => array(
								'type'        => 'object',
								'description' => __( 'Event context: source, reason, attempts, success, device_count, locked_until, actor_user_id. Only the fields the event recorded.', 'quick-2fa' ),
							),
							'ip_metadata' => array(
								'type'        => array( 'object', 'null' ),
								'description' => __( 'Reserved for non-identifying IP metadata such as country; always null in this version.', 'quick-2fa' ),
							),
						),
					),
				),
			),
		);
	}
}
