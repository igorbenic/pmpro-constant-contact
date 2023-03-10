<?php

namespace PaidMembershipPro;

use PaidMembershipPro\EMS\EMS;

if ( ! class_exists( '\PaidMembershipPro\EMS\EMS' ) ) {
    require_once 'library/class-ems.php';
}

class Constant_Contact extends EMS {

    /**
     * API URL
     *
     * @var string
     */
    protected $api_url = 'https://api.cc.email/v3';

    /**
     * Has Tags to include tag settings.
     *
     * @var bool
     */
    protected $has_tags = true;

    /**
     * Doesn't have a double optin, so we hide settings.
     * @var bool
     */
    protected $has_double_optin = false;

    /**
     * @var null|Constant_Contact
     */
    protected static $instance = null;

    /**
     * @var bool
     */
    protected $bulk_update = true;

    /**
     * Returns the instance of the current class.
     *
     * @return Constant_Contact object
     * @since 2.0.0
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {

        $this->id = 'constant_contact';
        $this->page_title = __('Constant Contact', 'pmpro-contantcontact');
        $this->menu_title = __('Constant Contact', 'pmpro-contantcontact');
        $this->plugin_file = PMPRO_CC_FILE;

        $this->includes();

        parent::__construct();

        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        add_action(  'pmpro_' . $this->get_settings_id() . '_bulk_change_data', [ $this, 'maybe_add_custom_fields_for_bulk_update' ], 20, 2 );

        $this->get_api()->oauth_hooks();

        $this->fields['api_key']['title'] = __( 'Client ID', 'pmpro-constantcontact' );
    }

    /**
     * Description for the page
     *
     * @return void
     */
    public function settings_description() {
        ?>

        <p>This plugin will integrate your site with Constant Contact. You can choose one or more Constant Contact lists to have users subscribed to when they signup for your site.</p>
        <p>If you have <a href="http://www.paidmembershipspro.com">Paid Memberships Pro</a> installed, you can also choose one or more Constant Contact lists to have members subscribed to for each membership level.</p>
        <p>Don't have a Constant Contact account? <a href="http://www.constantcontact.com/index.jsp?pn=paidmembershipspro" target="_blank">Get one here</a>. It's free.</p>

        <?php
    }

    /**
     * Maybe add custom fields for bulk update.
     *
     * @param array    $bulk_data
     * @param \WP_User $user
     * @return mixed
     */
    public function maybe_add_custom_fields_for_bulk_update( $bulk_data, $user ) {
        $custom_fields = apply_filters("pmpro_constant_contact_custom_fields", array(), $user );

        if ( $custom_fields ) {
            $bulk_data['custom_fields'] = $custom_fields;
        }

        return $bulk_data;
    }

    /**
     * Return the Description for API section.
     *
     * @return string
     */
    public function get_api_section_description() {
        return __( 'Constant Contact API Settings can be found under your Constant Contact Application.', 'pmpro-constantcontact' ) . ' ' . sprintf( '<a href="https://developer.constantcontact.com/api_guide/apps_create.html" target="_blank">%s</a>', __( 'Click here for Instructions', 'pmpro-constantcontact' ) );
    }

    /**
     * Load the languages folder for translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain( 'pmpro-contantcontact', false, basename( dirname( PMPRO_CC_FILE ) ) . '/languages' );
    }

    /**
     * Return the API for AC
     * @return mixed|\PaidMembershipPro\EMS\API|null
     */
    public function get_api() {
        if ( null === $this->api ) {
            $this->api = new Constant_Contact_API( $this );
            $this->api->set_api_url( $this->api_url );
            $this->api->set_api_key( $this->get_api_key() );
        }

        return $this->api;
    }

    /**
     * Include files.
     * @return void
     */
    public function includes() {
        require_once 'class-constant-contact-api.php';
    }

    /**
     * Return Plugin links to be added.
     *
     * @param array $links Array of links.
     *
     * @return array|string[]
     */
    public function get_plugin_links() {
        return array(
            '<a href="' . get_admin_url( null, 'admin.php?page=pmpro_activecampaign_options' ) . '">' . __( 'Settings', 'pmpro-constantcontact' ) . '</a>',
        );
    }

    /**
     * Return Links to be added in meta row.
     *
     * @return array|string[]
     */
    public function get_row_meta_links() {
        return  array(
            '<a href="' . esc_url('https://www.paidmembershipspro.com/add-ons/pmpro-constant-contact/') . '" title="' . esc_attr( __( 'View Documentation', 'pmpro' ) ) . '">' . __( 'Docs', 'pmpro' ) . '</a>',
            '<a href="' . esc_url('https://www.constantcontact.com/index.jsp?pn=paidmembershipspro') . '" title="' . esc_attr( __( 'Constant Contact Signup', 'pmpro-constantcontact' ) ) . '">' . __( 'Constant Contact Signup', 'pmpro-constantcontact' ) . '</a>',
            '<a href="' . esc_url('https://www.paidmembershipspro.com/support/') . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro' ) ) . '">' . __( 'Support', 'pmpro' ) . '</a>',
        );
    }
}