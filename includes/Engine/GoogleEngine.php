<?php

namespace MediaWiki\Extension\Phonos\Engine;

use DOMDocument;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use stdClass;
use Wikimedia\ObjectCache\WANObjectCache;

class GoogleEngine extends Engine {

	protected string $apiEndpoint;
	protected string $apiKey;

	/**
	 * @var int
	 * @override
	 */
	protected const MIN_FILE_SIZE = 1200;

	protected function register(): void {
		$this->apiEndpoint = $this->config->get( 'PhonosApiEndpointGoogle' );
		$this->apiKey = $this->config->get( 'PhonosApiKeyGoogle' );
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedLanguages(): ?array {
		$langs = $this->wanCache->getWithSetCallback(
			$this->wanCache->makeKey( 'phonos-google-langs' ),
			WANObjectCache::TTL_MONTH,
			function () {
				$response = $this->makeGoogleRequest( 'voices', [] );
				$langs = [];
				foreach ( $response->voices as $voice ) {
					$langs = array_merge( $langs, $voice->languageCodes );
				}
				// Remove duplicates and re-index the array.
				return array_values( array_unique( $langs ) );
			}
		);
		return $langs;
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

		$postData = [
			'audioConfig' => [
				'audioEncoding' => 'MP3',
			],
			'input' => [
				'ssml' => trim( $this->getSsml( $params ) ),
			],
			'voice' => [
				'languageCode' => $params->lang,
			],
		];
		$options = [
			'method' => 'POST',
			'postData' => json_encode( $postData )
		];

		$response = $this->makeGoogleRequest( 'text:synthesize', $options );
		$audio = base64_decode( $response->audioContent );
		$this->persistAudio( $params, $audio );

		return $audio;
	}

	/**
	 * Make a request to the Google Cloud Text-to-Speech API.
	 * @param string $method The API method name.
	 * @param mixed[] $options HTTP request options.
	 * @return stdClass
	 * @throws PhonosException If the request is not successful.
	 */
	private function makeGoogleRequest( string $method, array $options ): stdClass {
		if ( $this->apiProxy ) {
			$options['proxy'] = $this->apiProxy;
		}

		$request = $this->requestFactory->create(
			$this->apiEndpoint . $method . '?key=' . $this->apiKey,
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

		return json_decode( $request->getContent() );
	}

	/**
	 * @inheritDoc
	 */
	public function getSsml( AudioParams $params ): string {
		$ssmlDoc = new DOMDocument();

		$speakNode = $ssmlDoc->createElement( 'speak' );
		$ssmlDoc->appendChild( $speakNode );

		$phonemeNode = $ssmlDoc->createElement( 'phoneme', $params->text );
		$phonemeNode->setAttribute( 'alphabet', 'ipa' );

		// Trim slashes from IPA; see T313497
		$ipa = trim( $params->ipa, '/' );
		// Replace apostrophes with U+02C8; see T313711
		$ipa = str_replace( "'", "ˈ", $ipa );

		$phonemeNode->setAttribute( 'ph', $ipa );
		$speakNode->appendChild( $phonemeNode );

		// Return the documentElement (omitting the <?xml> tag) since it is not
		// needed and Google charges by the number of characters in the payload.
		return $ssmlDoc->saveXML( $ssmlDoc->documentElement );
	}
}
