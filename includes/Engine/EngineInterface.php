<?php

namespace MediaWiki\Extension\Phonos\Engine;

interface EngineInterface {

	/**
	 * Get SSML.
	 *
	 * @param AudioParams $params
	 * @return string
	 */
	public function getSsml( AudioParams $params ): string;

	/**
	 * Get rendered audio for the given IPA string.
	 *
	 * @param AudioParams $params
	 * @return string
	 */
	public function getAudioData( AudioParams $params ): string;

	/**
	 * Get a list of languages supported by this engine.
	 *
	 * @return string[]|null Array of IETF language codes, or null if any language is supported.
	 */
	public function getSupportedLanguages(): ?array;
}
