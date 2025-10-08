<?php
/*
 * Plugin Name:     Ultimate Member - Redirect Homepage
 * Description:     Extension to Ultimate Member for WP redirect and WP error logging
 * Version:         1.1.0
 * Author:          Miss Veronica
 * License:         GPL v3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Author URI:      https://github.com/MissVeronica
 * Plugin URI:      https://github.com/MissVeronica/um-redirect-homepage
 * Update URI:      https://github.com/MissVeronica/um-redirect-homepage
 * Text Domain:     ultimate-member
 * Domain Path:     /languages
 * UM version:      2.10.6
*/

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! class_exists( 'UM' ) ) return;

class UM_Redirect_Homepage {

    public $trace_plugins = array( 'ultimate-member' );

    function __construct() {

        define( 'Plugin_Basename_RH', plugin_basename( __FILE__ ));

        if( is_admin() && ! defined( 'DOING_AJAX' )) {
            add_filter( 'um_settings_structure', array( $this, 'um_settings_structure' ), 10, 1 );
            add_filter( 'plugin_action_links_' . Plugin_Basename_RH, array( $this, 'settings_link' ), 10 );
        }

        if ( UM()->options()->get( 'um_redirect_homepage_activation' ) == 1 ) {
            add_action( 'wp_error_added',                            array( $this, 'wp_redirect_custom_log' ), 10, 3 );
            add_filter( 'x_redirect_by',                             array( $this, 'wp_redirect_custom_log' ), 10, 3 );
            add_filter( "um_profile_default_homepage_empty__filter", array( $this, 'um_profile_default_homepage_empty_student_fix' ), 999, 1 );
            add_filter( "um_profile_default_homepage__filter",       array( $this, 'um_profile_default_homepage_student_fix' ), 999, 1 );
            add_filter( 'wp_php_error_args',                         array( $this, 'wp_php_error_backtrace' ), 10, 2 );
            add_filter( 'wp_should_handle_php_error',                array( $this, 'wp_php_error_backtrace' ), 10, 2 );
            add_action( 'wp_trigger_error_run',                      array( $this, 'wp_trigger_error_run_backtrace' ), 10, 3 );

            $plugins = array_map( 'sanitize_text_field', UM()->options()->get( 'um_redirect_homepage_plugins' ));
            $this->trace_plugins = array_merge( $plugins, $this->trace_plugins );
        }
    }

    public function settings_link( $links ) {

        $url = get_admin_url() . 'admin.php?page=um_options&tab=access&section=other';
        $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings' ) . '</a>';

        return $links;
    }

    public function um_profile_default_homepage_empty_student_fix( $bool ) {

        $this->write_debug_log( 'empty_filter hook bool:' . absint( $bool ));
        return $bool;
    }

    public function um_profile_default_homepage_student_fix( $bool ) {

        $this->write_debug_log( 'filter hook bool:' . absint( $bool ));
        return $bool;
    }

    public function wp_redirect_custom_log( $x_redirect_by, $location, $status ) {

        if ( is_numeric( $location )) {
            $output = "redirect by: {$x_redirect_by}, {$location}, {$status}";

        } else {

            if ( $x_redirect_by == 'internal_server_error' ) {

                $output = "WP error code: {$x_redirect_by}";

            } else {

                if ( is_array( $status )) {
                    foreach ( $status as &$arg ) {
                        $arg = is_array( $arg ) ? implode( ', ', $arg ) : $arg;
                    }
                }

                $output  = "WP error code: {$x_redirect_by} message: {$location}";
                $status  = is_array( $status ) ? implode( ', ', $status ) : $status;
                if ( ! is_object( $status )) {
                    $output .= empty( $status ) ? '' : " data: {$status}";
                } else {
                    $output .= ' OBJECT ';
                    global $um_html_view_function;
	                $um_html_view_function->debug_cpu_update_profile( $status, __FUNCTION__, 'STATUS', basename( $_SERVER['PHP_SELF'] ), __line__ );
                }

                $traces = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT );
                foreach ( $traces as $trace ) {

                    if ( isset( $trace['file'] ) && ! str_contains( $trace['file'], 'um-redirect-homepage' ) && str_contains( $trace['file'], '/plugins/' )) {

                        $file = explode( '/plugins/', $trace['file'] );
                        if ( isset( $file[1] )) {
                            $trace['line']     = isset( $trace['line'] )     ? $trace['line'] : '';
                            $trace['function'] = isset( $trace['function'] ) ? $trace['function'] . '(...)' : '';
                            $output .= " plugin: {$file[1]}:{$trace['line']} {$trace['function']}";
                        }
                        break;
                    }
                }
            }
        }

