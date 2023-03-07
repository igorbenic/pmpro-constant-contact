<?php

namespace PaidMembershipPro\EMS;

abstract class API {

    protected $api_url = '';

    protected $api_key = null;

    protected $api_secret = null;

    protected $exchange_token_url = '';

    protected $authorize_url = '';

    /** @var EMS|null  */
    protected $ems = null;

    public function __construct(EMS $ems) {
        $this->ems = $ems;
    }

    public function has_oauth() {
        return $this->authorize_url && $this->exchange_token_url;
    }

    public function oauth_hooks() {
        if ( ! $this->has_oauth() ) {
            return;
        }

        add_action( 'init', [ $this, 'maybe_get_access_token'] );
        add_action( 'pmpro_' . $this->ems->get_settings_id() . '_refresh_oauth_token', [ $this, 'refresh_oauth_token' ] );
    }

    public function refresh_oauth_token() {
        $resp = wp_remote_post(
            $this->exchange_token_url,
            [
                'headers' => $this->get_exchange_token_authorization_header(),
                'body' =>  [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $this->get_oauth_value( 'refresh_token' )
                ]
            ]
        );

        $this->save_and_schedule_oauth_token( $resp );
    }

    protected function save_and_schedule_oauth_token( $response ) {
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        update_option( 'pmpro_' . $this->ems->get_settings_id() . '_oauth', $body );

        if ( $code < 300 ) {
            // Make sure only one is triggered.
            wp_unschedule_hook( 'pmpro_' . $this->ems->get_settings_id() . '_refresh_oauth_token' );
            wp_schedule_single_event( time() + ( absint( $body['expires_in'] ) - HOUR_IN_SECONDS ), 'pmpro_' . $this->ems->get_settings_id() . '_refresh_oauth_token' );
        }
    }

    /**
     * Check Connection
     *
     * @return array|\WP_Error
     */
    public function check_connection() {
        if ( ! $this->api_url ) {
            return new \WP_Error( 'no-api-url', __( 'Missing API URL. Please enter it.', 'pmpro-constantcontact' ) );
        }

        if ( ! $this->get_api_key() ) {
            return new \WP_Error( 'no-api-key', __( 'Missing Client ID. Please enter it.', 'pmpro-constantcontact' ) );
        }

        if ( $this->has_oauth() && ! $this->get_api_secret() ) {
            return new \WP_Error( 'no-api-key', __( 'Missing Client Secret. Please enter it.', 'pmpro-constantcontact' ) );
        }

        return $this->get_lists();
    }

    /**
     * Get Oauth Value
     *
     * @param $key
     * @return mixed|string
     */
    public function get_oauth_value( $key ) {
        $oauth = get_option( 'pmpro_' . $this->ems->get_settings_id() . '_oauth' );
        return ! empty( $oauth[ $key ] ) ? $oauth[ $key ] : '';
    }

    /**
     * Get Access Token.
     *
     * @return mixed|string
     */
    public function get_access_token() {
        $oauth = get_option( 'pmpro_' . $this->ems->get_settings_id() . '_oauth' );
        return ! empty( $oauth['access_token'] ) ? $oauth['access_token'] : '';
    }

    /**
     * Get API Secret.
     *
     * @return mixed|string
     */
    public function get_api_secret() {
        if ( null === $this->api_secret ) {
            $this->api_secret = $this->ems->get_option('api_secret');
        }

        return $this->api_secret;
    }

    /**
     * Get API Key.
     *
     * @return mixed|string
     */
    public function get_api_key() {
        if ( null === $this->api_key ) {
            $this->api_key = $this->ems->get_option('api_key');
        }

        return $this->api_key;
    }

    /**
     * Get Authorize params.
     *
     * @return array
     */
    public function get_authorize_params() {
        return [
            'client_id' => $this->api_key,
            'redirect_uri' => home_url(),
            'response_type' => 'code',
            'state' => $this->generate_state(),
        ];
    }

    /**
     * Get Authorize URL.
     *
     * @return false|string
     */
    public function get_authorize_url() {
        if ( ! $this->authorize_url ) {
            return false;
        }

        $url = add_query_arg( $this->get_authorize_params(), $this->authorize_url);

        return $url;
    }

    /**
     * Generate State.
     *
     * @return string
     */
    public function generate_state() {
        return  substr( md5( 'pmpro_' . $this->ems->get_settings_id() . '_' . wp_get_current_user()->user_email ), 0, 10 );
    }

