<?php
/*
 * Plugin Name:     Ultimate Member - Redirect Homepage
 * Description:     Extension to Ultimate Member for WP redirect and WP error logging
 * Version:         1.0.0 beta
 * Author:          Miss Veronica
 * License:         GPL v3 or later
 * License URI:     https://www.gnu.org/licenses/gpl-2.0.html
 * Author URI:      https://github.com/MissVeronica
 * Plugin URI:      https://github.com/MissVeronica/um-redirect-homepage
 * Update URI:      https://github.com/MissVeronica/um-redirect-homepage
 * Text Domain:     ultimate-member
 * Domain Path:     /languages
 * UM version:      2.10.5
*/

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! class_exists( 'UM' ) ) return;

class UM_Redirect_Homepage {

    public $trace_plugins = array( 'ultimate-member', 'lifterlms' );

    function __construct() {

        add_action( 'wp_error_added',                            array( $this, 'wp_redirect_custom_log' ), 10, 3 );
        add_filter( 'x_redirect_by',                             array( $this, 'wp_redirect_custom_log' ), 10, 3 );
        add_filter( "um_profile_default_homepage_empty__filter", array( $this, 'um_profile_default_homepage_empty_student_fix' ), 999, 1 );
        add_filter( "um_profile_default_homepage__filter",       array( $this, 'um_profile_default_homepage_student_fix' ), 999, 1 );
        add_filter( 'wp_php_error_args',                         array( $this, 'wp_php_error_backtrace' ), 10, 2 );
        add_filter( 'wp_should_handle_php_error',                array( $this, 'wp_php_error_backtrace' ), 10, 2 );
        add_action( 'wp_trigger_error_run',                      array( $this, 'wp_trigger_error_run_backtrace' ), 10, 3 );
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

                $text    = is_array( $status ) ? implode( ', ', $status ) : $status;
                $output  = "WP error code: {$x_redirect_by} message: {$location}";
                $output .= empty( $text ) ? '' : " data: {$text}";

                $traces = debug_backtrace( DEBUG_BACKTRACE_PROVIDE_OBJECT );
                foreach ( $traces as $trace ) {

                    if ( ! str_contains( $trace['file'], 'um-redirect-homepage' ) &&  str_contains( $trace['file'], '/plugins/' )) {
                        $file    = explode( '/plugins/', $trace['file'] );
                        $output .= " plugin: {$file[1]}:{$trace['line']} {$trace['function']}()";
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

            if( isset( $trace['file'] ) && strpos( $trace['file'], '/plugins/' ) > 0 ) {

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

                                    $multi_dim = false;
                                    foreach( $arg as $value ) {
                                        if ( is_array( $value )) {
                                            $multi_dim = true;
                                        }
                                    }

                                    $arg = ! $multi_dim ? implode( ', ', $arg ) : '';
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

                if ( is_numeric( array_key_first( $array )) &&  is_numeric( array_key_last( $array ))) {
                    return " {$message}: " . implode( ',', $array ); 
                }

                $req = array();
                foreach( $array as $key => $arg ) {

                    if ( is_array( $arg )) {
                        $arg = '(' . implode( ',', $arg ) . ')';
                    }

                    $req[] = $key . '=>' . $arg;
                }

                return " {$message}: " . implode( ',', $req );
            }

        } else {

            if ( ! empty( $array )) { 
                return " {$message}: " . $array;
            }
        }

        return '';
    }


}

new UM_Redirect_Homepage();

