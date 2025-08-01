<?php

namespace MediaWiki\Extension\Phonos\Engine;

class AudioParams {
	public function __construct(
		public readonly string $ipa,
		public readonly string $text,
		public readonly string $lang,
	) {
	}
}
