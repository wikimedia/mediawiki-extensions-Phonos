<?php

namespace MediaWiki\Extension\Phonos\Engine;

use Config;
use DOMDocument;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Http\HttpRequestFactory;

class GoogleEngine implements EngineInterface {

	/** @var HttpRequestFactory */
	protected $requestFactory;

	/** @var string */
	protected $apiEndpoint;

	/** @var string */
	protected $apiKey;

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param Config $config
	 */
	public function __construct( HttpRequestFactory $requestFactory, Config $config ) {
		$this->requestFactory = $requestFactory;
		$this->apiEndpoint = $config->get( 'PhonosApiEndpointGoogle' );
		$this->apiKey = $config->get( 'PhonosApiKeyGoogle' );
	}

	/**
	 * @inheritDoc
	 */
	public function getAudioData( string $ipa, string $text, string $lang ): string {
		$postData = [
			'audioConfig' => [
				'audioEncoding' => 'LINEAR16',
			],
			'input' => [
				'ssml' => trim( $this->getSsml( $ipa, $text, $lang ) ),
			],
			'voice' => [
				'languageCode' => $lang,
			],
		];
		$request = $this->requestFactory->create(
			$this->apiEndpoint . '?key=' . $this->apiKey,
			[
				'method' => 'POST',
				'postData' => json_encode( $postData ),
			],
			__METHOD__
		);
		$request->setHeader( 'Content-Type', 'application/json; charset=utf-8' );

		$status = $request->execute();

		if ( !$status->isOK() ) {
			$error = $status->getMessage()->text();
			throw new PhonosException(
				'Unable to retrieve audio using the Google engine: ' . $error
			);
		}

		return base64_decode( json_decode( $request->getContent() )->audioContent );
	}

	/**
	 * @inheritDoc
	 */
	public function getSsml( string $ipa, string $text, string $lang ): string {
		$ssmlDoc = new DOMDocument( '1.0' );

		$speakNode = $ssmlDoc->createElement( 'speak' );
		$ssmlDoc->appendChild( $speakNode );

		$langNode = $ssmlDoc->createElement( 'lang' );
		$langNode->setAttribute( 'xml:lang', $lang );

		$phonemeNode = $ssmlDoc->createElement( 'phoneme', $text );
		$phonemeNode->setAttribute( 'alphabet', 'ipa' );
		$phonemeNode->setAttribute( 'ph', $ipa );

		$langNode->appendChild( $phonemeNode );
		$speakNode->appendChild( $langNode );

		return $ssmlDoc->saveXML();
	}
}
