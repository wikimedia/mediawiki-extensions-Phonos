<?php

namespace MediaWiki\Extension\Phonos\Engine;

use DOMDocument;
use MediaWiki\MediaWikiServices;

/**
 * @link http://espeak.sourceforge.net/
 */
class EspeakEngine extends Engine {

	/**
	 * @inheritDoc
	 * @codeCoverageIgnore
	 */
	public function getAudioData( string $ipa, string $text, string $lang ): string {
		$cachedAudio = $this->getCachedAudio( $ipa, $text, $lang );
		if ( $cachedAudio ) {
			return $cachedAudio;
		}

		$cmdArgs = [
			'espeak',
			// Read text input from stdin instead of a file
			'--stdin',
			// Interpret SSML markup, and ignore other < > tags
			'-m',
			// Write speech output to stdout
			'--stdout',
		];
		$cmd = MediaWikiServices::getInstance()->getShellCommandFactory()
			->createBoxed( 'phonos' )
			->disableNetwork()
			->firejailDefaultSeccomp()
			->routeName( 'phonos-espeak' );
		$cmd->params( $cmdArgs )
			->stdin( $this->getSsml( $ipa, $text, $lang ) );
		$out = $cmd->params( $cmdArgs )
			->execute();

		$this->cacheAudio( $ipa, $text, $lang, $out->getStdout() );

		return $out->getStdout();
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
