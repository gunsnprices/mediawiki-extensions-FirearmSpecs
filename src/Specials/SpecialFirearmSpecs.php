<?php

namespace MediaWiki\Extension\FirearmSpecs\Specials;

use GunsNPrices\Parser as TitleParser;
use MediaWiki\Config\Config;
use MediaWiki\Extension\FirearmSpecs\SpecReader;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Special:FirearmSpecs: reads the specs of product titles typed into a form.
 *
 * Special:FirearmSpecs/<title> reads one title given in the URL.
 *
 * @license GPL-2.0-or-later
 */
class SpecialFirearmSpecs extends SpecialPage {

	/** Most titles read at once. */
	public const MAX_LINES = 50;

	/** Longest title read, in characters. */
	public const MAX_LENGTH = 500;

	/** Only links to these pages are made, whatever the library data says. */
	private const LINK_PATTERN = '#^https://gunsnprices\.com/[A-Za-z0-9/._~-]+$#';

	/** @var array[] Parse results of the submitted titles */
	private array $results = [];

	private bool $truncated = false;

	public function __construct(
		private readonly Config $config
	) {
		parent::__construct( 'FirearmSpecs' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();
		$this->addHelpLink( 'https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:FirearmSpecs', true );
		$out = $this->getOutput();

		if ( !SpecReader::isAvailable() ) {
			$out->addHTML( Html::errorBox( $this->msg( 'firearmspecs-error-library' )->escaped() ) );
			return;
		}

		HTMLForm::factory( 'ooui', [
			'titles' => [
				'type' => 'textarea',
				'name' => 'titles',
				'rows' => 6,
				'label-message' => [ 'firearmspecs-form-titles', self::MAX_LINES ],
				'default' => $par ?? '',
			],
			'kind' => [
				'type' => 'select',
				'name' => 'kind',
				'label-message' => 'firearmspecs-form-kind',
				'options-messages' => [
					'firearmspecs-form-kind-guess' => '',
					'firearmspecs-kind-firearm' => 'firearm',
					'firearmspecs-kind-ammo' => 'ammo',
				],
				'default' => '',
			],
		], $this->getContext() )
			->setMethod( 'get' )
			->setTitle( $this->getPageTitle() )
			->setWrapperLegendMsg( 'firearmspecs-legend' )
			->setSubmitTextMsg( 'firearmspecs-form-submit' )
			->setSubmitCallback( [ $this, 'onSubmit' ] )
			->show();

		if ( $this->results ) {
			$out->addHTML( $this->resultsHtml() );
		}
		$out->addWikiMsg( 'firearmspecs-footer', TitleParser::VERSION );
	}

	/**
	 * Reads the submitted titles.
	 *
	 * @param array $data Form data
	 * @return bool Always false, so that the form stays on the page
	 */
	public function onSubmit( array $data ): bool {
		$lines = preg_split( '/\R/u', (string)( $data['titles'] ?? '' ) ) ?: [];
		$lines = array_values( array_filter( array_map( 'trim', $lines ), static function ( $line ) {
			return $line !== '';
		} ) );
		if ( count( $lines ) > self::MAX_LINES ) {
			$this->truncated = true;
			$lines = array_slice( $lines, 0, self::MAX_LINES );
		}
		$kind = (string)( $data['kind'] ?? '' );
		foreach ( $lines as $line ) {
			$this->results[] = SpecReader::parse( mb_substr( $line, 0, self::MAX_LENGTH ), $kind );
		}
		return false;
	}

	/**
	 * @return string HTML of the results table
	 */
	private function resultsHtml(): string {
		$unit = strtolower( (string)$this->config->get( 'FirearmSpecsBarrelUnit' ) );
		if ( !in_array( $unit, SpecReader::UNITS, true ) ) {
			$unit = 'in';
		}
		$columns = [ 'title', 'kind', 'brand', 'model', 'caliber', 'capacity', 'barrel', 'rounds' ];
		$header = '';
		foreach ( $columns as $column ) {
			// Messages used: firearmspecs-col-title, firearmspecs-spec-kind, firearmspecs-spec-brand,
			// firearmspecs-spec-model, firearmspecs-spec-caliber, firearmspecs-spec-capacity,
			// firearmspecs-spec-barrel, firearmspecs-spec-rounds
			$key = $column === 'title' ? 'firearmspecs-col-title' : 'firearmspecs-spec-' . $column;
			$header .= Html::element( 'th', [ 'scope' => 'col' ], $this->msg( $key )->text() );
		}
		$rows = Html::rawElement( 'tr', [], $header );
		foreach ( $this->results as $parsed ) {
			$cells = Html::element( 'td', [], $parsed['title'] );
			// Messages used: firearmspecs-kind-firearm, firearmspecs-kind-ammo
			$cells .= Html::element( 'td', [], $this->msg( 'firearmspecs-kind-' . $parsed['kind'] )->text() );
			$cells .= Html::element( 'td', [], SpecReader::field( $parsed, 'brand' ) ?? '' );
			$cells .= Html::element( 'td', [], SpecReader::field( $parsed, 'model' ) ?? '' );
			$cells .= Html::rawElement( 'td', [], $this->calibersHtml( $parsed['calibers'] ) );
			$cells .= Html::element( 'td', [], $this->roundsText( $parsed['capacity'] ?? null ) );
			$barrel = SpecReader::field( $parsed, 'barrel', $unit );
			// Messages used: firearmspecs-unit-in, firearmspecs-unit-cm, firearmspecs-unit-mm
			$cells .= Html::element( 'td', [],
				$barrel === null ? '' : $this->msg( 'firearmspecs-unit-' . $unit )->numParams( $barrel )->text()
			);
			$cells .= Html::element( 'td', [], $this->roundsText( $parsed['rounds'] ?? null ) );
			$rows .= Html::rawElement( 'tr', [], $cells );
		}

		$html = Html::element( 'h2', [], $this->msg( 'firearmspecs-results' )->text() );
		if ( $this->truncated ) {
			$html .= Html::warningBox(
				$this->msg( 'firearmspecs-results-truncated' )->numParams( self::MAX_LINES )->escaped()
			);
		}
		$html .= Html::rawElement( 'table', [ 'class' => 'wikitable firearmspecs-results' ], $rows );
		$html .= $this->msg( 'firearmspecs-results-usage' )->parseAsBlock();
		return $html;
	}

	/**
	 * @param array[] $calibers Calibers from the library
	 * @return string HTML
	 */
	private function calibersHtml( array $calibers ): string {
		$links = (bool)$this->config->get( 'FirearmSpecsCaliberLinks' );
		$items = [];
		foreach ( $calibers as $caliber ) {
			$url = $caliber['url'] ?? '';
			$items[] = $links && preg_match( self::LINK_PATTERN, $url )
				? Linker::makeExternalLink( $url, $caliber['name'], true, '', [], $this->getPageTitle() )
				: htmlspecialchars( $caliber['name'] );
		}
		return implode( $this->msg( 'comma-separator' )->escaped(), $items );
	}

	/**
	 * @param int|null $count
	 * @return string "15 rounds", or '' for null
	 */
	private function roundsText( ?int $count ): string {
		return $count === null ? '' : $this->msg( 'firearmspecs-rounds' )->numParams( $count )->text();
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'wiki';
	}
}
