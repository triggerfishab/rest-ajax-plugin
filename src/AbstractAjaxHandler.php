<?php
declare(strict_types=1);

namespace Triggerfish\Ajax;

use WP_REST_Request;

abstract class AbstractAjaxHandler implements AjaxHandlerInterface
{
    protected $request;
    protected $params;

    final public function __construct(WP_REST_Request $request)
    {
        $this->request = $request;
        $this->params = $this->request->get_params();
    }

    // Always use / as directory separator, not . as in Blade templates.
    public function __template() : string
    {
        return '';
    }
}
