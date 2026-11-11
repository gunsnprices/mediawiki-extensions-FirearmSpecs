<?php

namespace MediaWiki\Extension\FirearmSpecs;

use GunsNPrices\Parser as TitleParser;
use ReflectionClass;

/**
 * Reads specs with the gunsnprices library and turns them into plain values.
 *
 * This class does not depend on MediaWiki, so it can be unit tested on its own.
 * All values it returns are plain text: callers must escape them for their output.
 *
 * @license GPL-2.0-or-later
 */
class SpecReader {

	/** Barrel length units. */
	public const UNITS = [ 'in', 'cm', 'mm' ];

	/** Product types the library knows. */
	public const KINDS = [ 'firearm', 'ammo' ];

	/** Fields of {{#caliber:}}. */
	public const CALIBER_FIELDS = [ 'name', 'id', 'type', 'aliases' ];

	/** Fields of {{#firearmspecs:}}. */
	public const SPEC_FIELDS = [
		'kind', 'brand', 'model', 'family', 'caliber', 'calibers', 'caliberid', 'calibertype',
		'capacity', 'barrel', 'rounds', 'costperround',
	];

	/** Decimal places of a cost per round when none are given. */
	public const DEFAULT_DECIMALS = 2;

	/** Most decimal places allowed for a cost per round. */
	public const MAX_DECIMALS = 4;

	private const MM_PER_INCH = 25.4;

	/** @var array<string,string[]>|null Aliases keyed by caliber id, loaded on first use */
	private static ?array $aliases = null;

	/**
	 * Whether the gunsnprices/gunsnprices library is installed.
	 */
	public static function isAvailable(): bool {
		return class_exists( TitleParser::class );
	}

	/**
	 * Finds the caliber that a caliber name or a free text means.
	 *
	 * @param string $text A caliber name ("9MM LUGER", "5.56 NATO", "12 GA") or any text
	 * @return array{id:string,name:string,type:string,url:string}|null
	 */
	public static function caliber( string $text ): ?array {
		return trim( $text ) === '' ? null : TitleParser::normalizeCaliber( $text );
	}

	/**
	 * Returns the other spellings of a caliber that retailers use, as listed in the
	 * library's data file. Returns an empty list if the data file cannot be read.
	 *
	 * @param string $id Caliber id
	 * @return string[]
	 */
	public static function caliberAliases( string $id ): array {
		if ( self::$aliases === null ) {
			self::$aliases = self::loadAliases();
		}
		return self::$aliases[$id] ?? [];
	}

	/**
	 * @return array<string,string[]>
	 */
	private static function loadAliases(): array {
		$file = dirname( (string)( new ReflectionClass( TitleParser::class ) )->getFileName(), 2 )
			. '/data/calibers.json';
		$json = is_readable( $file ) ? file_get_contents( $file ) : false;
		$data = $json === false ? null : json_decode( $json, true );
		$aliases = [];
		foreach ( $data['calibers'] ?? [] as $caliber ) {
			if ( !isset( $caliber['id'] ) || !is_array( $caliber['aliases'] ?? null ) ) {
				continue;
			}
			$list = [];
			foreach ( $caliber['aliases'] as $alias ) {
				$alias = trim( (string)$alias );
				// One entry per spelling, ignoring case.
				if ( $alias !== '' && !isset( $list[mb_strtolower( $alias )] ) ) {
					$list[mb_strtolower( $alias )] = $alias;
				}
			}
			$aliases[(string)$caliber['id']] = array_values( $list );
		}
		return $aliases;
	}

	/**
	 * Parses a product title.
	 *
	 * @param string $title Product title
	 * @param string $kind 'firearm', 'ammo', or '' to guess from the title
	 * @param string $brand Brand, for titles that do not name it ('' if unknown)
	 * @param string $mpn Manufacturer part number ('' if unknown)
	 * @param float|null $price Price of the item, for the cost per round of ammunition
	 * @return array The library's result, plus the key 'price'
	 */
	public static function parse(
		string $title, string $kind = '', string $brand = '', string $mpn = '', ?float $price = null
	): array {
		$options = [];
		if ( in_array( $kind, self::KINDS, true ) ) {
			$options['kind'] = $kind;
		}
		if ( $brand !== '' ) {
			$options['brand'] = $brand;
		}
		if ( $mpn !== '' ) {
			$options['mpn'] = $mpn;
		}
		$parsed = TitleParser::parseTitle( $title, $options );
		$parsed['price'] = $price;
		return $parsed;
	}

