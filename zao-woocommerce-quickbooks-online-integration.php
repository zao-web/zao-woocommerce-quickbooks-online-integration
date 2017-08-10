<?php
/**
 * Plugin Name: Zao WooCommerce QuickBooks Online Integration
 * Plugin URI:  https://zao.is
 * Description: Integrates QuickBooks Online with WooCommerce
 * Version:     0.1.0
 * Author:      Zao
 * Author URI:  https://zao.is
 * Text Domain: zwqoi
 * Domain Path: /languages
 * License:     GPL-2.0+
 */

/**
 * Copyright (c) 2017 Zao (email : jt@zao.is)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Built using yo wp-make:plugin
 * Copyright (c) 2017 10up, LLC
 * https://github.com/10up/generator-wp-make
 */

// Useful global constants
define( 'ZWQOI_VERSION', '0.1.0' );
define( 'ZWQOI_URL',     plugin_dir_url( __FILE__ ) );
define( 'ZWQOI_PATH',    dirname( __FILE__ ) . '/' );
define( 'ZWQOI_INC',     ZWQOI_PATH . 'includes/' );

// Include files
require_once ZWQOI_INC . 'functions/core.php';


// Activation/Deactivation
register_activation_hook( __FILE__, '\Zao\WC_QBO_Integration\Core\activate' );
register_deactivation_hook( __FILE__, '\Zao\WC_QBO_Integration\Core\deactivate' );

// Bootstrap
Zao\WC_QBO_Integration\setup();
