<?php

namespace MediaWiki\Extension\Phonos\Wikibase;

use stdClass;

/**
 * Wikibase item for Phonos
 * @newable
 */
class PhonosWikibasePronunciationAudio {
	/** @var stdClass */
	private $audioFile;

	/** @var string */
	private $commonsMediaUrl;

	/**
	 * @param stdClass $audioFile
	 * @param string $commonsMediaUrl
	 */
	public function __construct( stdClass $audioFile, string $commonsMediaUrl ) {
		$this->audioFile = $audioFile;
		$this->commonsMediaUrl = $commonsMediaUrl;
	}

	/**
	 * @return string
	 */
	public function getCommonsAudioFileUrl(): string {
		$audioFileValue = $this->audioFile->mainsnak->datavalue->value;
		return $this->commonsMediaUrl . $audioFileValue;
	}
}
