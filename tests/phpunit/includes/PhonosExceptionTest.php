<?php

namespace MediaWiki\Extension\Phonos;

use MediaWiki\Extension\Phonos\Exception\PhonosException;
use PHPUnit\Framework\TestCase;

/**
 * @group Phonos
 * @covers \MediaWiki\Extension\Phonos\Exception\PhonosException
 */
class PhonosExceptionTest extends TestCase {

	public function testToString(): void {
		$e = new PhonosException( 'phonos-directory-error', [ 'foobar' ] );
		$this->assertSame( 'Failed to create storage directory foobar', $e->toString() );
	}

	public function testGetStatsdKey(): void {
		$e = new PhonosException( 'phonos-directory-error', [ 'foobar' ] );
		$this->assertSame( 'phonos_directory_error', $e->getStatsdKey() );
	}
}
