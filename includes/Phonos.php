<?php
namespace MediaWiki\Extension\Phonos;

use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Config\Config;
use MediaWiki\Extension\Phonos\Engine\AudioParams;
use MediaWiki\Extension\Phonos\Engine\Engine;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Extension\Phonos\Job\PhonosIPAFilePersistJob;
use MediaWiki\Extension\Phonos\Wikibase\WikibaseEntityAndLexemeFetcher;
use MediaWiki\FileRepo\File\File;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Html\Html;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\TimedMediaHandler\TimedMediaHandler;
use MediaWiki\TimedMediaHandler\WebVideoTranscode\WebVideoTranscode;
use MediaWiki\Title\Title;
use OOUI\HtmlSnippet;
use Psr\Log\LoggerInterface;

/**
 * Phonos extension
 *
 * @file
 * @ingroup Extensions
 * @license GPL-2.0-or-later
 */
class Phonos implements ParserFirstCallInitHook {

	protected RepoGroup $repoGroup;
	protected LinkRenderer $linkRenderer;
	protected Engine $engine;
	protected WikibaseEntityAndLexemeFetcher $wikibaseEntityAndLexemeFetcher;
	private StatsdDataFactoryInterface $statsdDataFactory;
	private JobQueueGroup $jobQueueGroup;
	private bool $renderingEnabled;
	protected LoggerInterface $logger;
	private bool $inlineAudioPlayerMode;
	private array $wikibaseProperties;

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
		$parserOutput = $parser->getOutput();
		$parserOutput->addModuleStyles( [ 'ext.phonos.styles', 'ext.phonos.icons' ] );
		$parserOutput->addModules( [ 'ext.phonos.init' ] );
		$parser->addTrackingCategory( 'phonos-tracking-category' );

