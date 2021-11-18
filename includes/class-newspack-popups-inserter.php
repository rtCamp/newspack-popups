<?php
/**
 * Newspack Popups Inserter
 *
 * @package Newspack
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/../api/segmentation/class-segmentation.php';

/**
 * API endpoints
 */
final class Newspack_Popups_Inserter {
	/**
	 * The popup objects to display.
	 *
	 * @var array
	 */
	protected static $popups = [];

	/**
	 * Whether we've already inserted prompts into the content.
	 * If we've already inserted popups into the content, don't try to do it again.
	 *
	 * @var boolean
	 */
	public static $the_content_has_rendered = false;

	/**
	 * Retrieve the appropriate popups for the current post.
	 *
	 * @return array Popup objects.
	 */
	public static function popups_for_post() {
		if ( ! empty( self::$popups ) ) {
			return self::$popups;
		}

		// Get the previewed popup and return early.
		if ( Newspack_Popups::previewed_popup_id() ) {
			return [ Newspack_Popups_Model::retrieve_preview_popup( Newspack_Popups::previewed_popup_id() ) ];
		}

		// Popups disabled for this page.
		if ( self::assess_has_disabled_popups() ) {
			return [];
		}

		$view_as_spec        = Segmentation::parse_view_as( Newspack_Popups_View_As::viewing_as_spec() );
		$campaign_id         = isset( $view_as_spec['campaign'] ) ? $view_as_spec['campaign'] : false;
		$include_unpublished = isset( $view_as_spec['show_unpublished'] ) && 'true' === $view_as_spec['show_unpublished'] ? true : false;

		// Retrieve all prompts eligible for display.
		$popups_to_maybe_display = Newspack_Popups_Model::retrieve_eligible_popups( $include_unpublished, $campaign_id );

		return array_filter(
			$popups_to_maybe_display,
			function( $popup ) {
				return self::should_display( $popup, true );
			}
		);
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_filter( 'the_content', [ $this, 'insert_popups_in_content' ], 1 );
		add_shortcode( 'newspack-popup', [ $this, 'popup_shortcode' ] );
		add_action( 'after_header', [ $this, 'insert_popups_after_header' ] ); // This is a Newspack theme hook. When used with other themes, popups won't be inserted on archive pages.
		add_action( 'wp_head', [ $this, 'insert_popups_amp_access' ] );
		add_action( 'wp_head', [ $this, 'register_amp_scripts' ] );
		add_action( 'before_header', [ $this, 'insert_before_header' ] );
		add_action( 'after_archive_post', [ $this, 'insert_inline_prompt_in_archive_pages' ] );

		// Always enqueue scripts, since this plugin's scripts are handling pageview sending via GTAG.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

		add_filter(
			'newspack_popups_assess_has_disabled_popups',
			function ( $disabled ) {
				if ( get_post_meta( get_the_ID(), 'newspack_popups_has_disabled_popups', true ) ) {
					return true;
				}

				return $disabled;
			}
		);

		// Suppress popups on product pages.
		// Until the popups non-AMP refactoring happens, they will break Add to Cart buttons.
		add_filter(
			'newspack_popups_assess_has_disabled_popups',
			function( $disabled ) {
				if ( function_exists( 'is_product' ) && is_product() ) {
					return true;
				}
				return $disabled;
			}
		);

		// The suppress filter used to be named 'newspack_newsletters_assess_has_disabled_popups'.
		// Maintain that filter for backwards compatibility.
		add_filter(
			'newspack_popups_assess_has_disabled_popups',
			function( $disabled ) {
				if ( apply_filters( 'newspack_newsletters_assess_has_disabled_popups', false ) ) {
					return true;
				}

				return $disabled;
			}
		);

		// These hooks are fired before and after rendering posts in the Homepage Posts block.
		// By removing the the_content filter before rendering, we avoid incorrectly injecting popup content into excerpts in the block.
		add_action(
			'newspack_blocks_homepage_posts_before_render',
			function() {
				remove_filter( 'the_content', [ $this, 'insert_popups_in_content' ], 1 );
			}
		);

		add_action(
			'newspack_blocks_homepage_posts_after_render',
			function() {
				add_filter( 'the_content', [ $this, 'insert_popups_in_content' ], 1 );
			}
		);
	}

