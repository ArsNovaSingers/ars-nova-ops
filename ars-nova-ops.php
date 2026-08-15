<?php
/**
 * Plugin Name: Ars Nova Ops (Plugin Installer)
 * Description: Admin-only REST endpoints that let the Ars Nova WordPress MCP connector INSTALL, UPDATE, ACTIVATE, DEACTIVATE and DELETE plugins by command. Wraps WordPress core's own Plugin_Upgrader. Accepts a WordPress.org slug, a zip URL (allow-listed hosts), a base64 zip, or a Google Drive file ID fetched authenticated via ars-nova-google-connector. Also exposes the handful of core site options WordPress core REST omits. Production installs require an explicit confirmation flag.
 * Version: 1.2.0
 * Author: Ars Nova (Jonathan Raabe) + Claude
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ANS_OPS_VERSION', '1.2.0' );
define( 'ANS_OPS_NS', 'ans-ops/v1' );

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------ */

/** Permission callback: only true admins who can install plugins. */
function ans_ops_can_install() {
	return current_user_can( 'install_plugins' );
}
function ans_ops_can_activate() {
	return current_user_can( 'activate_plugins' );
}
function ans_ops_can_delete() {
	return current_user_can( 'delete_plugins' );
}

/** Is this the live production site? Used to gate destructive installs. */
function ans_ops_is_production() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$prod = apply_filters( 'ans_ops_production_hosts', array( 'arsnovasingers.org', 'www.arsnovasingers.org' ) );
	return in_array( strtolower( (string) $host ), array_map( 'strtolower', $prod ), true );
}

/** Hosts a zip URL is allowed to be fetched from. Filterable. */
function ans_ops_allowed_hosts() {
	$self = wp_parse_url( home_url(), PHP_URL_HOST );
	$defaults = array(
		$self,
		'github.com',
		'codeload.github.com',
		'objects.githubusercontent.com',
		'raw.githubusercontent.com',
		'release-assets.githubusercontent.com',
		'drive.google.com',
		'drive.usercontent.google.com',
		'docs.google.com',
		'googleusercontent.com',
	);
	return apply_filters( 'ans_ops_allowed_hosts', array_values( array_filter( $defaults ) ) );
}

function ans_ops_host_allowed( $url ) {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( '' === $host ) { return false; }
	foreach ( ans_ops_allowed_hosts() as $allowed ) {
		$allowed = strtolower( $allowed );
		if ( $host === $allowed || ( '' !== $allowed && substr( $host, - ( strlen( $allowed ) + 1 ) ) === '.' . $allowed ) ) {
			return true;
		}
	}
	return false;
}

/** Load the WordPress upgrader machinery + a quiet skin. */
function ans_ops_load_upgrader() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'ans_ops_fs', 'Could not initialize the WordPress filesystem (WP_Filesystem returned false).' );
	}
	return true;
}

/** Read a plugin's version from its main file, given the plugin basename (folder/file.php). */
function ans_ops_plugin_version( $basename ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$all = get_plugins();
	if ( isset( $all[ $basename ] ) ) {
		return array(
			'name'    => $all[ $basename ]['Name'],
			'version' => $all[ $basename ]['Version'],
			'active'  => is_plugin_active( $basename ),
		);
	}
	return null;
}

/** Core installer: run Plugin_Upgrader::install on a local path or URL. */
function ans_ops_run_install( $package, $overwrite ) {
	$loaded = ans_ops_load_upgrader();
	if ( is_wp_error( $loaded ) ) { return $loaded; }

	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );

	$result = $upgrader->install( $package, array( 'overwrite_package' => (bool) $overwrite ) );

	$messages = method_exists( $skin, 'get_upgrade_messages' ) ? $skin->get_upgrade_messages() : array();

	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'ans_ops_install_failed', $result->get_error_message(), array( 'messages' => $messages ) );
	}
	if ( false === $result || null === $result ) {
		return new WP_Error( 'ans_ops_install_failed', 'Installer returned no result.', array( 'messages' => $messages ) );
	}

	$plugin_file = $upgrader->plugin_info(); // folder/file.php of the installed plugin
	return array(
		'plugin'   => $plugin_file,
		'messages' => $messages,
	);
}

