<?php
/**
 * Plugin Name: Festival ID Tracker
 * Plugin URI:  https://github.com/Clustmart/festival-id-tracker-wp-plugin
 * Description: Tracks URL calls with an 'id' parameter (e.g., ?id=XXXXXX) and displays daily statistics and per-ID statistics in the WordPress dashboard. Includes redirect functionality.
 * Version:     1.5.0
 * Author:      Paul Wasicsek / Digital Travel Guide
 * Author URI:  https://vernissaria.de/
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: festival-id-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'FESTIDTRACK_VERSION', '1.5.0' ); // Updated version with unique prefix
define( 'FESTIDTRACK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FESTIDTRACK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Activation Hook: Create database table on plugin activation.
 */
function festidtrack_activate_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'festidtrack_log';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id INT NOT NULL AUTO_INCREMENT,
        festival_id VARCHAR(10) NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        user_hash VARCHAR(32) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        PRIMARY KEY (id),
        KEY festival_id_idx (festival_id),
        KEY timestamp_idx (timestamp),
        KEY user_hash_idx (user_hash)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    update_option( 'festidtrack_db_version', FESTIDTRACK_VERSION );
    
    // Add default settings
    add_option( 'festidtrack_redirect_url', '' );
    add_option( 'festidtrack_redirect_enabled', false );
}
register_activation_hook( __FILE__, 'festidtrack_activate_plugin' );

/**
 * Check rate limiting for tracking requests.
 * 
 * @param string $ip The IP address to check.
 * @return bool True if within rate limit, false if exceeded.
 */
function festidtrack_check_rate_limit( $ip ) {
    $transient_key = 'festidtrack_rate_' . md5( $ip );
    $attempts = get_transient( $transient_key );
    
    if ( false === $attempts ) {
        set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
        return true;
    }
    
    // Allow maximum 10 requests per minute per IP
    if ( $attempts >= 10 ) {
        return false; // Rate limit exceeded
    }
    
    set_transient( $transient_key, $attempts + 1, MINUTE_IN_SECONDS );
    return true;
}

/**
 * Simple bot detection based on user agent.
 * 
 * @return bool True if likely a bot, false otherwise.
 */
function festidtrack_is_likely_bot() {
    if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
        return true; // No user agent is suspicious
    }
    
    $user_agent = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );
    
    // Common bot patterns
    $bot_patterns = array(
        'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget', 
        'python', 'java', 'ruby', 'go-http', 'postman'
    );
    
    foreach ( $bot_patterns as $pattern ) {
        if ( strpos( $user_agent, $pattern ) !== false ) {
            return true;
        }
    }
    
    return false;
}

/**
 * Checks for the 'id' query parameter, logs the call, and handles redirect if configured.
 * 
 * Note: This function intentionally does not use nonces as it tracks anonymous public visitors
 * similar to analytics services. Rate limiting and bot detection are used instead for security.
 */
function festidtrack_track_festival_id_call() {
    // Only process GET requests on frontend
    // SECURITY NOTE: No nonce check here as this is public-facing anonymous tracking
    // Similar to Google Analytics or other tracking pixels that work without user authentication
    // We use rate limiting and bot detection instead for security
    if ( ! is_admin() && isset( $_GET['id'] ) && preg_match( '/^[a-zA-Z0-9]{6}$/', sanitize_text_field( wp_unslash( $_GET['id'] ) ) ) ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'festidtrack_log';
        $festival_id = sanitize_text_field( wp_unslash( $_GET['id'] ) );

        $user_ip = festidtrack_get_client_ip();
        
        // Check rate limiting
        if ( ! festidtrack_check_rate_limit( $user_ip ) ) {
            // Rate limit exceeded, silently ignore
            return;
        }
        
        // Check for bots
        if ( festidtrack_is_likely_bot() ) {
            // Likely a bot, skip tracking
            return;
        }
        
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown';
        $daily_salt = gmdate( 'Ymd' );
        $user_hash = md5( $user_ip . $user_agent . $daily_salt );

        // Log the call
        $wpdb->insert(
            $table_name,
            array(
                'festival_id' => $festival_id,
                'user_hash'   => $user_hash,
                'ip_address'  => $user_ip,
            ),
            array(
                '%s',
                '%s',
                '%s',
            )
        );

        // Handle redirect if configured
        $redirect_enabled = get_option( 'festidtrack_redirect_enabled', false );
        $redirect_url = get_option( 'festidtrack_redirect_url', '' );
        
        if ( $redirect_enabled && ! empty( $redirect_url ) ) {
            // Add the festival_id to the redirect URL
            $redirect_url_with_id = add_query_arg( 'id', $festival_id, $redirect_url );
            
            // Perform the redirect
            wp_redirect( $redirect_url_with_id, 302 );
            exit;
        }
    }
}
add_action( 'wp', 'festidtrack_track_festival_id_call' );

