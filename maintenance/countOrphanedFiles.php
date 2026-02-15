<?php

use MediaWiki\Extension\Phonos\Engine\Engine;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\Site\SiteList;
use MediaWiki\Site\SiteLookup;
use MediaWiki\Status\Status;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\FileBackend\FileBackend;
use Wikimedia\Rdbms\LBFactory;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to find and optionally delete orphaned Phonos files.
 *
 * On wiki farms, you can use the '--wikis' flag to specify which wikis to process, passing
 * in the global IDs (database names). If not provided, the script will loop through all
 * wikis as specified in the 'sites' table, and process any where Phonos is installed.
 * If the 'sites' table is not set up, the script will act only on the current wiki.
 *
 * @see https://www.mediawiki.org/wiki/Manual:AddSite.php
 *
 * @ingroup Maintenance
 */
class CountOrphanedFiles extends Maintenance {

	private HttpRequestFactory $requestFactory;
	private LBFactory $lbFactory;
	private SiteLookup $siteLookup;
	private FileBackend $backend;
	private ?string $apiProxy;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Find and optionally delete orphaned Phonos files across all wikis.' );
		$this->addOption( 'delete', 'Delete the orphaned files in addition to reporting how many there are.' );
		$this->addOption(
			'wikis',
			'Comma-separated list of db names. Only these wikis will be processed.',
			false,
			true
		);
		$this->requireExtension( 'Phonos' );
	}

	public function execute(): void {
		$services = $this->getServiceContainer();
		$config = $services->getMainConfig();
		$this->requestFactory = $services->getHttpRequestFactory();
		$this->apiProxy = $config->get( 'PhonosApiProxy' ) ?: null;
		$this->lbFactory = $services->getDBLoadBalancerFactory();
		$this->siteLookup = $services->getSiteLookup();
		$this->backend = Engine::getFileBackend(
			$services->getFileBackendGroup(),
			$config
		);

		$usedFiles = [];
		$skippedSites = 0;
		/** @var MediaWikiSite $site */
		foreach ( $this->getSites() as $site ) {
			try {
				$usedFiles = array_unique( array_merge( $usedFiles, $this->fetchUsedFiles( $site ) ) );
			} catch ( Throwable $e ) {
				$skippedSites++;
				$this->error( $e->getMessage() . "\n" );
				continue;
			}
		}

		$msg = count( $usedFiles ) . ' in-use files found.' .
			( $skippedSites > 0 ? " $skippedSites sites skipped due to errors." : '' );
		$this->output( "$msg\n" );

		$this->reportUnusedFiles( array_unique( $usedFiles ) );
	}

	/**
	 * Get an array of all the sites we need to query.
	 */
	private function getSites(): SiteList {
		$wikisOption = $this->getOption( 'wikis' );
		if ( $wikisOption ) {
			$wikis = explode( ',', $wikisOption );
			$sites = new SiteList();
			foreach ( $wikis as $wiki ) {
				/** @var MediaWikiSite $site */
				$site = $this->siteLookup->getSite( $wiki );
				// @phan-suppress-next-line PhanTypeMismatchArgumentSuperType
				if ( $site && $this->isExtensionInstalled( $site ) ) {
					$sites->setSite( $site );
				} else {
					$this->output( "Wiki '$wiki' not found or Phonos isn't installed, skipping...\n" );
				}
			}
		} else {
			$sites = $this->siteLookup->getSites();
		}

		if ( $sites->isEmpty() ) {
			// 'sites' table is probably not set up.
			// Assume this is a MW installation and act only on the current wiki.
			$id = WikiMap::getCurrentWikiId();
			$this->output( "sites table is empty, processing only $id...\n" );
			$site = new MediaWikiSite();
			$site->setGlobalId( $id );
			$sites->setSite( $site );
		}

		return $sites;
	}

	/**
	 * Query API:Siteinfo to determine if Phonos is installed on the given Site.
	 */
	private function isExtensionInstalled( MediaWikiSite $site ): bool {
		$wiki = $site->getGlobalId();
		if ( WikiMap::isCurrentWikiId( $wiki ) ) {
			// The API code will error out for local installations since MediaWiki-Docker
			// can't talk to localhost as if it were public. Phonos has to be installed
			// for the script to be ran anyway, so there's no need to check for the current wiki.
			return true;
		}

		try {
			$apiRoot = $site->getFileUrl( 'api.php' );
		} catch ( RuntimeException ) {
			$this->fatalError( "file_path not specified in the sites table for wiki '$wiki'.\n" );
		}

		$request = $this->requestFactory->create(
			$apiRoot . '?' . http_build_query( [
				'action' => 'query',
				'meta' => 'siteinfo',
				'siprop' => 'extensions',
				'format' => 'json'
			] ),
			[
				'proxy' => $this->apiProxy,
				'followRedirects' => true
			],
			__METHOD__
		);
		$status = $request->execute();
		if ( !$status->isOK() ) {
			$msg = $status->getMessage();
			$this->fatalError( "Could not fetch siteinfo for wiki '$wiki': $msg\n" );
		}

		$extensions = json_decode( $request->getContent(), true )['query']['extensions'] ?? [];
		foreach ( $extensions as $extension ) {
			if ( $extension['name'] === 'Phonos' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Query for the 'phonos-files' page property to find all Phonos files that are in-use.
	 *
	 * @param MediaWikiSite $site
	 * @return string[] Paths to the files relative to root storage path with Engine::STORAGE_PREFIX.
	 */
	private function fetchUsedFiles( MediaWikiSite $site ): array {
		$dbr = $this->lbFactory->getReplicaDatabase( $site->getGlobalId() );
		$queryBuilder = $dbr->newSelectQueryBuilder();
		$queryBuilder->select( 'pp_value' )
			->from( 'page_props' )
			->where( [ 'pp_propname' => 'phonos-files' ] );
		$props = $queryBuilder->caller( __METHOD__ )->fetchFieldValues();
		return array_unique( array_merge( ...array_map( 'json_decode', $props ) ) );
	}

	/**
	 * Reports the number of unused files in storage, optionally deleting them as well.
	 */
	private function reportUnusedFiles( array $usedFiles ): void {
		$this->output( "Finding unused files in storage...\n" );
		$dir = $this->backend->getRootStoragePath() . '/' . Engine::STORAGE_PREFIX;
		$filesToDelete = [];

		foreach (
			$this->backend->getFileList( [ 'dir' => $dir, 'adviseStat' => true ] ) as $file
		) {
			$slug = basename( $file, '.mp3' );
			if ( !in_array( $slug, $usedFiles ) ) {
				$fullPath = $dir . '/' . $file;
				$filesToDelete[] = [ 'op' => 'delete', 'src' => $fullPath ];
			}
		}

		$count = count( $filesToDelete );

		if ( $count ) {
			$this->output( $count . " unused files found.\n" );
			if ( $this->getOption( 'delete' ) ) {
				$this->deleteFiles( $dir, $filesToDelete );
			}
		} else {
			$this->output( "No unused files found!\n" );
		}
	}

	/**
	 * Delete the given files within the given directory.
	 * This operation is batched for performance reasons.
	 */
	private function deleteFiles( string $dir, array $files ): void {
		$this->output( "Deleting files from storage...\n" );
		$deletedCount = 0;
		foreach ( array_chunk( $files, 1000 ) as $chunk ) {
			$ret = $this->backend->doQuickOperations( $chunk );

			if ( $ret->isOK() ) {
				$deletedCount += count( $chunk );
				$this->output( "$deletedCount...\n" );
			} else {
				$status = Status::wrap( $ret );
				$this->output( "Deleting unused Phonos files errored.\n" );
				$this->fatalError( $status->getWikiText( false, false, 'en' ) );
			}
		}

		$this->output( "$deletedCount orphaned Phonos files deleted.\n" );

		// Remove empty directories.
		$ret = $this->backend->clean( [
			'dir' => $dir,
			'recursive' => true,
		] );
		if ( !$ret->isOK() ) {
			$status = Status::wrap( $ret );
			$this->output( "Cleaning empty directories errored.\n" );
			$this->fatalError( $status->getWikiText( false, false, 'en' ) );
		}
	}
}

$maintClass = CountOrphanedFiles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