/* ---------------------------------------------------------------------------
 * Google Drive as an install source
 *
 * WHY THIS EXISTS, so nobody re-litigates it: you cannot install a plugin from
 * a Drive share link. Drive's download endpoint serves browser clients and
 * server-side clients differently — a link that downloads perfectly in an
 * incognito window still hands WordPress something that is not a zip. Verified
 * against a real public link on 2026-08-15, after the same link was proven to
 * work in a browser. The failure surfaces as an unpack error, which names the
 * wrong cause and sends the next person hunting a sharing problem that isn't
 * there.
 *
 * So we don't use a link at all. ars-nova-google-connector already holds a
 * service account with Drive scope, so we ask the Drive API for the bytes
 * directly. The consequence that matters: the Drive folder stays PRIVATE and is
 * shared with the service account, never with "anyone with the link". These are
 * paid third-party plugin zips and must not be world-readable.
 * ------------------------------------------------------------------------ */

/**
 * Download a zip from Google Drive as the site's own service account.
 *
 * @param  string $file_id Drive file ID (the long token in the file's URL).
 * @return string|WP_Error Absolute path to a temp file, or WP_Error.
 */
function ans_ops_fetch_drive_zip( $file_id ) {
	if ( ! function_exists( 'ansg_get_access_token' ) ) {
		return new WP_Error(
			'ans_ops_no_ansg',
			'Ars Nova Google Connector is not active, so Drive downloads are unavailable. Activate ars-nova-google-connector, or use url / zip_b64 instead.',
			array( 'status' => 409 )
		);
	}

	$token = ansg_get_access_token( 'https://www.googleapis.com/auth/drive.readonly' );
	if ( is_wp_error( $token ) ) { return $token; }

	// supportsAllDrives is required: our installers folder lives in a Shared Drive.
	$url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) . '?alt=media&supportsAllDrives=true';

	$resp = wp_remote_get( $url, array(
		'timeout' => 60,
		'headers' => array( 'Authorization' => 'Bearer ' . $token ),
	) );
	if ( is_wp_error( $resp ) ) { return $resp; }

	$code = (int) wp_remote_retrieve_response_code( $resp );
	$body = wp_remote_retrieve_body( $resp );

	if ( 200 !== $code ) {
		// Drive answers with JSON on error even when alt=media was requested.
		$err  = json_decode( $body, true );
		$msg  = ( is_array( $err ) && isset( $err['error']['message'] ) ) ? $err['error']['message'] : ( 'HTTP ' . $code );
		$hint = ( 403 === $code || 404 === $code )
			? ' Share the file, or the folder holding it, with the service account as Viewer.'
			: '';
		return new WP_Error( 'ans_ops_drive_fetch', 'Google Drive refused the download — ' . $msg . $hint, array( 'status' => 502 ) );
	}

	// Check the magic bytes BEFORE handing anything to the unzipper. A sign-in
	// page or an error document reaching Plugin_Upgrader produces "Incompatible
	// Archive", which reads like a corrupt download and hides the real cause.
	if ( strlen( $body ) < 4 || "PK\x03\x04" !== substr( $body, 0, 4 ) ) {
		return new WP_Error(
			'ans_ops_drive_not_zip',
			'Drive returned ' . strlen( $body ) . ' bytes that are not a zip archive (no PK header). Check that this file ID points at a .zip.',
			array( 'status' => 422 )
		);
	}

	$tmp = wp_tempnam( 'ans-ops-drive.zip' );
	if ( ! $tmp ) {
		return new WP_Error( 'ans_ops_tmp', 'Could not create a temp file for the Drive download.', array( 'status' => 500 ) );
	}
	if ( false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp );
		return new WP_Error( 'ans_ops_write', 'Could not write the Drive download to disk.', array( 'status' => 500 ) );
	}
	return $tmp;
}

