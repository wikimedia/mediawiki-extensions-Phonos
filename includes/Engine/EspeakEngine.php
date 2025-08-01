<?php

namespace MediaWiki\Extension\Phonos\Engine;

use DOMDocument;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use Shellbox\Command\BoxedCommand;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @link http://espeak.sourceforge.net/
 */
class EspeakEngine extends Engine {

	protected string $espeakPath;
	protected BoxedCommand $espeakCommand;

	protected function register(): void {
		$this->espeakPath = $this->config->get( 'PhonosEspeak' );
		$this->espeakCommand = $this->commandFactory
			->createBoxed( 'phonos' )
			->disableNetwork()
			->firejailDefaultSeccomp()
			->routeName( 'phonos-espeak' );
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

		$cmdArgs = [
			$this->espeakPath,
			// Read text input from stdin instead of a file
			'--stdin',
			// Interpret SSML markup, and ignore other < > tags
			'-m',
			// Write speech output to stdout
			'--stdout',
		];
		$out = $this->espeakCommand
			->params( $cmdArgs )
			->stdin( $this->getSsml( $params ) )
			->execute();

		if ( $out->getExitCode() !== 0 ) {
			throw new PhonosException( 'phonos-engine-error', [ 'eSpeak', $out->getStderr() ] );
		}

		// TODO: The above and Engine::convertWavToMp3() should ideally be refactored into
		//   a single shell script so that there's only one round trip to Shellbox.
		$out = $this->convertWavToMp3( $out->getStdout() );
		$this->persistAudio( $params, $out );

		return $out;
	}

	/**
	 * @inheritDoc
	 *
	 * Espeak has its own syntax for phonemes: http://espeak.sourceforge.net/phonemes.html
	 * It is supposed to also support SSML, but seems to ignore the phoneme 'ph' attribute
	 * and just uses the text.
	 */
	public function getSsml( AudioParams $params ): string {
		$ssmlDoc = new DOMDocument( '1.0' );

		$speakNode = $ssmlDoc->createElement( 'speak' );
		$speakNode->setAttribute( 'xmlns', 'http://www.w3.org/2001/10/synthesis' );
		$speakNode->setAttribute( 'version', '1.1' );
		$speakNode->setAttribute( 'xml:lang', $params->getLang() );
		$ssmlDoc->appendChild( $speakNode );

		// phoneme element spec: https://www.w3.org/TR/speech-synthesis/#S3.1.10
		$phoneme = $ssmlDoc->createElement( 'phoneme', $params->getText() );
		$phoneme->setAttribute( 'alphabet', 'ipa' );
		$phoneme->setAttribute( 'ph', $params->getIpa() );

		$speakNode->appendChild( $phoneme );

		return $ssmlDoc->saveXML();
	}

	/**
	 * @inheritDoc
	 */
	public function getSupportedLanguages(): ?array {
		return $this->wanCache->getWithSetCallback(
			$this->wanCache->makeKey( 'phonos-espeak-langs', $this->espeakPath ),
			WANObjectCache::TTL_MONTH,
			/** @throws PhonosException */
			function () {
				$out = $this->espeakCommand
					->params( [ $this->espeakPath, '--voices' ] )
					->execute();
				if ( $out->getExitCode() !== 0 ) {
					throw new PhonosException( 'phonos-engine-error', [ 'eSpeak', $out->getStderr() ] );
				}
				return $this->getLangsFromOutput( $out->getStdout() );
			}
		);
	}

	/**
	 * Get languages from the espeak --voices output.
	 *
	 * The output is formatted like the following, so we split on whitespace and return the 2nd element.
	 *
	 *     Pty Language Age/Gender VoiceName          File          Other Languages
	 *      5  af             M  afrikaans            other/af
	 *      5  an             M  aragonese            europe/an
	 *      5  bg             -  bulgarian            europe/bg
	 *
	 * @param string $output The output text to parse.
	 * @return string[] Array of language codes.
	 */
	public function getLangsFromOutput( string $output ): array {
		$lines = explode( "\n", $output );
		$langs = array_map( static function ( string $line ) {
			$parts = array_values( array_filter( explode( ' ', $line ) ) );
			$lang = $parts[1] ?? null;
			return $lang === 'Language' ? null : $lang;
		}, $lines );
		return array_values( array_filter( $langs ) );
	}
}
