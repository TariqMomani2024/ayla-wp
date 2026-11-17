<?php
/**
 * Plugin Name:       Ayla by Promyra Digital Solutions
 * Description:       Your AI assistant: answers website visitors and WhatsApp messages, learns from your site, books appointments and follows up with customers.
 * Version:           1.5.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Promyra Digital Solutions
 * Text Domain:       ayla-by-promyra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AYLA_VERSION', '1.5.2' );
define( 'AYLA_PLUGIN_FILE', __FILE__ );
define( 'AYLA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AYLA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once AYLA_PLUGIN_DIR . 'includes/config.php';
require_once AYLA_PLUGIN_DIR . 'includes/class-ayla-i18n.php';
require_once AYLA_PLUGIN_DIR . 'includes/class-ayla-secrets.php';
require_once AYLA_PLUGIN_DIR . 'includes/class-ayla-settings.php';
require_once AYLA_PLUGIN_DIR . 'includes/class-ayla-client.php';
require_once AYLA_PLUGIN_DIR . 'includes/class-ayla-relay.php';
require_once AYLA_PLUGIN_DIR . 'includes/class-ayla-admin.php';
require_once AYLA_PLUGIN_DIR . 'includes/class-ayla-web-chat.php';
require_once AYLA_PLUGIN_DIR . 'includes/class-ayla-site-sync.php';
require_once AYLA_PLUGIN_DIR . 'includes/class-ayla-bridge.php';

Ayla_Relay::register();
Ayla_Admin::register();
Ayla_Web_Chat::register();
Ayla_Site_Sync::register();
Ayla_Bridge::register();
