<?php
/**
 * Raw content search and find-and-replace (added 1.4.0, INFRA-29).
 *
 * WHY THIS EXISTS: the WordPress connector could only change a page or post by
 * sending back its WHOLE content. Deleting one line meant retyping everything,
 * including things like the large inline SVG on the Darkness & Light page, and
 * every retype is a chance to damage something nobody meant to touch. These two
 * routes let a client change exactly the text it names and nothing else.
 *
 *   POST ans-ops/v1/content/search   { q, post_types?, statuses?, case_sensitive?, limit? }
 *   POST ans-ops/v1/content/replace  { id, edits:[{find, replace, expect_count?}],
 *                                      fields?, regex?, dry_run?, confirm_production?,
 *                                      expect_modified_gmt?, ignore_lock? }
 *
 * Both work on RAW stored content (Gutenberg markup, block attributes and all).
 * Core search only looks at text and misses block attributes and HTML.
 *
 * Safety rules for /content/replace, in the order they are checked:
 *   1. dry_run defaults to TRUE. A dry run returns counts and a diff and saves nothing.
 *   2. Every edit must match exactly expect_count times (default 1), or nothing
 *      is saved and the counts are returned. A vague "find" cannot quietly
 *      rewrite the wrong place.
 *   3. Regex only when regex=true, with a lowered backtrack limit so a bad
 *      pattern fails fast instead of hanging PHP.
 *   4. A real save on production needs confirm_production=true.
 *   5. The new content's block comments must balance (<!-- wp:x --> ... <!-- /wp:x -->).
 *      If the old content balanced and the new does not, the save is refused.
 *   6. The save goes through wp_update_post(), so WordPress keeps a revision to undo to.
 *   7. The user must have unfiltered_html. Without it WordPress runs kses on
 *      save and silently strips SVG, scripts and some attributes from parts of
 *      the page the edit never touched. Then the stored content is read back
 *      and compared with what was intended.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Permission: reading drafts/private content and editing others' content needs editor-level rights. */
function ans_ops_can_edit_content() {
	return current_user_can( 'edit_others_posts' ) && current_user_can( 'edit_others_pages' );
}

add_action( 'rest_api_init', function () {
	register_rest_route( ANS_OPS_NS, '/content/search', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_ops_can_edit_content',
		'callback'            => 'ans_ops_route_content_search',
	) );
	register_rest_route( ANS_OPS_NS, '/content/replace', array(
		'methods'             => 'POST',
		'permission_callback' => 'ans_ops_can_edit_content',
		'callback'            => 'ans_ops_route_content_replace',
	) );
} );

/* ---------------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------------ */

/** Accept an array or a comma-separated string; return a clean list of strings. */
function ans_ops_list_param( $value ) {
	if ( is_string( $value ) ) { $value = explode( ',', $value ); }
	if ( ! is_array( $value ) ) { return array(); }
	return array_values( array_filter( array_map( 'sanitize_key', array_map( 'trim', $value ) ) ) );
}

/** Byte offsets of every occurrence of $needle in $hay. */
function ans_ops_find_offsets( $hay, $needle, $case_sensitive = true, $max = 1000 ) {
	$out = array();
	if ( '' === $needle ) { return $out; }
	$pos = 0;
	while ( count( $out ) < $max ) {
		$found = $case_sensitive ? strpos( $hay, $needle, $pos ) : stripos( $hay, $needle, $pos );
		if ( false === $found ) { break; }
		$out[] = $found;
		$pos   = $found + strlen( $needle );
	}
	return $out;
}

/**
 * Byte-range substring that never splits a UTF-8 character: the start moves
 * forward and the end moves back off continuation bytes. mbstring is not
 * guaranteed on every host and WordPress does not polyfill mb_strcut().
 */
function ans_ops_utf8_cut( $s, $start, $len ) {
	$n     = strlen( $s );
	$start = max( 0, min( $n, (int) $start ) );
	$end   = max( $start, min( $n, $start + (int) $len ) );
	while ( $start < $end && ( ord( $s[ $start ] ) & 0xC0 ) === 0x80 ) { $start++; }
	while ( $end > $start && $end < $n && ( ord( $s[ $end ] ) & 0xC0 ) === 0x80 ) { $end--; }
	return substr( $s, $start, $end - $start );
}

