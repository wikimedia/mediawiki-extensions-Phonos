<?php

namespace MediaWiki\Extension\Phonos\Engine;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\FileBackend\FileBackendGroup;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Language\Language;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Output\StreamFile;
use MediaWiki\Shell\CommandFactory;
use MediaWiki\Status\Status;
use NullLockManager;
use ReflectionClass;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\FileBackend\FSFileBackend;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Contains logic common to all Engines.
 */
abstract class Engine implements EngineInterface {

	/**
	 * Version for cache invalidation.
	 *
	 * WARNING: Changing this value will cause *all* Phonos files to be regenerated!
	 *
	 * After changing, please also run the deleteOldPhonosFiles.php script
	 * with the appropriate timestamp to delete old orphaned files.
	 *
	 * @var int
	 */
	private const CACHE_VERSION = 1;

	/** @var int|null Minimum file size in bytes. Null for no minimum. See T324239 */
	protected const MIN_FILE_SIZE = null;

	/** @var string Prefix directory name when persisting files to storage. */
	public const STORAGE_PREFIX = 'phonos-render';

	protected readonly FileBackend $fileBackend;

	protected readonly ?string $apiProxy;

	protected readonly string $lamePath;

	protected readonly string $uploadPath;

	private readonly string $engineName;

	/** Time in days we want to persist the file for */
	protected readonly int $fileExpiry;

	public function __construct(
		protected readonly HttpRequestFactory $requestFactory,
		protected readonly CommandFactory $commandFactory,
		FileBackendGroup $fileBackendGroup,
		private readonly BagOStuff $stash,
		protected readonly WANObjectCache $wanCache,
		private readonly Language $contentLanguage,
		protected readonly Config $config,
	) {
		$this->fileBackend = self::getFileBackend( $fileBackendGroup, $config );
		$this->apiProxy = $config->get( 'PhonosApiProxy' ) ?: null;
		$this->lamePath = $config->get( 'PhonosLame' );
		$this->uploadPath = $config->get( 'PhonosPath' ) ?:
			$config->get( MainConfigNames::UploadPath ) . '/' . self::STORAGE_PREFIX;
		// Using ReflectionClass to get the unqualified class name is actually faster than doing string operations.
		$this->engineName = ( new ReflectionClass( get_class( $this ) ) )->getShortName();

		// Only used if filebackend supports ATTR_METADATA
		$this->fileExpiry = (int)$config->get( 'PhonosFileExpiry' );

		$this->register();
	}

	abstract protected function register(): void;

	/**
	 * Get either the configured FileBackend, or create a Phonos-specific FSFileBackend.
	 *
	 * @codeCoverageIgnore
	 */
	public static function getFileBackend( FileBackendGroup $fileBackendGroup, Config $config ): FileBackend {
		if ( $config->get( 'PhonosFileBackend' ) ) {
			return $fileBackendGroup->get( $config->get( 'PhonosFileBackend' ) );
		}

		$uploadDirectory = $config->get( 'PhonosFileBackendDirectory' ) ?:
			$config->get( MainConfigNames::UploadDirectory ) . '/' . self::STORAGE_PREFIX;

		return new FSFileBackend( [
			'name' => 'phonos-backend',
			'basePath' => $uploadDirectory,
			// NOTE: We intentionally use a blank 'domainId' since Phonos files with identical
			// parameters (including language) won't differ cross-wiki and should be shared.
			// Similarly we set the 'containerPaths', which effectively tells FileBackend to
			// bypass using the 'domainId' when building paths. This is to prevent the asymmetry
			// in path names used by FSFileBackend and others such as Swift. However, all files
			// are under a dedicated directory with the name self::STORAGE_PREFIX, which should
			// be enough to prevent collisions with other backends using the same storage system.
			// If this is undesired, set $wgPhonosFileBackendDirectory and/or $wgPhonosFileBackend
			// accordingly, along with the user-facing path specified by $wgPhonosPath.
			'domainId' => '',
			'containerPaths' => [ self::STORAGE_PREFIX => $uploadDirectory ],
			'lockManager' => new NullLockManager( [] ),
			'fileMode' => 0777,
			'directoryMode' => 0777,
			'obResetFunc' => wfResetOutputBuffers( ... ),
			'streamMimeFunc' => StreamFile::contentTypeFromPath( ... ),
			'statusWrapper' => Status::wrap( ... ),
			'logger' => LoggerFactory::getInstance( 'phonos' ),
		] );
	}

