<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\LarynxEngine;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Extension\Phonos\Engine\LarynxEngine
 * @group Phonos
 */
class LarynxEngineTest extends TestCase {

	public function testGetSsml(): void {
		$services = MediaWikiServices::getInstance();
		$engine = new LarynxEngine(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainWANObjectCache(),
			$services->getMainConfig()
		);
		$this->assertSame(
			"<?xml version=\"1.0\"?>\n<speak xmlns=\"http://www.w3.org/2001/10/synthesis\" version=\"1.1\" " .
				"xml:lang=\"en\"><lexicon alphabet=\"ipa\"><lexeme><grapheme>hello</grapheme>" .
				"<phoneme>h &#x259; &#x2C8; l &#x259; &#x28A;</phoneme></lexeme></lexicon>" .
				"<w>hello</w></speak>\n",
			$engine->getSsml( 'həˈləʊ', 'hello', 'en' )
		);
	}
}
