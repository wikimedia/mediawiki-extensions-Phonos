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

		// T315404: Wrap output element in data-nosnippet
		$this->setAttributes( [ 'data-nosnippet' => '' ] );

		// Set aria-label if it's provided.
		if ( isset( $config['aria-label'] ) && trim( $config['aria-label'] ) !== '' ) {
			$this->setAttributes( [ 'aria-label' => $config['aria-label'] ] );
		}

		// Change display for errors.
		if ( isset( $config['data']['error'] ) ) {
			$this->setDisabled( true );
			$this->setIcon( 'volumeOff' );
		}
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