/** About $radius characters either side of a match, cut on UTF-8 boundaries. */
function ans_ops_context( $hay, $offset, $len, $radius = 80 ) {
	$start  = max( 0, $offset - $radius );
	$end    = min( strlen( $hay ), $offset + $len + $radius );
	$before = ans_ops_utf8_cut( $hay, $start, $offset - $start );
	$match  = substr( $hay, $offset, $len );
	$after  = ans_ops_utf8_cut( $hay, $offset + $len, $end - ( $offset + $len ) );
	return ( $start > 0 ? '…' : '' ) . $before . '[[' . $match . ']]' . $after . ( $end < strlen( $hay ) ? '…' : '' );
}

/**
 * Check that Gutenberg block comments balance. Uses the same tokenizer regex
 * as core's WP_Block_Parser, then matches openers to closers on a stack.
 *
 * @return array List of problems; empty means balanced.
 */
function ans_ops_block_problems( $content ) {
	$re = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
	if ( false === preg_match_all( $re, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return array( 'block tokenizer failed (pcre error ' . preg_last_error() . ')' );
	}
	$stack    = array();
	$problems = array();
	foreach ( $m as $tok ) {
		$name = ( isset( $tok['namespace'][0] ) && '' !== $tok['namespace'][0] ? $tok['namespace'][0] : 'core/' ) . $tok['name'][0];
		$at   = $tok[0][1];
		if ( isset( $tok['void'][0] ) && '/' === $tok['void'][0] ) { continue; }
		if ( isset( $tok['closer'][0] ) && '/' === $tok['closer'][0] ) {
			if ( empty( $stack ) ) {
				$problems[] = "closer /wp:$name at byte $at has no opener";
				continue;
			}
			$open = array_pop( $stack );
			if ( $open[0] !== $name ) {
				$problems[] = "closer /wp:$name at byte $at does not match opener wp:{$open[0]} at byte {$open[1]}";
			}
			continue;
		}
		$stack[] = array( $name, $at );
	}
	foreach ( $stack as $open ) {
		$problems[] = "opener wp:{$open[0]} at byte {$open[1]} is never closed";
	}
	return $problems;
}

/** Shorten a very long line for display, keeping both ends. */
function ans_ops_clip_line( $line, $max = 400 ) {
	if ( strlen( $line ) <= $max ) { return $line; }
	$half = (int) floor( $max / 2 );
	return ans_ops_utf8_cut( $line, 0, $half ) . ' …[' . ( strlen( $line ) - $max ) . ' bytes omitted]… ' . ans_ops_utf8_cut( $line, strlen( $line ) - $half, $half );
}

/**
 * Unified-style line diff with $ctx lines of context, built on the Text_Diff
 * engine WordPress already ships for revisions.
 */
function ans_ops_unified_diff( $old, $new, $ctx = 2 ) {
	if ( $old === $new ) { return ''; }
	if ( ! class_exists( 'Text_Diff', false ) ) { require_once ABSPATH . WPINC . '/wp-diff.php'; }
	$a     = explode( "\n", str_replace( "\r\n", "\n", $old ) );
	$b     = explode( "\n", str_replace( "\r\n", "\n", $new ) );
	$diff  = new Text_Diff( 'auto', array( $a, $b ) );
	$ops   = $diff->getDiff();
	$out   = array();
	$la    = 1; // current line number in old
	$lb    = 1; // current line number in new
	$n     = count( $ops );
	foreach ( $ops as $i => $op ) {
		$orig  = is_array( $op->orig ) ? $op->orig : array();
		$final = is_array( $op->final ) ? $op->final : array();
		if ( $op instanceof Text_Diff_Op_copy ) {
			$len  = count( $orig );
			$head = ( $i > 0 ) ? array_slice( $orig, 0, $ctx ) : array();
			$tail = ( $i < $n - 1 ) ? array_slice( $orig, max( count( $head ), $len - $ctx ) ) : array();
			foreach ( $head as $l ) { $out[] = '  ' . ans_ops_clip_line( $l ); }
			if ( $len > count( $head ) + count( $tail ) ) {
				$skip_to_a = $la + $len - count( $tail );
				$skip_to_b = $lb + $len - count( $tail );
				if ( $i < $n - 1 ) { $out[] = "@@ old line $skip_to_a / new line $skip_to_b @@"; }
			}
			foreach ( $tail as $l ) { $out[] = '  ' . ans_ops_clip_line( $l ); }
			$la += $len;
			$lb += $len;
			continue;
		}
		foreach ( $orig as $l ) { $out[] = '- ' . ans_ops_clip_line( $l ); }
		foreach ( $final as $l ) { $out[] = '+ ' . ans_ops_clip_line( $l ); }
		$la += count( $orig );
		$lb += count( $final );
	}
	if ( ! empty( $ops ) && ! ( $ops[0] instanceof Text_Diff_Op_copy ) ) {
		array_unshift( $out, '@@ old line 1 / new line 1 @@' );
	}
	return implode( "\n", $out );
}

/* ---------------------------------------------------------------------------
 * POST content/search
 * ------------------------------------------------------------------------ */

function ans_ops_route_content_search( WP_REST_Request $req ) {
	global $wpdb;

	$q = (string) $req->get_param( 'q' );
	if ( '' === trim( $q ) ) {
		return new WP_Error( 'ans_ops_bad_query', 'q is required.', array( 'status' => 400 ) );
	}
	$case_sensitive = filter_var( $req->get_param( 'case_sensitive' ), FILTER_VALIDATE_BOOLEAN );

	$types = ans_ops_list_param( $req->get_param( 'post_types' ) );
	if ( empty( $types ) ) {
		$types   = array_values( get_post_types( array( 'public' => true ) ) );
		$types[] = 'wp_block';          // synced patterns
		$types[] = 'wp_template_part';  // header/footer parts
		$types   = array_values( array_diff( $types, array( 'attachment' ) ) );
	}
	$statuses = ans_ops_list_param( $req->get_param( 'statuses' ) );
	if ( empty( $statuses ) ) {
		$statuses = array( 'publish', 'future', 'draft', 'pending', 'private' );
	}
	$limit = (int) $req->get_param( 'limit' );
	$limit = ( $limit > 0 ) ? min( $limit, 500 ) : 200;

	$like   = '%' . $wpdb->esc_like( $q ) . '%';
	$tph    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$sph    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
	$sql    = "SELECT ID, post_type, post_status, post_title, post_content, post_excerpt
	           FROM {$wpdb->posts}
	           WHERE post_type IN ($tph) AND post_status IN ($sph)
	             AND ( post_content LIKE %s OR post_excerpt LIKE %s OR post_title LIKE %s )
	           ORDER BY post_type, ID
	           LIMIT %d";
	$args   = array_merge( $types, $statuses, array( $like, $like, $like, $limit ) );
	$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL

	$items = array();
	foreach ( (array) $rows as $row ) {
		$fields  = array( 'title' => $row->post_title, 'content' => $row->post_content, 'excerpt' => $row->post_excerpt );
		$matches = array();
		$total   = 0;
		foreach ( $fields as $field => $text ) {
			$offsets = ans_ops_find_offsets( (string) $text, $q, $case_sensitive );
			$total  += count( $offsets );
			foreach ( array_slice( $offsets, 0, 10 ) as $off ) {
				$matches[] = array( 'field' => $field, 'offset' => $off, 'context' => ans_ops_context( (string) $text, $off, strlen( $q ) ) );
			}
		}
		// The SQL LIKE is collation-dependent; only report rows that really match.
		if ( 0 === $total ) { continue; }
		$items[] = array(
			'id'          => (int) $row->ID,
			'type'        => $row->post_type,
			'status'      => $row->post_status,
			'title'       => $row->post_title,
			'match_count' => $total,
			'matches'     => $matches,
		);
	}

	return array(
		'ok'             => true,
		'q'              => $q,
		'case_sensitive' => $case_sensitive,
		'post_types'     => $types,
		'statuses'       => $statuses,
		'count'          => count( $items ),
		'truncated'      => count( (array) $rows ) >= $limit,
		'items'          => $items,
	);
}

/* ---------------------------------------------------------------------------
 * POST content/replace
 * ------------------------------------------------------------------------ */

function ans_ops_route_content_replace( WP_REST_Request $req ) {
	$id   = (int) $req->get_param( 'id' );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || 'revision' === $post->post_type ) {
		return new WP_Error( 'ans_ops_no_post', 'No post or page with id ' . $id . '.', array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return new WP_Error( 'ans_ops_forbidden', 'You cannot edit post ' . $id . '.', array( 'status' => 403 ) );
	}

	$dry_run = $req->has_param( 'dry_run' ) ? filter_var( $req->get_param( 'dry_run' ), FILTER_VALIDATE_BOOLEAN ) : true;
	$regex   = filter_var( $req->get_param( 'regex' ), FILTER_VALIDATE_BOOLEAN );
	$fields  = ans_ops_list_param( $req->get_param( 'fields' ) );
	if ( empty( $fields ) ) { $fields = array( 'content' ); }
	$bad_fields = array_diff( $fields, array( 'content', 'excerpt' ) );
	if ( $bad_fields ) {
		return new WP_Error( 'ans_ops_bad_fields', 'fields may only contain "content" and "excerpt".', array( 'status' => 400 ) );
	}

	$edits = $req->get_param( 'edits' );
	if ( ! is_array( $edits ) || empty( $edits ) ) {
		return new WP_Error( 'ans_ops_no_edits', 'edits must be a non-empty array of { find, replace, expect_count? }.', array( 'status' => 400 ) );
	}
	if ( count( $edits ) > 50 ) {
		return new WP_Error( 'ans_ops_too_many_edits', 'At most 50 edits per call.', array( 'status' => 400 ) );
	}

	// Optimistic concurrency: refuse if the post changed since the caller looked.
	$expect_modified = (string) $req->get_param( 'expect_modified_gmt' );
	if ( '' !== $expect_modified && $expect_modified !== $post->post_modified_gmt ) {
		return new WP_Error( 'ans_ops_stale', 'Post ' . $id . ' was modified at ' . $post->post_modified_gmt . ' GMT, not ' . $expect_modified . '. Re-read it and try again.', array( 'status' => 409, 'post_modified_gmt' => $post->post_modified_gmt ) );
	}

	$field_map = array( 'content' => 'post_content', 'excerpt' => 'post_excerpt' );
	$old       = array();
	$new       = array();
	foreach ( $fields as $f ) {
		$old[ $f ] = (string) $post->{ $field_map[ $f ] };
		$new[ $f ] = $old[ $f ];
	}

	// Apply edits in order, each to the result of the previous one.
	$report   = array();
	$all_good = true;
	$prev_bt  = ini_get( 'pcre.backtrack_limit' );
	if ( $regex ) { ini_set( 'pcre.backtrack_limit', '200000' ); } // phpcs:ignore

	foreach ( array_values( $edits ) as $i => $e ) {
		$find    = isset( $e['find'] ) ? (string) $e['find'] : '';
		$replace = isset( $e['replace'] ) ? (string) $e['replace'] : '';
		$expect  = isset( $e['expect_count'] ) ? (int) $e['expect_count'] : 1;
		if ( '' === $find ) {
			if ( $regex ) { ini_set( 'pcre.backtrack_limit', $prev_bt ); } // phpcs:ignore
			return new WP_Error( 'ans_ops_empty_find', "Edit $i has an empty find.", array( 'status' => 400 ) );
		}
		if ( $regex && false === @preg_match( $find, '' ) ) { // phpcs:ignore
			ini_set( 'pcre.backtrack_limit', $prev_bt ); // phpcs:ignore
			return new WP_Error( 'ans_ops_bad_regex', "Edit $i: find is not a valid PCRE pattern (include delimiters, e.g. /x/u).", array( 'status' => 400 ) );
		}

		$count    = 0;
		$per      = array();
		$contexts = array();
		foreach ( $fields as $f ) {
			if ( $regex ) {
				$c = preg_match_all( $find, $new[ $f ], $mm, PREG_OFFSET_CAPTURE );
				if ( false === $c || PREG_NO_ERROR !== preg_last_error() ) {
					ini_set( 'pcre.backtrack_limit', $prev_bt ); // phpcs:ignore
					return new WP_Error( 'ans_ops_regex_failed', "Edit $i: regex failed (pcre error " . preg_last_error() . '), probably too much backtracking. Simplify the pattern.', array( 'status' => 400 ) );
				}
				foreach ( array_slice( $mm[0], 0, 5 ) as $hit ) {
					$contexts[] = array( 'field' => $f, 'context' => ans_ops_context( $new[ $f ], $hit[1], strlen( $hit[0] ) ) );
				}
			} else {
				$offs = ans_ops_find_offsets( $new[ $f ], $find, true );
				$c    = count( $offs );
				foreach ( array_slice( $offs, 0, 5 ) as $off ) {
					$contexts[] = array( 'field' => $f, 'context' => ans_ops_context( $new[ $f ], $off, strlen( $find ) ) );
				}
			}
			$per[ $f ] = $c;
			$count    += $c;
		}

		$ok = ( $count === $expect );
		if ( $ok ) {
			foreach ( $fields as $f ) {
				if ( 0 === $per[ $f ] ) { continue; }
				$new[ $f ] = $regex ? preg_replace( $find, $replace, $new[ $f ] ) : str_replace( $find, $replace, $new[ $f ] );
				if ( null === $new[ $f ] ) {
					ini_set( 'pcre.backtrack_limit', $prev_bt ); // phpcs:ignore
					return new WP_Error( 'ans_ops_regex_failed', "Edit $i: replacement failed (pcre error " . preg_last_error() . ').', array( 'status' => 400 ) );
				}
			}
		} else {
			$all_good = false;
		}
		$report[] = array(
			'index'        => $i,
			'expect_count' => $expect,
			'found'        => $count,
			'per_field'    => $per,
			'ok'           => $ok,
			'matches'      => $contexts,
		);
	}
	if ( $regex ) { ini_set( 'pcre.backtrack_limit', $prev_bt ); } // phpcs:ignore

	$base = array(
		'id'                => $id,
		'type'              => $post->post_type,
		'status'            => $post->post_status,
		'title'             => $post->post_title,
		'post_modified_gmt' => $post->post_modified_gmt,
		'is_production'     => ans_ops_is_production(),
		'edits'             => $report,
	);

	if ( ! $all_good ) {
		return new WP_REST_Response( array_merge( array(
			'ok'      => false,
			'saved'   => false,
			'code'    => 'ans_ops_count_mismatch',
			'message' => 'At least one edit did not match exactly expect_count times. Nothing was changed.',
		), $base ), 409 );
	}

	// Diffs and block balance on the result.
	$diffs  = array();
	$blocks = array();
	foreach ( $fields as $f ) {
		$diffs[ $f ] = ans_ops_unified_diff( $old[ $f ], $new[ $f ] );
	}
	$block_before = in_array( 'content', $fields, true ) ? ans_ops_block_problems( $old['content'] ) : array();
	$block_after  = in_array( 'content', $fields, true ) ? ans_ops_block_problems( $new['content'] ) : array();
	if ( in_array( 'content', $fields, true ) ) {
		$blocks = array(
			'top_level_before' => count( array_filter( parse_blocks( $old['content'] ), function ( $b ) { return ! empty( $b['blockName'] ); } ) ),
			'top_level_after'  => count( array_filter( parse_blocks( $new['content'] ), function ( $b ) { return ! empty( $b['blockName'] ); } ) ),
			'problems_before'  => $block_before,
			'problems_after'   => $block_after,
		);
	}
	$changed = false;
	foreach ( $fields as $f ) {
		if ( $old[ $f ] !== $new[ $f ] ) { $changed = true; }
	}
	$base['diff']        = $diffs;
	$base['blocks']      = $blocks;
	$base['bytes_before'] = array_map( 'strlen', $old );
	$base['bytes_after']  = array_map( 'strlen', $new );

	if ( empty( $block_before ) && ! empty( $block_after ) ) {
		return new WP_REST_Response( array_merge( array(
			'ok'      => false,
			'saved'   => false,
			'code'    => 'ans_ops_blocks_broken',
			'message' => 'The edits would leave unbalanced block comments. Nothing was changed.',
		), $base ), 409 );
	}

	if ( $dry_run ) {
		return array_merge( array(
			'ok'      => true,
			'saved'   => false,
			'dry_run' => true,
			'changed' => $changed,
			'message' => 'Dry run: nothing saved. Resend with dry_run=false to apply' . ( ans_ops_is_production() ? ' (and confirm_production=true, this is LIVE).' : '.' ),
		), $base );
	}

	if ( ! $changed ) {
		return array_merge( array( 'ok' => true, 'saved' => false, 'changed' => false, 'message' => 'Edits produce identical content; nothing to save.' ), $base );
	}

	if ( ans_ops_is_production() && ! filter_var( $req->get_param( 'confirm_production' ), FILTER_VALIDATE_BOOLEAN ) ) {
		return new WP_Error( 'ans_ops_production_blocked', 'This is the production site. Resend with confirm_production=true to save.', array( 'status' => 403 ) );
	}

	if ( ! current_user_can( 'unfiltered_html' ) ) {
		return new WP_Error( 'ans_ops_filtered_html', 'This user lacks unfiltered_html, so WordPress would run kses on save and strip SVG/scripts from untouched parts of the page. Refusing.', array( 'status' => 403 ) );
	}

	// Someone editing in wp-admin right now would overwrite this on their next save.
	if ( ! function_exists( 'wp_check_post_lock' ) ) { require_once ABSPATH . 'wp-admin/includes/post.php'; }
	$lock_user = wp_check_post_lock( $id );
	if ( $lock_user && ! filter_var( $req->get_param( 'ignore_lock' ), FILTER_VALIDATE_BOOLEAN ) ) {
		$u = get_userdata( $lock_user );
		return new WP_Error( 'ans_ops_post_locked', 'Post ' . $id . ' is open in the editor by ' . ( $u ? $u->display_name : 'user ' . $lock_user ) . '. Their next save would undo this. Resend with ignore_lock=true to save anyway.', array( 'status' => 409 ) );
	}

	// WordPress's own revision is taken AFTER the update, so it holds the new
	// text. Snapshot the current state first so there is always an undo point.
	$undo_rev = null;
	if ( wp_revisions_enabled( $post ) ) {
		$r = _wp_put_post_revision( $post );
		if ( $r && ! is_wp_error( $r ) ) { $undo_rev = (int) $r; }
	}

	$update = array( 'ID' => $id );
	foreach ( $fields as $f ) {
		$update[ $field_map[ $f ] ] = $new[ $f ];
	}
	// wp_update_post() unslashes its input, so slash it first or backslashes in
	// the content (JSON in block attributes, e.g. -) are silently eaten.
	$result = wp_update_post( wp_slash( $update ), true );
	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response( array_merge( array(
			'ok'      => false,
			'saved'   => false,
			'code'    => $result->get_error_code(),
			'message' => $result->get_error_message(),
		), $base ), 500 );
	}

	clean_post_cache( $id );
	$after    = get_post( $id );
	$verified = true;
	foreach ( $fields as $f ) {
		if ( (string) $after->{ $field_map[ $f ] } !== $new[ $f ] ) { $verified = false; }
	}
	return array_merge( $base, array(
		'ok'                => $verified,
		'saved'             => true,
		'dry_run'           => false,
		'changed'           => true,
		'verified'          => $verified,
		'message'           => $verified ? 'Saved and verified.' : 'Saved, but the stored content differs from what was intended (a save filter changed it). Restore revision ' . ( $undo_rev ? $undo_rev : '?' ) . ' if needed.',
		'undo_revision_id'  => $undo_rev,
		'post_modified_gmt' => $after->post_modified_gmt,
	) );
}
