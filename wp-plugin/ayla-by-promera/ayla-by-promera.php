<?php
/**
 * Plugin Name:       Ayla by Promera
 * Description:       AI receptionist for clinics and businesses: replies on WhatsApp, books appointments and reminds patients.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Promera
 * Text Domain:       ayla-by-promera
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AYLA_VERSION', '1.0.1' );
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

Ayla_Relay::register();
Ayla_Admin::register();
Ayla_Web_Chat::register();
