<?php

namespace PaidMembershipPro\EMS;

class Profile {

    /**
     * EMS System
     * @var null|EMS
     */
    protected $ems = null;

    public function __construct( EMS $ems ) {
        $this->ems = $ems;

        add_action( 'user_register', [ $this, 'user_register'], 20, 2 );
        add_action( 'personal_options_update', [ $this, 'update_profile' ], 10, 2 );
        add_action( 'edit_user_profile_update', [ $this, 'update_profile' ], 10, 2 );
        add_action( 'pmpro_show_user_profile', [ $this, 'show_optin_lists_on_profile'], 12 );
        add_action( 'pmpro_personal_options_update', [ $this, 'subscribe_user_from_profile_update'] );
    }

    /**
     * Register User
     *
     * @param $user_id
     * @return void
     */
    public function user_register( $user_id, $user_data ) {
        $list_ids  = $this->ems->get_option( 'non_member_list' );
        $bulk_data = array(
            'lists' => array(
                'add' => array(),
                'remove' => array()
            ),
            'tags' => array(
                'add' => array(),
                'remove' => array()
            ),
        );

        if ( $list_ids ) {
            if ( ! is_array( $list_ids ) ) {
                $list_ids = array( $list_ids );
            }

            if ( ! $this->ems->bulk_update_enabled() ) {
                foreach ($list_ids as $list_id) {
                    $this->ems->subscribe($user_id, $list_id);
                }
            }

            $bulk_data['lists']['add'] = $list_ids;
        }

        $tags_levels = $this->ems->get_option( 'tags_levels' );

        if ( isset( $tags_levels['non_member'] ) && $tags_levels['non_member'] ) {
            if ( ! is_array( $tags_levels['non_member'] ) ) {
                $tags_levels['non_member'] = array( $tags_levels['non_member'] );
            }
            if ( ! $this->ems->bulk_update_enabled() ) {
                foreach ($tags_levels['non_member'] as $tag_id) {
                    $this->ems->tag($user_id, $tag_id);
                }
            }

            $bulk_data['tags']['add'] = $tags_levels['non_member'];
        }

        $bulk_data = apply_filters( 'pmpro_' . $this->ems->get_settings_id() . '_bulk_data_on_user_register',
            $bulk_data,
            $user_id,
            $user_data
        );

        if ( $this->ems->bulk_update_enabled() ) {
            $this->ems->bulk_change( $user_id, $bulk_data );
        }
    }

    /**
     * Updates a subscriber's details upon updating a user profile in wp-admin
     *
     * @access public
     * @return void
     */
    public function update_profile( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        $user_id = isset( $_REQUEST['user_id'] ) ? intval( $_REQUEST['user_id'] ) : 0;

        if ( ! empty( $user_id ) ) {

            $user_email = isset( $_REQUEST['email'] ) ? sanitize_email( $_REQUEST['email'] ) : '';
            $first_name = isset( $_REQUEST['first_name'] ) ? sanitize_text_field( $_REQUEST['first_name'] ) : '';
            $last_name = isset( $_REQUEST['last_name'] ) ? sanitize_text_field( $_REQUEST['last_name'] ) : '';

            $subscriber_info = array(
                'email_address' => $user_email,
                'first_name'    => $first_name,
                'last_name'     => $last_name
            );

            /**
             * Filter the subscriber data to add custom fields
             *
             * @param array $subscriber_info The array containing the subscriber data
             */
            $subscriber_info = apply_filters( 'pmpro_' . $this->ems->get_settings_id() . '_subscriber_update_data', $subscriber_info );

            $this->ems->update_contact( $user_id, $subscriber_info );
        }
    }


