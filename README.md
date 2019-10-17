# router
Minimalistic PHP router with the ability of autoloading of classes, variables in the path, filters and redirects and localization

$router_cfg = [
	'routes' => [
		[ 'dashboard', 'home#dashboard', 'get' ],
		[ 'account', 'account#index', 'get, post' ],
		[ 'account/$id_user', 'account#index', 'get', 'user_has_right|accounts_read', 'account' ],
		[ 'account/$id_user', 'account#index', 'post', 'user_has_right|accounts_write', 'account' ],
		[ 'accounts', 'accounts#index', 'get', 'user_has_right|accounts_read', 'account' ],
		[ 'account/login', 'account#login', 'get, post', 'user_not_logged_in', 'account' ],
		[ 'account/forgot-password', 'account#forgot_password', 'get, post', 'user_not_logged_in', 'account' ],
		[ 'account/reset-password/$id_user/$hash', 'account#reset_password', 'get, post', 'user_not_logged_in', 'account' ],
		[ 'account/logout', 'account#logout', 'get' ],
		[ 'account/delete', 'account#delete', 'get', 'user_has_right|account_write', 'account' ],
		[ 'account/delete/$id_user', 'account#delete', 'get', 'user_has_right|accounts_write', 'account' ],
	],
	'localizations' => [
		'cs_CZ' => [
			'login' => 'prihlaseni',
			'forgot-password' => 'zapomenute-heslo',
			'reset-password' => 'obnoveni-hesla',
			'delete' => 'smazat',
			'logout' => 'odhlaseni',
			'dashboard' => 'nastenka',
			'account' => 'ucet',
			'accounts' => 'ucty',
		],
	],
	'handlers' => [
		401 => ['render', 'unauthorized'],
		404 => ['render', 'not_found'],
		500 => ['render', 'server_error'],
	],
	'config' => [
		'locale'            => 'cs_CZ',
		'autoprocess'       => false,
		'default_route'     => 'dashboard',
		'default_filter'    => 'user_logged_in',
		'default_redirect'  => 'account/login?url={route}',
		'static_renderer'   => 'render',
		'forbidden_prefix'  => '_',
		'allow_autoload'    => true,
		'base_dir'          => 'lib/controller',
		'default_controller'=> 'home',
		'default_method'    => 'index',
	],
];

function user_logged_in() {
  ...
}

function user_not_logged_in() {
  ...
}

function render($template, $message = null) {
  $templater = new Templater();
  return $templater->render_template($template, [ 'message' => $message ]);
}

...

$router = new Router($router_cfg);
$router->process_route();
