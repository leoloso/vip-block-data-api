<?php
/**
 * Content Parser
 *
 * @package vip-block-data-api
 */

namespace WPCOMVIP\BlockDataApi;

defined( 'ABSPATH' ) || die();

use Throwable;
use WP_Error;
use WP_Block_Type;
use WP_Block_Type_Registry;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The content parser that would be used to transform a post into an array of blocks, along with their attributes.
 */
class ContentParser {
	/**
	 * Block registry instance
	 *
	 * @var WP_Block_Type_Registry
	 *
	 * @access private
	 */
	protected $block_registry;
	/**
	 * Post ID
	 *
	 * @var int
	 *
	 * @access private
	 */
	protected $post_id;
	/**
	 * Warnings that would be returned with the blocks
	 *
	 * @var array
	 *
	 * @access private
	 */
	protected $warnings = [];

	/**
	 * Initialize the ContentParser class.
	 *
	 * @param WP_Block_Type_Registry|null $block_registry the block registry instance.
	 */
	public function __construct( $block_registry = null ) {
		if ( null === $block_registry ) {
			$block_registry = WP_Block_Type_Registry::get_instance();
		}

		$this->block_registry = $block_registry;
	}

	/**
	 * Filter out a block from the blocks output based on:
	 *
	 * - include parameter, if it is set or
	 * - exclude parameter, if it is set.
	 *
	 * and finally, based on a filter vip_block_data_api__allow_block
	 *
	 * @param array  $block Current block.
	 * @param string $block_name Name of the block.
	 * @param array  $filter_options Options to be used for filtering, if any.
	 *
	 * @return bool true, if the block should be included or false otherwise
	 *
	 * @access private
	 */
	public function should_block_be_included( $block, $block_name, $filter_options ) {
		$is_block_included = true;

		if ( ! empty( $filter_options['include'] ) ) {
			$is_block_included = in_array( $block_name, $filter_options['include'] );
		} elseif ( ! empty( $filter_options['exclude'] ) ) {
			$is_block_included = ! in_array( $block_name, $filter_options['exclude'] );
		}

		/**
		 * Filter out blocks from the blocks output
		 *
		 * @param bool   $is_block_included True if the block should be included, or false to filter it out.
		 * @param string $block_name   Name of the parsed block, e.g. 'core/paragraph'.
		 * @param string $block         Result of parse_blocks() for this block.
		 *                              Contains 'blockName', 'attrs', 'innerHTML', and 'innerBlocks' keys.
		 */
		return apply_filters( 'vip_block_data_api__allow_block', $is_block_included, $block_name, $block );
	}

	/**
	 * Parses a post's content and returns an array of blocks with their attributes and inner blocks.
	 *
	 * @param string   $post_content HTML content of a post.
	 * @param int|null $post_id ID of the post being parsed. Required for blocks containing meta-sourced attributes and some block filters.
	 * @param array    $filter_options An associative array of options for filtering blocks. Can contain keys:
	 *                 'exclude': An array of block names to block from the response.
	 *                 'include': An array of block names that are allowed in the response.
	 *
	 * @return array|WP_Error
	 */
	public function parse( $post_content, $post_id = null, $filter_options = [] ) {
		Analytics::record_usage();

		if ( isset( $filter_options['exclude'] ) && isset( $filter_options['include'] ) ) {
			return new WP_Error( 'vip-block-data-api-invalid-params', 'Cannot provide blocks to exclude and include at the same time', [ 'status' => 400 ] );
		}

		$this->post_id  = $post_id;
		$this->warnings = [];

		$has_blocks = has_blocks( $post_content );

		if ( ! $has_blocks ) {
			$error_message = join(' ', [
				sprintf( 'Error parsing post ID %d: This post does not appear to contain block content.', $post_id ),
				'The VIP Block Data API is designed to parse Gutenberg blocks and can not read classic editor content.',
			] );

			return new WP_Error( 'vip-block-data-api-no-blocks', $error_message, [ 'status' => 400 ] );
		}

		$parsing_error = false;

		try {
			$blocks = parse_blocks( $post_content );
			$blocks = array_values( array_filter( $blocks, function ( $block ) {
				$is_whitespace_block = ( null === $block['blockName'] && empty( trim( $block['innerHTML'] ) ) );
				return ! $is_whitespace_block;
			} ) );

			$registered_blocks = $this->block_registry->get_all_registered();

			$sourced_blocks = array_map(function ( $block ) use ( $registered_blocks, $filter_options ) {
				return $this->source_block( $block, $registered_blocks, $filter_options );
			}, $blocks);

			$sourced_blocks = array_values( array_filter( $sourced_blocks ) );

			$result = [
				'blocks' => $sourced_blocks,
			];

			if ( ! empty( $this->warnings ) ) {
				$result['warnings'] = $this->warnings;
			}

			// Debug output.
			if ( $this->is_debug_enabled() ) {
				$result['debug'] = [
					'blocks_parsed' => $blocks,
					'content'       => $post_content,
				];
			}
		} catch ( Throwable $error ) {
			$parsing_error = $error;
		}

		if ( $parsing_error ) {
			$error_message = sprintf( 'Error parsing post ID %d: %s', $post_id, $parsing_error->getMessage() );
			return new WP_Error( 'vip-block-data-api-parser-error', $error_message, [
				'status'  => 500,
				'details' => $parsing_error->__toString(),
			] );
		} else {
			return $result;
		}
	}