	/**
	 * Returns one field of a parse result as plain text.
	 *
	 * @param array $parsed A result of parse()
	 * @param string $field One of SPEC_FIELDS
	 * @param string $unit Barrel length unit, one of UNITS
	 * @param int $decimals Decimal places of the cost per round
	 * @return string|null Null if the title does not have this spec
	 */
	public static function field( array $parsed, string $field, string $unit = 'in', int $decimals = 2 ): ?string {
		$caliber = $parsed['caliber'] ?? null;
		switch ( $field ) {
			case 'kind':
				return self::nonEmpty( $parsed['kind'] ?? '' );
			case 'brand':
				return self::nonEmpty( $parsed['brand'] ?? '' );
			case 'model':
				return self::nonEmpty( $parsed['model']['model'] ?? '' );
			case 'family':
				return self::nonEmpty( $parsed['model']['family'] ?? '' );
			case 'caliber':
				return self::nonEmpty( $caliber['name'] ?? '' );
			case 'calibers':
				return self::nonEmpty( implode( ', ', array_column( $parsed['calibers'] ?? [], 'name' ) ) );
			case 'caliberid':
				return self::nonEmpty( $caliber['id'] ?? '' );
			case 'calibertype':
				return self::nonEmpty( $caliber['type'] ?? '' );
			case 'capacity':
				return isset( $parsed['capacity'] ) ? (string)(int)$parsed['capacity'] : null;
			case 'barrel':
				return isset( $parsed['barrelLength'] )
					? self::barrelLength( (float)$parsed['barrelLength'], $unit )
					: null;
			case 'rounds':
				return isset( $parsed['rounds'] ) ? (string)(int)$parsed['rounds'] : null;
			case 'costperround':
				return isset( $parsed['price'] ) && isset( $parsed['rounds'] )
					? self::costPerRound( (float)$parsed['price'], (int)$parsed['rounds'], $decimals )
					: null;
		}
		return null;
	}

	/**
	 * Reads the number of rounds per box from a title.
	 *
	 * @param string $title Product title
	 * @return int|null
	 */
	public static function roundsInTitle( string $title ): ?int {
		return TitleParser::roundsPerBox( $title );
	}

	/**
	 * Converts a barrel length to a unit and formats it.
	 *
	 * @param float $inches Barrel length in inches
	 * @param string $unit One of UNITS
	 * @return string For example "16.5" (in), "41.9" (cm) or "419" (mm)
	 */
	public static function barrelLength( float $inches, string $unit ): string {
		switch ( $unit ) {
			case 'mm':
				return self::formatDecimal( $inches * self::MM_PER_INCH, 0, true );
			case 'cm':
				return self::formatDecimal( $inches * self::MM_PER_INCH / 10, 1, true );
		}
		return self::formatDecimal( $inches, 2, true );
	}

	/**
	 * Computes and formats the price of one round.
	 *
	 * @param float $price Price of the box
	 * @param int $rounds Rounds in the box
	 * @param int $decimals Decimal places
	 * @return string|null For example "0.38"; null if $rounds is not positive
	 */
	public static function costPerRound( float $price, int $rounds, int $decimals = self::DEFAULT_DECIMALS ): ?string {
		if ( $rounds <= 0 ) {
			return null;
		}
		return self::formatDecimal( $price / $rounds, $decimals, false );
	}

	/**
	 * Formats a number with a dot as the decimal separator and no thousands separator.
	 *
	 * @param float $number
	 * @param int $decimals Decimal places (rounded half up)
	 * @param bool $trimZeros Drop trailing zeros: 16.50 gives "16.5", 16.00 gives "16"
	 * @return string
	 */
	public static function formatDecimal( float $number, int $decimals, bool $trimZeros ): string {
		$decimals = max( 0, $decimals );
		$text = number_format( round( $number, $decimals ), $decimals, '.', '' );
		if ( $trimZeros && str_contains( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}
		return $text;
	}

	/**
	 * Reads a price: "18.99", "$18.99", "1,299.00" or "1299".
	 *
	 * A currency sign before the number is ignored. Commas are accepted only as
	 * thousands separators, so "18,99" is not a price.
	 *
	 * @param string $text
	 * @return float|null Null if the text is not a positive number
	 */
	public static function parsePrice( string $text ): ?float {
		$text = trim( preg_replace( '/^[^\d.\-]{1,4}/u', '', trim( $text ) ) ?? '' );
		if ( !preg_match( '/^(?:\d{1,3}(?:,\d{3})+|\d+)?(?:\.\d+)?$/', $text ) || !preg_match( '/\d/', $text ) ) {
			return null;
		}
		$price = (float)str_replace( ',', '', $text );
		return $price > 0 ? $price : null;
	}

	/**
	 * Reads a whole number such as a round count: "50" or "1,000".
	 *
	 * @param string $text
	 * @return int|null Null if the text is not a whole number
	 */
	public static function parseCount( string $text ): ?int {
		$text = trim( $text );
		if ( !preg_match( '/^(?:\d{1,3}(?:,\d{3})+|\d+)$/', $text ) ) {
			return null;
		}
		return (int)str_replace( ',', '', $text );
	}

	private static function nonEmpty( string $value ): ?string {
		return $value === '' ? null : $value;
	}
}
