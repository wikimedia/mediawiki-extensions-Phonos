<?php

use MediaWiki\Extension\Phonos\Engine\Engine;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to delete old Phonos files from storage.
 *
 * Based on Extension:Score's DeleteOldScoreFiles.php, GPLv2+
 *
 * @ingroup Maintenance
 */
class DeleteOldPhonosFiles extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Deletes old Phonos files from storage" );
		$this->addOption(
			"date",
			'Delete Phonos files that were created before this date (e.g. 20220101000000)',
			true,
			true
		);
		$this->requireExtension( "Phonos" );
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$backend = Engine::getFileBackend(
			$services->getFileBackendGroup(),
			$services->getMainConfig()
		);
		$dir = $backend->getRootStoragePath() . '/' . Engine::STORAGE_PREFIX;

		$filesToDelete = [];
		$deleteDate = $this->getOption( 'date' );
		foreach (
			$backend->getFileList( [ 'dir' => $dir, 'adviseStat' => true ] ) as $file
		) {
			$fullPath = $dir . '/' . $file;
			$timestamp = $backend->getFileTimestamp( [ 'src' => $fullPath ] );
			if ( $timestamp < $deleteDate ) {
				$filesToDelete[] = [ 'op' => 'delete', 'src' => $fullPath ];
			}
		}

		$count = count( $filesToDelete );

		if ( !$count ) {
			$this->output( "No old Phonos files to delete.\n" );
			return;
		}

		$this->output( "$count old Phonos files to be deleted.\n" );

		$deletedCount = 0;
		foreach ( array_chunk( $filesToDelete, 1000 ) as $chunk ) {
			$ret = $backend->doQuickOperations( $chunk );

			if ( $ret->isOK() ) {
				$deletedCount += count( $chunk );
				$this->output( "$deletedCount...\n" );
			} else {
				$status = Status::wrap( $ret );
				$this->output( "Deleting old Phonos files errored.\n" );
				$this->fatalError( $status->getWikiText( false, false, 'en' ) );
			}
		}

		$this->output( "$deletedCount old Phonos files deleted.\n" );

		// Remove empty directories.
		$ret = $backend->clean( [
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

$maintClass = DeleteOldPhonosFiles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
