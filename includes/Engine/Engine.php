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
use NullLockManager;
use ReflectionClass;
use Status;
use WikiMap;

/**
 * Contains logic common to all Engines.
 */
abstract class Engine implements EngineInterface {

	/** @var int Version for cache invalidation. */
	private const CACHE_VERSION = 1;

	/** @var HttpRequestFactory */
	protected $requestFactory;

	/** @var FileBackend */
	protected $fileBackend;

	/** @var string */
	protected $apiProxy;

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param FileBackendGroup $fileBackendGroup
	 * @param Config $config
	 */
	public function __construct(
		HttpRequestFactory $requestFactory,
		FileBackendGroup $fileBackendGroup,
		Config $config
	) {
		$this->requestFactory = $requestFactory;
		$this->fileBackend = self::getFileBackend( $fileBackendGroup, $config );
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
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
			$config->get( MainConfigNames::UploadDirectory );

		return new FSFileBackend( [
			'name' => 'phonos-backend',
			'basePath' => $uploadDirectory,
			'wikiId' => WikiMap::getCurrentWikiId(),
			'lockManager' => new NullLockManager( [] ),
			'fileMode' => 0777,
			'directoryMode' => 0777,
			'obResetFunc' => 'wfResetOutputBuffers',
			'streamMimeFunc' => [ 'StreamFile', 'contentTypeFromPath' ],
			'logger' => LoggerFactory::getInstance( 'phonos' ),
		] );
	}

	/**
	 * Cache the given audio data using the configured storage backend.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @param string $data
	 * @return void
	 * @throws PhonosException
	 */
	final public function cacheAudio( string $ipa, string $text, string $lang, string $data ): void {
		$status = $this->fileBackend->prepare( [
			'dir' => $this->getStoragePath(),
		] );
		if ( !$status->isOK() ) {
			throw new PhonosException(
				'Unable to create storage directory: ' . Status::wrap( $status )->getMessage()->text()
			);
		}

		// Create the file.
		$status = $this->fileBackend->create( [
			'dst' => $this->getCacheFileDest( $ipa, $text, $lang ),
			'content' => $data,
			'overwriteSame' => true,
		] );
		if ( !$status->isOK() ) {
			throw new PhonosException(
				'Unable to create audio file: ' . Status::wrap( $status )->getMessage()->text()
			);
		}
	}

	/**
	 * Fetch the contents of the cached file in the storage backend, or null if the file doesn't exist.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string|null
	 */
	final public function getCachedAudio( string $ipa, string $text, string $lang ): ?string {
		$params = [
			'src' => $this->getCacheFileDest( $ipa, $text, $lang ),
		];
		if ( !$this->fileBackend->fileExists( $params ) ) {
			return null;
		}
		return $this->fileBackend->getFileContents( $params );
	}

	/**
	 * Get the full path to the cached file, whether it exists or not.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	private function getCacheFileDest( string $ipa, string $text, string $lang ): string {
		// Using ReflectionClass to get the unqualified class name is actually faster than doing string operations.
		$engineName = ( new ReflectionClass( get_class( $this ) ) )->getShortName();
		$cacheKey = md5( implode( '|', [ $engineName, $ipa, $text, $lang, self::CACHE_VERSION ] ) );
		return $this->getStoragePath() . "/$cacheKey.wav";
	}

	/**
	 * Get the path to where Phonos files are stored.
	 *
	 * @return string
	 */
	private function getStoragePath(): string {
		return $this->fileBackend->getRootStoragePath() . '/phonos';
	}

}
