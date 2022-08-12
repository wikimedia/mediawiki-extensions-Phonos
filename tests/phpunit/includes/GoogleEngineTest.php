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
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainConfig()
		);
		$this->assertSame(
			"<speak><phoneme alphabet=\"ipa\" " .
				"ph=\"&#x2C8;h&#x28C;s&#x259;n &#x2C8;m&#x26A;nh&#x251;&#x2D0;d&#x292;\">" .
				"Hasan Minhaj</phoneme></speak>",
			$engine->getSsml( "/'hʌsən 'mɪnhɑː(d)ʒ/", 'Hasan Minhaj', 'en' )
		);
	}

}