        $this->write_debug_log( $output );

        return $x_redirect_by;
    }

    public function wp_php_error_backtrace( $args, $error ) {

        $output = date_i18n( 'Y-m-d H:i:s ', current_time( 'timestamp' )) . $this->maybe_implode_with_keys( $error, 'Error' ) . chr(13);

        $traces = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT );
        foreach ( $traces as $trace ) {
            if ( isset( $trace['file'] )) {
                $trace['line']     = isset( $trace['line'] )     ? $trace['line'] : '';
                $trace['function'] = isset( $trace['function'] ) ? $trace['function'] . '(...)' : '';
                $output .= "{$trace['file']}:{$trace['line']} {$trace['function']}" . chr(13);
            }
        }

        file_put_contents( WP_CONTENT_DIR . '/debug.log', $this->hide_site_url( $output ) . chr(13), FILE_APPEND  );

        return $args;
    }

    public function wp_trigger_error_run_backtrace( $function_name, $message, $error_level ) {

        $this->wp_php_error_backtrace( array(), $function_name );
    }

    public function get_debug_backtrace() {

        $traces = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT );
        $plugin_trace = array();

        foreach ( $traces as $trace ) {

            if( isset( $trace['file'] ) && str_contains( $trace['file'], '/plugins/' )) {

                $file   = explode( '/plugins/', $trace['file'] );
                $plugin = explode( '/', $file[1] );

                if ( ! str_contains( $plugin[0], 'um-redirect-homepage' )) {
                    if ( in_array( $plugin[0], $this->trace_plugins ) || str_contains( $plugin[0], 'um-' )) {

                        $args = '():';
                        if ( is_array( $trace['args'] )) {
                            foreach ( $trace['args'] as &$arg ) {

                                if ( is_object( $arg )) {
                                    $arg = 'CLASS ' . get_class( $arg );
                                }

                                if ( is_array( $arg )) {
                                    $arg = ! $this->multi_dim_array( $arg ) ? implode( ', ', $arg ) : '...';
                                }
                            }

                            $args = '(' . implode( ', ', $trace['args'] ) . '):';
                        }

                        $plugin_trace[] = $file[1] . $args . $trace['line'];
                    }
                }
            }
        }

        return $plugin_trace;
    }

    public function write_debug_log( $debug_message ) {

        global $current_user;

        $trace = date_i18n( 'Y-m-d H:i:s ', current_time( 'timestamp' ));

        if ( ! empty( $current_user->ID )) {

            $roleID      = UM()->roles()->get_priority_user_role( $current_user->ID );
            $option_name = 'um_role_' . str_replace( 'um_', '', $roleID ) . '_meta';
            $um_role     = get_option( $option_name );
            $priority    = empty( $um_role['_um_priority'] ) ? '-' : $um_role['_um_priority'];
            $trace      .= "user_id: {$current_user->ID} role: {$roleID}:{$priority}";
        } else {
            $trace      .= 'no user logged in';
        }

        $trace .= " {$debug_message}";

        $plugin_trace = $this->get_debug_backtrace();

        $trace .= $this->maybe_implode_with_keys( $plugin_trace, 'plugin backtrace' );
        $trace .= $this->maybe_implode_with_keys( $_REQUEST, 'REQ' );

        file_put_contents( WP_CONTENT_DIR . '/debug.log', $this->hide_site_url( $trace ) . chr(13), FILE_APPEND );
    }

    public function hide_site_url( $text ) {

        $site_url = str_replace( array( 'http://', 'https://' ), '', site_url());
        return str_replace( array( ABSPATH, $site_url ), array( 'ABSPATH/', 'site_url' ), urldecode( $text ));
    }

    public function maybe_implode_with_keys( $array, $message = '' ) {

        if ( is_array( $array )) {
            if ( ! empty( $array )) {

                if ( is_numeric( array_key_first( $array )) && is_numeric( array_key_last( $array ))) {
                    return " {$message}: " . implode( ',', $array );
                }

                $req = array();
                foreach( $array as $key => $arg ) {

                    if ( is_array( $arg )) {
                        $arg = '(' . ( ! $this->multi_dim_array( $arg ) ? implode( ',', $arg ) : '...' ) . ')';
                    }

                    $req[] = $key . '=>' . $arg;
                }

                return " {$message}: " . ( ! $this->multi_dim_array( $req ) ? implode( ',', $req ) : '...' );
            }

        } else {

            if ( ! empty( $array )) {
                return " {$message}: " . $array;
            }
        }

        return '';
    }

    public function multi_dim_array( $arg ) {

        $multi_dim = false;
        foreach( $arg as $value ) {
            if ( is_array( $value )) {
                $multi_dim = true;
            }
        }

        return $multi_dim;
    }

    public function um_settings_structure( $settings_structure ) {

        $plugins        = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $plugin_list    = array();

        foreach ( $active_plugins as $plugin_path ) {

            $folder = explode( '/', $plugin_path );
            if ( isset( $folder[0] )) {
                if ( substr( $folder[0], 0, 3 ) == 'um-' || in_array( $folder[0], array( 'ultimate-member' ))) {
                    continue;
                }

                if ( isset( $plugins[$plugin_path]['Name'] )) {
                    $plugin_list[$folder[0]] = $plugins[$plugin_path]['Name'];
                }
            }
        }

        asort( $plugin_list );
        $prefix = '&nbsp; * &nbsp;';
        $plugin_data = get_plugin_data( __FILE__ );

        $settings_structure['access']['sections']['other']['form_sections']['redirect_homepage']['title']       = esc_html__( 'Redirect Homepage', 'ultimate-member' );
        $settings_structure['access']['sections']['other']['form_sections']['redirect_homepage']['description'] = sprintf( esc_html__( 'Plugin version %s - tested with UM 2.10.6', 'ultimate-member' ), $plugin_data['Version'] );

        $settings_structure['access']['sections']['other']['form_sections']['redirect_homepage']['fields'][] = array(
                        'id'             => 'um_redirect_homepage_activation',
                        'type'           => 'checkbox',
                        'label'          => $prefix . esc_html__( 'Activate ', 'ultimate-member' ),
                        'checkbox_label' => esc_html__( 'Click to activate the Redirect Homepage plugin.', 'ultimate-member' ),
                    );

        $settings_structure['access']['sections']['other']['form_sections']['redirect_homepage']['fields'][] = array(
                        'id'             => 'um_redirect_homepage_plugins',
                        'type'           => 'select',
                        'multi'          => true,
                        'options'        => $plugin_list,
                        'label'          => $prefix . esc_html__( 'Active Plugins to include', 'ultimate-member' ),
                        'description'    => esc_html__( 'Select single or multiple Plugins for tracing of stack calls. Ultimate Member and the UM extensions are always included.', 'ultimate-member' ),
                        'conditional'    => array( 'um_redirect_homepage_activation', '=', 1 ),
                    );

        return $settings_structure;
    }
}

new UM_Redirect_Homepage();


