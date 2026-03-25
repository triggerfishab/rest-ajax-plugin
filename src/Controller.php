<?php
declare(strict_types=1);

namespace Triggerfish\REST_Ajax;

use WP_REST_Server;
use WP_REST_Request;
use WP_Error;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use function App\template;
use function App\locate_template;

/**
 * Ajax functionality description
 *
 * The following WordPress action and filters are available:
 * Actions:
 * - tf/ajax/before
 * - tf/ajax/before/action=XX
 * - tf/ajax/after/action=XX
 * - tf/ajax/after/action=XX
 *
 * Filters:
 * - tf/ajax/result
 * - tf/ajax/result/action=XX
 * - tf/ajax/template_paths
 * - tf/ajax/template_paths/action=XX
 *
 * Actions and handlers does not need to be registered like tf_add_ajax_handler or add_action('wp_ajax_XXX').
 * The request is automatically mapped to a class or a method, specific to the current action.
 * All is mapped by the action sent in the request.
 * Automatic templating can be achieved.
 *
 * Request flow:
 *
 * 1. A class named like the action in StudlyCase will be searched in the following namespace, App\AjaxHandler.
 *    If such a class is found and has a public method named "__getData", the flow will jump to 3.
 *    This is what is called "class based" handler below.
 *
 * 2. If a class cannot be found by 1, the fallback will be searched for.
 *    The fallback is a public static method named like the action in camelCase in App\AjaxHandler\DefaultHandler.
 *    This is what is called "method based" handler below.
 *
 * 3. Automatic templating.
 *    If the data from 1 or 2 is an array, a template named like the action in kebab-case will be searched for
 *    in a directory called "ajax" in the views directory.
 *
 *    But if the handler is "class based", the class can define a public method named "__template"
 *    that return the preferred template's path.
 *    This will take precedence over, and fall back to, the template in the "ajax" directory from above.
 *
 *    The data from 1 or 2 will be injected as the template will be included with the App\template function.
 */

class Controller
{
    const REST_NAMESPACE = 'theme/v1';
    const REST_ROUTE = 'ajax';

    protected $request;
    protected $action;