	/**
	 * Some blocks should never have a prompt right after them. For example, a prompt right after a subheading
	 * (header block) would not look good.
	 *
	 * @param object $block A block.
	 */
	private static function can_block_be_followed_by_prompt( $block ) {
		if (
			in_array(
				$block['blockName'],
				[
					// A prompt may not appear right after a heading block.
					'core/heading',
				]
			) ) {
			return false;
		}
		if (
			// A prompt may not appear after a floated image block, because it
			// will mess up the layout then.
			'core/image' === $block['blockName']
			&& isset( $block['attrs']['align'] )
			&& in_array( $block['attrs']['align'], [ 'left', 'right' ] )
		) {
			return false;
		}
		return true;
	}

	/**
	 * Convert blocks containing classic (legacy) content into regular blocks.
	 *
	 * @param array $blocks Array of blocks, some of which might be classic content.
	 */
	private static function convert_classic_blocks( $blocks ) {
		return array_reduce(
			$blocks,
			function( $blocks, $block ) {
				$is_classic_block = null === $block['blockName'] || 'core/freeform' === $block['blockName']; // Classic content results in a block without a block name.
				$is_empty         = empty( trim( $block['innerHTML'] ) );
				if ( $is_classic_block && ! $is_empty ) {
					$classic_content = force_balance_tags( wpautop( $block['innerHTML'] ) ); // Ensure we have paragraph tags and valid HTML.
					$dom             = new DomDocument();
					libxml_use_internal_errors( true );
					$dom->loadHTML( mb_convert_encoding( $classic_content, 'HTML-ENTITIES', get_bloginfo( 'charset' ) ) );
					$dom_body = $dom->getElementsByTagName( 'body' );
					if ( 0 < $dom_body->length ) {
						$dom_body_elements = $dom_body->item( 0 )->childNodes;
						foreach ( $dom_body_elements as $index => $entry ) {
							$block_html = $dom->saveHtml( $entry );
							$block_name = 'core/html';
							if ( 1 === preg_match( '/^<h\d>.*<\/h\d>$/', $block_html ) ) {
								$block_name = 'core/heading';
							}
							$blocks[] = [
								'blockName'    => $block_name,
								'attrs'        => [],
								'innerBlocks'  => [],
								'innerHTML'    => $block_html,
								'innerContent' => [
									$block_html,
								],
							];
						}
					}
				} else {
					$blocks[] = $block;
				}
				return $blocks;
			},
			[]
		);
	}

