<?php

require_once __DIR__ . '/blueprints-library/src/WordPress/Streams/StreamWrapperInterface.php';
require_once __DIR__ . '/blueprints-library/src/WordPress/Streams/StreamWrapper.php';
require_once __DIR__ . '/blueprints-library/src/WordPress/Streams/StreamPeekerWrapper.php';
require_once __DIR__ . '/blueprints-library/src/WordPress/AsyncHttp/StreamWrapper/ChunkedEncodingWrapper.php';
require_once __DIR__ . '/blueprints-library/src/WordPress/AsyncHttp/StreamWrapper/InflateStreamWrapper.php';
require_once __DIR__ . '/blueprints-library/src/WordPress/AsyncHttp/Request.php';
require_once __DIR__ . '/blueprints-library/src/WordPress/AsyncHttp/Response.php';
require_once __DIR__ . '/blueprints-library/src/WordPress/AsyncHttp/HttpError.php';
require_once __DIR__ . '/blueprints-library/src/WordPress/AsyncHttp/Connection.php';
require_once __DIR__ . '/blueprints-library/src/WordPress/AsyncHttp/Client.php';

require_once __DIR__ . '/src/byte-readers/WP_Byte_Reader.php';
require_once __DIR__ . '/src/byte-readers/WP_File_Reader.php';
require_once __DIR__ . '/src/byte-readers/WP_GZ_File_Reader.php';
require_once __DIR__ . '/src/byte-readers/WP_Remote_File_Reader.php';
require_once __DIR__ . '/src/byte-readers/WP_Remote_File_Ranged_Reader.php';

if(!class_exists('WP_HTML_Tag_Processor')) {
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-token.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-span.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-text-replacement.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-decoder.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-attribute-token.php";

	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-decoder.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-tag-processor.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-open-elements.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-token-map.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/html5-named-character-references.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-active-formatting-elements.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-processor-state.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-unsupported-exception.php";
	require_once __DIR__ . "/src/wordpress-core-html-api/class-wp-html-processor.php";
}
if (!isset($html5_named_character_references)) {
	require_once __DIR__ . "/src/wordpress-core-html-api/html5-named-character-references.php";
}

require_once __DIR__ . '/src/block-markup/WP_Block_Markup_Processor.php';
require_once __DIR__ . '/src/block-markup/WP_Block_Markup_Url_Processor.php';
require_once __DIR__ . '/src/block-markup/WP_URL_In_Text_Processor.php';
require_once __DIR__ . '/src/block-markup/WP_URL.php';

require_once __DIR__ . '/src/xml-api/WP_XML_Decoder.php';
require_once __DIR__ . '/src/xml-api/WP_XML_Processor.php';
require_once __DIR__ . '/src/wxr/WP_WXR_Reader.php';
require_once __DIR__ . '/src/import/WP_Block_Object.php';
require_once __DIR__ . '/src/import/WP_Entity_Importer.php';
require_once __DIR__ . '/src/import/WP_File_Visitor.php';
require_once __DIR__ . '/src/import/WP_File_Visitor_Event.php';
require_once __DIR__ . '/src/import/WP_Imported_Entity.php';
require_once __DIR__ . '/src/import/WP_Attachment_Downloader.php';
require_once __DIR__ . '/src/import/WP_Attachment_Downloader_Event.php';
require_once __DIR__ . '/src/import/WP_Stream_Importer.php';
require_once __DIR__ . '/src/import/WP_Markdown_Importer.php';
require_once __DIR__ . '/src/import/WP_Logger.php';
require_once __DIR__ . '/src/import/WP_Topological_Sorter.php';

require_once __DIR__ . '/src/utf8_decoder.php';

require_once __DIR__ . '/src/markdown-api/WP_Markdown_To_Blocks.php';
require_once __DIR__ . '/src/markdown-api/WP_Markdown_Directory_Tree_Reader.php';
require_once __DIR__ . '/src/markdown-api/WP_Markdown_HTML_Processor.php';
require_once __DIR__ . '/vendor/autoload.php';

// Polyfill WordPress core functions
if (!function_exists('_doing_it_wrong')) {
	$GLOBALS['_doing_it_wrong_messages'] = [];
	function _doing_it_wrong($method, $message, $version) {
		$GLOBALS['_doing_it_wrong_messages'][] = $message;
	}
}

if(!function_exists('wp_kses_uri_attributes')) {
	function wp_kses_uri_attributes() {
		return [];
	}
}

if (!function_exists('__')) {
	function __($input) {
		return $input;
	}
}

if (!function_exists('esc_attr')) {
	function esc_attr($input) {
		return htmlspecialchars($input);
	}
}

if (!function_exists('esc_html')) {
	function esc_html($input) {
		return htmlspecialchars($input);
	}
}

if (!function_exists('esc_url')) {
	function esc_url($url) {
		return htmlspecialchars($url);
	}
}

if (!function_exists('wp_kses_uri_attributes')) {
	function wp_kses_uri_attributes() {
		return array();
	}
}

if (!function_exists('mbstring_binary_safe_encoding')) {
	function mbstring_binary_safe_encoding( $reset = false ) {
		static $encodings  = array();
		static $overloaded = null;

		if ( is_null( $overloaded ) ) {
			if ( function_exists( 'mb_internal_encoding' )
				&& ( (int) ini_get( 'mbstring.func_overload' ) & 2 ) // phpcs:ignore PHPCompatibility.IniDirectives.RemovedIniDirectives.mbstring_func_overloadDeprecated
			) {
				$overloaded = true;
			} else {
				$overloaded = false;
			}
		}

		if ( false === $overloaded ) {
			return;
		}

		if ( ! $reset ) {
			$encoding = mb_internal_encoding();
			array_push( $encodings, $encoding );
			mb_internal_encoding( 'ISO-8859-1' );
		}

		if ( $reset && $encodings ) {
			$encoding = array_pop( $encodings );
			mb_internal_encoding( $encoding );
		}
	}
}

if (!function_exists('reset_mbstring_encoding')) {
	function reset_mbstring_encoding() {
		mbstring_binary_safe_encoding( true );
	}
}
