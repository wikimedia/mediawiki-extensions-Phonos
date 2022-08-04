<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\GoogleEngine;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\TestCase;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Engine\GoogleEngine
 */
class GoogleEngineTest extends TestCase {

	public function testGetSsml(): void {
		$services = MediaWikiServices::getInstance();
		$engine = new GoogleEngine(
			$services->getHttpRequestFactory(),
			$services->getMainConfig()
		);
		$this->assertSame(
			"<speak><phoneme alphabet=\"ipa\" ph=\"h&#x259;&#x2C8;v&#xE6;n&#x259;\">Havana</phoneme></speak>",
			$engine->getSsml( '/həˈvænə/', 'Havana', 'en' )
		);
	}

}
