<?php
namespace MediaWiki\Extension\Phonos;

use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Extension\Phonos\Engine\Engine;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Extension\Phonos\Wikibase\WikibaseEntityAndLexemeFetcher;
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

	/** @var Engine */
	protected $engine;

	/** @var StatsdDataFactoryInterface */
	private $statsdDataFactory;
	/** @var WikibaseEntityAndLexemeFetcher */
	protected $wikibaseEntityAndLexemeFetcher;

	/**
	 * @param RepoGroup $repoGroup
	 * @param Engine $engine
	 * @param WikibaseEntityAndLexemeFetcher $wikibaseEntityAndLexemeFetcher
	 * @param StatsdDataFactoryInterface $statsdDataFactory
	 */
	public function __construct( RepoGroup $repoGroup,
		Engine $engine,
		WikibaseEntityAndLexemeFetcher $wikibaseEntityAndLexemeFetcher,
		StatsdDataFactoryInterface $statsdDataFactory
	) {
		$this->repoGroup = $repoGroup;
		$this->engine = $engine;
		$this->wikibaseEntityAndLexemeFetcher = $wikibaseEntityAndLexemeFetcher;
		$this->statsdDataFactory = $statsdDataFactory;
	}

	/**
	 * Bind the renderPhonos function to the phonos magic word
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'phonos', [ $this, 'renderPhonos' ] );
	}

	/**
	 * Convert phonos magic word to HTML
	 * <phonos ipa="/həˈləʊ/" text="hello" file="foo.ogg" language="en" wikibase="Q23501">Hello!</phonos>
	 *
	 * @param string|null $label
	 * @param array $args
	 * @param Parser $parser
	 * @return string
	 */
	public function renderPhonos( ?string $label, array $args, Parser $parser ): string {
		// Add the CSS and JS
		$parser->getOutput()->addModuleStyles( [ 'ext.phonos.styles', 'ext.phonos.icons' ] );
		$parser->getOutput()->addModules( [ 'ext.phonos' ] );

		// Get the named parameters and merge with defaults.
		$defaultOptions = [
			'lang' => $parser->getContentLanguage()->getCode(),
			'text' => '',
			'file' => '',
			'label' => $label ?: '',
			'ipa' => '',
			'wikibase' => '',
		];
		$options = array_merge( $defaultOptions, $args );

		// Require at least something to display.
		if ( !$options['ipa'] && !$options['label'] && !$options['file'] ) {
			return '';
		}

		$parser->addTrackingCategory( 'phonos-tracking-category' );
		$buttonConfig = [
			'label' => $label ?: $options['ipa'],
			'data' => [
				'ipa' => $options['ipa'],
				'text' => $options['text'],
				'lang' => $options['lang'],
				'wikibase' => $options['wikibase']
			],
		];

		// Check length of IPA.
		if ( strlen( $options['ipa'] ) > 300 ) {
			// Don't send the very long IPA
			$options['ipa'] = '';
			$buttonConfig['data']['error'] = 'phonos-ipa-too-long';
		}

		try {
			// If an audio file has been provided, fetch the upload URL.
			if ( $options['file'] ) {
				$buttonConfig['data']['file'] = $options['file'];
				$file = $this->repoGroup->findFile( $options['file'] );
				if ( $file ) {
					$buttonConfig['data']['file'] = $file->getTitle()->getText();
					if ( $file->getMediaType() === MEDIATYPE_AUDIO ) {
						$buttonConfig['href'] = $file->getUrl();
					} else {
						$buttonConfig['data']['error'] = 'phonos-file-not-audio';
					}
				} else {
					$buttonConfig['data']['error'] = 'phonos-file-not-found';
				}
			} elseif ( $options['wikibase'] ) {
				// If a wikibase has been provided, fetch from Wikibase.
				$phonosWikibaseAudio = $this->wikibaseEntityAndLexemeFetcher->fetchPhonosWikibaseAudio(
					$options['wikibase'],
					$options['text'],
					$options['lang']
				);

				if ( $phonosWikibaseAudio ) {
					$buttonConfig['href'] = $phonosWikibaseAudio->getCommonsAudioFileUrl();
				} else {
					throw new PhonosException( 'phonos-wikibase-not-found', [ $options['wikibase'] ] );
				}
			} else {
				$isPersisted = $this->engine->isPersisted( $options['ipa'], $options['text'], $options['lang'] );
				if ( !$isPersisted && !$parser->incrementExpensiveFunctionCount() ) {
					// Return nothing. See T315483
					return '';
				}
				// Otherwise generate the audio based on the given data, and pass the URL to the clientside.
				$buttonConfig['href'] = $this->engine->getFileUrl(
					$options['ipa'],
					$options['text'],
					$options['lang']
				);
			}
		} catch ( PhonosException $e ) {
			$this->recordError( $e );
			$buttonConfig['data']['error'] = $e->toString();
			$parser->addTrackingCategory( 'phonos-error-category' );
		}

		$parser->getOutput()->setEnableOOUI( true );
		OutputPage::setupOOUI();
		$button = new PhonosButton( $buttonConfig );
		return $button->toString();
	}

	/**
	 * Record exceptions that we capture and their types into statsd.
	 *
	 * @param PhonosException $e
	 * @return void
	 */
	private function recordError( PhonosException $e ): void {
		$key = $e->getStatsdKey();
		$this->statsdDataFactory->increment( "phonos_error.$key" );
	}

}
