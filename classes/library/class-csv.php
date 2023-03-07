<?php

namespace PaidMembershipPro\EMS;

/**
 * ActiveCampaign CSV
 */
class CSV {

    /** @var EMS|null  */
    protected $ems = null;

    /**
     * Constructor.
     */
    public function __construct( EMS $ems ) {
        $this->ems = $ems;

        add_action( 'pmpro_' . $this->ems->get_settings_id() . '_settings_before_options', [ $this, 'download_csv_button' ] );
        add_action( 'wp_ajax_pmpro_ems_' . $this->ems->get_settings_id() . '_download_csv', [ $this, 'get_all_users_csv' ] );
        add_action( 'wp_ajax_pmpro_ems_' . $this->ems->get_settings_id() . '_download_non_synced_csv', [ $this, 'get_non_synced_users_csv' ] );
    }

    /**
     * Get all User's data for CSV that aren't synced in AC.
     *
     * @return void
     */
    public function get_non_synced_users_csv() {
        set_time_limit( 0 );

        $users = $this->get_users( true );

        $this->get_users_csv( $users );
    }

    /**
     * Get all the User's data for CSV
     *
     * @return void
     */
    public function get_all_users_csv() {
        $this->get_users_csv();
    }

    /**
     * Get the JSON data for CSV.
     *
     * CSV is built with JavaScript.
     *
     * @param $users
     * @return void
     */
    public function get_users_csv( $users = null ) {
        if ( null === $users ) {
            set_time_limit( 0 );

            $users = $this->get_users();
        }

        if ( ! $users ) {
            wp_send_json_error( __( 'No Users found', 'pmpro-constantcontact' ) );
            wp_die();
        }

        $headers = apply_filters( 'pmpro_' . $this->ems->get_settings_id() . '_csv_headers', [
            'email'      => 'Email Address',
            'first_name' => 'First Name',
            'last_name'  => 'Last Name'
        ]);

        $data = [
            $headers
        ];

        foreach ( $users as $user ) {
            $object = new \WP_User();
            $object->init( $user );

            $user_row = apply_filters(
                'pmpro_' . $this->ems->get_settings_id() . '_csv_user_row',
                [
                    'email'      => $object->user_email,
                    'first_name' => $object->first_name,
                    'last_name'  => $object->last_name
                ],
                $object
            );

            $data[] = $user_row;
        }

        wp_send_json_success( $data );
        wp_die();
    }

    /**
     * Show Download CSV Buttons
     *
     * @return void
     */
    public function download_csv_button() {
        ?>
        <p>
            <?php esc_html_e( 'Import Existing Users with CSV.', 'pmpro-constantcontact' ); ?>
        </p>
        <p>
            <?php esc_html_e( 'Download all users or only users that are not yet synced.', 'pmpro-constantcontact' ); ?>
        </p>
        <button id="pmproDownloadCSV" data-action="<?php echo esc_attr( $this->ems->get_settings_id() ); ?>_download_csv" type="button" class="button button-secondary"><?php esc_html_e( 'Download All Users CSV', 'pmpro-constantcontact' ); ?></button>
        <button id="pmproDownloadCSVNonSynced" data-action="<?php echo esc_attr( $this->ems->get_settings_id() ); ?>_download_non_synced_csv" type="button" class="button button-secondary"><?php esc_html_e( 'Download Non Synced Users CSV', 'pmpro-constantcontact' ); ?></button>

        <?php
    }

    /**
     * Recursive function to get users in a paged query.
     *
     * @param bool     $non_synced_only If true, it'll get only users that aren't synced in AC.
     * @param integer  $page Page number.
     * @param object[] $users Array of user rows as objects.
     *
     * @return array
     */
    public function get_users( $non_synced_only = false, $page = 1,  $users = [] ) {
        $limit = apply_filters( 'pmpro_set_max_user_per_export_loop', 2000 );
        $args  = [
            'number' => $limit,
            'paged'  => $page
        ];

        // Make all next page queries faster.
        // We need 'Total' only on first.
        if ( $page !==  1) {
            $args['count_total'] = false;
        }

        if ( $non_synced_only ) {
            $args['meta_key']     =  '_pmpro_' . $this->ems->get_settings_id() . '_contact_id';
            $args['meta_compare'] = 'NOT EXISTS';
        }

        $user_search = new \WP_User_Query( $args );
        $users       = array_merge( $users, $user_search->get_results() );

        if ( $page === 1 ) {
            $total = $user_search->get_total();
            while( count( $users ) < $total ) {
                $page++;
                $users = $this->get_users( $non_synced_only, $page, $users );
            }
        }

        return $users;
    }
}