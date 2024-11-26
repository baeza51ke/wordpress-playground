<?php

use WordPress\AsyncHTTP\Client;
use WordPress\AsyncHTTP\Request;

/**
 * Idea:
 * * Stream-process the WXR file.
 * * Frontload all the assets before processing the posts – in an idempotent
 *   and re-entrant way.
 * * Import the posts, rewrite the URLs and IDs before inserting anything.
 * * Never do any post-processing at the database level after inserting. That's
 *   too slow for large datasets.
 *
 * @TODO:
 * ✅ Re-entrant import
 * * Log errors for the user to decide what to do.
 * * Log progress information.
 * * Error out if `source_site_url` is not set by the time we're processing
 *   the first encountered URL.
 * * Disable anything remotely related to KSES during the import. KSES
 *   modifies and often corrupts the content, and it also slows down the
 *   import. If we don't trust the imported content, we have larger problems
 *   than some escaping.
 * * Research which other filters are also worth disabling during the import.
 *   What would be a downside of disabling ALL the filters except the ones
 *   registered by WordPress Core? The upside would be preventing plugins from
 *   messing with the imported content. The downside would be the same. What else?
 *   Perhaps that could be a choice and left up to the API consumer?
 */
class WP_Stream_Importer {

	/**
	 * Populated from the WXR file's <wp:base_blog_url> tag.
	 */
	private $source_site_url;
	private $entity_iterator_factory;
	/**
	 * @param array|string|null $query {
	 *     @type string      $uploads_path  The directory to download the media attachments to.
	 *                                      E.g. WP_CONTENT_DIR . '/uploads'
	 *     @type string      $uploads_url   The URL where the media attachments will be accessible
	 *                                      after the import. E.g. http://127.0.0.1:9400/wp-content/uploads/
	 * }
	 */
	private $options;

	const STAGE_INITIAL          = '#initial';
	const STAGE_TOPOLOGICAL_SORT = '#topological_sort';
	const STAGE_FRONTLOAD_ASSETS = '#frontload_assets';
	const STAGE_IMPORT_ENTITIES  = '#import_entities';
	const STAGE_FINISHED         = '#finished';

	/**
	 * The current state of the import process.
	 * @var string
	 */
	private $stage = self::STAGE_INITIAL;

	/**
	 * Iterator that streams entities to import.
	 */
	private $entity_iterator;
	private $resume_at_entity;
	/**
	 * A map of currently downloaded resources for each entity in
	 * the following format:
	 *
	 * [$entity_cursor => [$resource_id => true]]
	 *
	 * @var array<string,array<string,bool>>
	 */
	private $active_downloads = array();
	private $downloader;

	/**
	 * @var WP_Topological_Sorter
	 */
	private $topological_sorter;

	public static function create_for_wxr_file( $wxr_path, $options = array(), $cursor = null ) {
		return static::create(
			function ( $cursor = null ) use ( $wxr_path ) {
				return WP_WXR_Reader::create( new WP_File_Reader( $wxr_path ), $cursor );
			},
			$options,
			$cursor
		);
	}

	public static function create_for_wxr_url( $wxr_url, $options = array(), $cursor = null ) {
		return static::create(
			function ( $cursor = null ) use ( $wxr_url ) {
				return WP_WXR_Reader::create( new WP_Remote_File_Reader( $wxr_url ), $cursor );
			},
			$options,
			$cursor
		);
	}

	public static function create(
		$entity_iterator_factory,
		$options = array(),
		$cursor = null
	) {
		$options  = static::parse_options( $options );
		$importer = new WP_Stream_Importer( $entity_iterator_factory, $options );
		if ( null !== $cursor && true !== $importer->initialize_from_cursor( $cursor ) ) {
			return false;
		}
		return $importer;
	}

	public function get_reentrancy_cursor() {
		return json_encode(
			array(
				'state' => $this->stage,
				'resume_at_entity' => $this->resume_at_entity,
			)
		);
	}

	private function initialize_from_cursor( $cursor ) {
		$cursor = json_decode( $cursor, true );
		if ( ! is_array( $cursor ) ) {
			_doing_it_wrong( __METHOD__, 'Cannot resume an importer with a non-array cursor.', '1.0.0' );
			return false;
		}
		$this->stage            = $cursor['state'];
		$this->resume_at_entity = $cursor['resume_at_entity'];
		return true;
	}

