<?php
/**
 * Plugin Name: Festival ID Tracker
 * Plugin URI:  https://festivalul-inimilor.ro/
 * Description: Tracks URL calls with an 'id' parameter (e.g., ?id=XXXXXX) and displays daily statistics and per-ID statistics in the WordPress dashboard. Includes redirect functionality.
 * Version:     1.4.0
 * Author:      Paul Wasicsek / Digital Travel Guide
 * Author URI:  https://festivalul-inimilor.ro/
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: festival-id-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'FIT_VERSION', '1.4.0' ); // Updated version for redirect functionality
define( 'FIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Activation Hook: Create database table on plugin activation.
 */
function fit_activate_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'festival_id_log';

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

    update_option( 'fit_db_version', FIT_VERSION );
    
    // Add default settings
    add_option( 'fit_redirect_url', '' );
    add_option( 'fit_redirect_enabled', false );
}
register_activation_hook( __FILE__, 'fit_activate_plugin' );

/**
 * Checks for the 'id' query parameter, logs the call, and handles redirect if configured.
 */
function fit_track_festival_id_call() {
    if ( ! is_admin() && isset( $_GET['id'] ) && preg_match( '/^[a-zA-Z0-9]{6}$/', $_GET['id'] ) ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'festival_id_log';
        $festival_id = sanitize_text_field( $_GET['id'] );

        $user_ip = fit_get_client_ip();
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : 'unknown';
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
        $redirect_enabled = get_option( 'fit_redirect_enabled', false );
        $redirect_url = get_option( 'fit_redirect_url', '' );
        
        if ( $redirect_enabled && ! empty( $redirect_url ) ) {
            // Add the festival_id to the redirect URL
            $redirect_url_with_id = add_query_arg( 'id', $festival_id, $redirect_url );
            
            // Perform the redirect
            wp_redirect( $redirect_url_with_id, 302 );
            exit;
        }
    }
}
add_action( 'wp', 'fit_track_festival_id_call' );

/**
 * Gets the real client IP address, handling proxies.
 * @return string
 */
function fit_get_client_ip() {
    $ip = 'UNKNOWN';
    if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        $ips = explode( ',', $ip );
        $ip = trim( $ips[0] );
    } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return sanitize_text_field( $ip );
}

/**
 * Add admin menu for plugin settings.
 */
function fit_add_admin_menu() {
    add_options_page(
        __( 'Festival ID Tracker Settings', 'festival-id-tracker' ),
        __( 'Festival ID Tracker', 'festival-id-tracker' ),
        'manage_options',
        'festival-id-tracker',
        'fit_settings_page'
    );
}
add_action( 'admin_menu', 'fit_add_admin_menu' );

/**
 * Initialize plugin settings.
 */
function fit_settings_init() {
    register_setting( 'fit_settings', 'fit_redirect_enabled' );
    register_setting( 'fit_settings', 'fit_redirect_url' );

    add_settings_section(
        'fit_redirect_section',
        __( 'Redirect Settings', 'festival-id-tracker' ),
        'fit_redirect_section_callback',
        'fit_settings'
    );

    add_settings_field(
        'fit_redirect_enabled',
        __( 'Enable Redirect', 'festival-id-tracker' ),
        'fit_redirect_enabled_callback',
        'fit_settings',
        'fit_redirect_section'
    );

    add_settings_field(
        'fit_redirect_url',
        __( 'Redirect URL', 'festival-id-tracker' ),
        'fit_redirect_url_callback',
        'fit_settings',
        'fit_redirect_section'
    );
}
add_action( 'admin_init', 'fit_settings_init' );

/**
 * Redirect section description callback.
 */
function fit_redirect_section_callback() {
    echo '<p>' . __( 'Configure redirect behavior for URLs with the "id" parameter. When enabled, visitors will be redirected to the specified URL with the ID parameter preserved.', 'festival-id-tracker' ) . '</p>';
}

/**
 * Redirect enabled field callback.
 */
function fit_redirect_enabled_callback() {
    $enabled = get_option( 'fit_redirect_enabled', false );
    echo '<input type="checkbox" id="fit_redirect_enabled" name="fit_redirect_enabled" value="1" ' . checked( 1, $enabled, false ) . ' />';
    echo '<label for="fit_redirect_enabled">' . __( 'Enable automatic redirect when ID parameter is detected', 'festival-id-tracker' ) . '</label>';
}

/**
 * Redirect URL field callback.
 */
