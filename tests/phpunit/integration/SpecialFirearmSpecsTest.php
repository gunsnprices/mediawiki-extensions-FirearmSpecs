<?php

namespace MediaWiki\Extension\FirearmSpecs\Tests\Integration;

use MediaWiki\Extension\FirearmSpecs\Specials\SpecialFirearmSpecs;
use MediaWiki\Request\FauxRequest;
use SpecialPageTestBase;

/**
 * @covers \MediaWiki\Extension\FirearmSpecs\Specials\SpecialFirearmSpecs
 * @license GPL-2.0-or-later
 */
class SpecialFirearmSpecsTest extends SpecialPageTestBase {

	protected function newSpecialPage() {
		return new SpecialFirearmSpecs( $this->getServiceContainer()->getMainConfig() );
	}

	public function testFormWithoutResults() {
		[ $html ] = $this->executeSpecialPage( '', null, 'en' );
		$this->assertMatchesRegularExpression( '/<textarea [^>]*name=[\'"]titles[\'"]/', $html );
		$this->assertMatchesRegularExpression( '/<select [^>]*name=[\'"]kind[\'"]/', $html );
		$this->assertStringNotContainsString( 'firearmspecs-results', $html );
		$this->assertStringContainsString( 'CC BY 4.0', $html );
	}

	public function testResultsAreEscaped() {
		$request = new FauxRequest( [
			'titles' => "Glock 19 Gen 5 MOS 9mm 4.02in 15rd Pistol\n\n<script>alert(1)</script> 9mm 115gr 50rd",
		] );
		[ $html ] = $this->executeSpecialPage( '', $request, 'en' );
		$this->assertStringContainsString( 'firearmspecs-results', $html );
		$this->assertStringContainsString( '<td>Glock 19 Gen 5 MOS</td>', $html );
		$this->assertStringContainsString( '<td>15 rounds</td>', $html );
		$this->assertStringContainsString( '<td>4.02 in</td>', $html );
		$this->assertStringContainsString( '<td>50 rounds</td>', $html );
		$this->assertStringContainsString( '&lt;script>alert(1)&lt;/script> 9mm 115gr 50rd', $html );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( 'gunsnprices.com/ammo', $html );
	}

	public function testSubpageAndKind() {
		$request = new FauxRequest( [ 'kind' => 'ammo' ] );
		[ $html ] = $this->executeSpecialPage( 'Magtech 9mm 50rd', $request, 'en' );
		$this->assertStringContainsString( '<td>Ammunition</td>', $html );
		$this->assertStringContainsString( '<td>50 rounds</td>', $html );
	}

	public function testUnitAndCaliberLinks() {
		$this->overrideConfigValues( [
			'FirearmSpecsBarrelUnit' => 'mm',
			'FirearmSpecsCaliberLinks' => true,
		] );
		$request = new FauxRequest( [ 'titles' => 'Glock 19 Gen 5 MOS 9mm 4.02in 15rd Pistol' ] );
		[ $html ] = $this->executeSpecialPage( '', $request, 'en' );
		$this->assertStringContainsString( '<td>102 mm</td>', $html );
		$this->assertStringContainsString(
			'href="https://gunsnprices.com/ammo/handgun-ammo/9-19mm-parabellum"', $html
		);
	}

	public function testTooManyLines() {
		$lines = str_repeat( "Glock 19 9mm\n", SpecialFirearmSpecs::MAX_LINES + 5 );
		[ $html ] = $this->executeSpecialPage( '', new FauxRequest( [ 'titles' => $lines ] ), 'en' );
		$this->assertSame( SpecialFirearmSpecs::MAX_LINES, substr_count( $html, '<td>Glock 19</td>' ) );
		$this->assertStringContainsString( 'Only the first 50 lines were read.', $html );
	}
}