    /**
     * Subscribe user from profile update.
     *
     * @param int $user_id User ID.
     * @return void
     */
    public function subscribe_user_from_profile_update( $user_id ) {
        if ( ! isset( $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in_on_profile'] ) ) {
            return;
        }

        $bulk_data = array(
            'lists' => array(
                'add' => array(),
                'remove' => array()
            )
        );

        $checked_lists = isset( $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in'] ) ? $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in'] : [];

        if ( $checked_lists ) {
            foreach ( $checked_lists as $list_id ) {
                if ( ! $this->ems->bulk_update_enabled() ) {
                    $this->ems->subscribe( $user_id, $list_id );
                }
                $bulk_data['lists']['add'] = $list_id;
            }
        }

        $optin_lists = $this->ems->get_option( 'optin' );

        if ( ! empty( $optin_lists ) ) {
            // If we are showing some of those lists and user has unchecked them,
            // Make sure the user is unsuscribed.
            foreach ( $optin_lists as $index => $optin_list_id ) {
                if ( in_array( $optin_list_id, $checked_lists ) ) {
                    continue;
                }

                if ( ! $this->ems->bulk_update_enabled() ) {
                    $this->ems->unsubscribe( $user_id, $optin_list_id );
                }
                $bulk_data['lists']['remove'] = $optin_list_id;
            }
        }

        $bulk_data = apply_filters( 'pmpro_' . $this->ems->get_settings_id() . '_bulk_data_on_profile_update',
            $bulk_data,
            $user_id
        );

        if ( $this->ems->bulk_update_enabled() ) {
            $this->ems->bulk_change( $user_id, $bulk_data );
        }
    }

    /**
     * Show Optin Lists on the front profile page
     *
     * @param $user
     * @return void
     */
    public function show_optin_lists_on_profile( $user ) {
        // Return if we don't have opt-in lists selected.
        $optin_lists = $this->ems->get_option( 'optin' );

        if ( empty( $optin_lists ) ) {
            return;
        }

        $subscribed_lists = [];

        if ( ! empty( $user ) ) {
            $subscribed_lists = $this->ems->get_subscribed_list_ids( $user->ID );
        }

        // Show a field at checkout.
        $optin_label = $this->ems->get_option( 'optin_label', __( 'Join our mailing lists.', 'pmpro-constantcontact' ) );

        ?>
        <div class="pmpro_member_profile_edit-field ">
            <hr />
            <input type="hidden" name="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ) ?>_opt_in_on_profile" value="1" />
            <?php
            if ( count( $optin_lists ) > 1 ) { echo '<p><strong>' . esc_html( $optin_label ) . '</strong></p>'; }
            ?>
            <div class="pmpro_checkout-fields">
                <?php
                if ( count( $optin_lists ) < 2 ) :
                    $is_subscribed = in_array( current( $optin_lists ), $subscribed_lists );

                    ?>
                    <div class="pmpro_checkout-field pmpro_checkout-field-checkbox pmpro_checkout-field-<?php echo esc_attr( $this->ems->get_settings_id() ) ?>-pmp-opt-in">
                        <input <?php checked( $is_subscribed ); ?> type="checkbox" id="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ) ?>_opt_in" name="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ) ?>_opt_in[]" value="<?php echo esc_attr( current( $optin_lists ) ) ?>" />
                        <label for="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ) ?>_opt_in"><?php echo esc_html( $optin_label ); ?></label>
                    </div> <!-- end pmpro_checkout-field -->
                <?php
                else:
                    $lists = $this->ems->get_lists();

                    foreach ($optin_lists as $index => $list_id) {
                        $is_subscribed = in_array( $list_id, $subscribed_lists );

                        ?>
                        <div class="pmpro_checkout-field pmpro_checkout-field-checkbox pmpro_checkout-field-<?php echo esc_attr( $this->ems->get_settings_id() ) ?>-pmp-opt-in">
                            <input <?php checked( $is_subscribed ); ?> type="checkbox" id="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ) ?>_opt_in_<?php echo esc_attr( $index ); ?>" name="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ) ?>_opt_in[]" value="<?php echo esc_attr( $list_id ); ?>" />
                            <label for="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ) ?>_opt_in_<?php echo esc_attr( $index ); ?>"><?php echo esc_html( $lists[ $list_id ] ); ?></label>
                        </div> <!-- end pmpro_checkout-field -->
                        <?php
                    }
                    ?>

                <?php endif; ?>
            </div> <!-- end pmpro_checkout-fields -->
            <hr />
        </div> <!-- end pmpro_checkout_box-name -->
        <?php
    }
}