/* ---------------------------------------------------------------------------
 * REST routes
 * ------------------------------------------------------------------------ */

add_action( 'rest_api_init', function () {

	// Status / sanity.
	register_rest_route( ANS_OPS_NS, '/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'ans_ops_can_install',
		'callback'            => function () {
			return array(
				'ok'              => true,
				'plugin'          => 'ars-nova-ops',
				'version'         => ANS_OPS_VERSION,
				'site'            => home_url(),
				'is_production'   => ans_ops_is_production(),
				'file_mods'       => ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
				'can_install'     => current_user_can( 'install_plugins' ),
				'allowed_hosts'   => ans_ops_allowed_hosts(),
				// Whether drive_file_id installs are usable right now.
				'drive_ready'     => function_exists( 'ansg_get_access_token' ),
			);
		},
	) );

	// Install (or update, if overwrite=true). Source: slug | url | zip_b64 | drive_file_id.
	register_rest_route( ANS_OPS_NS, '/plugin/install', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_ops_can_install',
		'callback'            => 'ans_ops_route_install',
	) );

	// Update = install with overwrite forced true.
	register_rest_route( ANS_OPS_NS, '/plugin/update', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_ops_can_install',
		'callback'            => function ( WP_REST_Request $r ) {
			$r->set_param( 'overwrite', true );
			return ans_ops_route_install( $r );
		},
	) );

	// Activate / deactivate.
	register_rest_route( ANS_OPS_NS, '/plugin/status', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_ops_can_activate',
		'callback'            => 'ans_ops_route_status',
	) );

	// Delete.
	register_rest_route( ANS_OPS_NS, '/plugin/delete', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_ops_can_delete',
		'callback'            => 'ans_ops_route_delete',
	) );

	// Read/write the handful of core site options WordPress core REST won't expose.
	register_rest_route( ANS_OPS_NS, '/site/options', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'ans_ops_can_manage_options',
			'callback'            => 'ans_ops_route_get_options',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'ans_ops_can_manage_options',
			'callback'            => 'ans_ops_route_set_options',
		),
	) );

	// Delete an orphan directory left behind by a failed install.
	register_rest_route( ANS_OPS_NS, '/plugin/delete-dir', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_ops_can_delete',
		'callback'            => 'ans_ops_route_delete_dir',
	) );

	// List (convenience).
	register_rest_route( ANS_OPS_NS, '/plugin/list', array(
		'methods'             => 'GET',
		'permission_callback' => 'ans_ops_can_install',
		'callback'            => function () {
			if ( ! function_exists( 'get_plugins' ) ) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
			// Never serve a cached scan — a delete in a prior request must show here.
			wp_cache_delete( 'plugins', 'plugins' );
			$out = array();
			foreach ( get_plugins() as $basename => $data ) {
				$out[] = array(
					'plugin'  => $basename,
					'name'    => $data['Name'],
					'version' => $data['Version'],
					'active'  => is_plugin_active( $basename ),
				);
			}
			return array( 'count' => count( $out ), 'items' => $out );
		},
	) );
} );

/* ---------------------------------------------------------------------------
 * Route callbacks
 * ------------------------------------------------------------------------ */

