<?php

namespace MediaWiki\Extension\Phonos\Engine;

class AudioParams {
	public function __construct(
		private readonly string $ipa,
		private readonly string $text,
		private readonly string $lang,
	) {
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
