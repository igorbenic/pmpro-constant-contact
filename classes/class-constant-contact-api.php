<?php

namespace PaidMembershipPro;

if ( ! class_exists( '\PaidMembershipPro\EMS\API' ) ) {
    require_once 'library/abstract/class-api.php';
}

use PaidMembershipPro\EMS\API;

class Constant_Contact_API extends API {

    protected $exchange_token_url = 'https://authz.constantcontact.com/oauth2/default/v1/token';

    protected $authorize_url = 'https://authz.constantcontact.com/oauth2/default/v1/authorize';

    protected function get_default_headers() {
        return [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->get_access_token()
        ];
    }

    public function get_authorize_params() {
        $args = parent::get_authorize_params();

        $args['scope'] = 'contact_data offline_access';

        return $args;
    }

    /**
     * Errors related to HTTP codes.
     *
     * @param integer $code
     * @return mixed|null Error message or null if it's not found.
     */
    protected function get_error_message_from_code( $code ) {
        $errors = [
            400 => __( 'Bad request. Either the JSON was malformed or there was a data validation error.', 'pmpro-contantcontact'),
            401 => __( 'The Access Token used is invalid.', 'pmpro-contantcontact'),
            403 => __( 'Forbidden request. You lack the necessary scopes, you lack the necessary user privileges, or the application is deactivated.', 'pmpro-contantcontact' ),
            404 => __( 'The requested resource was not found.', 'pmpro-contantcontact' ),
            500 => __( 'There was a problem with our internal service.', 'pmpro-contantcontact' ),
            503 => __( 'Our internal service is temporarily unavailable.', 'pmpro-contantcontact' )
        ];

        return isset( $errors[ $code ] ) ? $errors[ $code ] : null;
    }

    /**
     * Prepare Body to work with application/json content type.
     *
     * @param $body
     * @return false|mixed|string
     */
    protected function prepare_body( $body ) {
        if ( is_array( $body ) ) {
            $body = json_encode( $body );
        }

        return $body;
    }

