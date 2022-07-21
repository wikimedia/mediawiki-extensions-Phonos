<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\GoogleEngine;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\TestCase;

/**
 * @group Phonos
 */
class GoogleEngineTest extends TestCase {

	/**
	 * @covers GoogleEngine::getSsml
	 */
	public function testGetSsml(): void {
		$services = MediaWikiServices::getInstance();
		$engine = new GoogleEngine(
			$services->getHttpRequestFactory(),
			$services->getMainConfig()
		);
		$this->assertSame(
			"<?xml version=\"1.0\"?>\n<speak><lang xml:lang=\"en\"><phoneme alphabet=\"ipa\" " .
				"ph=\"h&#x259;&#x2C8;v&#xE6;n&#x259;\">Havana</phoneme></lang></speak>\n",
			$engine->getSsml( 'həˈvænə', 'Havana', 'en' )
		);
	}

}
