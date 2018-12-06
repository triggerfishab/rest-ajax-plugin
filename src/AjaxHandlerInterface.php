<?php
declare(strict_types=1);

namespace Triggerfish\Ajax;

interface AjaxHandlerInterface
{
    public function __template() : string;

    public function __getData();
}