	/**
	 * Processes a single block, and returns the sourced block data.
	 *
	 * @param array           $block Block to be processed.
	 * @param WP_Block_Type[] $registered_blocks Blocks that have been registered.
	 * @param array           $filter_options Options to filter using, if any.
	 *
	 * @return array|null
	 *
	 * @access private
	 */
	protected function source_block( $block, $registered_blocks, $filter_options ) {
		$block_name = $block['blockName'];

		if ( ! $this->should_block_be_included( $block, $block_name, $filter_options ) ) {
			return null;
		}

		if ( ! isset( $registered_blocks[ $block_name ] ) ) {
			$this->add_missing_block_warning( $block_name );
		}

		$block_definition            = $registered_blocks[ $block_name ] ?? null;
		$block_definition_attributes = $block_definition->attributes ?? [];

		$block_attributes = $block['attrs'];

		foreach ( $block_definition_attributes as $block_attribute_name => $block_attribute_definition ) {
			$attribute_source        = $block_attribute_definition['source'] ?? null;
			$attribute_default_value = $block_attribute_definition['default'] ?? null;

			if ( null === $attribute_source ) {
				// Unsourced attributes are stored in the block's delimiter attributes, skip DOM parser.

				if ( isset( $block_attributes[ $block_attribute_name ] ) ) {
					// Attribute is already set in the block's delimiter attributes, skip.
					continue;
				} elseif ( null !== $attribute_default_value ) {
					// Attribute is unset and has a default value, use default value.
					$block_attributes[ $block_attribute_name ] = $attribute_default_value;
					continue;
				} else {
					// Attribute is unset and has no default value, skip.
					continue;
				}
			}

			// Specify a manual doctype so that the parser will use the HTML5 parser.
			$crawler = new Crawler( sprintf( '<!doctype html><html><body>%s</body></html>', $block['innerHTML'] ) );

			// Enter the <body> tag for block parsing.
			$crawler = $crawler->filter( 'body' );

			$attribute_value = $this->source_attribute( $crawler, $block_attribute_definition );

			if ( null !== $attribute_value ) {
				$block_attributes[ $block_attribute_name ] = $attribute_value;
			}
		}

		$sourced_block = [
			'name'       => $block_name,
			'attributes' => $block_attributes,
		];

		if ( isset( $block['innerBlocks'] ) ) {
			$inner_blocks = array_map( function ( $block ) use ( $registered_blocks, $filter_options ) {
				return $this->source_block( $block, $registered_blocks, $filter_options );
			}, $block['innerBlocks'] );

			$inner_blocks = array_values( array_filter( $inner_blocks ) );

			if ( ! empty( $inner_blocks ) ) {
				$sourced_block['innerBlocks'] = $inner_blocks;
			}
		}

		if ( $this->is_debug_enabled() ) {
			$sourced_block['debug'] = [
				'block_definition_attributes' => $block_definition->attributes,
			];
		}

		/**
		 * Filters a block when parsing is complete.
		 *
		 * @param array $sourced_block An associative array of parsed block data with keys 'name' and 'attribute'.
		 * @param string $block_name Name of the parsed block, e.g. 'core/paragraph'.
		 * @param int $post_id Post ID associated with the parsed block.
		 * @param array $block Result of parse_blocks() for this block. Contains 'blockName', 'attrs', 'innerHTML', and 'innerBlocks' keys.
		 */
		$sourced_block = apply_filters( 'vip_block_data_api__sourced_block_result', $sourced_block, $block_name, $this->post_id, $block );

		// If attributes are empty, explicitly use an object to avoid encoding an empty array in JSON.
		if ( empty( $sourced_block['attributes'] ) ) {
			$sourced_block['attributes'] = (object) [];
		}

		return $sourced_block;
	}

