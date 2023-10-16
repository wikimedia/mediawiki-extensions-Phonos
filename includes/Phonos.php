<?php
namespace MediaWiki\Extension\Phonos;

use Config;
use ExtensionRegistry;
use File;
use JobQueueGroup;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Extension\Phonos\Engine\AudioParams;
use MediaWiki\Extension\Phonos\Engine\Engine;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Extension\Phonos\Job\PhonosIPAFilePersistJob;
use MediaWiki\Extension\Phonos\Wikibase\WikibaseEntityAndLexemeFetcher;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\TimedMediaHandler\TimedMediaHandler;
use MediaWiki\TimedMediaHandler\WebVideoTranscode\WebVideoTranscode;
use MediaWiki\Title\Title;
use OOUI\HtmlSnippet;
use OutputPage;
use Parser;
use Psr\Log\LoggerInterface;
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

	/** @var LoggerInterface */
	protected $logger;

	/** @var bool */
	private $inlineAudioPlayerMode;

	/** @var array */
	private $wikibaseProperties;

	/**
	 * @param RepoGroup $repoGroup
	 * @param Engine $engine
	 * @param WikibaseEntityAndLexemeFetcher $wikibaseEntityAndLexemeFetcher
	 * @param StatsdDataFactoryInterface $statsdDataFactory
	 * @param JobQueueGroup $jobQueueGroup
	 * @param LinkRenderer $linkRenderer
	 * @param Config $config
	 */
	public function __construct(
		RepoGroup $repoGroup,
		Engine $engine,
		WikibaseEntityAndLexemeFetcher $wikibaseEntityAndLexemeFetcher,
		StatsdDataFactoryInterface $statsdDataFactory,
		JobQueueGroup $jobQueueGroup,
		LinkRenderer $linkRenderer,
		Config $config
	) {
		$this->repoGroup = $repoGroup;
		$this->engine = $engine;
		$this->wikibaseEntityAndLexemeFetcher = $wikibaseEntityAndLexemeFetcher;
		$this->statsdDataFactory = $statsdDataFactory;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->linkRenderer = $linkRenderer;
		$this->isCommandLineMode = $config->get( 'CommandLineMode' );
		$this->renderingEnabled = $config->get( 'PhonosIPARenderingEnabled' );
		$this->logger = LoggerFactory::getInstance( 'Phonos' );
		$this->inlineAudioPlayerMode = $config->get( 'PhonosInlineAudioPlayerMode' );
		$this->wikibaseProperties = $config->get( 'PhonosWikibaseProperties' );
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
		$parser->getOutput()->addModules( [ 'ext.phonos.init' ] );
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
		// Don't allow a label= attribute; see T340905#8983499
		unset( $args['label'] );
		$options = array_merge( $defaultOptions, $args );

		$buttonLabel = $options['ipa'];
		if ( $options['label'] ) {
			$content = $parser->recursiveTagParseFully( trim( $options['label'] ) );
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
				$this->handleExistingFile( $options, $buttonConfig, $parser );
			} elseif ( $options['wikibase'] ) {
				$this->handleWikibaseEntity( $options, $buttonConfig, $parser );
			}

			// If there's not yet an audio file, and no error, fetch audio from the engine.
			if ( !isset( $buttonConfig['href'] ) && !isset( $buttonConfig['data']['error'] )
				&& is_string( $options['ipa'] ) && $options['ipa']
			) {
				$this->handleNewFile( $options, $buttonConfig, $parser );
			}

			// Add aria-label for screenreaders. This is also used as the tooltip.
			$buttonConfig['aria-label'] = wfMessage( 'phonos-player-aria-description' )->parse();
		} catch ( PhonosException $e ) {
			$this->recordError( $e );
			$buttonConfig['data']['error'] = $e->toString();
			// Tell screenreaders that there's an error, but we can't add the actual error message because it's in the
			// client-side popup which doesn't exist here.
			$buttonConfig['aria-label'] = wfMessage( 'phonos-aria-error' )->parse();
		}

		// Errors also set outside from exceptions, add tracking for it, but it missing stats at the moment
		if ( isset( $buttonConfig['data']['error'] ) ) {
			$parser->addTrackingCategory( 'phonos-error-category' );
		}

		OutputPage::setupOOUI();
		$button = new PhonosButton( $buttonConfig );
		return Html::rawElement(
			'span',
			[ 'class' => 'ext-phonos' ],
			$button->toString() . $this->addAttributionLink( $buttonConfig )
		);
	}

	/**
	 * Return an attribution link if required.
	 *
	 * @param array $buttonConfig
	 * @return string
	 */
	private function addAttributionLink( array $buttonConfig ): string {
		if ( !isset( $buttonConfig['data']['file'] ) ) {
			return '';
		}
		$file = $this->repoGroup->findFile( $buttonConfig['data']['file'] );
		$pageReference = PageReferenceValue::localReference( NS_FILE, $buttonConfig['data']['file'] );

		if ( $file ) {
			// File exists, link to PageReference
			$linkContent = $this->linkRenderer->makeLink(
				$pageReference,
				wfMessage( 'phonos-attribution-icon' )->plain()
			);
		} else {
			// File does not exist, link to upload (UploadMissingFileUrl)
			$linkContent = Linker::makeBrokenImageLinkObj(
				// @phan-suppress-next-line PhanTypeMismatchArgumentNullable argument will never be null
				Title::castFromPageReference( $pageReference ),
				wfMessage( 'phonos-attribution-icon' )->plain()
			);
		}

		return Html::rawElement(
			'sup',
			[ 'class' => 'ext-phonos-attribution noexcerpt navigation-not-searchable' ],
			$linkContent
		);
	}

	/**
	 * Fetch audio from the engine and persist a new file.
	 *
	 * @param array $options
	 * @param array &$buttonConfig
	 * @param Parser $parser
	 */
	private function handleNewFile( array $options, array &$buttonConfig, Parser $parser ): void {
		if ( $this->inlineAudioPlayerMode ) {
			throw new PhonosException(
				'phonos-inline-audio-player-mode',
				[
					$this->wikibaseProperties[ 'wikibasePronunciationAudioProp' ]
				]
			);
		}
		$options['lang'] = $this->engine->checkLanguageSupport( $options['lang'] );
		$audioParams = new AudioParams( $options['ipa'], $options['text'], $options['lang'] );
		$isPersisted = $this->engine->isPersisted( $audioParams );
		// TODO: Remove this debug log once T325464 resolved.
		$this->logger->debug(
			__METHOD__ . ' debug',
			[
				'audioParams' => $audioParams,
				'isPersisted' => $isPersisted,
				'renderingEnabled' => $this->renderingEnabled,
				'isCommandLineMode' => $this->isCommandLineMode,
				'incrementExpensiveFunctionCount' => $parser->incrementExpensiveFunctionCount(),
			]
		);
		if ( $isPersisted ) {
			$this->engine->updateFileExpiry( $audioParams );
		} else {
			if ( !$this->renderingEnabled ) {
				throw new PhonosException( 'phonos-rendering-disabled' );
			}
			if ( $this->isCommandLineMode || !$parser->incrementExpensiveFunctionCount() ) {
				// generate audio file in a job
				$this->pushJob( $options['ipa'], $options['text'], $options['lang'] );
			} else {
				$this->engine->getAudioData( $audioParams );
			}
		}
		// Pass the URL to the clientside even if audio file is not ready
		$buttonConfig['href'] = $this->engine->getFileUrl( $audioParams );
		// Store the filename as a page prop so that we can track orphaned files (T326163).
		// We append to the existing page prop, if it exists, since we can have multiple files per page.
		// The database transaction shouldn't happen until the request finishes.
		$propFiles = json_decode(
			$parser->getOutput()->getPageProperty( 'phonos-files' ) ?? '[]'
		);
		$propFiles[] = basename( $this->engine->getFileName( $audioParams ), '.mp3' );
		$parser->getOutput()->setPageProperty( 'phonos-files', json_encode( array_unique( $propFiles ) ) );
	}

	/**
	 * Fetch the upload URL of an existing File.
	 *
	 * @param array $options
	 * @param array &$buttonConfig
	 * @param Parser $parser
	 */
	private function handleExistingFile( array $options, array &$buttonConfig, Parser $parser ): void {
		$buttonConfig['data']['file'] = $options['file'];
		$file = $this->repoGroup->findFile( $options['file'] );
		$wikitextLink = '[[' . $options['file'] . ']]';
		if ( !$file ) {
			throw new PhonosException( 'phonos-file-not-found', [ $wikitextLink ] );
		}
		$buttonConfig['data']['file'] = $file->getTitle()->getText();
		$parser->getOutput()->addImage( $file->getTitle()->getDBkey() );
		if ( $file->getMediaType() !== MEDIATYPE_AUDIO ) {
			throw new PhonosException( 'phonos-file-not-audio', [ $wikitextLink ] );
		}
		$buttonConfig['href'] = $this->getFileUrl( $file );
	}

	/**
	 * Fetch IPA and/or audio from Wikibase entity/lexeme.
	 *
	 * @param array &$options
	 * @param array &$buttonConfig
	 * @param Parser $parser
	 * @throws PhonosException
	 */
	private function handleWikibaseEntity( array &$options, array &$buttonConfig, Parser $parser ): void {
		// If a wikibase attribute has been provided, fetch from Wikibase.
		$wikibaseEntity = $this->wikibaseEntityAndLexemeFetcher->fetch(
			$options['wikibase'],
			$options['text'],
			$options['lang']
		);

		// Set file URL if available.
		$audioFile = $wikibaseEntity->getAudioFile();
		if ( $audioFile ) {
			$buttonConfig['data']['file'] = $audioFile->getTitle()->getText();
			$buttonConfig['href'] = $this->getFileUrl( $audioFile );
			$parser->getOutput()->addImage( $audioFile->getTitle()->getDBkey() );
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
	 * Get the public URL for the given File, using TimedMediaHandler to
	 * find a transcoded MP3 source if the given File isn't already an MP3.
	 *
	 * If TimeMediaHandler can't find an MP3 source, the original non-MP3
	 * file URL will be returned instead.
	 *
	 * @param File $file
	 * @return string
	 */
	public function getFileUrl( File $file ): string {
		$isAlreadyMP3 = $file->getMimeType() === 'audio/mpeg';
		$isHandledByTMH = ExtensionRegistry::getInstance()->isLoaded( 'TimedMediaHandler' ) &&
			$file->getHandler() && $file->getHandler() instanceof TimedMediaHandler;

		if ( !$isAlreadyMP3 && $isHandledByTMH ) {
			$mp3Source = array_filter( WebVideoTranscode::getSources( $file ), static function ( $source ) {
				return isset( $source['transcodekey'] ) && $source[ 'transcodekey' ] === 'mp3';
			} );
			$mp3Source = reset( $mp3Source );
			if ( isset( $mp3Source['src'] ) ) {
				return $mp3Source[ 'src' ];
			}
		}

		return $file->getUrl();
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

		$this->logger->info(
			__METHOD__ . ' Job being created',
			[
				'params' => $jobParams
			]
		);

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
