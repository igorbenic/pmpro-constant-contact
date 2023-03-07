<?php

namespace PaidMembershipPro\EMS;

class Checkout {

    /**
     * EMS System
     * @var null|EMS
     */
    protected $ems = null;

    public function __construct( EMS $ems ) {
        $this->ems = $ems;

        add_action( 'pmpro_checkout_after_tos_fields', [ $this, 'show_optin_on_checkout' ] );
        add_action( 'pmpro_after_checkout', [ $this, 'process_after_checkout' ], 15 );
        add_action( 'pmpro_paypalexpress_session_vars', [ $this, 'paypalexpress_session_vars' ] );
    }

    /**
     * Sets session variables to preserve opt-in info when going off-site for payment w/offsite payment gateway (PayPal Express).
     *
     */
    public function paypalexpress_session_vars() {
        if ( isset( $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in_on_checkout'] ) ) {
            $_SESSION['_pmpro_' . $this->ems->get_settings_id() . '_opt_in_on_checkout'] = $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in_on_checkout'];
        }
        if ( isset( $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in'] ) ) {
            $_SESSION['_pmpro_' . $this->ems->get_settings_id() . '_opt_in'] = $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in'];
        }
    }

    /**
     * Subscribe to chosen lists
     *
     * @param $user_id
     * @return void
     */
    public function process_after_checkout( $user_id ) {
        if ( ! isset( $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in_on_checkout'] ) ) {
            return;
        }

        foreach ( $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in'] as $list_id ) {
            $this->ems->subscribe( $user_id, $list_id );
        }

        $optin_lists = $this->ems->get_option( 'optin' );
        if ( empty( $optin_lists ) ) {
            return;
        }

        // If we are showing some of those lists and user has unchecked them,
        // Make sure the user is unsubscribed.
        foreach ( $optin_lists as $index => $optin_list_id ) {
            if ( in_array( $optin_list_id, $_REQUEST['_pmpro_' . $this->ems->get_settings_id() . '_opt_in'] ) ) {
                continue;
            }

            $this->ems->unsubscribe( $user_id, $optin_list_id );
        }
    }

    /**
     * Show the opt-in checkbox on Membership Checkout.
     *
     */
    public function show_optin_on_checkout() {
        global $pmpro_review, $current_user;

        $display_modifier = empty( $pmpro_review ) ? '' : 'style="display: none;"';

        // Return if we don't have opt-in lists selected.
        $optin_lists = $this->ems->get_option( 'optin' );
        if ( empty( $optin_lists ) ) {
            return;
        }

        $subscribed_lists = [];

        if ( ! empty( $current_user ) ) {
            $subscribed_lists = $this->ems->get_subscribed_list_ids( $current_user->ID );
        }

        // Show a field at checkout.
        $optin_label = $this->ems->get_option( 'optin_label', __( 'Join our mailing lists. ', 'pmpro-constantcontact' ) );

        ?>
        <div id="pmpro_checkout_box-<?php echo esc_attr( $this->ems->get_settings_id() ); ?>-opt-in" class="pmpro_checkout" <?php echo( $display_modifier ); ?>>
            <hr />
            <input type="hidden" name="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ); ?>_opt_in_on_checkout" value="1" />
            <?php
            if ( count( $optin_lists ) > 1 ) { echo '<p><strong>' . $optin_label . '</strong></p>'; }
            ?>
            <div class="pmpro_checkout-fields">
                <?php
                if ( count( $optin_lists ) < 2 ) :
                    ?>
                    <div class="pmpro_checkout-field pmpro_checkout-field-checkbox pmpro_checkout-field-<?php echo esc_attr( $this->ems->get_settings_id() ); ?>-pmp-opt-in">
                        <input
                            type="checkbox"
                            id="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ); ?>_opt_in"
                            name="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ); ?>_opt_in[]"
                            value="<?php echo esc_attr( current( $optin_lists ) ) ?>"
                        />
                        <label
                            for="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ); ?>_opt_in">
                            <?php echo esc_html( $optin_label ); ?>
                        </label>
                    </div> <!-- end pmpro_checkout-field -->
                <?php
                else:
                    $lists = $this->ems->get_lists();
                    foreach ($optin_lists as $index => $list_id) {
                        $is_subscribed = in_array( $list_id, $subscribed_lists );

                        ?>
                        <div class="pmpro_checkout-field pmpro_checkout-field-checkbox pmpro_checkout-field-ac-pmp-opt-in">
                            <input
                                <?php checked( $is_subscribed ); ?>
                                type="checkbox"
                                id="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ); ?>_opt_in_<?php echo esc_attr( $index ); ?>"
                                name="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ); ?>_opt_in[]" value="<?php echo esc_attr( $list_id ); ?>"
                            />
                            <label
                                for="_pmpro_<?php echo esc_attr( $this->ems->get_settings_id() ); ?>_opt_in_<?php echo esc_attr( $index ); ?>">
                                <?php echo esc_html( $lists[ $list_id ] ); ?>
                            </label>
                        </div> <!-- end pmpro_checkout-field -->
                        <?php
                    }
                    ?>

                <?php endif; ?>
            </div> <!-- end pmpro_checkout-fields -->
        </div> <!-- end pmpro_checkout_box-name -->
        <?php
    }

}