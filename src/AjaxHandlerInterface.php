<?php
declare(strict_types=1);

namespace Triggerfish\REST_Ajax;

interface AjaxHandlerInterface
{
    public function __template() : string;

    public function __getData();
}
