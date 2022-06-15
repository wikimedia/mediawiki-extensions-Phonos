<?php

use MediaWiki\Extension\Phonos\Engine\EngineInterface;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'Phonos.Engine' => static function ( MediaWikiServices $services ): EngineInterface {
		$engineName = ucfirst( $services->getMainConfig()->get( 'PhonosEngine' ) );
		$className = '\\MediaWiki\\Extension\\Phonos\\Engine\\' . $engineName . 'Engine';
		return new $className();
	},
];