	private static function parse_options( $options ) {
		if ( ! isset( $options['new_site_url'] ) ) {
			$options['new_site_url'] = get_site_url();
		}

		if ( ! isset( $options['uploads_path'] ) ) {
			$options['uploads_path'] = WP_CONTENT_DIR . '/uploads';
		}
		// Remove the trailing slash to make concatenation easier later.
		$options['uploads_path'] = rtrim( $options['uploads_path'], '/' );

		if ( ! isset( $options['uploads_url'] ) ) {
			$options['uploads_url'] = $options['new_site_url'] . '/wp-content/uploads';
		}
		// Remove the trailing slash to make concatenation easier later.
		$options['uploads_url'] = rtrim( $options['uploads_url'], '/' );

		return $options;
	}

	private function __construct(
		$entity_iterator_factory,
		$options = array()
	) {
		$this->entity_iterator_factory = $entity_iterator_factory;
		$this->options                 = $options;
		if ( isset( $options['source_site_url'] ) ) {
			$this->source_site_url = $options['source_site_url'];
		}
	}

	/**
	 * The WordPress entity importer instance.
	 * @TODO: Consider inlining the importer code into this class.
	 *
	 * @var WP_Entity_Importer
	 */
	private $importer;

	public function next_step() {
		switch ( $this->stage ) {
			case self::STAGE_INITIAL:
				$this->stage = self::STAGE_TOPOLOGICAL_SORT;
				return true;
			case self::STAGE_TOPOLOGICAL_SORT:
				// @TODO: Topologically sort the entities.
				$this->stage = self::STAGE_FRONTLOAD_ASSETS;
				return true;
			case self::STAGE_FRONTLOAD_ASSETS:
				$this->next_frontloading_step();
				return true;
			case self::STAGE_IMPORT_ENTITIES:
				$this->import_next_entity();
				return true;
			case self::STAGE_FINISHED:
				return false;
		}
	}

	/**
	 * Get the current stage.
	 *
	 * @return string
	 */
	public function get_current_stage() {
		return $this->stage;
	}

	/**
	 * Advance the cursor to the oldest finished download. For example:
	 *
	 * * We've started downloading files A, B, C, and D in this order.
	 * * D is the first to finish. We don't do anything yet.
	 * * A finishes next. We advance the cursor to A.
	 * * C finishes next. We don't do anything.
	 * * Then we pause.
	 *
	 * When we resume, we'll start where we left off, which is after A. The
	 * downloader will enqueue B for download and will skip C and D since
	 * the relevant files already exist in the filesystem.
	 */
	private function frontloading_advance_reentrancy_cursor() {
		while ( $this->downloader->next_event() ) {
			$event = $this->downloader->get_event();
			switch ( $event->type ) {
				case WP_Attachment_Downloader_Event::SUCCESS:
				case WP_Attachment_Downloader_Event::FAILURE:
					foreach ( array_keys( $this->active_downloads ) as $entity_cursor ) {
						unset( $this->active_downloads[ $entity_cursor ][ $event->resource_id ] );
					}
					break;
			}
		}

		while ( count( $this->active_downloads ) > 0 ) {
			$oldest_download_cursor = key( $this->active_downloads );
			$downloads_completed    = empty( $this->active_downloads[ $oldest_download_cursor ] );
			if ( ! $downloads_completed ) {
				break;
			}
			// Advance the cursor to the next entity.
			$this->resume_at_entity = $oldest_download_cursor;
			unset( $this->active_downloads[ $oldest_download_cursor ] );
		}
	}

