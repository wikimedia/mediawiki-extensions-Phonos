<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Engine\EngineInterface;
use MediaWiki\Extension\Phonos\Engine\EspeakEngine;
use MediaWiki\MediaWikiServices;
use Monolog\Test\TestCase;
use WikiMap;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Engine\Engine
 */
class EngineTest extends TestCase {

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

	public function testGetCachedAudio(): void {
		$args = [ '/həˈvænə/', 'Havana', 'en', 'foobar' ];
		$this->engine->cacheAudio( ...$args );
		$this->assertSame(
			'foobar',
			$this->engine->getCachedAudio( ...$args )
		);
	}

	public function testIsCached(): void {
		$ipa = '/həˈvænə/';
		$text = 'Havana';
		$lang = 'en';
		$this->engine->cacheAudio( $ipa, $text, $lang, 'foobar' );
		$this->assertTrue( $this->engine->isCached( $ipa, $text, $lang ) );
	}

	public function testGetAudioUrl(): void {
		$args = [ '/həˈvænə/', 'Havana', 'en' ];
		$this->assertSame(
			"{$this->uploadPath}/" . WikiMap::getCurrentWikiId() . "-phonos/" . $this->engine->getFileName( ...$args ),
			$this->engine->getAudioUrl( ...$args )
		);
	}
}
