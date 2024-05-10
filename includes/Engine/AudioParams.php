<?php

namespace MediaWiki\Extension\Phonos\Engine;

class AudioParams {
	private string $lang;
	private string $text;
	private string $ipa;

	public function __construct( string $ipa, string $text, string $lang ) {
		$this->ipa = $ipa;
		$this->text = $text;
		$this->lang = $lang;
	}

	public function getLang(): string {
		return $this->lang;
	}

	public function getText(): string {
		return $this->text;
	}

	public function getIpa(): string {
		return $this->ipa;
	}
}
