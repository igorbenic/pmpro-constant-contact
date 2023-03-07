<?php

namespace PaidMembershipPro\EMS;

abstract class Settings {

    /**
     * Setting ID. Prefixes all options.
     * @var string
     */
    protected $id = '';

    protected $page_title = '';

    protected $menu_title = '';

    /**
     * Settings Fields.
     * @var array
     */
    protected $fields = [];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_page' ] );
        add_action( 'admin_init', [ $this, 'register_fields' ] );
    }

    public function register_page() {
        add_submenu_page(
                'pmpro-dashboard',
            $this->page_title,
            $this->menu_title ?: $this->page_title,
            'manage_options',
            'pmpro_' . $this->id . '_options',
            [ $this, 'render_page']
        );
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <div id="icon-options-general" class="icon32"><br></div>
            <h2><?php echo $this->page_title; ?></h2>

            <form action="options.php" method="post">

                <?php

                do_action( 'pmpro_' . $this->id . '_settings_before_options' );

                settings_fields('pmpro_' . $this->id . '_options' );

                do_settings_sections('pmpro_' . $this->id . '_options' );

                do_action( 'pmpro_' . $this->id . '_settings_before_submit_button' );

                submit_button( __( 'Save Settings', 'pmpro-constantcontact' ) );

                do_action( 'pmpro_' . $this->id . '_settings_after_submit_button' );
                ?>

            </form>
            <?php do_action( 'pmpro_' . $this->id . '_settings_after_form' ); ?>
        </div>
        <?php
    }

    public function get_settings_id() {
        return $this->id;
    }

    public function add_field( $field ) {
        $this->fields = array_merge( $this->fields, $field );
    }

    /**
     * Remove a section regardign this settings.
     *
     * This will remove the section only for the current page load when called.
     *
     *
     * @param $section
     * @return void
     */
    public function remove_section( $section ) {
        global $wp_settings_sections;

        $page = 'pmpro_' . $this->id . '_options';

        if ( ! isset( $wp_settings_sections[ $page ] ) ) {
            return;
        }

        $section = 'pmpro_section_' . $this->id . '_' . $section;

        if ( ! isset( $wp_settings_sections[ $page ][ $section ] ) ) {
            return;
        }

        unset($wp_settings_sections[ $page ][ $section ]);
    }

    /**
     * Remove a section regardign this settings.
     *
     * This will remove the section only for the current page load when called.
     *
     *
     * @param $section
     * @return void
     */
    public function remove_field( $field ) {
        global $wp_settings_fields;

        if ( ! is_array( $field ) ) {
            $key    = $field;
            $fields = $this->get_fields();
            $field  = ! empty( $fields[ $key ] ) ? $fields[ $key ] : false;
        }

        if ( ! $field ) {
            return;
        }

        $page = 'pmpro_' . $this->id . '_options';

        if ( ! isset( $wp_settings_fields[ $page ] ) ) {
            return;
        }

        $section = 'pmpro_section_' . $this->id . '_' . $field['section'];

        if ( ! isset( $wp_settings_fields[ $page ][ $section ] ) ) {
            return;
        }

        $field_id = 'pmpro_option_' . $this->id . '_' . $field['name'];

        if ( ! isset( $wp_settings_fields[ $page ][ $section ][ $field_id] ) ) {
            return;
        }

        unset($wp_settings_fields[ $page ][ $section ][ $field_id ]);
    }

    /**
     * Get Settings Fields.
     *
     * @return mixed|null
     */
    public function get_fields() {
        return apply_filters( 'pmpro_settings_' . $this->id . '_get_fields', $this->fields );
    }

    /**
     * Register Fields
     *
     * @return void
     */
    public function register_fields() {
        $fields = $this->get_fields();

        register_setting(
            'pmpro_' . $this->id . '_options',
            'pmpro_' . $this->id . '_options',
            [
                'sanitize_callback' => [ $this, 'sanitize' ]
            ]
        );

        foreach ( $fields as $field ) {
            if ( 'section' === $field['type'] ) {
                add_settings_section(
                    'pmpro_section_' . $this->id . '_' . $field['name'],
                    $field['title'],
                    [ $this, 'render_section' ],
                    'pmpro_' . $this->id . '_options',
                    $field
                );
            } else {
                add_settings_field(
                    'pmpro_option_' . $this->id . '_' . $field['name'],
                    $field['title'],
                    [ $this, 'render_field' ],
                    'pmpro_' . $this->id . '_options',
                    'pmpro_section_' . $this->id . '_' . $field['section'],
                    $field
                );
            }
        }
    }

    public function render_section( $args ) {
        if ( ! empty( $args['description'] ) ) {
            ?>
            <p class="description"><?php echo wp_kses_post( $args['description'] ); ?></p>
            <?php
        }
    }

    /**
     * Get option key. Useful for name attributes in forms.
     *
     * @param string $key Field Name.
     * @return string
     */
    public function get_option_key( $key ) {
        return 'pmpro_' . $this->id . '_options[' . $key . ']';
    }

    /**
     * Get Option
     *
     * @param string $id Field Name
     * @param mixed  $default Default value if we don't have anything saved.
     * @return mixed|string
     */
    public function get_option( $id, $default = '' ) {
        $options = get_option( 'pmpro_' . $this->id . '_options' );

        return isset( $options[ $id ] ) ? $options[ $id ] : $default;
    }

    /**
     * Render Field
     * @param array $args Field Arguments.
     * @return void
     */
    public function render_field( $args ) {
        $this->{'render_' . $args['type'] }( $args );
    }

    /**
     * Render Text input
     *
     * @param $args
     * @return void
     */
    public function render_text( $args ) {
        $default = ! empty( $args['default'] ) ? $args['default'] : '';
        ?>
        <input type="text" class="widefat" name="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>" value="<?php echo esc_attr( $this->get_option( $args['name'], $default ) ); ?>" />
        <?php
    }

    /**
     * Render Checkbox input
     *
     * @param $args
     * @return void
     */
    public function render_checkbox( $args ) {
        $option_checked = $this->get_option($args['name']);
        ?>
        <label for="<?php echo esc_attr($this->get_option_key($args['name']));  ?>">
            <input <?php checked( $option_checked, 1, true ); ?> id="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>" type="checkbox" name="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>" value="1" />
            <?php echo esc_html( $args['description'] ); ?>
        </label>
        <?php
    }

    /**
     * Render Checkbox input
     *
     * @param $args
     * @return void
     */
    public function render_multi_checkbox( $args ) {
        $selected_values = $this->get_option($args['name']);
        if ( ! is_array( $selected_values ) ) {
            $selected_values = array( $selected_values );
        }

         if ( ! empty( $args['description'] ) ) { ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php
            }
        ?>
        <div class="pmpro-ac-scrollable">
            <?php
            foreach ( $args['options'] as $option_value => $option_text ) {
                $selected = in_array( $option_value, $selected_values );
                ?>
                <p>
                    <label for="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_<?php echo esc_attr( $option_value ); ?>">
                        <input
                                id="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>_<?php echo esc_attr( $option_value ); ?>"
                            <?php checked( $selected ); ?>
                                type="checkbox"
                                name="<?php echo esc_attr( $this->get_option_key( $args['name'] ) ); ?>[]"
                                value="<?php echo esc_attr( $option_value ); ?>"
                        />
                        <?php echo esc_html( $option_text ); ?>
                    </label>
                </p>
                <?php
            }
            ?>
        </div>
        <?php
    }

    public function sanitize( $input ) {
        $fields = $this->get_fields();

        foreach ( $fields as $field ) {
            // No need to sanitize sections.
            if ( 'section' === $field['type'] ) {
                continue;
            }

            if ( ! isset( $input[ $field['name'] ] ) ) {
                continue;
            }

            switch ( $field['type'] ) {
                case 'text':
                    $input[ $field['name'] ] = sanitize_text_field( $input[ $field['name'] ] );
                    break;
            }
        }

        do_action( 'pmpro_' . $this->id . '_settings_sanitized', $input, $fields, $_POST, $this );

        return $input;
    }
}