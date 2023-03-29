<?php

namespace PaidMembershipPro\EMS;

if ( ! class_exists( '\PaidMembershipPro\EMS\Settings' ) ) {
    require_once 'abstract/class-settings.php';
}

class EMS extends Settings {

    /**
     * API URL
     * @var string
     */
    protected $api_url = '';

    /**
     * API Key
     * @var string
     */
    protected $api_key = '';

    /**
     * @var null|\PaidMembershipPro\EMS\API
     */
    protected $api = null;

    /**
     * @var null|\PaidMembershipPro\EMS\Cache
     */
    protected $cache_layer = null;

    /**
     * True to show the option to require double-optin with API
     * Set false if the service doesn't have that.
     * @var bool
     */
    protected $has_double_optin = true;

    /**
     * Does the service provide tag feature to tag contacts?
     * @var bool
     */
    protected $has_tags = false;

    /**
     * @var Checkout|null|false
     */
    protected $checkout = null;

    /**
     * @var Profile|null|false
     */
    protected $profile = null;

    /**
     * @var CSV|null|false
     */
    protected $csv = null;

    /**
     * Set true if your API allows you to bulk update data such as tags and lists.
     *
     * @var bool
     */
    protected $bulk_update = false;

    /**
     * @var null|string
     */
    protected $plugin_file = null;

    /**
     * Bulk Queue for updating data.
     *
     * @var array
     */
    protected $bulk_queue = [];

    /**
     * Constructor method.
     */
    public function __construct() {
        $this->include_library_files();
        $this->register_ems_fields();

        add_action( 'pmpro_' . $this->id . '_settings_sanitized', [ $this, 'reset_cache_if_api_changed' ], 20, 3 );
        add_action( 'pmpro_' . $this->id . '_settings_before_options', [ $this, 'maybe_hide_fields_if_api_not_set' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'pmpro_after_all_membership_level_changes', [ $this, 'membership_changes' ] );
        add_action( 'wp_ajax_pmpro_ems_clear_cache_' . $this->id, [ $this, 'clear_cache_ajax' ] );
        add_action( 'pmpro_' . $this->id . '_settings_before_options', [ $this, 'settings_description' ] );
        add_action( 'pmpro_' . $this->id . '_settings_before_options', [ $this, 'show_notice_if_lists_used_on_multiple' ] );

        add_filter( 'plugin_row_meta', [ $this, 'row_meta' ], 10, 2 );
        add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), [ $this, 'plugin_links' ] );

        if ( $this->bulk_update_enabled() ) {
            add_action( 'template_redirect', [ $this, 'process_bulk_change' ], 2 );
            add_filter( 'wp_redirect', [ $this, 'process_bulk_change' ], 100 );
            add_action( 'pmpro_membership_post_membership_expiry', [ $this, 'process_bulk_change' ] );
            add_action( 'shutdown', [ $this, 'process_bulk_change' ] );

        }

        $this->get_checkout();
        $this->get_profile();
        $this->get_csv();
        $this->get_cache();

