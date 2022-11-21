# Container

Container is a simple package that functions as our service container for the plugin.

Any functionality that alters or adds to WordPress' core functionality should be added to the container either through a Definer or a Subscriber.

## Definer

Definers are the simplest concept. When you add a service to a definer, PHP-DI makes it available through the container to the application. For example"

```php
public function define(): array {
	return [
		'foo' => 'bar',
		Foo_Controller::class => static function(): Foo_Controller {
			// Something needed in the constructor.
			$bar = app_env( 'SECRET' );

			return new Foo_Controller( $bar );
		},
	];
}
```

This allows you to access these two definitions the following ways:

```php
$foo = hdpiano()->get( 'foo' ); // 'bar'
$foo = hdpiano()->get( Foo_Controller::class ); // Foo_Controller instance.
```

## Subscriber

A subscriber attaches functions in your controllers to WordPress events. When you add a service that uses WordPress hooks to fire, you *subscribe* to that event. For example:

```php
public function register(): void {
	add_action( 'init', function (): void {
		$this->container->get( Foo_Controller::class )->my_method();
	}, 10, 0 );
}
```
