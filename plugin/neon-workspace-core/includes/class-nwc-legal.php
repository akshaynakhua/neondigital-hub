<?php
/**
 * Terms & Conditions and Privacy Policy: editable drafts, revisions, approval, immutable published
 * snapshots, and the record of who accepted which version.
 *
 * Both documents start as drafts seeded from the supplied legal pack. Nothing goes public until an
 * authorised admin approves it, every placeholder is resolved and the business facts are confirmed.
 * Acceptance is only ever recorded for something a person actually did: there is no code path that
 * writes acceptance for an existing account.
 */

defined( 'ABSPATH' ) || exit;

class NWC_Legal {

	const CAP_EDIT    = 'nwc_edit_legal';
	const CAP_PUBLISH = 'nwc_publish_legal';
	const UNPUBLISHED = 'unpublished';

	const DOCS = array(
		'terms'   => array( 'title' => 'Terms & Conditions', 'slug' => 'terms-and-conditions', 'seed' => 'Terms-and-Conditions-Draft.md' ),
		'privacy' => array( 'title' => 'Privacy Policy', 'slug' => 'privacy-policy', 'seed' => 'Privacy-Policy-Draft.md' ),
	);

	const STATES = array( 'draft' => 'Draft', 'needs_review' => 'Needs Review', 'approved' => 'Approved', 'published' => 'Published' );

	/** What the owner confirms before the first publication. */
	const CONFIRMATIONS = array(
		'legal_entity'   => 'Full legal entity name',
		'address'        => 'Registered address',
		'contacts'       => 'Support, privacy and grievance email addresses',
		'grievance'      => 'Legal contact or grievance designation',
		'jurisdiction'   => 'Jurisdiction and dispute resolution',
		'providers'      => 'Actual payment, email and hosting providers',
		'data_sharing'   => 'The real fields shared through NeonDigital Connect',
		'retention'      => 'Retention and deletion behaviour as actually configured',
		'legal_review'   => 'Review by a qualified Indian lawyer',
	);

	public static function t( $n ) {
		return NWC_DB::t( $n );
	}

	/* ---------------------------- Setup ---------------------------- */

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_nwc_legal_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_nwc_legal_state', array( __CLASS__, 'handle_state' ) );
		add_action( 'admin_post_nwc_legal_publish', array( __CLASS__, 'handle_publish' ) );
		add_action( 'admin_post_nwc_legal_unpublish', array( __CLASS__, 'handle_unpublish' ) );
		add_action( 'admin_post_nwc_legal_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_post_nwc_legal_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'wp_ajax_nwc_legal_preview', array( __CLASS__, 'ajax_preview' ) );
	}

	public static function routes() {
		NWC_REST::route( '/me/legal', 'GET', array( __CLASS__, 'pending_route' ), 'user' );
		NWC_REST::route( '/me/legal/accept', 'POST', array( __CLASS__, 'accept_route' ), 'user' );
		NWC_REST::route( '/me/marketing', 'GET', array( __CLASS__, 'marketing_route' ), 'user' );
		NWC_REST::route( '/me/marketing', 'PUT', array( __CLASS__, 'marketing_save' ), 'user' );
	}

