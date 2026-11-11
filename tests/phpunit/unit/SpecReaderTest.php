<?php

namespace MediaWiki\Extension\FirearmSpecs\Tests\Unit;

use MediaWiki\Extension\FirearmSpecs\SpecReader;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\FirearmSpecs\SpecReader
 * @license GPL-2.0-or-later
 */
class SpecReaderTest extends MediaWikiUnitTestCase {

	public function testLibraryIsAvailable() {
		$this->assertTrue( SpecReader::isAvailable() );
	}

	public static function providePrices(): array {
		return [
			'plain' => [ '18.99', 18.99 ],
			'whole' => [ '18', 18.0 ],
			'dollar sign' => [ '$18.99', 18.99 ],
			'currency code' => [ 'USD 18.99', 18.99 ],
			'thousands' => [ '1,299.00', 1299.0 ],
			'spaces' => [ '  $ 449.99 ', 449.99 ],
			'leading dot' => [ '.5', 0.5 ],
			'decimal comma' => [ '18,99', null ],
			'negative' => [ '-5', null ],
			'zero' => [ '0', null ],
			'word' => [ 'free', null ],
			'empty' => [ '', null ],
			'two dots' => [ '1.2.3', null ],
			'markup' => [ '[[18.99]]', null ],
		];
	}

	/**
	 * @dataProvider providePrices
	 */
	public function testParsePrice( string $text, ?float $expected ) {
		$this->assertSame( $expected, SpecReader::parsePrice( $text ) );
	}

	public static function provideCounts(): array {
		return [
			[ '50', 50 ],
			[ ' 1,000 ', 1000 ],
			[ '0', 0 ],
			[ '1000', 1000 ],
			[ '1,00', null ],
			[ '50 rounds', null ],
			[ '', null ],
		];
	}

	/**
	 * @dataProvider provideCounts
	 */
	public function testParseCount( string $text, ?int $expected ) {
		$this->assertSame( $expected, SpecReader::parseCount( $text ) );
	}

	public static function provideDecimals(): array {
		return [
			[ 16.5, 2, true, '16.5' ],
			[ 16.0, 2, true, '16' ],
			[ 4.02, 2, true, '4.02' ],
			[ 0.3798, 2, false, '0.38' ],
			[ 0.4, 2, false, '0.40' ],
			[ 0.125, 2, false, '0.13' ],
			[ 1234.5, 0, false, '1235' ],
		];
	}

	/**
	 * @dataProvider provideDecimals
	 */
	public function testFormatDecimal( float $number, int $decimals, bool $trim, string $expected ) {
		$this->assertSame( $expected, SpecReader::formatDecimal( $number, $decimals, $trim ) );
	}

	public function testBarrelLength() {
		$this->assertSame( '16.5', SpecReader::barrelLength( 16.5, 'in' ) );
		$this->assertSame( '41.9', SpecReader::barrelLength( 16.5, 'cm' ) );
		$this->assertSame( '419', SpecReader::barrelLength( 16.5, 'mm' ) );
		$this->assertSame( '4.02', SpecReader::barrelLength( 4.02, 'unknown' ) );
	}

	public function testCostPerRound() {
		$this->assertSame( '0.38', SpecReader::costPerRound( 18.99, 50 ) );
		$this->assertSame( '0.380', SpecReader::costPerRound( 18.99, 50, 3 ) );
		$this->assertSame( '1.60', SpecReader::costPerRound( 31.99, 20 ) );
		$this->assertNull( SpecReader::costPerRound( 18.99, 0 ) );
	}

	public function testCaliber() {
		$caliber = SpecReader::caliber( '308 WIN (7.62X51 NATO)' );
		$this->assertSame( '.308 Win', $caliber['name'] );
		$this->assertSame( 'rifle', $caliber['type'] );
		$this->assertNull( SpecReader::caliber( '' ) );
		$this->assertNull( SpecReader::caliber( 'not a caliber' ) );
	}

	public function testCaliberAliases() {
		$aliases = SpecReader::caliberAliases( '22lr' );
		$this->assertContains( '.22 Long Rifle', $aliases );
		$this->assertSameSize( $aliases, array_unique( array_map( 'mb_strtolower', $aliases ) ) );
		$this->assertSame( [], SpecReader::caliberAliases( 'no-such-id' ) );
	}

	public function testFirearmFields() {
		$parsed = SpecReader::parse( 'Glock 19 Gen 5 MOS 9mm 4.02in 15rd Pistol PA195S203MOS' );
		$expected = [
			'kind' => 'firearm',
			'brand' => 'Glock',
			'model' => 'Glock 19 Gen 5 MOS',
			'family' => 'Glock 19',
			'caliber' => '9×19mm Parabellum',
			'calibers' => '9×19mm Parabellum',
			'caliberid' => '9x19mmparabellum',
			'calibertype' => 'handgun',
			'capacity' => '15',
			'barrel' => '4.02',
			'rounds' => null,
			'costperround' => null,
		];
		foreach ( SpecReader::SPEC_FIELDS as $field ) {
			$this->assertSame( $expected[$field], SpecReader::field( $parsed, $field ), $field );
		}
		$this->assertSame( '102', SpecReader::field( $parsed, 'barrel', 'mm' ) );
		$this->assertNull( SpecReader::field( $parsed, 'no-such-field' ) );
	}

	public function testAmmoFields() {
		$parsed = SpecReader::parse( 'Federal American Eagle 5.56x45mm 55gr FMJ 1000 Rounds', '', '', '', 449.99 );
		$this->assertSame( 'ammo', SpecReader::field( $parsed, 'kind' ) );
		$this->assertSame( '1000', SpecReader::field( $parsed, 'rounds' ) );
		$this->assertSame( '0.45', SpecReader::field( $parsed, 'costperround' ) );
		$this->assertSame( '0.4500', SpecReader::field( $parsed, 'costperround', 'in', 4 ) );
		$this->assertNull( SpecReader::field( $parsed, 'model' ) );
		$this->assertNull( SpecReader::field( $parsed, 'capacity' ) );
	}

	public function testKindBrandAndMpn() {
		$this->assertSame( 'firearm', SpecReader::parse( 'Magtech 9mm 50rd' )['kind'] );
		$this->assertSame( 50, SpecReader::parse( 'Magtech 9mm 50rd', 'ammo' )['rounds'] );
		$parsed = SpecReader::parse( 'GLOCK 9MM PISTOL 15RD', 'firearm', 'Glock', 'UA4350201' );
		$this->assertSame( 'Glock 43', SpecReader::field( $parsed, 'model' ) );
	}

	public function testRoundsInTitle() {
		$this->assertSame( 20, SpecReader::roundsInTitle( 'Winchester 308 150gr PowerMax Bonded 20rds' ) );
		$this->assertNull( SpecReader::roundsInTitle( 'Federal 9mm 115gr FMJ' ) );
	}
}
