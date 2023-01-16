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

		// Change display for errors.
		if ( isset( $config['data']['error'] ) ) {
			$config['classes'][] = 'ext-phonos-error';
			$config['icon'] = 'volumeOff';
		}

		if ( !$config['label'] ) {
			// Add class with which to change margins when there's no visible label.
			$config['classes'][] = 'ext-phonos-PhonosButton-emptylabel';
		}
		parent::__construct( $config );

		// T315404: Wrap output element in data-nosnippet
		$this->setAttributes( [ 'data-nosnippet' => '' ] );

		// Set aria-label if it's provided.
		if ( isset( $config['aria-label'] ) && trim( $config['aria-label'] ) !== '' ) {
			$this->button->setAttributes( [ 'aria-label' => $config['aria-label'] ] );
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
