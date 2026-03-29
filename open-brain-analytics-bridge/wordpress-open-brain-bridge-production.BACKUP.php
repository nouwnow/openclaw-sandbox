<?php
/**
 * Plugin Name: Open Brain Analytics Bridge (Production)
 * Description: Secure endpoints for Matomo and VikBooking data integration - PRODUCTION VERSION
 * Version: 1.0.0
 * Author: Open Brain
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register REST API endpoints
 */
add_action('rest_api_init', function() {
    // Batch performance data endpoint
    register_rest_route('brain/v1', '/performance/batch', [
        'methods' => 'POST',
        'callback' => 'get_batch_performance_data_production',
        'permission_callback' => function() {
            // SECURITY: Only allow users with edit_posts capability
            return current_user_can('edit_posts');
        },
    ]);

    // Combined performance data endpoint
    register_rest_route('brain/v1', '/performance/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_combined_performance_data',
        'permission_callback' => function() {
            // SECURITY: Only allow users with edit_posts capability
            return current_user_can('edit_posts');
        },
        'args' => [
            'id' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);

    // Diagnostic endpoint to check table structure
    register_rest_route('brain/v1', '/diagnostic/tables', [
        'methods' => 'GET',
        'callback' => 'get_diagnostic_table_info',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        },
    ]);
    
    // VikBooking site-wide summary endpoint
    register_rest_route('brain/v1', '/vikbooking/summary', [
        'methods' => 'GET',
        'callback' => 'get_vikbooking_summary',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        },
    ]);

    // Matomo full diagnostic — all tables + sample data
    register_rest_route('brain/v1', '/matomo/diagnostic', [
        'methods' => 'GET',
        'callback' => 'get_matomo_diagnostic',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        },
    ]);

    // Matomo site-wide summary endpoint (last 30 days)
    register_rest_route('brain/v1', '/matomo/summary', [
        'methods' => 'GET',
        'callback' => 'get_matomo_summary',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        },
    ]);

    // Matomo goals endpoint for conversion tracking
    register_rest_route('brain/v1', '/matomo/goals/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_matomo_goals_data',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        },
        'args' => [
            'id' => [
                'required' => true,
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                },
                'sanitize_callback' => 'absint',
            ],
        ],
    ]);
});

/**
 * Get combined performance data for a single post/page
 */
function get_combined_performance_data($request) {
    $post_id = $request['id'];
    $post = get_post($post_id);
    
    if (!$post) {
        return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
    }

    global $wpdb;
    
    // Get post URL for tracking
    $post_url = get_permalink($post_id);
    
    // --- 1. MATOMO DATA OPHALEN ---
    $matomo_data = get_matomo_data_production($post_id, $post_url);
    
    // --- 2. VIKBOOKING DATA OPHALEN ---
    $vikbooking_data = get_vikbooking_data_production($post_id, $post_url);
    
    // --- 3. WORDPRESS METADATA ---
    $wordpress_data = [
        'title' => $post->post_title,
        'url' => $post_url,
        'type' => $post->post_type,
        'status' => $post->post_status,
        'published' => $post->post_date_gmt,
        'modified' => $post->post_modified_gmt,
        'days_since_update' => get_days_since_update($post->post_modified_gmt),
    ];

    return [
        'post_id' => $post_id,
        'wordpress' => $wordpress_data,
        'analytics' => $matomo_data,
        'business' => $vikbooking_data,
        'last_updated' => current_time('mysql'),
        'success' => true,
    ];
}

/**
 * Get batch performance data for multiple posts
 */
function get_batch_performance_data_production($request) {
    $body = $request->get_json_params();
    
    if (!isset($body['post_ids']) || !is_array($body['post_ids'])) {
        return new WP_Error('invalid_request', 'post_ids array required', ['status' => 400]);
    }
    
    $results = [];
    foreach ($body['post_ids'] as $post_id) {
        $post_id = absint($post_id);
        if ($post_id > 0) {
            $sub_request = new WP_REST_Request('GET', '/brain/v1/performance/' . $post_id);
            $sub_request->set_url_params(['id' => $post_id]);
            $results[$post_id] = get_combined_performance_data($sub_request);
        }
    }
    
    return [
        'results' => $results,
        'count' => count($results),
        'success' => true,
    ];
}

/**
 * Get Matomo analytics data - PRODUCTION VERSION
 * Minimal logging for production
 */