	/**
	 * Get the relative URL to the persisted file.
	 */
	public function getFileUrl( AudioParams $params ): string {
		return $this->getFileProperties( $params )['dest_url'];
	}

	/**
	 * Persist the given audio data using the configured storage backend.
	 *
	 * @throws PhonosException
	 */
	final public function persistAudio( AudioParams $params, string $data ): void {
		if ( static::MIN_FILE_SIZE && strlen( $data ) < static::MIN_FILE_SIZE ) {
			throw new PhonosException( 'phonos-empty-file-error', [ 'text' ] );
		}

		$status = $this->fileBackend->prepare( [
			'dir' => $this->getFileStoragePath( $params ),
		] );
		if ( !$status->isOK() ) {
			throw new PhonosException( 'phonos-directory-error', [
				Status::wrap( $status )->getMessage()->text(),
			] );
		}

		// Create the file.
		$status = $this->fileBackend->quickCreate( [
			'dst' => $this->getFullFileStoragePath( $params ),
			'content' => $data,
			'overwriteSame' => true,
			'headers' => [
				'X-Delete-At' => $this->generateExpiryTs()
			],
		] );

		if ( !$status->isOK() ) {
			throw new PhonosException( 'phonos-storage-error', [
				Status::wrap( $status )->getMessage()->text()
			] );
		}
	}

	/**
	 * Fetch the contents of the persisted file in the storage backend, or null if the file doesn't exist.
	 *
	 * @param AudioParams $params
	 * @return string|null base64 data, or null if the file doesn't exist.
	 */
	final public function getPersistedAudio( AudioParams $params ): ?string {
		if ( !$this->isPersisted( $params ) ) {
			return null;
		}
		return $this->fileBackend->getFileContents( [
			'src' => $this->getFullFileStoragePath( $params ),
		] );
	}

	/**
	 * Is there a persisted file for the given parameters?
	 */
	final public function isPersisted( AudioParams $params ): bool {
		return (bool)$this->fileBackend->fileExists( [
			'src' => $this->getFullFileStoragePath( $params ),
		] );
	}

	/**
	 * Get the previous error recorded for the given parameters.
	 *
	 * @param AudioParams $params
	 * @return ?array Message key and parameters, or null if no error
	 */
	final public function getError( AudioParams $params ): ?array {
		return $this->stash->get(
			$this->stash->makeKey( 'phonos', 'engine-error',
				$params->ipa, $params->text, $params->lang )
		) ?: null;
	}

	/**
	 * Record the error encountered for the given parameters.
	 *
	 * @param AudioParams $params
	 * @param array $error Message key and parameters
	 */
	final public function setError( AudioParams $params, array $error ): void {
		$this->stash->set(
			$this->stash->makeKey( 'phonos', 'engine-error',
				$params->ipa, $params->text, $params->lang ),
			$error
		);
	}

	/**
	 * Clear the previous error recorded for the given parameters.
	 *
	 * @param AudioParams $params
	 */
	final public function clearError( AudioParams $params ): void {
		$this->stash->delete(
			$this->stash->makeKey( 'phonos', 'engine-error',
				$params->ipa, $params->text, $params->lang )
		);
	}

	/**
	 * Update file expiry when supported by the file backend
	 */
	final public function updateFileExpiry( AudioParams $params ): void {
		if ( $this->fileBackend->hasFeatures( FileBackend::ATTR_HEADERS ) ) {
			$this->fileBackend->quickDescribe( [
				'src' => $this->getFullFileStoragePath( $params ),
				'headers' => [
					'X-Delete-At' => $this->generateExpiryTs(),
				],
			] );
		}
	}

