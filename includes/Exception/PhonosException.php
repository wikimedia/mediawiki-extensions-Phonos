<?php

namespace MediaWiki\Extension\Phonos\Exception;

use Exception;
use MediaWiki\Page\PageReferenceValue;

/**
 * Exception thrown when something within Phonos failed.
 * @newable
 */
class PhonosException extends Exception {

	/** @var array */
	private $args;

	/**
	 * @param string $message Message key of error message.
	 * @param array $args Parameters to pass to the message.
	 */
	public function __construct( string $message, array $args = [] ) {
		parent::__construct( $message );
		$this->args = $args;
	}

	/**
	 * Returns exception messages in the wiki's content language.
	 *
	 * @return string
	 */
	public function toString(): string {
		return wfMessage( $this->getMessage(), ...$this->args )
			->inContentLanguage()
			// A context is needed when using wfMessage(). We use Special:Badtitle since we
			// don't have access nor do we need the current page title for this message.
			->page( PageReferenceValue::localReference( NS_SPECIAL, 'Badtitle' ) )
			->parse();
	}

	/**
	 * Key for use in statsd metrics, where hyphens aren't allowed.
	 *
	 * @return string
	 */
	public function getStatsdKey(): string {
		return str_replace( '-', '_', $this->getMessage() );
	}
}
