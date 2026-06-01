<?php
/**
 * File:    dishdash-core/class-dd-insights.php
 * Purpose: AI Insights Engine — rule-based pattern detection over order data.
 *          Returns a ranked array of insight objects for display on Analytics page.
 *          Stateless: reads DB on each call, no caching (page is admin-only, low traffic).
 *          Designed for Phase 6 migration: each detect_* method maps to one ML model later.
 *
 * Last modified: v3.4.87
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DD_Insights {

    const INFO        = 'info';
    const OPPORTUNITY = 'opportunity';
    const WARNING     = 'warning';

    private \wpdb $wpdb;
    private string $ot;
    private string $oit;
    private string $ct;
    private int    $total_orders;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->ot    = $wpdb->prefix . 'dishdash_orders';
        $this->oit   = $wpdb->prefix . 'dishdash_order_items';
        $this->ct    = $wpdb->prefix . 'dishdash_customers';

        $this->total_orders = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$this->ot}` WHERE is_test = 0"
        );
    }

    /**
     * Returns the maturity state of the AI engine.
     * 'seed'      = < 50 orders  — no insights yet
     * 'growing'   = 50–199       — insights shown with confidence note
     * 'confident' = 200+         — full engine, all detectors active
     */
    public function get_maturity(): array {
        $count = $this->total_orders;
        if ( $count < 50 ) {
            return [
                'state'  => 'seed',
                'count'  => $count,
                'target' => 50,
                'pct'    => min( 100, round( ( $count / 50 ) * 100 ) ),
            ];
        }
        return [
            'state'  => $count < 200 ? 'growing' : 'confident',
            'count'  => $count,
            'target' => 200,
            'pct'    => 100,
        ];
    }

    /**
     * Main entry point. Returns array of insight arrays, each:
     * [ 'type', 'severity', 'icon', 'headline', 'detail', 'action_label', 'action_url' ]
     * Sorted: WARNING first, then OPPORTUNITY, then INFO.
     */
    public function get_insights(): array {
        if ( $this->total_orders < 50 ) return [];

        $insights = array_filter( array_merge(
            [ $this->detect_confirm_delay() ],
            [ $this->detect_cook_time_rising() ],
            [ $this->detect_peak_hour_slowdown() ],
            [ $this->detect_revenue_change() ],
            [ $this->detect_dead_hours() ],
            [ $this->detect_weak_day() ],
            [ $this->detect_rising_star_item() ],
            [ $this->detect_fading_item() ],
            [ $this->detect_vip_at_risk() ],
            [ $this->detect_loyalty_signal() ],
            [ $this->detect_aov_trend() ],
            [ $this->detect_cash_dominance() ],
            [ $this->detect_combo_opportunity() ],
            [ $this->detect_speed_loyalty_link() ]
        ) );

        $order = [ self::WARNING => 0, self::OPPORTUNITY => 1, self::INFO => 2 ];
        usort( $insights, function( $a, $b ) use ( $order ) {
            return ( $order[ $a['severity'] ] ?? 9 ) <=> ( $order[ $b['severity'] ] ?? 9 );
        });

        return array_values( $insights );
    }

    // ─── Private detectors ────────────────────────────────────────────────────

    private function detect_confirm_delay(): ?array {
        $avg_seconds = (float) $this->wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, confirmed_at))
             FROM `{$this->ot}`
             WHERE confirmed_at IS NOT NULL AND is_test = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        if ( $avg_seconds <= 0 ) return null;
        $avg_min = round( $avg_seconds / 60, 1 );
        if ( $avg_min <= 5 ) return null;

        return [
            'type'         => 'confirm_delay',
            'severity'     => self::WARNING,
            'icon'         => '⏱️',
            'headline'     => "Orders wait {$avg_min} min before confirmation",
            'detail'       => 'Industry best is under 2 minutes. Slow confirmation delays the entire order and can cause cancellations.',
            'action_label' => 'View Orders',
            'action_url'   => admin_url( 'admin.php?page=dish-dash-orders' ),
        ];
    }

    private function detect_cook_time_rising(): ?array {
        $this_month = (float) $this->wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, confirmed_at, ready_at))
             FROM `{$this->ot}`
             WHERE confirmed_at IS NOT NULL AND ready_at IS NOT NULL AND is_test = 0
               AND MONTH(created_at) = MONTH(CURDATE())
               AND YEAR(created_at)  = YEAR(CURDATE())"
        );
        $last_month = (float) $this->wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, confirmed_at, ready_at))
             FROM `{$this->ot}`
             WHERE confirmed_at IS NOT NULL AND ready_at IS NOT NULL AND is_test = 0
               AND MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
               AND YEAR(created_at)  = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))"
        );
        if ( $this_month <= 0 || $last_month <= 0 ) return null;
        $delta_pct = ( ( $this_month - $last_month ) / $last_month ) * 100;
        if ( $delta_pct < 20 ) return null;

        $pct_label = round( $delta_pct );
        $this_min  = round( $this_month );
        return [
            'type'         => 'cook_time_rising',
            'severity'     => self::WARNING,
            'icon'         => '🔥',
            'headline'     => "Cook time up {$pct_label}% this month (avg {$this_min} min)",
            'detail'       => "Average prep time was " . round($last_month) . " min last month. Rising cook time delays delivery and reduces repeat orders.",
            'action_label' => '',
            'action_url'   => '',
        ];
    }

    private function detect_peak_hour_slowdown(): ?array {
        $overall_avg = (float) $this->wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, delivered_at))
             FROM `{$this->ot}`
             WHERE delivered_at IS NOT NULL AND is_test = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $peak_avg = (float) $this->wpdb->get_var(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, delivered_at))
             FROM `{$this->ot}`
             WHERE delivered_at IS NOT NULL AND is_test = 0
               AND HOUR(created_at) BETWEEN 19 AND 21
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        if ( $overall_avg <= 0 || $peak_avg <= 0 ) return null;
        if ( $peak_avg < $overall_avg * 1.4 ) return null;

        $slow_min = round( $peak_avg );
        $pct      = round( ( ( $peak_avg - $overall_avg ) / $overall_avg ) * 100 );
        return [
            'type'         => 'peak_slowdown',
            'severity'     => self::WARNING,
            'icon'         => '🌙',
            'headline'     => "Dinner rush orders take {$slow_min} min ({$pct}% slower than average)",
            'detail'       => 'Orders placed between 7pm–10pm take significantly longer. Pre-prepping popular items before 7:30pm can help.',
            'action_label' => '',
            'action_url'   => '',
        ];
    }

    private function detect_revenue_change(): ?array {
        $this_week = (float) $this->wpdb->get_var(
            "SELECT COALESCE(SUM(total),0) FROM `{$this->ot}`
             WHERE status = 'delivered' AND is_test = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $last_week = (float) $this->wpdb->get_var(
            "SELECT COALESCE(SUM(total),0) FROM `{$this->ot}`
             WHERE status = 'delivered' AND is_test = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
               AND created_at <  DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        if ( $last_week <= 0 ) return null;
        $delta_pct = ( ( $this_week - $last_week ) / $last_week ) * 100;
        if ( abs( $delta_pct ) < 25 ) return null;

        $direction = $delta_pct > 0 ? 'up' : 'down';
        $pct_label = round( abs( $delta_pct ) );
        $severity  = $delta_pct > 0 ? self::INFO : self::WARNING;
        $icon      = $delta_pct > 0 ? '📈' : '📉';
        return [
            'type'         => 'revenue_change',
            'severity'     => $severity,
            'icon'         => $icon,
            'headline'     => "Revenue is {$direction} {$pct_label}% vs last week",
            'detail'       => 'This week: ' . number_format($this_week) . ' RWF vs last week: ' . number_format($last_week) . ' RWF.',
            'action_label' => 'See Revenue Chart',
            'action_url'   => '',
        ];
    }

    private function detect_dead_hours(): ?array {
        $rows = $this->wpdb->get_results(
            "SELECT HOUR(created_at) as hr, DATE(created_at) as day
             FROM `{$this->ot}`
             WHERE is_test = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             GROUP BY hr, day",
            ARRAY_A
        );
        $hours_with_orders = [];
        foreach ( $rows as $r ) {
            $h = (int) $r['hr'];
            $hours_with_orders[ $h ] = ( $hours_with_orders[ $h ] ?? 0 ) + 1;
        }
        $total_days = 14;
        for ( $h = 10; $h <= 20; $h++ ) {
            $h1_days = $hours_with_orders[ $h ]     ?? 0;
            $h2_days = $hours_with_orders[ $h + 1 ] ?? 0;
            if ( $h1_days <= ( $total_days * 0.3 ) && $h2_days <= ( $total_days * 0.3 ) ) {
                $h1_fmt = sprintf( '%02d:00', $h );
                $h2_fmt = sprintf( '%02d:00', $h + 2 );
                return [
                    'type'         => 'dead_hours',
                    'severity'     => self::OPPORTUNITY,
                    'icon'         => '😴',
                    'headline'     => "Almost no orders between {$h1_fmt}–{$h2_fmt}",
                    'detail'       => 'Consider a lunch special or promotion during this window to build a new order habit.',
                    'action_label' => '',
                    'action_url'   => '',
                ];
            }
        }
        return null;
    }

    private function detect_weak_day(): ?array {
        $rows = $this->wpdb->get_results(
            "SELECT DAYOFWEEK(created_at) as dow, COUNT(*) as cnt
             FROM `{$this->ot}`
             WHERE is_test = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
             GROUP BY dow ORDER BY cnt DESC",
            ARRAY_A
        );
        if ( count( $rows ) < 5 ) return null;
        $max_cnt   = (int) $rows[0]['cnt'];
        $day_names = [ 1=>'Sunday',2=>'Monday',3=>'Tuesday',4=>'Wednesday',5=>'Thursday',6=>'Friday',7=>'Saturday' ];
        foreach ( array_reverse( $rows ) as $r ) {
            if ( ( (int)$r['cnt'] / $max_cnt ) < 0.6 ) {
                $day = $day_names[ (int)$r['dow'] ] ?? 'that day';
                return [
                    'type'         => 'weak_day',
                    'severity'     => self::OPPORTUNITY,
                    'icon'         => '📅',
                    'headline'     => "{$day} is your quietest day — only " . round( ((int)$r['cnt']/$max_cnt)*100 ) . "% of your best day",
                    'detail'       => 'A weekly special or WhatsApp promotion on ' . $day . ' could level out revenue.',
                    'action_label' => '',
                    'action_url'   => '',
                ];
            }
        }
        return null;
    }

    private function detect_rising_star_item(): ?array {
        $this_month_rows = $this->wpdb->get_results(
            "SELECT oi.item_name, COUNT(*) as cnt
             FROM `{$this->oit}` oi
             JOIN `{$this->ot}` o ON o.id = oi.order_id
             WHERE o.is_test = 0
               AND MONTH(o.created_at) = MONTH(CURDATE())
               AND YEAR(o.created_at)  = YEAR(CURDATE())
             GROUP BY oi.item_name ORDER BY cnt DESC LIMIT 10",
            ARRAY_A
        );
        $last_month_rows = $this->wpdb->get_results(
            "SELECT oi.item_name, COUNT(*) as cnt
             FROM `{$this->oit}` oi
             JOIN `{$this->ot}` o ON o.id = oi.order_id
             WHERE o.is_test = 0
               AND MONTH(o.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
               AND YEAR(o.created_at)  = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
             GROUP BY oi.item_name ORDER BY cnt DESC",
            ARRAY_A
        );
        $last_map = [];
        foreach ( $last_month_rows as $r ) $last_map[ $r['item_name'] ] = (int) $r['cnt'];

        foreach ( $this_month_rows as $r ) {
            $name     = $r['item_name'];
            $this_cnt = (int) $r['cnt'];
            $last_cnt = $last_map[ $name ] ?? 0;
            if ( $last_cnt > 0 && ( ( $this_cnt - $last_cnt ) / $last_cnt ) >= 0.4 ) {
                $pct = round( ( ( $this_cnt - $last_cnt ) / $last_cnt ) * 100 );
                return [
                    'type'         => 'rising_star',
                    'severity'     => self::OPPORTUNITY,
                    'icon'         => '⭐',
                    'headline'     => esc_html( $name ) . " orders up {$pct}% this month",
                    'detail'       => "This item is trending. Consider featuring it prominently on the menu or creating a combo.",
                    'action_label' => '',
                    'action_url'   => '',
                ];
            }
        }
        return null;
    }

    private function detect_fading_item(): ?array {
        $last_top5 = $this->wpdb->get_col(
            "SELECT oi.item_name
             FROM `{$this->oit}` oi
             JOIN `{$this->ot}` o ON o.id = oi.order_id
             WHERE o.is_test = 0
               AND MONTH(o.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
               AND YEAR(o.created_at)  = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
             GROUP BY oi.item_name ORDER BY COUNT(*) DESC LIMIT 5"
        );
        $this_top10 = $this->wpdb->get_col(
            "SELECT oi.item_name
             FROM `{$this->oit}` oi
             JOIN `{$this->ot}` o ON o.id = oi.order_id
             WHERE o.is_test = 0
               AND MONTH(o.created_at) = MONTH(CURDATE())
               AND YEAR(o.created_at)  = YEAR(CURDATE())
             GROUP BY oi.item_name ORDER BY COUNT(*) DESC LIMIT 10"
        );
        foreach ( $last_top5 as $name ) {
            if ( ! in_array( $name, $this_top10, true ) ) {
                return [
                    'type'         => 'fading_item',
                    'severity'     => self::INFO,
                    'icon'         => '📉',
                    'headline'     => esc_html( $name ) . " was top 5 last month — now dropping",
                    'detail'       => 'This item may need a price review, a recipe refresh, or a promotional push.',
                    'action_label' => '',
                    'action_url'   => '',
                ];
            }
        }
        return null;
    }

    private function detect_vip_at_risk(): ?array {
        $vip = $this->wpdb->get_row(
            "SELECT name, whatsapp, total_orders,
                    DATEDIFF(NOW(), last_order_at) as days_silent
             FROM `{$this->ct}`
             WHERE total_orders >= 10
               AND last_order_at < DATE_SUB(NOW(), INTERVAL 21 DAY)
             ORDER BY total_orders DESC LIMIT 1",
            ARRAY_A
        );
        if ( ! $vip ) return null;
        $name  = esc_html( $vip['name'] ?: 'A loyal customer' );
        $days  = (int) $vip['days_silent'];
        $count = (int) $vip['total_orders'];
        return [
            'type'         => 'vip_at_risk',
            'severity'     => self::WARNING,
            'icon'         => '💔',
            'headline'     => "{$name} ({$count} orders) hasn't ordered in {$days} days",
            'detail'       => 'High-value customers who go quiet rarely return on their own. A personal WhatsApp message can win them back.',
            'action_label' => 'View Customers',
            'action_url'   => admin_url( 'admin.php?page=dish-dash-customers' ),
        ];
    }

    private function detect_loyalty_signal(): ?array {
        $total_rev = (float) $this->wpdb->get_var(
            "SELECT COALESCE(SUM(o.total),0) FROM `{$this->ot}` o
             WHERE o.status='delivered' AND o.is_test=0
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        if ( $total_rev <= 0 ) return null;
        $returning_rev = (float) $this->wpdb->get_var(
            "SELECT COALESCE(SUM(o.total),0)
             FROM `{$this->ot}` o
             JOIN `{$this->ct}` c ON c.id = o.customer_id
             WHERE o.status='delivered' AND o.is_test=0
               AND c.total_orders > 1
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $pct = round( ( $returning_rev / $total_rev ) * 100 );
        if ( $pct < 60 ) return null;
        return [
            'type'         => 'loyalty_signal',
            'severity'     => self::INFO,
            'icon'         => '🔁',
            'headline'     => "{$pct}% of your revenue comes from returning customers",
            'detail'       => 'Strong loyalty base. Protecting these customers from slow orders or stockouts is critical.',
            'action_label' => 'View Customers',
            'action_url'   => admin_url( 'admin.php?page=dish-dash-customers' ),
        ];
    }

    private function detect_aov_trend(): ?array {
        $this_aov = (float) $this->wpdb->get_var(
            "SELECT AVG(total) FROM `{$this->ot}`
             WHERE status='delivered' AND is_test=0
               AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())"
        );
        $last_aov = (float) $this->wpdb->get_var(
            "SELECT AVG(total) FROM `{$this->ot}`
             WHERE status='delivered' AND is_test=0
               AND MONTH(created_at)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))
               AND YEAR(created_at)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))"
        );
        if ( $last_aov <= 0 || $this_aov <= 0 ) return null;
        $delta_pct = ( ( $this_aov - $last_aov ) / $last_aov ) * 100;
        if ( abs( $delta_pct ) < 15 ) return null;

        $direction = $delta_pct > 0 ? 'up' : 'down';
        $pct_label = round( abs( $delta_pct ) );
        $severity  = $delta_pct > 0 ? self::INFO : self::WARNING;
        return [
            'type'         => 'aov_trend',
            'severity'     => $severity,
            'icon'         => $delta_pct > 0 ? '💰' : '⚠️',
            'headline'     => "Average order value is {$direction} {$pct_label}% vs last month",
            'detail'       => 'This month: ' . number_format( round($this_aov) ) . ' RWF vs last month: ' . number_format( round($last_aov) ) . ' RWF.',
            'action_label' => '',
            'action_url'   => '',
        ];
    }

    private function detect_cash_dominance(): ?array {
        $total = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM `{$this->ot}`
             WHERE is_test=0 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        if ( $total < 10 ) return null;
        $cash = (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM `{$this->ot}`
             WHERE is_test=0 AND payment_method='cod'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $pct = round( ( $cash / $total ) * 100 );
        if ( $pct < 50 ) return null;
        return [
            'type'         => 'cash_dominance',
            'severity'     => self::INFO,
            'icon'         => '💵',
            'headline'     => "{$pct}% of orders are Cash on Delivery",
            'detail'       => 'Consider promoting MoMo Pay with a small incentive (free delivery, discount) to reduce cash handling.',
            'action_label' => '',
            'action_url'   => '',
        ];
    }

    private function detect_combo_opportunity(): ?array {
        if ( $this->total_orders < 200 ) return null;

        $pair = $this->wpdb->get_row(
            "SELECT a.item_name as item_a, b.item_name as item_b, COUNT(*) as together
             FROM `{$this->oit}` a
             JOIN `{$this->oit}` b ON b.order_id = a.order_id AND b.item_name > a.item_name
             JOIN `{$this->ot}` o ON o.id = a.order_id
             WHERE o.is_test = 0
               AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             GROUP BY a.item_name, b.item_name
             ORDER BY together DESC LIMIT 1",
            ARRAY_A
        );
        if ( ! $pair || (int) $pair['together'] < 5 ) return null;

        $item_a_orders = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT order_id) FROM `{$this->oit}` WHERE item_name = %s",
            $pair['item_a']
        ) );
        if ( $item_a_orders <= 0 ) return null;
        $pct = round( ( (int)$pair['together'] / $item_a_orders ) * 100 );
        if ( $pct < 30 ) return null;

        $a = esc_html( $pair['item_a'] );
        $b = esc_html( $pair['item_b'] );
        return [
            'type'         => 'combo_opportunity',
            'severity'     => self::OPPORTUNITY,
            'icon'         => '🍱',
            'headline'     => "{$a} + {$b} ordered together {$pct}% of the time",
            'detail'       => "Create a named combo for these two items to increase AOV and make ordering faster.",
            'action_label' => '',
            'action_url'   => '',
        ];
    }

    private function detect_speed_loyalty_link(): ?array {
        if ( $this->total_orders < 200 ) return null;

        $fast_return_rate = (float) $this->wpdb->get_var(
            "SELECT AVG(c.total_orders)
             FROM `{$this->ot}` o
             JOIN `{$this->ct}` c ON c.id = o.customer_id
             WHERE o.delivered_at IS NOT NULL AND o.is_test = 0
               AND TIMESTAMPDIFF(MINUTE, o.created_at, o.delivered_at) < 30"
        );
        $slow_return_rate = (float) $this->wpdb->get_var(
            "SELECT AVG(c.total_orders)
             FROM `{$this->ot}` o
             JOIN `{$this->ct}` c ON c.id = o.customer_id
             WHERE o.delivered_at IS NOT NULL AND o.is_test = 0
               AND TIMESTAMPDIFF(MINUTE, o.created_at, o.delivered_at) > 45"
        );
        if ( $fast_return_rate <= 0 || $slow_return_rate <= 0 ) return null;
        if ( $fast_return_rate < $slow_return_rate * 1.2 ) return null;

        $diff = round( ( ( $fast_return_rate - $slow_return_rate ) / $slow_return_rate ) * 100 );
        return [
            'type'         => 'speed_loyalty',
            'severity'     => self::INFO,
            'icon'         => '⚡',
            'headline'     => "Customers served in <30 min reorder {$diff}% more often",
            'detail'       => 'Your data confirms: speed directly drives repeat orders. Every minute saved is a customer retained.',
            'action_label' => '',
            'action_url'   => '',
        ];
    }
}
