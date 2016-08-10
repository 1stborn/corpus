<?php
namespace Corpus;

use Interop\Container\ContainerInterface;

use Slim\Handlers\AbstractHandler;
use Slim\Handlers\NotFound;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * @property View view
 */
class App extends AbstractHandler {
	/**
	 * @var ContainerInterface
	 */
	protected $ci;

	protected $session;

	public $http_status = 200;

	protected $method, $params;

	private $args;

	public function __construct(ContainerInterface  $ci) {
		$this->ci = $ci;
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

	public function before() {}

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

	public function getSession() {
		return $this->session;
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
		return $this->ci->get('request');
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
		return $this->ci->get('response');
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

	public function route($method, array $args = []) {
		if ( $route = $this->findRoute($method) )
			return call_user_func_array($route, $args);
		else if ( $view = $this->getView($method) ?: $this->getView($this->getRequest()->getUri()->getPath()) )
			return call_user_func([$this, 'render'], $args, $view);
		else
			return call_user_func(new NotFound(), $this->getRequest(), $this->getResponse());
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
		$this->method = trim($request->getUri()->getPath(), DS) ?: Config::get('router.default');
		$this->params = $args;

		$this->before();

		return $this->after($this->route($this->method, $args));
	}
}