function ans_ops_route_install( WP_REST_Request $req ) {
	$slug        = trim( (string) $req->get_param( 'slug' ) );
	$url         = trim( (string) $req->get_param( 'url' ) );
	$zip_b64     = (string) $req->get_param( 'zip_b64' );
	$drive_id    = trim( (string) $req->get_param( 'drive_file_id' ) );
	$activate    = filter_var( $req->get_param( 'activate' ), FILTER_VALIDATE_BOOLEAN );
	$overwrite   = filter_var( $req->get_param( 'overwrite' ), FILTER_VALIDATE_BOOLEAN );
	$confirm_prod = filter_var( $req->get_param( 'confirm_production' ), FILTER_VALIDATE_BOOLEAN );

	// Production guard.
	if ( ans_ops_is_production() && ! $confirm_prod ) {
		return new WP_Error( 'ans_ops_production_blocked', 'This is the production site. Resend with confirm_production=true to proceed.', array( 'status' => 403 ) );
	}

	// File mods guard.
	if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
		return new WP_Error( 'ans_ops_file_mods_disabled', 'DISALLOW_FILE_MODS is enabled on this site; plugin installs are blocked.', array( 'status' => 409 ) );
	}

	$sources = array_filter( array(
		'slug'          => $slug,
		'url'           => $url,
		'zip_b64'       => ( '' !== $zip_b64 ) ? '1' : '',
		'drive_file_id' => $drive_id,
	) );
	if ( 1 !== count( $sources ) ) {
		return new WP_Error( 'ans_ops_bad_source', 'Provide exactly one of: slug, url, zip_b64, drive_file_id.', array( 'status' => 400 ) );
	}

	$loaded = ans_ops_load_upgrader();
	if ( is_wp_error( $loaded ) ) { return $loaded; }

	$package  = '';
	$tmp_file = '';

	if ( '' !== $slug ) {
		$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'ans_ops_slug_lookup', 'Could not find "' . $slug . '" in the WordPress.org directory: ' . $api->get_error_message(), array( 'status' => 404 ) );
		}
		$package = $api->download_link;
	} elseif ( '' !== $url ) {
		if ( ! ans_ops_host_allowed( $url ) ) {
			return new WP_Error( 'ans_ops_host_blocked', 'Zip URL host is not on the allow-list. Allowed: ' . implode( ', ', ans_ops_allowed_hosts() ), array( 'status' => 400 ) );
		}
		$package = $url;
	} elseif ( '' !== $drive_id ) {
		// Drive file -> authenticated download -> temp file. See the block comment
		// above ans_ops_fetch_drive_zip() for why a share link cannot be used here.
		$tmp_file = ans_ops_fetch_drive_zip( $drive_id );
		if ( is_wp_error( $tmp_file ) ) { return $tmp_file; }
		$package = $tmp_file;
	} else {
		// base64 zip -> temp file
		$bytes = base64_decode( $zip_b64, true );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'ans_ops_bad_b64', 'zip_b64 was not valid base64.', array( 'status' => 400 ) );
		}
		$tmp_file = wp_tempnam( 'ans-ops-upload.zip' );
		if ( ! $tmp_file ) {
			return new WP_Error( 'ans_ops_tmp', 'Could not create a temp file for the upload.', array( 'status' => 500 ) );
		}
		if ( false === file_put_contents( $tmp_file, $bytes ) ) { // phpcs:ignore
			@unlink( $tmp_file );
			return new WP_Error( 'ans_ops_write', 'Could not write the uploaded zip to disk.', array( 'status' => 500 ) );
		}
		$package = $tmp_file;
	}

	$res = ans_ops_run_install( $package, ( '' !== $slug ) ? false : $overwrite );

	if ( $tmp_file && file_exists( $tmp_file ) ) { @unlink( $tmp_file ); }

	if ( is_wp_error( $res ) ) {
		$data = $res->get_error_data();
		return new WP_REST_Response( array(
			'ok'       => false,
			'error'    => $res->get_error_message(),
			'messages' => is_array( $data ) && isset( $data['messages'] ) ? $data['messages'] : array(),
		), 500 );
	}

	$plugin_file = $res['plugin'];
	$activated   = false;
	$activate_error = '';

	if ( $activate && $plugin_file ) {
		$act = activate_plugin( $plugin_file );
		if ( is_wp_error( $act ) ) {
			$activate_error = $act->get_error_message();
		} else {
			$activated = true;
		}
	}

	$info = $plugin_file ? ans_ops_plugin_version( $plugin_file ) : null;

	return array(
		'ok'            => true,
		'plugin'        => $plugin_file,
		'name'          => isset( $info['name'] ) ? $info['name'] : null,
		'version'       => isset( $info['version'] ) ? $info['version'] : null,
		'active'        => isset( $info['active'] ) ? $info['active'] : $activated,
		'activated_now' => $activated,
		'activate_error'=> $activate_error,
		'messages'      => $res['messages'],
	);
}

