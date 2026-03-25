<?php
declare(strict_types=1);

namespace Triggerfish\REST_Ajax;

use WP_REST_Request;

/**
 * Handlers read merged REST params in {@see AbstractAjaxHandler::$params}.
 *
 * JSON bodies (application/json) expose fields via {@see WP_REST_Request::get_json_params()}.
 * Those are merged into query/form params so nested keys are real PHP arrays. Without that step,
 * a JSON object can surface as a stdClass, which breaks foreach on PHP 8+.
 */
abstract class AbstractAjaxHandler implements AjaxHandlerInterface
{
    protected $request;
    protected $params;

    final public function __construct(WP_REST_Request $request)
    {
        $this->request = $request;
        $params = $this->request->get_params();
        $jsonParams = $this->request->get_json_params();
        if (is_array($jsonParams) && $jsonParams !== []) {
            $params = array_replace($params, $jsonParams);
        }
        $this->params = self::normalizeNestedBodyParams($params);
    }

    /**
     * @param  array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected static function normalizeNestedBodyParams(array $params): array
    {
        foreach (['searchArgs', 'taxonomyFilters'] as $key) {
            if (! array_key_exists($key, $params)) {
                continue;
            }
            $value = $params[$key];
            if (is_array($value)) {
                continue;
            }
            if (is_object($value)) {
                $decoded = json_decode(wp_json_encode($value), true);
                $params[$key] = is_array($decoded) ? $decoded : [];
                continue;
            }
            $params[$key] = [];
        }

        return $params;
    }

    // Always use / as directory separator, not . as in Blade templates.
    public function __template() : string
    {
        return '';
    }
}
