<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\EspeakEngine;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\TestCase;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Engine\EspeakEngine
 */
class EspeakEngineTest extends TestCase {

	public function testGetSsml(): void {
		$services = MediaWikiServices::getInstance();
		$engine = new EspeakEngine(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainConfig()
		);
		$this->assertSame(
			"<?xml version=\"1.0\"?>\n" .
				"<speak xmlns=\"http://www.w3.org/2001/10/synthesis\" version=\"1.1\" xml:lang=\"en\">" .
				"<phoneme alphabet=\"ipa\" ph=\"/h&#x259;&#x2C8;v&#xE6;n&#x259;/\">Havana</phoneme></speak>\n",
			$engine->getSsml( '/həˈvænə/', 'Havana', 'en' )
		);
	}
}
