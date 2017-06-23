<?php

namespace Corpus;

use Interop\Container\ContainerInterface;
use Slim\Container;
use Slim\Handlers\AbstractHandler;
use Slim\Handlers\NotFound;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * @property View    view
 * @property Cookies cookies
 * @property Session session
 * @property DB      db
 * @property Redis   cache
 */
class App extends AbstractHandler {
	/**
	 * @var ContainerInterface
	 */
	protected $ci;

	public $http_status = 200;

	protected
		$method,
		$params = [],
		$view_root = "";

	/**
	 * @var Response
	 */
	private $response;

	/**
	 * @var Request
	 */
	private $request;

	private $args;

	protected $default = 'index';

	public static function run($silent = false, $options = []) {
		$options = Config::merge([
			'settings' => Config::get('settings'),
			'view'     => function ($ci) {
				$renderer = Config::get('view.renderer');

				return new $renderer($ci);
			},
			'db'       => function () {
				return new DB(Config::get('db.{default}'));
			},
			'cache'    => function () {
				return new Redis(Config::get("redis.{default}"));
			},
			'cookies'  => function ($ci) {
				return new Cookies($ci);
			},
			'session'  => function ($ci) {
				return new Session($ci);
			}
		], $options);

		$app = new \Slim\App(new Container($options));

		$app->add(function (Request $request, Response $response, \Slim\App $app) {
			$path = $request->getUri()->getPath();

			if ( preg_match('~^/([a-z]{2})($|/)~i', $path, $m) ) {
				if ( in_array($m[1], Config::get('language.available')) ) {
					$path = substr($path, 3) ?: '/';

					$request =
						$request
							->withUri($request->getUri()->withPath($path))
							->withAttribute('language', $m[1]);
				}
			}

			$namespace = Config::get('router.namespace');
			$search = explode('/', trim($path, DS));

			do {
				if ( class_exists($controller = $namespace . implode('\\', $search)) )
					break;
				array_pop($search);
			} while ($search);

			if ( !$search ) {
				$controller = $namespace . Config::get('router.default');
			}

			$app->map(Config::get('router.methods'), $path, $controller);

			return $app($request, $response);
		});

		return $app->run($silent);
	}

	public function __construct(ContainerInterface $ci) {
		$this->ci = $ci;
	}

	public function param($name, $default = null) {
		$name = is_array($name) ? reset($name) : $name;

		return array_key_exists($name, $this->params)
			? $this->params[$name]
			: $this->getRequest()->getParam($name, $default);
	}

	public function params() {
		return array_merge($this->params, $this->getRequest()->getParams());
	}

	public function url() {
		return strtolower(str_replace('\\', '/', str_replace(__NAMESPACE__, '', static::class)) . DS . $this->method);
	}

	public function controller($class) {
		return new $class($this->ci);
	}

	public function before() {
		$method = substr(strtolower($this->getRequest()->getUri()->getPath()), 1);
		$controller =
			str_replace('\\', DS,
				strtolower(
					substr(get_class($this), strlen(Config::get('router.namespace')))));

		if ( strpos($method, $controller) === 0 )
			$method = substr($method, strlen($controller) + 1);

		$parts = explode(DS, $method);

		for ( $i = 0, $l = sizeof($parts); $i != $l; $i++ )
			if ( !preg_match('~^[a-z]~iu', $parts[$i]) )
				break;

		$method = implode(DS, array_slice($parts, 0, $i));
		if ( isset($parts[$i]) )
			$this->params = array_merge($this->params, array_slice($parts, $i));

		$this->method = trim(trim($method), DS) ?: strtolower(Config::get('router.default'));

		$this->assign('controller',
			$controller == $this->method
				? $this->method
				: $controller . DS . $this->method
		);
	}

	public function actionIndex() {
		if ( $view = $this->getView($this->method) ?: $this->getView($this->getRequest()->getUri()->getPath()) )
			return call_user_func([$this, 'render'], $this->params, $view);
		else
			return call_user_func(new NotFound(), $this->getRequest(), $this->getResponse());
	}