	/**
	 * Insert popups in a post content.
	 *
	 * @param string $content The post content.
	 * @param array  $popups Array of popup objects.
	 */
	public static function insert_popups_in_post_content( $content, $popups ) {
		// For certain types of blocks, their innerHTML is not a good representation of the length of their content.
		// For example, slideshows may have an arbitrary amount of slide content, but only show one slide at a time.
		// For these blocks, let's ignore their length for purposes of inserting prompts.
		$length_ignored_blocks = [ 'jetpack/slideshow', 'newspack-blocks/carousel', 'newspack-popups/single-prompt' ];

		$parsed_blocks = self::convert_classic_blocks( parse_blocks( $content ) );

		$parsed_blocks = array_values( // array_values will reindex the array.
			// Filter out empty blocks.
			array_filter(
				$parsed_blocks,
				function( $block ) {
					return 0 !== strlen( trim( $block['innerHTML'] ) );
				}
			)
		);

		$block_index          = 0;
		$parsed_blocks_groups = array_reduce(
			$parsed_blocks,
			function ( $block_groups, $block ) use ( &$block_index, $parsed_blocks ) {
				// Look up the prev block to maybe include it in the group.
				if ( 0 !== $block_index ) {
					$prev_block                           = $parsed_blocks[ $block_index - 1 ];
					$should_next_be_grouped_with_previous = ! self::can_block_be_followed_by_prompt( $prev_block );
					if ( $should_next_be_grouped_with_previous ) {
						$block_groups[] = [ $prev_block, $block ];
					} elseif ( self::can_block_be_followed_by_prompt( $block ) ) {
						$block_groups[] = [ $block ];
					}
				} elseif ( self::can_block_be_followed_by_prompt( $block ) ) {
					$block_groups[] = [ $block ];
				}

				$block_index++;
				return $block_groups;
			},
			[]
		);
		$total_length         = 0;

		// Compute the total length of the content.
		foreach ( $parsed_blocks as $block ) {
			if ( in_array( $block['blockName'], $length_ignored_blocks ) ) {
				// Give length-ignored blocks a length of 1 so that prompts at 0% can still be inserted before them.
				$total_length++;
			} else {
				$is_classic_block = null === $block['blockName'] || 'core/freeform' === $block['blockName']; // Classic block doesn't have a block name.
				$block_content    = $is_classic_block ? force_balance_tags( wpautop( $block['innerHTML'] ) ) : $block['innerHTML'];
				$total_length    += strlen( wp_strip_all_tags( $block_content ) );
			}
		}

		// 1. Separate prompts into inline and overlay.
		$inline_popups  = [];
		$overlay_popups = [];
		foreach ( $popups as $popup ) {
			if ( Newspack_Popups_Model::is_inline( $popup ) ) {
				$percentage                = intval( $popup['options']['trigger_scroll_progress'] ) / 100;
				$popup['precise_position'] = $total_length * $percentage;
				$popup['is_inserted']      = false;
				$inline_popups[]           = $popup;
			} elseif ( Newspack_Popups_Model::is_overlay( $popup ) ) {
				$overlay_popups[] = $popup;
			}
		}

		// Return early if there are no popups to insert. This can happen if e.g. the only popup is an above header one.
		if ( empty( $inline_popups ) && empty( $overlay_popups ) ) {
			return $content;
		}

		// 2. Iterate over all blocks and insert inline prompts.
		$pos    = 0;
		$output = '';

		foreach ( $parsed_blocks_groups as $block_group ) {
			// Compute the length of the blocks in the group.
			foreach ( $block_group as $block ) {
				if ( in_array( $block['blockName'], $length_ignored_blocks ) ) {
					// Give length-ignored blocks a length of 1 so that prompts at 0% can still be inserted before them.
					$pos++;
				} else {
					$pos += strlen( wp_strip_all_tags( $block['innerHTML'] ) );
				}
			}

			// Inject prompts before the group.
			foreach ( $inline_popups as &$inline_popup ) {
				if (
					! $inline_popup['is_inserted'] &&
					$pos > $inline_popup['precise_position']
				) {
					$output                     .= '<!-- wp:shortcode -->[newspack-popup id="' . $inline_popup['id'] . '"]<!-- /wp:shortcode -->';
					$inline_popup['is_inserted'] = true;
				}
			}

			// Render blocks from the block group.
			foreach ( $block_group as $block ) {
				$output .= serialize_block( $block );
			}
		}

		// 3. Insert any remaining inline prompts at the end.
		foreach ( $inline_popups as &$inline_popup ) {
			if ( ! $inline_popup['is_inserted'] ) {
				$output                     .= '<!-- wp:shortcode -->[newspack-popup id="' . $inline_popup['id'] . '"]<!-- /wp:shortcode -->';
				$inline_popup['is_inserted'] = true;
			}
		}

		// 4. Insert overlay prompts at the top of content.
		foreach ( $overlay_popups as $overlay_popup ) {
			$output = '<!-- wp:html -->' . Newspack_Popups_Model::generate_popup( $overlay_popup ) . '<!-- /wp:html -->' . $output;
		}
		return $output;
	}

	/**
	 * Process popups and insert into post and page content if needed.
	 *
	 * @param string $content The content of the post.
	 */
	public static function insert_popups_in_content( $content = '' ) {
		if (
			// Avoid duplicate execution.
			true === self::$the_content_has_rendered
			// Not Frontend.
			|| is_admin()
			// Content is empty.
			|| empty( trim( $content ) )
			// No popup insertion in archive pages - there's another method for that.
			|| ! is_singular()
			// If not in the loop, ignore.
			|| ! in_the_loop()
			// Don't inject inline popups on paywalled posts.
			// It doesn't make sense with a paywall message and also causes an infinite loop.
			|| function_exists( 'wc_memberships_is_post_content_restricted' ) && wc_memberships_is_post_content_restricted()
		) {
			return $content;
		}

		// If any popups are inserted using a shortcode, skip them - no need to duplicate.
		$shortcoded_popups_ids = self::get_shortcoded_popups_ids( get_the_content() );
		$popups                = array_filter(
			self::popups_for_post(),
			function ( $popup ) use ( $shortcoded_popups_ids ) {
				return ! in_array( $popup['id'], $shortcoded_popups_ids ) && Newspack_Popups_Model::should_be_inserted_in_page_content( $popup );
			}
		);

		if ( empty( $popups ) ) {
			return $content;
		}

		$content_with_popups = self::insert_popups_in_post_content(
			$content,
			$popups
		);

		self::$the_content_has_rendered = true;
		return $content_with_popups;
	}

