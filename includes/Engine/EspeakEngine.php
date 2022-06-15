<?php

namespace MediaWiki\Extension\Phonos\Engine;

use DOMDocument;
use MediaWiki\Shell\Shell;

/**
 * @link http://espeak.sourceforge.net/
 */
class EspeakEngine implements EngineInterface {

	/**
	 * @inheritDoc
	 */
	public function getAudioData( string $ipa, string $text, string $lang ): string {
		$cmdArgs = [
			'espeak',
			// Read text input from stdin instead of a file
			'--stdin',
			// Interpret SSML markup, and ignore other < > tags
			'-m',
			// Write speech output to stdout
			'--stdout',
		];
		$cmd = Shell::command( $cmdArgs );
		// Espeak has its own syntax for phonemes: http://espeak.sourceforge.net/phonemes.html
		// It is supposed to also support SSML, but seems to ignore the phoneme 'ph' attribute
		// and just uses the text.
		$cmd->input( $this->getSsml( $ipa, $text, $lang ) );
		$cmd->disableNetwork();
		$out = $cmd->execute();
		return $out->getStdout();
	}

	/**
	 * @inheritDoc
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
