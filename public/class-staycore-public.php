<?php
if (!defined('ABSPATH')) exit;

final class StayCore_Public {
    public static function boot(): void {
        add_shortcode('staycore_self_checkin',[__CLASS__,'render']);
        add_filter('wp_robots',[__CLASS__,'robots']);
    }

    public static function ensure_page(): int {
        $stored=(int)get_option('staycore_self_checkin_page_id',0);
        if($stored && get_post($stored)) return $stored;
        $page=get_page_by_path('self-check-in',OBJECT,'page');
        if($page){ update_option('staycore_self_checkin_page_id',(int)$page->ID); return (int)$page->ID; }
        $id=wp_insert_post([
            'post_title'=>'Self Check-in',
            'post_name'=>'self-check-in',
            'post_type'=>'page',
            'post_status'=>'publish',
            'post_content'=>'[staycore_self_checkin]',
            'comment_status'=>'closed',
        ],true);
        if(is_wp_error($id)) return 0;
        update_option('staycore_self_checkin_page_id',(int)$id);
        return (int)$id;
    }

    public static function robots(array $robots): array {
        $id=(int)get_option('staycore_self_checkin_page_id',0);
        if($id && is_page($id)){ $robots['noindex']=true; $robots['nofollow']=true; }
        return $robots;
    }

