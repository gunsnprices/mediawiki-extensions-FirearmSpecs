<?php

namespace MediaWiki\Extension\FirearmSpecs;

use MediaWiki\Config\Config;
use MediaWiki\Parser\Parser;

/**
 * The parser functions {{#caliber:}}, {{#firearmspecs:}} and {{#costperround:}}.
 *
 * Every function returns wikitext. Text from the library or from the page is
 * escaped with wfEscapeWikiText(), so it is shown as written and never read
 * as markup. A value that the title does not contain gives an empty string,
 * so the functions work with {{#if:}}; invalid input gives an error that
 * {{#iferror:}} recognizes.
 *
 * @license GPL-2.0-or-later
 */
class ParserFunctions {

	/** Only links to these pages are made, whatever the library data says. */
	private const LINK_PATTERN = '#^https://gunsnprices\.com/[A-Za-z0-9/._~-]+$#';

	public function __construct(
		private readonly Config $config
	) {
	}

	/**
	 * {{#caliber: text | field }}
	 *
	 * @param Parser $parser
	 * @param string $text A caliber name or any text that contains one
	 * @param string $field '' (display name), 'name', 'id', 'type' or 'aliases'
	 * @return string Wikitext
	 */
	public function caliber( Parser $parser, string $text = '', string $field = '' ): string {
		if ( !SpecReader::isAvailable() ) {
			return $this->error( $parser, 'firearmspecs-error-library' );
		}
		$field = strtolower( trim( $field ) );
		if ( $field !== '' && !in_array( $field, SpecReader::CALIBER_FIELDS, true ) ) {
			return $this->fieldError( $parser, $field, SpecReader::CALIBER_FIELDS );
		}
		$caliber = SpecReader::caliber( $this->input( $parser, $text ) );
		if ( $caliber === null ) {
			return '';
		}
		switch ( $field ) {
			case '':
				return $this->caliberName( $caliber );
			case 'aliases':
				return wfEscapeWikiText( implode( ', ', SpecReader::caliberAliases( $caliber['id'] ) ) );
			default:
				return wfEscapeWikiText( $caliber[$field] );
		}
	}

	/**
	 * {{#firearmspecs: title | field | kind= | brand= | mpn= | price= | unit= | decimals= }}
	 *
	 * Without a field, returns a table of all specs found in the title.
	 *
	 * @param Parser $parser
	 * @param string $title Product title
	 * @param string ...$args A field name and named options, in any order
	 * @return string Wikitext
	 */
	public function firearmSpecs( Parser $parser, string $title = '', string ...$args ): string {
		if ( !SpecReader::isAvailable() ) {
			return $this->error( $parser, 'firearmspecs-error-library' );
		}
		$options = $this->readOptions( $parser, $args, [ 'kind', 'brand', 'mpn', 'price', 'unit', 'decimals' ] );
		$field = strtolower( $options[0] ?? '' );
		if ( $field !== '' && !in_array( $field, SpecReader::SPEC_FIELDS, true ) ) {
			return $this->fieldError( $parser, $field, SpecReader::SPEC_FIELDS );
		}

		$kind = strtolower( $options['kind'] ?? '' );
		if ( $kind !== '' && !in_array( $kind, SpecReader::KINDS, true ) ) {
			return $this->error( $parser, 'firearmspecs-error-kind', $kind );
		}
		$unit = strtolower( $options['unit'] ?? '' );
		if ( $unit === '' ) {
			$unit = $this->defaultUnit();
		} elseif ( !in_array( $unit, SpecReader::UNITS, true ) ) {
			return $this->error( $parser, 'firearmspecs-error-unit', $unit );
		}
		$price = null;
		if ( ( $options['price'] ?? '' ) !== '' ) {
			$price = SpecReader::parsePrice( $options['price'] );
			if ( $price === null ) {
				return $this->error( $parser, 'firearmspecs-error-price', $options['price'] );
			}
		}
		$decimals = $this->readDecimals( $options['decimals'] ?? '' );
		if ( $decimals === null ) {
			return $this->error( $parser, 'firearmspecs-error-decimals', $options['decimals'] ?? '' );
		}

		$title = $this->input( $parser, $title );
		if ( $title === '' ) {
			return '';
		}
		$parsed = SpecReader::parse(
			$title, $kind, $options['brand'] ?? '', $options['mpn'] ?? '', $price
		);
		if ( $field === '' ) {
			return $this->table( $parser, $parsed, $unit, $decimals );
		}
		$value = SpecReader::field( $parsed, $field, $unit, $decimals );
		return $value === null ? '' : wfEscapeWikiText( $value );
	}

