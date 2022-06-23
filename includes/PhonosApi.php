<?php

namespace MediaWiki\Extension\Phonos;

use ApiBase;
use ApiMain;
use MediaWiki\Extension\Phonos\Engine\EngineInterface;
use Wikimedia\ParamValidator\ParamValidator;

class PhonosApi extends ApiBase {

	/** @var EngineInterface */
	private $engine;

	/**
	 * @param ApiMain $main
	 * @param string $name
	 * @param EngineInterface $engine
	 */
	public function __construct( ApiMain $main, $name, EngineInterface $engine ) {
		parent::__construct( $main, $name );
		$this->engine = $engine;
	}

	public function execute() {
		// Get and check parameter values.
		$ipa = $this->getParameter( 'ipa' );
		$text = $this->getParameter( 'text' );
		$lang = $this->getParameter( 'lang' );
		if ( $lang === '' || $lang === null ) {
			$lang = $this->getLanguage()->getCode();
		}

		// Get configured engine and use it to render the audio.
		$audio = $this->engine->getAudioData( $ipa, $text, $lang );

		// Return SSML and base64-encoded audio.
		$this->getResult()->addValue( 'phonos', 'ssml', $this->engine->getSsml( $ipa, $text, $lang ) );
		$this->getResult()->addValue( 'phonos', 'audioData', base64_encode( $audio ) );
	}

	/**
	 * @return mixed[][]
	 */
	public function getAllowedParams() {
		return [
			'ipa' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'text' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'lang' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages() {
		return [
			'action=phonos&ipa=həˈləʊ&text=hello&lang=en' => 'apihelp-phonos-example-1',
		];
	}
}
