<?php

namespace MediaWiki\Extension\Phonos;

use FileBackendGroup;
use JobQueueGroup;
use Language;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Extension\Phonos\Engine\EngineInterface;
use MediaWiki\Extension\Phonos\Engine\LarynxEngine;
use MediaWiki\Extension\Phonos\Wikibase\WikibaseEntityAndLexemeFetcher;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\CommandFactory;
use MediaWikiIntegrationTestCase;
use Parser;
use ParserOutput;
use WANObjectCache;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Phonos
 */
class PhonosTest extends MediaWikiIntegrationTestCase {

	protected function getEngineMock(): EngineInterface {
		// because of static methods (getFileBackend) in Engine we need to mock
		// FileBackendGroup
		return $this->getMockBuilder( LarynxEngine::class )
			->setConstructorArgs( [
				$this->createMock( HttpRequestFactory::class ),
				$this->createMock( CommandFactory::class ),
				$this->createMock( FileBackendGroup::class ),
				WANObjectCache::newEmpty(),
				MediaWikiServices::getInstance()->getMainConfig(),
			] )->onlyMethods( [ 'getAudioData', 'getFileUrl' ] )
			->getMock();
	}

	protected function getJobQueueGroupMock(): JobQueueGroup {
		return $this->createPartialMock( JobQueueGroup::class, [ 'push' ] );
	}

	protected function getParserMock(): Parser {
		$parserOutput = $this->createMock( ParserOutput::class );
		$parserLanguage = $this->createMock( Language::class );
		$parser = $this->getMockBuilder( Parser::class )
			->disableOriginalConstructor()
			->onlyMethods( [
				'getOutput',
				'getContentLanguage',
				'addTrackingCategory',
				'incrementExpensiveFunctionCount',
				'recursiveTagParseFully',
		] )->getMock();
		$parser->method( 'getOutput' )->willReturn( $parserOutput );
		$parser->method( 'getContentLanguage' )->willReturn( $parserLanguage );
		$parser->method( 'recursiveTagParseFully' )->willReturn( '' );

		return $parser;
	}

	protected function getWBELFMock(): WikibaseEntityAndLexemeFetcher {
		return $this->createMock( WikibaseEntityAndLexemeFetcher::class );
	}

	protected function getStatsdDataFactoryInterfaceMock(): StatsdDataFactoryInterface {
		return $this->createMock( StatsdDataFactoryInterface::class );
	}

	public function testCreateJobWhenInCliMode(): void {
		$this->overrideConfigValue( 'CommandLineMode', true );
		$this->overrideConfigValue( 'PhonosFileBackend', false );

		$services = MediaWikiServices::getInstance();

		$jobQueueGroupMock = $this->getJobQueueGroupMock();
		$jobQueueGroupMock->expects( $this->once() )->method( 'push' );

		$engineMock = $this->getEngineMock();
		$engineMock->expects( $this->once() )->method( 'getFileUrl' );

		$phonos = new Phonos(
			$services->getRepoGroup(),
			$engineMock,
			$this->getWBELFMock(),
			$this->getStatsdDataFactoryInterfaceMock(),
			$jobQueueGroupMock,
			$services->getMainConfig()
		);

		$args = [ 'lang' => 'en', 'text' => 'test', 'ipa' => 'tɛst' ];
		$phonos->renderPhonos( "test", $args, $this->getParserMock() );
	}

	public function testCreateJobWhenExpensiveFunctionCountHitsLimit(): void {
		$this->overrideConfigValue( 'CommandLineMode', false );
		$this->overrideConfigValue( 'PhonosFileBackend', false );

		$services = MediaWikiServices::getInstance();

		$jobQueueGroupMock = $this->getJobQueueGroupMock();
		$jobQueueGroupMock->expects( $this->once() )->method( 'push' );

		$engineMock = $this->getEngineMock();
		$engineMock->expects( $this->once() )->method( 'getFileUrl' );

		$phonos = new Phonos(
			$services->getRepoGroup(),
			$engineMock,
			$this->getWBELFMock(),
			$this->getStatsdDataFactoryInterfaceMock(),
			$jobQueueGroupMock,
			$services->getMainConfig()
		);

		$parserMock = $this->getParserMock();
		$parserMock->method( 'incrementExpensiveFunctionCount' )->willReturn( false );

		$args = [ 'lang' => 'en', 'text' => 'test', 'ipa' => 'tɛst' ];
		$phonos->renderPhonos( "test", $args, $parserMock );
	}

	public function testPersistAudioSynchronously(): void {
		$this->overrideConfigValue( 'CommandLineMode', false );
		$this->overrideConfigValue( 'PhonosFileBackend', false );

		$services = MediaWikiServices::getInstance();

		$engineMock = $this->getEngineMock();
		$engineMock->expects( $this->once() )->method( 'getAudioData' );
		$engineMock->expects( $this->once() )->method( 'getFileUrl' );

		$phonos = new Phonos(
			$services->getRepoGroup(),
			$engineMock,
			$this->getWBELFMock(),
			$this->getStatsdDataFactoryInterfaceMock(),
			$this->getJobQueueGroupMock(),
			$services->getMainConfig()
		);

		$parserMock = $this->getParserMock();
		$parserMock->method( 'incrementExpensiveFunctionCount' )->willReturn( true );

		$args = [ 'lang' => 'en', 'text' => 'test', 'ipa' => 'tɛst' ];
		$phonos->renderPhonos( "test", $args, $parserMock );
	}
}
