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
use MediaWiki\Shell\CommandFactory;
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

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param CommandFactory $commandFactory
	 * @param FileBackendGroup $fileBackendGroup
	 * @param Config $config
	 */
	public function __construct(
		HttpRequestFactory $requestFactory,
		CommandFactory $commandFactory,
		FileBackendGroup $fileBackendGroup,
		Config $config
	) {
		$this->requestFactory = $requestFactory;
		$this->commandFactory = $commandFactory;
		$this->fileBackend = self::getFileBackend( $fileBackendGroup, $config );
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
		$this->lamePath = $config->get( 'PhonosLame' );
		$this->uploadPath = $config->get( 'PhonosPath' ) ?: $config->get( MainConfigNames::UploadPath );
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
	 * Get the relative URL to the cached file, or create one if it doesn't exist.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	public function getAudioUrl( string $ipa, string $text, string $lang ): string {
		$fileDest = $this->getFileDest( $ipa, $text, $lang );
		$exists = $this->fileBackend->fileExists( [
			'src' => $fileDest,
		] );

		if ( !$exists ) {
			// Generate the audio and store the file first.
			$data = $this->getAudioData( $ipa, $text, $lang );
			$this->cacheAudio( $ipa, $text, $lang, $data );
		}

		if ( $this->fileBackend instanceof FSFileBackend ) {
			// FileBackend::getFileHttpUrl() is not supported by FSFileBackend
			return "{$this->uploadPath}/" . WikiMap::getCurrentWikiId() .
				"-phonos/" . $this->getFileName( $ipa, $text, $lang );
		} else {
			return $this->fileBackend->getFileHttpUrl( [
				'src' => $fileDest,
			] );
		}
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
			throw new PhonosException( 'phonos-directory-error', [
				Status::wrap( $status )->getMessage()->text(),
			] );
		}

		// Create the file.
		$status = $this->fileBackend->quickCreate( [
			'dst' => $this->getFileDest( $ipa, $text, $lang ),
			'content' => $data,
			'overwriteSame' => true,
		] );
		if ( !$status->isOK() ) {
			throw new PhonosException( 'phonos-storage-error', [
				Status::wrap( $status )->getMessage()->text()
			] );
		}
	}

	/**
	 * Fetch the contents of the cached file in the storage backend, or null if the file doesn't exist.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string|null base64 data, or null if the file doesn't exist.
	 */
	final public function getCachedAudio( string $ipa, string $text, string $lang ): ?string {
		if ( !$this->isCached( $ipa, $text, $lang ) ) {
			return null;
		}
		return $this->fileBackend->getFileContents( [
			'src' => $this->getFileDest( $ipa, $text, $lang ),
		] );
	}

	/**
	 * Is there a cached file for the given parameters?
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return bool
	 */
	final public function isCached( string $ipa, string $text, string $lang ): bool {
		return $this->fileBackend->fileExists( [
			'src' => $this->getFileDest( $ipa, $text, $lang ),
		] );
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
	 * Get the full storage path to the cached file, whether it exists or not.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	private function getFileDest( string $ipa, string $text, string $lang ): string {
		return $this->getStoragePath() . '/' . $this->getFileName( $ipa, $text, $lang );
	}

	/**
	 * Get a unique filename for the given set of Phonos parameters, including the file extension.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	public function getFileName( string $ipa, string $text, string $lang ): string {
		// Using ReflectionClass to get the unqualified class name is actually faster than doing string operations.
		$engineName = ( new ReflectionClass( get_class( $this ) ) )->getShortName();
		$cacheKey = md5( implode( '|', [ $engineName, $ipa, $text, $lang, self::CACHE_VERSION ] ) );
		return "$cacheKey.mp3";
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
