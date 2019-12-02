<?php

class Router {

	protected $_routes = [];
	protected $_localizations = [];
	protected $_handlers = [];
	protected $_config = [];
	protected $_current_route;
	protected $_current_method;

	function __construct($cfg) {
		if (!$cfg) {
			throw new Exception("No router configuration defined.");
		}
		$this->_config = $cfg['config'];
		if (!$this->_config['root']) {
			$this->_config['root'] = strtolower(dirname($_SERVER['SCRIPT_NAME']));
		}
		if ($this->_config['default_filter']) {
			$filter = explode('|', $this->_config['default_filter']);
			$this->is_valid_method($filter[0], true);
		}

		$this->_localizations = $cfg['localizations'];
		$this->_handlers = $cfg['handlers'];

		$this->_routes = $this->parse_routes($cfg['routes']);

		if ($this->_config['autoprocess']) {
			$this->process_route();
		}
	}

	function parse_route($request) {
		$request = str_replace('//', '/', $request);
		$parts = explode('/', trim($request, '/'));
		if ($parts[0] == '') {
			array_shift($parts);
		}
		return $parts;
	}

	function get_localized($route, $params = null) {
		$localizations = $this->_localizations[$this->_config['locale']];
		if (!$localizations) {
			return $route;
		}
		$parts = $this->parse_route($route);
		foreach ($parts as $index => $value) {
			if ($value[0] == '$') {
				$val = $params ? $params[substr($value, 1)] : null;
				if (isset($val)) {
					$parts[$index] = $val;
				}
			} elseif ($localizations[$value]) {
				$parts[$index] = $localizations[$value];
			}
		}
		$rel = join('/', $parts);
		$localized = $this->_config['root'] . '/' . $rel;
		return $localized;
	}

	function get_root() {
		return $this->_config['root'];
	}

	function get_unlocalized($route) {
		$localizations = $this->_localizations[$this->_config['locale']];
		if (!$localizations) {
			return $route;
		}
		$parts = $this->parse_route($route);
		foreach ($parts as $index => $value) {
			$idx = array_search($value, $localizations);
			if ($idx !== false) {
				$parts[$index] = $idx;
			}
		}
		$unlocalized = join('/', $parts);
		return $unlocalized;
	}

	function is_valid_method($method, $exception_on_error = false) {
		$valid = false;
		$parts = explode('|', $method);
		$method = $parts[0];
		$parts = explode('#', $method);
		if (count($parts) > 1) {
			$parts[0] = ucfirst($parts[0]);
			if (class_exists($parts[0], false)) {
				$valid = method_exists($parts[0], $parts[1]);
			} elseif ($this->_config['allow_autoload']) {
				# TODO: autoload of controller
				$path = explode('\\', $parts[0]);
				$file = $_SERVER['DOCUMENT_ROOT'] . $this->_config['root'] . '/' . $this->_config['base_dir'] . '/' . join('/', $path) . '.php';
				if (is_file($file)) {
					include_once($file);
					if (class_exists($parts[0], false)) {
						$valid = method_exists($parts[0], $parts[1]);
					}
				}
			}
		} else {
			$valid = is_callable($method);
		}
		if (!$valid && $exception_on_error) {
			throw new Exception("Method '$method' is not valid.");
		}
		return $valid;
	}

	function call_method($method, $args = []) {
		$parts = explode('#', $method);
		if (count($parts) > 1) {
			$parts[0] = ucfirst($parts[0]);
			$class = new $parts[0]();
			$name = $parts[1];
			return $class->$name($args);
		}
		if (is_array($args) && count($args)) {
			return call_user_func_array($method, $args);
		}
		return call_user_func($method);
	}