/**
 * Gets the real client IP address, handling proxies.
 * @return string
 */
function festidtrack_get_client_ip() {
    $ip = 'UNKNOWN';
    if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
    } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
        $ips = explode( ',', $ip );
        $ip = trim( $ips[0] );
    } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
    }
    return sanitize_text_field( $ip );
}

/**
 * Sanitization callback for redirect enabled setting.
 * 
 * @param mixed $input The input value to sanitize.
 * @return bool The sanitized boolean value.
 */
function festidtrack_sanitize_redirect_enabled( $input ) {
    return ! empty( $input ) ? true : false;
}

/**
 * Sanitization callback for redirect URL setting.
 * 
 * @param mixed $input The input value to sanitize.
 * @return string The sanitized URL.
 */
function festidtrack_sanitize_redirect_url( $input ) {
    if ( empty( $input ) ) {
        return '';
    }
    
    $url = esc_url_raw( $input );
    
    // Additional validation: ensure it's a valid URL
    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        add_settings_error(
            'festidtrack_redirect_url',
            'invalid_url',
            /* translators: Error message shown when user enters invalid URL */
            __( 'Please enter a valid URL for the redirect destination.', 'festival-id-tracker' ),
            'error'
        );
        return get_option( 'festidtrack_redirect_url', '' ); // Return previous value on error
    }
    
    return $url;
}

/**
 * Add admin menu for plugin settings.
 */
function festidtrack_add_admin_menu() {
    add_options_page(
        /* translators: Page title for plugin settings */
        __( 'Festival ID Tracker Settings', 'festival-id-tracker' ),
        /* translators: Menu title for plugin in admin */
        __( 'Festival ID Tracker', 'festival-id-tracker' ),
        'manage_options',
        'festival-id-tracker',
        'festidtrack_settings_page'
    );
}
add_action( 'admin_menu', 'festidtrack_add_admin_menu' );

/**
 * Initialize plugin settings.
 */
function festidtrack_settings_init() {
    register_setting( 
        'festidtrack_settings', 
        'festidtrack_redirect_enabled',
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'festidtrack_sanitize_redirect_enabled',
            'default' => false,
        )
    );
    
    register_setting( 
        'festidtrack_settings', 
        'festidtrack_redirect_url',
        array(
            'type' => 'string',
            'sanitize_callback' => 'festidtrack_sanitize_redirect_url',
            'default' => '',
        )
    );

    add_settings_section(
        'festidtrack_redirect_section',
        /* translators: Section title for redirect settings */
        __( 'Redirect Settings', 'festival-id-tracker' ),
        'festidtrack_redirect_section_callback',
        'festidtrack_settings'
    );

    add_settings_field(
        'festidtrack_redirect_enabled',
        /* translators: Label for enable redirect checkbox */
        __( 'Enable Redirect', 'festival-id-tracker' ),
        'festidtrack_redirect_enabled_callback',
        'festidtrack_settings',
        'festidtrack_redirect_section'
    );

    add_settings_field(
        'festidtrack_redirect_url',
        /* translators: Label for redirect URL field */
        __( 'Redirect URL', 'festival-id-tracker' ),
        'festidtrack_redirect_url_callback',
        'festidtrack_settings',
        'festidtrack_redirect_section'
    );
}
add_action( 'admin_init', 'festidtrack_settings_init' );

/**
 * Redirect section description callback.
 */
function festidtrack_redirect_section_callback() {
    echo '<p>' . esc_html( 
        /* translators: Description text for redirect settings section */
        __( 'Configure redirect behavior for URLs with the "id" parameter. When enabled, visitors will be redirected to the specified URL with the ID parameter preserved.', 'festival-id-tracker' ) 
    ) . '</p>';
}

/**
 * Redirect enabled field callback.
 */
