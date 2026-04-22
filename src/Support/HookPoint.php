<?php

declare(strict_types=1);

namespace Lumen\JsonRpc\Support;

enum HookPoint: string
{
    case BEFORE_REQUEST = 'before.request';
    case AFTER_REQUEST = 'after.request';
    case BEFORE_HANDLER = 'before.handler';
    case AFTER_HANDLER = 'after.handler';
    case ON_ERROR = 'on.error';
    case ON_AUTH_SUCCESS = 'on.auth.success';
    case ON_AUTH_FAILURE = 'on.auth.failure';
    case ON_RESPONSE = 'on.response';
}