    public static function render(): string {
        $booking=absint($_GET['booking']??0);
        $token=sanitize_text_field(wp_unslash($_GET['token']??''));
        $root=esc_url_raw(rest_url('staycore/v1/self-checkin/'.$booking));
        $property=get_option('staycore_pms_settings',[]);
        $property_name=sanitize_text_field($property['property_name']??get_bloginfo('name'));
        ob_start(); ?>
        <div class="staycore-checkin" id="staycore-checkin">
            <style>
                .staycore-checkin{--ink:#171713;--muted:#74756d;--line:#deddd6;--bg:#f5f4ef;--card:#fff;max-width:620px;margin:40px auto;padding:18px;color:var(--ink);font-family:inherit}
                .staycore-ci-card{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:22px;box-shadow:0 18px 50px #0000000a}
                .staycore-ci-eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin:0 0 8px}
                .staycore-checkin h1{font-size:32px;line-height:1.05;margin:0 0 8px;letter-spacing:-.04em}
                .staycore-ci-meta{color:var(--muted);font-size:13px;line-height:1.5}
                .staycore-ci-grid{display:grid;gap:12px;margin-top:18px}
                .staycore-ci-grid.two{grid-template-columns:1fr 1fr}
                .staycore-ci-field{display:grid;gap:6px;font-size:12px;color:var(--muted)}
                .staycore-ci-field input,.staycore-ci-field select{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:13px;min-height:48px;padding:0 13px;font-size:16px;background:#fff;color:var(--ink)}
                .staycore-ci-summary{margin:18px 0 0;padding:14px;border-radius:16px;background:var(--bg);display:grid;gap:5px}
                .staycore-ci-summary strong{font-size:14px}.staycore-ci-summary span{font-size:12px;color:var(--muted)}
                .staycore-ci-actions{margin-top:18px;display:grid;gap:9px}
                .staycore-ci-button{border:0;border-radius:14px;min-height:50px;padding:0 18px;background:var(--ink);color:#fff;font-weight:700;font-size:15px;cursor:pointer}
                .staycore-ci-note{font-size:12px;color:var(--muted);line-height:1.5;text-align:center}.staycore-ci-optional{margin-top:12px;border:1px solid var(--line);border-radius:13px;padding:0 12px}.staycore-ci-optional summary{cursor:pointer;padding:11px 0;font-size:12px;font-weight:700}.staycore-ci-optional[open]{padding-bottom:12px}
                .staycore-ci-status{padding:15px;border-radius:16px;background:var(--bg);font-size:14px;line-height:1.5}
                .staycore-ci-status.ok{background:#e7f4e9}.staycore-ci-status.wait{background:#fff3d9}.staycore-ci-status.error{background:#fde9e4}
                .staycore-ci-loading{padding:20px;text-align:center;color:var(--muted)}
                @media(max-width:600px){.staycore-checkin{margin:0 auto;padding:12px}.staycore-ci-card{border-radius:20px;padding:18px}.staycore-checkin h1{font-size:28px}.staycore-ci-grid.two{grid-template-columns:1fr}}
            </style>
            <div class="staycore-ci-card">
                <p class="staycore-ci-eyebrow"><?php echo esc_html($property_name); ?></p>
                <div id="staycore-ci-body"><div class="staycore-ci-loading">Loading your booking…</div></div>
            </div>
            <script>
            (()=>{const root=<?php echo wp_json_encode($root); ?>,token=<?php echo wp_json_encode($token); ?>,body=document.getElementById('staycore-ci-body');
            const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const pretty=v=>{const d=new Date(String(v||'').replace(' ','T'));return isNaN(d)?String(v||''):d.toLocaleString('en-IN',{day:'numeric',month:'short',hour:'numeric',minute:'2-digit'})};
            const api=async(method,payload)=>{const url=root+(method==='GET'?'?token='+encodeURIComponent(token):'');const r=await fetch(url,{method,headers:{'Content-Type':'application/json'},body:method==='GET'?undefined:JSON.stringify({...payload,token})});const d=await r.json();if(!r.ok)throw new Error(d.message||'Could not continue');return d};
            const done=(kind,title,msg)=>{body.innerHTML='<div class="staycore-ci-status '+kind+'"><strong>'+esc(title)+'</strong><div style="margin-top:5px">'+esc(msg)+'</div></div>'};
            const render=d=>{const missing=d.missing||[],optional=missing.filter(x=>x!=='phone');let optionalHtml='';if(optional.length)optionalHtml='<details class="staycore-ci-optional"><summary>Add optional missing details</summary><div class="staycore-ci-grid">'+(missing.includes('email')?'<label class="staycore-ci-field">Email<input name="email" type="email" autocomplete="email"></label>':'')+(missing.includes('nationality')?'<label class="staycore-ci-field">Nationality<input name="nationality" autocomplete="country-name"></label>':'')+(missing.includes('id')?'<div class="staycore-ci-grid two"><label class="staycore-ci-field">ID type<select name="id_type"><option value="">Select</option><option>Passport</option><option>Aadhaar</option><option>Driving Licence</option><option>Other</option></select></label><label class="staycore-ci-field">ID number<input name="id_number"></label></div>':'')+'</div></details>';body.innerHTML='<h1>Hi '+esc(d.first_name||'there')+'</h1><div class="staycore-ci-meta">'+(d.can_checkin_now?'Confirm and you are done.':'Complete this now for a faster arrival.')+'</div><div class="staycore-ci-summary"><strong>'+esc(d.assignment||d.stay_type_label||'Your stay')+'</strong><span>'+pretty(d.check_in)+' → '+pretty(d.check_out)+'</span><span>Booking '+esc(d.reference||'#'+d.id)+'</span></div><form id="staycore-ci-form"><div class="staycore-ci-grid">'+(missing.includes('phone')?'<label class="staycore-ci-field">Mobile number<input name="phone" inputmode="tel" autocomplete="tel" required></label>':'')+'</div>'+optionalHtml+'<div class="staycore-ci-actions"><button class="staycore-ci-button" type="submit">'+(d.can_checkin_now?'Confirm check-in':'Complete pre-check-in')+'</button><div class="staycore-ci-note">Existing guest details are reused automatically.</div></div></form>';
                document.getElementById('staycore-ci-form').onsubmit=async e=>{e.preventDefault();const p=Object.fromEntries(new FormData(e.target).entries());const btn=e.target.querySelector('button');btn.disabled=true;btn.textContent='Confirming…';try{const r=await api('POST',p);if(r.state==='checked_in')done('ok','Checked in','You are checked in. Welcome!');else if(r.state==='waiting_housekeeping')done('wait','Almost ready','Your room or bed is being prepared. The front desk can complete check-in as soon as housekeeping clears it.');else done('ok','Details saved','Your pre-check-in is complete. You will not need to enter these details again at arrival.')}catch(err){done('error','Could not complete check-in',err.message)}}};
            if(!<?php echo wp_json_encode((bool)$booking && (bool)$token); ?>){done('error','Invalid check-in link','Please request a fresh self check-in link from the front desk.');return}
            api('GET').then(render).catch(e=>done('error','Invalid or expired link',e.message));
            })();
            </script>
        </div>
        <?php return (string)ob_get_clean();
    }
}
