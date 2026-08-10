<?php

// SPDX-FileCopyrightText: 2026 WPPilot <dev@wppilot.co>
// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit();
}

// The release packager replaces this value only in the staged WordPress.org
// artifact. Directory builds exclude every developer/file-execution source
// file as well as disabling their loaders at runtime.
if (!defined('WPPILOT_WORDPRESS_ORG_EDITION')) {
    define(constant_name: 'WPPILOT_WORDPRESS_ORG_EDITION', value: false);
}

function wppilot_is_wordpress_org_edition(): bool
{
    return constant('WPPILOT_WORDPRESS_ORG_EDITION') === true;
}
