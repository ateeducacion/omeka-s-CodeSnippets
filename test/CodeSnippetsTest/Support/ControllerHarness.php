<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

use CodeSnippets\Controller\Admin\SnippetController;
use CodeSnippets\Service\ActionCsrf;
use CodeSnippets\Service\SafeMode;
use CodeSnippets\Service\SnippetService;

/**
 * Wires SnippetController plugins without Laminas MVC.
 */
class ControllerHarness
{
    /** @var SnippetController */
    public $controller;

    /** @var object */
    public $request;

    /** @var object */
    public $response;

    /** @var object */
    public $params;

    /** @var object */
    public $redirect;

    /** @var object */
    public $messenger;

    /** @var object */
    public $url;

    /** @var bool */
    public $allowed = true;

    /** @var string|null */
    public $identityRole = 'global_admin';

    public function __construct(
        SnippetService $service,
        SafeMode $safeMode,
        ActionCsrf $csrf
    ) {
        $this->request = new class {
            /** @var bool */
            public $post = false;
            /** @var array<string, mixed> */
            public $query = [];

            public function isPost()
            {
                return $this->post;
            }

            public function getQuery($name = null, $default = null)
            {
                if ($name === null) {
                    return $this->query;
                }
                return array_key_exists($name, $this->query) ? $this->query[$name] : $default;
            }
        };

        $this->response = new class {
            /** @var int */
            public $statusCode = 200;
            /** @var string */
            public $content = '';
            /** @var object */
            public $headers;

            public function __construct()
            {
                $this->headers = new class {
                    /** @var array<int, array{0:mixed,1:mixed}> */
                    public $lines = [];

                    public function addHeaderLine($name, $value)
                    {
                        $this->lines[] = [$name, $value];
                    }
                };
            }

            public function setStatusCode($code)
            {
                $this->statusCode = (int) $code;
                return $this;
            }

            public function getHeaders()
            {
                return $this->headers;
            }

            public function setContent($content)
            {
                $this->content = (string) $content;
                return $this;
            }
        };

        $this->params = new class {
            /** @var array<string, mixed> */
            public $post = [];
            /** @var array<string, mixed> */
            public $route = [];

            public function fromPost($name = null, $default = null)
            {
                if ($name === null) {
                    return $this->post;
                }
                return array_key_exists($name, $this->post) ? $this->post[$name] : $default;
            }

            public function fromRoute($name = null, $default = null)
            {
                if ($name === null) {
                    return $this->route;
                }
                return array_key_exists($name, $this->route) ? $this->route[$name] : $default;
            }
        };

        $this->redirect = new class {
            /** @var string|null */
            public $route;
            /** @var array<string, mixed> */
            public $params = [];
            /** @var array<string, mixed> */
            public $options = [];

            public function toRoute($route = null, $params = [], $options = [])
            {
                $this->route = $route;
                $this->params = $params;
                $this->options = $options;
                return 'redirect:' . (string) $route;
            }
        };

        $this->messenger = new class {
            /** @var array<int, mixed> */
            public $success = [];
            /** @var array<int, mixed> */
            public $error = [];
            /** @var array<int, mixed> */
            public $formErrors = [];

            public function addSuccess($message)
            {
                $this->success[] = $message;
            }

            public function addError($message)
            {
                $this->error[] = $message;
            }

            public function addFormErrors($form)
            {
                $this->formErrors[] = $form;
            }
        };

        $this->url = new class {
            public function fromRoute($route = null, $params = [], $options = [], $reuse = false)
            {
                return '/admin/code-snippets';
            }
        };

        $controller = new SnippetController($service, $safeMode, $csrf);
        $controller->request = $this->request;
        $controller->response = $this->response;
        $harness = $this;
        $controller->pluginMap = [
            'params' => $this->params,
            'url' => $this->url,
            'redirect' => $this->redirect,
            'messenger' => $this->messenger,
            'userIsAllowed' => static function () use ($harness) {
                return $harness->allowed;
            },
            'translate' => static function ($message) {
                return $message;
            },
            'identity' => static function () use ($harness) {
                if ($harness->identityRole === null) {
                    return null;
                }
                return new class ($harness->identityRole) {
                    /** @var mixed */
                    private $role;

                    public function __construct($role)
                    {
                        $this->role = $role;
                    }

                    public function getRole()
                    {
                        return $this->role;
                    }
                };
            },
        ];
        $this->controller = $controller;
    }
}
