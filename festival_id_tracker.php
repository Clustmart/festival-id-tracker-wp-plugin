<?php
/**
 * Plugin Name: Festival ID Tracker
 * Plugin URI:  https://festivalul-inimilor.ro/
 * Description: Tracks URL calls with an 'id' parameter (e.g., ?id=XXXXXX) and displays daily statistics and per-ID statistics in the WordPress dashboard.
 * Version:     1.3.0
 * Author:      Paul Wasicsek / Digital Travel Guide
 * Author URI:  https://festivalul-inimilor.ro/
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: festival-id-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'FIT_VERSION', '1.3.0' ); // Updated version for new widget features
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
}
register_activation_hook( __FILE__, 'fit_activate_plugin' );

/**
 * Checks for the 'id' query parameter and logs the call.
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
// }
// register_deactivation_hook( __FILE__, 'fit_deactivate_plugin' );