<?php
declare(strict_types=1);

namespace Molipa\TmlShipping\Config;

final class ConfigPaths
{
    private function __construct() {}

    public const XML_ENABLED  = 'tmlshipping/general/enabled';
    public const CLIENT_ID     = 'tmlshipping/credentials/client_id';
    public const CLIENT_SECRET = 'tmlshipping/credentials/client_secret';
}
