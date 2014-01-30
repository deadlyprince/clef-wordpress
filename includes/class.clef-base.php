<?php
    class LoginException extends Exception {}

    class ClefBase {

        const MS_ENABLED_OPTION = "clef_multisite_enabled";
        const MS_ALLOW_OVERRIDE_OPTION = 'clef_multsite_allow_override';
        const MS_OVERRIDE_OPTION = 'clef_multisite_override';

        private static $_individual_settings = null;
        private static $_settings = null;

        public static function setting( $name , $value=false, $refresh=false) {

            if (self::individual_settings()) {
                $getter = 'get_option';
                $setter = 'update_option';
            } else {
                $getter = 'get_site_option';
                $setter = 'update_site_option';
            }

            if ($refresh || !self::$_settings) {
                self::$_settings = $getter( CLEF_OPTIONS_NAME );
            } 

            if ($value) {
                
                self::$_settings[$name] = $value;

                $setter(CLEF_OPTIONS_NAME, self::$_settings);
                return $value;
            } else {
                if ( isset( self::$_settings[$name] ) ) {
                    return self::$_settings[$name];
                }
            }

            // Fall-through
            return FALSE;
        }

        public static function delete_setting($name) {
            if (self::individual_settings()) {
                $getter = 'get_option';
                $setter = 'update_option';
            } else {
                $getter = 'get_site_option';
                $setter = 'update_site_option';
            }

            if (!self::$_settings) {
                self::$_settings = $getter( CLEF_OPTIONS_NAME );
            }

            if (isset(self::$_settings[$name])) {
                $value = self::$_settings[$name];
                unset(self::$_settings[$name]);
                $setter(CLEF_OPTIONS_NAME, self::$_settings);
                return $value;
            }
            
            return FALSE;
        }

        /**
        * Function to check whether site's settings are controlled by 
        * multisite or not.
        **/
        public static function individual_settings() {
            if (!self::is_multisite_enabled()) return true;

            if (is_null(self::$_individual_settings)) {
                // we cache this check, this solves an issue on the first update
                $override = false;

                if (get_site_option(self::MS_ALLOW_OVERRIDE_OPTION)) {
                    $override = get_option(self::MS_OVERRIDE_OPTION, 'undefined');

                    // check to see whether the override is set (it would not be set
                    // if the blog had previously been used without multisite 
                    // enabled). sets it if it is null.
                    if ($override == "undefined") {
                        $override = !!get_option(CLEF_OPTIONS_NAME);
                        add_option(self::MS_OVERRIDE_OPTION, $override);
                    }

                }

                self::$_individual_settings = $override && !is_network_admin();
            } 
            return self::$_individual_settings;

        }

        public static function multisite_disallow_settings_override() {
            return self::is_multisite_enabled() && !get_site_option(self::MS_ALLOW_OVERRIDE_OPTION);
        }

        public static function exchange_oauth_code_for_info($code, $app_id=false, $app_secret=false) {
            if (!$app_id) $app_id = self::setting( 'clef_settings_app_id' );
            if (!$app_secret) $app_secret = self::setting( 'clef_settings_app_secret' );

            $args = array(
                'code' => $code,
                'app_id' => $app_id,
                'app_secret' => $app_secret,
            );

            $response = wp_remote_post( CLEF_API_BASE . 'authorize', array( 'method'=> 'POST', 'body' => $args, 'timeout' => 20 ) ); 

            if ( is_wp_error($response)  ) {
                throw new LoginException(__( "Something went wrong: ", 'clef' ) . $response->get_error_message());
                // $_SESSION['Clef_Messages'][] = ;
                // self::redirect_error();
            }

            $body = json_decode( $response['body'] );

            if ( !isset($body->success) || $body->success != 1 ) {
                throw new LoginException(__( 'Error retrieving Clef access token: ', 'clef') . $body->error);
            }

            $access_token = $body->access_token;
            $_SESSION['wpclef_access_token'] = $access_token;

            // Get info
            $response = wp_remote_get( CLEF_API_BASE . "info?access_token={$access_token}" );
            if ( is_wp_error($response)  ) {
                throw new LoginException(__( "Something went wrong: ", 'clef' ) . $response->get_error_message());
            }

            $body = json_decode( $response['body'] );

            if ( !isset($body->success) || $body->success != 1 ) {
                throw new LoginException(__('Error retrieving Clef user data: ', 'clef')  . $body->error);
            }

            return $body->info;
        }

        public static function redirect_error() {
            if (!is_user_logged_in()) {
                header( 'Location: ' . wp_login_url() );
            } else {
                header( 'Location: ' . get_edit_profile_url(wp_get_current_user()->ID));
            }
            exit();
        }

        public static function associate_clef_id($clef_id, $user_id=false) {
            if (!$user_id) {
                $user_id = wp_get_current_user()->ID;
            }

            update_user_meta($user_id, 'clef_id', $clef_id);
        }

        protected static function dissociate_clef_id($user_id=false) {
            if (!$user_id) {
                $user_id = wp_get_current_user()->ID;
            }
            
            delete_user_meta($user_id, "clef_id");
        }

        public static function table_name($tablename) {
            global $wpdb;
            return $wpdb->prefix . $tablename;
        }

        protected static function is_multisite_enabled() {
            return is_plugin_active_for_network('wpclef/wpclef.php') && get_site_option(self::MS_ENABLED_OPTION);
        }

        public static function bruteprotect_active() {
            return in_array( 'bruteprotect/bruteprotect.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) );
        }

        public static function passwords_disabled() {
            return self::setting('clef_password_settings_disable_passwords') 
                || self::setting('clef_password_settings_force') 
                || self::setting('clef_password_settings_disable_certain_passwords') != "Disabled";
        }

        public static function xml_passwords_enabled() {
            return !self::passwords_disabled() || (self::passwords_disabled() && self::setting('clef_password_settings_xml_allowed'));
        }
    }
?>