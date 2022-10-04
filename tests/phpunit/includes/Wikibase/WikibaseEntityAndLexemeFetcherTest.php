<?php

namespace MediaWiki\Extension\Phonos\Wikibase;

use HashConfig;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;
use MWHttpRequest;
use PHPUnit\Framework\MockObject\MockClass;
use Status;
use WANObjectCache;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Wikibase\WikibaseEntityAndLexemeFetcher
 */
class WikibaseEntityAndLexemeFetcherTest extends MediaWikiIntegrationTestCase {

	/**
	 * Get a mock request object with the given Wikibase entity as response.
	 * @param string $id
	 * @param mixed[] $entityData
	 * @return MWHttpRequest
	 */
	private function getEntityRequest( string $id, array $entityData ): MWHttpRequest {
		$request = $this->createMock( MWHttpRequest::class );
		$request->method( 'execute' )
			->willReturn( Status::newGood() );
		$request->method( 'getContent' )
			->willReturn( json_encode( [ 'entities' => [ $id => $entityData ] ] ) );
		return $request;
	}

	/**
	 * @dataProvider provideIPATranscription()
	 */
	public function testIPATranscription(
		string $lang, string $text, string $id, array $entityData, ?string $expected
	) {
		/** @var HttpRequestFactory|MockClass */
		$requestFactory = $this->createMock( HttpRequestFactory::class );

		$requestFactory
			->method( 'create' )
			->willReturnCallback( function ( $url ) use ( $id, $entityData ) {
				// Set up two languages manually.
				if ( strpos( $url, 'Q9043' ) ) {
					return $this->getEntityRequest( 'Q9043', [
						'claims' => [
							'P305' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => 'no' ] ] ] ]
						],
					] );
				} elseif ( strpos( $url, 'Q1860' ) ) {
					return $this->getEntityRequest( 'Q1860', [
						'claims' => [
							'P305' => [ [ 'mainsnak' => [ 'datavalue' => [ 'value' => 'en' ] ] ] ]
						],
					] );
				} else {
					return $this->getEntityRequest( $id, $entityData );
				}
			} );

		$cache = new WANObjectCache( [ 'cache' => MediaWikiServices::getInstance()->getMainObjectStash() ] );

		$config = new HashConfig( [
			'PhonosWikibaseProperties' => [
				'wikibasePronunciationAudioProp' => 'P443',
				'wikibaseLangNameProp' => 'P407',
				'wikibaseIETFLangTagProp' => 'P305',
				'wikibaseIPATranscriptionProp' => 'P898',
			],
			'PhonosWikibaseUrl' => 'base-url',
			'PhonosCommonsMediaUrl' => 'commons-url',
			'PhonosApiProxy' => false,
		] );

		$fetcher = new WikibaseEntityAndLexemeFetcher( $requestFactory, $cache, $config );
		$entity = $fetcher->fetch( $id, $text, $lang );
		$this->assertSame( $expected, $entity->getIPATranscription() );
	}

	public function provideIPATranscription(): array {
		return [

			'simple item' => [
				'lang' => 'no',
				'text' => 'Hello',
				'id' => 'Q1',
				'entity_data' => [
					'type' => 'entity',
					'claims' => [
						'P898' => [
							[
								'mainsnak' => [ 'datavalue' => [ 'value' => 'həˈləʊ' ] ],
								'qualifiers' => [
									'P407' => [
										[ 'datavalue' => [ 'value' => [ 'id' => 'Q9043' ] ] ],
									]
								]
							]
						]
					],
				],
				'expected' => 'həˈləʊ',
			],

			'item with IPA in wrong language' => [
				'lang' => 'en',
				'text' => 'Hello',
				'id' => 'Q1',
				'entity_data' => [
					'type' => 'entity',
					'claims' => [
						'P898' => [
							[
								'mainsnak' => [ 'datavalue' => [ 'value' => 'həˈləʊ' ] ],
								'qualifiers' => [
									'P407' => [
										[ 'datavalue' => [ 'value' => [ 'id' => 'Q9043' ] ] ],
									]
								]
							]
						]
					],
				],
				'expected' => null,
			],

			'item with two languages for IPA' => [
				'lang' => 'en',
				'text' => 'Hello',
				'id' => 'Q1',
				'entity_data' => [
					'type' => 'entity',
					'claims' => [
						'P898' => [
							[
								'mainsnak' => [ 'datavalue' => [ 'value' => 'həˈləʊ' ] ],
								'qualifiers' => [
									'P407' => [
										[ 'datavalue' => [ 'value' => [ 'id' => 'Q1860' ] ] ],
										[ 'datavalue' => [ 'value' => [ 'id' => 'Q9043' ] ] ],
									]
								]
							]
						]
					],
				],
				'expected' => 'həˈləʊ',
			],

		];
	}
}