function get_matomo_data_production($post_id, $post_url) {
    $visits = 0;
    $bounce_rate = 0;
    $pageviews = 0;
    $avg_time = 0;
    
    // Direct database query via Matomo tables (free Matomo for WordPress plugin
    // stores all data locally in WordPress DB — no external server or token needed).
    // matomo_log_action.name holds the URL, joined via matomo_log_link_visit_action.
    global $wpdb;

    $action_table = $wpdb->prefix . 'matomo_log_action';
    $link_table   = $wpdb->prefix . 'matomo_log_link_visit_action';

    $action_exists = $wpdb->get_var("SHOW TABLES LIKE '{$action_table}'");
    $link_exists   = $wpdb->get_var("SHOW TABLES LIKE '{$link_table}'");

    $source = 'no_matomo_tables';

    if ($action_exists && $link_exists) {
        $path  = parse_url($post_url, PHP_URL_PATH);
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT llva.idvisit) AS visits,
                COUNT(*) AS pageviews
            FROM {$link_table} llva
            INNER JOIN {$action_table} la ON llva.idaction_url = la.idaction
            WHERE la.name LIKE %s
            AND la.type = 1
            AND llva.server_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '%' . $wpdb->esc_like($path) . '%'
        ));

        if ($stats && $stats->visits > 0) {
            $visits    = $stats->visits;
            $pageviews = $stats->pageviews;
            $source    = 'matomo_db';
        } else {
            $source = 'matomo_db_no_data';
        }
    }

    return [
        'visits'           => (int)$visits,
        'unique_visitors'  => (int)($visits * 0.7),
        'pageviews'        => (int)$pageviews,
        'avg_time_seconds' => (int)$avg_time,
        'bounce_rate'      => (float)$bounce_rate,
        'exit_rate'        => (float)($bounce_rate * 0.8),
        'source'           => $source,
    ];
}

/**
 * Get VikBooking business data - PRODUCTION VERSION
 * Based on actual database structure (khj_ prefix)
 */
function get_vikbooking_data_production($post_id, $post_url) {
    global $wpdb;
    
    $conversions = 0;
    $revenue = 0;
    
    // TABLE PREFIX: khj_ (same for staging and production)
    $table_prefix = 'khj_';
    $vikbooking_table = $table_prefix . 'vikbooking_orders';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$vikbooking_table'");
    
    if (!$table_exists) {
        // Minimal logging for production
        return [
            'conversions' => 0,
            'revenue' => 0,
            'avg_conversion_value' => 0,
            'source' => 'no_table_found',
        ];
    }
    
    // Get post title to search in custdata
    $post_title = get_the_title($post_id);
    $post_slug = get_post_field('post_name', $post_id);
    
    // Clean post title for search
    $clean_title = preg_replace('/[^a-zA-Z0-9\s]/', '', $post_title);
    $search_terms = [
        $post_title,
        $clean_title,
        $post_slug,
        $post_url,
        parse_url($post_url, PHP_URL_PATH),
    ];
    
    // Remove empty values
    $search_terms = array_filter($search_terms);
    
    // Build search conditions
    $search_conditions = [];
    foreach ($search_terms as $term) {
        if (!empty($term)) {
            $search_conditions[] = $wpdb->prepare("custdata LIKE %s", '%' . $wpdb->esc_like($term) . '%');
        }
    }
    
    // Build where conditions
    $where_conditions = [];
    $where_conditions[] = "status IN ('confirmed', 'paid')";
    $where_conditions[] = "date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    // Add search conditions if we have them
    if (!empty($search_conditions)) {
        $where_conditions[] = "(" . implode(" OR ", $search_conditions) . ")";
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where_conditions);
    
    // Query for bookings linked to this post
    $query = "
        SELECT 
            COUNT(*) as conversions,
            SUM(CASE WHEN total > 0 THEN total ELSE totpaid END) as revenue
        FROM $vikbooking_table 
        $where_clause
    ";
    
    $result = $wpdb->get_row($query);

    if ($result) {
        $conversions = $result->conversions ?? 0;
        $revenue = $result->revenue ?? 0;
    }

    return [
        'conversions' => (int)$conversions,
        'revenue' => (float)$revenue,
        'avg_conversion_value' => $conversions > 0 ? $revenue / $conversions : 0,
        'source' => $conversions > 0 ? 'vikbooking_db' : 'vikbooking_db_no_data',
    ];
}

