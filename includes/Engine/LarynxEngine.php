<?php

namespace MediaWiki\Extension\Phonos\Engine;

use Config;
use DOMDocument;
use FileBackendGroup;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Shell\CommandFactory;

class LarynxEngine extends Engine {

	/** @var string */
	protected $apiEndpoint;

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param CommandFactory $commandFactory
	 * @param FileBackendGroup $fileBackendGroup
	 * @param Config $config
	 */
	public function __construct(
		HttpRequestFactory $requestFactory,
		CommandFactory $commandFactory,
		FileBackendGroup $fileBackendGroup,
		Config $config
	) {
		parent::__construct( $requestFactory, $commandFactory, $fileBackendGroup, $config );
		$this->apiEndpoint = $config->get( 'PhonosApiEndpointLarynx' );
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 */
	public function getAudioData( string $ipa, string $text, string $lang ): string {
		$cachedAudio = $this->getCachedAudio( $ipa, $text, $lang );
		if ( $cachedAudio ) {
			return $cachedAudio;
		}

		$ssml = trim( $this->getSsml( $ipa, $text, $lang ) );
		$url = $this->apiEndpoint . '?' . http_build_query( [
			'ssml' => true,
			// TODO: should the voice be configurable, too?
			'voice' => 'en-us/blizzard_lessac-glow_tts',
			'text' => $ssml,
		] );
		$options = [
			'method' => 'GET'
		];

		if ( $this->apiProxy ) {
			$options['proxy'] = $this->apiProxy;
		}

		$request = $this->requestFactory->create(
			$url,
			$options,
			__METHOD__
		);
		$status = $request->execute();

		if ( !$status->isOK() ) {
			$error = $status->getMessage()->text();
			throw new PhonosException(
				'Unable to retrieve audio using the Larynx engine: ' . $error
			);
		}

		$mp3Data = $this->convertWavToMp3( $request->getContent() );
		$this->cacheAudio( $ipa, $text, $lang, $mp3Data );

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