	/**
	 * {{#costperround: price | round count or title | decimals= }}
	 *
	 * @param Parser $parser
	 * @param string $price Price of the box: "18.99", "$18.99" or "1,299.00"
	 * @param string $rounds A round count ("50", "1,000") or a title that contains one
	 * @param string ...$args Named options
	 * @return string Wikitext: the price of one round, such as "0.38"
	 */
	public function costPerRound( Parser $parser, string $price = '', string $rounds = '', string ...$args ): string {
		if ( !SpecReader::isAvailable() ) {
			return $this->error( $parser, 'firearmspecs-error-library' );
		}
		$options = $this->readOptions( $parser, $args, [ 'decimals' ] );
		$decimals = $this->readDecimals( $options['decimals'] ?? '' );
		if ( $decimals === null ) {
			return $this->error( $parser, 'firearmspecs-error-decimals', $options['decimals'] ?? '' );
		}
		$price = $this->input( $parser, $price );
		$amount = SpecReader::parsePrice( $price );
		if ( $amount === null ) {
			return $this->error( $parser, 'firearmspecs-error-price', $price );
		}
		$rounds = $this->input( $parser, $rounds );
		if ( $rounds === '' ) {
			return $this->error( $parser, 'firearmspecs-error-rounds' );
		}
		$count = SpecReader::parseCount( $rounds ) ?? SpecReader::roundsInTitle( $rounds );
		if ( $count === null ) {
			return '';
		}
		return SpecReader::costPerRound( $amount, $count, $decimals ) ?? '';
	}

	/**
	 * Builds the table of specs.
	 *
	 * @param Parser $parser
	 * @param array $parsed A result of SpecReader::parse()
	 * @param string $unit Barrel length unit
	 * @param int $decimals Decimal places of the cost per round
	 * @return string Wikitext table, or '' if the title has no specs
	 */
	private function table( Parser $parser, array $parsed, string $unit, int $decimals ): string {
		$rows = [];
		foreach ( [ 'brand', 'model' ] as $key ) {
			$value = SpecReader::field( $parsed, $key );
			if ( $value !== null ) {
				$rows[$key] = wfEscapeWikiText( $value );
			}
		}
		if ( $parsed['calibers'] ) {
			$rows['caliber'] = implode( ', ', array_map( [ $this, 'caliberName' ], $parsed['calibers'] ) );
		}
		if ( isset( $parsed['capacity'] ) ) {
			$rows['capacity'] = $this->text( $parser->msg( 'firearmspecs-rounds' )->numParams( $parsed['capacity'] ) );
		}
		$barrel = SpecReader::field( $parsed, 'barrel', $unit );
		if ( $barrel !== null ) {
			// Messages used: firearmspecs-unit-in, firearmspecs-unit-cm, firearmspecs-unit-mm
			$rows['barrel'] = $this->text( $parser->msg( 'firearmspecs-unit-' . $unit )->numParams( $barrel ) );
		}
		if ( isset( $parsed['rounds'] ) ) {
			$rows['rounds'] = $this->text( $parser->msg( 'firearmspecs-rounds' )->numParams( $parsed['rounds'] ) );
		}
		$cost = SpecReader::field( $parsed, 'costperround', $unit, $decimals );
		if ( $cost !== null ) {
			$symbol = (string)$this->config->get( 'FirearmSpecsCurrencySymbol' );
			$rows['costperround'] = $this->text(
				$parser->msg( 'firearmspecs-costperround-value' )->plaintextParams( $symbol . $cost )
			);
		}
		if ( !$rows ) {
			return '';
		}

		// Messages used: firearmspecs-kind-firearm, firearmspecs-kind-ammo
		$rows = [ 'kind' => $this->text( $parser->msg( 'firearmspecs-kind-' . $parsed['kind'] ) ) ] + $rows;
		$lines = [];
		foreach ( $rows as $key => $value ) {
			// Messages used: firearmspecs-spec-kind, firearmspecs-spec-brand, firearmspecs-spec-model,
			// firearmspecs-spec-caliber, firearmspecs-spec-capacity, firearmspecs-spec-barrel,
			// firearmspecs-spec-rounds, firearmspecs-spec-costperround
			$label = $this->text( $parser->msg( 'firearmspecs-spec-' . $key ) );
			$lines[] = "! scope=\"row\" | $label\n| $value\n";
		}
		return "{| class=\"wikitable firearmspecs\"\n" . implode( "|-\n", $lines ) . '|}';
	}