	/** Safe to repeat: creates only what is missing and never overwrites an edited document. */
	public static function install() {
		global $wpdb;
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_EDIT );
			$admin->add_cap( self::CAP_PUBLISH );
		}
		add_role( 'nwc_legal_editor', 'Legal Editor', array( 'read' => true, self::CAP_EDIT => true ) );
		$now = NWC::now();
		foreach ( self::DOCS as $key => $d ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SELECT doc_key FROM ' . self::t( 'legal_docs' ) . ' WHERE doc_key = %s', $key ) ) ) { // phpcs:ignore
				continue;
			}
			$md = (string) @file_get_contents( NWC_DIR . 'assets/legal/' . $d['seed'] ); // phpcs:ignore
			$ver = self::seed_version( $md );
			$wpdb->insert( self::t( 'legal_docs' ), array( 'doc_key' => $key, 'draft_md' => $md, 'state' => 'draft', 'version' => $ver, 'updated_by' => 0, 'updated_at' => $now ) );
			self::add_revision( $key, $md, $ver, 'draft', 'Imported from the supplied draft', 0 );
			self::sync_page( $key );
		}
	}

	/** The version a seed draft states in its header ("Policy Version: 1.2" / "Draft version: 1.0"), else 1.0. */
	public static function seed_version( $md ) {
		return preg_match( '/^\**(?:Policy|Draft) version:?\**:?\s*(\d+(?:\.\d+)*)/im', substr( (string) $md, 0, 2000 ), $m ) ? $m[1] : '1.0';
	}

	/**
	 * Phase 2 rewrote the Privacy Policy draft (Google Drive folders, data sharing with NeonDigital,
	 * notification choices). The new text replaces the stored draft only when nobody has edited it
	 * since it was imported, and only as a new draft revision: a published version is never touched.
	 */
	const PRIVACY_SEED_V1 = 'cf408c25d530a10cd453bf468982919c0adb5a876fb5c3900e308cf14758f812';
	/** Phase 2 draft 1.1, as shipped. Phase 3 (1.2) replaces it, or 1.0, only if unedited. */
	const PRIVACY_SEED_V11 = 'c905043af6e3bf4595ff642bd2e69d3ae61bdfd1f5b504f8a98de8677e6f0912';
	/** Draft 1.2 as shipped in Core 1.5 and 1.6; an unedited copy is refreshed to 1.3 (Phase 5). */
	const PRIVACY_SEED_V12 = '2567e26b48ee8ac7704f6debe18af0aa5facc42c27f29fe42b4ba13615499eb7';

	public static function refresh_privacy_seed() {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'legal_docs' ) . ' WHERE doc_key = %s', 'privacy' ) ); // phpcs:ignore
		if ( ! $row || 'published' === $row->state || ! in_array( hash( 'sha256', (string) $row->draft_md ), array( self::PRIVACY_SEED_V1, self::PRIVACY_SEED_V11, self::PRIVACY_SEED_V12 ), true ) ) {
			return false;
		}
		$md = (string) @file_get_contents( NWC_DIR . 'assets/legal/' . self::DOCS['privacy']['seed'] ); // phpcs:ignore
		if ( '' === $md || hash( 'sha256', $md ) === hash( 'sha256', (string) $row->draft_md ) ) {
			return false;
		}
		$wpdb->update( self::t( 'legal_docs' ), array( 'draft_md' => $md, 'version' => '1.3', 'updated_at' => NWC::now() ), array( 'doc_key' => 'privacy' ) );
		self::add_revision( 'privacy', $md, '1.3', 'draft', 'Phase 5: essential platform processing and authorised access, agency and self-purchased Workspaces kept apart, Personal Workspace, real deletion with the restricted archive, backups', 0 );
		return true;
	}

	/** The real WordPress page for this document. Public only once the document is published. */
	public static function sync_page( $key, $html = null ) {
		if ( ! did_action( 'init' ) ) { // Upgrades run before post types exist.
			add_action( 'init', function () use ( $key, $html ) {
				self::sync_page( $key, $html );
			}, 20 );
			return;
		}
		$d    = self::DOCS[ $key ];
		$page = get_page_by_path( $d['slug'], OBJECT, 'page' );
		$args = array(
			'post_title'   => $d['title'],
			'post_name'    => $d['slug'],
			'post_type'    => 'page',
			'post_status'  => null === $html ? 'draft' : 'publish',
			'post_content' => null === $html ? 'Managed in Neon Workspace → Legal Pages. Edit it there.' : $html,
		);
		if ( $page ) {
			$args['ID'] = $page->ID;
			wp_update_post( wp_slash( $args ) );
		} else {
			wp_insert_post( wp_slash( $args ) );
		}
	}

	/* ---------------------------- Reading ---------------------------- */

	public static function doc( $key ) {
		global $wpdb;
		return isset( self::DOCS[ $key ] ) ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'legal_docs' ) . ' WHERE doc_key = %s', $key ) ) : null; // phpcs:ignore
	}

	/** @return object|null The immutable snapshot visitors see. */
	public static function published( $key ) {
		global $wpdb;
		$d = self::doc( $key );
		if ( ! $d || ! $d->published_revision ) {
			return null;
		}
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'legal_revisions' ) . ' WHERE id = %d', $d->published_revision ) ); // phpcs:ignore
	}

	/** The version a new acceptance is recorded against. Honest when nothing is published yet. */
	public static function current_version( $key ) {
		$p = self::published( $key );
		return $p ? $p->version : self::UNPUBLISHED;
	}

	public static function url( $key ) {
		return home_url( '/' . self::DOCS[ $key ]['slug'] . '/' );
	}

	/** What the public route draws. */
	public static function page_context( $key ) {
		$d    = self::doc( $key );
		$pub  = self::published( $key );
		$prev = isset( $_GET['preview'] ) && current_user_can( self::CAP_EDIT ); // phpcs:ignore
		$ctx  = array( 'doc' => array( 'title' => self::DOCS[ $key ]['title'], 'state_label' => $d ? self::STATES[ $d->state ] : 'Draft' ), 'published' => (bool) $pub, 'preview' => false, 'html' => '', 'meta' => null );
		if ( $prev && $d ) {
			$ctx['preview'] = true;
			$ctx['html']    = self::render( $d->draft_md );
			return $ctx;
		}
		if ( $pub ) {
			$ctx['html'] = $pub->html;
			$ctx['meta'] = array( 'version' => $pub->version, 'effective' => $pub->effective_date, 'updated' => $pub->last_updated );
		}
		return $ctx;
	}

	/* ---------------------------- Markdown ---------------------------- */

	/** Handles what the legal documents use: headings, lists, tables, quotes, links, bold, italics. */
	public static function render( $md ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $md );
		$out   = array();
		$para  = array();
		$list  = null;
		$quote = array();
		$flush_para = function () use ( &$para, &$out ) {
			if ( $para ) {
				$out[] = '<p>' . implode( '', array_map( array( __CLASS__, 'inline_line' ), $para ) ) . '</p>';
				$para  = array();
			}
		};
		$flush_list = function () use ( &$list, &$out ) {
			if ( $list ) {
				$out[] = '<' . $list[0] . '>' . implode( '', array_map( function ( $i ) {
					return '<li>' . self::inline( $i ) . '</li>';
				}, $list[1] ) ) . '</' . $list[0] . '>';
				$list  = null;
			}
		};
		$flush_quote = function () use ( &$quote, &$out ) {
			if ( $quote ) {
				$out[] = '<blockquote><p>' . self::inline( implode( ' ', $quote ) ) . '</p></blockquote>';
				$quote = array();
			}
		};
		$n = count( $lines );
		for ( $i = 0; $i < $n; $i++ ) {
			$l = $lines[ $i ];
			if ( '' === trim( $l ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				continue;
			}
			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $l, $m ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$h     = strlen( $m[1] );
				$out[] = '<h' . $h . '>' . self::inline( trim( $m[2] ) ) . '</h' . $h . '>';
				continue;
			}
			if ( preg_match( '/^\s*([-*_])(\s*\1){2,}\s*$/', $l ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$out[] = '<hr>';
				continue;
			}
			if ( preg_match( '/^>\s?(.*)$/', $l, $m ) ) {
				$flush_para();
				$flush_list();
				$quote[] = $m[1];
				continue;
			}
			if ( false !== strpos( $l, '|' ) && $i + 1 < $n && preg_match( '/^\s*\|?\s*:?-{2,}/', $lines[ $i + 1 ] ) ) {
				$flush_para();
				$flush_list();
				$flush_quote();
				$cells = function ( $row ) {
					return array_map( 'trim', explode( '|', trim( trim( $row ), '|' ) ) );
				};
				$html = '<div class="table-wrap"><table><thead><tr>' . implode( '', array_map( function ( $c ) {
					return '<th>' . self::inline( $c ) . '</th>';
				}, $cells( $l ) ) ) . '</tr></thead><tbody>';
				$i   += 2;
				while ( $i < $n && false !== strpos( $lines[ $i ], '|' ) && '' !== trim( $lines[ $i ] ) ) {
					$html .= '<tr>' . implode( '', array_map( function ( $c ) {
						return '<td>' . self::inline( $c ) . '</td>';
					}, $cells( $lines[ $i ] ) ) ) . '</tr>';
					$i++;
				}
				$i--;
				$out[] = $html . '</tbody></table></div>';
				continue;
			}
			if ( preg_match( '/^\s*(?:[-*]|(\d+)\.)\s+(.*)$/', $l, $m ) ) {
				$flush_para();
				$flush_quote();
				$kind = '' !== $m[1] ? 'ol' : 'ul';
				if ( ! $list || $list[0] !== $kind ) {
					$flush_list();
					$list = array( $kind, array() );
				}
				$list[1][] = $m[2];
				continue;
			}
			$flush_list();
			$flush_quote();
			$para[] = $l;
		}
		$flush_para();
		$flush_list();
		$flush_quote();
		return wp_kses_post( implode( "\n", $out ) );
	}

	/** Two trailing spaces mean a line break, as in the supplied drafts. */
	private static function inline_line( $l ) {
		$hard = (bool) preg_match( '/ {2,}$/', $l );
		return self::inline( rtrim( $l ) ) . ( $hard ? '<br>' : ' ' );
	}

	private static function inline( $t ) {
		$t = esc_html( $t );
		$t = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $t );
		$t = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $t );
		$t = preg_replace( '/(?<![*\w])\*([^*\n]+)\*(?![*\w])/', '<em>$1</em>', $t );
		return preg_replace_callback( '/\[([^\]]+)\]\(([^)\s]+)\)/', function ( $m ) {
			$url = esc_url( html_entity_decode( $m[2] ), array( 'http', 'https', 'mailto' ) );
			return '' === $url ? $m[1] : '<a href="' . $url . '">' . $m[1] . '</a>';
		}, $t );
	}

	/**
	 * Everything that must be resolved before publishing: bracketed placeholders and the draft
	 * banner. A markdown link's [text](url) is not a placeholder.
	 *
	 * @return string[] Distinct items.
	 */
	public static function blockers( $md ) {
		$found = array();
		if ( preg_match_all( '/\[([^\]\n]{2,})\](?!\()/', (string) $md, $m ) ) {
			foreach ( $m[1] as $x ) {
				$found[ '[' . $x . ']' ] = true;
			}
		}
		$out = array_keys( $found );
		if ( preg_match( '/DRAFT\s+—\s+REQUIRES/u', (string) $md ) ) {
			$out[] = 'The "DRAFT — REQUIRES BUSINESS AND LEGAL REVIEW" notice is still in the text';
		}
		if ( preg_match( '/\*\*(?:Draft version|End of[^*]*Draft)[^*]*\*\*/', (string) $md ) || false !== stripos( (string) $md, 'Draft v1.0' ) ) {
			$out[] = 'The text still calls itself a draft version';
		}
		// Sentences in the supplied drafts written to the authors, which must not reach the public page.
		foreach ( array( 'must be completed and reviewed', 'must be checked against', 'Please complete the appropriate', 'The final policy must', 'The final published policy', 'must match the deployed', 'Do not state that a user', 'should be documented and published only' ) as $note ) {
			if ( false !== stripos( (string) $md, $note ) ) {
				$out[] = 'An instruction to the authors is still in the text: "' . $note . '…"';
			}
		}
		return $out;
	}

	/* ---------------------------- Revisions ---------------------------- */

	private static function add_revision( $key, $md, $version, $state, $note, $user_id, $snapshot = array() ) {
		global $wpdb;
		$wpdb->insert( self::t( 'legal_revisions' ), array_merge( array( 'doc_key' => $key, 'version' => mb_substr( (string) $version, 0, 20 ), 'state' => $state, 'body_md' => $md, 'note' => mb_substr( (string) $note, 0, 255 ), 'editor_id' => (int) $user_id, 'created_at' => NWC::now() ), $snapshot ) );
		return (int) $wpdb->insert_id;
	}

	public static function revisions( $key, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, version, state, note, editor_id, is_published, created_at FROM ' . self::t( 'legal_revisions' ) . ' WHERE doc_key = %s ORDER BY id DESC LIMIT %d', $key, $limit ) ); // phpcs:ignore
	}

	/** Saving keeps a revision only when the text changed, and moves an approved document back to Draft. */
	public static function save_draft( $key, $md, $meta, $user_id ) {
		global $wpdb;
		$d = self::doc( $key );
		if ( ! $d ) {
			return NWC::err( 'Unknown document.', 404, 'nwc_not_found' );
		}
		$md  = str_replace( "\r\n", "\n", (string) $md );
		$row = array( 'updated_by' => (int) $user_id, 'updated_at' => NWC::now() );
		foreach ( array( 'version' => 20, 'effective_date' => 10, 'last_updated' => 10 ) as $k => $len ) {
			if ( isset( $meta[ $k ] ) ) {
				$row[ $k ] = mb_substr( sanitize_text_field( (string) $meta[ $k ] ), 0, $len );
			}
		}
		$changed = $md !== str_replace( "\r\n", "\n", (string) $d->draft_md );
		if ( $changed ) {
			$row['draft_md'] = $md;
			$row['state']    = 'draft';
			self::add_revision( $key, $md, $row['version'] ?? $d->version, 'draft', sanitize_text_field( (string) ( $meta['note'] ?? '' ) ), $user_id );
		}
		$wpdb->update( self::t( 'legal_docs' ), $row, array( 'doc_key' => $key ) );
		return $changed;
	}

	/**
	 * Draft → Needs Review by an editor; Approved only by a publisher.
	 *
	 * @return true|WP_Error
	 */
	public static function set_state( $key, $state, $user_id ) {
		global $wpdb;
		$d = self::doc( $key );
		if ( ! $d || ! isset( self::STATES[ $state ] ) || 'published' === $state ) {
			return NWC::err( 'That state is not available.' );
		}
		if ( 'approved' === $state && ! user_can( $user_id, self::CAP_PUBLISH ) ) {
			return NWC::err( 'Only an authorised admin can approve.', 403, 'nwc_forbidden' );
		}
		$wpdb->update( self::t( 'legal_docs' ), array( 'state' => $state, 'updated_by' => (int) $user_id, 'updated_at' => NWC::now() ), array( 'doc_key' => $key ) );
		NWC::audit( 'legal_state_changed', array( 'workspace_id' => null, 'kind' => 'admin', 'user_id' => $user_id, 'entity' => 'legal', 'details' => array( 'doc' => $key, 'state' => $state ) ) );
		return true;
	}

	/**
	 * The publication gate. Requires permission, approval, no placeholders, a new version number,
	 * dates, and the business confirmations.
	 *
	 * @param array $in version-independent flags: confirm[], reaccept, change_notice.
	 * @return int|WP_Error Revision id of the immutable snapshot.
	 */
	public static function publish( $key, $in, $user_id ) {
		global $wpdb;
		if ( ! user_can( $user_id, self::CAP_PUBLISH ) ) {
			return NWC::err( 'Only an authorised admin can publish.', 403, 'nwc_forbidden' );
		}
		$d = self::doc( $key );
		if ( ! $d ) {
			return NWC::err( 'Unknown document.', 404, 'nwc_not_found' );
		}
		if ( 'approved' !== $d->state ) {
			return NWC::err( 'Approve this version first.', 409, 'nwc_not_approved' );
		}
		$block = self::blockers( $d->draft_md );
		if ( $block ) {
			return NWC::err( count( $block ) . ' item(s) in the text must be resolved before publishing.', 409, 'nwc_legal_blocked', array( 'blockers' => $block ) );
		}
		if ( '' === (string) $d->effective_date || '' === (string) $d->last_updated ) {
			return NWC::err( 'Set the effective date and last-updated date.', 409, 'nwc_legal_blocked' );
		}
		$confirmed = array_keys( array_filter( (array) ( $in['confirm'] ?? array() ) ) );
		$missing   = array_diff( array_keys( self::CONFIRMATIONS ), $confirmed );
		if ( $missing ) {
			return NWC::err( 'Confirm each business and legal item before publishing.', 409, 'nwc_legal_blocked', array( 'unconfirmed' => array_values( $missing ) ) );
		}
		$dup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'legal_revisions' ) . ' WHERE doc_key = %s AND version = %s AND is_published = 1', $key, $d->version ) ); // phpcs:ignore
		if ( $dup ) {
			return NWC::err( 'Version ' . $d->version . ' was already published. Published versions never change, so give this one a new number.', 409, 'nwc_legal_version' );
		}
		$html = self::render( $d->draft_md );
		$rev  = self::add_revision( $key, $d->draft_md, $d->version, 'published', 'Published', $user_id, array( 'html' => $html, 'is_published' => 1, 'effective_date' => $d->effective_date, 'last_updated' => $d->last_updated, 'reaccept' => empty( $in['reaccept'] ) ? 0 : 1, 'change_notice' => sanitize_textarea_field( (string) ( $in['change_notice'] ?? '' ) ), 'confirmations' => wp_json_encode( $confirmed ) ) );
		$wpdb->update( self::t( 'legal_docs' ), array( 'state' => 'published', 'published_revision' => $rev, 'updated_by' => (int) $user_id, 'updated_at' => NWC::now() ), array( 'doc_key' => $key ) );
		self::sync_page( $key, $html );
		NWC::audit( 'legal_published', array( 'workspace_id' => null, 'kind' => 'admin', 'user_id' => $user_id, 'entity' => 'legal', 'entity_id' => $rev, 'details' => array( 'doc' => $key, 'version' => $d->version, 'reaccept' => ! empty( $in['reaccept'] ) ) ) );
		return $rev;
	}

	public static function unpublish( $key, $user_id ) {
		global $wpdb;
		if ( ! user_can( $user_id, self::CAP_PUBLISH ) ) {
			return NWC::err( 'Only an authorised admin can unpublish.', 403, 'nwc_forbidden' );
		}
		$wpdb->update( self::t( 'legal_docs' ), array( 'state' => 'draft', 'published_revision' => null, 'updated_by' => (int) $user_id, 'updated_at' => NWC::now() ), array( 'doc_key' => $key ) );
		self::sync_page( $key );
		NWC::audit( 'legal_unpublished', array( 'workspace_id' => null, 'kind' => 'admin', 'user_id' => $user_id, 'entity' => 'legal', 'details' => array( 'doc' => $key ) ) );
		return true;
	}

	/** Restoring makes the old text the new draft. The history, and any snapshot, is untouched. */
	public static function restore( $revision_id, $user_id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'legal_revisions' ) . ' WHERE id = %d', $revision_id ) ); // phpcs:ignore
		if ( ! $r ) {
			return NWC::err( 'Revision not found.', 404, 'nwc_not_found' );
		}
		self::save_draft( $r->doc_key, $r->body_md, array( 'note' => 'Restored from revision ' . (int) $r->id ), $user_id );
		return $r->doc_key;
	}

	/* ---------------------------- Acceptance ---------------------------- */

	/** Records terms and privacy, each with its own version, and how the account was created. */
	public static function record_acceptance( $user_id, $workspace_id, $method ) {
		global $wpdb;
		$now = NWC::now();
		foreach ( array( 'terms', 'privacy' ) as $k ) {
			$wpdb->insert( self::t( 'consents' ), array( 'user_id' => (int) $user_id, 'workspace_id' => $workspace_id ? (int) $workspace_id : null, 'kind' => $k, 'version' => mb_substr( self::current_version( $k ), 0, 20 ), 'granted' => 1, 'method' => mb_substr( (string) $method, 0, 20 ), 'ip' => NWC::ip(), 'created_at' => $now ) );
		}
	}

	/** Separate from legal acceptance, and only a real choice writes it. */
	public static function record_marketing( $user_id, $granted, $method ) {
		global $wpdb;
		$wpdb->insert( self::t( 'consents' ), array( 'user_id' => (int) $user_id, 'kind' => 'marketing', 'version' => '', 'granted' => $granted ? 1 : 0, 'method' => mb_substr( (string) $method, 0, 20 ), 'ip' => NWC::ip(), 'created_at' => NWC::now() ) );
	}

	private static function last_consent( $user_id, $kind ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'consents' ) . ' WHERE user_id = %d AND kind = %s ORDER BY id DESC LIMIT 1', $user_id, $kind ) ); // phpcs:ignore
	}

	public static function has_accepted_any( $user_id ) {
		return (bool) self::last_consent( $user_id, 'terms' );
	}

	/**
	 * Documents this person still has to accept. Someone who accepted before anything was published
	 * must accept the first real version. After that it needs an admin's re-acceptance flag.
	 * Accounts from before this feature have no record, and are asked only when a version asks.
	 *
	 * @return array[] {key,title,url,version,notice}
	 */
	public static function pending_for( $user_id ) {
		$out = array();
		foreach ( array_keys( self::DOCS ) as $k ) {
			$p = self::published( $k );
			if ( ! $p ) {
				continue;
			}
			$last = self::last_consent( $user_id, $k );
			if ( $last && $last->version === $p->version ) {
				continue;
			}
			if ( ( $last && self::UNPUBLISHED === $last->version ) || (int) $p->reaccept ) {
				$out[] = array( 'key' => $k, 'title' => self::DOCS[ $k ]['title'], 'url' => self::url( $k ), 'version' => $p->version, 'notice' => (string) $p->change_notice );
			}
		}
		return $out;
	}

	public static function pending_route() {
		return array( 'pending' => self::pending_for( get_current_user_id() ) );
	}

	public static function accept_route( WP_REST_Request $r ) {
		global $wpdb;
		$uid  = get_current_user_id();
		$keys = (array) ( NWC_REST::body( $r )['documents'] ?? array() );
		$want = wp_list_pluck( self::pending_for( $uid ), 'version', 'key' );
		foreach ( $keys as $k ) {
			if ( isset( $want[ $k ] ) ) {
				$wpdb->insert( self::t( 'consents' ), array( 'user_id' => $uid, 'kind' => $k, 'version' => mb_substr( $want[ $k ], 0, 20 ), 'granted' => 1, 'method' => 'reaccept', 'ip' => NWC::ip(), 'created_at' => NWC::now() ) );
			}
		}
		return self::pending_route();
	}

	public static function marketing_route() {
		$last = self::last_consent( get_current_user_id(), 'marketing' );
		return array( 'granted' => $last ? (bool) $last->granted : false, 'at' => $last ? $last->created_at : null );
	}

	public static function marketing_save( WP_REST_Request $r ) {
		self::record_marketing( get_current_user_id(), ! empty( NWC_REST::body( $r )['granted'] ), 'settings' );
		return self::marketing_route();
	}

	/** How many accounts a re-acceptance would reach. */
	public static function affected_accounts( $key ) {
		global $wpdb;
		$p = self::published( $key );
		$t = self::t( 'consents' );
		if ( ! $p ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE NOT EXISTS (SELECT 1 FROM $t c WHERE c.user_id = u.ID AND c.kind = %s AND c.version = %s)", $key, $p->version ) ); // phpcs:ignore
	}

	/* ---------------------------- Admin screen ---------------------------- */

	public static function menu() {
		add_submenu_page( 'nwc', 'Legal Pages', 'Legal Pages', self::CAP_EDIT, 'nwc-legal', array( __CLASS__, 'page' ) );
		// A Legal Editor has no other Neon Workspace page, so gets this one as a top-level entry.
		if ( ! current_user_can( 'manage_options' ) && current_user_can( self::CAP_EDIT ) ) {
			add_menu_page( 'Legal Pages', 'Legal Pages', self::CAP_EDIT, 'nwc-legal', array( __CLASS__, 'page' ), 'dashicons-media-text', 3 );
		}
	}

	private static function back( $key, $msg, $bad = false ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'nwc-legal', 'doc' => $key, 'msg' => rawurlencode( $msg ), 'bad' => $bad ? 1 : 0 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function guard( $action, $cap = self::CAP_EDIT ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( $action );
		$key = isset( $_REQUEST['doc'] ) ? sanitize_key( wp_unslash( $_REQUEST['doc'] ) ) : ''; // phpcs:ignore
		if ( ! isset( self::DOCS[ $key ] ) ) {
			wp_die( 'Unknown document.', 404 );
		}
		return $key;
	}

	public static function handle_save() {
		$key     = self::guard( 'nwc_legal_save' );
		$changed = self::save_draft( $key, isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : '', array( 'version' => wp_unslash( $_POST['version'] ?? '' ), 'effective_date' => wp_unslash( $_POST['effective_date'] ?? '' ), 'last_updated' => wp_unslash( $_POST['last_updated'] ?? '' ), 'note' => wp_unslash( $_POST['note'] ?? '' ) ), get_current_user_id() ); // phpcs:ignore
		self::back( $key, $changed ? 'Draft saved. A new revision was kept.' : 'Saved. The text did not change.' );
	}

	public static function handle_state() {
		$key = self::guard( 'nwc_legal_state' );
		$res = self::set_state( $key, sanitize_key( wp_unslash( $_POST['state'] ?? '' ) ), get_current_user_id() ); // phpcs:ignore
		is_wp_error( $res ) ? self::back( $key, $res->get_error_message(), true ) : self::back( $key, 'State updated.' );
	}

	public static function handle_publish() {
		$key = self::guard( 'nwc_legal_publish', self::CAP_PUBLISH );
		$res = self::publish( $key, array( 'confirm' => array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['confirm'] ?? array() ) ), 'reaccept' => ! empty( $_POST['reaccept'] ), 'change_notice' => wp_unslash( $_POST['change_notice'] ?? '' ) ), get_current_user_id() ); // phpcs:ignore
		is_wp_error( $res ) ? self::back( $key, $res->get_error_message(), true ) : self::back( $key, 'Published.' );
	}

	public static function handle_unpublish() {
		$key = self::guard( 'nwc_legal_unpublish', self::CAP_PUBLISH );
		self::unpublish( $key, get_current_user_id() );
		self::back( $key, 'Unpublished. Visitors now see the holding notice.' );
	}

	public static function handle_restore() {
		$key = self::guard( 'nwc_legal_restore' );
		$res = self::restore( absint( $_POST['revision'] ?? 0 ), get_current_user_id() ); // phpcs:ignore
		is_wp_error( $res ) ? self::back( $key, $res->get_error_message(), true ) : self::back( $key, 'That revision is now the draft.' );
	}

	public static function handle_export() {
		$key = self::guard( 'nwc_legal_export' );
		$d   = self::doc( $key );
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::DOCS[ $key ]['slug'] . '-' . sanitize_file_name( (string) $d->version ) . '.md"' );
		echo $d->draft_md; // phpcs:ignore
		exit;
	}

	public static function ajax_preview() {
		if ( ! current_user_can( self::CAP_EDIT ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'nwc_legal_preview' );
		$md = isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : ''; // phpcs:ignore
		wp_send_json_success( array( 'html' => self::render( $md ), 'blockers' => self::blockers( $md ) ) );
	}

	public static function page() {
		if ( ! current_user_can( self::CAP_EDIT ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$key = isset( $_GET['doc'] ) && isset( self::DOCS[ $_GET['doc'] ] ) ? sanitize_key( $_GET['doc'] ) : 'terms'; // phpcs:ignore
		$d   = self::doc( $key );
		$pub = self::published( $key );
		$can = current_user_can( self::CAP_PUBLISH );
		$blk = self::blockers( $d->draft_md );
		$act = admin_url( 'admin-post.php' );
		echo '<div class="wrap nwc-legal"><h1>Legal Pages</h1>';
		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore
			echo '<div class="notice ' . ( empty( $_GET['bad'] ) ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['msg'] ) ) ) ) . '</p></div>'; // phpcs:ignore
		}
		echo '<p class="description">These drafts are not legal certification. Have a qualified Indian lawyer review them, including the phased commencement of the Digital Personal Data Protection Act, 2023 and its Rules, as of the publication date.</p><h2 class="nav-tab-wrapper">';
		foreach ( self::DOCS as $k => $x ) {
			echo '<a class="nav-tab' . ( $k === $key ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array( 'page' => 'nwc-legal', 'doc' => $k ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $x['title'] ) . '</a>';
		}
		echo '</h2><p><b>State:</b> ' . esc_html( self::STATES[ $d->state ] ) . ( 'published' !== $d->state ? ' <span class="description">(Requires business and legal review)</span>' : '' ) . ' &nbsp; <b>Live version:</b> ' . esc_html( $pub ? $pub->version . ', published ' . $pub->created_at . ' UTC' : 'none, visitors see a holding notice' ) . ' &nbsp; <a href="' . esc_url( add_query_arg( 'preview', 1, self::url( $key ) ) ) . '" target="_blank" rel="noopener">Open draft preview</a> &nbsp; <a href="' . esc_url( self::url( $key ) ) . '" target="_blank" rel="noopener">Public page</a></p>';

		echo '<form method="post" action="' . esc_url( $act ) . '"><input type="hidden" name="action" value="nwc_legal_save"><input type="hidden" name="doc" value="' . esc_attr( $key ) . '">';
		wp_nonce_field( 'nwc_legal_save' );
		echo '<p><label>Version <input name="version" size="8" value="' . esc_attr( $d->version ) . '"></label> &nbsp; <label>Effective date <input type="date" name="effective_date" value="' . esc_attr( (string) $d->effective_date ) . '"></label> &nbsp; <label>Last updated <input type="date" name="last_updated" value="' . esc_attr( (string) $d->last_updated ) . '"></label></p>';
		echo '<div class="nwc-legal-grid"><div><label for="nwc-body"><b>Text</b> <span class="description">Markdown: # headings, **bold**, - lists, [links](https://…), | tables |</span></label><textarea id="nwc-body" name="body" rows="30" style="width:100%;font:13px/1.5 Consolas,monospace">' . esc_textarea( $d->draft_md ) . '</textarea></div><div><p><b>Preview</b> <button type="button" class="button button-small" data-w="100%">Desktop</button> <button type="button" class="button button-small" data-w="390px">Mobile</button></p><div id="nwc-preview" style="border:1px solid #c3c4c7;background:#fff;padding:16px;height:560px;overflow:auto;width:100%;box-sizing:border-box">' . self::render( $d->draft_md ) . '</div></div></div>'; // phpcs:ignore
		echo '<p><label>Note for this revision <input name="note" size="50"></label> <button class="button button-primary">Save draft</button></p></form>';

		echo '<h2>Publication blockers</h2><div id="nwc-blockers">';
		echo $blk ? '<ul style="list-style:disc;margin-left:20px">' . implode( '', array_map( function ( $b ) { return '<li>' . esc_html( $b ) . '</li>'; }, $blk ) ) . '</ul>' : '<p>None in the text.</p>'; // phpcs:ignore
		echo '</div>';

		echo '<h2>Review and publish</h2><form method="post" action="' . esc_url( $act ) . '" style="display:inline-block;margin-right:8px"><input type="hidden" name="action" value="nwc_legal_state"><input type="hidden" name="doc" value="' . esc_attr( $key ) . '">';
		wp_nonce_field( 'nwc_legal_state' );
		echo '<select name="state"><option value="draft"' . selected( $d->state, 'draft', false ) . '>Draft</option><option value="needs_review"' . selected( $d->state, 'needs_review', false ) . '>Needs Review</option>' . ( $can ? '<option value="approved"' . selected( $d->state, 'approved', false ) . '>Approved</option>' : '' ) . '</select> <button class="button">Set state</button></form>';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'nwc_legal_export', 'doc' => $key ), $act ), 'nwc_legal_export' ) ) . '">Export current text</a>';
		if ( $can ) {
			echo '<form method="post" action="' . esc_url( $act ) . '"><input type="hidden" name="action" value="nwc_legal_publish"><input type="hidden" name="doc" value="' . esc_attr( $key ) . '">';
			wp_nonce_field( 'nwc_legal_publish' );
			echo '<p><b>The owner confirms each of these is correct in the text:</b></p>';
			foreach ( self::CONFIRMATIONS as $k => $label ) {
				echo '<label style="display:block"><input type="checkbox" name="confirm[' . esc_attr( $k ) . ']" value="1"> ' . esc_html( $label ) . '</label>';
			}
			echo '<p><label><input type="checkbox" name="reaccept" value="1"' . ( $pub ? '' : ' checked' ) . '> Ask affected accounts to accept this version</label>' . ( $pub ? ' <span class="description">' . (int) self::affected_accounts( $key ) . ' account(s) have not accepted the live version.</span>' : '' ) . '</p>';
			echo '<p><label>Change notice shown to them<br><textarea name="change_notice" rows="2" cols="70"></textarea></label></p><p><button class="button button-primary"' . ( 'approved' === $d->state && ! $blk ? '' : ' disabled' ) . '>Publish version ' . esc_html( $d->version ) . '</button>' . ( 'approved' === $d->state && ! $blk ? '' : ' <span class="description">Available once this is Approved and has no blockers.</span>' ) . '</p></form>';
			if ( $pub ) {
				echo '<form method="post" action="' . esc_url( $act ) . '"><input type="hidden" name="action" value="nwc_legal_unpublish"><input type="hidden" name="doc" value="' . esc_attr( $key ) . '">';
				wp_nonce_field( 'nwc_legal_unpublish' );
				echo '<button class="button" onclick="return confirm(\'Take this document off the public site? Its snapshot and acceptance records stay.\')">Unpublish</button></form>';
			}
		}

		echo '<h2>Revision history</h2><table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Version</th><th>State</th><th>Editor</th><th>Note</th><th></th></tr></thead><tbody>';
		$revs = self::revisions( $key );
		foreach ( $revs as $i => $r ) {
			$u = $r->editor_id ? get_userdata( $r->editor_id ) : null;
			echo '<tr><td>' . esc_html( $r->created_at ) . '</td><td>' . esc_html( $r->version ) . '</td><td>' . esc_html( (int) $r->is_published ? 'Published snapshot' : ( self::STATES[ $r->state ] ?? $r->state ) ) . '</td><td>' . esc_html( $u ? $u->display_name : 'System' ) . '</td><td>' . esc_html( $r->note ) . '</td><td><a class="button button-small" href="' . esc_url( add_query_arg( array( 'page' => 'nwc-legal', 'doc' => $key, 'compare' => $r->id ), admin_url( 'admin.php' ) ) ) . '#compare">Compare with draft</a> ';
			echo '<form method="post" action="' . esc_url( $act ) . '" style="display:inline"><input type="hidden" name="action" value="nwc_legal_restore"><input type="hidden" name="doc" value="' . esc_attr( $key ) . '"><input type="hidden" name="revision" value="' . (int) $r->id . '">';
			wp_nonce_field( 'nwc_legal_restore' );
			echo '<button class="button button-small">Restore</button></form></td></tr>';
		}
		echo '</tbody></table>';
		if ( isset( $_GET['compare'] ) ) { // phpcs:ignore
			global $wpdb;
			$old = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'legal_revisions' ) . ' WHERE id = %d AND doc_key = %s', absint( $_GET['compare'] ), $key ) ); // phpcs:ignore
			if ( $old ) {
				$diff = wp_text_diff( $old->body_md, $d->draft_md, array( 'title_left' => 'Revision ' . $old->id . ' (' . $old->created_at . ' UTC)', 'title_right' => 'Current draft' ) );
				echo '<h2 id="compare">Compare</h2>' . ( $diff ? wp_kses_post( $diff ) : '<p>No differences.</p>' ); // phpcs:ignore
			}
		}
		$nonce = wp_create_nonce( 'nwc_legal_preview' );
		?>
		<style>.nwc-legal-grid{display:grid;grid-template-columns:1fr;gap:16px}@media(min-width:1100px){.nwc-legal-grid{grid-template-columns:1fr 1fr}}#nwc-preview table{border-collapse:collapse}#nwc-preview td,#nwc-preview th{border:1px solid #ddd;padding:4px 8px}#nwc-preview blockquote{border-left:3px solid #8B5CF6;margin:0 0 1em;padding-left:12px}</style>
		<script>
		(function () {
			var ta = document.getElementById('nwc-body'), pv = document.getElementById('nwc-preview'), bl = document.getElementById('nwc-blockers'), t = null;
			document.querySelectorAll('[data-w]').forEach(function (b) { b.onclick = function () { pv.style.width = b.getAttribute('data-w'); }; });
			ta.addEventListener('input', function () {
				clearTimeout(t);
				t = setTimeout(function () {
					var d = new FormData();
					d.append('action', 'nwc_legal_preview');
					d.append('_ajax_nonce', <?php echo wp_json_encode( $nonce ); ?>);
					d.append('body', ta.value);
					fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ), JSON_UNESCAPED_SLASHES ); ?>, { method: 'POST', credentials: 'same-origin', body: d }).then(function (r) { return r.json(); }).then(function (r) {
						if (!r.success) return;
						pv.innerHTML = r.data.html;
						bl.textContent = r.data.blockers.length ? r.data.blockers.join(' · ') : 'None in the text.';
					});
				}, 400);
			});
		})();
		</script>
		<?php
		echo '</div>';
	}
}