function fit_redirect_url_callback() {
    $url = get_option( 'fit_redirect_url', '' );
    echo '<input type="url" id="fit_redirect_url" name="fit_redirect_url" value="' . esc_attr( $url ) . '" class="regular-text" placeholder="https://example.com/destination" />';
    echo '<p class="description">' . __( 'Enter the full URL where users should be redirected. The ID parameter will be automatically added to this URL. Leave empty to disable redirect.', 'festival-id-tracker' ) . '</p>';
    echo '<p class="description"><strong>' . __( 'Example:', 'festival-id-tracker' ) . '</strong> ' . __( 'If you enter "https://example.com/festival" and someone visits your site with "?id=ABC123", they will be redirected to "https://example.com/festival?id=ABC123"', 'festival-id-tracker' ) . '</p>';
}

/**
 * Settings page content.
 */
function fit_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle form submission
    if ( isset( $_GET['settings-updated'] ) ) {
        add_settings_error( 'fit_messages', 'fit_message', __( 'Settings saved successfully!', 'festival-id-tracker' ), 'updated' );
    }

    settings_errors( 'fit_messages' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        
        <div class="notice notice-info">
            <p><strong><?php _e( 'How it works:', 'festival-id-tracker' ); ?></strong></p>
            <ul style="margin-left: 20px;">
                <li><?php _e( '• When someone visits your site with an ID parameter (e.g., yoursite.com?id=ABC123)', 'festival-id-tracker' ); ?></li>
                <li><?php _e( '• The plugin logs this visit for tracking purposes', 'festival-id-tracker' ); ?></li>
                <li><?php _e( '• If redirect is enabled, the visitor is automatically sent to your specified URL', 'festival-id-tracker' ); ?></li>
                <li><?php _e( '• The ID parameter is preserved in the redirect URL', 'festival-id-tracker' ); ?></li>
            </ul>
        </div>

        <form action="options.php" method="post">
            <?php
            settings_fields( 'fit_settings' );
            do_settings_sections( 'fit_settings' );
            submit_button( __( 'Save Settings', 'festival-id-tracker' ) );
            ?>
        </form>

        <div class="card" style="margin-top: 20px;">
            <h2><?php _e( 'Current Statistics', 'festival-id-tracker' ); ?></h2>
            <?php fit_display_quick_stats(); ?>
        </div>

        <div class="card" style="margin-top: 20px;">
            <h2><?php _e( 'Testing Your Configuration', 'festival-id-tracker' ); ?></h2>
            <p><?php _e( 'To test your redirect configuration:', 'festival-id-tracker' ); ?></p>
            <ol>
                <li><?php _e( 'Save your settings above', 'festival-id-tracker' ); ?></li>
                <li><?php _e( 'Visit your site with a test ID:', 'festival-id-tracker' ); ?> 
                    <code><?php echo home_url( '?id=TEST01' ); ?></code>
                </li>
                <li><?php _e( 'You should be redirected to your configured URL with the ID parameter', 'festival-id-tracker' ); ?></li>
            </ol>
        </div>
    </div>
    <?php
}

/**
 * Display quick statistics on the settings page.
 */
function fit_display_quick_stats() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'festival_id_log';

    $total_calls = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
    $unique_ids = $wpdb->get_var( "SELECT COUNT(DISTINCT festival_id) FROM {$table_name}" );
    $today_calls = $wpdb->get_var( $wpdb->prepare( 
        "SELECT COUNT(*) FROM {$table_name} WHERE DATE(timestamp) = %s", 
        current_time( 'Y-m-d' ) 
    ) );

    echo '<table class="form-table">';
    echo '<tr><th scope="row">' . __( 'Total Calls Tracked:', 'festival-id-tracker' ) . '</th><td>' . number_format( (int) $total_calls ) . '</td></tr>';
    echo '<tr><th scope="row">' . __( 'Unique Festival IDs:', 'festival-id-tracker' ) . '</th><td>' . number_format( (int) $unique_ids ) . '</td></tr>';
    echo '<tr><th scope="row">' . __( 'Calls Today:', 'festival-id-tracker' ) . '</th><td>' . number_format( (int) $today_calls ) . '</td></tr>';
    echo '</table>';

    $redirect_enabled = get_option( 'fit_redirect_enabled', false );
    $redirect_url = get_option( 'fit_redirect_url', '' );
    
    echo '<h4>' . __( 'Current Redirect Configuration:', 'festival-id-tracker' ) . '</h4>';
    if ( $redirect_enabled && ! empty( $redirect_url ) ) {
        echo '<span style="color: green;">✓ ' . __( 'Redirect is ENABLED', 'festival-id-tracker' ) . '</span><br>';
        echo '<strong>' . __( 'Redirect URL:', 'festival-id-tracker' ) . '</strong> ' . esc_html( $redirect_url );
    } else {
        echo '<span style="color: #666;">○ ' . __( 'Redirect is DISABLED', 'festival-id-tracker' ) . '</span>';
    }
}

