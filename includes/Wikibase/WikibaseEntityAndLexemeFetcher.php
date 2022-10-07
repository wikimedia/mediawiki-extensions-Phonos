<?php

namespace MediaWiki\Extension\Phonos\Wikibase;

use Config;
use LanguageCode;
use MediaWiki\Extension\Phonos\Exception\PhonosException;
use MediaWiki\Http\HttpRequestFactory;
use stdClass;
use WANObjectCache;

/**
 * Wikibase item fetcher for Phonos
 * @newable
 */
class WikibaseEntityAndLexemeFetcher {

	/** @var string */
	private $wikibaseUrl;

	/** @var string */
	private $wikibasePronunciationAudioProp;

	/** @var string */
	private $wikibaseLangNameProp;

	/** @var string */
	private $wikibaseIETFLangTagProp;

	/** @var string */
	private $commonsMediaUrl;

	/** @var HttpRequestFactory */
	private $requestFactory;

	/** @var WANObjectCache */
	private $wanCache;

	/** @var string */
	private $apiProxy;

	/**
	 * @param HttpRequestFactory $requestFactory
	 * @param WANObjectCache $wanCache
	 * @param Config $config
	 */
	public function __construct(
		HttpRequestFactory $requestFactory,
		WANObjectCache $wanCache,
		Config $config
	) {
		$this->requestFactory = $requestFactory;
		$this->wikibaseUrl = $config->get( 'PhonosWikibaseUrl' );

		$phonosWikibaseProperties = $config->get( 'PhonosWikibaseProperties' );
		$this->wikibasePronunciationAudioProp = $phonosWikibaseProperties['wikibasePronunciationAudioProp'];
		$this->wikibaseLangNameProp = $phonosWikibaseProperties['wikibaseLangNameProp'];
		$this->wikibaseIETFLangTagProp = $phonosWikibaseProperties['wikibaseIETFLangTagProp'];

		$this->commonsMediaUrl = $config->get( 'PhonosCommonsMediaUrl' );
		$this->wanCache = $wanCache;
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
	}

	/**
	 * @param string $wikibaseEntity
	 * @param string $text
	 * @param string $lang
	 * @return PhonosWikibasePronunciationAudio|null
	 * @throws PhonosException
	 */
	public function fetchPhonosWikibaseAudio(
		string $wikibaseEntity,
		string $text,
		string $lang
	): ?PhonosWikibasePronunciationAudio {
		if ( !$this->isValidEntityOrLexeme( $wikibaseEntity ) ) {
			throw new PhonosException( 'phonos-wikibase-invalid-entity-lexeme',
				[ $wikibaseEntity ] );
		}

		$item = $this->fetchWikibaseItem( $wikibaseEntity );

		if ( $item === null ) {
			return null;
		}

		$audioFiles = [];

		if ( $item->type === "lexeme" ) {
			// If lexeme, we need the $text representation for the audio file
			if ( $text === "" ) {
				return null;
			}
			$itemForms = $item->forms;
			$wordFound = false;
			foreach ( $itemForms as $form ) {
				if ( $wordFound ) {
					break;
				}
				$formRepresentations = $form->representations;
				foreach ( $formRepresentations as $representation ) {
					// check if $text value is found in representation
					if ( $representation->value === $text ) {
						$audioFiles = $form->claims->{$this->wikibasePronunciationAudioProp} ?? [];
						$wordFound = true;
						break;
					}
				}
			}
		} else {
			$audioFiles = $item
				->claims->{$this->wikibasePronunciationAudioProp} ?? [];
		}

		// Return if no audio files found
		if ( $audioFiles === [] ) {
			return null;
		}

		$pronunciationFile = null;
		$normalizedLang = LanguageCode::bcp47( $lang );
		foreach ( $audioFiles as $audioFile ) {
			$langNameId = $audioFile
				->qualifiers->{$this->wikibaseLangNameProp}[0]
				->datavalue->value->id ?? false;
			// Check if audio has language name prop
			if ( !$langNameId ) {
				continue;
			}

			$langCode = $this->getCachedLanguageEntityCode( $langNameId );
			if ( $langCode === $normalizedLang ) {
				$pronunciationFile = new PhonosWikibasePronunciationAudio(
					$audioFile,
					$this->commonsMediaUrl
				);
				break;
			}
		}
		return $pronunciationFile;
	}

	/**
	 * @param string $IETFLangEntity
	 * @return string|false
	 */
	private function getCachedLanguageEntityCode( string $IETFLangEntity ) {
		return $this->wanCache->getWithSetCallback(
			$this->wanCache->makeKey( 'IETF-lang', $IETFLangEntity ),
			WANObjectCache::TTL_INDEFINITE,
			function () use ( $IETFLangEntity ) {
				$langEntity = $this->fetchWikibaseItem( $IETFLangEntity );
				if ( $langEntity ) {
					return $langEntity->claims->{$this->wikibaseIETFLangTagProp}[0]->mainsnak->datavalue->value
						?? false;
				}
				return false;
			}
		);
	}

	/**
	 * @param string $wikibaseEntity
	 * @return stdClass|null
	 * @throws PhonosException
	 */
	private function fetchWikibaseItem( string $wikibaseEntity ): ?stdClass {
		$url = $this->wikibaseUrl . "Special:EntityData/" . $wikibaseEntity . ".json";
		$options = [
			'method' => 'GET'
		];

		if ( $this->apiProxy ) {
			$options['proxy'] = $this->apiProxy;
		}

		$request = $this->requestFactory->create(
			$url,
			$options,
			__METHOD__
		);
		$status = $request->execute();

		if ( !$status->isOK() ) {
			$error = $status->getMessage()->text();
			throw new PhonosException( 'phonos-wikibase-api-error', [ $error ] );
		}
		$content = json_decode( $request->getContent() );

		return $content->entities->{$wikibaseEntity} ?? null;
	}

	/**
	 * @param string $wikibaseEntity
	 * @return bool
	 */
	private function isValidEntityOrLexeme( string $wikibaseEntity ): bool {
		return preg_match( '/^[QL][0-9]+$/i', $wikibaseEntity );
	}
}
