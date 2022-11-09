<?php

namespace MediaWiki\Extension\Phonos\Engine;

use Config;
use DOMDocument;
use FileBackendGroup;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Shell\CommandFactory;
use WANObjectCache;

class LarynxEngine extends Engine {

	/** @var string */
	protected $apiEndpoint;

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param CommandFactory $commandFactory
	 * @param FileBackendGroup $fileBackendGroup
	 * @param WANObjectCache $wanCache
	 * @param Config $config
	 */
	public function __construct(
		HttpRequestFactory $requestFactory,
		CommandFactory $commandFactory,
		FileBackendGroup $fileBackendGroup,
		WANObjectCache $wanCache,
		Config $config
	) {
		parent::__construct( $requestFactory, $commandFactory, $fileBackendGroup, $wanCache, $config );
		$this->apiEndpoint = $config->get( 'PhonosApiEndpointLarynx' );
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 * @throws PhonosException
	 */
	public function getAudioData( string $ipa, string $text, string $lang ): string {
		$persistedAudio = $this->getPersistedAudio( $ipa, $text, $lang );
		if ( $persistedAudio ) {
			return $persistedAudio;
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
			throw new PhonosException( 'phonos-engine-error', [ 'Larynx', $error ] );
		}

		if ( $this->storeFilesAsMp3 ) {
			$out = $this->convertWavToMp3( $request->getContent() );
		} else {
			$out = $request->getContent();
		}

		$this->persistAudio( $ipa, $text, $lang, $out );

		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function getSsml( string $ipa, string $text, string $lang ): string {
		$ipa = trim( implode( ' ', mb_str_split( $ipa ) ) );

		$ssmlDoc = new DOMDocument( '1.0' );

		$speakNode = $ssmlDoc->createElement( 'speak' );
		$speakNode->setAttribute( 'xmlns', 'http://www.w3.org/2001/10/synthesis' );
		$speakNode->setAttribute( 'version', '1.1' );
		$speakNode->setAttribute( 'xml:lang', $lang );
		$ssmlDoc->appendChild( $speakNode );

		/**
		 * Adds the following to the <speak> node:
		 *   <lexicon alphabet="ipa">
		 *     <lexeme>
		 *       <grapheme>{$text}</grapheme>
		 *       <phoneme>{$ipa}</phoneme>
		 *     </lexeme>
		 *   </lexicon>
		 */
		$lexiconNode = $ssmlDoc->createElement( 'lexicon' );
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
		 *   <w>{$text}</w>
		 */
		$wNode = $ssmlDoc->createElement( 'w', $text );
		$speakNode->appendChild( $wNode );

		return $ssmlDoc->saveXML();
	}
}