	function parse_routes($routes) {
		$allowed_methods = [
			'ANY',

			'GET',
			'HEAD',
			'POST',
			'PUT',
			'DELETE',
			'CONNECT',
			'OPTIONS',
			'TRACE',
			'PATCH',
		];
		$parsed = [];
		foreach ($routes as $route) {
			# route_name, target (function/method) [, methods_allowed [, filter, redirect ] ]
			$parts = $this->parse_route($route[0]);
			$curr = &$parsed;
			foreach ($parts as $part) {
				if ($part[0] == '$') {
					$param = substr($part, 1);
					if ($param[0] == '_') {
						throw new Exception("Route parameter name cannot begin with underscore in route '$route[0]'");
					}
					if (!array_key_exists('params', $curr)) {
						$curr['params'] = [];
					}
					# TODO: allow different names of same parts for different routes?
					if (array_key_exists('_name', $curr['params']) && $curr['params']['_name'] != $param) {
						throw new Exception("Route parameter '{$curr['params']['_name']}' cannot be replaced by '$param' in route '$route[0]'.");
					}
					$curr['params']['_name'] = $param;
					if (!array_key_exists($param, $curr['params'])) {
						$curr['params'][$param] = [];
					}
					$curr = &$curr['params'][$param];
				} else {
					if (!array_key_exists('subs', $curr)) {
						$curr['subs'] = [];
					}
					if (!array_key_exists($part, $curr['subs'])) {
						$curr['subs'][$part] = [];
					}
					$curr = &$curr['subs'][$part];
				}
			}
			if (!array_key_exists('methods', $curr)) {
				$curr['methods'] = [];
			}
			if (count($route) > 2) {
				if (!$route[2]) {
					$methods = ['ANY'];
				} else {
					$route[2] = strtoupper(str_replace(' ', '', $route[2]));
					$methods = explode(',', $route[2]);
					if (in_array('ANY', $methods)) {
						$methods = ['ANY'];
					}
				}
				foreach ($methods as $method) {
					$method = strtoupper(trim($method));
					if (in_array($method, $allowed_methods)) {
						$curr['methods'][$method] = [
							'target' => $route[1],
						];
					} else {
						throw new Exception("Method '$method' is not allowed in route '$route[0]'.");
					}
					if (count($route) > 3 || $this->_config['default_filter']) {
						if (count($route) < 4) {
							$route[3] = $this->_config['default_filter'];
							$curr['methods'][$method]['filter'] = $this->_config['default_filter'];
						} else {
							if ($route[3] && $this->is_valid_method($route[3], true)) {
								$curr['methods'][$method]['filter'] = $route[3];
							}
						}
						$curr['methods'][$method]['redirect'] = null;
						if (count($route) < 5 && $this->_config['default_redirect']) {
							$route[4] = $this->_config['default_redirect'];
						}
						if (count($route) > 4) {
							if ($route[4]) {
								$curr['methods'][$method]['redirect'] = $route[4];
							} else {
								if (array_key_exists('default_route', $this->_config)) {
									$curr['methods'][$method]['redirect'] = $this->_config['default_route'];
								}
							}
						}
					}
				}
			} else {
				$curr['methods']['ANY'] = [
					'target' => $route[1],
				];
			}
		}
		return $parsed;
	}

	function process_static($route) {
		$renderer = $this->_config['static_renderer'];
		if ($renderer) {
			$renderer = explode('|', $renderer);
			if (count($renderer) > 1) {
				$renderer[1] = explode(',', $renderer[1]);
			}
			if (!is_array($renderer[1])) {
				if ($renderer[1]) {
					$renderer[1] = [$renderer[1]];
				} else {
					$renderer[1] = [];
				}
			}
			array_push($renderer[1], $route);
			if ($this->is_valid_method($renderer[0])) {
				return $this->call_method($renderer[0], $renderer[1]);
			}
		}
		return $this->handle_error(404, 'Template not found');
	}

	function get_current_route($clean = false) {
		$current_route = $this->_current_route;
		if ($clean) {
			$current_route = preg_replace('/(\/\$[^\/]+)/', '', $current_route);
		}
		return $current_route;
	}

	function get_current_method() {
		return $this->_current_method;
	}

