<?php
declare(strict_types=1);

namespace Triggerfish\REST_Ajax;

use WP_REST_Server;
use WP_REST_Request;
use WP_Error;
use Illuminate\Support\Collection;
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
 *    This is what is called "class based" action below.
 *
 * 2. If a class cannot be found by 1, the fallback will be searched for.
 *    The fallback is a public static method named like the action in camelCase in App\AjaxHandler\DefaultHandler.
 *    This is what is called "method based" action below.
 *
 * 3. Automatic templating.
 *    If the data from 1 or 2 is an array, a template named like the action in kebab-case will be searched for
 *    in a directory called "ajax" in the views directory.
 *
 *    But if the action is "class based", the class can define a public method named "__template"
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

    public static function registerRESTRoute()
    {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods' => [ WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ],
                'callback' => function (WP_REST_Request $request) {
                    $instance = new self($request);

                    return $instance->handleRequest();
                },
                'args' => [
                    'action' => [
                        'type' => 'string',
                        'required' => true,
                        'validate_callback' => function ($action) {
                            return self::hasCallableHandler($action);
                        },
                    ],
                ],
            ]
        );
    }

    protected function __construct(WP_REST_Request $request)
    {
        $this->request = $request;
        $this->action = $this->request->get_param('action');
    }

    protected function handleRequest()
    {
        add_filter('wp_doing_ajax', '__return_true');

        // Fix to set the current language in Polylang.
        if (function_exists('PLL') && class_exists('PLL_Choose_Lang_Url')) {
            $choose_lang = new \PLL_Choose_Lang_Url(PLL());
            $lang = $choose_lang->get_preferred_language();

            if (pll_current_language() != $lang) {
                PLL()->curlang = $lang;
                $GLOBALS['text_direction'] = PLL()->curlang->is_rtl ? 'rtl' : 'ltr';
            }
        }

        do_action('tf/ajax/before', $this->action, $this->request);
        do_action('tf/ajax/before/action=' . $this->action, $this->action);

        $result = $this->getHandlerData($this->action, $this->request->get_params());

        if (is_array($result) || (is_object($result) && $result instanceof ArrayAccess)) {
            $template = $this->getTemplate();

            if (! empty($template)) {
                $result = template($template, $result);
            }
        }

        $result = apply_filters('tf/ajax/result', $result, $this->action, $this->request);
        $result = apply_filters('tf/ajax/result/action=' . $this->action, $result, $this->action, $this->request);

        do_action('tf/ajax/after', $this->action, $result, $this->request);
        do_action('tf/ajax/after/action=' . $this->action, $this->action, $result, $this->request);

        return $result;
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
        $action_kebab_case = kebab_case(camel_case($this->action));

        // Default template path is always ajax/ followed by the action in kebab case.
        // If the action is "class based" this path will be considered the fallback path
        // for when the action class defines the "__template" method.
        $template_paths = collect(['ajax/' . $action_kebab_case]);

        // If the ajax action is "class based"
        // the class can have a public method named __template with the path to the template for that action.
        if (self::hasClassBasedHandler($this->action)) {
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

        return locate_template($template_paths->filter());
    }

    protected static function getActionClass(string $action): string
    {
        return '\App\AjaxHandler\\' . studly_case($action);
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
        return camel_case($action);
    }

    protected static function hasClassBasedHandler(string $action): bool
    {
        if (! is_callable([self::getActionClass($action), self::getActionClassMethod()])) {
            return false;
        }

        if (! is_subclass_of(self::getActionClass($action), 'Triggerfish\REST_Ajax\AbstractAjaxHandler')) {
            trigger_error(
                sprintf(
                    '%s must extend class Triggerfish\REST_Ajax\AbstractAjaxHandler.',
                    self::getActionClass($action)
                ),
                E_USER_ERROR
            );
        }

        return true;
    }

    protected static function hasMethodBasedHandler(string $action): bool
    {
        return is_callable([self::getAjaxClass(), self::getAjaxClassMethod($action)]);
    }

    protected static function hasCallableHandler(string $action): bool
    {
        if (self::hasClassBasedHandler($action)) {
            return true;
        }

        if (self::hasMethodBasedHandler($action)) {
            return true;
        }

        return false;
    }

    protected function getCallabeHandler(): ?callable
    {
        if (self::hasClassBasedHandler($this->action)) {
            return [$this->getActionClassInstance(), self::getActionClassMethod()];
        }

        if (self::hasMethodBasedHandler($this->action)) {
            return [self::getAjaxClass(), self::getAjaxClassMethod($this->action)];
        }

        return null;
    }

    protected function getActionClassInstance() : ?AbstractAjaxHandler
    {
        if (! self::hasClassBasedHandler($this->action)) {
            return null;
        }

        static $instance;

        if (is_null($instance)) {
            $class = self::getActionClass($this->action);
            $instance = new $class($this->request);
        }

        return $instance;
    }

    protected function getHandlerData(string $action)
    {
        // Send all extra arguments to getHandlerData() onwards to the actual handler.
        $args = func_get_args();
        $extra_arguments = collect($args)->slice(1)->all();

        return call_user_func_array(
            $this->getCallabeHandler(),
            $extra_arguments
        );
    }
}
