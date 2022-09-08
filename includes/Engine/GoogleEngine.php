<?php

namespace MediaWiki\Extension\Phonos\Engine;

use Config;
use DOMDocument;
use FileBackendGroup;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Shell\CommandFactory;

class GoogleEngine extends Engine {

	/** @var string */
	protected $apiEndpoint;

	/** @var string */
	protected $apiKey;

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
		$this->apiEndpoint = $config->get( 'PhonosApiEndpointGoogle' );
		$this->apiKey = $config->get( 'PhonosApiKeyGoogle' );
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

		$postData = [
			'audioConfig' => [
				'audioEncoding' => 'MP3',
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
			// See if the result contains error details.
			$response = json_decode( $request->getContent() );
			$error = $response->error->message ?? $status->getMessage()->text();
			throw new PhonosException( 'phonos-engine-error', [ 'Google', $error ] );
		}

		$audio = base64_decode( json_decode( $request->getContent() )->audioContent );
		$this->persistAudio( $ipa, $text, $lang, $audio );

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
