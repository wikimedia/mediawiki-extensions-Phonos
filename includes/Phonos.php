<?php
namespace MediaWiki\Extension\Phonos;

use Parser;

/**
 * Phonos extension
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */
class Phonos {
	/**
	 * Bind the renderPhonos function to the phonos magic word
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setFunctionHook( 'phonos', [ self::class, 'renderPhonos' ] );
	}

	/**
	 * Convert phonos magic word to HTML
	 * {{#phonos: word=hello | ipa=/həˈləʊ/ | type=ipa | lang=en}}
	 * @todo Error handling for missing required parameters
	 * @param Parser $parser
	 * @return string
	 */
	public static function renderPhonos( Parser $parser ) {
		// Add the CSS and JS
		$parser->getOutput()->addModules( [ 'ext.phonos' ] );

		// Get the named parameters
		$options = self::extractOptions( array_slice( func_get_args(), 1 ) );

		// Explicitly set a default type if none is given
		if ( !isset( $options['type'] ) ) {
			$options['type'] = 'ipa';
		}

		// Using a switch() so that future types can be added
		switch ( $options['type'] ) {
			case 'ipa':
				// Fall through
			default:
				// For now, default to IPA
				$html = "<span class='mw-phonos' data-phonos-lang='{$options['lang']}'
                        data-ssml-sub-alias='{$options['word']}' data-ssml-phoneme-alphabet='ipa'
                        data-ssml-phoneme-ph='{$options['ipa']}'>{$options['ipa']}</span>";
		}

		return $html;
	}

	/**
	 * Converts an array of values in form [0] => "name=value"
	 * into a real associative array in form [name] => value
	 * If no = is provided, true is assumed like this: [name] => true
	 * @see https://www.mediawiki.org/wiki/Manual:Parser_functions#Named_parameters
	 * @param array $options
	 * @return array $results
	 */
	private static function extractOptions( array $options ) {
		$results = [];
		foreach ( $options as $option ) {
			$pair = array_map( 'trim', explode( '=', $option, 2 ) );
			if ( count( $pair ) === 2 ) {
				$results[ $pair[0] ] = $pair[1];
			}
			if ( count( $pair ) === 1 ) {
				$results[ $pair[0] ] = true;
			}
		}
		return $results;
	}

}
