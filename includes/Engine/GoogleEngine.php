<?php

namespace MediaWiki\Extension\Phonos\Engine;

use Config;
use DOMDocument;
use FileBackendGroup;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Http\HttpRequestFactory;

class GoogleEngine extends Engine {

	/** @var string */
	protected $apiEndpoint;

	/** @var string */
	protected $apiKey;

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param FileBackendGroup $fileBackendGroup
	 * @param Config $config
	 */
	public function __construct(
		HttpRequestFactory $requestFactory,
		FileBackendGroup $fileBackendGroup,
		Config $config
	) {
		parent::__construct( $requestFactory, $fileBackendGroup, $config );
		$this->apiEndpoint = $config->get( 'PhonosApiEndpointGoogle' );
		$this->apiKey = $config->get( 'PhonosApiKeyGoogle' );
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

		$audio = base64_decode( json_decode( $request->getContent() )->audioContent );
		$this->cacheAudio( $ipa, $text, $lang, $audio );

		return $audio;
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
		// Replace apostrophes with U+02C8; see T313711
		$ipa = str_replace( "'", "ˈ", $ipa );
		// Google doesn't like the parentheses, which we understand aren't important anyway.
		$ipa = str_replace( [ '(', ')' ], '', $ipa );

		$phonemeNode->setAttribute( 'ph', $ipa );
		$speakNode->appendChild( $phonemeNode );

		// Return the documentElement (omitting the <?xml> tag) since it is not
		// needed and Google charges by the number of characters in the payload.
		return $ssmlDoc->saveXML( $ssmlDoc->documentElement );
	}
}
