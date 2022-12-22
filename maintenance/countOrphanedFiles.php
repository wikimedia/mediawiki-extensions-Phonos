<?php

use MediaWiki\Content\Renderer\ContentRenderer;
use MediaWiki\Extension\Phonos\Engine\Engine;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to find and optionally delete orphaned Phonos files.
 *
 * WARNING: Currently this script only runs on a single wiki, while the files are inherently global.
 * Running the script after Phonos is deployed to a wiki farm may result in unnecessary deletion
 * (and later regeneration) of files. In the future, this script may be rewritten to support
 * a wiki farm, thus ensuring any orphaned files are in fact unused.
 *
 * If installed, use the --restbase flag to point to the RESTBase page/html endpoint.
 * This will make the script much faster. Example value: "https://en.wikipedia.org/api/rest_v1/page/html/"
 *
 * @ingroup Maintenance
 */
class CountOrphanedFiles extends Maintenance {

	/** @var HttpRequestFactory */
	private $requestFactory;

	/** @var ContentRenderer */
	private $contentRenderer;

	/** @var string */
	private $apiProxy;

	/** @var Engine */
	private $engine;

	/** @var FileBackend */
	private $backend;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Find and optionally delete orphaned Phonos files.' );
		$this->addOption( 'delete', 'Delete the orphaned files in addition to reporting how many there are.' );
		$this->addOption( 'limit', 'Number of files to fetch from the wiki (default 5000).', false, true );
		$this->addOption( 'restbase', 'URL to RESTBase page/html API, if available. This makes the script faster.' );
		$this->requireExtension( 'Phonos' );
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$this->requestFactory = $services->getHttpRequestFactory();
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
		$this->contentRenderer = $services->getContentRenderer();
		$this->engine = $services->get( 'Phonos.Engine' );
		$this->backend = Engine::getFileBackend(
			$services->getFileBackendGroup(),
			$config
		);

		$usedFileUrls = $this->fetchUsedFiles();
		$this->reportUnusedFiles( $usedFileUrls );
	}

	/**
	 * Iterate through the tracking category to find all Phonos files that are in-use.
	 *
	 * @return string[] Paths to the files relative to root storage path with Engine::STORAGE_PREFIX.
	 */
	private function fetchUsedFiles(): array {
		// Fetch the category name via system message, as it can be overridden by sysops.
		$categoryName = wfMessage( 'phonos-tracking-category' );
		$category = Category::newFromName( $categoryName );
		// Get the category members.
		$titles = $category->getMembers( $this->getOption( 'limit', 5000 ) );

		$this->output( 'Fetching in-use files from ' . iterator_count( $titles ) . " pages...\n" );
		$usedFiles = [];

		foreach ( $titles as $title ) {
			$xml = new SimpleXMLElement( $this->getPageHtml( $title ) );
			$elements = $xml->xpath( "//span[contains(@class, 'ext-phonos')]/a" );
			foreach ( $elements as $element ) {
				$href = (string)$element->attributes()->href;
				// Ensure it's a file created by Phonos.
				if ( strpos( $href, $this->engine->getUploadPath() ) === 0 ) {
					$usedFiles[] = $this->normalizeFileName( $href );
				}
			}
		}

		$usedFiles = array_unique( $usedFiles );
		$this->output( count( $usedFiles ) . " files found.\n" );

		return $usedFiles;
	}

	/**
	 * Get the HTML of the given page.
	 *
	 * @param Title $title
	 * @return string
	 */
	private function getPageHtml( Title $title ): string {
		// Use RESTBase if instructed.
		if ( $this->getOption( 'restbase' ) ) {
			$request = $this->requestFactory->create(
				$this->getOption( 'restbase' ) . $title->getDBkey(),
				[ 'proxy' => $this->apiProxy ]
			);
			$request->setHeader( 'accept', 'text/html' );
			$status = $request->execute();
			if ( !$status->isOK() ) {
				$this->fatalError( "Could not fetch HTML for: " . $title->getDBkey() );
			}
			return $request->getContent();
		}

		// Parse and return the wikitext.
		$wikiPage = new WikiPage( $title );
		return $this->contentRenderer
			->getParserOutput( $wikiPage->getContent(), $title )
			->getText();
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
			if ( !in_array( $file, $usedFiles ) ) {
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

	/**
	 * Return the file name along with the two hash levels.
	 *
	 * @param string $input May be a URL or storage path.
	 * @return string For example "/a/b/abc.mp3"
	 */
	private function normalizeFileName( string $input ): string {
		$parts = explode( '/', $input );
		return implode( '/', array_slice( $parts, -3 ) );
	}
}

$maintClass = CountOrphanedFiles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
