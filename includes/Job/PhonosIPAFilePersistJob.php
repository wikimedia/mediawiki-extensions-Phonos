<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extension\Phonos\Job;

use GenericParameterJob;
use Job;
use MediaWiki\Extension\Phonos\Engine\AudioParams;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Psr\Log\LoggerInterface;

/**
 * Generate audio file using text to speech engine
 *
 * This job will reach out to an external service and generate
 * pronunciation audio files based on IPA notation
 *
 * Some external services like Google have a rate limit and so this job
 * should be throttled accordingly using $wgJobBackoffThrottling
 */
class PhonosIPAFilePersistJob extends Job implements GenericParameterJob {
	/** @var LoggerInterface */
	protected $logger;

	/**
	 * @inheritDoc
	 */
	public function __construct( array $params ) {
		parent::__construct( 'phonosIPAFilePersist', $params );
		$this->removeDuplicates = true;
		$this->logger = LoggerFactory::getInstance( 'Phonos' );
		$this->logger->info(
			__METHOD__ . ' Job created',
			[
				'params' => $params
			]
		);
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$this->logger->info(
			__METHOD__ . ' Job being run',
			[
				'params' => $this->params
			]
		);
		$engine = MediaWikiServices::getInstance()->get( 'Phonos.Engine' );
		$params = new AudioParams(
			$this->params['ipa'],
			$this->params['text'],
			$this->params['lang']
		);

		try {
			$engine->getAudioData( $params );
			$engine->clearError( $params );

		} catch ( PhonosException $e ) {
			$engine->setError( $params, $e->getMessageKeyAndArgs() );

			$this->logger->error(
				__METHOD__ . ' Job failed',
				[
					'params' => $this->params,
					'exception' => $e
				]
			);
			$statsdDataFactory = MediaWikiServices::getInstance()->get( 'StatsdDataFactory' );
			$key = $e->getStatsdKey();
			$statsdDataFactory->increment( "extension.Phonos.error.$key" );

			throw $e;
		}

		return true;
	}
}
