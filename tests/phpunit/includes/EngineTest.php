<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\EngineInterface;
use MediaWiki\Extension\Phonos\Engine\EspeakEngine;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Engine\Engine
 */
class EngineTest extends MediaWikiIntegrationTestCase {

	/** @var EngineInterface */
	private $engine;

	/** @var string */
	private $uploadPath;

	public function setUp(): void {
		parent::setUp();
		$services = MediaWikiServices::getInstance();
		$this->engine = new EspeakEngine(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainConfig()
		);
		$this->uploadPath = $services->getMainConfig()->get( 'UploadPath' );
	}

	public function testGetPersistedAudio(): void {
		$args = [ '/həˈvænə/', 'Havana', 'en', 'foobar' ];
		$this->engine->persistAudio( ...$args );
		$this->assertSame(
			'foobar',
			$this->engine->getPersistedAudio( ...$args )
		);
	}

	public function testIsPersisted(): void {
		$args = [ '/həˈvænə/', 'Havana', 'en', 'foobar' ];
		$this->engine->persistAudio( ...$args );
		$this->assertTrue( $this->engine->isPersisted( ...$args ) );
	}

	public function testGetFileUrl(): void {
		$args = [ '/həˈvænə/', 'Havana', 'en' ];
		// We know how the cache key is generated, so we know what the final URL should be.
		$this->assertSame(
			"{$this->uploadPath}/phonos-render/0/8/08h2h100e3dgycsfj2my0oc8ll84q3a.mp3",
			$this->engine->getFileUrl( ...$args )
		);
	}

	public function testPhonosExceptions(): void {
		$this->overrideConfigValues( [
			'PhonosLame' => '/invalid',
		] );
		$this->expectException( PhonosException::class );
		$this->expectExceptionMessage( 'phonos-audio-conversion-error' );
		$this->engine->convertWavToMp3( 'not valid binary data!' );
	}
}
