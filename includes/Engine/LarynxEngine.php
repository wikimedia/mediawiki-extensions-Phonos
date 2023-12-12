<?php

namespace MediaWiki\Extension\Phonos\Engine;

use BagOStuff;
use DOMDocument;
use FileBackendGroup;
use MediaWiki\Config\Config;
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
	 * @param BagOStuff $stash
	 * @param WANObjectCache $wanCache
	 * @param Config $config
	 */
	public function __construct(
		HttpRequestFactory $requestFactory,
		CommandFactory $commandFactory,
		FileBackendGroup $fileBackendGroup,
		BagOStuff $stash,
		WANObjectCache $wanCache,
		Config $config
	) {
		parent::__construct( $requestFactory, $commandFactory, $fileBackendGroup, $stash, $wanCache, $config );
		$this->apiEndpoint = $config->get( 'PhonosApiEndpointLarynx' );
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 * @throws PhonosException
	 */
	public function getAudioData( AudioParams $params ): string {
		$persistedAudio = $this->getPersistedAudio( $params );
		if ( $persistedAudio ) {
			return $persistedAudio;
		}

		$ssml = trim( $this->getSsml( $params ) );
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

		$out = $this->convertWavToMp3( $request->getContent() );
		$this->persistAudio( $params, $out );

		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function getSsml( AudioParams $params ): string {
		$ipa = trim( $params->getIpa() );
		$text = $params->getText() ?: $ipa;

		$ssmlDoc = new DOMDocument( '1.0' );

		$speakNode = $ssmlDoc->createElement( 'speak' );
		$speakNode->setAttribute( 'xmlns', 'http://www.w3.org/2001/10/synthesis' );
		$speakNode->setAttribute( 'version', '1.1' );
		$speakNode->setAttribute( 'xml:lang', $params->getLang() );
		$ssmlDoc->appendChild( $speakNode );

		/**
		 * Adds the following to the <speak> node:
		 *   <phoneme alphabet="ipa" ph={$ipa}>
		 *    <w>{$text} ?: {$ipa}</w>
		 *   </phoneme>
		 */

		$phonemeNode = $ssmlDoc->createElement( 'phoneme' );
		$phonemeNode->setAttribute( 'alphabet', 'ipa' );
		$phonemeNode->setAttribute( 'ph', $ipa );
		$wNode = $ssmlDoc->createElement( 'w', $text );
		$phonemeNode->appendChild( $wNode );
		$speakNode->appendChild( $phonemeNode );

		return $ssmlDoc->saveXML();
	}
}