	public function render(array $args = [], $template = null) {
		return $this->view->render(
			Config::merge(
				$args,
				$this->args,
				['request' => (array)$this->params + $this->getRequest()->getParams()]
			), $template ?: $this->getView());
	}

	public function assign($name, $value = null) {
		if ( is_array($name) )
			foreach ( $name as $key => $value )
				$this->args[$key] = $value;
		else
			$this->args[$name] = $value;

		return $this;
	}

	public function refresh() {
		return $this->redirect($this->getRequest()->getUri());
	}

	public function redirect($url, $status = 301) {
		return $this->getResponse()->withRedirect($url, $status);
	}

	public function moveTo($method, $status = 302) {
		return $this->getResponse()->withRedirect(DS . $this->path($method), $status);
	}

	public function __get($name) {
		return $this->ci->get($name);
	}

	/**
	 * @return Request
	 */
	public function getRequest() {
		return $this->request;
	}

	public function getPath() {
		return $this->getRequest()->getUri()->getPath();
	}

	public function isValid(...$required) {
		foreach ( $required as $key )
			if ( !$this->param($key) )
				return false;

		return true;
	}

	/**
	 * @return Response
	 */
	public function getResponse() {
		return $this->response;
	}

	/**
	 * @param $content
	 * @return Response
	 */
	public function asJson($content) {
		if ( $content instanceof \Traversable ) {
			$data = [];
			foreach ( $content as $id => $value )
				$data[$id] = $value;

			$content = $data;
		}

		return $this->getResponse()->withJson(
			$content,
			$this->http_status,
			JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES
		);
	}

	public function asPlain($content) {
		if ( $content instanceof View ) {
			$content = $this->render([], $content);
		}

		return $this->getResponse()->withStatus($this->http_status)->write($content);
	}

	/**
	 * @param Response|mixed $response
	 * @return Response
	 */
	public function after($response) {
		if ( $response instanceof Response )
			return $response;
		else if ( is_scalar($response) )
			return $this->asPlain($response);
		else if ( $response )
			return $this->asJson($response);
		else if ( $this->request->isXhr() && $this->request->getMethod() == 'POST' )
			return $this->getResponse()->withStatus(202);
		else if ( $view = $this->getView() )
			return $this->asPlain($this->render((array)$response, $view));
		else
			return $this->getResponse()->withStatus(204);
	}

	public function route($method) {
		if ( $route = $this->findRoute($method, $this->default) ) {
			if ( method_exists($this, 'middleware') ) {
				$result = $this->middleware();

				if ( $result instanceof Response ) {
					return $result;
				}
				else if ( $result === false ) {
					return $this->getResponse()->withStatus(403);
				}
			}

			return call_user_func_array($route, $this->params);
		}

		return null;
	}

	protected function findRoute($method) {
		$http_method = strtolower($this->getRequest()->getMethod());

		foreach ( func_get_args() as $current ) {
			$current = preg_replace('~[^a-z_0-9]~iu', '', $current);

			if ( method_exists($this, $http_method . $current) )
				return [$this, $http_method . $current];

			if ( method_exists($this, 'action' . $current) )
				return [$this, 'action' . $current];
		}

		return null;
	}

	protected function getView($method = null) {
		$method = strtolower($method ?: $this->method);
		$path   = strtolower(substr(static::class, Config::get('router.namespace|strlen')));

		return
			$this->view->find($this->view_root . DS . $path . DS . $method) ?:
				$this->view->find($this->view_root . DS . $path);
	}

	final public function __invoke(Request $request, Response $response, array $args = []) {
		$this->request  = $request;
		$this->response = $response;
		$this->params   = $args;

		$this->before();

		$response = $this->after($this->route($this->method));

		foreach ( $this->ci->get('cookies')->toHeaders() as $cookie ) {
			$response = $response->withAddedHeader('Set-Cookie', $cookie);
		}

		return $response;
	}
}