    public static function registerRESTRoute()
    {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods' => [ WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ],
                'callback' => function (WP_REST_Request $request) {
                    $instance = new self($request);

                    return $instance->dispatchRequest();
                },
                'args' => [
                    'action' => [
                        'type' => 'string',
                        'required' => true,
                        'validate_callback' => function ($action, $request) {
                            try {
                                return self::hasCallableHandler($action, $request);
                            } catch (\Throwable $e) {
                                error_log(
                                    sprintf(
                                        'REST Ajax validate: %s in %s:%d',
                                        $e->getMessage(),
                                        $e->getFile(),
                                        $e->getLine()
                                    )
                                );

                                return new WP_Error(
                                    'rest_ajax_validate',
                                    (defined('WP_DEBUG') && WP_DEBUG) ? $e->getMessage() : 'Validation failed.',
                                    ['status' => 500]
                                );
                            }
                        },
                    ],
                ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    protected function __construct(WP_REST_Request $request)
    {
        $this->request = $request;
        $this->action = $this->request->get_param('action');
    }

    protected function dispatchRequest()
    {
        try {
            return $this->handleRequest();
        } catch (\Throwable $e) {
            error_log(
                sprintf(
                    'REST Ajax exception: %s in %s:%d',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                )
            );

            return new WP_Error(
                'rest_ajax_exception',
                (defined('WP_DEBUG') && WP_DEBUG) ? $e->getMessage() : 'Request failed.',
                ['status' => 500]
            );
        }
    }

    protected static function setPolylangCurlangFromModel(object $pll): void
    {
        if (! isset($pll->model) || ! is_object($pll->model)) {
            return;
        }

        $model = $pll->model;
        $lang = null;

        if (method_exists($model, 'get_language_from_request')) {
            $slug = $model->get_language_from_request();
            if ($slug) {
                $lang = $model->get_language($slug);
            }
        }

        if (! $lang && method_exists($model, 'get_default_language')) {
            $default = $model->get_default_language();
            if ($default) {
                $lang = $model->get_language($default);
            }
        }

        if (is_object($lang) && isset($lang->slug)) {
            $pll->curlang = $lang;
            $GLOBALS['text_direction'] = (isset($lang->is_rtl) && $lang->is_rtl) ? 'rtl' : 'ltr';
        }
    }

    protected static function maybeSetPolylangLanguageForAjax(): void
    {
        if (! function_exists('PLL')) {
            return;
        }

        $pll = PLL();

        if (class_exists(\PLL_REST_Request::class) && $pll instanceof \PLL_REST_Request) {
            self::setPolylangCurlangFromModel($pll);

            return;
        }

        if (class_exists(\PLL_Frontend::class) && $pll instanceof \PLL_Frontend) {
            if (class_exists(\PLL_Choose_Lang_Url::class)) {
                $choose_lang = new \PLL_Choose_Lang_Url($pll);
                $lang = $choose_lang->get_preferred_language();

                if (is_object($lang) && isset($lang->slug)) {
                    $pll->curlang = $lang;
                    $GLOBALS['text_direction'] = (isset($lang->is_rtl) && $lang->is_rtl) ? 'rtl' : 'ltr';
                }
            }

            return;
        }

        self::setPolylangCurlangFromModel($pll);
    }

    protected function handleRequest()
    {
        add_filter('wp_doing_ajax', '__return_true');

        self::maybeSetPolylangLanguageForAjax();

        do_action('tf/ajax/before', $this->action, $this->request);
        do_action('tf/ajax/before/action=' . $this->action, $this->action);


        $result = $this->getHandlerData($this->action, $this->request->get_params());

        if (is_wp_error($result)) {
            return rest_ensure_response($result);
        }

        if (is_array($result) || (is_object($result) && $result instanceof ArrayAccess)) {
            $template = $this->getTemplate();

            if (! empty($template)) {
                try {
                    $result = template($template, $result);
                } catch (\Throwable $e) {
                    error_log(
                        sprintf(
                            'REST Ajax template: %s in %s:%d',
                            $e->getMessage(),
                            $e->getFile(),
                            $e->getLine()
                        )
                    );

                    return rest_ensure_response(
                        new WP_Error(
                            'rest_ajax_template',
                            (defined('WP_DEBUG') && WP_DEBUG) ? $e->getMessage() : 'Template rendering failed.',
                            ['status' => 500]
                        )
                    );
                }
            }
        }

        $result = apply_filters('tf/ajax/result', $result, $this->action, $this->request);
        $result = apply_filters('tf/ajax/result/action=' . $this->action, $result, $this->action, $this->request);

        do_action('tf/ajax/after', $this->action, $result, $this->request);
        do_action('tf/ajax/after/action=' . $this->action, $this->action, $result, $this->request);

        return rest_ensure_response($result);
    }

    public static function getURL() : string
    {
        return rest_url(
            sprintf(
                '%s/%s',
                self::REST_NAMESPACE,
                self::REST_ROUTE
            )
        );
    }

    public static function getPath() : string
    {
        return sprintf(
            '/%s/%s/%s',
            rest_get_url_prefix(),
            self::REST_NAMESPACE,
            self::REST_ROUTE
        );
    }

    protected function getTemplate() : string
    {
        $action_kebab_case = Str::kebab(Str::camel($this->action));

        // Default template path is always ajax/ followed by the action in kebab case.
        // If the action is "class based" this path will be considered the fallback path
        // for when the action class defines the "__template" method.
        $template_paths = collect(['ajax/' . $action_kebab_case]);

        // If the ajax action is "class based"
        // the class can have a public method named __template with the path to the template for that action.
        if (self::hasClassBasedHandler($this->action, $this->request)) {
            $template_path = $this->getActionClassInstance()->__template();

            $template_paths->push($template_path);
        }

        $template_paths = apply_filters('tf/ajax/template_paths', $template_paths, $this->action, $this->request);
        $template_paths = apply_filters(
            'tf/ajax/template_paths/action=' . $this->action,
            $template_paths,
            $this->action,
            $this->request
        );

        return locate_template($template_paths->filter()->toArray());
    }

    protected static function getActionClass(string $action): string
    {
        return '\App\AjaxHandler\\' . Str::studly($action);
    }

    protected static function getActionClassMethod(): string
    {
        return '__getData';
    }

    protected static function getAjaxClass(): string
    {
        return '\App\AjaxHandler\DefaultHandler';
    }

    protected static function getAjaxClassMethod(string $action): string
    {
        return Str::camel($action);
    }

    protected static function hasClassBasedHandler(string $action, WP_REST_Request $request): bool
    {

        $className = self::getActionClass($action);
        if (!class_exists($className)) {
            return false;
        }

        if (! is_callable([new $className($request), self::getActionClassMethod()])) {
            return false;
        }

        if (! is_subclass_of($className, AbstractAjaxHandler::class)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    sprintf(
                        '%s must extend class %s.',
                        $className,
                        AbstractAjaxHandler::class
                    )
                );
            }

            return false;
        }

        return true;
    }

    protected static function hasMethodBasedHandler(string $action): bool
    {
        return is_callable([self::getAjaxClass(), self::getAjaxClassMethod($action)]);
    }

    protected static function hasCallableHandler(string $action, WP_REST_Request $request): bool
    {

        if (self::hasClassBasedHandler($action, $request)) {
            return true;
        }

        if (self::hasMethodBasedHandler($action)) {
            return true;
        }

        return false;
    }

    protected function getCallabeHandler(): ?callable
    {

        if (self::hasClassBasedHandler($this->action, $this->request)) {
            return [$this->getActionClassInstance(), self::getActionClassMethod()];
        }

        if (self::hasMethodBasedHandler($this->action)) {
            return [self::getAjaxClass(), self::getAjaxClassMethod($this->action)];
        }

        return null;
    }

    /** @var array<string, AbstractAjaxHandler> */
    protected $actionClassInstances = [];

    protected function getActionClassInstance() : ?AbstractAjaxHandler
    {

        if (! self::hasClassBasedHandler($this->action, $this->request)) {
            return null;
        }

        if (! isset($this->actionClassInstances[$this->action])) {
            $class = self::getActionClass($this->action);
            $this->actionClassInstances[$this->action] = new $class($this->request);
        }

        return $this->actionClassInstances[$this->action];
    }

    protected function getHandlerData(string $action)
    {
        $handler = $this->getCallabeHandler();
        if ($handler === null) {
            return new WP_Error(
                'no_handler',
                'No handler found for this action.',
                ['status' => 500]
            );
        }

        // Send all extra arguments to getHandlerData() onwards to the actual handler.
        $args = func_get_args();
        $extra_arguments = collect($args)->slice(1)->all();

        return call_user_func_array($handler, $extra_arguments);
    }
}
