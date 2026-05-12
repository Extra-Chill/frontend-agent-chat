<?php
/**
 * Configuration — per-site and network-wide agent settings.
 *
 * Each site configures which registered WordPress agent powers its floating chat.
 * On multisite, a network-wide option serves as the default for all sites.
 * Per-site options override the network default (including opting out).
 *
 * Visibility is determined by Agents API's access surface where available,
 * with legacy Data Machine access retained only as a compatibility fallback.
 *
 * Resolution order:
 *   1. Per-site option  (get_option)
 *   2. Network option   (get_site_option, multisite only)
 *   3. Filter           (frontend_agent_chat_config)
 *   4. Hardcoded defaults
 *
 * @package FrontendAgentChat
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the frontend chat configuration for the current site.
 *
 * On multisite, falls back to the network-wide option when the current
 * site has no per-site config. This lets you configure the agent once
 * and have it apply across all sites in the network.
 *
 * @since 0.4.0
 * @since 0.6.0 Added network option fallback for multisite.
 *
 * @return array
 */
function frontend_agent_chat_get_config(): array {
	$defaults = array(
		'agent_slug'       => '',
		'description'      => __( 'Your AI assistant.', 'frontend-agent-chat' ),
		'enabled'          => false,
		'loading_messages' => true,
	);

	$saved = get_option( 'frontend_agent_chat_config', array() );
	if ( empty( $saved ) ) {
		$saved = get_option( 'data_machine_frontend_chat_config', array() );
	}

	// On multisite, fall back to the network option when no per-site config exists.
	if ( empty( $saved ) && is_multisite() ) {
		$saved = get_site_option( 'frontend_agent_chat_config', array() );
		if ( empty( $saved ) ) {
			$saved = get_site_option( 'data_machine_frontend_chat_config', array() );
		}
	}

	$config = wp_parse_args( $saved, $defaults );

	/**
	 * Filter the frontend chat config for the current site.
	 *
	 * @since 0.4.0
	 *
	 * @param array $config Current configuration.
	 */
	$config = apply_filters( 'frontend_agent_chat_config', $config );

	/**
	 * Legacy config filter retained for Data Machine Frontend Chat installs.
	 *
	 * @since 0.4.0
	 *
	 * @param array $config Current configuration.
	 */
	return apply_filters( 'data_machine_frontend_chat_config', $config );
}

/**
 * Check whether the current user can see the chat widget.
 *
 * Prefers Agents API access abilities/helpers and falls back to the legacy
 * Data Machine helper for installs that have not adopted Agents API access
 * stores yet. Site administrators retain access as a final capability gate.
 *
 * @param array|null $agent Resolved agent row from frontend_agent_chat_resolve_agent.
 * @return bool
 */
function frontend_agent_chat_user_can_see( ?array $agent ): bool {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$allowed = frontend_agent_chat_current_user_can_access_agent( $agent );
	if ( ! $allowed ) {
		$allowed = current_user_can( 'manage_options' );
	}

	$allowed = (bool) apply_filters( 'frontend_agent_chat_user_can_see', $allowed, $agent );

	return (bool) apply_filters( 'data_machine_frontend_chat_user_can_see', $allowed, $agent );
}

/**
 * Check the current user's access to a resolved agent.
 *
 * @param array|null $agent Resolved agent row.
 * @return bool
 */
function frontend_agent_chat_current_user_can_access_agent( ?array $agent ): bool {
	$agent_slug = frontend_agent_chat_get_agent_access_slug( $agent );
	if ( '' === $agent_slug ) {
		return false;
	}

	$minimum_role = class_exists( 'WP_Agent_Access_Grant' ) ? WP_Agent_Access_Grant::ROLE_VIEWER : 'viewer';
	$allowed      = frontend_agent_chat_can_access_agent_via_ability( $agent_slug, $minimum_role );
	if ( true === $allowed || ( false === $allowed && frontend_agent_chat_agents_api_has_access_store() ) ) {
		return $allowed;
	}

	if ( class_exists( 'WP_Agent_Access' ) ) {
		$allowed = WP_Agent_Access::can_current_principal_access_agent( $agent_slug, $minimum_role );
		if ( $allowed || frontend_agent_chat_agents_api_has_access_store() ) {
			return $allowed;
		}
	}

	if ( class_exists( '\DataMachine\Abilities\PermissionHelper' ) ) {
		$agent_id = (int) ( $agent['agent_id'] ?? 0 );
		return $agent_id > 0 && \DataMachine\Abilities\PermissionHelper::can_access_agent( $agent_id, $minimum_role );
	}

	return false;
}

