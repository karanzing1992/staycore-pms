<?php
if (!defined('ABSPATH')) exit;

final class StayCore_Public {
    public static function boot(): void {
        add_shortcode('staycore_self_checkin',[__CLASS__,'render']);
        add_shortcode('staycore_stay_feedback',[__CLASS__,'render_feedback']);
        add_filter('wp_robots',[__CLASS__,'robots']);
    }

    private static function ensure_named_page(string $slug,string $title,string $shortcode,string $option): int {
        $stored=(int)get_option($option,0);
        if($stored && get_post($stored)) return $stored;
        $page=get_page_by_path($slug,OBJECT,'page');
        if($page){ update_option($option,(int)$page->ID); return (int)$page->ID; }
        $id=wp_insert_post([
            'post_title'=>$title,
            'post_name'=>$slug,
            'post_type'=>'page',
            'post_status'=>'publish',
            'post_content'=>$shortcode,
            'comment_status'=>'closed',
        ],true);
        if(is_wp_error($id)) return 0;
        update_option($option,(int)$id);
        return (int)$id;
    }

    public static function ensure_page(): int {
        $checkin=self::ensure_named_page('self-check-in','Self Check-in','[staycore_self_checkin]','staycore_self_checkin_page_id');
        self::ensure_named_page('stay-feedback','How was your stay?','[staycore_stay_feedback]','staycore_feedback_page_id');
        return $checkin;
    }

    public static function robots(array $robots): array {
        $ids=[(int)get_option('staycore_self_checkin_page_id',0),(int)get_option('staycore_feedback_page_id',0)];
        if(is_page(array_filter($ids))){ $robots['noindex']=true; $robots['nofollow']=true; }
        return $robots;
    }

