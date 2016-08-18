<?php
namespace Corpus;

use Interop\Container\ContainerInterface;

use Slim\Container;
use Slim\Handlers\AbstractHandler;
use Slim\Handlers\NotFound;
use Slim\Http\Cookies;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * @property View view
 * @property \Corpus\Redis cache
 */
class App extends AbstractHandler {
	/**
	 * @var ContainerInterface
	 */
	protected $ci;

	public $http_status = 200;

	protected $method, $params;

	/**
	 * @var Response
	 */
	private $response;
	/**
	 * @var Request
	 */
	private $request;

	private $args;

	private $session = null;

	private $cookies;

	protected $default = 'index';

	public static function run($silent = false, $options = []) {
		$options = Config::merge([
			'settings' => Config::get('settings'),
			'view' => function() {
				$renderer = Config::get('view.renderer');
				return new $renderer;
			},
			'db' => function() {
				return new DB(Config::get('db.{default}'));
			},
			'cache' => function() {
				return new Redis(Config::get("redis.{default}"));
			}
		], $options);

		$app = new \Slim\App(new Container($options));

		$app->add(function (Request $request, Response $response, \Slim\App $app) {
			$path = $request->getUri()->getPath();

			if ( preg_match('~^/([a-z]{2})($|/)~i', $path, $m) ) {
				$path = substr($path, 3) ?: '/';

				if ( in_array($m[1], Config::get('language.available')) )
					$request = $request
						->withUri($request->getUri()->withPath($path))
						->withAttribute('language', $m[1]);
				else
					return $response->withRedirect($path);
			}

			$controller = ucfirst(current(explode('/', trim($path, DS))) ?: Config::get('router.default'));
			if ( !class_exists(Config::get('router.namespace') . $controller) )
				$controller = Config::get('router.default');

			$app->map(Config::get('router.methods'), $path, Config::get('router.namespace') . $controller);

			return $app($request, $response);
		});

		return $app->run($silent);
	}

	public function __construct(ContainerInterface  $ci) {
		$this->ci = $ci;
		$this->cookies = new Cookies;
	}

	public function param($name, $default = null) {
		return array_key_exists($name, $this->params)
			? $this->params[$name]
			: $this->getRequest()->getParam($name, $default);
	}

	public function url() {
		return strtolower(str_replace('\\', '/', str_replace(__NAMESPACE__, '', static::class)) . DS . $this->method);
	}

	public function controller($class) {
		return new $class($this->ci);
	}

	public function before() {
		$method = strtolower(trim($this->getRequest()->getUri()->getPath(), DS));
		$controller = strtolower(substr(get_class($this), strlen(Config::get('router.namespace'))));

		$this->method = trim(str_replace($controller, '', $method), '/') ?: strtolower(Config::get('router.default'));
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
				[ 'request' => (array)$this->params + $this->getRequest()->getParams() ]
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

	public function flash($message = null) {
		if ( !array_key_exists('__flash__', $this->session) )
			$this->session['__flash__'] = [];

		return $message
			? array_push($this->session['__flash__'], $message)
			: array_pop($this->session['__flash__']);
	}

	public function get($param, $default = null) {
		if ( is_null($this->session) ) {
			$this->session = $this->cache->hgetall($this->getSID()) ?: [];
		}

		return
			array_key_exists($param, $this->session)
				? ( $this->session[$param] ?: $default )
				: $default;
	}

	private function getSID($scope = '') {
		if ( !$sid = $this->getCookie('sid') )
			$this->setCookie('sid', $sid = sha1(fread(fopen('/dev/urandom', 'r'), 100)), Config::get('session.lifetime'));

		return '__session.' . ( $scope ? $scope . '.' : '') . $sid;
	}

	public function setCookie($name, $value, $expire = 0) {
		$this->cookies->set($name, ['value' => $value, 'expires' => $expire]);
	}

	public function getCookie($name, $default = null) {
		return $this->cookies->get(
			$name,
			$this->getRequest()->getCookieParam($name, $default));
	}

	public function set($param, $value = null) {
		if ( is_array($param) )
			foreach ( $param as $key => $value )
				$this->set($key, $value);
		else if (is_null($value) )
			unset($this->session[$param]);
		else
			$this->session[$param] = $value;

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
		return $this->getResponse()->withJson($content,
			$this->http_status, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * @param Response|mixed $response
	 * @return Response
	 */
	public function after($response) {
		if ( $response instanceof Response )
			return $response;
		else if ( is_scalar($response) )
			return $this->getResponse()->withStatus($this->http_status)->write($response);
		else if ( $view = $this->getView() )
			return $this->render((array)$response, $view);
		else if ( $response )
			return $this->asJson($response);
		else
			return $this->getResponse()->withStatus(204);
	}

	public function route($method) {
		if ( $route = $this->findRoute($method) )
			return call_user_func_array($route, $this->params);
		else {
			$http_method = strtolower($this->getRequest()->getMethod());

			return call_user_func([
				$this, (
					method_exists($this, $http_method . $this->default) ? $http_method : 'action'
				) . $this->default]);
		}
	}

	protected function findRoute($method) {
		$http_method = strtolower($this->getRequest()->getMethod());

		$method = preg_replace('~[^a-z_0-9]~iu', '', $method);

		if ( method_exists($this, $http_method . $method) )
			return [$this, $http_method . $method];

		if ( method_exists($this, 'action' . $method) )
			return [$this, 'action' . $method ];

		return null;
	}

	protected function getView($method = null) {
		$method = $method ?: $this->method;

		return $this->view->find(strtolower($method)) ?:
			   $this->view->find($this->path($method));
	}

	protected function path($method = null) {
		$method = $method ?: $this->method;

		return strtolower(str_replace(Config::get('router.namespace'), '', static::class . DS . $method));
	}

	final public function __invoke(Request $request, Response $response, array $args = []) {
		$this->request = $request;
		$this->response = $response;

		$this->params = $args;

		$response = $this->before() ?: $this->after($this->route($this->method));

		foreach ($this->cookies->toHeaders() as $cookie )
			$response = $response->withAddedHeader('Set-Cookie', $cookie);

		return $response;
	}

	public function __destruct() {
		if ( $this->session ) {
			$this->cache->del($this->getSID());
			$this->cache->hmset($this->getSID(), $this->session);
			if ( $lifetime = Config::get('session.lifetime', 0))
				$this->cache->expire($this->getSID(), $lifetime);
		}
	}
}