    /**
     * Prepare Response from API
     *
     * @param array $response Response.
     * @return mixed|\WP_Error
     */
    protected function prepare_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 <= $code && $code < 300 ) {
            return $body;
        }

        if ( null !== $this->get_error_message_from_code( $code ) ) {
            return new \WP_Error( 'cc-error-' . wp_remote_retrieve_response_code( $response ), $this->get_error_message_from_code( $code ) );
        }

        if ( isset( $body['message'] ) ) {
            return new \WP_Error( 'cc-error-' . wp_remote_retrieve_response_code( $response ), $body['message'] );
        }

        if ( isset( $body['errors'] ) ) {
            return new \WP_Error( 'cc-error-' . wp_remote_retrieve_response_code( $response ), $body['errors'][0]['detail'] );
        }

        return new \WP_Error( 'cc-error-' . wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_response_message( $response ) );
    }

    public function get_lists() {
        $lists = $this->get('contact_lists');

        if ( is_wp_error( $lists ) ) {
            return $lists;
        }

        $lists_array = wp_list_pluck( $lists['lists'], 'name', 'list_id' );

        return $lists_array;
    }

    public function get_tags() {
        $tags = $this->get('contact_tags');

        if ( is_wp_error( $tags ) ) {
            return $tags;
        }

        $tags_array = wp_list_pluck( $tags['tags'], 'name', 'tag_id' );

        return $tags_array;
    }

    /**
     * Get all Custom Fields.
     *
     * @return array|mixed|\WP_Error
     */
    public function get_custom_fields() {
        $fields = $this->get('contact_custom_fields ');

        if ( is_wp_error( $fields ) ) {
            return $fields;
        }

        return $fields['custom_fields'];
    }

    /**
     * Create a Contact
     * @param \WP_User $user user object.
     *
     * @return int|mixed|void
     */
    public function create_contact( $user ) {
        $resp = $this->find_contact_by_email( $user->user_email );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        if ( $resp ) {
           return $resp['contact_id'];
        }

        $contact_data = [
            'email_address' => [
              'address' => $user->user_email
            ],
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'create_source' => 'Account'
        ];

        $contact_data = apply_filters(
            'pmpro_' . $this->ems->get_settings_id() . '_contact_data',
            $contact_data,
            $user
        );

        $resp = $this->post('contacts', $contact_data );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        return $resp['contact_id'];
    }

    /**
     * Update Contact.
     *
     * @param $contact_id
     * @param $body
     * @return array|int|mixed|void|\WP_Error
     */
    public function update_contact( $contact_id, $body ) {
        $contact = $this->get( 'contacts/' . $contact_id );

        if ( is_wp_error( $contact ) ) {
            return $contact;
        }

        // Making sure we're updating the contact correctly.
        if ( isset( $body['email_address'] ) && ! is_array( $body['email_address'] ) ) {
            $contact['email_address']['address'] = $body['email_address'];
            unset( $body['email_address'] );
        }

        $contact = wp_parse_args( $body, $contact );

        // No need to update contact ID.
        unset( $contact['contact_id'] );

        $contact['update_source'] = 'Account';

        $resp = $this->put( 'contacts/' . $contact_id, $contact );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        return $contact_id;
    }

    /**
     * Find a Contact by Email
     *
     * @param $email
     * @return array|false|mixed|\WP_Error
     */
    public function find_contact_by_email( $email ) {
        $response = $this->get( 'contacts?email=' . $email );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return current( $response['contacts'] );
    }

    /**
     * Get List IDs from Contact
     *
     * @param mixed $contact_id Contact ID.
     * @return array|mixed|\WP_Error
     */
    public function get_lists_from_contact( $contact_id ) {
        $contact = $this->get( 'contacts/' . $contact_id . '?include=list_memberships' );

        if ( is_wp_error( $contact ) ) {
            return $contact;
        }

        if ( ! isset( $contact['list_memberships'] ) ) {
            return [];
        }

        return $contact['list_memberships'];
    }

    public function create_custom_field( $label ) {
        $resp = $this->post( 'contact_custom_fields', [
            'label' => $label,
            'type'  => 'string'
        ]);

        return $resp;
    }

    /**
     * Subscribe to a List
     *
     * @param $contact_id
     * @param $list_id
     * @return array|mixed|true|void|\WP_Error
     */
    public function subscribe( $contact_id, $list_id ) {
        $contact = $this->get( 'contacts/' . $contact_id . '?include=list_memberships' );

        if ( is_wp_error( $contact ) ) {
            return $contact;
        }

        $lists = isset( $contact['list_memberships'] ) ? $contact['list_memberships'] : [];
        $lists[] = $list_id;

        $contact['list_memberships'] = $lists;

        return $this->update_contact( $contact_id, $contact );
    }

    public function unsubscribe( $contact_id, $list_id ) {
        $contact = $this->get( 'contacts/' . $contact_id . '?include=list_memberships' );

        if ( is_wp_error( $contact ) ) {
            return $contact;
        }

        $lists = isset( $contact['list_memberships'] ) ? $contact['list_memberships'] : [];
        $index = array_search( $list_id, $lists );
        if ( $index >= 0 ) {
            unset( $lists[ $index ] );
        }

        $contact['list_memberships'] = $lists;

        return $this->update_contact( $contact_id, $contact );
    }

    public function tag( $contact_id, $tag_id ) {
        $contact = $this->get( 'contacts/' . $contact_id . '?include=taggings' );

        if ( is_wp_error( $contact ) ) {
            return $contact;
        }

        $tags   = isset( $contact['taggings'] ) ? $contact['taggings'] : [];
        $tags[] = $tag_id;

        $contact['taggings'] = $tags;

        return $this->update_contact( $contact_id, $contact );
    }

    public function untag( $contact_id, $tag_id ) {
        $contact = $this->get( 'contacts/' . $contact_id . '?include=taggings' );

        if ( is_wp_error( $contact ) ) {
            return $contact;
        }

        $tags   = isset( $contact['taggings'] ) ? $contact['taggings'] : [];
        $index = array_search( $tag_id, $tags );
        if ( $index >= 0 ) {
            unset( $tags[ $index ] );
        }

        $contact['taggings'] = $tags;

        return $this->update_contact( $contact_id, $contact );
    }

    protected function add_data_to_contact( $contact, $values, $key ) {

        $contact_data = isset( $contact[ $key ] ) ? $contact[ $key ] : [];
        $contact_data = array_unique( array_merge( $values, $contact_data ) );

        $contact[ $key ] = $contact_data;

        return $contact;
    }

    protected function remove_data_from_contact( $contact, $values, $key ) {

        $contact_data = isset( $contact[ $key ] ) ? $contact[ $key ] : [];

        if ( ! is_array( $values ) ) {
            $values = [ $values ];
        }

        foreach ( $values as $value ) {
            $index = array_search( $value, $contact_data );
            if ( false !== $index && $index >= 0 ) {
                unset( $contact_data[ $index ] );
            }
        }

        $contact[ $key ] = array_values( $contact_data );

        return $contact;
    }

    protected function add_or_change_custom_fields_to_contact( $contact, $custom_fields ) {
        $existing_contact_fields       = ! empty( $contact['custom_fields'] ) ? $contact['custom_fields'] : [];
        $existing_account_fields       = $this->get_custom_fields();
        $existing_account_field_labels = wp_list_pluck(
            $existing_account_fields,
            'label',
            'custom_field_id'
        );
        $existing_account_field_names= wp_list_pluck(
            $existing_account_fields,
            'name',
            'custom_field_id'
        );

        $fields_to_add = [];
        foreach ( $custom_fields as $custom_field ) {
            $name           = $custom_field['name'];
            $exist_as_label = array_search( $name, $existing_account_field_labels );

            if ( $exist_as_label ) {
                $fields_to_add[] = [
                    'custom_field_id' => $exist_as_label,
                    'value'           => $custom_field['value']
                ];
                continue;
            }

            $exist_as_name = array_search( $name, $existing_account_field_names );

            if ( $exist_as_name ) {
                $fields_to_add[] = [
                    'custom_field_id' => $exist_as_name,
                    'value'           => $custom_field['value']
                ];
                continue;
            }

            // Does not exist as a field, need to create one first.
            $new_custom_field = $this->create_custom_field( $custom_field['name'] );
            if ( is_wp_error( $new_custom_field ) ) {
                // Something went wrong, don't add this field.
                continue;
            }

            if ( empty( $new_custom_field['custom_field_id'] ) ) {
                // Something went wrong, don't add this field.
                continue;
            }

            $fields_to_add[] = [
                'custom_field_id' => $new_custom_field['custom_field_id'],
                'value'           => $custom_field['value']
            ];
        }

        if ( $existing_contact_fields ) {
            $fields_to_add_ids = $fields_to_add ? wp_list_pluck(
                $fields_to_add,
                'custom_field_id'
            ) : [];

            foreach ( $existing_contact_fields as $existing_custom_field ) {
                // Such existing field was found above and has a new value.
                // No need to add it.
                if ( in_array( $existing_custom_field['custom_field_id'], $fields_to_add_ids ) ) {
                    continue;
                }

                $fields_to_add[] = $existing_custom_field;
            }
        }

        if ( $fields_to_add ) {
            $contact['custom_fields'] = $fields_to_add;
        }

        return $contact;
    }

    /**
     * Bulk Update
     *
     * @param $contact_id
     * @param $bulk_data
     * @return array|int|mixed|true|\WP_Error|null
     */
    public function bulk_update( $contact_id, $bulk_data ) {
        if ( is_wp_error( $contact_id ) ) {
            return $contact_id;
        }

        $include = apply_filters(
            'pmpro_' . $this->ems->get_settings_id() . '_include_data',
            [
                'list_memberships',
                'taggings'
            ],
            $bulk_data,
            $contact_id
        );

        // We have fields to update.
        if ( isset( $bulk_data['custom_fields'] ) ) {
            $include[] = 'custom_fields';
        }

        $contact = $this->get( 'contacts/' . $contact_id . '?include=' . implode( ',', $include ) );

        if ( is_wp_error( $contact ) ) {
            return $contact;
        }

        if ( isset( $bulk_data['lists'] ) ) {
            if ( ! empty( $bulk_data['lists']['add'] ) ) {
                $contact = $this->add_data_to_contact( $contact, $bulk_data['lists']['add'], 'list_memberships' );
            }
            if ( ! empty( $bulk_data['lists']['remove'] ) ) {
                $contact = $this->remove_data_from_contact( $contact, $bulk_data['lists']['remove'], 'list_memberships' );
            }
        }

        if ( isset( $bulk_data['tags'] ) ) {
            if ( ! empty( $bulk_data['tags']['add'] ) ) {
                $contact = $this->add_data_to_contact( $contact, $bulk_data['tags']['add'], 'taggings' );
            }
            if ( ! empty( $bulk_data['tags']['remove'] ) ) {
                $contact = $this->remove_data_from_contact( $contact, $bulk_data['tags']['remove'], 'taggings' );
            }
        }

        if ( isset( $bulk_data['custom_fields'] ) ) {
            $contact = $this->add_or_change_custom_fields_to_contact( $contact, $bulk_data['custom_fields'] );
        }

        return $this->update_contact( $contact_id, $contact );
    }
}