		// Get the named parameters and merge with defaults.
		$defaultOptions = [
			'lang' => $parser->getContentLanguage()->getCode(),
			'text' => '',
			'file' => '',
			'label' => $label ?: '',
			'ipa' => '',
			'wikibase' => '',
			'class' => wfMessage( 'phonos-button-classes' )->inContentLanguage()->parse(),
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
		// Split the class attribute into an array of classes and drop any empty classes.
		$buttonClasses = explode( ' ', $options['class'] );
		$buttonClasses = array_values( array_filter( $buttonClasses, 'strlen' ) );

		$buttonConfig = [
			'label' => $buttonLabel,
			'data' => [
				'ipa' => $options['ipa'],
				'text' => $options['text'],
				'lang' => $options['lang'],
				'wikibase' => $options['wikibase']
			],
			'classes' => $buttonClasses,
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
				$this->handleExistingFile( $options, $buttonConfig, $parserOutput );
			} elseif ( $options['wikibase'] ) {
				$this->handleWikibaseEntity( $options, $buttonConfig, $parserOutput );
			}

			// If there's not yet an audio file, and no error, fetch audio from the engine.
			if ( !isset( $buttonConfig['href'] ) && !isset( $buttonConfig['data']['error'] )
				&& is_string( $options['ipa'] ) && $options['ipa']
			) {
				$this->handleNewFile( $options, $buttonConfig, $parserOutput );
			}

			// Add aria-label for screenreaders. This is also used as the tooltip.
			$buttonConfig['aria-label'] = wfMessage( 'phonos-player-aria-description' )->parse();
		} catch ( PhonosException $e ) {
			$this->recordError( $e );
			$buttonConfig['data']['error'] = $e->getMessageKeyAndArgs();
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
	 * Get a link to the file, or to upload if the file doesn't exist.
	 *
	 * @param array $buttonConfig
	 * @param string $linkText Text to display in the link
	 * @return string HTML
	 */
	private function getFileLink( array $buttonConfig, string $linkText ): string {
		$file = $this->repoGroup->findFile( $buttonConfig['data']['file'] );
		$pageReference = PageReferenceValue::localReference( NS_FILE, $buttonConfig['data']['file'] );

		if ( $file ) {
			// File exists, link to PageReference
			$linkContent = $this->linkRenderer->makeLink(
				$pageReference,
				$linkText
			);
		} else {
			// File does not exist, link to upload (UploadMissingFileUrl)
			$linkContent = Linker::makeBrokenImageLinkObj(
				// @phan-suppress-next-line PhanTypeMismatchArgumentNullable argument will never be null
				Title::castFromPageReference( $pageReference ),
				$linkText
			);
		}

		// Returns HTML
		return $linkContent;
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
		$linkContent = $this->getFileLink( $buttonConfig, wfMessage( 'phonos-attribution-icon' )->plain() );

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
	 * @param ParserOutput $parserOutput
	 * @throws PhonosException
	 */
	private function handleNewFile( array $options, array &$buttonConfig, ParserOutput $parserOutput ): void {
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
		if ( $isPersisted ) {
			$this->engine->updateFileExpiry( $audioParams );
		} else {
			if ( !$this->renderingEnabled ) {
				throw new PhonosException( 'phonos-rendering-disabled' );
			}
			// Generate audio file in a job, so that the parser doesn't have to wait for it, similar to
			// image thumbnails (T325464#9400171). Using jobs also allows controlling the execution rate
			// to avoid hitting backend rate limits (T318086).
			$this->pushJob( $options['ipa'], $options['text'], $options['lang'] );
		}
		// Pass the URL to the clientside even if audio file is not ready
		$buttonConfig['href'] = $this->engine->getFileUrl( $audioParams );
		// Store the filename as a page prop so that we can track orphaned files (T326163).
		// We append to the existing page prop, if it exists, since we can have multiple files per page.
		// The database transaction shouldn't happen until the request finishes.
		$propFiles = json_decode(
			$parserOutput->getPageProperty( 'phonos-files' ) ?? '[]'
		);
		$propFiles[] = basename( $this->engine->getFileName( $audioParams ), '.mp3' );
		$parserOutput->setPageProperty( 'phonos-files', json_encode( array_unique( $propFiles ) ) );

		$previousError = $this->engine->getError( $audioParams );
		if ( $previousError ) {
			// If the job failed the last time, assume it's going to fail again, and display its error
			// message. There should be a way to do this without parsing the page again…
			$key = array_shift( $previousError );
			throw new PhonosException( $key, $previousError );
		}
	}

	/**
	 * Fetch the upload URL of an existing File.
	 *
	 * @param array $options
	 * @param array &$buttonConfig
	 * @param ParserOutput $parserOutput
	 * @throws PhonosException
	 */
	private function handleExistingFile( array $options, array &$buttonConfig, ParserOutput $parserOutput ): void {
		$buttonConfig['data']['file'] = $options['file'];
		$file = $this->repoGroup->findFile( $options['file'] );
		$title = Title::makeTitleSafe( NS_FILE, $options['file'] );
		if ( !$title ) {
			// title is malformed
			throw new PhonosException( 'phonos-invalid-title', [ $options['file'] ] );
		}
		if ( !$file ) {
			throw new PhonosException( 'phonos-file-not-found', [
				Linker::getUploadUrl( $title ),
				$title->getText()
			] );
		}
		$buttonConfig['data']['file'] = $file->getTitle()->getText();
		$parserOutput->addImage( $file->getTitle()->getDBkey() );
		if ( $file->getMediaType() !== MEDIATYPE_AUDIO ) {
			throw new PhonosException( 'phonos-file-not-audio', [
				$title->getPrefixedText(),
				$title->getText()
			] );
		}
		$buttonConfig['href'] = $this->getFileUrl( $file );
	}

	/**
	 * Fetch IPA and/or audio from Wikibase entity/lexeme.
	 *
	 * @param array &$options
	 * @param array &$buttonConfig
	 * @param ParserOutput $parserOutput
	 * @throws PhonosException
	 */
	private function handleWikibaseEntity( array &$options, array &$buttonConfig, ParserOutput $parserOutput ): void {
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
			$parserOutput->addImage( $audioFile->getTitle()->getDBkey() );
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

		$job = new PhonosIPAFilePersistJob( $jobParams, $this->engine, $this->statsdDataFactory );
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
