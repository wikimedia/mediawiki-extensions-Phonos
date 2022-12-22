<?php
namespace MediaWiki\Extension\Phonos;

use Config;
use JobQueueGroup;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Extension\Phonos\Engine\Engine;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Extension\Phonos\Job\PhonosIPAFilePersistJob;
use MediaWiki\Extension\Phonos\Wikibase\WikibaseEntityAndLexemeFetcher;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Linker\LinkRenderer;
use OOUI\HtmlSnippet;
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

	/** @var WikibaseEntityAndLexemeFetcher */
	protected $wikibaseEntityAndLexemeFetcher;

	/** @var StatsdDataFactoryInterface */
	private $statsdDataFactory;

	/** @var JobQueueGroup */
	private $jobQueueGroup;

	/** @var bool */
	private $isCommandLineMode;

	/** @var bool */
	private $renderingEnabled;

	/**
	 * @param RepoGroup $repoGroup
	 * @param Engine $engine
	 * @param WikibaseEntityAndLexemeFetcher $wikibaseEntityAndLexemeFetcher
	 * @param StatsdDataFactoryInterface $statsdDataFactory
	 * @param JobQueueGroup $jobQueueGroup
	 * @param Config $config
	 */
	public function __construct(
		RepoGroup $repoGroup,
		Engine $engine,
		WikibaseEntityAndLexemeFetcher $wikibaseEntityAndLexemeFetcher,
		StatsdDataFactoryInterface $statsdDataFactory,
		JobQueueGroup $jobQueueGroup,
		Config $config
	) {
		$this->repoGroup = $repoGroup;
		$this->engine = $engine;
		$this->wikibaseEntityAndLexemeFetcher = $wikibaseEntityAndLexemeFetcher;
		$this->statsdDataFactory = $statsdDataFactory;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->isCommandLineMode = $config->get( 'CommandLineMode' );
		$this->renderingEnabled = $config->get( 'PhonosIPARenderingEnabled' );
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
		$parser->addTrackingCategory( 'phonos-tracking-category' );

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

		$buttonLabel = $options['ipa'];
		if ( $options['label'] ) {
			$content = $parser->recursiveTagParseFully( $options['label'] );
			// Strip out the <p> tag that might have been added by the parser.
			$buttonLabel = new HtmlSnippet( Parser::stripOuterParagraph( $content ) );
		}
		$buttonConfig = [
			'label' => $buttonLabel,
			'data' => [
				'ipa' => $options['ipa'],
				'text' => $options['text'],
				'lang' => $options['lang'],
				'wikibase' => $options['wikibase']
			],
		];

		try {
			// Require at least something to display generated from something other than just plain text (T322787).
			if ( !$options['ipa'] && !$options['file'] && !$options['wikibase'] ) {
				throw new PhonosException( 'phonos-param-error' );
			}

			// Check for maximum IPA length.
			if ( strlen( $options['ipa'] ) > 300 ) {
				throw new PhonosException( 'phonos-ipa-too-long' );
			}

			if ( $options['file'] ) {
				$this->handleExistingFile( $options, $buttonConfig );
			} elseif ( $options['wikibase'] ) {
				$this->handleWikibaseEntity( $options, $buttonConfig );
			}

			// If there's not yet an audio file, and no error, fetch audio from the engine.
			if ( !isset( $buttonConfig['href'] ) && !isset( $buttonConfig['data']['error'] )
				&& is_string( $options['ipa'] ) && $options['ipa']
			) {
				$this->handleNewFile( $options, $buttonConfig, $parser );
			}

			// Add aria-label for screenreaders.
			$buttonConfig['aria-label'] = wfMessage( 'phonos-player-aria-description', [ $options['text'] ] )->parse();
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
	 * Fetch audio from the engine and persist a new file.
	 *
	 * @param array $options
	 * @param array &$buttonConfig
	 * @param Parser $parser
	 */
	private function handleNewFile( array $options, array &$buttonConfig, Parser $parser ): void {
		$options['lang'] = $this->engine->checkLanguageSupport( $options['lang'] );
		$isPersisted = $this->engine->isPersisted( $options['ipa'], $options['text'], $options['lang'] );
		if ( $isPersisted ) {
			$this->engine->updateFileExpiry( $options['ipa'], $options['text'], $options['lang'] );
		} else {
			if ( !$this->renderingEnabled ) {
				throw new PhonosException( 'phonos-rendering-disabled' );
			}
			if ( $this->isCommandLineMode || !$parser->incrementExpensiveFunctionCount() ) {
				// generate audio file in a job
				$this->pushJob( $options['ipa'], $options['text'], $options['lang'] );
			} else {
				$this->engine->getAudioData( $options['ipa'], $options['text'], $options['lang'] );
			}
		}
		// Pass the URL to the clientside even if audio file is not ready
		$buttonConfig['href'] = $this->engine->getFileUrl(
			$options['ipa'],
			$options['text'],
			$options['lang']
		);
	}

	/**
	 * Fetch the upload URL of an existing File.
	 *
	 * @param array $options
	 * @param array &$buttonConfig
	 */
	private function handleExistingFile( array $options, array &$buttonConfig ): void {
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
	}

	/**
	 * Fetch IPA and/or audio from Wikibase entity/lexeme.
	 *
	 * @param array &$options
	 * @param array &$buttonConfig
	 * @throws PhonosException
	 */
	private function handleWikibaseEntity( array &$options, array &$buttonConfig ): void {
		// If a wikibase attribute has been provided, fetch from Wikibase.
		$wikibaseEntity = $this->wikibaseEntityAndLexemeFetcher->fetch(
			$options['wikibase'],
			$options['text'],
			$options['lang']
		);
		// Set file URL if available.
		if ( $wikibaseEntity->getAudioFile() ) {
			$buttonConfig['href'] = $wikibaseEntity->getCommonsAudioFileUrl();
		}
		// Set the IPA option and button config, if available.
		if ( !$options['ipa'] ) {
			if ( $wikibaseEntity->getIPATranscription() ) {
				$options['ipa'] = $wikibaseEntity->getIPATranscription();
				$buttonConfig['data']['ipa'] = $options['ipa'];
				if ( !$buttonConfig['label'] ) {
					$buttonConfig['label'] = $options['ipa'];
				}
			} elseif ( !isset( $buttonConfig['href'] ) ) {
				// If a Wikibase item is provided, but it doesn't have IPA (in the correct language).
				throw new PhonosException( 'phonos-wikibase-no-ipa' );
			}
		}
	}

	/**
	 * Push a job into the job queue
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 *
	 * @return void
	 */
	private function pushJob( string $ipa, string $text, string $lang ): void {
		$jobParams = [
			'ipa' => $ipa,
			'text' => $text,
			'lang' => $lang,
		];

		$job = new PhonosIPAFilePersistJob( $jobParams );
		$this->jobQueueGroup->push( $job );
	}

	/**
	 * Record exceptions that we capture and their types into statsd.
	 *
	 * @param PhonosException $e
	 * @return void
	 */
	private function recordError( PhonosException $e ): void {
		$key = $e->getStatsdKey();
		$this->statsdDataFactory->increment( "extension.Phonos.error.$key" );
	}
}
