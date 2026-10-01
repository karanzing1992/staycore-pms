<?php
if (!defined('ABSPATH')) exit;

final class StayCore_Staff {
    public static function boot(): void {
        add_action('template_redirect',[__CLASS__,'route'],0);
        add_action('admin_init',[__CLASS__,'redirect_staff_admin']);
        add_filter('show_admin_bar',[__CLASS__,'hide_admin_bar']);
        add_filter('login_redirect',[__CLASS__,'login_redirect'],10,3);
    }

    private static function is_staff_user(): bool {
        return is_user_logged_in() && (current_user_can('staycore_view_pms') || current_user_can('manage_options'));
    }

    private static function request_path(): string {
        $path=(string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        return '/' . trim($path,'/') . '/';
    }

    public static function hide_admin_bar(bool $show): bool {
        if(self::is_staff_user() && !current_user_can('manage_options')) return false;
        return $show;
    }

    public static function login_redirect(string $redirect_to,string $requested,$user): string {
        if($user instanceof WP_User && user_can($user,'staycore_view_pms') && !user_can($user,'manage_options')) return home_url('/staff/');
        return $redirect_to;
    }

    public static function redirect_staff_admin(): void {
        if(!self::is_staff_user() || current_user_can('manage_options') || wp_doing_ajax()) return;
        global $pagenow;
        if(in_array($pagenow,['admin-post.php','async-upload.php'],true)) return;
        wp_safe_redirect(home_url('/staff/'));
        exit;
    }

    public static function route(): void {
        $path=self::request_path();
        if($path==='/staff-login/') self::render_login();
        if($path==='/staff/') self::render_portal();
    }

    private static function security_headers(): void {
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0',true);
        header('Pragma: no-cache',true);
        header('X-Robots-Tag: noindex, nofollow, noarchive',true);
        header('Referrer-Policy: no-referrer',true);
        header('X-Content-Type-Options: nosniff',true);
        header("Content-Security-Policy: frame-ancestors 'self'",true);
    }

    private static function render_login(): void {
        self::security_headers();
        if(self::is_staff_user()){
            wp_safe_redirect(home_url('/staff/'));
            exit;
        }

        $error='';
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $nonce=sanitize_text_field(wp_unslash($_POST['_staycore_login_nonce']??''));
            if(!wp_verify_nonce($nonce,'staycore_staff_login')){
                $error='Your login session expired. Please try again.';
            } else {
                $creds=[
                    'user_login'=>sanitize_text_field(wp_unslash($_POST['log']??'')),
                    'user_password'=>(string)($_POST['pwd']??''),
                    'remember'=>!empty($_POST['rememberme']),
                ];
                $user=wp_signon($creds,is_ssl());
                if(is_wp_error($user)){
                    $error='Incorrect username/email or password.';
                } elseif(!user_can($user,'staycore_view_pms') && !user_can($user,'manage_options')){
                    wp_logout();
                    $error='This account does not have employee access.';
                } else {
                    wp_safe_redirect(home_url('/staff/'));
                    exit;
                }
            }
        }

        status_header(200);
        $action=esc_url(home_url('/staff-login/'));
        $logo=esc_html(get_option('staycore_pms_settings',[])['property_name']??'Andaz Vibe Stay');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Staff login · '.esc_html(get_bloginfo('name')).'</title>';
        echo '<style>
        :root{color-scheme:light;--ink:#171713;--muted:#77786f;--line:#dfded7;--bg:#f4f3ef;--card:#fff}
        *{box-sizing:border-box}html,body{margin:0;min-height:100%;background:var(--bg);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Arial,sans-serif}
        body{min-height:100dvh;display:grid;place-items:center;padding:20px}
        .sc-login{width:min(100%,420px);background:var(--card);border:1px solid var(--line);border-radius:24px;padding:24px;box-shadow:0 18px 60px rgba(0,0,0,.06)}
        .sc-login .brand{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin-bottom:9px}.sc-login h1{margin:0;font-size:30px;letter-spacing:-.04em}.sc-login p{color:var(--muted);line-height:1.5}
        .sc-login form{display:grid;gap:14px;margin-top:22px}.sc-login label{display:grid;gap:6px;font-size:12px;color:var(--muted)}.sc-login input[type=text],.sc-login input[type=password]{width:100%;min-height:52px;border:1px solid var(--line);border-radius:14px;padding:0 14px;font-size:16px;background:#fff}
        .sc-login .remember{display:flex;align-items:center;gap:8px;color:var(--ink)}.sc-login button{min-height:52px;border:0;border-radius:14px;background:var(--ink);color:#fff;font-size:15px;font-weight:700;cursor:pointer}
        .sc-login .error{padding:11px 12px;border-radius:12px;background:#fde8e3;font-size:13px;margin-top:14px}.sc-login .note{font-size:12px;text-align:center;margin-top:14px}
        </style></head><body><main class="sc-login"><div class="brand">'.$logo.'</div><h1>Employee login</h1><p>Sign in to open the staff dashboard.</p>';
        if($error) echo '<div class="error">'.esc_html($error).'</div>';
        echo '<form method="post" action="'.$action.'">';
        wp_nonce_field('staycore_staff_login','_staycore_login_nonce');
        echo '<label>Username or email<input name="log" type="text" autocomplete="username" required autofocus></label>';
        echo '<label>Password<input name="pwd" type="password" autocomplete="current-password" required></label>';
        echo '<label class="remember"><input name="rememberme" type="checkbox" value="1"> Keep me signed in on this device</label>';
        echo '<button type="submit">Open StayCore</button></form><div class="note">Employee access only</div></main></body></html>';
        exit;
    }

    private static function render_portal(): void {
        self::security_headers();
        if(!is_user_logged_in()){
            wp_safe_redirect(home_url('/staff-login/'));
            exit;
        }
        if(!self::is_staff_user()){
            status_header(403);
            echo '<!doctype html><html><body><p>Employee access required.</p></body></html>';
            exit;
        }

        $config=StayCore_Admin::client_config();
        $logout=wp_logout_url(home_url('/staff-login/'));
        $css=esc_url(STAYCORE_PMS_URL.'admin/assets/app.css?ver='.rawurlencode(STAYCORE_PMS_VERSION));
        $js=esc_url(STAYCORE_PMS_URL.'admin/assets/app.js?ver='.rawurlencode(STAYCORE_PMS_VERSION));
        $dash=esc_url(includes_url('css/dashicons.min.css'));
        status_header(200);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#f4f3ef"><title>StayCore Staff</title><link rel="stylesheet" href="'.$dash.'"><link rel="stylesheet" href="'.$css.'">';
        echo '<style>
        html,body{margin:0;min-height:100%;background:#f4f3ef;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Arial,sans-serif}.staycore-staff-portal .staycore-shell{margin:0!important}.staycore-staff-portal #staycore-app{padding-top:14px}
        .staycore-staff-portal .button{appearance:none;display:inline-flex;align-items:center;justify-content:center;border:1px solid #c9c8c1;border-radius:10px;background:#fff;color:#171713;text-decoration:none;padding:0 12px;cursor:pointer;font-weight:600}.staycore-staff-portal .button-primary{background:#171713!important;color:#fff!important;border-color:#171713!important}
        .sc-portal-bar{max-width:1080px;margin:0 auto;padding:10px 18px 0;display:flex;justify-content:flex-end}.sc-portal-bar a{font-size:12px;color:#77786f;text-decoration:none}
        @media(max-width:782px){.staycore-staff-portal .staycore-head{top:0!important}.staycore-staff-portal .sc-toolbar{top:70px!important}.sc-portal-bar{padding:8px 12px 0}.staycore-staff-portal #staycore-app{padding-top:4px}}
        </style></head><body class="staycore-staff-portal"><div class="sc-portal-bar"><a href="'.esc_url($logout).'">Log out</a></div>';
        StayCore_Admin::render();
        echo '<script>window.StayCorePMS='.wp_json_encode($config).';</script><script src="'.$js.'" defer></script></body></html>';
        exit;
    }
}
