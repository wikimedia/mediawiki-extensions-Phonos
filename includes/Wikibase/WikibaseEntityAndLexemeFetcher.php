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
	private $wikibaseIPATranscriptionProp;

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
		$this->wikibaseIPATranscriptionProp = $phonosWikibaseProperties['wikibaseIPATranscriptionProp'];

		$this->commonsMediaUrl = $config->get( 'PhonosCommonsMediaUrl' );
		$this->wanCache = $wanCache;
		$this->apiProxy = $config->get( 'PhonosApiProxy' );
	}

	/**
	 * @param string $wikibaseEntity
	 * @param string $text
	 * @param string $lang
	 * @return Entity
	 * @throws PhonosException
	 */
	public function fetch(
		string $wikibaseEntity,
		string $text,
		string $lang
	): Entity {
		// Validate Wikibase ID.
		if ( !$this->isValidEntityOrLexeme( $wikibaseEntity ) ) {
			throw new PhonosException( 'phonos-wikibase-invalid-entity-lexeme',
				[ $wikibaseEntity ] );
		}

		// Fetch entity data.
		$item = $this->fetchWikibaseItem( $wikibaseEntity );

		// Entity not found.
		if ( $item === null ) {
			throw new PhonosException( 'phonos-wikibase-not-found', [ $wikibaseEntity ] );
		}

		$entity = new Entity( $this->commonsMediaUrl );
		$audioFiles = [];
		$ipaTranscriptions = [];

		if ( $item->type === "lexeme" ) {
			// If lexeme, we need the $text representation for the audio file
			if ( $text === "" ) {
				return $entity;
			}
			$itemForms = $item->forms;
			foreach ( $itemForms as $form ) {
				$formRepresentations = $form->representations;
				foreach ( $formRepresentations as $representation ) {
					// check if $text value is found in representation
					if ( $representation->value === $text ) {
						$audioFiles = $form->claims->{$this->wikibasePronunciationAudioProp} ?? [];
						$ipaTranscriptions = $form->claims->{$this->wikibaseIPATranscriptionProp} ?? [];
						break 2;
					}
				}
			}
		} else {
			$audioFiles = $item->claims->{$this->wikibasePronunciationAudioProp} ?? [];
			$ipaTranscriptions = $item->claims->{$this->wikibaseIPATranscriptionProp} ?? [];
		}

		$entity->setIPATranscription( $this->getClaimValueByLang( $ipaTranscriptions, $lang ) );
		$entity->setAudioFile( $this->getClaimValueByLang( $audioFiles, $lang ) );

		return $entity;
	}

	/**
	 * Look through a set of claims to find the first value in the specified language.
	 * @param mixed[] $claims Set of claims.
	 * @param string $lang User-provided IETF language code.
	 * @return string|null
	 */
	private function getClaimValueByLang( array $claims, string $lang ): ?string {
		$normalizedLang = LanguageCode::bcp47( $lang );
		foreach ( $claims as $claim ) {
			$qualLangs = $claim->qualifiers->{$this->wikibaseLangNameProp} ?? [];
			foreach ( $qualLangs as $qualLang ) {
				$langId = $qualLang->datavalue->value->id ?? false;
				if ( $langId ) {
					$langCode = $this->getCachedLanguageEntityCode( $langId );
					if ( $langCode === $normalizedLang ) {
						return $claim->mainsnak->datavalue->value;
					}
				}
			}
		}
		return null;
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
