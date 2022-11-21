<?php declare( strict_types=1 );

namespace HDPiano\Integrations;

use Client\Integrations\Controllers\RevenueCat_Controller;

class Integrations_Subscriber extends \HDPiano\Container\Subscriber {
	public function register(): void {
		add_action( 'custom_rest_hook', function( $event ): void {
			$this->container->get( RevenueCat_Controller::class )->receive_webhook( (array) $event );
		}, 10, 1 );
	}
}
