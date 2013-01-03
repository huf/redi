<?php

/** TODO
 * support 'route' => 'other route' type aliases in $routes array
 *
 * url/link generation?
 * redirection? (internal is the thing above, probably. external is Location ....)
 **/
/** 
 * routes:
 * all keys are optional:
 * route_key => array(
 * 	'handler' => func, to be called for processing
 * 	'file' => filename to be included before calling handler
 * 	'filter' => func to call if handler returned non-null
 * 	'pre' => function to call before the rest of the request handling (argument is rq)
 * 	'post' => function to call after the rest of the request handling (argument is rq)
 * )
 * route_key is:
 * !e* - builtin, overridable catch-all error
 * !e404 
 * !e400
 * part/part/part - any number of such things
 * part can be literal or ?
 * /* at the end means it accepts any number of arguments afterwards
 *
 * options:
 * cache: file, if set, will read/write the cache and disregard all other arguments
 * cache_regen: if true, will regenerate cache file
 * defaults: hash of options to use as defaults in the routes
 *
 * make_rq($path) -> request obj
 * handle_rq($rq) -> whatever the handler for the path returns
 * handle($path) -> make_rq + handle_rq
 * run($path) -> output buffering + handle
 *
 * if you plan to echo stuff yourself in the handler, run is probably your best bet
 * if not, you can jump to another handler by doing
 * 		 return $this->handle('some/other/path');
 **/
class redi {
	const version = '0.1';

	private $routes,
		$options,
		$rx_to_path,
		$rxparts,
		$rx;

	public function __construct($routes, $opts = array()) {
		$need_init = true;

		if (!empty($opts['cache'])) {
			if (!empty($opts['cache_regen']) && is_file($opts['cache'])) {
				unlink($opts['cache']);
			}
			if (is_file($opts['cache'])) {
				$cache = json_decode(file_get_contents($opts['cache']), true);
				foreach ($cache as $k => $v) {
					$this->$k = $v;
				}
				$need_init = false;
			}
		}

		if ($need_init) {
			$routes += array(
				'!e*' => array(
					'handler' => array($this, 'generic_error_handler'),
				),
			);

			$part_rx = '(?:[\w_+-]*|\?)';
			$check_rx = "!^{$part_rx}*(?:/{$part_rx}*)*(?:/\*)?$!";

			$rxbits = array();
			$rx_to_path = array();

			foreach ($routes as $k => &$route) {
				if (is_scalar($route)) { // route alias
					if (isset($routes[$route])) {
						$route = $routes[$route];
					}
					else {
						die("route $k points to nonexistent route\n");
					}
				}
				if (!empty($opts['defaults'])) {
					foreach ($opts['defaults'] as $_k => $_v) {
						if (!isset($route[$_k])) {
							$route[$_k] = $_v;
						}
					}
				}

				if ($k[0] == '!') {
					continue;
				}
				if (!preg_match($check_rx, $k)) {
					die("bad route $k\n");
				}
				$length = substr_count($k, '/');
				if (substr($k, -2, 2) != '/*') {
					$length += 1;
				}

				$weight = 0;
				$weight += substr_count($k, '?');
				$weight += 10000 * substr_count($k, '*');

				$rx = strtr($k, array(
					'?' => '[^/]*',
					'/*' => '(?:$|/.*)',
				));

				$rx = "($rx)";
				$rxbits[$length][$weight][] = $rx;

				$rx_to_path[$rx] = $k;
			}
			unset($route);

			$rxparts = array();

			ksort($rxbits);
			$rxbits = array_reverse($rxbits, true);
			foreach ($rxbits as $length => $by_weight) {
				ksort($by_weight);
				foreach ($by_weight as $rx) {
					$rxparts = array_merge($rxparts, $rx);
				}
			}

			$rx_whole = implode('|', $rxparts);
			$rx_whole = "!^(?:$rx_whole)$!";
			
			$this->rx = $rx_whole;
			$this->rxparts = $rxparts;
			$this->rx_to_path = $rx_to_path;
			$this->routes = $routes;
			$this->options = $opts;

			if (!empty($opts['cache'])) {
				$cache = array();
				foreach (array('rx', 'rxparts', 'rx_to_path', 'routes', 'options') as $k) {
					$cache[$k] = $this->$k;
				}
				file_put_contents($opts['cache'], json_encode($cache));
			}
		}
	}

	private function select($path) {
		$key = null;
		if ($path[0] == '!') {
			if (isset($this->routes[$path])) {
				$key = $path;
			}
		}
		else if (preg_match($this->rx, $path, $m)) {
			$matching_idx = count($m)-2;
			$key = $this->rx_to_path[$this->rxparts[$matching_idx]];
		}

		if ($key) {
			return (object)array(
				'path' => $path,
				'key' => $key,
				'route' => $this->routes[$key],
				'args' => $this->parse_args($key, $path),
			);
		}
	}

	private function parse_args($key, $path) {
		if ($key[0] == '!') {
			return array();
		}
		$rx = strtr($key, array(
			'?' => '([^/]*)',
			'/*' => '(.*)',
		));
		if (preg_match("!^$rx$!", $path, $m)) {
			$args = $m;
			$args[0] = null;
			if (substr($key, -2, 2) == '/*') {
				$rest = array_pop($args);
				if ($rest !== '') {
					$args = array_merge($args, explode('/', $rest));
				}
			}
			return $args;
		}
		return array();
	}

	private function generic_error_handler($rq) {
		return "Some sort of error during {$rq->original_path}\n";
	}

	private function clean_path($path) {
		return $path && $path[0] == '!' ? '!e400' : $path;
	}

	public function make_rq($path) {
		$rq = null;
		foreach (array($this->clean_path($path), '!e404', '!e*') as $try) {
			if ($rq = $this->select($try)) {
				break;
			}
		}
		if (!$rq) {
			return null;
		}

		$rq->redi = $this;
		$rq->original_path = $path;
		$rq->args[0] = $rq;

		return $rq;
	}

	public function handle_rq($rq) {
		if (!empty($rq->route['pre'])) {
			call_user_func($rq->route['pre'], $rq);
		}
		if (!empty($rq->route['file'])) {
			require_once $rq->route['file'];
		}
		$return = null;
		if (!empty($rq->route['handler'])) {
			$return = call_user_func_array($rq->route['handler'], $rq->args);
			if ($return !== null && !empty($rq->route['filter'])) {
				$return = call_user_func($rq->route['filter'], $rq, $return);
			}
		}
		if (!empty($rq->route['post'])) {
			call_user_func($rq->route['post'], $rq);
		}
		return $return;
	}

	public function handle($path) {
		$rq = $this->make_rq($path);
		if (!$rq) {
			die("nothing found for $path\n");
		}
		return $this->handle_rq($rq);
	}

	public function run($path) {
		ob_start();
		$return = $this->handle($path);
		if ($return !== null) {
			if (is_scalar($return)) {
				echo $return;
			}
			else {
				var_dump($return);
			}
		}
		return ob_get_clean();
	}
}