    private static function shell_styles(): string {
        return '<style>
        .staycore-guest{--ink:#171713;--muted:#74756d;--line:#deddd6;--bg:#f5f4ef;--card:#fff;max-width:620px;margin:40px auto;padding:18px;color:var(--ink);font-family:inherit}
        .scg-card{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:22px;box-shadow:0 18px 50px #0000000a}
        .scg-eyebrow{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin:0 0 8px}
        .staycore-guest h1{font-size:32px;line-height:1.05;margin:0 0 8px;letter-spacing:-.04em}
        .staycore-guest h2{font-size:22px;line-height:1.15;margin:0 0 8px}
        .scg-meta{color:var(--muted);font-size:13px;line-height:1.5}
        .scg-grid{display:grid;gap:12px;margin-top:18px}.scg-grid.two{grid-template-columns:1fr 1fr}
        .scg-field{display:grid;gap:6px;font-size:12px;color:var(--muted)}
        .scg-field input,.scg-field select,.scg-field textarea{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:13px;min-height:48px;padding:0 13px;font-size:16px;background:#fff;color:var(--ink)}
        .scg-field textarea{padding:12px 13px;min-height:120px;resize:vertical}
        .scg-summary{margin:18px 0 0;padding:14px;border-radius:16px;background:var(--bg);display:grid;gap:5px}.scg-summary strong{font-size:14px}.scg-summary span{font-size:12px;color:var(--muted)}
        .scg-actions{margin-top:18px;display:grid;gap:9px}.scg-button,.scg-link{border:0;border-radius:14px;min-height:50px;padding:0 18px;background:var(--ink);color:#fff!important;font-weight:700;font-size:15px;cursor:pointer;text-decoration:none!important;display:flex;align-items:center;justify-content:center;text-align:center}
        .scg-button.secondary,.scg-link.secondary{background:var(--bg);color:var(--ink)!important;border:1px solid var(--line)}
        .scg-note{font-size:12px;color:var(--muted);line-height:1.5;text-align:center}.scg-optional{margin-top:12px;border:1px solid var(--line);border-radius:13px;padding:0 12px}.scg-optional summary{cursor:pointer;padding:11px 0;font-size:12px;font-weight:700}.scg-optional[open]{padding-bottom:12px}
        .scg-status{padding:15px;border-radius:16px;background:var(--bg);font-size:14px;line-height:1.5}.scg-status.ok{background:#e7f4e9}.scg-status.wait{background:#fff3d9}.scg-status.error{background:#fde9e4}
        .scg-loading{padding:20px;text-align:center;color:var(--muted)}
        .scg-file{border:1px dashed var(--line);border-radius:14px;padding:12px;background:var(--bg)}.scg-file input{border:0;padding:8px 0;min-height:auto;background:transparent}
        .scg-required{font-size:11px;color:#8b3f2f}.scg-wa{display:grid;gap:10px;margin-top:18px}.scg-bubble{max-width:88%;padding:12px 14px;border-radius:16px;font-size:15px;line-height:1.45}.scg-bubble.them{background:var(--bg);border-bottom-left-radius:5px}.scg-bubble.me{background:#dcf8c6;margin-left:auto;border-bottom-right-radius:5px}
        .scg-choice{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.scg-choice button{min-height:58px;border-radius:15px;border:1px solid var(--line);background:#fff;font-size:16px;font-weight:700;cursor:pointer}
        .scg-choice button:hover{background:var(--bg)}.scg-help{margin-top:16px;padding:12px;border-radius:14px;background:#fff3d9;font-size:13px;line-height:1.5}
        @media(max-width:600px){.staycore-guest{margin:0 auto;padding:12px}.scg-card{border-radius:20px;padding:18px}.staycore-guest h1{font-size:28px}.scg-grid.two{grid-template-columns:1fr}}
        </style>';
    }

    public static function render(): string {
        $booking=absint($_GET['booking']??0);
        $token=sanitize_text_field(wp_unslash($_GET['token']??''));
        $root=esc_url_raw(rest_url('staycore/v1/self-checkin/'.$booking));
        $property=get_option('staycore_pms_settings',[]);
        $property_name=sanitize_text_field($property['property_name']??get_bloginfo('name'));
        ob_start(); ?>
        <div class="staycore-guest" id="staycore-checkin">
            <?php echo self::shell_styles(); ?>
            <div class="scg-card">
                <p class="scg-eyebrow"><?php echo esc_html($property_name); ?></p>
                <div id="staycore-ci-body"><div class="scg-loading">Loading your booking…</div></div>
            </div>
            <script>
            (()=>{const root=<?php echo wp_json_encode($root); ?>,token=<?php echo wp_json_encode($token); ?>,body=document.getElementById('staycore-ci-body');
            const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const pretty=v=>{const d=new Date(String(v||'').replace(' ','T'));return isNaN(d)?String(v||''):d.toLocaleString('en-IN',{day:'numeric',month:'short',hour:'numeric',minute:'2-digit'})};
            const api=async(method,payload)=>{const url=root+(method==='GET'?'?token='+encodeURIComponent(token):'');const r=await fetch(url,{method,headers:{'Content-Type':'application/json'},body:method==='GET'?undefined:JSON.stringify({...payload,token})});const d=await r.json();if(!r.ok)throw new Error(d.message||'Could not continue');return d};
            const done=(kind,title,msg)=>{body.innerHTML='<div class="scg-status '+kind+'"><strong>'+esc(title)+'</strong><div style="margin-top:5px">'+esc(msg)+'</div></div>'};
            const countryCodes='<option value="91" selected>🇮🇳 +91 India</option><option value="7">🇷🇺 +7 Russia</option><option value="44">🇬🇧 +44 UK</option><option value="1">🇺🇸 +1 US / Canada</option><option value="49">🇩🇪 +49 Germany</option><option value="33">🇫🇷 +33 France</option><option value="972">🇮🇱 +972 Israel</option><option value="971">🇦🇪 +971 UAE</option><option value="61">🇦🇺 +61 Australia</option><option value="39">🇮🇹 +39 Italy</option><option value="34">🇪🇸 +34 Spain</option><option value="31">🇳🇱 +31 Netherlands</option><option value="other">Other</option>';
            const compress=async file=>new Promise((resolve,reject)=>{if(!file){resolve('');return}if(!/^image\/(jpeg|png|webp)$/i.test(file.type)){reject(new Error('Please choose a JPG, PNG or WebP image.'));return}const img=new Image(),url=URL.createObjectURL(file);img.onload=()=>{try{const max=1600,scale=Math.min(1,max/Math.max(img.width,img.height)),w=Math.max(1,Math.round(img.width*scale)),h=Math.max(1,Math.round(img.height*scale)),canvas=document.createElement('canvas');canvas.width=w;canvas.height=h;canvas.getContext('2d').drawImage(img,0,0,w,h);URL.revokeObjectURL(url);resolve(canvas.toDataURL('image/jpeg',.82))}catch(e){reject(e)}};img.onerror=()=>{URL.revokeObjectURL(url);reject(new Error('Could not read the ID image.'))};img.src=url});
            const render=d=>{const m=d.missing||[],needPhone=m.includes('phone'),needId=m.includes('id'),needImage=m.includes('id_image'),needNationality=m.includes('nationality'),needEmail=m.includes('email');
                const required=(needPhone?'<div class="scg-grid two"><label class="scg-field">Country code<select name="country_code" required>'+countryCodes+'</select></label><label class="scg-field">Mobile number<input name="phone" inputmode="numeric" autocomplete="tel-national" required placeholder="Mobile number"></label></div>':'')+
                (needNationality?'<label class="scg-field">Nationality<input name="nationality" autocomplete="country-name" required placeholder="e.g. Indian"></label>':'')+
                (needId?'<div class="scg-grid two"><label class="scg-field">ID type<select name="id_type" required><option value="">Select</option><option>Passport</option><option>Aadhaar</option><option>Driving Licence</option><option>Other</option></select></label><label class="scg-field">ID number<input name="id_number" required autocomplete="off"></label></div>':'')+
                (needImage?'<label class="scg-field scg-file">Photo of ID <span class="scg-required">Required · clear photo</span><input name="id_image" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required></label>':'');
                const optional=needEmail?'<details class="scg-optional"><summary>Add email (optional)</summary><label class="scg-field">Email<input name="email" type="email" autocomplete="email"></label></details>':'';
                body.innerHTML='<h1>Hi '+esc(d.first_name||'there')+'</h1><div class="scg-meta">'+(d.can_checkin_now?'Confirm your guest details and you are done.':'Complete this now for a faster arrival.')+'</div><div class="scg-summary"><strong>'+esc(d.assignment||'Your stay')+'</strong><span>'+pretty(d.check_in)+' → '+pretty(d.check_out)+'</span><span>Booking '+esc(d.reference||'#'+d.id)+'</span></div><form id="staycore-ci-form"><div class="scg-grid">'+required+'</div>'+optional+'<div class="scg-actions"><button class="scg-button" type="submit">'+(d.can_checkin_now?'Confirm check-in':'Complete pre-check-in')+'</button><div class="scg-note">Country code, guest identity details and an ID image are required. Existing verified details are reused on future stays. Your ID image is kept in the private guest record for authorized staff only.</div></div></form>';
                const form=document.getElementById('staycore-ci-form'),cc=form.elements.country_code;if(cc)cc.onchange=()=>{if(cc.value==='other'){const v=prompt('Enter country calling code, numbers only (example 977)');cc.innerHTML+='<option value="'+String(v||'').replace(/\D/g,'')+'" selected>+'+String(v||'').replace(/\D/g,'')+'</option>'}};
                form.onsubmit=async e=>{e.preventDefault();if(!form.reportValidity())return;const fd=new FormData(form),p=Object.fromEntries(fd.entries()),file=form.elements.id_image?.files?.[0],btn=form.querySelector('button');btn.disabled=true;btn.textContent='Saving…';try{if(file)p.id_image_data=await compress(file);delete p.id_image;const r=await api('POST',p);if(r.state==='checked_in')done('ok','Checked in','You are checked in. Welcome!');else if(r.state==='waiting_housekeeping')done('wait','Almost ready','Your room or bed is being prepared. The front desk can complete check-in as soon as housekeeping clears it.');else done('ok','Details saved','Your pre-check-in is complete. You will not need to enter these details again at arrival.')}catch(err){btn.disabled=false;btn.textContent=d.can_checkin_now?'Confirm check-in':'Complete pre-check-in';done('error','Could not complete check-in',err.message)}};
            };
            if(!<?php echo wp_json_encode((bool)$booking && (bool)$token); ?>){done('error','Invalid check-in link','Please request a fresh self check-in link from the front desk.');return}
            api('GET').then(render).catch(e=>done('error','Invalid or expired link',e.message));
            })();
            </script>
        </div>
        <?php return (string)ob_get_clean();
    }

    public static function render_feedback(): string {
        $booking=absint($_GET['booking']??0);
        $token=sanitize_text_field(wp_unslash($_GET['token']??''));
        $root=esc_url_raw(rest_url('staycore/v1/feedback/'.$booking));
        $property=get_option('staycore_pms_settings',[]);
        $property_name=sanitize_text_field($property['property_name']??get_bloginfo('name'));
        ob_start(); ?>
        <div class="staycore-guest" id="staycore-feedback">
            <?php echo self::shell_styles(); ?>
            <div class="scg-card">
                <p class="scg-eyebrow"><?php echo esc_html($property_name); ?></p>
                <div id="staycore-fb-body"><div class="scg-loading">Opening your checkout message…</div></div>
            </div>
            <script>
            (()=>{const root=<?php echo wp_json_encode($root); ?>,token=<?php echo wp_json_encode($token); ?>,body=document.getElementById('staycore-fb-body');
            const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const api=async(method,payload)=>{const url=root+(method==='GET'?'?token='+encodeURIComponent(token):'');const r=await fetch(url,{method,headers:{'Content-Type':'application/json'},body:method==='GET'?undefined:JSON.stringify({...payload,token})});const d=await r.json();if(!r.ok)throw new Error(d.message||'Could not continue');return d};
            const error=msg=>body.innerHTML='<div class="scg-status error"><strong>Could not open feedback</strong><div style="margin-top:5px">'+esc(msg)+'</div></div>';
            const happy=d=>{const c=d.config||{},share='I had a great stay at '+(c.property_name||'Andaz Vibe Stay')+'. '+(c.site_url||''),review=c.review_url||'',ig=c.instagram_url||'https://www.instagram.com/';body.innerHTML='<h1>Thank you ❤️</h1><div class="scg-wa"><div class="scg-bubble them">That means a lot to us. Want to share the experience?</div><div class="scg-bubble me">Happy to.</div></div><div class="scg-actions">'+(review?'<a class="scg-link" target="_blank" rel="noopener" href="'+esc(review)+'">Leave a Google review</a>':'')+'<a class="scg-link secondary" target="_blank" rel="noopener" href="'+esc(ig)+'">Post about your stay on Instagram</a><a class="scg-link secondary" target="_blank" rel="noopener" href="https://wa.me/?text='+encodeURIComponent(share)+'">Recommend us on WhatsApp</a><button class="scg-button secondary" id="scg-copy">Copy recommendation text</button></div>';document.getElementById('scg-copy').onclick=async()=>{try{await navigator.clipboard.writeText(share);document.getElementById('scg-copy').textContent='Copied'}catch(e){prompt('Copy this',share)}}};
            const unhappy=d=>{const c=d.config||{},number=String(c.management_whatsapp||'').replace(/\D/g,''),message='Hi, I just checked out from '+(c.property_name||'Andaz Vibe Stay')+' ('+(d.reference||'booking')+'). I was not happy with part of my stay. '+(d.message||''),wa=number?'https://wa.me/'+number+'?text='+encodeURIComponent(message):'';body.innerHTML='<h1>We want to fix it.</h1><div class="scg-wa"><div class="scg-bubble them">Thank you for telling us privately. Management has this feedback in StayCore.</div><div class="scg-bubble me">'+esc(d.message||'I need help with my stay.')+'</div><div class="scg-bubble them">Message us now and we’ll work on it.</div></div><div class="scg-actions">'+(wa?'<a class="scg-link" target="_blank" rel="noopener" href="'+esc(wa)+'">Message management on WhatsApp</a>':'')+(c.review_url?'<a class="scg-link secondary" target="_blank" rel="noopener" href="'+esc(c.review_url)+'">Leave a public review</a>':'')+'</div><div class="scg-note">Your private feedback has already been saved to the booking.</div>'};
            const ask=d=>{body.innerHTML='<h1>How was your stay?</h1><div class="scg-wa"><div class="scg-bubble them">Thanks for staying with us, '+esc(d.first_name||'')+'. Before you go — how did we do?</div></div><div class="scg-choice"><button id="scg-happy">😊 Happy</button><button id="scg-unhappy">😕 Not happy</button></div><div id="scg-follow"></div>';
                document.getElementById('scg-happy').onclick=async()=>{try{happy(await api('POST',{sentiment:'happy'}))}catch(e){error(e.message)}};
                document.getElementById('scg-unhappy').onclick=()=>{const box=document.getElementById('scg-follow');box.innerHTML='<div class="scg-help"><strong>Tell management what went wrong.</strong><div class="scg-meta">This is saved privately to your booking.</div></div><label class="scg-field" style="margin-top:12px">Your message<textarea id="scg-message" required placeholder="What happened, and what can we do to make it right?"></textarea></label><div class="scg-actions"><button class="scg-button" id="scg-send">Send to management</button></div>';document.getElementById('scg-send').onclick=async()=>{const message=document.getElementById('scg-message').value.trim();if(message.length<3){document.getElementById('scg-message').focus();return}try{unhappy(await api('POST',{sentiment:'not_happy',message}))}catch(e){error(e.message)}}};
            };
            if(!<?php echo wp_json_encode((bool)$booking && (bool)$token); ?>){error('Please request a fresh checkout feedback link from the front desk.');return}
            api('GET').then(d=>{if(d.sentiment==='happy')happy(d);else if(d.sentiment==='not_happy')unhappy(d);else ask(d)}).catch(e=>error(e.message));
            })();
            </script>
        </div>
        <?php return (string)ob_get_clean();
    }
}