function ans_ops_route_status( WP_REST_Request $req ) {
	$plugin = trim( (string) $req->get_param( 'plugin' ) );
	$active = filter_var( $req->get_param( 'active' ), FILTER_VALIDATE_BOOLEAN );
	if ( '' === $plugin ) {
		return new WP_Error( 'ans_ops_no_plugin', 'Provide the plugin basename (folder/file.php).', array( 'status' => 400 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( ! ans_ops_plugin_version( $plugin ) ) {
		return new WP_Error( 'ans_ops_not_found', 'Plugin "' . $plugin . '" is not installed.', array( 'status' => 404 ) );
	}
	if ( $active ) {
		$r = activate_plugin( $plugin );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'ans_ops_activate', $r->get_error_message(), array( 'status' => 500 ) );
		}
	} else {
		deactivate_plugins( array( $plugin ) );
	}
	return array( 'ok' => true, 'plugin' => $plugin, 'active' => is_plugin_active( $plugin ) );
}

/* ---------------------------------------------------------------------------
 * Core site options that WordPress core REST does not expose
 *
 * /wp/v2/settings deliberately omits timezone_string, gmt_offset and blog_public,
 * so a misconfigured timezone can only be fixed by hand in wp-admin. On a site
 * selling timed tickets that is a real gap: a site left on a manual UTC offset
 * does not follow daylight saving, so event times drift by an hour twice a year.
 *
 * This is a STRICT ALLOW-LIST. Anything not named here is rejected — this route
 * must never become a general-purpose option writer.
 * ------------------------------------------------------------------------ */
function ans_ops_can_manage_options() {
	return current_user_can( 'manage_options' );
}

function ans_ops_allowed_options() {
	return array( 'timezone_string', 'gmt_offset', 'date_format', 'time_format', 'start_of_week', 'blog_public' );
}

function ans_ops_read_options() {
	$out = array();
	foreach ( ans_ops_allowed_options() as $key ) {
		$out[ $key ] = get_option( $key );
	}
	$out['_now_site'] = current_time( 'Y-m-d H:i:s' );
	$out['_now_utc']  = gmdate( 'Y-m-d H:i:s' );
	$out['_wp_tz']    = wp_timezone_string();
	return $out;
}

function ans_ops_route_get_options() {
	return rest_ensure_response( ans_ops_read_options() );
}

function ans_ops_route_set_options( WP_REST_Request $req ) {
	$params  = (array) $req->get_json_params();
	$allowed = ans_ops_allowed_options();
	$changed = array();

	foreach ( $params as $key => $value ) {
		if ( ! in_array( $key, $allowed, true ) ) {
			return new WP_Error(
				'ans_ops_option_not_allowed',
				'"' . sanitize_text_field( (string) $key ) . '" is not on the allow-list. Allowed: ' . implode( ', ', $allowed ),
				array( 'status' => 400 )
			);
		}

		switch ( $key ) {
			case 'timezone_string':
				$tz = trim( (string) $value );
				if ( '' !== $tz && ! in_array( $tz, timezone_identifiers_list(), true ) ) {
					return new WP_Error( 'ans_ops_bad_timezone', '"' . esc_html( $tz ) . '" is not a valid PHP timezone identifier.', array( 'status' => 400 ) );
				}
				update_option( 'timezone_string', $tz );
				// WordPress treats these as mutually exclusive: a named zone means
				// the manual offset must be cleared, or wp_timezone() disagrees
				// with what the Settings screen shows.
				if ( '' !== $tz ) { update_option( 'gmt_offset', '' ); }
				$changed[] = $key;
				break;

			case 'gmt_offset':
				$off = (float) $value;
				if ( $off < -12 || $off > 14 ) {
					return new WP_Error( 'ans_ops_bad_offset', 'gmt_offset must be between -12 and 14.', array( 'status' => 400 ) );
				}
				update_option( 'gmt_offset', $off );
				update_option( 'timezone_string', '' );
				$changed[] = $key;
				break;

			case 'start_of_week':
				update_option( 'start_of_week', max( 0, min( 6, (int) $value ) ) );
				$changed[] = $key;
				break;

			case 'blog_public':
				update_option( 'blog_public', $value ? 1 : 0 );
				$changed[] = $key;
				break;

			default: // date_format, time_format
				update_option( $key, sanitize_text_field( (string) $value ) );
				$changed[] = $key;
				break;
		}
	}

	if ( ! $changed ) {
		return new WP_Error( 'ans_ops_no_options', 'No recognised options were supplied.', array( 'status' => 400 ) );
	}

	return rest_ensure_response( array(
		'ok'      => true,
		'changed' => $changed,
		'options' => ans_ops_read_options(),
	) );
}

/**
 * Resolve a path under wp-content/plugins and refuse anything that escapes it.
 *
 * Returns an absolute, normalised path, or WP_Error. Guards against traversal
 * ("../"), absolute paths, and the plugins directory itself — deleting
 * WP_PLUGIN_DIR would take the whole site's plugins with it.
 */
function ans_ops_safe_plugin_path( $relative ) {
	$relative = str_replace( '\\', '/', trim( (string) $relative ) );
	$relative = ltrim( $relative, '/' );
	if ( '' === $relative || false !== strpos( $relative, '../' ) || false !== strpos( $relative, './' ) ) {
		return new WP_Error( 'ans_ops_bad_path', 'Refusing a path that is empty or contains relative segments.', array( 'status' => 400 ) );
	}
	$root = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );
	$abs  = wp_normalize_path( $root . $relative );
	if ( 0 !== strpos( $abs, $root ) || untrailingslashit( $abs ) === untrailingslashit( $root ) ) {
		return new WP_Error( 'ans_ops_bad_path', 'Refusing a path outside wp-content/plugins.', array( 'status' => 400 ) );
	}
	return $abs;
}

