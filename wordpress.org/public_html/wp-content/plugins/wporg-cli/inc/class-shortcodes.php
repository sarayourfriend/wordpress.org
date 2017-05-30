<?php

namespace WPOrg_Cli;

class Shortcodes{

	private static $auth_token;

	/**
	 * Register custom shortcodes.
	 */
	public static function action_init() {
		add_shortcode( 'cli-repo-list', array( __CLASS__, 'repo_list' ) );
	}

	/**
	 * Renders WP-CLI repositories in a table format.
	 */
	public static function repo_list( $atts ) {

		if ( isset( $atts['auth_token'] ) ) {
			self::$auth_token = $atts['auth_token'];
		}

		$out = '<h2>Repositories</h2>';
		$repos = self::github_request( 'https://api.github.com/orgs/wp-cli/repos?per_page=100' );
		if ( is_wp_error( $repos ) ) {
			$out .= '<p>' . esc_html( $repos->get_error_message() ) . '</p>';
			return $out;
		}
		$repo_list = array();
		foreach( $repos as $repo ) {
			if ( ! preg_match( '#^wp-cli/.+-command$#', $repo->full_name ) ) {
				continue;
			}
			$repo_list[] = $repo->full_name;
		}
		sort( $repo_list );
		array_unshift( $repo_list, 'wp-cli/wp-cli' );
		$out .= '<table>' . PHP_EOL;
		$out .= '<thead>' . PHP_EOL;
		$out .= '<tr>' . PHP_EOL;
		$out .= '<th>Repository</th>' . PHP_EOL;
		$out .= '<th>Overview</th>' . PHP_EOL;
		$out .= '<th>Status</th>' . PHP_EOL;
		$out .= '</tr>' . PHP_EOL;
		$out .= '</thead>' . PHP_EOL;
		foreach( $repo_list as $i => $repo_name ) {
			$out .= '<tr>' . PHP_EOL;
			// Name
			$out .= '<td><a href="' . esc_url( sprintf( 'https://github.com/%s', $repo_name ) ) . '">' . esc_html( $repo_name ) . '</td>' . PHP_EOL;
			// Overview
			$out .= '<td><ul>' . PHP_EOL;
			// Overview: Active milestone
			$url = sprintf( 'https://api.github.com/repos/%s/milestones', $repo_name );
			$milestones = self::github_request( $url );
			$latest_milestone = '<em>None</em>';
			if ( is_wp_error( $milestones ) ) {
				$latest_milestone = $milestones->get_error_message();
			} elseif ( ! empty( $milestones ) ) {
				$milestones = array_shift( $milestones );
				$latest_milestone = '<a href="' . esc_url( $milestones->html_url ) . '">v' . esc_html( $milestones->title ) . '</a> (' . (int) $milestones->open_issues . ' open, ' . (int) $milestones->closed_issues . ' closed)';
			}
			$out .= '<li>Active: ' . wp_kses_post( $latest_milestone ) . '</li>';
			// Overview: Latest release
			$url = sprintf( 'https://api.github.com/repos/%s/releases', $repo_name );
			$releases = self::github_request( $url );
			$latest_release = '<em>None</em>';
			if ( is_wp_error( $releases ) ) {
				$latest_release = $releases->get_error_message();
			} elseif ( ! empty( $releases ) ) {
				$releases = array_shift( $releases );
				$latest_release = '<a href="' . esc_url( $releases->html_url ) . '">' . esc_html( $releases->tag_name ) . '</a>';
			}
			$out .= '<li>Latest: ' . wp_kses_post( $latest_release ) . '</li>';
			$out .= '</ul></td>' . PHP_EOL;
			// Status
			// dist-archive primarily uses Circle
			if ( 'wp-cli/dist-archive-command' === $repo_name ) {
				$status_image = sprintf( 'https://circleci.com/gh/%s/tree/master.svg?style=svg', $repo_name );
				$status_link = sprintf( 'https://circleci.com/gh/%s/tree/master', $repo_name );
			} else {
				$status_image = sprintf( 'https://travis-ci.org/%s.svg?branch=master', $repo_name );
				$status_link = sprintf( 'https://travis-ci.org/%s/branches', $repo_name );
			}
			$out .= '<td><a href="' . esc_url( $status_link ) . '"><img src="' . esc_url( $status_image ) . '">' . '</a></td>' . PHP_EOL;
			$out .= '</tr>' . PHP_EOL;
		}
		$out .= '</table>';
		return $out;
	}

	/**
	 * Make an API request to GitHub
	 */
	private static function github_request( $url ) {

		$cache_key = 'cli_github_' . md5( $url );
		if ( false !== ( $cache_value = get_transient( $cache_key ) ) ) {
			return $cache_value;
		}

		$request = array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress.org / WP-CLI',
			)
		);
		if ( isset( self::$auth_token ) ) {
			$request['headers']['Authorization'] = 'token ' . self::$auth_token;
		}
		$response = wp_remote_get( $url, $request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			return new \WP_Error( 'github_error', sprintf( 'GitHub API error (HTTP code %d )', $response_code ) );
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body );
		set_transient( $cache_key, $data, '', 3 * MINUTE_IN_SECONDS );
		return $data;
	}

}
