<?php
if (!defined('ABSPATH')) exit;

final class StayCore_Admin {
    public static function boot(): void {
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
    }
    public static function menu(): void {
        add_menu_page('StayCore PMS','StayCore PMS','staycore_view_pms','staycore-pms',[__CLASS__,'render'],'dashicons-building',3);
    }
    public static function assets(string $hook): void {
        if($hook!=='toplevel_page_staycore-pms') return;
        wp_enqueue_style('staycore-pms',STAYCORE_PMS_URL.'admin/assets/app.css',[],STAYCORE_PMS_VERSION);
        wp_enqueue_script('staycore-pms',STAYCORE_PMS_URL.'admin/assets/app.js',[],STAYCORE_PMS_VERSION,true);
        wp_localize_script('staycore-pms','StayCorePMS',[
            'root'=>esc_url_raw(rest_url('staycore/v1/')),'nonce'=>wp_create_nonce('wp_rest'),'today'=>current_time('Y-m-d'),
            'settings'=>get_option('staycore_pms_settings',[]),
            'caps'=>[
                'reservations'=>current_user_can('staycore_manage_reservations')||current_user_can('manage_options'),
                'payments'=>current_user_can('staycore_manage_payments')||current_user_can('manage_options'),
                'housekeeping'=>current_user_can('staycore_manage_housekeeping')||current_user_can('manage_options'),
            ],
        ]);
    }
    public static function render(): void {
        if(!current_user_can('staycore_view_pms')&&!current_user_can('manage_options')) wp_die('Not allowed.');
        echo '<div class="wrap staycore-shell"><div id="staycore-app">';
        echo '<header class="staycore-head"><div><p class="eyebrow">ANDAZ VIBE STAY</p><h1>Front Desk</h1><p class="sc-today-label">'.esc_html(wp_date('D, j M')).'</p></div><button class="button button-primary sc-new" id="sc-new-booking">+ Booking</button></header>';
        echo '<section class="staycore-kpis" id="sc-kpis"></section><section id="sc-alerts"></section>';
        echo '<main id="sc-view"><div class="staycore-loading">Loading…</div></main>';
        echo '<nav class="staycore-tabs"><button data-tab="rooms" class="active"><span class="dashicons dashicons-building"></span><span>Rooms</span></button><button data-tab="bookings"><span class="dashicons dashicons-clipboard"></span><span>Bookings</span></button><button data-tab="calendar"><span class="dashicons dashicons-calendar-alt"></span><span>Calendar</span></button><button data-tab="more"><span class="dashicons dashicons-menu"></span><span>More</span></button></nav>';
        echo '<dialog id="sc-dialog"></dialog></div></div>';
    }
}