	/**
	 * Returns the display name of a caliber: plain, or linked to its price
	 * comparison page if $wgFirearmSpecsCaliberLinks is on.
	 *
	 * @param array $caliber A caliber from the library
	 * @return string Wikitext
	 */
	private function caliberName( array $caliber ): string {
		$name = wfEscapeWikiText( $caliber['name'] );
		$url = $caliber['url'] ?? '';
		if ( $this->config->get( 'FirearmSpecsCaliberLinks' ) && preg_match( self::LINK_PATTERN, $url ) ) {
			return "[$url $name]";
		}
		return $name;
	}

	/**
	 * Splits arguments into named options ("name=value") and positional ones.
	 *
	 * @param Parser $parser
	 * @param string[] $args
	 * @param string[] $names Option names
	 * @return array<int|string,string> Options by name, positional arguments by index
	 */
	private function readOptions( Parser $parser, array $args, array $names ): array {
		$options = [];
		$position = 0;
		foreach ( $args as $arg ) {
			if ( preg_match( '/^\s*([a-z]+)\s*=(.*)$/si', $arg, $m )
				&& in_array( strtolower( $m[1] ), $names, true )
			) {
				$options[strtolower( $m[1] )] = $this->input( $parser, $m[2] );
			} else {
				$options[$position++] = $this->input( $parser, $arg );
			}
		}
		return $options;
	}

	/**
	 * Returns the text of an argument: the content of <nowiki> tags is kept,
	 * other strip markers are removed.
	 *
	 * @param Parser $parser
	 * @param string $text
	 * @return string
	 */
	private function input( Parser $parser, string $text ): string {
		return trim( $parser->killMarkers( $parser->getStripState()->unstripNoWiki( $text ) ) );
	}

	/**
	 * @param string $value '' for the default, else 0 to SpecReader::MAX_DECIMALS
	 * @return int|null Null if invalid
	 */
	private function readDecimals( string $value ): ?int {
		if ( $value === '' ) {
			return SpecReader::DEFAULT_DECIMALS;
		}
		if ( !ctype_digit( $value ) || (int)$value > SpecReader::MAX_DECIMALS ) {
			return null;
		}
		return (int)$value;
	}

	private function defaultUnit(): string {
		$unit = strtolower( (string)$this->config->get( 'FirearmSpecsBarrelUnit' ) );
		return in_array( $unit, SpecReader::UNITS, true ) ? $unit : 'in';
	}

	/**
	 * @param \MediaWiki\Message\Message $message
	 * @return string Message text, escaped for wikitext
	 */
	private function text( $message ): string {
		return wfEscapeWikiText( $message->text() );
	}

	/**
	 * @param Parser $parser
	 * @param string $field The unknown field
	 * @param string[] $fields Valid fields
	 * @return string Wikitext
	 */
	private function fieldError( Parser $parser, string $field, array $fields ): string {
		return $this->error( $parser, 'firearmspecs-error-field', $field, implode( ', ', $fields ) );
	}

	/**
	 * @param Parser $parser
	 * @param string $key Message key
	 * @param string ...$params Message parameters (plain text)
	 * @return string Wikitext of an error that {{#iferror:}} recognizes
	 */
	private function error( Parser $parser, string $key, string ...$params ): string {
		// Messages used: firearmspecs-error-library, firearmspecs-error-field, firearmspecs-error-kind,
		// firearmspecs-error-unit, firearmspecs-error-price, firearmspecs-error-decimals,
		// firearmspecs-error-rounds
		$text = $parser->msg( $key )->plaintextParams( ...$params )->text();
		return '<strong class="error">' . wfEscapeWikiText( $text ) . '</strong>';
	}
}