function festidtrack_redirect_enabled_callback() {
    $enabled = get_option( 'festidtrack_redirect_enabled', false );
    echo '<input type="checkbox" id="festidtrack_redirect_enabled" name="festidtrack_redirect_enabled" value="1" ' . checked( 1, $enabled, false ) . ' />';
    echo '<label for="festidtrack_redirect_enabled">' . esc_html( 
        /* translators: Label text for enable redirect checkbox */
        __( 'Enable automatic redirect when ID parameter is detected', 'festival-id-tracker' ) 
    ) . '</label>';
}

/**
 * Redirect URL field callback.
 */
function festidtrack_redirect_url_callback() {
    $url = get_option( 'festidtrack_redirect_url', '' );
    echo '<input type="url" id="festidtrack_redirect_url" name="festidtrack_redirect_url" value="' . esc_attr( $url ) . '" class="regular-text" placeholder="https://example.com/destination" />';
    echo '<p class="description">' . esc_html( 
        /* translators: Help text for redirect URL field */
        __( 'Enter the full URL where users should be redirected. The ID parameter will be automatically added to this URL. Leave empty to disable redirect.', 'festival-id-tracker' ) 
    ) . '</p>';
    echo '<p class="description"><strong>' . esc_html( 
        /* translators: Example label */
        __( 'Example:', 'festival-id-tracker' ) 
    ) . '</strong> ' . esc_html( 
        /* translators: Example description showing how redirect works */
        __( 'If you enter "https://example.com/festival" and someone visits your site with "?id=ABC123", they will be redirected to "https://example.com/festival?id=ABC123"', 'festival-id-tracker' ) 
    ) . '</p>';
}

/**
 * Settings page content.
 */
function festidtrack_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Check if settings were updated - WordPress handles the nonce for settings forms automatically
    // The settings API includes its own nonce verification, so we don't need to add it manually
    if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
        add_settings_error( 'festidtrack_messages', 'festidtrack_message', esc_html( 
            /* translators: Success message when settings are saved */
            __( 'Settings saved successfully!', 'festival-id-tracker' ) 
        ), 'updated' );
    }

    settings_errors( 'festidtrack_messages' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        
        <div class="notice notice-info">
            <p><strong><?php echo esc_html( 
                /* translators: Header text explaining how the plugin works */
                __( 'How it works:', 'festival-id-tracker' ) 
            ); ?></strong></p>
            <ul style="margin-left: 20px;">
                <li><?php echo esc_html( 
                    /* translators: First bullet point explaining plugin functionality */
                    __( '• When someone visits your site with an ID parameter (e.g., yoursite.com?id=ABC123)', 'festival-id-tracker' ) 
                ); ?></li>
                <li><?php echo esc_html( 
                    /* translators: Second bullet point explaining plugin functionality */
                    __( '• The plugin logs this visit for tracking purposes', 'festival-id-tracker' ) 
                ); ?></li>
                <li><?php echo esc_html( 
                    /* translators: Third bullet point explaining plugin functionality */
                    __( '• If redirect is enabled, the visitor is automatically sent to your specified URL', 'festival-id-tracker' ) 
                ); ?></li>
                <li><?php echo esc_html( 
                    /* translators: Fourth bullet point explaining plugin functionality */
                    __( '• The ID parameter is preserved in the redirect URL', 'festival-id-tracker' ) 
                ); ?></li>
            </ul>
        </div>

        <form action="options.php" method="post">
            <?php
            settings_fields( 'festidtrack_settings' );
            do_settings_sections( 'festidtrack_settings' );
            /* translators: Button text to save plugin settings */
            submit_button( __( 'Save Settings', 'festival-id-tracker' ) );
            ?>
        </form>

        <div class="card" style="margin-top: 20px;">
            <h2><?php echo esc_html( 
                /* translators: Section title for current statistics */
                __( 'Current Statistics', 'festival-id-tracker' ) 
            ); ?></h2>
            <?php festidtrack_display_quick_stats(); ?>
        </div>

        <div class="card" style="margin-top: 20px;">
            <h2><?php echo esc_html( 
                /* translators: Section title for testing configuration */
                __( 'Testing Your Configuration', 'festival-id-tracker' ) 
            ); ?></h2>
            <p><?php echo esc_html( 
                /* translators: Introduction text for testing instructions */
                __( 'To test your redirect configuration:', 'festival-id-tracker' ) 
            ); ?></p>
            <ol>
                <li><?php echo esc_html( 
                    /* translators: First step in testing instructions */
                    __( 'Save your settings above', 'festival-id-tracker' ) 
                ); ?></li>
                <li><?php echo esc_html( 
                    /* translators: Second step in testing instructions */
                    __( 'Visit your site with a test ID:', 'festival-id-tracker' ) 
                ); ?> 
                    <code><?php echo esc_url( home_url( '?id=TEST01' ) ); ?></code>
                </li>
                <li><?php echo esc_html( 
                    /* translators: Third step in testing instructions */
                    __( 'You should be redirected to your configured URL with the ID parameter', 'festival-id-tracker' ) 
                ); ?></li>
            </ol>
        </div>
    </div>
    <?php
}