/**
 * Diagnostic function to check table structure
 */
function get_diagnostic_table_info() {
    global $wpdb;
    
    $table_prefix = $wpdb->prefix;
    
    // Check VikBooking tables
    $vikbooking_tables = [];
    $possible_vikbooking_tables = [
        $table_prefix . 'vikbooking_orders',
        $table_prefix . 'vikbooking_customers_orders',
    ];
    
    foreach ($possible_vikbooking_tables as $table) {
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'")) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");
            
            $vikbooking_tables[$table] = [
                'exists' => true,
                'columns' => $columns,
            ];
        }
    }
    
    // Check Matomo tables
    $matomo_tables = [];
    $possible_matomo_tables = [
        $table_prefix . 'matomo_log_visit',
        $table_prefix . 'matomo_log_link_visit_action',
        $table_prefix . 'matomo_site',
    ];
    
    foreach ($possible_matomo_tables as $table) {
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'")) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM $table");
            
            $matomo_tables[$table] = [
                'exists' => true,
                'columns' => $columns,
            ];
        }
    }
    
    return [
        'table_prefix' => $table_prefix,
        'vikbooking_tables' => $vikbooking_tables,
        'matomo_tables' => $matomo_tables,
        'total_posts' => $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}posts WHERE post_status = 'publish' AND post_type IN ('post', 'page')"),
        'success' => true,
    ];
}

/**
 * Full Matomo diagnostic — all matomo_* tables, row counts, columns, sample data
 */
function get_matomo_diagnostic() {
    global $wpdb;
    $prefix = $wpdb->prefix;

    // Find all matomo_* tables
    $all_tables = $wpdb->get_col("SHOW TABLES LIKE '{$prefix}matomo%'");

    $result = [];
    foreach ($all_tables as $table) {
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
        $count   = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        $sample  = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 2", ARRAY_A);
        $result[$table] = [
            'columns' => $columns,
            'row_count' => $count,
            'sample' => $sample,
        ];
    }

    // Extra: sample from log_visit with all columns (1 row)
    $visit_table = $prefix . 'matomo_log_visit';
    $visit_sample = null;
    if (in_array($visit_table, $all_tables)) {
        $visit_sample = $wpdb->get_row(
            "SELECT * FROM `{$visit_table}` ORDER BY visit_last_action_time DESC LIMIT 1",
            ARRAY_A
        );
    }

    // Extra: sample from log_conversion (1 row)
    $conv_table = $prefix . 'matomo_log_conversion';
    $conv_sample = null;
    if (in_array($conv_table, $all_tables)) {
        $conv_sample = $wpdb->get_row("SELECT * FROM `{$conv_table}` ORDER BY server_time DESC LIMIT 1", ARRAY_A);
    }

    // Extra: all goals from matomo_goal
    $goal_table = $prefix . 'matomo_goal';
    $goals = [];
    if (in_array($goal_table, $all_tables)) {
        $goals = $wpdb->get_results("SELECT * FROM `{$goal_table}`", ARRAY_A);
    }

    return [
        'success'       => true,
        'prefix'        => $prefix,
        'matomo_tables' => $result,
        'visit_sample'  => $visit_sample,
        'conv_sample'   => $conv_sample,
        'goals'         => $goals,
    ];
}

/**
 * Get Matomo site-wide summary for the last 30 days
 * Covers: totals, acquisition, visitors, behaviour, goals & booking funnel
 */