/**
 * Add settings link to plugin actions.
 */
function fit_add_settings_link( $links ) {
    $settings_link = '<a href="' . admin_url( 'options-general.php?page=festival-id-tracker' ) . '">' . __( 'Settings', 'festival-id-tracker' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'fit_add_settings_link' );

/**
 * Add custom dashboard widgets.
 */
function fit_add_dashboard_widgets() {
    // Widget 1: Daily Call Statistics
    wp_add_dashboard_widget(
        'fit_festival_id_daily_stats',
        __( 'Festival ID Daily Statistics', 'festival-id-tracker' ),
        'fit_render_daily_dashboard_widget'
    );

    // Widget 2: Individual Festival ID Statistics
    wp_add_dashboard_widget(
        'fit_festival_id_individual_stats',
        __( 'Festival ID Global Statistics', 'festival-id-tracker' ),
        'fit_render_individual_dashboard_widget'
    );
}
add_action( 'wp_dashboard_setup', 'fit_add_dashboard_widgets' );

/**
 * Render the content of the daily dashboard widget.
 */
function fit_render_daily_dashboard_widget() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'festival_id_log';

    $current_date_param = isset( $_GET['fit_start_date'] ) ? sanitize_text_field( $_GET['fit_start_date'] ) : '';
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

    $results = $wpdb->get_results( $wpdb->prepare( "
        SELECT
            DATE(timestamp) as day,
            COUNT(*) as total_calls,
            COUNT(DISTINCT festival_id) as unique_ids_count
        FROM {$table_name}
        WHERE timestamp BETWEEN %s AND %s
        GROUP BY day
        ORDER BY day ASC
    ", $period_start->format('Y-m-d 00:00:00'), $period_end->format('Y-m-d 23:59:59') ), ARRAY_A );

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

    // --- Navigation Links ---
    $dashboard_url = admin_url( 'index.php' );

    $prev_start_date = clone $period_start;
    $prev_start_date->modify( '-7 days' );
    $prev_link = add_query_arg( 'fit_start_date', $prev_start_date->format('Y-m-d'), $dashboard_url );

    $next_start_date = clone $period_start;
    $next_start_date->modify( '+7 days' );
    $next_link = add_query_arg( 'fit_start_date', $next_start_date->format('Y-m-d'), $dashboard_url );

    $check_future_date = clone $period_end;
    $check_future_date->modify('+1 day');
    $has_future_data = $wpdb->get_var( $wpdb->prepare( "
        SELECT EXISTS (
            SELECT 1 FROM {$table_name}
            WHERE timestamp >= %s
            LIMIT 1
        )", $check_future_date->format('Y-m-d 00:00:00')
    ) );

    echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">';
    echo '<span>';
    echo '<a href="' . esc_url( $prev_link ) . '">&lt;&lt; ' . __( 'Previous 7 Days', 'festival-id-tracker' ) . '</a>';
    echo '</span>';
    echo '<span>';
    echo '<strong>' . esc_html( $period_start->format('M jS') ) . ' - ' . esc_html( $period_end->format('M jS, Y') ) . '</strong>';
    echo '</span>';
    echo '<span>';
    if ( $has_future_data ) {
        echo '<a href="' . esc_url( $next_link ) . '">' . __( 'Next 7 Days', 'festival-id-tracker' ) . ' &gt;&gt;</a>';
    } else {
        echo '&nbsp;';
    }
    echo '</div>';

    echo '<table class="wp-list-table widefat striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>' . __( 'Date', 'festival-id-tracker' ) . '</th>';
    echo '<th>' . __( 'Total Calls', 'festival-id-tracker' ) . '</th>';
    echo '<th>' . __( 'Unique IDs (Users)', 'festival-id-tracker' ) . '</th>';
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
    echo '<em>' . __( 'Unique IDs (Users) are calculated by counting distinct festival_id values per day.', 'festival-id-tracker' ) . '</em>';
    echo '</p>';
}

/**
 * Render the content of the individual Festival ID statistics dashboard widget.
 */
function fit_render_individual_dashboard_widget() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'festival_id_log';

    $limit = 5; // Default limit for display
    $show_all = isset( $_GET['fit_show_all_ids'] ) && $_GET['fit_show_all_ids'] === 'true';
    $current_dashboard_url = admin_url( 'index.php' );

    // 1. Get total count of unique festival_ids
    $total_unique_ids = $wpdb->get_var( "SELECT COUNT(DISTINCT festival_id) FROM {$table_name}" );

    if ( $total_unique_ids === null || $total_unique_ids === '0' ) {
        echo '<p>' . __( 'No individual Festival ID data available yet.', 'festival-id-tracker' ) . '</p>';
        return;
    }

    echo '<p><strong>' . sprintf(
        __( 'Total Unique Festival IDs: %d', 'festival-id-tracker' ),
        (int) $total_unique_ids
    ) . '</strong></p>';

    // Build the query
    $sql_query = "
        SELECT
            festival_id,
            COUNT(*) as total_accesses,
            COUNT(DISTINCT DATE(timestamp)) as unique_days_used
        FROM {$table_name}
        GROUP BY festival_id
        ORDER BY total_accesses DESC, festival_id ASC
    ";

    // Add LIMIT clause if not showing all
    if ( ! $show_all ) {
        $sql_query .= " LIMIT " . (int) $limit;
    }

    $results = $wpdb->get_results( $sql_query, ARRAY_A );

    if ( ! $results ) {
        echo '<p>' . __( 'No individual Festival ID data available yet (after filtering).', 'festival-id-tracker' ) . '</p>';
        return;
    }

    echo '<table class="wp-list-table widefat striped">';
    echo '<thead>';
    echo '<tr>';
    echo '<th>' . __( 'Festival ID', 'festival-id-tracker' ) . '</th>';
    echo '<th>' . __( 'Total Accesses', 'festival-id-tracker' ) . '</th>';
    echo '<th>' . __( 'Unique Days Used', 'festival-id-tracker' ) . '</th>';
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

    // --- Navigation Links for "Show More" / "Show Less" ---
    if ( (int) $total_unique_ids > $limit ) { // Only show links if there are more than 5 IDs
        echo '<p style="margin-top: 10px; text-align: center;">';
        if ( ! $show_all ) {
            // Link to show all
            $show_all_link = add_query_arg( 'fit_show_all_ids', 'true', $current_dashboard_url );
            // Preserve the daily stats navigation if present
            if ( isset( $_GET['fit_start_date'] ) ) {
                 $show_all_link = add_query_arg( 'fit_start_date', sanitize_text_field( $_GET['fit_start_date'] ), $show_all_link );
            }
            echo '<a href="' . esc_url( $show_all_link ) . '">' . __( 'Show All IDs', 'festival-id-tracker' ) . ' (' . ( (int) $total_unique_ids - $limit ) . ' more)</a>';
        } else {
            // Link to show top 5
            $show_top_5_link = remove_query_arg( 'fit_show_all_ids', $current_dashboard_url );
            // Preserve the daily stats navigation if present
            if ( isset( $_GET['fit_start_date'] ) ) {
                 $show_top_5_link = add_query_arg( 'fit_start_date', sanitize_text_field( $_GET['fit_start_date'] ), $show_top_5_link );
            }
            echo '<a href="' . esc_url( $show_top_5_link ) . '">' . __( 'Show Top 5 IDs', 'festival-id-tracker' ) . '</a>';
        }
        echo '</p>';
    }

    echo '<p style="font-size: 0.8em; margin-top: 10px;">';
    echo '<em>' . __( 'Shows statistics for each unique Festival ID since tracking began, ordered by total accesses.', 'festival-id-tracker' ) . '</em>';
    echo '</p>';
}

/**
 * Deactivation Hook: Clean up on plugin deactivation (optional, but good practice).
 * You might want to skip dropping the table if you want to retain data for later re-activation.
 */
// function fit_deactivate_plugin() {
//     global $wpdb;
//     $table_name = $wpdb->prefix . 'festival_id_log';
//     $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
//     delete_option( 'fit_db_version' );
//     delete_option( 'fit_redirect_url' );
//     delete_option( 'fit_redirect_enabled' );
// }
// register_deactivation_hook( __FILE__, 'fit_deactivate_plugin' );