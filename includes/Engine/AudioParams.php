<?php

namespace MediaWiki\Extension\Phonos\Engine;

class AudioParams {
	/** @var string */
	private string $lang;

	/** @var string */
	private string $text;

	/** @var string */
	private string $ipa;

	/**
	 * @param string $ipa
	 * @param string $text
	 * @param string $lang
	 */
	public function __construct( string $ipa, string $text, string $lang ) {
		$this->ipa = $ipa;
		$this->text = $text;
		$this->lang = $lang;
	}

	/**
	 * @return string
	 */
	public function getLang(): string {
		return $this->lang;
	}

	/**
	 * @return string
	 */
	public function getText(): string {
		return $this->text;
	}

	/**
	 * @return string
	 */
	public function getIpa(): string {
		return $this->ipa;
	}
}
