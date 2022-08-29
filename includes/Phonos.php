<?php
namespace MediaWiki\Extension\Phonos;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Linker\LinkRenderer;
use OutputPage;
use Parser;
use RepoGroup;

/**
 * Phonos extension
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */
class Phonos implements ParserFirstCallInitHook {

	/** @var RepoGroup */
	protected $repoGroup;

	/** @var LinkRenderer */
	protected $linkRenderer;

	/**
	 * @param RepoGroup $repoGroup
	 */
	public function __construct( RepoGroup $repoGroup ) {
		$this->repoGroup = $repoGroup;
	}

	/**
	 * Bind the renderPhonos function to the phonos magic word
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook( 'phonos', [ $this, 'renderPhonos' ] );
	}

	/**
	 * Convert phonos magic word to HTML
	 * {{#phonos: text=hello | ipa=/həˈləʊ/ | file=foo.ogg | lang=en}}
	 * @todo Error handling for missing required parameters
	 * @param Parser $parser
	 * @return mixed[]
	 */
	public function renderPhonos( Parser $parser ): array {
		// Add the CSS and JS
		$parser->getOutput()->addModuleStyles( [ 'ext.phonos.styles' ] );
		$parser->getOutput()->addModules( [ 'ext.phonos' ] );

		// Get the named parameters and merge with defaults.
		$defaultOptions = [
			'lang' => $parser->getContentLanguage()->getCode(),
			'text' => '',
			'file' => '',
		];
		$suppliedOptions = self::extractOptions( array_slice( func_get_args(), 1 ) );
		$options = array_merge( $defaultOptions, $suppliedOptions );

		// Require at least something to display.
		if ( !isset( $options['ipa'] ) ) {
			return [];
		}

		$parser->addTrackingCategory( 'phonos-tracking-category' );

		$buttonConfig = [
			'label' => $options['ipa'],
			'data' => [
				'ipa' => $options['ipa'],
				'text' => $options['text'],
				'lang' => $options['lang'],
			],
		];

		// If an audio file has been provided, fetch the upload URL.
		if ( $options['file'] ) {
			$file = $this->repoGroup->findFile( $options['file'] );
			if ( $file && $file->getMediaType() === MEDIATYPE_AUDIO ) {
				$buttonConfig['data']['file'] = $file->getTitle()->getText();
				$buttonConfig['href'] = $file->getUrl();
			} else {
				$buttonConfig['data']['file'] = $options['file'];
				$buttonConfig['data']['error'] = 'phonos-file-not-found';
			}
		} elseif ( !$parser->incrementExpensiveFunctionCount() ) {
			// Return nothing. See T315483
			// TODO: Once T315481 is resolved, check to see if there is a cached file.
			return [];
		}

		$parser->getOutput()->setEnableOOUI( true );
		OutputPage::setupOOUI();
		$button = new PhonosButton( $buttonConfig );
		return [ 'isHTML' => true, 0 => $button->toString() ];
	}

	/**
	 * Converts an array of values in form [0] => "name=value"
	 * into a real associative array in form [name] => value
	 * If no = is provided, true is assumed like this: [name] => true
	 * @see https://www.mediawiki.org/wiki/Manual:Parser_functions#Named_parameters
	 * @param array $options
	 * @return array $results
	 */
	private function extractOptions( array $options ): array {
		$results = [];
		foreach ( $options as $option ) {
			$pair = array_map( 'trim', explode( '=', $option, 2 ) );
			if ( count( $pair ) === 2 ) {
				$results[ $pair[0] ] = $pair[1];
			}
			if ( count( $pair ) === 1 && $pair[0] !== '' ) {
				$results[ $pair[0] ] = true;
			}
		}
		return $results;
	}

}
