<?php

namespace MediaWiki\Extension\Phonos\Engine;

use Config;
use DOMDocument;
use FileBackendGroup;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Shell\CommandFactory;

/**
 * @link http://espeak.sourceforge.net/
 */
class EspeakEngine extends Engine {

	/** @var string */
	protected $espeakPath;

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
		$this->espeakPath = $config->get( 'PhonosEspeak' );
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

		$cmdArgs = [
			$this->espeakPath,
			// Read text input from stdin instead of a file
			'--stdin',
			// Interpret SSML markup, and ignore other < > tags
			'-m',
			// Write speech output to stdout
			'--stdout',
		];
		$out = $this->commandFactory->createBoxed( 'phonos' )
			->disableNetwork()
			->firejailDefaultSeccomp()
			->routeName( 'phonos-espeak' )
			->params( $cmdArgs )
			->stdin( $this->getSsml( $ipa, $text, $lang ) )
			->execute();

		if ( $out->getExitCode() !== 0 ) {
			throw new PhonosException( 'phonos-engine-error', [ 'eSpeak', $out->getStderr() ] );
		}

		if ( $this->storeFilesAsMp3 ) {
			// TODO: The above and Engine::convertWavToMp3() should ideally be refactored into
			//   a single shell script so that there's only one round trip to Shellbox.
			$out = $this->convertWavToMp3( $out->getStdout() );
		} else {
			$out = (string)$out->getStdout();
		}

		$this->persistAudio( $ipa, $text, $lang, $out );

		return $out;
	}

	/**
	 * @inheritDoc
	 *
	 * Espeak has its own syntax for phonemes: http://espeak.sourceforge.net/phonemes.html
	 * It is supposed to also support SSML, but seems to ignore the phoneme 'ph' attribute
	 * and just uses the text.
	 */
	public function getSsml( string $ipa, string $text, string $lang ): string {
		$ssmlDoc = new DOMDocument( '1.0' );

		$speakNode = $ssmlDoc->createElement( 'speak' );
		$speakNode->setAttribute( 'xmlns', 'http://www.w3.org/2001/10/synthesis' );
		$speakNode->setAttribute( 'version', '1.1' );
		$speakNode->setAttribute( 'xml:lang', $lang );
		$ssmlDoc->appendChild( $speakNode );

		// phoneme element spec: https://www.w3.org/TR/speech-synthesis/#S3.1.10
		$phoneme = $ssmlDoc->createElement( 'phoneme', $text );
		$phoneme->setAttribute( 'alphabet', 'ipa' );
		$phoneme->setAttribute( 'ph', $ipa );

		$speakNode->appendChild( $phoneme );

		return $ssmlDoc->saveXML();
	}
}