	/**
	 * Downloads all the assets referenced in the imported entities.
	 *
	 * This method is idempotent, re-entrant, and should be called
	 * before import_entities() so that every inserted post already has
	 * all its attachments downloaded.
	 */
	private function next_frontloading_step() {
		if ( null === $this->entity_iterator ) {
			$this->entity_iterator    = $this->create_entity_iterator();
			$this->topological_sorter = new WP_Topological_Sorter();
			$this->downloader         = new WP_Attachment_Downloader( $this->options );
		}

		$this->frontloading_advance_reentrancy_cursor();

		// We're done if all the entities are processed and all the downloads are finished.
		if ( ! $this->entity_iterator->valid() && ! $this->downloader->has_pending_requests() ) {
			// This is an assertion to make double sure we're emptying the state queue.
			if ( ! empty( $this->active_downloads ) ) {
				_doing_it_wrong( __METHOD__, 'Frontloading queue is not empty.', '1.0' );
			}

			print_r( $this->topological_sorter->mapping );

			$this->stage              = self::STAGE_IMPORT_ENTITIES;
			$this->topological_sorter = null;
			$this->downloader         = null;
			$this->active_downloads   = array();
			$this->entity_iterator    = null;
			$this->resume_at_entity   = null;
			return false;
		}

		// Poll the bytes between scheduling new downloads.
		$only_downloader_pending = ! $this->entity_iterator->valid() && $this->downloader->has_pending_requests();
		if ( $this->downloader->queue_full() || $only_downloader_pending ) {
			/**
			 * @TODO:
			 * * Process and store failures.
			 *   E.g. what if the attachment is not found? Error out? Ignore? In a UI-based
			 *   importer scenario, this is the time to log a failure to let the user
			 *   fix it later on. In a CLI-based Blueprint step importer scenario, we
			 *   might want to provide an "image not found" placeholder OR ignore the
			 *   failure.
			 *
			 * @TODO: Update the download progress:
			 * * After every downloaded file.
			 * * For large files, every time a full megabyte is downloaded above 10MB.
			 */
			return $this->downloader->poll();
		}

		/**
		 * Identify the static assets referenced in the current entity
		 * and enqueue them for download.
		 */
		$entity                            = $this->entity_iterator->current();
		$cursor                            = $this->entity_iterator->get_reentrancy_cursor();
		$this->active_downloads[ $cursor ] = array();

		$data     = $entity->get_data();
		$upstream = $this->entity_iterator->get_upstream();

		switch ( $entity->get_type() ) {
			case 'category':
			case 'term':
				$this->topological_sorter->map_term( $upstream, $data );
				break;
			case 'site_option':
				if ( $data['option_name'] === 'home' ) {
					$this->source_site_url = $data['option_value'];
				}
				break;
			case 'post':
				$this->topological_sorter->map_post( $upstream, $data );

				if ( isset( $data['post_type'] ) && $data['post_type'] === 'attachment' ) {
					$this->enqueue_attachment_download( $data['attachment_url'], null );
				} elseif ( isset( $data['post_content'] ) ) {
					$post = $data;
					$p    = new WP_Block_Markup_Url_Processor( $post['post_content'], $this->source_site_url );
					while ( $p->next_url() ) {
						if ( ! $this->url_processor_matched_asset_url( $p ) ) {
							continue;
						}
						$this->enqueue_attachment_download(
							$p->get_raw_url(),
							$post['source_path'] ?? $post['slug'] ?? null
						);
					}
				}
				break;
		}

		/**
		 * @TODO: Update the progress information.
		 * @TODO: Save the freshly requested URLs to the cursor.
		 */

		// Move on to the next entity.
		$this->entity_iterator->next();

		$this->frontloading_advance_reentrancy_cursor();
		return true;
	}

	/**
	 * @TODO: Explore a way of making this idempotent. Maybe
	 *        use GUIDs to detect whether a post or an attachment
	 *        has already been imported? That would be slow on
	 *        large datasets, but maybe it could be a choice for
	 *        the API consumer?
	 */
	private function import_next_entity() {
		if ( null === $this->entity_iterator ) {
			$this->entity_iterator = $this->create_entity_iterator();
			$this->importer        = new WP_Entity_Importer();
		}

		if ( ! $this->entity_iterator->valid() ) {
			// We're done.
			$this->stage           = self::STAGE_FINISHED;
			$this->entity_iterator = null;
			$this->importer        = null;
			return;
		}

		$entity      = $this->entity_iterator->current();
		$attachments = array();
		// Rewrite the URLs in the post.
		switch ( $entity->get_type() ) {
			case 'post':
				$data = $entity->get_data();
				foreach ( array( 'guid', 'post_content', 'post_excerpt' ) as $key ) {
					if ( ! isset( $data[ $key ] ) ) {
						continue;
					}
					$p = new WP_Block_Markup_Url_Processor( $data[ $key ], $this->source_site_url );
					while ( $p->next_url() ) {
						if ( $this->url_processor_matched_asset_url( $p ) ) {
							$filename      = $this->new_asset_filename( $p->get_raw_url() );
							$new_asset_url = $this->options['uploads_url'] . '/' . $filename;
							$p->replace_base_url( WP_URL::parse( $new_asset_url ) );
							$attachments[] = $new_asset_url;
							/**
							 * @TODO: How would we know a specific image block refers to a specific
							 *        attachment? We need to cross-correlate that to rewrite the URL.
							 *        The image block could have query parameters, too, but presumably the
							 *        path would be the same at least? What if the same file is referred
							 *        to by two different URLs? e.g. assets.site.com and site.com/assets/ ?
							 *        A few ideas: GUID, block attributes, fuzzy matching. Maybe a configurable
							 *        strategy? And the API consumer would make the decision?
							 */
						} elseif ( $this->source_site_url &&
							$p->get_parsed_url() &&
							url_matches( $p->get_parsed_url(), $this->source_site_url )
						) {
							$p->replace_base_url( WP_URL::parse( $this->options['new_site_url'] ) );
						} else {
							// Ignore other URLs.
						}
					}
					$data[ $key ] = $p->get_updated_html();
				}
				$entity->set_data( $data );
				break;
		}

		// @TODO: Monitor failures.
		$post_id = $this->importer->import_entity( $entity );
		foreach ( $attachments as $filepath ) {
			// @TODO: Monitor failures.
			$this->importer->import_attachment( $filepath, $post_id );
		}

		/**
		 * @TODO: Update the progress information.
		 */
		$this->resume_at_entity = $this->entity_iterator->get_reentrancy_cursor();
		$this->entity_iterator->next();
	}