/** Recursively remove a directory under wp-content/plugins. Returns bool. */
function ans_ops_rmdir( $abs_dir ) {
	global $wp_filesystem;
	if ( $wp_filesystem && $wp_filesystem->is_dir( $abs_dir ) ) {
		if ( $wp_filesystem->delete( trailingslashit( $abs_dir ), true ) ) {
			return true;
		}
	}
	// Fallback: plain PHP recursion, for hosts where WP_Filesystem's delete is unreliable.
	if ( ! is_dir( $abs_dir ) ) { return ! file_exists( $abs_dir ); }
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $abs_dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() ) { @rmdir( $item->getPathname() ); } else { @unlink( $item->getPathname() ); }
	}
	@rmdir( $abs_dir );
	clearstatcache( true, $abs_dir );
	return ! file_exists( $abs_dir );
}

function ans_ops_route_delete( WP_REST_Request $req ) {
	$plugin = trim( (string) $req->get_param( 'plugin' ) );
	if ( '' === $plugin ) {
		return new WP_Error( 'ans_ops_no_plugin', 'Provide the plugin basename (folder/file.php).', array( 'status' => 400 ) );
	}
	if ( ans_ops_is_production() && ! filter_var( $req->get_param( 'confirm_production' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return new WP_Error( 'ans_ops_production_blocked', 'Production site: resend with confirm_production=true to delete.', array( 'status' => 403 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( ! ans_ops_plugin_version( $plugin ) ) {
		return new WP_Error( 'ans_ops_not_found', 'Plugin "' . $plugin . '" is not installed.', array( 'status' => 404 ) );
	}
	if ( is_plugin_active( $plugin ) ) {
		deactivate_plugins( array( $plugin ) );
	}
	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'ans_ops_fs', 'Could not initialize filesystem for delete.', array( 'status' => 500 ) );
	}

	// Work out what SHOULD disappear, so we can verify rather than assume.
	$target = ( false !== strpos( $plugin, '/' ) ) ? dirname( $plugin ) : $plugin;
	$abs    = ans_ops_safe_plugin_path( $target );
	if ( is_wp_error( $abs ) ) { return $abs; }

	$res = delete_plugins( array( $plugin ) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'ans_ops_delete', $res->get_error_message(), array( 'status' => 500 ) );
	}
	// NOTE: delete_plugins() returns TRUE on success, FALSE on an empty list and
	// NULL when it wants filesystem credentials. Only WP_Error was checked before
	// 1.1.0, so a NULL/FALSE no-op was reported to the caller as a success.
	$method = 'delete_plugins';
	clearstatcache( true, $abs );
	if ( file_exists( $abs ) ) {
		$method = 'recursive_rmdir';
		ans_ops_rmdir( $abs );
		clearstatcache( true, $abs );
	}
	$gone = ! file_exists( $abs );

	// Drop the cached plugin scan so plugin/list reflects reality immediately.
	wp_cache_delete( 'plugins', 'plugins' );

	if ( ! $gone ) {
		return new WP_Error(
			'ans_ops_delete_unverified',
			'Delete reported no error but "' . $target . '" is still on disk. Check file permissions.',
			array( 'status' => 500, 'path' => $abs )
		);
	}
	return array(
		'ok'       => true,
		'deleted'  => $plugin,
		'removed'  => $target,
		'verified' => true,
		'method'   => $method,
	);
}

/**
 * Delete an orphan directory under wp-content/plugins by folder name.
 *
 * For leftovers from a failed install that no longer present a valid plugin
 * basename, so plugin/delete cannot address them.
 */
function ans_ops_route_delete_dir( WP_REST_Request $req ) {
	$dir = trim( (string) $req->get_param( 'dir' ) );
	if ( '' === $dir ) {
		return new WP_Error( 'ans_ops_no_dir', 'Provide the folder name relative to wp-content/plugins.', array( 'status' => 400 ) );
	}
	if ( ans_ops_is_production() && ! filter_var( $req->get_param( 'confirm_production' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return new WP_Error( 'ans_ops_production_blocked', 'Production site: resend with confirm_production=true to delete.', array( 'status' => 403 ) );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$abs = ans_ops_safe_plugin_path( $dir );
	if ( is_wp_error( $abs ) ) { return $abs; }
	if ( ! file_exists( $abs ) ) {
		return new WP_Error( 'ans_ops_not_found', 'No such folder: ' . $dir, array( 'status' => 404 ) );
	}

	// Never remove a directory that holds an ACTIVE plugin.
	foreach ( (array) get_option( 'active_plugins', array() ) as $active ) {
		$active_root = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . dirname( $active ) );
		if ( 0 === strpos( trailingslashit( $active_root ), trailingslashit( $abs ) ) ) {
			return new WP_Error( 'ans_ops_active', 'Refusing: "' . $dir . '" contains the active plugin "' . $active . '". Deactivate it first.', array( 'status' => 409 ) );
		}
	}

	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'ans_ops_fs', 'Could not initialize filesystem for delete.', array( 'status' => 500 ) );
	}
	ans_ops_rmdir( $abs );
	clearstatcache( true, $abs );
	wp_cache_delete( 'plugins', 'plugins' );

	if ( file_exists( $abs ) ) {
		return new WP_Error( 'ans_ops_delete_unverified', 'Could not remove "' . $dir . '". Check file permissions.', array( 'status' => 500, 'path' => $abs ) );
	}
	return array( 'ok' => true, 'removed' => $dir, 'verified' => true );
}
