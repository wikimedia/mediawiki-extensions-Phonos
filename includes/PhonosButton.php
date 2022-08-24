<?php

namespace MediaWiki\Extension\Phonos;

use OOUI\ButtonWidget;

class PhonosButton extends ButtonWidget {

	/**
	 * @inheritDoc
	 */
	public function __construct( array $config = [] ) {
		$config['infusable'] = true;
		$config['icon'] = 'volumeUp';
		$config['framed'] = false;
		$config['classes'] = [
			'ext-phonos',
			'ext-phonos-PhonosButton',
			// `.noexcerpt` is defined by TextExtracts
			'noexcerpt'
		];
		parent::__construct( $config );
	}

	/**
	 * The class name of the JavaScript version of this widget.
	 *
	 * @return string
	 */
	protected function getJavaScriptClassName(): string {
		return 'mw.Phonos.PhonosButton';
	}
}
