<?php

namespace SCEventsManager\Setup;

final class Activator
{
    public const REDIRECT_TRANSIENT = 'scem_activation_redirect';

    public static function activate(): void
    {
        \set_transient(self::REDIRECT_TRANSIENT, true, 30);
    }

    public static function deactivate(): void
    {
        \flush_rewrite_rules();
    }
}