	/**
	 * Processes the source attributes of a block.
	 *
	 * @param Symfony\Component\DomCrawler\Crawler $crawler Crawler instance.
	 * @param array                                $block_attribute_definition Definition of the block attribute.
	 *
	 *  @return array|string|null
	 *
	 * @access private
	 */
	protected function source_attribute( $crawler, $block_attribute_definition ) {
		$attribute_value         = null;
		$attribute_default_value = $block_attribute_definition['default'] ?? null;
		$attribute_source        = $block_attribute_definition['source'];

		// See block attribute sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#value-source.
		if ( 'attribute' === $attribute_source || 'property' === $attribute_source ) {
			// 'property' sources were removed in 2018. Default to attribute value.
			// https://github.com/WordPress/gutenberg/pull/8276.

			$attribute_value = $this->source_block_attribute( $crawler, $block_attribute_definition );
		} elseif ( 'html' === $attribute_source ) {
			$attribute_value = $this->source_block_html( $crawler, $block_attribute_definition );
		} elseif ( 'text' === $attribute_source ) {
			$attribute_value = $this->source_block_text( $crawler, $block_attribute_definition );
		} elseif ( 'tag' === $attribute_source ) {
			$attribute_value = $this->source_block_tag( $crawler, $block_attribute_definition );
		} elseif ( 'raw' === $attribute_source ) {
			$attribute_value = $this->source_block_raw( $crawler );
		} elseif ( 'query' === $attribute_source ) {
			$attribute_value = $this->source_block_query( $crawler, $block_attribute_definition );
		} elseif ( 'meta' === $attribute_source ) {
			$attribute_value = $this->source_block_meta( $block_attribute_definition );
		} elseif ( 'node' === $attribute_source ) {
			$attribute_value = $this->source_block_node( $crawler, $block_attribute_definition );
		} elseif ( 'children' === $attribute_source ) {
			$attribute_value = $this->source_block_children( $crawler, $block_attribute_definition );
		}

		if ( null === $attribute_value ) {
			$attribute_value = $attribute_default_value;
		}

		return $attribute_value;
	}

	/**
	 * Helper function to process the `attribute` source attribute.
	 *
	 * @param Symfony\Component\DomCrawler\Crawler $crawler Crawler instance.
	 * @param array                                $block_attribute_definition Definition of the block attribute.
	 *
	 * @return string|null
	 *
	 * @access private
	 */
	protected function source_block_attribute( $crawler, $block_attribute_definition ) {
		// 'attribute' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#attribute-source.

		$attribute_value = null;
		$attribute       = $block_attribute_definition['attribute'];
		$selector        = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() > 0 ) {
			$attribute_value = $crawler->attr( $attribute );
		}