        parent::__construct();
    }


    /**
     * Process bulk change data.
     *
     * @param mixed $passed_data This is the data passed when using add_filter. Should be returned.
     * @return mixed|void|null
     */
    public function process_bulk_change( $passed_data = null ) {
        if ( empty( $this->bulk_queue ) ) {
            return $passed_data;
        }

        foreach ( $this->bulk_queue as $user_id => $bulk_data ) {
            $this->bulk_change( $user_id, $bulk_data );
        }

        // Clearing the queue to not be procesed more than once.
        $this->bulk_queue = [];

        return $passed_data;
    }

    /**
     * Show Notice if Lists used on Multiple.
     *
     * @return void
     */
    public function show_notice_if_lists_used_on_multiple() {
        $non_member_lists = $this->get_option('non_member_list', [] );
        $optin_lists      = $this->get_option('optin', [] );
        $level_lists      = $this->get_option('audience_levels', [] );

        $all_arrays = array( $non_member_lists, $optin_lists );
        foreach ( $level_lists as $level_array ) {
            $all_arrays[] = $level_array;
        }

        $list_arrays_count = count( $all_arrays );
        $non_unique_lists  = array();

        while( $list_arrays_count > 0 ) {
            $diff = call_user_func_array('array_diff', $all_arrays );

            // If diff array is empty, it might mean all are shared somewhere.
            $array_checked = current( $all_arrays );
            $non_unique    = array_diff( $array_checked, $diff );
            if ( $non_unique ) {
                foreach ( $non_unique as $non_unique_list_id ) {
                    $non_unique_lists[] = $non_unique_list_id;
                }
            }

            $first_array = array_shift($all_arrays);
            // Move it to back.
            $all_arrays[] = $first_array;
            $list_arrays_count--;
        }

        $non_unique_lists = array_unique( $non_unique_lists );
        if ( $non_unique_lists ) {
            $lists = $this->get_lists();
            if ( is_wp_error( $lists ) ) {
                return;
            }
            ?>
            <div class="notice notice-error">
                <p><?php esc_html_e( 'These lists are set more than once in the settings below.', 'pmpro-constantcontact'  ); ?></p>
                <p><?php esc_html_e( 'This can cause users getting unsubscribed or subscribed where you don\'t want it.', 'pmpro-constantcontact'  ); ?></p>
                <p><?php esc_html_e( 'Lists:', 'pmpro-constantcontact'  ); ?></p>

                <ul>
                    <?php
                    foreach ( $non_unique_lists as $non_unique_list_id ) {
                        ?>
                        <li><strong><?php echo esc_html( $lists[ $non_unique_list_id ] ); ?></strong></li>
                        <?php
                    }
                    ?>
                </ul>
            </div>
            <?php
        }
    }

    /**
     * Display description on top of all fields.
     * Useful for short guides.
     *
     * @return void
     */
    public function settings_description() {}

    /**
     * Adds Meta Links to Plugin Row
     *
     * @param array  $links Array of links.
     * @param string $file Plugin file string.
     *
     * @return array|mixed|string[]
     */
    public function row_meta( $links, $file ) {
        if ( ! $this->plugin_file ) {
            return $links;
        }

        if ( strpos( $file, $this->plugin_file ) !== false ) {

            $meta_links = $this->get_row_meta_links();

            $links = array_merge( $links, $meta_links );
        }
        return $links;
    }

    /**
     * Check if bulk update is enabled.
     *
     * @return bool
     */
    public function bulk_update_enabled() {
        return $this->bulk_update;
    }

    /**
     * Return Links to be added in meta row.
     *
     * @return array|string[]
     */
    public function get_row_meta_links() {
        return array();
    }

    /**
     * Return Plugin links to be added.
     *
     * @return array|string[]
     */
    public function get_plugin_links() {
        return array();
    }

    /**
     * Adding Settings Link to Plugin row.
     *
     * @param array $links Array of links.
     *
     * @return array|string[]
     */
    public function plugin_links( $links ) {
        $new_links = $this->get_plugin_links();

        return array_merge( $new_links, $links );
    }


    /**
     * Clear Cache from AJAX call.
     *
     * @return void
     */
    public function clear_cache_ajax() {
        check_admin_referer( 'pmpro_ems', 'nonce' );

        $this->reset_cache();

        wp_send_json_success();
    }


    /**
     * Enqueue Assets in Admin
     *
     * @param string $hook Page hook.
     * @return void
     */
    public function enqueue( $hook ) {
        if ( 'memberships_page_pmpro_' . $this->get_settings_id() . '_options' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'pmpro_ems',
            trailingslashit( $this->get_library_url() ) . 'assets/pmpro-ems-admin.js',
            [ 'jquery' ],
            filemtime( trailingslashit( $this->get_library_path() ) . 'assets/pmpro-ems-admin.js' ),
            true
        );

        wp_localize_script( 'pmpro_ems', 'pmpro_ems', [
            'nonce'   => wp_create_nonce( 'pmpro_ems' ),
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'text'    => [
                'clear_cache' => __( 'Clear Cache', 'pmpro-constantcontact' ),
                'clearing_cache' => __( 'Clearing ...', 'pmpro-constantcontact' ),
                'cache_cleared' => __( 'Cache Cleared.', 'pmpro-constantcontact' ),
            ]
        ]);

        wp_enqueue_style(
            'pmpro_ems',
            trailingslashit( $this->get_library_url() ) . 'assets/pmpro-ems-admin.css',
            null,
            filemtime( trailingslashit( $this->get_library_path() ) . 'assets/pmpro-ems-admin.css' ),
        );
    }

    /**
     * Library URL
     * @return string
     */
    public function get_library_url() {
        return plugins_url( '', __FILE__ );
    }

    /**
     * Library Path
     * @return string
     */
    public function get_library_path() {
        return plugin_dir_path( __FILE__ );
    }

    /**
     * Start the cache layer or get it.
     * If you want to disable it in an integration, set $this->cache = false in the class extending this one.
     *
     * @return Cache|null
     */
    public function get_cache() {
        if ( null === $this->cache_layer ) {
            $this->cache_layer = new EMS_Cache( $this->get_settings_id() );
        }

        return $this->cache_layer;
    }

    /**
     * Start the checkout or get it.
     * If you want to disable it in an integration, set $this->checkout = false in the class extending this one.
     *
     * @return Checkout|null
     */
    public function get_checkout() {
        if ( null === $this->checkout ) {
            $this->checkout = new Checkout( $this );
        }

        return $this->checkout;
    }

    /**
     * Start the Profile or get it.
     * If you want to disable it in an integration, set $this->profile = false in the class extending this one.
     *
     * @return Profile|null
     */
    public function get_profile() {
        if ( null === $this->profile ) {
            $this->profile = new Profile( $this );
        }

        return $this->profile;
    }

    /**
     * Start the CSV or get it.
     * If you want to disable it in an integration, set $this->csv = false in the class extending this one.
     *
     * @return CSV|null
     */
    public function get_csv() {
        if ( null === $this->csv ) {
            $this->csv = new CSV( $this );
        }

        return $this->csv;
    }

    /**
     * Include Library Files
     *
     * @return void
     */
    public function include_library_files() {
        require_once 'class-checkout.php';
        require_once 'class-profile.php';
        require_once 'class-csv.php';
        require_once 'class-ems-cache.php';
    }

    /**
     * Hide other sections & fields if the API is not set or can't connect properly.
     *
     * @return void
     */
    public function maybe_hide_fields_if_api_not_set() {
        if ( null === $this->get_api() ) {
            $this->hide_sections_and_show_error( __( 'No API found to get lists from.', 'pmpro-constantcontact' ) );
            return;
        }

        $connection = $this->get_api()->check_connection();

        if ( ! is_wp_error( $connection ) ) {
            return;
        }

        $this->hide_sections_and_show_error( sprintf( __( 'An Error occurred: %s', 'pmpro-constantcontact' ), $connection->get_error_message() ) );
    }

    /**
     * Hide Sections and show error message if exists
     * @param $error
     * @return void
     */
    public function hide_sections_and_show_error( $error = '' ) {
        $this->remove_section('audience');
        $this->remove_section('tags');
        $this->remove_field( 'cache' );

        if ( ! $error ) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p><?php echo esc_html( $error ); ?></p>
        </div>
        <?php
    }

    /**
     * Useful for API section to describe where to get the API key and such.
     * @return string
     */
    public function get_api_section_description() {
        return '';
    }

    /**
     * Register fields for EMS
     * @return void
     */
    public function register_ems_fields() {
        $this->fields['api'] = [
            'name'        => 'api',
            'title'       => __( 'API', 'pmpro-constantcontact' ),
            'description' => $this->get_api_section_description(),
            'type'        => 'section'
        ];

        if( ! $this->api_url ) {
            $this->fields['api_url'] = [
                'name'        => 'api_url',
                'title'       => __( 'API URL', 'pmpro-constantcontact' ),
                'section'     => 'api',
                'type'        => 'text'
            ];
        }

        $this->fields['api_key'] = [
            'name'        => 'api_key',
            'title'       => __( 'API Key', 'pmpro-constantcontact' ),
            'section'     => 'api',
            'type'        => 'text'
        ];

        if ( $this->get_api()->has_oauth() ) {
            $this->fields['api_secret'] = [
                'name'        => 'api_secret',
                'title'       => __( 'Client Secret', 'pmpro-constantcontact' ),
                'section'     => 'api',
                'type'        => 'text'
            ];

            $this->fields['api_redirect'] = [
                'name'        => 'api_redirect',
                'title'       => __( 'Redirect URI', 'pmpro-constantcontact' ),
                'section'     => 'api',
                'type'        => 'redirect_url',
                'description' => __( 'Add this URL as redirect URI to your Application', 'pmpro-constantcontact' ),
            ];

            $this->fields['oauth'] = [
                'name'        => 'oauth',
                'title'       => __( 'Connect with Service', 'pmpro-constantcontact' ),
                'section'     => 'api',
                'type'        => 'oauth'
            ];
        }

        $this->fields['cache'] = [
            'name'        => 'cache',
            'title'       => __( 'Cache', 'pmpro-constantcontact' ),
            'type'        => 'cache_section',
            'section'     => 'api'
        ];

        $this->fields['audience'] = [
            'name'        => 'audience',
            'title'       => __( 'Audience', 'pmpro-constantcontact' ),
            'type'        => 'section'
        ];

        $this->fields['non_member_list'] = [
            'name'        => 'non_member_list',
            'title'       => __( 'Non Member List', 'pmpro-constantcontact' ),
            'section'     => 'audience',
            'type'        => 'lists',
            'description' => __( 'If a user has registered, but not yet on any member plan, they\'ll get subscribed to this list', 'pmpro-constantcontact' )
        ];

        $this->fields['optin_label'] = [
            'name'        => 'optin_label',
            'title'       => __( 'Opt-in Label', 'pmpro-constantcontact' ),
            'section'     => 'audience',
            'type'        => 'text',
            'default'     => __( 'Join our mailing lists. ', 'pmpro-constantcontact' ),
            'description' => __( 'Lists to be presented for opt-in on checkout or profile page. If only one Opt-in list is selected, this will be used as label.', 'pmpro-constantcontact' )
        ];

        $this->fields['optin'] = [
            'name'        => 'optin',
            'title'       => __( 'Opt-in Lists', 'pmpro-constantcontact' ),
            'section'     => 'audience',
            'type'        => 'lists',
            'description' => __( 'Lists to be presented for opt-in on checkout or profile page.', 'pmpro-constantcontact' )
        ];

        $this->fields['exclude_roles'] = [
            'name'        => 'exclude_roles',
            'title'       => __( 'Exclude Roles', 'pmpro-constantcontact' ),
            'section'     => 'audience',
            'type'        => 'roles',
            'description' => __( 'Checked roles won\'t be added to lists.', 'pmpro-constantcontact' )
        ];

        $this->fields['audience_levels'] = [
            'name'        => 'audience_levels',
            'title'       => __( 'Levels', 'pmpro-constantcontact' ),
            'type'        => 'audience_levels',
            'section'     => 'audience'
        ];

        if ( $this->has_double_optin ) {
            $this->fields['double_optin'] = [
                'name' => 'double_optin',
                'title' => __('Double Opt-in', 'pmpro-constantcontact'),
                'description' => __('If checked, users will be required to confirm their subscription', 'pmpro-constantcontact'),
                'section' => 'audience',
                'type' => 'checkbox'
            ];
        }

        if ( $this->has_tags ) {
            $this->fields['tags'] = [
                'name'        => 'tags',
                'title'       => __( 'Tags', 'pmpro-constantcontact' ),
                'description' => __( 'Map each Membership with a Tag. ', 'pmpro-constantcontact' ),
                'type'        => 'section'
            ];

            $this->fields['tags_levels'] = [
                'name'        => 'tags_levels',
                'title'       => __( 'Levels', 'pmpro-constantcontact' ),
                'type'        => 'tags_levels',
                'section'     => 'tags'
            ];
        }
    }

    public function render_redirect_url( $field ) {
        ?>
        <input readonly type="text" class="widefat" value="<?php echo esc_url( home_url() ); ?>" />
        <?php
        if ( ! empty( $field['description'] ) ) {
            ?>
            <p class="description"><?php echo esc_html( $field['description'] ); ?></p>
            <?php
        }
    }

    /**
     * Render Cache Section
     *
     * @param $field
     * @return void
     */
    public function render_cache_section( $field ) {
        $last_cached = get_option( 'pmpro_' . $this->id . '_last_cached' );
        $expiration  = $last_cached + DAY_IN_SECONDS;

        if ( ! $last_cached || $expiration < time() ) {
            ?>
            <p><?php esc_html_e( 'No list/tags were cached yet.', 'pmpro-constantcontact' ); ?></p>
            <?php
            return;
        }

        ?>
        <p>
            <?php echo esc_html( sprintf( __( 'The audience and tag options are cached to improve performance. The cache was last updated on %s.', 'pmpro-constantcontact' ), date('F j, Y', $last_cached ) ) ); ?>
            <button data-pmpro-action="pmpro_ems_clear_cache_<?php echo esc_attr( $this->id ); ?>" type="button" class="button button-secondary button-small">
                 <?php esc_html_e( 'Clear Cache', '' ); ?>
            </button>
        </p>
        <?php
    }

    /**
     * Sanitize options by saving old option value in case such option wasn't even posted.
     *
     * If it's not posted, it means that we probably have problems with API and not showing fields.
     *
     * @param $input
     * @return mixed
     */
    public function sanitize( $input ) {

        $get_saved_data = false;

        // This means that all fields are not shown and posted.
        // We are always showing Opt-in Label field.
        // It's hidden only when API params are invalid or not added.
        if ( ! isset( $input['optin_label'] ) ) {
            $get_saved_data = true;
        }

        if ( $get_saved_data ) {
            $fields = $this->get_fields();

            foreach ( $fields as $field ) {
                if ( 'section' === $field['type'] ) {
                    continue;
                }

                if ( isset( $input[ $field['name'] ] ) ) {
                    continue;
                }

                $input[ $field['name'] ] = $this->get_option( $field['name'] );
            }
        }

        return parent::sanitize( $input );
    }

    /**
     * Reset cache if we have changed API values.
     *
     * @param array $input Options to save
     * @param array $fields Fields we have.
     * @param array $posted Data in $_POST
     * @return void
     */
    public function reset_cache_if_api_changed( $input, $fields, $posted ) {
        if ( ! empty( $fields['api_url'] ) ) {
            $old_api_url = $this->get_option( 'api_url' );
            $api_url     = $input['api_url'];

            if ( $old_api_url !== $api_url ) {
                $this->reset_cache();
                return;
            }
        }

        if ( ! empty( $fields['api_key'] ) ) {
            $old_api_key = $this->get_option( 'api_key' );
            $api_key     = $input['api_key'];

            if ( $old_api_key !== $api_key ) {
                $this->reset_cache();
                $this->clear_oauth();
                return;
            }
        }

        if ( ! empty( $fields['api_secret'] ) ) {
            $old_api_secret = $this->get_option( 'api_secret' );
            $api_secret     = $input['api_secret'];

            if ( $old_api_secret !== $api_secret ) {
                $this->reset_cache();
                $this->clear_oauth();
                return;
            }
        }
    }

    /**
     * Clear Oauth data.
     * @return void
     */
    public function clear_oauth() {
        if ( ! $this->get_api()->has_oauth() ) {
            return;
        }

        delete_option( 'pmpro_' . $this->id . '_oauth' );
    }

    /**
     * Reset Cache
     * @return void
     */
    public function reset_cache() {
        if ( ! $this->cache_layer ) {
            return;
        }

        $this->cache_layer->delete( 'lists' );
        $this->cache_layer->delete( 'tags' );

        delete_option( 'pmpro_' . $this->id . '_last_cached' );

        do_action( 'pmpro_' . $this->id . '_reset_cache', $this );
    }

    /**
     * Render List input to choose multiple lists.
     *
     * @param $args
     * @return void
     */
    public function render_lists( $args ) {
        $lists = $this->get_lists();

        if ( is_wp_error( $lists ) ) {
            echo $lists->get_error_message();
            return;
        }

        if ( ! $lists ) {
            echo __( 'No Audience/List found.', 'pmpro-constantcontact' );
            return;
        }

        $chosen_lists = $this->get_option( $args['name'] );

        if ( $chosen_lists && ! is_array( $chosen_lists ) ) {
            $chosen_lists = [ $chosen_lists ];
        }

        if ( ! is_array( $chosen_lists ) ) {
            $chosen_lists = [];
        }

        if ( ! empty( $args['description'] ) ) {
            echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
        }
        ?>
            <div class="pmpro-ems-scrollable">
            <?php
            foreach ( $lists as $list_id => $list_name ) {
                $selected = in_array( $list_id, $chosen_lists );

                ?>
                <p>
                    <label for="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_<?php echo esc_attr( $list_id ); ?>">
                        <input
                            id="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_<?php echo esc_attr( $list_id ); ?>"
                            <?php checked( $selected ); ?>
                            type="checkbox"
                            name="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>[]"
                            value="<?php echo esc_attr( $list_id ); ?>"
                        />
                        <?php echo esc_html( $list_name ); ?>
                    </label>
                </p>
                <?php
            }
            ?>
            </div>
        <?php
    }

    /**
     * Render List input
     *
     * @param $args
     * @return void
     */
    public function render_list( $args ) {
        $lists = $this->get_lists();

        if ( is_wp_error( $lists ) ) {
            echo $lists->get_error_message();
            return;
        }

        if ( ! $lists ) {
            echo __( 'No Audience/List found.', 'pmpro-constantcontact' );
            return;
        }

        $chosen_list = $this->get_option( $args['name'] );

        ?>
        <select name="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>" class="widefat">
            <option value="0"><?php esc_html_e( 'Choose a List', 'pmpro-constantcontact' );?></option>
            <?php
                foreach ( $lists as $list_id => $list_name ) {
                    ?>
                    <option <?php selected( $chosen_list, $list_id ); ?> value="<?php echo esc_attr( $list_id ); ?>"><?php echo esc_html( $list_name ); ?></option>
                    <?php
                }
            ?>
        </select>
        <?php
        if ( ! empty( $args['description'] ) ) {
            echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
        }
    }

    /**
     * Render Roles
     *
     * @param $args
     * @return void
     */
    public function render_roles( $args ) {
        $roles = wp_roles();
        $args['options'] =  $roles->role_names;
        $this->render_multi_checkbox($args);
    }

    /**
     * Render Tags Levels
     *
     * @param $args
     * @return void
     */
    public function render_tags_levels( $args ) {
        $tags = $this->get_tags();
        $levels = $this->get_pmp_membership_levels();

        if ( is_wp_error( $tags ) ) {
            echo $tags->get_error_message();
            return;
        }

        if ( ! $levels ) {
            esc_html_e( 'Please create Membership Levels','pmpro-constantcontact' );
            return;
        }

        if ( ! $tags ) {
            esc_html_e( 'Please create Tags','pmpro-constantcontact' );
            return;
        }

        $values = $this->get_option( $args['name'] );
        ?>
        <table class="form-table">
            <tr>
                <th>
                    <?php esc_html_e( 'Non member', 'pmpro-constantcontact' ); ?>
                </th>
                <td>
                    <div class="pmpro-ems-scrollable">
                    <?php
                    $selected_values = isset( $values['non_member'] ) ? $values['non_member'] : array();
                    if ( ! is_array( $selected_values ) ) {
                        $selected_values = array( $selected_values );
                    }
                    foreach ( $tags as $tag_id => $tag_name ) {
                        $selected = in_array( $tag_id, $selected_values );
                        ?>
                        <p>
                            <label for="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_non_member_<?php echo esc_attr( $tag_id ); ?>">
                                <input
                                        id="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_non_member_<?php echo esc_attr( $tag_id ); ?>"
                                    <?php checked( $selected ); ?>
                                        type="checkbox"
                                        name="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>[non_member][]"
                                        value="<?php echo esc_attr( $tag_id ); ?>"
                                />
                                <?php echo esc_html( $tag_name ); ?>
                            </label>
                        </p>
                        <?php
                    }
                    ?>
                    </div>
                </td>
            </tr>
            <?php
                foreach ( $levels as $level_id => $level_name ) {
                    $selected_values = isset( $values[ $level_id ] ) ? $values[ $level_id ] : array();
                    if ( ! is_array( $selected_values ) ) {
                        $selected_values = array( $selected_values );
                    }
                    ?>
                    <tr>
                        <th>
                            <?php echo esc_html( $level_name ); ?>
                        </th>
                        <td>
                            <div class="pmpro-ems-scrollable">
                            <?php
                            foreach ( $tags as $tag_id => $tag_name ) {
                                $selected = in_array( $tag_id, $selected_values );
                                ?>
                                <p>
                                    <label for="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_<?php echo esc_attr( $level_id ); ?>_<?php echo esc_attr( $tag_id ); ?>">
                                        <input
                                                id="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_<?php echo esc_attr( $level_id ); ?>_<?php echo esc_attr( $tag_id ); ?>"
                                            <?php checked( $selected ); ?>
                                                type="checkbox"
                                                name="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>[<?php echo esc_attr( $level_id ); ?>][]"
                                                value="<?php echo esc_attr( $tag_id ); ?>"
                                        />
                                        <?php echo esc_html( $tag_name ); ?>
                                    </label>
                                </p>
                                <?php
                            }
                            ?>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            ?>
        </table>
        <?php

    }

    /**
     * Render List Levels
     * @param $args
     * @return void
     */
    public function render_audience_levels( $args ) {
        $lists = $this->get_lists();
        $levels = $this->get_pmp_membership_levels();

        if ( is_wp_error( $lists ) ) {
            echo $lists->get_error_message();
            return;
        }

        if ( ! $levels ) {
            esc_html_e( 'Please create Membership Levels','pmpro-constantcontact' );
            return;
        }

        if ( ! $lists ) {
            esc_html_e( 'Please create Lists','pmpro-constantcontact' );
            return;
        }

        $chosen_lists = $this->get_option( $args['name'] );

        if ( ! is_array( $chosen_lists ) ) {
            $chosen_lists = [];
        }

        ?>
        <table class="form-table">
            <?php
            foreach ( $levels as $level_id => $level_name ) {
                $selected_values = isset( $chosen_lists[ $level_id ] ) ? $chosen_lists[ $level_id ] : array();
                if ( ! is_array( $selected_values ) ) {
                    $selected_values = [];
                }

                ?>
                <tr>
                    <th>
                        <?php echo esc_html( $level_name ); ?>
                    </th>
                    <td>
                        <div class="pmpro-ems-scrollable">
                        <?php
                        foreach ( $lists as $list_id => $list_name ) {
                            $selected = in_array( $list_id, $selected_values );
                            ?>
                            <p>
                                <label for="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_<?php echo esc_attr( $level_id ); ?>_<?php echo esc_attr( $list_id ); ?>">
                                    <input
                                            id="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_<?php echo esc_attr( $level_id ); ?>_<?php echo esc_attr( $list_id ); ?>"
                                        <?php checked( $selected ); ?>
                                            type="checkbox"
                                            name="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>[<?php echo esc_attr( $level_id ); ?>][]"
                                            value="<?php echo esc_attr( $list_id ); ?>"
                                    />
                                    <?php echo esc_html( $list_name ); ?>
                                </label>
                            </p>
                            <?php
                        }
                        ?>
                        </div>
                    </td>
                </tr>
                <?php
            }
            ?>
        </table>
        <?php

    }

    /**
     * Get the API
     *
     * @return null
     */
    public function get_api() {
        return null;
    }

    /**
     * Get the API Key.
     * @return mixed|string
     */
    public function get_api_key() {
        return $this->get_option( 'api_key' );
    }

    /**
     * Get API URL
     * @return mixed|string
     */
    public function get_api_url() {
        return $this->get_option( 'api_url' );
    }

    /**
     * Get a cached value
     * @param string $name Name of the cache item.
     * @return false|mixed
     */
    public function get_cached_value( $name ) {
        if ( ! $this->cache_layer ) {
            return false;
        }

        return $this->cache_layer->get( $name );
    }

    /**
     * Set a cached value
     * @param string $name Name under which the values are cached.
     * @param mixed  $value Value to cache.
     * @return void
     */
    public function set_cached_value( $name, $value ) {
        if ( ! $this->cache_layer ) {
            return;
        }

        update_option( 'pmpro_' . $this->id . '_last_cached', time() );

        $this->cache_layer->add( $name, $value );
    }

    /**
     * Get Lists.
     * If there is a cached value, it will return that.
     *
     * @return mixed
     */
    public function get_lists() {
        $lists = $this->get_cached_value( 'lists' );

        if ( $lists ) {
            return $lists;
        }

        $lists = $this->get_api()->get_lists();

        if ( is_wp_error( $lists ) ) {
            return $lists;
        }

        $this->set_cached_value( 'lists', $lists );

        return $lists;
    }

    /**
     * Get Tags.
     * If there is a cached value, it will return that.
     *
     * @return mixed
     */
    public function get_tags() {
        $tags = $this->get_cached_value( 'tags' );

        if ( $tags ) {
            return $tags;
        }

        $tags = $this->get_api()->get_tags();

        if ( is_wp_error( $tags ) ) {
            return $tags;
        }


        $this->set_cached_value( 'tags', $tags );

        return $tags;
    }

    /**
     * Get all PMP Membership levels
     *
     * Helper function to get member levels from PMP database.
     * This is patterned on PMP's `membershiplevels.php` file.
     * @see https://github.com/strangerstudios/paid-memberships-pro/blob/dev/adminpages/membershiplevels.php#L656
     *
     * @since 1.0.0
     * @return array
     */
    public function get_pmp_membership_levels() {

        global $wpdb;

        // Bail if Paid Memberships Pro is not active.
        if ( ! defined( 'PMPRO_VERSION' ) ) {
            return [];
        }

        $sqlQuery = "SELECT * FROM $wpdb->pmpro_membership_levels ";
        $sqlQuery .= "ORDER BY id ASC";

        $result = $wpdb->get_results($sqlQuery, OBJECT);

        $levels = array();

        foreach ( $result as $_level ){
            $levels[ $_level->id ] = $_level->name;
        }

        return $levels;

    }

    /**
     * If the user has one of the excluded roles, we don't update it on the service.
     *
     * @param \WP_User|int $user User object or ID.
     * @return bool
     */
    public function has_excluded_role( $user ) {
        if ( ! $user ) {
            return false;
        }

        if ( ! is_a( $user, 'WP_User') ) {
            $user = get_user_by( 'id', $user );
        }

        if ( ! $user ) {
            return false;
        }

        $excluded_roles = $this->get_option( 'exclude_roles', [] );
        $roles          = $user->roles;

        if ( ! $excluded_roles ) {
            return false;
        }

        foreach ( $roles as $role ) {
            if ( in_array( $role, $excluded_roles, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render field for the Oauth flow.
     * It will contain the buttons to redirect the user to authorize or re-authorize.
     *
     * @param $field
     * @return void
     */
    public function render_oauth( $field ) {
        $api_key = $this->get_api_key();
        $api_secret = $this->get_option('api_secret');
        $oauth = get_option( 'pmpro_' . $this->id . '_oauth' );

        if ( $oauth && ! empty( $oauth['access_token'] ) ) {
            $next = wp_next_scheduled( 'pmpro_' . $this->get_settings_id() . '_refresh_oauth_token' );
            $url = $this->get_api()->get_authorize_url();
            ?>
                <p class="pmpro-oauth-connected">
                    <?php esc_html_e( 'Connected', 'pmpro-constantcontact' ); ?>
                    <a class="button button-secondary button-small" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Having issues? Re-authorize', 'pmpro-constantcontact' ); ?></a>
                </p>
                <?php
                if ( $next ) {
                    ?>
                    <p class="description">
                        <em><?php esc_html_e( 'Token will expire at:', 'pmpro-constantcontact' ); ?> <?php echo date( 'Y-m-d H:i', $next ); ?>. <?php esc_html_e( 'It is scheduled to be re-connected automatically.', 'pmpro-constantcontact' ); ?></em>
                    </p>
                    <?php
                }
                ?>
                <p class="description">
                    <em><?php esc_html_e( 'Changing Client Secret or Client ID will reset connection as well.', 'pmpro-constantcontact' ); ?></em>
                </p>
            <?php
            return;
        }

        if ( $oauth && ! empty( $oauth['error'] ) ) {
            ?>
                <p class="pmpro-oauth-error"><?php echo esc_html( 'Error: ' . $oauth['error_description'] ); ?></p>
            <?php
        }

        if ( ! $api_key || ! $api_secret ) {
            esc_html_e('Please enter the Client ID & Secret to connect.', 'pmpro-constantcontact' );
            return;
        }

        $url = $this->get_api()->get_authorize_url();
        ?>
        <a class="button button-secondary" href="<?php echo esc_url( $url ); ?>">
            <?php esc_html_e( 'Connect', 'pmpro-constantcontact'); ?>
        </a>
        <?php
    }

    /**
     * Perform changes based on levels.
     *
     * @param $pmpro_old_user_levels
     * @return void
     */
    public function membership_changes( $pmpro_old_user_levels ) {

        foreach ( $pmpro_old_user_levels as $user_id => $old_levels ) {
            $old_levels = wp_list_pluck( $old_levels, 'id' );
            // Get the user's current active membership levels.
            $new_levels = wp_list_pluck( pmpro_getMembershipLevelsForUser( $user_id ), 'id' );
            $user = get_userdata( $user_id );

            $tags      = $this->change_tags( $new_levels, $old_levels, $user );
            $lists     = $this->change_lists( $new_levels, $old_levels, $user );
            $bulk_data = apply_filters( 'pmpro_' . $this->get_settings_id() . '_bulk_change_data',
                [
                    'tags' => $tags,
                    'lists' => $lists
                ],
                $user,
                $old_levels,
                $new_levels
            );

            if ( $this->bulk_update_enabled() ) {
                $this->bulk_queue( $user_id, $bulk_data );
            }

            do_action( 'pmpro_' . $this->get_settings_id() . '_data_changed', $bulk_data, $user, $old_levels, $new_levels );
        }
    }

    /**
     * Perform the bulk change.
     * This will run only if bulk change is enabled.
     * Useful for services that allow a bulk update such as Constant Contact.
     *
     * @param $user_id
     * @param $bulk_data
     * @return false
     */
    public function bulk_change( $user_id, $bulk_data ) {
        if ( $this->has_excluded_role( $user_id ) ) {
            return false;
        }

        $contact_id = $this->get_contact_id( $user_id );

        if ( ! $contact_id ) {
            return false;
        }

        return $this->get_api()->bulk_update( $contact_id, $bulk_data, $user_id );
    }

    public function bulk_queue( $user_id, $bulk_data ) {
        if ( isset( $this->bulk_queue[ $user_id ] ) ) {
            $this->bulk_queue[ $user_id ] = array_merge_recursive( $this->bulk_queue[ $user_id ], $bulk_data );
            return;
        }
        $this->bulk_queue[ $user_id ] = $bulk_data;
    }

    /**
     * Return old & new data based on the provided set of data.
     *
     * @param array $data Set of data level_id => tag_id, level_id => list_id
     * @param array $old_levels Old Levels
     * @param array $new_levels New Levels
     * @return array Array (
     *    'old' => array of old tags/lists to remove
     *    'new' => array of new tags/lists to add
     * )
     */
    public function get_changed_data( $data, $old_levels, $new_levels ) {
        $new_tags    = array();
        $old_tags    = array();

        // Build an array of all tags assigned to the user's old membership levels.
        foreach ( $old_levels as $old_level ) {
            if ( ! empty( $data[ $old_level ] ) ) {
                $old_tags = array_merge( $old_tags, $data[ $old_level ] );
            }
        }

        // Build an array of all tags assigned to the user's new membership levels.
        foreach ( $new_levels as $new_level ) {
            if ( ! empty( $data[ $new_level ] ) ) {
                $new_tags = array_merge( $new_tags, $data[ $new_level ] );
            }
        }

        // Remove duplicates in the array of new and old tags.
        $new_tags = array_unique( $new_tags );
        $old_tags = array_unique( $old_tags );

        // Build a unique array of tags to subscribe to contact and remove from contact.
        $subscribe_tags = array_diff( $new_tags, $old_tags );
        $unsubscribe_tags = array_diff( $old_tags, $new_tags );

        /**
         * No new levels so we're assuming they're cancelling.
         * We'll add a 'Non-member' tag to the subscriber and remove it if they become a member again
         */
        if( empty( $new_tags ) && ! $new_levels ) {
            if ( ! empty( $data[ 'non_member' ] ) ) {
                $subscribe_tags = array_merge( $subscribe_tags, $data[ 'non_member' ] );
                $subscribe_tags = array_unique( $subscribe_tags );
            }
        } else {
            if ( ! empty( $data[ 'non_member' ] ) ) {
                $unsubscribe_tags = array_merge( $unsubscribe_tags, $data[ 'non_member' ] );
                $unsubscribe_tags = array_unique( $unsubscribe_tags );
            }
        }

        return [
            'old' => $unsubscribe_tags,
            'new' => $subscribe_tags
        ];
    }

    /**
     * Get Changed Tags
     *
     * @param $old_levels
     * @param $new_levels
     * @return array Array with keys 'add' and 'remove'.
     *               Key 'add': lists added or to be added.
     *               Key 'remove': lists removed or to be removed.
     */
    public function get_changed_tags( $old_levels, $new_levels ) {
        $tags_levels = $this->get_option( 'tags_levels' );
        return $this->get_changed_data( $tags_levels, $old_levels, $new_levels );
    }

    /**
     * Perform changing tags or return tags to be changed (for bulk updates)
     *
     * @param string[] $new_levels
     * @param string[] $old_levels
     * @param \WP_User $user
     * @return array
     */
    public function change_tags( $new_levels, $old_levels, $user ) {
        $changed_tags     = $this->get_changed_tags( $old_levels, $new_levels );
        $subscribe_tags   = $changed_tags['new'];
        $unsubscribe_tags = $changed_tags['old'];
        $user_id          = $user->ID;

        /**
         * Allow custom code to filter the subscribe tags for the user by email.
         *
         * @since 1.2.0
         *
         * @param array $subscribe_tags The array of tag IDs to subscribe this email address to.
         * @param string $user_email The user's email address to subscribe tags for.
         * @param array $new_levels The new level objects for this user.
         * @param array $old_levels The old level objects for this user.
         */
        $subscribe_tags = apply_filters( 'pmpro_' . $this->get_settings_id() . '_subscribe_tags', $subscribe_tags, $user, $new_levels, $old_levels );

        if ( ! $this->bulk_update_enabled() ) {
            foreach ( $subscribe_tags as $new_tag ) {
                $this->tag( $user_id, $new_tag );
            }
        }

        /**
         * Option to remove other tags for other levels on level change.
         *
         * @param bool $remove_tags Set to true to remove other tags. Default: false.
         * @param int $cancel_level The ID of the level previously held, if available.
         * @return bool $remove_tags.
         *
         */
        $remove_tags = apply_filters( 'pmpro_' . $this->get_settings_id() . '_after_all_membership_level_changes_remove_tags', true, $unsubscribe_tags );

        if ( ! empty( $remove_tags ) ) {
            /**
             * Allow custom code to filter the unsubscribe tags for the user by email.
             *
             * @since 1.2.0
             *
             * @param array $unsubscribe_tags The array of tag IDs to unubscribe this email address from.
             * @param string $user_email The user's email address to unsubscribe tags for.
             * @param array $new_levels The new level objects for this user.
             * @param array $old_levels The old level objects for this user.
             */
            $unsubscribe_tags = apply_filters( 'pmpro_' . $this->get_settings_id() . '_unsubscribe_tags', $unsubscribe_tags, $user, $new_levels, $old_levels );

            if ( ! $this->bulk_update_enabled() ) {
                // Run the API call to remove tags from this subscriber.
                if (!empty ($unsubscribe_tags)) {
                    foreach ($unsubscribe_tags as $unsubscribe_tag) {
                        $this->untag($user_id, $unsubscribe_tag);
                    }
                }
            }
        }

        return [
            'add'    => $subscribe_tags,
            'remove' => $remove_tags ? $unsubscribe_tags : []
        ];
    }

    /**
     * Get Changed Lists
     *
     * @param $old_levels
     * @param $new_levels
     * @return array
     */
    public function get_changed_lists( $old_levels, $new_levels ) {
        $list_levels     = $this->get_option( 'audience_levels' );
        $data            = $this->get_changed_data( $list_levels, $old_levels, $new_levels );
        $non_member_list = $this->get_option( 'non_member_list' );

        // If new lists data is empty & there are no new levels, set it as non_member.
        // If new lists data is empty, but user is still added to a level, make sure it's not set for non_member.
        if( empty( $data['new'] ) && ! $new_levels ) {
            if ( ! empty( $non_member_list ) ) {
                $data['new'] = array_merge( $data['new'], $non_member_list );
                $data['new'] = array_unique( $data['new'] );
            }
        } else {
            if ( ! empty( $non_member_list ) ) {
                $data['old'] = array_merge( $data['old'], $non_member_list );
                $data['old'] = array_unique( $data['old'] );
            }
        }

        return [
            'old' => $data['old'],
            'new' => $data['new']
        ];
    }

    /**
     * Perform changing lists or return lists to be changed (for bulk updates)
     *
     * @param string[] $new_levels
     * @param string[] $old_levels
     * @param \WP_User $user
     * @return array Array with keys 'add' and 'remove'.
     *               Key 'add': lists added or to be added.
     *               Key 'remove': lists removed or to be removed.
     */
    public function change_lists( $new_levels, $old_levels, $user ) {
        $changed_lists     = $this->get_changed_lists( $old_levels, $new_levels );
        $subscribe_lists   = $changed_lists['new'];
        $unsubscribe_lists = $changed_lists['old'];
        $user_id           = $user->ID;

        /**
         * Allow custom code to filter the subscribe tags for the user by email.
         *
         * @since 1.2.0
         *
         * @param array $subscribe_tags The array of tag IDs to subscribe this email address to.
         * @param string $user_email The user's email address to subscribe tags for.
         * @param array $new_levels The new level objects for this user.
         * @param array $old_levels The old level objects for this user.
         */
        $subscribe_lists = apply_filters( 'pmpro_' . $this->get_settings_id() . '_subscribe_lists', $subscribe_lists, $user, $new_levels, $old_levels );

        if ( ! $this->bulk_update_enabled() ) {
            foreach ($subscribe_lists as $list_id) {
                $this->subscribe($user_id, $list_id);
            }
        }

        /**
         * Option to remove other lists for other levels on level change.
         *
         * @param bool $remove_tags Set to true to remove other tags. Default: false.
         * @param int $cancel_level The ID of the level previously held, if available.
         * @return bool $remove_tags.
         *
         */
        $remove_from_lists = apply_filters( 'pmpro_' . $this->get_settings_id() . '_after_all_membership_level_changes_remove_from_lists', true, $unsubscribe_lists );

        if ( ! empty( $remove_from_lists ) ) {
            /**
             * Allow custom code to filter the unsubscribe tags for the user by email.
             *
             * @since 1.2.0
             *
             * @param array $unsubscribe_tags The array of tag IDs to unubscribe this email address from.
             * @param string $user_email The user's email address to unsubscribe tags for.
             * @param array $new_levels The new level objects for this user.
             * @param array $old_levels The old level objects for this user.
             */
            $unsubscribe_lists = apply_filters( 'pmpro_' . $this->get_settings_id() . '_unsubscribe_lists', $unsubscribe_lists, $user, $new_levels, $old_levels );

            if ( ! $this->bulk_update_enabled() ) {
                // Run the API call to remove tags from this subscriber.
                if (!empty ($unsubscribe_lists)) {
                    foreach ($unsubscribe_lists as $list_id) {
                        $this->unsubscribe($user_id, $list_id);
                    }
                }
            }
        }

        return [
            'add'    => $subscribe_lists,
            'remove' => $remove_from_lists ? $unsubscribe_lists : []
        ];
    }

    /**
     * Contact ID Key.
     * Used to store the contact ID of each user.
     *
     * @return string
     */
    public function get_contact_id_key() {
        return '_pmpro_' . $this->get_settings_id() . '_contact_id';
    }

    /**
     * Get Contact ID.
     *
     * @param integer $user_id User ID.
     * @return mixed
     */
    public function get_contact_id( $user_id ) {
        $contact_id = get_user_meta( $user_id, $this->get_contact_id_key(), true );
        return $contact_id ?: $this->create_contact( $user_id );
    }

    /**
     * Save the Contact ID
     * @param int        $user_id User ID
     * @param string|int $contact_id Contact ID from the service.
     * @return void
     */
    public function save_contact_id( $user_id, $contact_id ) {
        update_user_meta( $user_id, $this->get_contact_id_key(), $contact_id );
    }

    /**
     * Create an AC Contact
     *
     * @param integer $user_id User ID.
     * @return mixed
     */
    public function create_contact( $user_id ) {
        if ( ! $user_id ) {
            return 0;
        }

        if ( $this->has_excluded_role( $user_id ) ) {
            return 0;
        }

        $user = get_user_by( 'id', $user_id );

        $contact_id = $this->get_api()->create_contact( $user );

        if ( is_wp_error( $contact_id ) ) {
            return $contact_id;
        }

        $this->save_contact_id( $user_id, $contact_id );

        return $contact_id;
    }

    /**
     * Update an Contact
     *
     * @param integer $user_id User ID.
     * @param $body
     * @return mixed
     */
    public function update_contact( $user_id, $body ) {
        if ( $this->has_excluded_role( $user_id ) ) {
            return 0;
        }

        $contact_id = $this->get_contact_id( $user_id );

        if ( ! $contact_id ) {
            return 0;
        }

        $contact = $this->get_api()->update_contact( $contact_id, $body );

        if ( is_wp_error( $contact ) ) {
            return $contact;
        }

        return $contact;
    }

    /**
     * Get Subscribed List IDs.
     *
     * @param integer $user_id User ID.
     * @return array
     */
    public function get_subscribed_list_ids( $user_id ) {
        if ( $this->has_excluded_role( $user_id ) ) {
            return [];
        }

        $contact_id = $this->get_contact_id( $user_id );

        if ( ! $contact_id ) {
            return [];
        }

        $lists = $this->get_api()->get_lists_from_contact( $contact_id );

        if ( is_wp_error( $lists ) ) {
            return [];
        }

        if ( ! $lists ) {
            return [];
        }

        return $lists;
    }

    /**
     * Subscribe a user to a list.
     *
     * @param integer $user_id User ID.
     * @param integer $list_id AC List ID.
     * @return boolean|\WP_Error
     */
    public function subscribe( $user_id, $list_id ) {
        if ( $this->has_excluded_role( $user_id ) ) {
            return false;
        }

        $contact_id = $this->get_contact_id( $user_id );

        if ( ! $contact_id ) {
            return false;
        }

        $resp = $this->get_api()->subscribe( $contact_id, $list_id );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        return true;
    }

    /**
     * Unsubscribe a user from a list.
     *
     * @param integer $user_id User ID.
     * @param integer $list_id List ID.
     * @return bool|\WP_Error
     */
    public function unsubscribe( $user_id, $list_id ) {
        if ( $this->has_excluded_role( $user_id ) ) {
            return false;
        }

        $contact_id = $this->get_contact_id( $user_id );

        if ( ! $contact_id ) {
            return false;
        }

        $resp = $this->get_api()->unsubscribe( $contact_id, $list_id );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }

        return true;
    }

    /**
     * Tag user.
     *
     * @param integer $user_id User ID.
     * @param integer $tag_id Tag ID.
     * @return bool|\WP_Error|\WP_HTTP_Response
     */
    public function tag( $user_id, $tag_id ) {
        if ( $this->has_excluded_role( $user_id ) ) {
            return false;
        }

        $contact_id = $this->get_contact_id( $user_id );

        if ( ! $contact_id ) {
            return false;
        }

        $response = $this->get_api()->tag( $contact_id, $tag_id );

        return $response;
    }

    /**
     * Untag a user.
     *
     * @param integer $user_id User ID.
     * @param integer $tag_id Tag ID.
     * @return bool|\WP_Error|\WP_HTTP_Response
     */
    public function untag( $user_id, $tag_id ) {
        if ( $this->has_excluded_role( $user_id ) ) {
            return false;
        }

        $contact_id = $this->get_contact_id( $user_id );

        if ( ! $contact_id ) {
            return false;
        }

        $resp = $this->get_api()->untag( $contact_id, $tag_id );

        return $resp;
    }
}