	/**
	 * For a given language, check that it's supported by the engine.
	 * @param string $lang Language code to check. Will be returned if valid.
	 * @return string The normalized language code.
	 * @throws PhonosException If the language is not supported.
	 */
	public function checkLanguageSupport( string $lang ): string {
		// If an engine doesn't provide a list of supported languages, assume this one is supported.
		$supportedLangs = $this->getSupportedLanguages();
		if ( $supportedLangs === null ) {
			return $lang;
		}

		// Normalize and check for the requested language, returning it if it's supported.
		$normalizedLang = strtolower( strtr( $lang, '_', '-' ) );
		$normalizedLangs = array_map( static function ( $l ) {
			return strtolower( strtr( $l, '_', '-' ) );
		}, $supportedLangs );
		$supportedLangKey = array_search( $normalizedLang, $normalizedLangs );
		if ( $supportedLangKey !== false ) {
			return $supportedLangs[$supportedLangKey];
		}

		// Make a list of supported languages that are a superstring of the given one.
		$suggestions = array_filter( $supportedLangs, static function ( $sl ) use ( $lang ) {
			return stripos( $sl, $lang ) !== false;
		} );
		if ( count( $suggestions ) === 0 ) {
			throw new PhonosException( 'phonos-unsupported-language', [ $lang ] );
		} else {
			$suggestionList = $this->contentLanguage->listToText( $suggestions );
			throw new PhonosException( 'phonos-unsupported-language-with-suggestions', [ $lang, $suggestionList ] );
		}
	}

	/**
	 * Convert the given WAV data into MP3.
	 *
	 * @throws PhonosException
	 */
	final public function convertWavToMp3( string $data ): string {
		$out = $this->commandFactory
			->createBoxed( 'phonos' )
			->disableNetwork()
			->firejailDefaultSeccomp()
			->routeName( 'phonos-mp3-convert' )
			->params( $this->lamePath, '-', '-' )
			->stdin( $data )
			->execute();
		if ( $out->getExitCode() !== 0 ) {
			throw new PhonosException( 'phonos-audio-conversion-error', [ $out->getStderr() ] );
		}
		return $out->getStdout();
	}

	/**
	 * Get various storage properties about the persisted file.
	 *
	 * @param AudioParams $params
	 * @return string[] with keys 'dest_storage_path', 'dest_url', 'file_name'
	 */
	private function getFileProperties( AudioParams $params ): array {
		$baseStoragePath = $this->fileBackend->getRootStoragePath() . '/' . self::STORAGE_PREFIX;
		$cacheOptions = [ $this->engineName,
			$params->ipa,
			$params->text,
			$params->lang,
			self::CACHE_VERSION ];
		$fileCacheName = \Wikimedia\base_convert( sha1( implode( '|', $cacheOptions ) ), 16, 36, 31 );
		$filePrefixEnd = "{$fileCacheName[0]}/{$fileCacheName[1]}";
		$fileName = "$fileCacheName.mp3";
		return [
			'fileName' => $fileName,
			'dest_storage_path' => "$baseStoragePath/$filePrefixEnd",
			'dest_url' => "{$this->uploadPath}/$filePrefixEnd/{$fileName}",
		];
	}

	/**
	 * Get the internal storage path to the persisted file, whether it exists or not.
	 */
	private function getFileStoragePath( AudioParams $params ): string {
		return $this->getFileProperties( $params )[ 'dest_storage_path' ];
	}

	/**
	 * Get the unique filename for the given set of Phonos parameters, including the file extension.
	 */
	public function getFileName( AudioParams $params ): string {
		return $this->getFileProperties( $params )['fileName'];
	}

	/**
	 * Get the full path to the file.
	 */
	private function getFullFileStoragePath( AudioParams $params ): string {
		return $this->getFileStoragePath( $params ) . '/' . $this->getFileName( $params );
	}

	/**
	 * Generate file expiry with some deviations
	 * to minimize flooding on object expiration
	 */
	private function generateExpiryTs(): int {
		// convert days to seconds
		$ttl = $this->fileExpiry * 86400;
		return time() + rand( intval( $ttl * 0.8 ), $ttl );
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedLanguages(): ?array {
		return null;
	}

}
