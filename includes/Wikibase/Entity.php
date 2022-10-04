<?php

namespace MediaWiki\Extension\Phonos\Wikibase;

/**
 * Value class for storing Wikibase data.
 * @newable
 */
class Entity {

	/** @var string */
	private $commonsMediaUrl;

	/** @var string|null */
	private $audioFile;

	/** @var string|null */
	private $ipaTranscription;

	/**
	 * @param string $commonsMediaUrl
	 */
	public function __construct( string $commonsMediaUrl ) {
		$this->commonsMediaUrl = $commonsMediaUrl;
	}

	/**
	 * @param string|null $audioFile
	 */
	public function setAudioFile( ?string $audioFile ): void {
		$this->audioFile = $audioFile;
	}

	/**
	 * @return string|null
	 */
	public function getAudioFile(): ?string {
		return $this->audioFile;
	}

	/**
	 * @return string|null
	 */
	public function getCommonsAudioFileUrl(): ?string {
		return $this->audioFile ? $this->commonsMediaUrl . $this->audioFile : null;
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
