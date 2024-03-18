<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\AudioParams;
use MediaWiki\Extension\Phonos\Engine\GoogleEngine;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWikiIntegrationTestCase;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Engine\GoogleEngine
 */
class GoogleEngineTest extends MediaWikiIntegrationTestCase {

	/** @var GoogleEngine */
	private $engine;

	public function setUp(): void {
		$services = $this->getServiceContainer();
		$this->engine = new GoogleEngine(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainObjectStash(),
			$services->getMainWANObjectCache(),
			$services->getMainConfig()
		);
	}

	public function testGetSsml(): void {
		$this->assertSame(
			'<speak><phoneme alphabet="ipa" ' .
				'ph="&#x2C8;h&#x28C;s&#x259;n &#x2C8;m&#x26A;nh&#x251;&#x2D0;(d)&#x292;">' .
				'Hasan Minhaj</phoneme></speak>',
			$this->engine->getSsml( new AudioParams( "/'hʌsən 'mɪnhɑː(d)ʒ/", 'Hasan Minhaj', 'en' ) )
		);
	}

	public function testMinimumFileSize(): void {
		$this->expectException( PhonosException::class );
		$this->expectExceptionMessage( 'phonos-empty-file-error' );
		$this->engine->persistAudio( new AudioParams( '', '', 'en' ), 'small amount of data' );
	}
}
