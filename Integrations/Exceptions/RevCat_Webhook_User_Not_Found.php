<?php declare( strict_types=1 );

namespace HDPiano\Integrations\Exceptions;

/**
 * Class RevCat_Webhook_User_Not_Found
 */
class RevCat_Webhook_User_Not_Found extends \Exception {

	/**
	 * RevCat_Webhook_User_Not_Found constructor.
	 */
	public function __construct() {
		parent::__construct( 'Referenced user not found', 404 );
	}
}
