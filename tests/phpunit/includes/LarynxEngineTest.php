<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\AudioParams;
use MediaWiki\Extension\Phonos\Engine\LarynxEngine;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\Phonos\Engine\LarynxEngine
 * @group Phonos
 */
class LarynxEngineTest extends MediaWikiIntegrationTestCase {

	public function testGetSsml(): void {
		$services = $this->getServiceContainer();
		$engine = new LarynxEngine(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainObjectStash(),
			$services->getMainWANObjectCache(),
			$services->getContentLanguage(),
			$services->getMainConfig()
		);
		$this->assertSame(
			"<?xml version=\"1.0\"?>\n<speak xmlns=\"http://www.w3.org/2001/10/synthesis\" version=\"1.1\" " .
				"xml:lang=\"en\">" .
				"<phoneme alphabet=\"ipa\" ph=\"h&#x259;&#x2C8;l&#x259;&#x28A;\"><w>hello</w></phoneme></speak>\n",
			$engine->getSsml( new AudioParams( 'həˈləʊ', 'hello', 'en' ) )
		);
	}
}
