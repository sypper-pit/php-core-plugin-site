<?php
declare(strict_types=1);

namespace Core;

if (!defined('APP_ROOT')) {
    http_response_code(403);
    exit;
}

abstract class Plugin
{
    protected Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    abstract public function register(): void;
}
