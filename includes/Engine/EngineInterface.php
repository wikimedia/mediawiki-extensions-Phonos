<?php

namespace MediaWiki\Extension\Phonos\Engine;

interface EngineInterface {

	/**
	 * Get SSML.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	public function getSsml( string $ipa, string $text, string $lang ): string;

	/**
	 * Get rendered audio for the given IPA string.
	 *
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 * @return string
	 */
	public function getAudioData( string $ipa, string $text, string $lang ): string;

}