	/**
	 * Insert overlay prompts into archive pages if needed. Applies to Newspack Theme only.
	 */
	public static function insert_popups_after_header() {
		/* Posts and pages are covered by the_content hook */
		if ( is_singular() ) {
			return;
		}
		$popups = array_filter(
			self::popups_for_post(),
			function ( $popup ) {
				return Newspack_Popups_Model::should_be_inserted_in_page_content( $popup ) && Newspack_Popups_Model::is_overlay( $popup );
			}
		);
		foreach ( $popups as $popup ) {
			echo Newspack_Popups_Model::generate_popup( $popup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Insert popups markup before header.
	 */
	public static function insert_before_header() {
		$before_header_popups = array_filter( self::popups_for_post(), [ 'Newspack_Popups_Model', 'should_be_inserted_above_page_header' ] );
		foreach ( $before_header_popups as $popup ) {
			echo Newspack_Popups_Model::generate_popup( $popup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Insert popup after posts in archive pages. Called in the custom hook after_archive_post
	 * The hook is add inside the posts loop in the archive template.
	 *
	 * @param integer $post_count order of the post in the posts loop.
	 * @return void
	 */
	public static function insert_inline_prompt_in_archive_pages( $post_count ) {
		global $wp_query;

		$archives_popups = array_filter( self::popups_for_post(), [ 'Newspack_Popups_Model', 'should_be_inserted_in_archive_pages' ] );
		foreach ( $archives_popups as $popup ) {
			// insert popup only on selected archive page types.
			if ( is_category() && ! in_array( 'category', $popup['options']['archive_page_types'] )
				|| ( is_tag() && ! in_array( 'tag', $popup['options']['archive_page_types'] ) )
				|| ( is_author() && ! in_array( 'author', $popup['options']['archive_page_types'] ) )
				|| ( is_date() && ! in_array( 'date', $popup['options']['archive_page_types'] ) )
				|| ( is_post_type_archive() && ! in_array( 'post-type', $popup['options']['archive_page_types'] ) )
				|| ( is_tax() && ! in_array( 'taxonomy', $popup['options']['archive_page_types'] ) )
			) {
					return;
			}

			$archive_insertion_posts_count = intval( $popup['options']['archive_insertion_posts_count'] );
			// insert after archive_insertion_posts_count articles
			// or every archive_insertion_posts_count posts if prompt set to repeated
			// or at the end if the total posts count is less than the trigger count.
			if ( $post_count === $archive_insertion_posts_count
				|| ( $popup['options']['archive_insertion_is_repeating'] && 0 === $post_count % $archive_insertion_posts_count )
				|| ( $archive_insertion_posts_count >= $wp_query->post_count && $post_count === $wp_query->post_count )
			) {
				echo Newspack_Popups_Model::generate_popup( $popup ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}

	/**
	 * Enqueue the assets needed to display the popups.
	 */
	public static function enqueue_scripts() {
		if ( defined( 'IS_TEST_ENV' ) && IS_TEST_ENV ) {
			return;
		}
		$is_amp = function_exists( 'is_amp_endpoint' ) && is_amp_endpoint();
		if ( ! $is_amp ) {
			wp_register_script(
				'newspack-popups-view',
				plugins_url( '../dist/view.js', __FILE__ ),
				[ 'wp-dom-ready', 'wp-url' ],
				filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/view.js' ),
				true
			);
			wp_enqueue_script( 'newspack-popups-view' );
		}

		\wp_register_style(
			'newspack-popups-view',
			plugins_url( '../dist/view.css', __FILE__ ),
			null,
			filemtime( dirname( NEWSPACK_POPUPS_PLUGIN_FILE ) . '/dist/view.css' )
		);
		\wp_style_add_data( 'newspack-popups-view', 'rtl', 'replace' );
		\wp_enqueue_style( 'newspack-popups-view' );
	}

	/**
	 * The popup shortcode function.
	 * Primarily, the shortcode is inserted by the plugin, but it may also be inserted manually to
	 * display a specific popup anywhere on the site.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return HTML
	 */
	public static function popup_shortcode( $atts = array() ) {
		if ( isset( $atts['id'] ) ) {
			$include_unpublished = Newspack_Popups::is_preview_request();
			$found_popup         = Newspack_Popups_Model::retrieve_popup_by_id( $atts['id'], $include_unpublished );
		}
		if ( ! $found_popup ) {
			return;
		}

		if (
			// Bail if it should not be displayed.
			! self::should_display( $found_popup ) ||
			// Only inline or manual-only popups can be inserted via the shortcode.
			( ! Newspack_Popups_Model::is_inline( $found_popup ) && ! Newspack_Popups_Model::is_manual_only( $found_popup ) )
		) {
			return;
		}

		// Wrapping the inline popup in an aside element prevents the markup from being mangled
		// if the shortcode is the first block.
		return '<aside>' . Newspack_Popups_Model::generate_popup( $found_popup ) . '</aside>';
	}

	/**
	 * Create the popup definition for sending to the API.
	 *
	 * @param object $popup A popup.
	 */
	public static function create_single_popup_access_payload( $popup ) {
		$popup_id_string = Newspack_Popups_Model::canonize_popup_id( esc_attr( $popup['id'] ) );
		$frequency       = $popup['options']['frequency'];
		$is_overlay      = Newspack_Popups_Model::is_overlay( $popup );
		$is_above_header = Newspack_Popups_Model::is_above_header( $popup );
		$type            = 'i';

		if ( $is_overlay ) {
			$type = 'o';

			if ( 'always' === $frequency ) {
				$frequency = 'once';
			}
		}

		if ( $is_above_header ) {
			$type = 'a';
		}

		$popup_payload = [
			'id'  => $popup_id_string,
			'f'   => $frequency,
			'utm' => $popup['options']['utm_suppression'],
			's'   => $popup['options']['selected_segment_id'],
			'n'   => \Newspack_Popups_Model::has_newsletter_prompt( $popup ),
			'd'   => \Newspack_Popups_Model::has_donation_block( $popup ),
			't'   => $type,
		];

		if ( Newspack_Popups_Custom_Placements::is_custom_placement_or_manual( $popup ) ) {
			$popup_payload['c'] = $popup['options']['placement'];
		}

		return $popup_payload;
	}

	/**
	 * Add amp-access header code.
	 *
	 * The amp-access endpoint is also responsible for reporting visits, in order to minimise
	 * the number of requests. For this reason it is placed on every page, not only those
	 * with popups.
	 * All active popups will be retrieved. This is because amp-access has to be inserted in the head,
	 * and at this time it's yet unknown which popups will be on the page. Popups can be retrieved for
	 * the rendered page (popups_for_post method), but they can also be placed in widgets, which
	 * make reliable retrieval problematic.
	 */
	public static function insert_popups_amp_access() {
		if ( ! Newspack_Popups_Segmentation::is_tracking() ) {
			return;
		}
		$popups = array_filter(
			Newspack_Popups_Model::retrieve_popups(
				// Include drafts if it's a preview request.
				Newspack_Popups::is_preview_request()
			),
			[ __CLASS__, 'should_display' ]
		);

		// Prevent duplicates - a popup might be duplicated in a shortcode.
		$unique_ids = [];
		$popups     = array_filter(
			$popups,
			function ( $item ) use ( &$unique_ids ) {
				$id = $item['id'];
				if ( in_array( $id, $unique_ids ) ) {
					return false;
				}
				$unique_ids[] = $id;
				return true;
			}
		);
		// Sort the array, so the segmented popups come first. This is necessary for proper
		// prioritisation of single-popup placements (e.g. above header).
		uasort(
			$popups,
			function( $popup_a, $popup_b ) {
				$a_has_segments = ! empty( $popup_a['options']['selected_segment_id'] );
				$b_has_segments = ! empty( $popup_b['options']['selected_segment_id'] );
				if ( $a_has_segments && $b_has_segments ) {
					return 0;
				}
				return $a_has_segments && false === $b_has_segments ? -1 : 1;
			}
		);

		// "Escape hatch" if there's a need to block adding amp-access for pages that have no prompts.
		if ( apply_filters( 'newspack_popups_suppress_insert_amp_access', false, $popups ) ) {
			return;
		}

		$popups_access_provider = [
			'namespace'     => 'popups',
			'authorization' => esc_url( Newspack_Popups_Model::get_reader_endpoint() ) . '?cid=CLIENT_ID(' . Newspack_Popups_Segmentation::NEWSPACK_SEGMENTATION_CID_NAME . ')',
			'noPingback'    => true,
		];

		$popups_configs = [];
		foreach ( $popups as $popup ) {
			$popups_configs[] = self::create_single_popup_access_payload( $popup );
		}

		$categories   = get_the_category();
		$category_ids = '';
		if ( ! empty( $categories ) ) {
			$category_ids = implode(
				',',
				array_map(
					function( $cat ) {
						return $cat->term_id;
					},
					$categories
				)
			);
		}

		$settings                                 = array_reduce(
			\Newspack_Popups_Settings::get_settings(),
			function ( $acc, $item ) {
				$key       = $item['key'];
				$acc->$key = $item['value'];
				return $acc;
			},
			(object) []
		);
		$popups_access_provider['authorization'] .= '&ref=DOCUMENT_REFERRER';
		$popups_access_provider['authorization'] .= '&popups=' . wp_json_encode( $popups_configs );
		$popups_access_provider['authorization'] .= '&settings=' . wp_json_encode( $settings );
		$popups_access_provider['authorization'] .= '&visit=' . wp_json_encode(
			[
				'post_id'    => esc_attr( get_the_ID() ),
				'categories' => esc_attr( $category_ids ),
				'is_post'    => is_single(),
			]
		);
		$view_as_spec                             = Newspack_Popups_View_As::viewing_as_spec();
		if ( $view_as_spec ) {
			$popups_access_provider['authorization'] .= '&view_as=' . wp_json_encode( $view_as_spec );
		}
		if ( isset( $_GET['newspack-campaigns-debug'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$popups_access_provider['authorization'] .= '&debug';
		}

		?>
		<script id="amp-access" type="application/json">
			<?php echo wp_json_encode( $popups_access_provider ); ?>
		</script>
		<?php
	}

	/**
	 * Disable popups on posts and pages which have newspack_popups_has_disabled_popups.
	 *
	 * @return bool True if popups should be disabled for current page.
	 */
	public static function assess_has_disabled_popups() {
		return apply_filters( 'newspack_popups_assess_has_disabled_popups', false );
	}

	/**
	 * Register and enqueue all required AMP scripts, if needed.
	 */
	public static function register_amp_scripts() {
		if ( self::assess_has_disabled_popups() ) {
			return;
		}
		if ( ! is_admin() && ! wp_script_is( 'amp-runtime', 'registered' ) ) {
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_register_script(
				'amp-runtime',
				'https://cdn.ampproject.org/v0.js',
				null,
				null,
				true
			);
		}
		$scripts = [ 'amp-access', 'amp-animation', 'amp-bind', 'amp-position-observer' ];
		foreach ( $scripts as $script ) {
			if ( ! wp_script_is( $script, 'registered' ) ) {
				$path = "https://cdn.ampproject.org/v0/{$script}-latest.js";
				// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
				wp_register_script(
					$script,
					$path,
					array( 'amp-runtime' ),
					null,
					true
				);
			}
			wp_enqueue_script( $script );
		}
	}

	/**
	 * Look for popup shortcodes in a string and return their IDs.
	 *
	 * @param string $string String to assess.
	 * @return array Found shortcoded popups IDs.
	 */
	public static function get_shortcoded_popups_ids( $string ) {
		$parsed_blocks = parse_blocks( $string );

		$single_prompt_blocks    = array_filter(
			$parsed_blocks,
			function( $block ) {
				return 'newspack-popups/single-prompt' === $block['blockName'];
			}
		);
		$single_prompt_block_ids = array_reduce(
			$single_prompt_blocks,
			function( $acc, $popup ) {
				if ( ! empty( $popup['attrs']['promptId'] ) ) {
					$acc[] = intval( $popup['attrs']['promptId'] );
				}
				return $acc;
			},
			[]
		);

		preg_match_all( '/\[newspack-popup .*\]/', $string, $popup_shortcodes_in_content );

		if ( empty( $popup_shortcodes_in_content ) ) {
			return [];
		} else {
			return array_unique(
				array_merge(
					$single_prompt_block_ids,
					array_map(
						function ( $item ) {
							preg_match( '/id=["|\'](\d*)/', $item, $matches );
							if ( empty( $matches ) ) {
								return null;
							} else {
								return $matches[1];
							}
						},
						$popup_shortcodes_in_content[0]
					)
				)
			);
		}
	}

	/**
	 * If a prompt is assigned the given taxonomy, it should only be shown on posts/pages with at least one matching term.
	 * If the prompt has no terms, it should be shown regardless of the post's terms.
	 *
	 * @param object $popup The prompt to assess.
	 * @param string $taxonomy The type of taxonomy to match.
	 *
	 * @return bool Whether the prompt should be shown based on matching terms.
	 */
	public static function assess_taxonomy_filter( $popup, $taxonomy = 'category' ) {
		$post_terms     = get_the_terms( get_the_ID(), $taxonomy );
		$post_terms_ids = array_column( $post_terms ? $post_terms : [], 'term_id' );

		// Check if a post term is excluded on the popup options.
		if ( 'category' === $taxonomy ) {
			foreach ( $popup['options']['excluded_categories'] as $category_excluded_id ) {
				if ( in_array( $category_excluded_id, $post_terms_ids ) ) {
					return false;
				}
			}
		}

		if ( 'post_tag' === $taxonomy ) {
			foreach ( $popup['options']['excluded_tags'] as $post_tag_excluded_id ) {
				if ( in_array( $post_tag_excluded_id, $post_terms_ids ) ) {
					return false;
				}
			}
		}

		$popup_terms = get_the_terms( $popup['id'], $taxonomy );
		if ( false === $popup_terms ) {
			return true; // No terms on the popup, no need to compare.
		}
		return array_intersect(
			array_column( $post_terms ? $post_terms : [], 'term_id' ),
			array_column( $popup_terms, 'term_id' )
		);
	}

	/**
	 * Should Popup be rendered, based on universal conditions.
	 *
	 * @param object $popup The popup to assess.
	 * @param bool   $check_if_is_post Should the post type of post be taken into account.
	 * @return bool Should popup be shown.
	 */
	public static function should_display( $popup, $check_if_is_post = false ) {
		$post_type = get_post_type();
		// Inline prompts may not be rendered on pages, unless they are above-header.
		if (
			$check_if_is_post &&
			'page' === $post_type &&
			( Newspack_Popups_Model::is_inline( $popup ) && ! Newspack_Popups_Model::is_above_header( $popup ) )
		) {
			return false;
		}

		// Unless it's a preview request, perform some additional checks.
		if ( ! Newspack_Popups::is_preview_request() ) {
			// Hide prompts for admin users.
			if ( Newspack_Popups::is_user_admin() ) {
				return false;
			}
			// Hide overlay prompts in non-interactive mode, for non-admin users.
			if ( ! Newspack_Popups::is_user_admin() && Newspack_Popups_Settings::is_non_interactive() && ! Newspack_Popups_Model::is_inline( $popup ) ) {
				return false;
			}
		}

		// Prompts should be hidden on account related pages (e.g. password reset page).
		if ( Newspack_Popups::is_account_related_post( get_post() ) ) {
			return false;
		}
		// Custom and manual placements should override context conditions, since they are placed arbitrarily.
		if ( Newspack_Popups_Custom_Placements::is_custom_placement_or_manual( $popup ) ) {
			return true;
		}

		// Context in which the popup appears.
		// 1. the taxonomy of the post.
		$is_taxonomy_matching = self::assess_taxonomy_filter( $popup, 'category' ) && self::assess_taxonomy_filter( $popup, 'post_tag' );
		// 2. the type of the post supported by this popup, if different than the global setting.
		$popup_post_types = $popup['options']['post_types'];

		$default_post_types = Newspack_Popups_Model::get_default_popup_post_types();

		sort( $popup_post_types );
		sort( $default_post_types );
		if ( $popup_post_types === $default_post_types ) {
			// Popup's post types are the same as default - global post types should be used.
			$supported_post_types = Newspack_Popups_Model::get_globally_supported_post_types();
		} else {
			// Popup's post types are *set* - different than defaults. These should override the global post types.
			$supported_post_types = $popup_post_types;
		}
		$is_post_context_matching = $is_taxonomy_matching && in_array( $post_type, $supported_post_types );

		return $is_post_context_matching;
	}
}
$newspack_popups_inserter = new Newspack_Popups_Inserter();
