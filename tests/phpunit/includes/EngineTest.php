<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\EspeakEngine;
use MediaWiki\MediaWikiServices;
use Monolog\Test\TestCase;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Engine\Engine
 */
class EngineTest extends TestCase {

	public function testAudioCaching(): void {
		$services = MediaWikiServices::getInstance();
		$engine = new EspeakEngine(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainConfig()
		);

		$args = [ '/həˈvænə/', 'Havana', 'en', 'foobar' ];
		$engine->cacheAudio( ...$args );
		$this->assertSame(
			'foobar',
			$engine->getCachedAudio( ...$args )
		);
	}
}