		return $attribute_value;
	}

	/**
	 * Helper function to process the `html` source attribute.
	 *
	 * @param Symfony\Component\DomCrawler\Crawler $crawler Crawler instance.
	 * @param array                                $block_attribute_definition Definition of the block attribute.
	 *
	 * @return string|null
	 *
	 * @access private
	 */
	protected function source_block_html( $crawler, $block_attribute_definition ) {
		// 'html' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#html-source.

		$attribute_value = null;
		$selector        = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() > 0 ) {
			$multiline_selector = $block_attribute_definition['multiline'] ?? null;

			if ( null === $multiline_selector ) {
				$attribute_value = $crawler->html();
			} else {
				$multiline_parts = $crawler->filter( $multiline_selector )->each(function ( $node ) {
					return $node->outerHtml();
				});

				$attribute_value = join( '', $multiline_parts );
			}
		}

		return $attribute_value;
	}

	/**
	 * Helper function to process the `text` source attribute.
	 *
	 * @param Symfony\Component\DomCrawler\Crawler $crawler Crawler instance.
	 * @param array                                $block_attribute_definition Definition of the block attribute.
	 *
	 * @return string|null
	 *
	 * @access private
	 */
	protected function source_block_text( $crawler, $block_attribute_definition ) {
		// 'text' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#text-source.

		$attribute_value = null;
		$selector        = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() > 0 ) {
			$attribute_value = $crawler->text();
		}

		return $attribute_value;
	}

	/**
	 * Helper function to process the `query` source attribute.
	 *
	 * @param Symfony\Component\DomCrawler\Crawler $crawler Crawler instance.
	 * @param array                                $block_attribute_definition Definition of the block attribute.
	 *
	 * @return string|null
	 *
	 * @access private
	 */
	protected function source_block_query( $crawler, $block_attribute_definition ) {
		// 'query' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#query-source.

		$query_items = $block_attribute_definition['query'];
		$selector    = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		$attribute_values = $crawler->each(function ( $node ) use ( $query_items ) {
			$attribute_value = array_map(function ( $query_item ) use ( $node ) {
				return $this->source_attribute( $node, $query_item );
			}, $query_items);

			// Remove unsourced query values.
			$attribute_value = array_filter( $attribute_value, function ( $value ) {
				return null !== $value;
			});

			return $attribute_value;
		});


		return $attribute_values;
	}

	/**
	 * Helper function to process the `tag` source attribute.
	 *
	 * @param Symfony\Component\DomCrawler\Crawler $crawler Crawler instance.
	 * @param array                                $block_attribute_definition Definition of the block attribute.
	 *
	 * @return string|null
	 *
	 * @access private
	 */
	protected function source_block_tag( $crawler, $block_attribute_definition ) {
		// The only current usage of the 'tag' attribute is Gutenberg core is the 'core/table' block:
		// https://github.com/WordPress/gutenberg/blob/796b800/packages/block-library/src/table/block.json#L39.
		// Also see tag attribute parsing in Gutenberg:
		// https://github.com/WordPress/gutenberg/blob/6517008/packages/blocks/src/api/parser/get-block-attributes.js#L225.

		$attribute_value = null;
		$selector        = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() > 0 ) {
			$attribute_value = strtolower( $crawler->nodeName() );
		}

		return $attribute_value;
	}

	/**
	 * Helper function to process the `raw` source attribute.
	 *
	 * @param Symfony\Component\DomCrawler\Crawler $crawler Crawler instance.
	 *
	 * @return string|null
	 *
	 * @access private
	 */
	protected function source_block_raw( $crawler ) {
		// The only current usage of the 'raw' attribute in Gutenberg core is the 'core/html' block:
		// https://github.com/WordPress/gutenberg/blob/6517008/packages/block-library/src/html/block.json#L13.
		// Also see tag attribute parsing in Gutenberg:
		// https://github.com/WordPress/gutenberg/blob/6517008/packages/blocks/src/api/parser/get-block-attributes.js#L131.

		$attribute_value = null;

		if ( $crawler->count() > 0 ) {
			$attribute_value = trim( $crawler->html() );
		}

		return $attribute_value;
	}

	/**
	 * Helper function to process the `meta` source attribute.
	 *
	 * @param array $block_attribute_definition Definition of the block attribute.
	 *
	 * @return string|null
	 *
	 * @access private
	 */
	protected function source_block_meta( $block_attribute_definition ) {
		// 'meta' sources:
		// https://developer.wordpress.org/block-editor/reference-guides/block-api/block-attributes/#meta-source.

		$post = get_post( $this->post_id );
		if ( null === $post ) {
			return null;
		}

		$meta_key            = $block_attribute_definition['meta'];
		$is_metadata_present = metadata_exists( 'post', $post->ID, $meta_key );

		if ( ! $is_metadata_present ) {
			return null;
		} else {
			return get_post_meta( $post->ID, $meta_key, true );
		}
	}

	/**
	 * Helper function to process the `children` source attribute.
	 *
	 * @param Symfony\Component\DomCrawler\Crawler $crawler Crawler instance.
	 * @param array                                $block_attribute_definition Definition of the block attribute.
	 *
	 * @return array|string|null
	 *
	 * @access private
	 */
	protected function source_block_children( $crawler, $block_attribute_definition ) {
		// 'children' attribute usage was removed from core in 2018, but not officically deprecated until WordPress 6.1:
		// https://github.com/WordPress/gutenberg/pull/44265.
		// Parsing code for 'children' sources can be found here:
		// https://github.com/WordPress/gutenberg/blob/dd0504b/packages/blocks/src/api/children.js#L149.

		$attribute_values = [];
		$selector         = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		if ( $crawler->count() === 0 ) {
			// If the selector doesn't exist, return a default empty array.
			return $attribute_values;
		}

		$children = $crawler->children();

		if ( $children->count() === 0 ) {
			// 'children' attributes can be a single element. In this case, return the element value in an array.
			$attribute_values = [
				$crawler->getNode( 0 )->nodeValue,
			];
		} else {
			// Use DOMDocument childNodes directly to preserve text nodes. $crawler->children() will return only
			// element nodes and omit text content.
			$children_nodes = $crawler->getNode( 0 )->childNodes;

			foreach ( $children_nodes as $node ) {
				$node_value = $this->from_dom_node( $node );

				if ( $node_value ) {
					$attribute_values[] = $node_value;
				}
			}
		}

		return $attribute_values;
	}

	/**
	 * Helper function to process the `node` source attribute.
	 *
	 * @param Symfony\Component\DomCrawler\Crawler $crawler Crawler instance.
	 * @param array                                $block_attribute_definition Definition of the block attribute.
	 *
	 * @return string|null
	 *
	 * @access private
	 */
	protected function source_block_node( $crawler, $block_attribute_definition ) {
		// 'node' attribute usage was removed from core in 2018, but not officically deprecated until WordPress 6.1:
		// https://github.com/WordPress/gutenberg/pull/44265.
		// Parsing code for 'node' sources can be found here:
		// https://github.com/WordPress/gutenberg/blob/dd0504bd34c29b5b2824d82c8d2bb3a8d0f071ec/packages/blocks/src/api/node.js#L125.

		$attribute_value = null;
		$selector        = $block_attribute_definition['selector'] ?? null;

		if ( null !== $selector ) {
			$crawler = $crawler->filter( $selector );
		}

		$node       = $crawler->getNode( 0 );
		$node_value = null;

		if ( $node ) {
			$node_value = $this->from_dom_node( $node );
		}

		if ( null !== $node_value ) {
			$attribute_value = $node_value;
		}

		return $attribute_value;
	}

	/**
	 * Helper function to process markup used by the deprecated 'node' and 'children' sources.
	 * These sources can return a representation of the DOM tree and bypass the $crawler to access DOMNodes directly.
	 *
	 * @param \DOMNode $node Node currently being processed.
	 *
	 * @return array|string|null
	 *
	 * @access private
	 */
	protected function from_dom_node( $node ) {
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- external API calls

		if ( XML_TEXT_NODE === $node->nodeType ) {
			// For plain text nodes, return the text directly.
			$text = trim( $node->nodeValue );

			// Exclude whitespace-only nodes.
			if ( ! empty( $text ) ) {
				return $text;
			}
		} elseif ( XML_ELEMENT_NODE === $node->nodeType ) {
			$children = array_map( [ $this, 'from_dom_node' ], iterator_to_array( $node->childNodes ) );

			// For element nodes, recurse and return an array of child nodes.
			return [
				'type'     => $node->nodeName,
				'children' => array_filter( $children ),
			];
		} else {
			return null;
		}

		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Add a warning to the warnings, if a block is not registered server-side.
	 *
	 * @param string $block_name Name of the block.
	 *
	 * @return void
	 *
	 * @access private
	 */
	protected function add_missing_block_warning( $block_name ) {
		$warning_message = sprintf( 'Block type "%s" is not server-side registered. Sourced block attributes will not be available.', $block_name );

		if ( ! in_array( $warning_message, $this->warnings ) ) {
			$this->warnings[] = $warning_message;
		}
	}

	/**
	 * Check if debug mode is enabled.
	 *
	 * @return bool true if debug is enabled, or false otherwise
	 *
	 * @access private
	 */
	protected function is_debug_enabled() {
		return defined( 'VIP_BLOCK_DATA_API__PARSE_DEBUG' ) && constant( 'VIP_BLOCK_DATA_API__PARSE_DEBUG' ) === true;
	}
}
