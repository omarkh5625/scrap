<?php

/**
 * Router - Simple routing system for the application
 */
class Router {
    
    private $routes = [];
    private $basePath = '';
    
    public function __construct($basePath = '') {
        $this->basePath = rtrim($basePath, '/');
    }
    
    /**
     * Add a GET route
     * @param string $path
     * @param callable $handler
     */
    public function get($path, $handler) {
        $this->addRoute('GET', $path, $handler);
    }
    
    /**
     * Add a POST route
     * @param string $path
     * @param callable $handler
     */
    public function post($path, $handler) {
        $this->addRoute('POST', $path, $handler);
    }
    
    /**
     * Add a route
     * @param string $method
     * @param string $path
     * @param callable $handler
     */
    public function addRoute($method, $path, $handler) {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler
        ];
    }
    
    /**
     * Dispatch request
     * @return mixed
     */
    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        
        // Remove query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }
        
        // Remove base path
        if (!empty($this->basePath)) {
            $uri = substr($uri, strlen($this->basePath));
        }
        
        $uri = '/' . trim($uri, '/');
        
        // Find matching route
        foreach ($this->routes as $route) {
            if ($route['method'] === $method) {
                $pattern = $this->compilePattern($route['path']);
                
                if (preg_match($pattern, $uri, $matches)) {
                    array_shift($matches); // Remove full match
                    return call_user_func_array($route['handler'], $matches);
                }
            }
        }
        
        // No route found
        http_response_code(404);
        return ['error' => 'Route not found'];
    }
    
    /**
     * Compile route pattern to regex
     * @param string $path
     * @return string
     */
    private function compilePattern($path) {
        // Convert :param to regex group
        $pattern = preg_replace('/\/:([^\/]+)/', '/(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }
    
    /**
     * Send JSON response
     * @param mixed $data
     * @param int $code
     */
    public static function json($data, $code = 200) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    /**
     * Send HTML response
     * @param string $html
     * @param int $code
     */
    public static function html($html, $code = 200) {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }
}