/**
 * Display quick statistics on the settings page.
 */
function festidtrack_display_quick_stats() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'festidtrack_log';

    // Check if we should force refresh (via URL parameter for admins)
    $force_refresh = isset( $_GET['festidtrack_refresh_stats'] ) && current_user_can( 'manage_options' );
    
    if ( $force_refresh ) {
        // Clear all caches
        delete_transient( 'festidtrack_total_calls_' . md5( $table_name ) );
        delete_transient( 'festidtrack_unique_ids_' . md5( $table_name ) );
        wp_cache_delete( 'total_calls', 'festidtrack_cache' );
        wp_cache_delete( 'unique_ids', 'festidtrack_cache' );
        wp_cache_delete( 'today_calls', 'festidtrack_cache' );
    }

    // Use transients for caching expensive queries
    $cache_key_total = 'festidtrack_total_calls_' . md5( $table_name );
    $total_calls = get_transient( $cache_key_total );
    if ( false === $total_calls ) {
        $total_calls = wp_cache_get( 'total_calls', 'festidtrack_cache' );
        if ( false === $total_calls ) {
            // Fixed query - removed unnecessary WHERE clause and use proper table reference
            $total_calls = $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name`" );
            if ( $total_calls === null ) {
                $total_calls = 0;
            }
            wp_cache_set( 'total_calls', $total_calls, 'festidtrack_cache', HOUR_IN_SECONDS );
        }
        set_transient( $cache_key_total, $total_calls, HOUR_IN_SECONDS );
    }

    $cache_key_unique = 'festidtrack_unique_ids_' . md5( $table_name );
    $unique_ids = get_transient( $cache_key_unique );
    if ( false === $unique_ids ) {
        $unique_ids = wp_cache_get( 'unique_ids', 'festidtrack_cache' );
        if ( false === $unique_ids ) {
            // Fixed query - removed unnecessary WHERE clause and use proper table reference
            $unique_ids = $wpdb->get_var( "SELECT COUNT(DISTINCT festival_id) FROM `$table_name`" );
            if ( $unique_ids === null ) {
                $unique_ids = 0;
            }
            wp_cache_set( 'unique_ids', $unique_ids, 'festidtrack_cache', HOUR_IN_SECONDS );
        }
        set_transient( $cache_key_unique, $unique_ids, HOUR_IN_SECONDS );
    }

    $today_calls = wp_cache_get( 'today_calls', 'festidtrack_cache' );
    if ( false === $today_calls ) {
        $today_calls = $wpdb->get_var( $wpdb->prepare( 
            "SELECT COUNT(*) FROM `$table_name` WHERE DATE(timestamp) = %s", 
            current_time( 'Y-m-d' ) 
        ) );
        if ( $today_calls === null ) {
            $today_calls = 0;
        }
        wp_cache_set( 'today_calls', $today_calls, 'festidtrack_cache', MINUTE_IN_SECONDS * 15 );
    }

    echo '<table class="form-table">';
    echo '<tr><th scope="row">' . esc_html( 
        /* translators: Label for total calls tracked statistic */
        __( 'Total Calls Tracked:', 'festival-id-tracker' ) 
    ) . '</th><td>' . esc_html( number_format( (int) $total_calls ) ) . '</td></tr>';
    echo '<tr><th scope="row">' . esc_html( 
        /* translators: Label for unique festival IDs statistic */
        __( 'Unique Festival IDs:', 'festival-id-tracker' ) 
    ) . '</th><td>' . esc_html( number_format( (int) $unique_ids ) ) . '</td></tr>';
    echo '<tr><th scope="row">' . esc_html( 
        /* translators: Label for calls today statistic */
        __( 'Calls Today:', 'festival-id-tracker' ) 
    ) . '</th><td>' . esc_html( number_format( (int) $today_calls ) ) . '</td></tr>';
    echo '</table>';

    $redirect_enabled = get_option( 'festidtrack_redirect_enabled', false );
    $redirect_url = get_option( 'festidtrack_redirect_url', '' );
    
    echo '<h4>' . esc_html( 
        /* translators: Header for redirect configuration status */
        __( 'Current Redirect Configuration:', 'festival-id-tracker' ) 
    ) . '</h4>';
    if ( $redirect_enabled && ! empty( $redirect_url ) ) {
        echo '<span style="color: green;">✓ ' . esc_html( 
            /* translators: Status message when redirect is enabled */
            __( 'Redirect is ENABLED', 'festival-id-tracker' ) 
        ) . '</span><br>';
        echo '<strong>' . esc_html( 
            /* translators: Label for current redirect URL */
            __( 'Redirect URL:', 'festival-id-tracker' ) 
        ) . '</strong> ' . esc_html( $redirect_url );
    } else {
        echo '<span style="color: #666;">○ ' . esc_html( 
            /* translators: Status message when redirect is disabled */
            __( 'Redirect is DISABLED', 'festival-id-tracker' ) 
        ) . '</span>';
    }
    
    // Add refresh link for admins
    if ( current_user_can( 'manage_options' ) ) {
        $refresh_url = add_query_arg( 'festidtrack_refresh_stats', '1', admin_url( 'options-general.php?page=festival-id-tracker' ) );
        echo '<p style="margin-top: 10px;"><a href="' . esc_url( $refresh_url ) . '" class="button button-secondary">' . esc_html__( 'Refresh Statistics', 'festival-id-tracker' ) . '</a></p>';
    }
}

/**
 * Add settings link to plugin actions.
 */
function festidtrack_add_settings_link( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=festival-id-tracker' ) ) . '">' . esc_html( 
        /* translators: Settings link text in plugin list */
        __( 'Settings', 'festival-id-tracker' ) 
    ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'festidtrack_add_settings_link' );

/**
 * Add custom dashboard widgets.
 */
function festidtrack_add_dashboard_widgets() {
    // Widget 1: Daily Call Statistics
    wp_add_dashboard_widget(
        'festidtrack_festival_id_daily_stats',
        /* translators: Dashboard widget title for daily statistics */
        __( 'Festival ID Daily Statistics', 'festival-id-tracker' ),
        'festidtrack_render_daily_dashboard_widget'
    );

    // Widget 2: Individual Festival ID Statistics
    wp_add_dashboard_widget(
        'festidtrack_festival_id_individual_stats',
        /* translators: Dashboard widget title for global statistics */
        __( 'Festival ID Global Statistics', 'festival-id-tracker' ),
        'festidtrack_render_individual_dashboard_widget'
    );
}
add_action( 'wp_dashboard_setup', 'festidtrack_add_dashboard_widgets' );

/**
 * Render the content of the daily dashboard widget.
 */
function festidtrack_render_daily_dashboard_widget() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'festidtrack_log';

    // Verify nonce for dashboard navigation (GET parameters)
    $current_date_param = '';
    if ( isset( $_GET['festidtrack_start_date'] ) ) {
        // Check nonce for security
        if ( ! isset( $_GET['festidtrack_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['festidtrack_nonce'] ) ), 'festidtrack_dashboard_navigation' ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'festival-id-tracker' ) );
        }
        // Also verify user capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'festival-id-tracker' ) );
        }
        $current_date_param = sanitize_text_field( wp_unslash( $_GET['festidtrack_start_date'] ) );
    }
    
    $current_start_timestamp = null;

    try {
        if ( ! empty( $current_date_param ) ) {
            $current_start_timestamp = new DateTime( $current_date_param, new DateTimeZone('UTC') );
        } else {
            $current_start_timestamp = new DateTime( 'now', new DateTimeZone('UTC') );
            $current_start_timestamp->setTime(0, 0, 0);
            $current_start_timestamp->modify('-6 days');
        }
    } catch ( Exception $e ) {
        $current_start_timestamp = new DateTime( 'now', new DateTimeZone('UTC') );
        $current_start_timestamp->setTime(0, 0, 0);
        $current_start_timestamp->modify('-6 days');
    }

    $period_start = clone $current_start_timestamp;
    $period_end = clone $current_start_timestamp;
    $period_end->modify('+6 days');

    // Use caching for dashboard queries
    $cache_key = 'festidtrack_daily_stats_' . md5( $period_start->format('Y-m-d') . $period_end->format('Y-m-d') );
    $results = wp_cache_get( $cache_key, 'festidtrack_cache' );
    
    if ( false === $results ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results( $wpdb->prepare( "
            SELECT
                DATE(timestamp) as day,
                COUNT(*) as total_calls,
                COUNT(DISTINCT festival_id) as unique_ids_count
            FROM `{$table_name}`
            WHERE timestamp BETWEEN %s AND %s
            GROUP BY day
            ORDER BY day ASC
        ", $period_start->format('Y-m-d 00:00:00'), $period_end->format('Y-m-d 23:59:59') ), ARRAY_A );
        
        wp_cache_set( $cache_key, $results, 'festidtrack_cache', HOUR_IN_SECONDS );
    }

    $data_by_day = array();
    $temp_date = clone $period_start;
    for ( $i = 0; $i < 7; $i++ ) {
        $day_string = $temp_date->format( 'Y-m-d' );
        $data_by_day[ $day_string ] = array(
            'total_calls'      => 0,
            'unique_ids_count' => 0,
        );
        $temp_date->modify( '+1 day' );
    }

    foreach ( $results as $row ) {
        if ( isset( $data_by_day[ $row['day'] ] ) ) {
            $data_by_day[ $row['day'] ]['total_calls']      = $row['total_calls'];
            $data_by_day[ $row['day'] ]['unique_ids_count'] = $row['unique_ids_count'];
        }
    }

    // --- Navigation Links with Nonces ---
    $dashboard_url = admin_url( 'index.php' );

    $prev_start_date = clone $period_start;
    $prev_start_date->modify( '-7 days' );
    $prev_link = wp_nonce_url(
        add_query_arg( 'festidtrack_start_date', $prev_start_date->format('Y-m-d'), $dashboard_url ),
        'festidtrack_dashboard_navigation',
        'festidtrack_nonce'
    );

    $next_start_date = clone $period_start;
    $next_start_date->modify( '+7 days' );
    $next_link = wp_nonce_url(
        add_query_arg( 'festidtrack_start_date', $next_start_date->format('Y-m-d'), $dashboard_url ),
        'festidtrack_dashboard_navigation',
        'festidtrack_nonce'
    );

    $check_future_date = clone $period_end;
    $check_future_date->modify('+1 day');
    
    $future_cache_key = 'festidtrack_future_data_' . md5( $check_future_date->format('Y-m-d') );
    $has_future_data = wp_cache_get( $future_cache_key, 'festidtrack_cache' );
    
    if ( false === $has_future_data ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $has_future_data = $wpdb->get_var( $wpdb->prepare( "
            SELECT EXISTS (
                SELECT 1 FROM `{$table_name}`
                WHERE timestamp >= %s
                LIMIT 1
            )", $check_future_date->format('Y-m-d 00:00:00')
        ) );
        wp_cache_set( $future_cache_key, $has_future_data, 'festidtrack_cache', HOUR_IN_SECONDS );
    }

    echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
    echo '<span>';
    echo '<a href="' . esc_url( $prev_link ) . '">&lt;&lt; ' . esc_html( 
        /* translators: Navigation link to previous 7 days */
        __( 'Previous 7 Days', 'festival-id-tracker' ) 
    ) . '</a>';
    echo '</span>';
    echo '<span>';
    echo '<strong>' . esc_html( $period_start->format('M jS') ) . ' - ' . esc_html( $period_end->format('M jS, Y') ) . '</strong>';
    echo '</span>';
    echo '<span>';
    if ( $has_future_data ) {
        echo '<a href="' . esc_url( $next_link ) . '">' . esc_html( 
            /* translators: Navigation link to next 7 days */
            __( 'Next 7 Days', 'festival-id-tracker' ) 
        ) . ' &gt;&gt;</a>';
    } else {
        echo '&nbsp;';
    }
    echo '</span>';
    echo '</div>';

    echo '<table class="wp-list-table widefat striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>' . esc_html( 
        /* translators: Table header for date column */
        __( 'Date', 'festival-id-tracker' ) 
    ) . '</th>';
    echo '<th>' . esc_html( 
        /* translators: Table header for total calls column */
        __( 'Total Calls', 'festival-id-tracker' ) 
    ) . '</th>';
    echo '<th>' . esc_html( 
        /* translators: Table header for unique IDs column */
        __( 'Unique IDs (Users)', 'festival-id-tracker' ) 
    ) . '</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ( $data_by_day as $day => $data ) {
        echo '<tr>';
        echo '<td>' . esc_html( gmdate( 'D, M j', strtotime( $day ) ) ) . '</td>';
        echo '<td>' . esc_html( $data['total_calls'] ) . '</td>';
        echo '<td>' . esc_html( $data['unique_ids_count'] ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    echo '<p style="font-size: 0.8em; margin-top: 10px;">';
    echo '<em>' . esc_html( 
        /* translators: Explanation text for unique IDs calculation */
        __( 'Unique IDs (Users) are calculated by counting distinct festival_id values per day.', 'festival-id-tracker' ) 
    ) . '</em>';
    echo '</p>';
}

/**
 * Render the content of the individual Festival ID statistics dashboard widget.
 */
function festidtrack_render_individual_dashboard_widget() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'festidtrack_log';

    $limit = 5; // Default limit for display
    $show_all = false;
    if ( isset( $_GET['festidtrack_show_all_ids'] ) ) {
        // Check nonce for security
        if ( ! isset( $_GET['festidtrack_ids_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['festidtrack_ids_nonce'] ) ), 'festidtrack_show_ids_navigation' ) ) {
            wp_die( esc_html__( 'Security check failed. Please try again.', 'festival-id-tracker' ) );
        }
        // Also verify user capability
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'festival-id-tracker' ) );
        }
        $show_all = sanitize_text_field( wp_unslash( $_GET['festidtrack_show_all_ids'] ) ) === 'true';
    }
    $current_dashboard_url = admin_url( 'index.php' );

    // Use caching for total count
    $total_cache_key = 'festidtrack_total_unique_ids_' . md5( $table_name );
    $total_unique_ids = wp_cache_get( $total_cache_key, 'festidtrack_cache' );
    
    if ( false === $total_unique_ids ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_unique_ids = $wpdb->get_var( "SELECT COUNT(DISTINCT festival_id) FROM `{$table_name}`" );
        wp_cache_set( $total_cache_key, $total_unique_ids, 'festidtrack_cache', HOUR_IN_SECONDS );
    }

    if ( $total_unique_ids === null || $total_unique_ids === '0' ) {
        echo '<p>' . esc_html( 
            /* translators: Message when no festival ID data is available */
            __( 'No individual Festival ID data available yet.', 'festival-id-tracker' ) 
        ) . '</p>';
        return;
    }

    echo '<p><strong>' . esc_html( sprintf(
        /* translators: %d is the number of unique festival IDs */
        __( 'Total Unique Festival IDs: %d', 'festival-id-tracker' ),
        (int) $total_unique_ids
    ) ) . '</strong></p>';

    // Build the query with caching
    $results_cache_key = 'festidtrack_individual_stats_' . md5( $show_all ? 'all' : $limit );
    $results = wp_cache_get( $results_cache_key, 'festidtrack_cache' );
    
    if ( false === $results ) {
        if ( ! $show_all ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results( $wpdb->prepare( "
                SELECT
                    festival_id,
                    COUNT(*) as total_accesses,
                    COUNT(DISTINCT DATE(timestamp)) as unique_days_used
                FROM `{$table_name}`
                GROUP BY festival_id
                ORDER BY total_accesses DESC, festival_id ASC
                LIMIT %d
            ", $limit ), ARRAY_A );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results( "
                SELECT
                    festival_id,
                    COUNT(*) as total_accesses,
                    COUNT(DISTINCT DATE(timestamp)) as unique_days_used
                FROM `{$table_name}`
                GROUP BY festival_id
                ORDER BY total_accesses DESC, festival_id ASC
            ", ARRAY_A );
        }
        wp_cache_set( $results_cache_key, $results, 'festidtrack_cache', HOUR_IN_SECONDS );
    }

    if ( ! $results ) {
        echo '<p>' . esc_html( 
            /* translators: Message when no data is available after filtering */
            __( 'No individual Festival ID data available yet (after filtering).', 'festival-id-tracker' ) 
        ) . '</p>';
        return;
    }

    echo '<table class="wp-list-table widefat striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>' . esc_html( 
        /* translators: Table header for festival ID column */
        __( 'Festival ID', 'festival-id-tracker' ) 
    ) . '</th>';
    echo '<th>' . esc_html( 
        /* translators: Table header for total accesses column */
        __( 'Total Accesses', 'festival-id-tracker' ) 
    ) . '</th>';
    echo '<th>' . esc_html( 
        /* translators: Table header for unique days used column */
        __( 'Unique Days Used', 'festival-id-tracker' ) 
    ) . '</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    foreach ( $results as $row ) {
        echo '<tr>';
        echo '<td>' . esc_html( $row['festival_id'] ) . '</td>';
        echo '<td>' . esc_html( $row['total_accesses'] ) . '</td>';
        echo '<td>' . esc_html( $row['unique_days_used'] ) . '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';

    // --- Navigation Links for "Show More" / "Show Less" with Nonces ---
    if ( (int) $total_unique_ids > $limit ) { // Only show links if there are more than 5 IDs
        echo '<p style="margin-top: 10px; text-align: center;">';
        if ( ! $show_all ) {
            // Link to show all with nonce
            $show_all_link = add_query_arg( 'festidtrack_show_all_ids', 'true', $current_dashboard_url );
            // Preserve the daily stats navigation if present
            if ( isset( $_GET['festidtrack_start_date'] ) && isset( $_GET['festidtrack_nonce'] ) ) {
                // Verify the existing nonce is valid before preserving it
                if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['festidtrack_nonce'] ) ), 'festidtrack_dashboard_navigation' ) ) {
                    $show_all_link = add_query_arg( array(
                        'festidtrack_start_date' => sanitize_text_field( wp_unslash( $_GET['festidtrack_start_date'] ) ),
                        'festidtrack_nonce' => sanitize_text_field( wp_unslash( $_GET['festidtrack_nonce'] ) )
                    ), $show_all_link );
                }
            }
            // Add nonce for the show all IDs action
            $show_all_link = wp_nonce_url( $show_all_link, 'festidtrack_show_ids_navigation', 'festidtrack_ids_nonce' );
            
            echo '<a href="' . esc_url( $show_all_link ) . '">' . esc_html( 
                /* translators: Link text to show all IDs */
                __( 'Show All IDs', 'festival-id-tracker' ) 
            ) . ' (' . esc_html( ( (int) $total_unique_ids - $limit ) ) . ' ' . esc_html( 
                /* translators: Word "more" used in "X more" context */
                __( 'more', 'festival-id-tracker' ) 
            ) . ')</a>';
        } else {
            // Link to show top 5 with nonce
            $show_top_5_link = remove_query_arg( array( 'festidtrack_show_all_ids', 'festidtrack_ids_nonce' ), $current_dashboard_url );
            // Preserve the daily stats navigation if present
            if ( isset( $_GET['festidtrack_start_date'] ) && isset( $_GET['festidtrack_nonce'] ) ) {
                // Verify the existing nonce is valid before preserving it
                if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['festidtrack_nonce'] ) ), 'festidtrack_dashboard_navigation' ) ) {
                    $show_top_5_link = add_query_arg( array(
                        'festidtrack_start_date' => sanitize_text_field( wp_unslash( $_GET['festidtrack_start_date'] ) ),
                        'festidtrack_nonce' => sanitize_text_field( wp_unslash( $_GET['festidtrack_nonce'] ) )
                    ), $show_top_5_link );
                }
            }
            
            echo '<a href="' . esc_url( $show_top_5_link ) . '">' . esc_html( 
                /* translators: Link text to show only top 5 IDs */
                __( 'Show Top 5 IDs', 'festival-id-tracker' ) 
            ) . '</a>';
        }
        echo '</p>';
    }

    echo '<p style="font-size: 0.8em; margin-top: 10px;">';
    echo '<em>' . esc_html( 
        /* translators: Explanation text for the statistics table */
        __( 'Shows statistics for each unique Festival ID since tracking began, ordered by total accesses.', 'festival-id-tracker' ) 
    ) . '</em>';
    echo '</p>';
}



/**
 * Deactivation Hook: Clean up on plugin deactivation (optional, but good practice).
 * You might want to skip dropping the table if you want to retain data for later re-activation.
 */
// function festidtrack_deactivate_plugin() {
//     global $wpdb;
//     $table_name = $wpdb->prefix . 'festidtrack_log';
//     $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
//     delete_option( 'festidtrack_db_version' );
//     delete_option( 'festidtrack_redirect_url' );
//     delete_option( 'festidtrack_redirect_enabled' );
// }
// register_deactivation_hook( __FILE__, 'festidtrack_deactivate_plugin' );