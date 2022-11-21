<?php declare( strict_types=1 );

namespace HDPiano\Container;

/**
 * Interface Subscriber_Interface.
 *
 * @package    HDPiano-Plugin
 * @subpackage Container
 */
interface Subscriber_Interface {
	/**
	 * Register action/filter listeners to hook into WordPress
	 *
	 * @return void
	 */
	public function register(): void;
}