/**
 * Check whether Agents API can discover a host access store.
 *
 * @return bool
 */
function frontend_agent_chat_agents_api_has_access_store(): bool {
	static $has_access_store = null;

	if ( null === $has_access_store ) {
		$class_name = 'WP_Agent_Access';
		$callback   = array( $class_name, 'get_store' );
		$store      = class_exists( $class_name ) && is_callable( $callback ) ? call_user_func( $callback ) : null;

		$has_access_store = interface_exists( 'WP_Agent_Access_Store' ) && $store instanceof WP_Agent_Access_Store;
	}

	return $has_access_store;
}

/**
 * Check agent access through the Agents API ability when available.
 *
 * @param string $agent_slug   Registered agent slug/id.
 * @param string $minimum_role Minimum access role.
 * @return bool|null Access decision, or null when the ability is unavailable.
 */
function frontend_agent_chat_can_access_agent_via_ability( string $agent_slug, string $minimum_role ): ?bool {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'agents/can-access-agent' ) : null;
	if ( ! $ability ) {
		return null;
	}

	$result = $ability->execute(
		array(
			'agent'        => $agent_slug,
			'minimum_role' => $minimum_role,
		)
	);

	if ( is_wp_error( $result ) || ! is_array( $result ) || ! array_key_exists( 'allowed', $result ) ) {
		return false;
	}

	return (bool) $result['allowed'];
}

/**
 * Resolve the registered agent slug/id used by Agents API access checks.
 *
 * @param array|null $agent Resolved agent row.
 * @return string
 */
function frontend_agent_chat_get_agent_access_slug( ?array $agent ): string {
	if ( ! is_array( $agent ) ) {
		return '';
	}

	$slug = (string) ( $agent['agent_slug'] ?? '' );
	if ( '' === $slug ) {
		$slug = (string) ( $agent['slug'] ?? '' );
	}

	return sanitize_title( $slug );
}

/**
 * Resolve the agent from the Data Machine agents table by slug.
 *
 * @param string $slug Agent slug to resolve.
 * @return array|null Agent row or null.
 */
function frontend_agent_chat_resolve_agent( string $slug ): ?array {
	static $cache = array();
	$slug         = sanitize_title( $slug );

	if ( isset( $cache[ $slug ] ) ) {
		return $cache[ $slug ];
	}

	if ( function_exists( 'wp_get_agent' ) ) {
		$registered = wp_get_agent( $slug );
		if ( is_object( $registered ) && method_exists( $registered, 'get_slug' ) && method_exists( $registered, 'get_label' ) && method_exists( $registered, 'get_description' ) ) {
			$cache[ $slug ] = array(
				'agent_id'          => 0,
				'agent_slug'        => $registered->get_slug(),
				'agent_name'        => $registered->get_label(),
				'agent_description' => $registered->get_description(),
			);
			return $cache[ $slug ];
		}
	}

	$agent = null;
	if ( class_exists( '\DataMachine\Core\Database\Agents\Agents' ) ) {
		$repo  = new \DataMachine\Core\Database\Agents\Agents();
		$agent = $repo->get_by_slug( $slug );
	}

	$cache[ $slug ] = $agent;
	return $agent;
}

/**
 * Backward-compatible config wrapper.
 *
 * @return array
 */
function data_machine_frontend_chat_get_config(): array {
	return frontend_agent_chat_get_config();
}

/**
 * Backward-compatible visibility wrapper.
 *
 * @param array $agent Agent row.
 * @return bool
 */
function data_machine_frontend_chat_user_can_see( array $agent ): bool {
	return frontend_agent_chat_user_can_see( $agent );
}

/**
 * Backward-compatible agent resolver wrapper.
 *
 * @param string $slug Agent slug.
 * @return array|null
 */
function data_machine_frontend_chat_resolve_agent( string $slug ): ?array {
	return frontend_agent_chat_resolve_agent( $slug );
}