    /**
     * Verify State.
     *
     * @param string $state State returned from service.
     *
     * @return bool
     */
    protected function verify_state( $state ) {
        $expected_state = $this->generate_state();

        return $state === $expected_state;
    }

    /**
     * Get Exchange Token Header.
     *
     * @return string[]
     */
    public function get_exchange_token_authorization_header() {
        return [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic ' . base64_encode( $this->get_api_key() . ':' . $this->get_api_secret() )
        ];
    }

    /**
     * Get Exchange Token Body.
     *
     * @return array
     */
    public function get_exchange_token_authorization_body() {
        return [
            'code' => sanitize_text_field( $_GET['code'] ),
            'redirect_uri' => home_url(),
            'grant_type' => 'authorization_code'
        ];
    }

    /**
     * Check maybe if we should exchange to get Access Token.
     *
     * @return void
     */
    public function maybe_get_access_token() {
        if ( ! $this->exchange_token_url ) {
            return;
        }

        if ( empty( $_GET['code'] ) ) {
            return;
        }

        if ( empty( $_GET['state'] ) ) {
            return;
        }

        if ( ! $this->verify_state( $_GET['state'] ) ) {
            return;
        }

        $resp = wp_remote_post(
            $this->exchange_token_url,
            [
                'headers' => $this->get_exchange_token_authorization_header(),
                'body' =>  $this->get_exchange_token_authorization_body()
            ]
        );

        $this->save_and_schedule_oauth_token( $resp );

        wp_redirect( admin_url('admin.php?page=pmpro_constant_contact_options' ) );
        exit;
    }

    public function set_api_url( $api_url ) {
        $this->api_url = $api_url;
    }

    public function set_api_key( $api_key ) {
        $this->api_key = $api_key;
    }

    /**
     * Get API URL
     * @return mixed|string
     */
    public function get_api_url_for_request( $resource ) {
        return trailingslashit( $this->api_url ) . $resource;
    }

    protected function get_default_headers() {
        return [];
    }

    protected function prepare_headers( $headers = [] ) {
        $default = $this->get_default_headers();

        return wp_parse_args( $headers, $default );
    }

    protected function prepare_body( $body ) {
        return $body;
    }

    public function bulk_update( $contact_id, $bulk_data ) {
        return true;
    }

    public function delete( $resource, $headers = [] ) {
        $response = wp_remote_request(
            $this->get_api_url_for_request( $resource ),
            [
                'headers' => $this->prepare_headers( $headers ),
                'method'  => 'DELETE'
            ]
        );

        return $this->prepare_response( $response );
    }

    public function post( $resource, $body = [], $headers = [] ) {
        $response = wp_remote_post(
            $this->get_api_url_for_request( $resource ),
            [
                'headers' => $this->prepare_headers( $headers ),
                'body'    => $this->prepare_body( $body )
            ]
        );

        return $this->prepare_response( $response );
    }

    public function put( $resource, $body = [], $headers = [] ) {
        $response = wp_remote_request(
            $this->get_api_url_for_request( $resource ),
            [
                'headers' => $this->prepare_headers( $headers ),
                'body'    => $this->prepare_body( $body ),
                'method'  => 'PUT'
            ]
        );

        return $this->prepare_response( $response );
    }

    protected function prepare_response( $response ) {
        return $response;
    }

    /**
     * API
     *
     * @param $resource
     * @return array|mixed|\WP_Error
     */
    public function get( $resource ) {
        $response = wp_remote_get(
            $this->get_api_url_for_request( $resource ),
            [
                'headers' => $this->prepare_headers()
            ]
        );

        return $this->prepare_response( $response );
    }

    public function get_lists() {
        return [];
    }

    public function get_tags() {
        return [];
    }

    /**
     * Create a Contact.
     *
     * @param \WP_User $user User object.
     *
     * @return mixed Contact ID from the API.
     */
    public function create_contact( $user ) {
        return 0;
    }

    /**
     * Create a Contact.
     *
     * @param mixed $contact_id Contact ID.
     * @param mixed $body Contact Body
     *
     * @return mixed Contact ID from the API.
     */
    public function update_contact( $contact_id, $body ) {
        return 0;
    }

    public function get_lists_from_contact( $contact_id ) {
        return [];
    }

    public function subscribe( $contact_id, $list_id ) {
        return true;
    }

    public function unsubscribe( $contact_id, $list_id ) {
        return true;
    }

    public function tag( $contact_id, $tag_id ) {
        return true;
    }

    public function untag( $contact_id, $tag_id ) {
        return true;
    }
}