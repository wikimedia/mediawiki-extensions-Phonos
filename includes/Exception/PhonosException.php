<?php

namespace MediaWiki\Extension\Phonos\Exception;

use Exception;

/**
 * Exception thrown when something within Phonos failed.
 * @newable
 */
class PhonosException extends Exception {

	/**
	 * @param string $message Message key of error message.
	 * @param array $args Parameters to pass to the message.
	 */
	public function __construct(
		string $message,
		private readonly array $args = [],
	) {
		parent::__construct( $message );
	}

	/**
	 * Returns array with exception message key and parameters.
	 *
	 * @return array
	 */
	public function getMessageKeyAndArgs(): array {
		return [ $this->getMessage(), ...$this->args ];
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