	private function enqueue_attachment_download( string $raw_url, $context_path = null ) {
		$url            = $this->rewrite_attachment_url( $raw_url, $context_path );
		$asset_filename = $this->new_asset_filename( $raw_url );
		$output_path    = $this->options['uploads_path'] . '/' . ltrim( $asset_filename, '/' );

		$enqueued = $this->downloader->enqueue_if_not_exists( $url, $output_path );
		if ( $enqueued ) {
			$resource_id   = $this->downloader->get_enqueued_resource_id();
			$entity_cursor = $this->entity_iterator->get_reentrancy_cursor();
			$this->active_downloads[ $entity_cursor ][ $resource_id ] = true;
		}
		return $enqueued;
	}

	/**
	 * The downloaded file name is based on the URL hash.
	 *
	 * Download the asset to a new path.
	 *
	 * Note the path here is different than on the original site.
	 * There isn't an easy way to preserve the original assets paths on
	 * the new site.
	 *
	 * * The assets may come from multiple domains
	 * * The paths may be outside of `/wp-content/uploads/`
	 * * The same path on multiple domains may point to different files
	 *
	 * Even if we tried to preserve the paths starting with `/wp-content/uploads/`,
	 * we would run into race conditions where, in case of overlapping paths,
	 * the first downloaded asset would win.
	 *
	 * The assets downloader is meant to be idempotent, deterministic, and re-entrant.
	 *
	 * Therefore, instead of trying to preserve the original paths, we'll just
	 * compute an idempotent and deterministic new path for each asset.
	 *
	 * While using a content hash is tempting, it has two downsides:
	 * * We'd need to download the asset before computing the hash.
	 * * It would de-duplicate the imported assets even if they have
	 *   different URLs. This would cause subtle issues in the new sites.
	 *   Imagine two users uploading the same image. Each user has
	 *   different permissions. Just because Bob deletes his copy, doesn't
	 *   mean we should delete Alice's copy.
	 */
	private function new_asset_filename( string $raw_asset_url ) {
		$filename   = md5( $raw_asset_url );
		$parsed_url = WP_URL::parse( $raw_asset_url );
		if ( false !== $parsed_url ) {
			$pathname = $parsed_url->pathname;
		} else {
			// Assume $raw_asset_url is a relative path when it cannot be
			// parsed as an absolute URL.
			$pathname = $raw_asset_url;
		}
		$extension = pathinfo( $pathname, PATHINFO_EXTENSION );
		if ( ! empty( $extension ) ) {
			$filename .= '.' . $extension;
		}
		return $filename;
	}

	private function rewrite_attachment_url( string $raw_url, $context_path = null ) {
		if ( WP_URL::can_parse( $raw_url ) ) {
			// Absolute URL, nothing to do.
			return $raw_url;
		}
		$base_url = $this->source_site_url;
		if ( null !== $base_url && null !== $context_path ) {
			$base_url = $base_url . '/' . ltrim( $context_path, '/' );
		}
		$parsed_url = WP_URL::parse( $raw_url, $base_url );
		if ( false === $parsed_url ) {
			return false;
		}
		return $parsed_url->toString();
	}

	/**
	 * By default, we want to download all the assets referenced in the
	 * posts that are hosted on the source site.
	 *
	 * @TODO: How can we process the videos?
	 * @TODO: What other asset types are there?
	 */
	private function url_processor_matched_asset_url( WP_Block_Markup_Url_Processor $p ) {
		return (
			$p->get_tag() === 'IMG' &&
			$p->get_inspected_attribute_name() === 'src' &&
			( ! $this->source_site_url || url_matches( $p->get_parsed_url(), $this->source_site_url ) )
		);
	}

	private function create_entity_iterator() {
		$factory = $this->entity_iterator_factory;
		return $factory( $this->resume_at_entity );
	}
}
