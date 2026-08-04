<?php
/**
 * Consent manager tests.
 *
 * @package UCPF
 */

use PHPUnit\Framework\TestCase;
use UCPF\Consent_Manager;

class Consent_Manager_Test extends TestCase {

	public function test_default_categories_rejected_keeps_necessary() {
		$manager = Consent_Manager::instance();
		$cats    = $manager->default_categories_rejected();
		$this->assertTrue( $cats['necessary'] );
		$this->assertFalse( $cats['marketing'] );
	}

	public function test_default_categories_accepted_enables_all() {
		$manager = Consent_Manager::instance();
		$cats    = $manager->default_categories_accepted();
		foreach ( $cats as $enabled ) {
			$this->assertTrue( $enabled );
		}
	}

	public function test_sanitize_categories_forces_necessary() {
		$manager = Consent_Manager::instance();
		$cats    = $manager->sanitize_categories( array( 'necessary' => false, 'marketing' => true ) );
		$this->assertTrue( $cats['necessary'] );
		$this->assertTrue( $cats['marketing'] );
	}
}
