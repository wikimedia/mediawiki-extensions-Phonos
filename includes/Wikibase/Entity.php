<?php

namespace MediaWiki\Extension\Phonos\Wikibase;

use File;

/**
 * Value class for storing Wikibase data.
 * @newable
 */
class Entity {

	/** @var File|null */
	private $audioFile;

	/** @var string|null */
	private $ipaTranscription;

	/**
	 * @param File|null $audioFile
	 */
	public function setAudioFile( ?File $audioFile ): void {
		$this->audioFile = $audioFile;
	}

	/**
	 * @return File|null
	 */
	public function getAudioFile(): ?File {
		return $this->audioFile;
	}

	/**
	 * @param string|null $ipaTranscription
	 */
	public function setIPATranscription( ?string $ipaTranscription ): void {
		$this->ipaTranscription = $ipaTranscription;
	}

	/**
	 * @return string|null
	 */
	public function getIPATranscription(): ?string {
		return $this->ipaTranscription;
	}

}
