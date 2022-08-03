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

	/** @var string */
	protected $apiProxy;

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param Config $config
	 */
	public function __construct( HttpRequestFactory $requestFactory, Config $config ) {
		$this->requestFactory = $requestFactory;
		$this->apiEndpoint = $config->get( 'PhonosApiEndpointGoogle' );
		$this->apiKey = $config->get( 'PhonosApiKeyGoogle' );
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
	}

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
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
		$options = [
			'method' => 'POST',
			'postData' => json_encode( $postData )
		];

		if ( $this->apiProxy ) {
			$options['proxy'] = $this->apiProxy;
		}

		$request = $this->requestFactory->create(
			$this->apiEndpoint . '?key=' . $this->apiKey,
			$options,
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
		$ssmlDoc = new DOMDocument();

		$speakNode = $ssmlDoc->createElement( 'speak' );
		$ssmlDoc->appendChild( $speakNode );

		$phonemeNode = $ssmlDoc->createElement( 'phoneme', $text );
		$phonemeNode->setAttribute( 'alphabet', 'ipa' );

		// Trim slashes from IPA; see T313497
		$ipa = trim( $ipa, '/' );
		$phonemeNode->setAttribute( 'ph', $ipa );

		$speakNode->appendChild( $phonemeNode );

		// Return the documentElement (omitting the <?xml> tag) since it is not
		// needed and Google charges by the number of characters in the payload.
		return $ssmlDoc->saveXML( $ssmlDoc->documentElement );
	}
}
