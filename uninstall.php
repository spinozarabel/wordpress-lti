<?php
/*
 *  wordpress-lti - WordPress module to add LTI support
 *  Copyright (C) 2021  Simon Booth, Stephen P Vickers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License along
 *  with this program; if not, write to the Free Software Foundation, Inc.,
 *  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 *  Contact: s.p.booth@stir.ac.uk
 */

/* -------------------------------------------------------------------
 * Fired when the plugin is uninstalled.
  ------------------------------------------------------------------ */

use ceLTIc\LTI\Tool;
use ceLTIc\LTI\DataConnector\DataConnector;

// if uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// include the Library functions & lib.php loads LTI library
require_once ('includes' . DIRECTORY_SEPARATOR . 'lib.php');

// check if data should be deleted on uninstall
$options = get_site_option('lti_choices');

if (!empty($options['uninstallblogs']) && ($options['uninstallblogs'] === '1')) {
    $tool = new Tool($lti_db_connector);
    $platforms = $tool->getPlatforms();
    foreach ($platforms as $platform) {
        lti_delete($platform->getKey());
    }
}

if (!empty($options['uninstalldb']) && ($options['uninstalldb'] === '1')) {
    // delete plugin options.
    delete_site_option('lti_choices');

    // delete LTI tables.
    $wpdb->query("DROP TABLE {$wpdb->prefix}" . DataConnector::RESOURCE_LINK_SHARE_KEY_TABLE_NAME);
    $wpdb->query("DROP TABLE {$wpdb->prefix}" . DataConnector::USER_RESULT_TABLE_NAME);
    $wpdb->query("DROP TABLE {$wpdb->prefix}" . DataConnector::RESOURCE_LINK_TABLE_NAME);
    $wpdb->query("DROP TABLE {$wpdb->prefix}" . DataConnector::CONTEXT_TABLE_NAME);
    $wpdb->query("DROP TABLE {$wpdb->prefix}" . DataConnector::ACCESS_TOKEN_TABLE_NAME);
    $wpdb->query("DROP TABLE {$wpdb->prefix}" . DataConnector::NONCE_TABLE_NAME);
    $wpdb->query("DROP TABLE {$wpdb->prefix}" . DataConnector::PLATFORM_TABLE_NAME);
}
