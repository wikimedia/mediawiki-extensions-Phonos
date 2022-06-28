<?php

namespace MediaWiki\Extension\Phonos\Engine;

use Config;
use ConfigException;
use DOMDocument;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Http\HttpRequestFactory;

class LarynxEngine implements EngineInterface {

	/** @var HttpRequestFactory */
	protected $requestFactory;

	/** @var string */
	protected $apiEndpoint;

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param Config $config
	 */
	public function __construct( HttpRequestFactory $requestFactory, Config $config ) {
		$this->requestFactory = $requestFactory;

		$configName = 'PhonosApiEndpointLarynx';
		if ( $config->has( $configName ) ) {
			$this->apiEndpoint = $config->get( $configName );
		} else {
			throw new ConfigException( "$configName must be set for the LarynxEngine" );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getAudioData( string $ipa, string $text, string $lang ): string {
		$ssml = trim( $this->getSsml( $ipa, $text, $lang ) );
		$url = $this->apiEndpoint . '?' . http_build_query( [
			'ssml' => true,
			// TODO: should the voice be configurable, too?
			'voice' => 'en-us/blizzard_lessac-glow_tts',
			'text' => $ssml,
		] );
		$request = $this->requestFactory->create( $url, [ 'method' => 'GET' ], __METHOD__ );
		$status = $request->execute();

		if ( !$status->isOK() ) {
			$error = $status->getMessage()->text();
			throw new PhonosException(
				'Unable to retrieve audio using the Larynx engine: ' . $error
			);
		}

		return $request->getContent();
	}

	/**
	 * @inheritDoc
	 */
	public function getSsml( string $ipa, string $text, string $lang ): string {
		$ssmlDoc = new DOMDocument( '1.0' );
		$ipaId = 'ipaInput';

		$speakNode = $ssmlDoc->createElement( 'speak' );
		$speakNode->setAttribute( 'xmlns', 'http://www.w3.org/2001/10/synthesis' );
		$speakNode->setAttribute( 'version', '1.1' );
		$speakNode->setAttribute( 'xml:lang', $lang );
		$ssmlDoc->appendChild( $speakNode );

		/**
		 * Adds the following to the <speak> node:
		 *   <lexicon xml:id="ipaInput" alphabet="ipa">
		 *     <lexeme>
		 *       <grapheme>{$text}</grapheme>
		 *       <phoneme>{$ipa}</phoneme>
		 *     </lexeme>
		 *   </lexicon>
		 */
		$lexiconNode = $ssmlDoc->createElement( 'lexicon' );
		$lexiconNode->setAttribute( 'xml:id', $ipaId );
		$lexiconNode->setAttribute( 'alphabet', 'ipa' );
		$lexemeNode = $ssmlDoc->createElement( 'lexeme' );
		$graphemeNode = $ssmlDoc->createElement( 'grapheme', $text );
		$lexemeNode->appendChild( $graphemeNode );
		$phonemeNode = $ssmlDoc->createElement( 'phoneme', $ipa );
		$lexemeNode->appendChild( $phonemeNode );
		$lexiconNode->appendChild( $lexemeNode );
		$speakNode->appendChild( $lexiconNode );

		/**
		 * Adds the following to the <speak> node:
		 *   <lookup ref="ipaInput">
		 *     <w>{$text}</w>
		 *   </lookup>
		 */
		$lookupNode = $ssmlDoc->createElement( 'lookup' );
		$lookupNode->setAttribute( 'ref', $ipaId );
		$wNode = $ssmlDoc->createElement( 'w', $text );
		$lookupNode->appendChild( $wNode );
		$speakNode->appendChild( $lookupNode );

		return $ssmlDoc->saveXML();
	}
}
