<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\AudioParams;
use MediaWiki\Extension\Phonos\Engine\EngineInterface;
use MediaWiki\Extension\Phonos\Engine\EspeakEngine;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Engine\Engine
 */
class EngineTest extends MediaWikiIntegrationTestCase {

	private EngineInterface $engine;
	private string $uploadPath;

	public function setUp(): void {
		parent::setUp();
		$services = $this->getServiceContainer();
		$this->engine = new EspeakEngine(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainObjectStash(),
			$services->getMainWANObjectCache(),
			$services->getContentLanguage(),
			$services->getMainConfig()
		);
		$this->uploadPath = $services->getMainConfig()->get( MainConfigNames::UploadPath );
	}

	public function testGetPersistedAudio(): void {
		$args = [ new AudioParams( '/həˈvænə/', 'Havana', 'en' ), 'foobar' ];
		$this->engine->persistAudio( ...$args );
		$this->assertSame(
			'foobar',
			$this->engine->getPersistedAudio( ...$args )
		);
	}

	public function testIsPersisted(): void {
		$args = [ new AudioParams( '/həˈvænə/', 'Havana', 'en' ), 'foobar' ];
		$this->engine->persistAudio( ...$args );
		$this->assertTrue( $this->engine->isPersisted( ...$args ) );
	}

	public function testGetFileUrl(): void {
		// We know how the cache key is generated, so we know what the final URL should be.
		$this->assertSame(
			"{$this->uploadPath}/phonos-render/0/8/08h2h100e3dgycsfj2my0oc8ll84q3a.mp3",
			$this->engine->getFileUrl( new AudioParams( '/həˈvænə/', 'Havana', 'en' ) )
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

	/**
	 * @dataProvider provideCheckLanguageSupport
	 */
	public function testCheckLanguageSupport( $langs, $inLang, $outLang ) {
		$engine = $this->createPartialMock( EspeakEngine::class, [ 'getSupportedLanguages' ] );
		$engine->method( 'getSupportedLanguages' )->willReturn( $langs );
		$this->assertSame( $outLang, $engine->checkLanguageSupport( $inLang ) );
	}

	public static function provideCheckLanguageSupport(): array {
		return [
			'existing' => [ [ 'en', 'de' ], 'de', 'de' ],
			'existing, case normalized' => [ [ 'aa', 'bb' ], 'BB', 'bb' ],
			'existing, underscore normalized' => [ [ 'aa-BB', 'bb-gg' ], 'aa_bb', 'aa-BB' ],
			'no supported langs' => [ null, 'de', 'de' ],
		];
	}
}
