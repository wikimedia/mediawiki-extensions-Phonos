<?php

namespace MediaWiki\Extension\Phonos\Wikibase;

use File;

/**
 * Value class for storing Wikibase data.
 * @newable
 */
class Entity {

	private ?File $audioFile;

	private ?string $ipaTranscription;

	public function setAudioFile( ?File $audioFile ): void {
		$this->audioFile = $audioFile;
	}

	public function getAudioFile(): ?File {
		return $this->audioFile;
	}

	public function setIPATranscription( ?string $ipaTranscription ): void {
		$this->ipaTranscription = $ipaTranscription;
	}

	public function getIPATranscription(): ?string {
		return $this->ipaTranscription;
	}

}
