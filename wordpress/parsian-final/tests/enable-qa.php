<?php
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('QA bootstrap is available only under WP-CLI.');
}
if (!defined('WP_ENVIRONMENT_TYPE') || WP_ENVIRONMENT_TYPE !== 'staging') {
    throw new RuntimeException('QA bootstrap requires an isolated Staging environment.');
}
define('PARSIAN_ALLOW_FINANCE_TESTS', true);
