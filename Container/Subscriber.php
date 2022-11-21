<?php declare( strict_types=1 );

namespace HDPiano\Container;

use Psr\Container\ContainerInterface;

/**
 * Class Subscriber.
 *
 * @package    HDPiano-Plugin
 * @subpackage Container
 */
abstract class Subscriber implements Subscriber_Interface {
	protected ContainerInterface $container;

	/**
	 * Abstract_Subscriber constructor.
	 *
	 * @param \Psr\Container\ContainerInterface $container
	 */
	public function __construct( ContainerInterface $container ) {
		$this->container = $container;
	}
}