function get_matomo_summary() {
    global $wpdb;
    $p = $wpdb->prefix;

    $visit_table  = $p . 'matomo_log_visit';
    $action_table = $p . 'matomo_log_action';
    $link_table   = $p . 'matomo_log_link_visit_action';
    $conv_table   = $p . 'matomo_log_conversion';
    $goal_table   = $p . 'matomo_goal';

    if (!$wpdb->get_var("SHOW TABLES LIKE '{$visit_table}'")) {
        return new WP_Error('no_table', 'Matomo log_visit table not found', ['status' => 404]);
    }

    $days = 30;

    // ── 1. SITE TOTALS ────────────────────────────────────────────────────────
    $stats = $wpdb->get_row("
        SELECT
            COUNT(*)                                                                   AS total_visits,
            COUNT(DISTINCT idvisitor)                                                  AS unique_visitors,
            SUM(CASE WHEN visitor_returning = 0 THEN 1 ELSE 0 END)                    AS new_visitors,
            SUM(CASE WHEN visitor_returning = 1 THEN 1 ELSE 0 END)                    AS returning_visitors,
            SUM(visit_total_actions)                                                   AS total_pageviews,
            ROUND(AVG(visit_total_time))                                               AS avg_time_seconds,
            ROUND(AVG(visit_total_actions), 2)                                         AS avg_pages_per_visit,
            ROUND(100.0 * SUM(CASE WHEN visit_total_actions <= 1 THEN 1 ELSE 0 END)
                  / NULLIF(COUNT(*), 0), 2)                                            AS bounce_rate,
            SUM(visit_goal_converted)                                                  AS visits_with_goal,
            SUM(visit_total_searches)                                                  AS total_searches
        FROM {$visit_table}
        WHERE visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
    ");

    // ── 2. TRAFFIC SOURCES ───────────────────────────────────────────────────
    // referer_type: 1=Direct 2=Search 3=Website 6=Social 7=Campaign
    $raw_sources = $wpdb->get_results("
        SELECT
            CASE referer_type
                WHEN 1 THEN 'direct'   WHEN 2 THEN 'search'
                WHEN 3 THEN 'website'  WHEN 6 THEN 'social'
                WHEN 7 THEN 'campaign' ELSE 'other'
            END AS type,
            COUNT(*) AS visits
        FROM {$visit_table}
        WHERE visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY referer_type ORDER BY visits DESC
    ", ARRAY_A);

    // ── 3. SEARCH ENGINES ────────────────────────────────────────────────────
    $search_engines = $wpdb->get_results("
        SELECT referer_name AS engine, COUNT(*) AS visits
        FROM {$visit_table}
        WHERE referer_type = 2
          AND visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY referer_name ORDER BY visits DESC LIMIT 10
    ", ARRAY_A);

    // ── 4. SEARCH KEYWORDS ───────────────────────────────────────────────────
    $keywords = $wpdb->get_results("
        SELECT referer_keyword AS keyword, COUNT(*) AS visits
        FROM {$visit_table}
        WHERE referer_type = 2
          AND referer_keyword IS NOT NULL AND referer_keyword != ''
          AND visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY referer_keyword ORDER BY visits DESC LIMIT 20
    ", ARRAY_A);

    // ── 5. SOCIAL NETWORKS ───────────────────────────────────────────────────
    $social = $wpdb->get_results("
        SELECT referer_name AS network, COUNT(*) AS visits
        FROM {$visit_table}
        WHERE referer_type = 6
          AND visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY referer_name ORDER BY visits DESC LIMIT 10
    ", ARRAY_A);

    // ── 6. AI ASSISTANTS ─────────────────────────────────────────────────────
    $ai_assistants = $wpdb->get_results("
        SELECT ai_agent_name AS agent, COUNT(*) AS visits
        FROM {$visit_table}
        WHERE ai_agent_name IS NOT NULL AND ai_agent_name != ''
          AND visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY ai_agent_name ORDER BY visits DESC LIMIT 20
    ", ARRAY_A);

    // ── 7. DEVICES ───────────────────────────────────────────────────────────
    // config_device_type: 0=desktop 1=mobile 2=tablet
    $devices = $wpdb->get_results("
        SELECT
            CASE config_device_type
                WHEN 0 THEN 'desktop' WHEN 1 THEN 'mobile'
                WHEN 2 THEN 'tablet'  ELSE 'other'
            END AS device,
            COUNT(*) AS visits
        FROM {$visit_table}
        WHERE visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY config_device_type ORDER BY visits DESC
    ", ARRAY_A);

    // ── 8. BROWSERS ──────────────────────────────────────────────────────────
    $browsers = $wpdb->get_results("
        SELECT config_browser_name AS browser, COUNT(*) AS visits
        FROM {$visit_table}
        WHERE visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY config_browser_name ORDER BY visits DESC LIMIT 10
    ", ARRAY_A);

    // ── 9. COUNTRIES ─────────────────────────────────────────────────────────
    $countries = $wpdb->get_results("
        SELECT location_country AS country, COUNT(*) AS visits
        FROM {$visit_table}
        WHERE visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
          AND location_country IS NOT NULL AND location_country != ''
        GROUP BY location_country ORDER BY visits DESC LIMIT 15
    ", ARRAY_A);

    // ── 10. CITIES ───────────────────────────────────────────────────────────
    $cities = $wpdb->get_results("
        SELECT location_city AS city, location_country AS country, COUNT(*) AS visits
        FROM {$visit_table}
        WHERE visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
          AND location_city IS NOT NULL AND location_city != ''
        GROUP BY location_city, location_country ORDER BY visits DESC LIMIT 15
    ", ARRAY_A);

    // ── 11. TOP PAGES ────────────────────────────────────────────────────────
    $top_pages = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '{$link_table}'")) {
        $top_pages = $wpdb->get_results("
            SELECT
                la.name AS url,
                COUNT(*) AS pageviews,
                COUNT(DISTINCT llva.idvisit) AS unique_visits,
                ROUND(AVG(llva.time_spent)) AS avg_time_seconds
            FROM {$link_table} llva
            INNER JOIN {$action_table} la ON llva.idaction_url = la.idaction
            WHERE la.type = 1
              AND llva.server_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY la.name ORDER BY pageviews DESC LIMIT 15
        ", ARRAY_A);
    }

    // ── 12. ENTRY PAGES ──────────────────────────────────────────────────────
    $entry_pages = $wpdb->get_results("
        SELECT la.name AS url, COUNT(*) AS entries
        FROM {$visit_table} lv
        INNER JOIN {$action_table} la ON lv.visit_entry_idaction_url = la.idaction
        WHERE la.type = 1
          AND lv.visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY la.name ORDER BY entries DESC LIMIT 10
    ", ARRAY_A);

    // ── 13. EXIT PAGES ───────────────────────────────────────────────────────
    $exit_pages = $wpdb->get_results("
        SELECT la.name AS url, COUNT(*) AS exits
        FROM {$visit_table} lv
        INNER JOIN {$action_table} la ON lv.visit_exit_idaction_url = la.idaction
        WHERE la.type = 1
          AND lv.visit_last_action_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        GROUP BY la.name ORDER BY exits DESC LIMIT 10
    ", ARRAY_A);

    // ── 14. OUTLINKS (type=4 in log_action) ─────────────────────────────────
    $outlinks = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '{$link_table}'")) {
        $outlinks = $wpdb->get_results("
            SELECT la.name AS url, COUNT(*) AS clicks
            FROM {$link_table} llva
            INNER JOIN {$action_table} la ON llva.idaction_url = la.idaction
            WHERE la.type = 4
              AND llva.server_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY la.name ORDER BY clicks DESC LIMIT 10
        ", ARRAY_A);
    }

    // ── 15. GOALS + BOOKING FUNNEL ───────────────────────────────────────────
    $goal_conversions = [];
    $funnel = ['interesse' => 0, 'intentie_nl' => 0, 'intentie_en' => 0, 'actie' => 0, 'succes' => 0];

    if ($wpdb->get_var("SHOW TABLES LIKE '{$conv_table}'")) {
        // Goal definitions
        $goal_defs = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '{$goal_table}'")) {
            foreach ($wpdb->get_results("SELECT idgoal, name FROM {$goal_table}", ARRAY_A) as $g) {
                $goal_defs[(int)$g['idgoal']] = $g['name'];
            }
        }
        // Conversions per goal
        foreach ($wpdb->get_results("
            SELECT idgoal, COUNT(*) AS conversions, COUNT(DISTINCT idvisit) AS unique_visits
            FROM {$conv_table}
            WHERE server_time >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
            GROUP BY idgoal ORDER BY idgoal
        ", ARRAY_A) as $row) {
            $gid = (int)$row['idgoal'];
            $goal_conversions[] = [
                'goal_id'       => $gid,
                'name'          => $goal_defs[$gid] ?? "Goal {$gid}",
                'conversions'   => (int)$row['conversions'],
                'unique_visits' => (int)$row['unique_visits'],
            ];
            // Booking funnel mapping
            if ($gid === 1) $funnel['interesse']   = (int)$row['conversions'];
            if ($gid === 3) $funnel['intentie_nl'] = (int)$row['conversions'];
            if ($gid === 4) $funnel['intentie_en'] = (int)$row['conversions'];
            if ($gid === 2) $funnel['actie']       = (int)$row['conversions'];
            if ($gid === 5) $funnel['succes']      = (int)$row['conversions'];
        }
    }

    $intentie = $funnel['intentie_nl'] + $funnel['intentie_en'];
    $funnel_dropoff = [
        'interesse_to_intentie_pct' => $funnel['interesse'] > 0
            ? round(100.0 * $intentie / $funnel['interesse'], 1) : null,
        'intentie_to_actie_pct' => $intentie > 0
            ? round(100.0 * $funnel['actie'] / $intentie, 1) : null,
        'actie_to_succes_pct' => $funnel['actie'] > 0
            ? round(100.0 * $funnel['succes'] / $funnel['actie'], 1) : null,
    ];

    return [
        'success'      => true,
        'period_days'  => $days,
        'last_updated' => current_time('mysql'),

        // Totals
        'total_visits'        => (int)($stats->total_visits ?? 0),
        'unique_visitors'     => (int)($stats->unique_visitors ?? 0),
        'new_visitors'        => (int)($stats->new_visitors ?? 0),
        'returning_visitors'  => (int)($stats->returning_visitors ?? 0),
        'total_pageviews'     => (int)($stats->total_pageviews ?? 0),
        'avg_time_seconds'    => (int)($stats->avg_time_seconds ?? 0),
        'avg_pages_per_visit' => (float)($stats->avg_pages_per_visit ?? 0),
        'bounce_rate'         => (float)($stats->bounce_rate ?? 0),
        'visits_with_goal'    => (int)($stats->visits_with_goal ?? 0),

        // Acquisition
        'traffic_sources' => $raw_sources,
        'search_engines'  => $search_engines,
        'search_keywords' => $keywords,
        'social_networks' => $social,
        'ai_assistants'   => $ai_assistants,

        // Visitors
        'devices'   => $devices,
        'browsers'  => $browsers,
        'countries' => $countries,
        'cities'    => $cities,

        // Behaviour
        'top_pages'   => $top_pages,
        'entry_pages' => $entry_pages,
        'exit_pages'  => $exit_pages,
        'outlinks'    => $outlinks,

        // Goals & funnel
        'goal_conversions' => $goal_conversions,
        'funnel'           => $funnel,
        'funnel_dropoff'   => $funnel_dropoff,
    ];
}

/**
 * Get Matomo goals/conversion data for a post
 */
function get_matomo_goals_data($request) {
    $post_id = $request['id'];
    $post = get_post($post_id);
    
    if (!$post) {
        return new WP_Error('post_not_found', 'Post not found', ['status' => 404]);
    }
    
    $post_url = get_permalink($post_id);
    $goals_data = [];
    
    // Option 1: Use Matomo WordPress plugin API for goals
    if (class_exists('\Matomo\WordPress\WpMatomo')) {
        try {
            $matomo = \Matomo\WordPress\WpMatomo::get_instance();
            if ($matomo && method_exists($matomo, 'get_api')) {
                $api = $matomo->get_api();
                
                $goals = $api->get_goals();
                
                if ($goals && is_array($goals)) {
                    foreach ($goals as $goal) {
                        $goal_id = $goal['idgoal'] ?? null;
                        if ($goal_id) {
                            $conversions = $api->get_goal_conversions($goal_id, $post_url);
                            
                            if ($conversions) {
                                $goals_data[] = [
                                    'goal_id' => $goal_id,
                                    'goal_name' => $goal['name'] ?? 'Unknown Goal',
                                    'goal_type' => $goal['match_attribute'] ?? 'unknown',
                                    'conversions' => $conversions['nb_conversions'] ?? 0,
                                    'revenue' => $conversions['revenue'] ?? 0,
                                    'conversion_rate' => $conversions['conversion_rate'] ?? 0,
                                ];
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Matomo goals API error: ' . $e->getMessage());
        }
    }
    
    // Calculate totals
    $total_conversions = 0;
    $total_revenue = 0;
    
    foreach ($goals_data as $goal) {
        $total_conversions += $goal['conversions'];
        $total_revenue += $goal['revenue'];
    }
    
    // In production, return actual data only
    return [
        'post_id' => $post_id,
        'post_url' => $post_url,
        'goals' => $goals_data,
        'totals' => [
            'conversions' => $total_conversions,
            'revenue' => $total_revenue,
            'avg_conversion_value' => $total_conversions > 0 ? $total_revenue / $total_conversions : 0,
        ],
        'success' => !empty($goals_data),
        'source' => 'matomo_goals',
        'last_updated' => current_time('mysql'),
    ];
}

/**
 * Get VikBooking site-wide summary for the last 30 days
 * Uses tracking_infos for visitor/conversion data and orders for revenue
 */
function get_vikbooking_summary() {
    global $wpdb;

    $orders_table   = $wpdb->prefix . 'vikbooking_orders';
    $tracking_table = $wpdb->prefix . 'vikbooking_tracking_infos';

    if (!$wpdb->get_var("SHOW TABLES LIKE '{$orders_table}'")) {
        return new WP_Error('no_table', 'VikBooking orders table not found', ['status' => 404]);
    }

    // Total bookings, revenue and average nights for orders created in last 30 days
    // ts is a Unix timestamp; status can be 'confirmed' or 'paid'
    $orders = $wpdb->get_row(
        "SELECT
            COUNT(*) AS total_bookings,
            SUM(CASE WHEN total > 0 THEN total ELSE totpaid END) AS total_revenue,
            AVG(days) AS avg_nights
        FROM {$orders_table}
        WHERE status IN ('confirmed', 'paid')
        AND FROM_UNIXTIME(ts) >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );

    $total_bookings = (int)($orders->total_bookings ?? 0);
    $total_revenue  = (float)($orders->total_revenue ?? 0);
    $avg_nights     = round((float)($orders->avg_nights ?? 0), 1);

    $total_visitors      = 0;
    $converting_visitors = 0;
    $conversion_rate     = 0.0;
    $top_referrers       = [];

    if ($wpdb->get_var("SHOW TABLES LIKE '{$tracking_table}'")) {

        // Unique visitors and converting visitors from tracking
        $tracking = $wpdb->get_row(
            "SELECT
                COUNT(DISTINCT identifier) AS total_visitors,
                COUNT(DISTINCT CASE WHEN idorder > 0 THEN identifier END) AS converting_visitors
            FROM {$tracking_table}
            WHERE trackingdt >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        $total_visitors      = (int)($tracking->total_visitors ?? 0);
        $converting_visitors = (int)($tracking->converting_visitors ?? 0);
        $conversion_rate     = $total_visitors > 0
            ? round($converting_visitors / $total_visitors * 100, 2)
            : 0.0;

        // Top referrers grouped into relevant channels
        $referrer_rows = $wpdb->get_results(
            "SELECT
                CASE
                    WHEN referrer LIKE '%google%'         THEN 'Google'
                    WHEN referrer LIKE '%bedandbreakfast%' THEN 'Bed & Breakfast'
                    WHEN referrer IS NULL
                      OR referrer = ''
                      OR referrer LIKE '%logiesopdreef%'  THEN 'Direct / Eigen site'
                    ELSE 'Overig'
                END AS source,
                COUNT(DISTINCT identifier) AS visitors,
                COUNT(DISTINCT CASE WHEN idorder > 0 THEN identifier END) AS bookings,
                ROUND(
                    100 * COUNT(DISTINCT CASE WHEN idorder > 0 THEN identifier END)
                    / COUNT(DISTINCT identifier),
                    1
                ) AS conv_rate
            FROM {$tracking_table}
            WHERE trackingdt >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY source
            ORDER BY visitors DESC"
        );

        foreach ($referrer_rows as $row) {
            $top_referrers[] = [
                'source'    => $row->source,
                'visitors'  => (int)$row->visitors,
                'bookings'  => (int)$row->bookings,
                'conv_rate' => (float)$row->conv_rate,
            ];
        }
    }

    return [
        'success'             => true,
        'period_days'         => 30,
        'total_bookings'      => $total_bookings,
        'total_revenue'       => $total_revenue,
        'avg_nights'          => $avg_nights,
        'total_visitors'      => $total_visitors,
        'converting_visitors' => $converting_visitors,
        'conversion_rate'     => $conversion_rate,
        'top_referrers'       => $top_referrers,
        'last_updated'        => current_time('mysql'),
    ];
}

/**
 * Calculate days since last update
 */
function get_days_since_update($modified_date) {
    if (!$modified_date) {
        return 0;
    }
    
    $modified = new DateTime($modified_date);
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $interval = $now->diff($modified);
    
    return (int)$interval->days;
}

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    flush_rewrite_rules();
});

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});
