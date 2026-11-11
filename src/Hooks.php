<?php

namespace MediaWiki\Extension\FirearmSpecs;

use MediaWiki\Config\Config;
use MediaWiki\Hook\ParserFirstCallInitHook;

/**
 * Registers the parser functions.
 *
 * @license GPL-2.0-or-later
 */
class Hooks implements ParserFirstCallInitHook {

	public function __construct(
		private readonly Config $config
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onParserFirstCallInit( $parser ) {
		$functions = new ParserFunctions( $this->config );
		$parser->setFunctionHook( 'firearmspecs_caliber', [ $functions, 'caliber' ] );
		$parser->setFunctionHook( 'firearmspecs', [ $functions, 'firearmSpecs' ] );
		$parser->setFunctionHook( 'firearmspecs_costperround', [ $functions, 'costPerRound' ] );
	}
}
