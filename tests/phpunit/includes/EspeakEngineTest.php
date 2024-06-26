<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\AudioParams;
use MediaWiki\Extension\Phonos\Engine\EspeakEngine;
use MediaWikiIntegrationTestCase;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Engine\EspeakEngine
 */
class EspeakEngineTest extends MediaWikiIntegrationTestCase {

	public function testGetSsml(): void {
		$services = $this->getServiceContainer();
		$engine = new EspeakEngine(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainObjectStash(),
			$services->getMainWANObjectCache(),
			$services->getContentLanguage(),
			$services->getMainConfig()
		);
		$this->assertSame(
			"<?xml version=\"1.0\"?>\n" .
				"<speak xmlns=\"http://www.w3.org/2001/10/synthesis\" version=\"1.1\" xml:lang=\"en\">" .
				"<phoneme alphabet=\"ipa\" ph=\"/h&#x259;&#x2C8;v&#xE6;n&#x259;/\">Havana</phoneme></speak>\n",
			$engine->getSsml( new AudioParams( '/həˈvænə/', 'Havana', 'en' ) )
		);
	}

	/**
	 * @dataProvider provideGetLangsFromOutput
	 */
	public function testGetLangsFromOutput( string $output, array $result ) {
		$services = $this->getServiceContainer();
		$engine = new EspeakEngine(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainObjectStash(),
			$services->getMainWANObjectCache(),
			$services->getContentLanguage(),
			$services->getMainConfig()
		);
		$this->assertSame( $result, $engine->getLangsFromOutput( $output ) );
	}

	public static function provideGetLangsFromOutput() {
		return [
			[
				'
Pty Language Age/Gender VoiceName          File          Other Languages
 5  af             M  afrikaans            other/af
 5  an             M  aragonese            europe/an
 5  bg             -  bulgarian            europe/bg
',
				[ 'af', 'an', 'bg' ],
			]
		];
	}
}
