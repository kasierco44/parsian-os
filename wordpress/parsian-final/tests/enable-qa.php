<?php
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('QA bootstrap is available only under WP-CLI.');
}
define('PARSIAN_ALLOW_FINANCE_TESTS', true);