	function process_route($route = null, $method = null) {
		if ($route === null) {
			$route = $_SERVER['REQUEST_URI'];
			if (!$method) {
				$method = $_SERVER['REQUEST_METHOD'];
			}
		} else {
			if ($method === null) {
				$method = 'GET';
			}
		}
		$orig_route = $route;
		$route = parse_url($route, PHP_URL_PATH);
		$root = $this->_config['root'];
		if (strtolower(substr($route, 0, strlen($root))) == $root) {
			$route = substr($route, strlen($root));
		}
		$method = strtoupper($method);
		$url_parts = $this->parse_route($route);
		$curr_pos = &$this->_routes;
		$params = [];

		$locale = $this->_config['locale'];

		$matched_method = null;
		$controller_path = '';
		$current_route = [];

		foreach ($url_parts as $index => $part) {
			if ($this->_config['forbidden_prefix'] && substr($part, 0, strlen($this->_config['forbidden_prefix'])) == $this->_config['forbidden_prefix']) {
				return $this->handle_error(404, "Forbidden prefix '{$this->_config['forbidden_prefix']}' in route $route.");
			}
	
			$part = $this->get_unlocalized($part);
			if ($curr_pos && $curr_pos['subs'][strtolower($part)]) {
				$curr_pos = &$curr_pos['subs'][strtolower($part)];
				array_push($current_route, $part);
			} elseif ($curr_pos && $curr_pos['params']) {
				# TODO: match multiple params by data types (regexes)
				$param = $curr_pos['params']['_name'];
				$params[$param] = $part;
				$curr_pos = &$curr_pos['params'][$param];
				array_push($current_route, '$' . $param);
			} elseif ($this->_config['allow_autoload']) {
				$piece = str_replace('-', '_', $part);
				$curr_pos = false;
				if ($index == count($url_parts)-1) {
					$target;
					if ($controller_path) {
						if ($this->is_valid_method("$controller_path#$piece")) {
							$target = "$controller_path#$piece";
						} elseif ($this->is_valid_method("$controller_path\\$piece#{$this->_config['default_method']}")) {
							$target = "$controller_path\\$piece#{$this->_config['default_method']}";
						}
					} else {
						if ($this->is_valid_method("{$this->_config['default_controller']}#$piece")) {
							$target = "{$this->_config['default_controller']}#$piece";
						} elseif ($this->is_valid_method("$piece#{$this->_config['default_method']}")) {
							$target = "$piece#{$this->_config['default_method']}";
						}
					}
					if ($target) {
						$matched_method = [
							'target'   => $target,
							'filter'   => $this->_config['default_filter'],
							'redirect' => $this->_config['default_redirect'],
						];
					}
				}
				$controller_path = join('\\', [$controller_path, $piece]);
				array_push($current_route, $part);
			} else {
				$curr_pos = false;
				break;
			}
		}

		$this->_current_route = join('/', $current_route);

		if ($curr_pos && !$matched_method) {
			$matched_method = $curr_pos['methods']['ANY'] ? $curr_pos['methods']['ANY'] : $curr_pos['methods'][$method];
		}
		if ($matched_method) {
			$allowed = array_key_exists('target', $matched_method) && $matched_method['target'];
			if ($allowed && array_key_exists('filter', $matched_method) && $matched_method['filter']) {
				$filter = explode('|', $matched_method['filter']);
				if (count($filter) > 1) {
					$filter[1] = explode(',', $filter[1]);
					foreach ($filter[1] as $key => $value) {
						if ($filter[1][$key][0] == '$') {
							$filter[1][$key] = $params[substr($filter[1][$key], 1)];
						}
					}
				} else {
					if ($filter[1][0] == '$') {
						$filter[1] = $params[substr($filter[1], 1)];
					}
				}
				$allowed = $this->call_method($filter[0], $filter[1]);
			}
			if ($allowed) {
				$target = $matched_method['target'];
				if ($this->is_valid_method($target)) {
					$this->_current_method = $target;
					return $this->call_method($target, $params);
				} else {
					return $this->process_static($this->_current_route);
				}
			}
			if ($matched_method['redirect']) {
				$redirect = $matched_method['redirect'];
				$redirect = str_replace('{route}', urlencode($orig_route), $redirect);
				if (substr($redirect, 0, 7) == 'func://') {
					$redirect = substr($redirect, 7);
					$redirect = explode('|', $redirect);
					if (count($redirect) > 1) {
						$redirect[1] = explode(',', $redirect[1]);
					}
					if ($this->is_valid_method($redirect[0])) {
						return $this->call_method($redirect[0], $redirect[1]);
					} else {
						return $this->handle_error(404, 'Redirect method does not exist');
					}
				}
				$redirect = $this->get_localized($redirect);
				return $this->redirect_to($redirect);
			}
			return $this->handle_error(401, 'Forbidden');
		}
		if ($route == '/' && $this->_config['default_route']) {
			return $this->process_route($root . '/' . $this->_config['default_route']);
		}
		return $this->process_static($this->_current_route);
		return $this->handle_error(404, 'File not found');
	}

	function handle_error($error_number, $error_message = null) {
		http_response_code($error_number);
		if ($this->_handlers[$error_number]) {
			$method = $this->_handlers[$error_number];
			if (is_array($method)) {
				$params = $method;
				$method = array_shift($params);
				array_push($params, $error_message);
				return call_user_func_array($method, $params);
			} else {
				return call_user_func($method, $error_message);
			}
		}
		return $error_number . ($error_message ? ' - ' . $error_message : '');
	}

	function redirect_to($location, $code = 302) {
		return header('Location: ' . $location, true, $code);
	}

}
?>
