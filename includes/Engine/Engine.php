<?php

namespace MediaWiki\Extension\Phonos\Engine;

use Config;
use FileBackend;
use FileBackendGroup;
use FSFileBackend;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\CommandFactory;
use NullLockManager;
use ReflectionClass;
use Status;
use WANObjectCache;

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

	/** @var HttpRequestFactory */
	protected $requestFactory;

	/** @var CommandFactory */
	protected $commandFactory;

	/** @var FileBackend */
	protected $fileBackend;

	/** @var string */
	protected $apiProxy;

	/** @var string */
	protected $lamePath;

	/** @var string */
	protected $uploadPath;

	/** @var string */
	private $engineName;

	/** @var bool */
	protected $storeFilesAsMp3;

	/** @var int Time in days we want to persist the file for */
	protected $fileExpiry;

	/** @var WANObjectCache */
	protected $wanCache;

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param CommandFactory $commandFactory
	 * @param FileBackendGroup $fileBackendGroup
	 * @param WANObjectCache $wanCache
	 * @param Config $config
	 */
	public function __construct(
		HttpRequestFactory $requestFactory,
		CommandFactory $commandFactory,
		FileBackendGroup $fileBackendGroup,
		WANObjectCache $wanCache,
		Config $config
	) {
		$this->requestFactory = $requestFactory;
		$this->commandFactory = $commandFactory;
		$this->fileBackend = self::getFileBackend( $fileBackendGroup, $config );
		$this->wanCache = $wanCache;
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
		$this->lamePath = $config->get( 'PhonosLame' );
		$this->uploadPath = $config->get( 'PhonosPath' ) ?:
			$config->get( MainConfigNames::UploadPath ) . '/' . self::STORAGE_PREFIX;
		// Using ReflectionClass to get the unqualified class name is actually faster than doing string operations.
		$this->engineName = ( new ReflectionClass( get_class( $this ) ) )->getShortName();
		$this->storeFilesAsMp3 = $config->get( 'PhonosStoreFilesAsMp3' );

		// Only used if filebackend supports ATTR_METADATA
		$this->fileExpiry = $config->get( 'PhonosFileExpiry' );
	}

	/**
	 * Get either the configured FileBackend, or create a Phonos-specific FSFileBackend.
	 *
	 * @param FileBackendGroup $fileBackendGroup
	 * @param Config $config
	 * @return FileBackend
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
			'obResetFunc' => 'wfResetOutputBuffers',
			'streamMimeFunc' => [ 'StreamFile', 'contentTypeFromPath' ],
			'statusWrapper' => [ 'Status', 'wrap' ],
			'logger' => LoggerFactory::getInstance( 'phonos' ),
		] );
	}

	/**
	 * Get the relative URL to the persisted file.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	public function getFileUrl( string $ipa, string $text, string $lang ): string {
		return $this->getFileProperties( $ipa, $text, $lang )['dest_url'];
	}

	/**
	 * Persist the given audio data using the configured storage backend.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @param string $data
	 * @return void
	 * @throws PhonosException
	 */
	final public function persistAudio( string $ipa, string $text, string $lang, string $data ): void {
		if ( static::MIN_FILE_SIZE && strlen( $data ) < static::MIN_FILE_SIZE ) {
			throw new PhonosException( 'phonos-empty-file-error', [ 'text' ] );
		}

		$status = $this->fileBackend->prepare( [
			'dir' => $this->getFileStoragePath( $ipa, $text, $lang ),
		] );
		if ( !$status->isOK() ) {
			throw new PhonosException( 'phonos-directory-error', [
				Status::wrap( $status )->getMessage()->text(),
			] );
		}

		// Create the file.
		$status = $this->fileBackend->quickCreate( [
			'dst' => $this->getFullFileStoragePath( $ipa, $text, $lang ),
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
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string|null base64 data, or null if the file doesn't exist.
	 */
	final public function getPersistedAudio( string $ipa, string $text, string $lang ): ?string {
		if ( !$this->isPersisted( $ipa, $text, $lang ) ) {
			return null;
		}
		return $this->fileBackend->getFileContents( [
			'src' => $this->getFullFileStoragePath( $ipa, $text, $lang ),
		] );
	}

	/**
	 * Is there a persisted file for the given parameters?
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return bool
	 */
	final public function isPersisted( string $ipa, string $text, string $lang ): bool {
		return (bool)$this->fileBackend->fileExists( [
			'src' => $this->getFullFileStoragePath( $ipa, $text, $lang ),
		] );
	}

	/**
	 * Update file expiry when supported by the file backend
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return void
	 */
	final public function updateFileExpiry( string $ipa, string $text, string $lang ): void {
		if ( $this->fileBackend->hasFeatures( FileBackend::ATTR_HEADERS ) ) {
			$this->fileBackend->quickDescribe( [
				'src' => $this->getFullFileStoragePath( $ipa, $text, $lang ),
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
			$suggestionList = MediaWikiServices::getInstance()->getContentLanguage()->listToText( $suggestions );
			throw new PhonosException( 'phonos-unsupported-language-with-suggestions', [ $lang, $suggestionList ] );
		}
	}

	/**
	 * Convert the given WAV data into MP3.
	 *
	 * @param string $data
	 * @return string
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
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string[] with keys 'dest_storage_path', 'dest_url', 'file_name'
	 */
	private function getFileProperties( string $ipa, string $text, string $lang ): array {
		$baseStoragePath = $this->fileBackend->getRootStoragePath() . '/' . self::STORAGE_PREFIX;
		$cacheOptions = [ $this->engineName, $ipa, $text, $lang, self::CACHE_VERSION ];
		$fileCacheName = \Wikimedia\base_convert( sha1( implode( '|', $cacheOptions ) ), 16, 36, 31 );
		$filePrefixEnd = "{$fileCacheName[0]}/{$fileCacheName[1]}";
		$fileName = "$fileCacheName" . ( $this->storeFilesAsMp3 ? '.mp3' : '.wav' );
		return [
			'fileName' => $fileName,
			'dest_storage_path' => "$baseStoragePath/$filePrefixEnd",
			'dest_url' => "{$this->uploadPath}/$filePrefixEnd/{$fileName}",
		];
	}

	/**
	 * Get the internal storage path to the persisted file, whether it exists or not.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	private function getFileStoragePath( string $ipa, string $text, string $lang ): string {
		return $this->getFileProperties( $ipa, $text, $lang )[ 'dest_storage_path' ];
	}

	/**
	 * Get the unique filename for the given set of Phonos parameters, including the file extension.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	private function getFileName( string $ipa, string $text, string $lang ): string {
		return $this->getFileProperties( $ipa, $text, $lang )['fileName'];
	}

	/**
	 * Get the full path to the
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	private function getFullFileStoragePath( string $ipa, string $text, string $lang ): string {
		return $this->getFileStoragePath( $ipa, $text, $lang ) . '/' .
			$this->getFileName( $ipa, $text, $lang );
	}

	/**
	 * Generate file expiry with some deviations
	 * to minimize flooding on object expiration
	 * @return int
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

	/**
	 * Expose upload path for use in maintenance scripts.
	 *
	 * @return string
	 */
	final public function getUploadPath(): string {
		return $this->uploadPath;
	}

}
