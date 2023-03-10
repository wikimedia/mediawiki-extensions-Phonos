<?php

use MediaWiki\Extension\Phonos\Engine\Engine;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\LBFactory;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to find and optionally delete orphaned Phonos files.
 *
 * On large wiki farms that use a config setting to control where extensions are deployed,
 * use the --with-setting flag to indicate which setting to use for Phonos, i.e. 'wmgUsePhonos'.
 * If not provided, the script will iterate over all sites on the farm.
 *
 * @ingroup Maintenance
 */
class CountOrphanedFiles extends Maintenance {

	/** @var LBFactory */
	private $lbFactory;

	/** @var SiteStore */
	private $siteStore;

	/** @var FileBackend */
	private $backend;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Find and optionally delete orphaned Phonos files across all wikis.' );
		$this->addOption( 'delete', 'Delete the orphaned files in addition to reporting how many there are.' );
		$this->addOption( 'with-setting', 'Only process wikis with this config setting.', false, true );
		$this->requireExtension( 'Phonos' );
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$this->lbFactory = $services->getDBLoadBalancerFactory();
		$this->siteStore = $services->getSiteStore();
		$this->backend = Engine::getFileBackend(
			$services->getFileBackendGroup(),
			$config
		);

		$usedFiles = [];
		/** @var MediaWikiSite $site */
		foreach ( $this->getSites() as $site ) {
			try {
				$usedFiles += $this->fetchUsedFiles( $site );
			} catch ( Exception $e ) {
				$this->output( $e->getMessage() . "\n" );
				continue;
			}
		}

		$this->output( count( $usedFiles ) . " in-use files found.\n" );

		$this->reportUnusedFiles( array_unique( $usedFiles ) );
	}

	/**
	 * Get an array of all the sites we need to query.
	 *
	 * If the --with-setting flag is used, only sites with this setting with a truthy value will
	 * be returned. This is useful to restrict querying to only sites with Phonos installed,
	 * for instance on the WMF cluster where the setting would be 'wmgUsePhonos'.
	 *
	 * If no config setting is passed, all sites on the farm are returned.
	 *
	 * @return SiteList
	 */
	private function getSites(): SiteList {
		$withSetting = $this->getOption( 'with-setting' );
		/** @var MediaWikiSite[]|SiteList $sites */
		$sites = $this->siteStore->getSites();
		if ( $sites->isEmpty() ) {
			// 'sites' table is probably not set up.
			// Assume this is a MW installation and act only on the current wiki.
			$site = new MediaWikiSite();
			$site->setGlobalId( WikiMap::getCurrentWikiId() );
			return new SiteList( [ $site ] );
		}
		if ( !$withSetting ) {
			return $sites;
		}
		$conf = new SiteConfiguration();
		// @phan-suppress-next-line PhanTypeMismatchArgumentInternal, PhanTypeMismatchReturnReal
		return array_filter( $sites, static function ( $site ) use ( $conf, $withSetting ) {
			// Assumes the `site_global_key` column in the `sites` table refers to the database name.
			return $conf->getConfig( $site->getGlobalId(), $withSetting );
		} );
	}

	/**
	 * Iterate through the tracking category to find all Phonos files that are in-use.
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
	 *
	 * @param array $usedFiles
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
	 *
	 * @param string $dir
	 * @param array $files
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
