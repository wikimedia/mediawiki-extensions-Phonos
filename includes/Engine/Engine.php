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

/**
 * Contains logic common to all Engines.
 */
abstract class Engine implements EngineInterface {

	/** @var int Version for cache invalidation. */
	private const CACHE_VERSION = 1;

	/** @var string Prefix directory name when persisting files to storage. */
	private const STORAGE_PREFIX = 'phonos';

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
		$this->uploadPath = $config->get( 'PhonosPath' ) ?:
			$config->get( MainConfigNames::UploadPath ) . '/' . self::STORAGE_PREFIX;
		// Using ReflectionClass to get the unqualified class name is actually faster than doing string operations.
		$this->engineName = ( new ReflectionClass( get_class( $this ) ) )->getShortName();
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
	 * Get the relative URL to the persisted file, or create one if it doesn't exist.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	public function getFileUrl( string $ipa, string $text, string $lang ): string {
		$fileDest = $this->getFullFileStoragePath( $ipa, $text, $lang );
		$exists = $this->fileBackend->fileExists( [
			'src' => $fileDest,
		] );

		if ( !$exists ) {
			// Generate the audio and store the file first.
			$data = $this->getAudioData( $ipa, $text, $lang );
			$this->persistAudio( $ipa, $text, $lang, $data );
		}

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
		return $this->fileBackend->fileExists( [
			'src' => $this->getFullFileStoragePath( $ipa, $text, $lang ),
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
		$fileName = "$fileCacheName.mp3";
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

}
