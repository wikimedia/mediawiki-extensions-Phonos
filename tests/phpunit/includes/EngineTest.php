<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\EngineInterface;
use MediaWiki\Extension\Phonos\Engine\EspeakEngine;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use WikiMap;

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
		$ipa = '/həˈvænə/';
		$text = 'Havana';
		$lang = 'en';
		$this->engine->persistAudio( $ipa, $text, $lang, 'foobar' );
		$this->assertTrue( $this->engine->isPersisted( $ipa, $text, $lang ) );
	}

	public function testGetAudioUrl(): void {
		$args = [ '/həˈvænə/', 'Havana', 'en' ];
		$this->assertSame(
			"{$this->uploadPath}/" . WikiMap::getCurrentWikiId() . "-phonos/" . $this->engine->getFileName( ...$args ),
			$this->engine->getAudioUrl( ...$args )
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
