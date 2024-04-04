<?php

use MediaWiki\Config\ConfigException;
use MediaWiki\Extension\Phonos\Engine\EngineInterface;
use MediaWiki\Extension\Phonos\Wikibase\WikibaseEntityAndLexemeFetcher;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'Phonos.Engine' => static function ( MediaWikiServices $services ): EngineInterface {
		$config = $services->getMainConfig();
		$engineName = ucfirst( $config->get( 'PhonosEngine' ) );
		$className = '\\MediaWiki\\Extension\\Phonos\\Engine\\' . $engineName . 'Engine';
		if ( !class_exists( $className ) ) {
			throw new ConfigException( "$engineName is not a valid engine" );
		}
		return new $className(
			$services->getHttpRequestFactory(),
			$services->getShellCommandFactory(),
			$services->getFileBackendGroup(),
			$services->getMainObjectStash(),
			$services->getMainWANObjectCache(),
			$services->getContentLanguage(),
			$config
		);
	},
	'Phonos.Wikibase' => static function ( MediaWikiServices $services ): WikibaseEntityAndLexemeFetcher {
		return new WikibaseEntityAndLexemeFetcher(
			$services->getHttpRequestFactory(),
			$services->getRepoGroup(),
			$services->getMainWANObjectCache(),
			$services->getMainConfig()
		);
	},
];
