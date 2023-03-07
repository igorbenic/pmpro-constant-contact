<?php
/*
Plugin Name: Paid Memberships Pro - Constant Contact Add On
Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-constant-contact/
Description: Sync your WordPress users and members with Constant Contact lists.
Version: 1.0.3
Author: Paid Memberships Pro
Author URI: https://www.paidmembershipspro.com
*/
/*
	Copyright 2011	Stranger Studios	(email : jason@strangerstudios.com)
	GPLv2 Full license details in license.txt
*/


define( 'PMPRO_CC_PATH', plugin_dir_path( __FILE__ ) );
define( 'PMPRO_CC_URL', plugin_dir_url( __FILE__ ) );
define( 'PMPRO_CC_FILE', __FILE__ );

require_once 'classes/class-constant-contact.php';

function pmpro_constant_contact() {
    return \PaidMembershipPro\Constant_Contact::get_instance();
}

pmpro_constant_contact();