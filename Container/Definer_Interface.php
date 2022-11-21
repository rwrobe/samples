<?php declare( strict_types=1 );

namespace HDPiano\Container;

/**
 * Interface Definer_Interface.
 *
 * @package    HDPiano-Plugin
 * @subpackage Container
 */
interface Definer_Interface {
	/**
	 * Sets service definitions.
	 *
	 * @return array
	 */
	public function define(): array;
}
