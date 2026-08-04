<?php
/**
 * Script registry validation tests.
 *
 * @package UCPF
 */

use PHPUnit\Framework\TestCase;
use UCPF\Script_Registry;

class Script_Registry_Test extends TestCase {

	public function test_register_service_requires_key() {
		$registry = Script_Registry::instance();
		$result   = $registry->register_service( array( 'name' => 'Test' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_register_service_valid_definition() {
		$registry = Script_Registry::instance();
		$result   = $registry->register_service(
			array(
				'key'      => 'test_service',
				'name'     => 'Test',
				'category' => 'analytics',
			)
		);
		$this->assertTrue( $result );
	}
}
