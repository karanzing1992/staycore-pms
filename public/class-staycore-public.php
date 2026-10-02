<?php
if (!defined('ABSPATH')) exit;

final class StayCore_Public {
    public static function boot(): void {
        add_shortcode('staycore_self_checkin',[__CLASS__,'render']);
        add_shortcode('staycore_stay_feedback',[__CLASS__,'render_feedback']);
        add_filter('wp_robots',[__CLASS__,'robots']);
        add_action('template_redirect',[__CLASS__,'privacy_page_headers']);
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

    public static function privacy_page_headers(): void {
        $ids=array_filter([(int)get_option('staycore_self_checkin_page_id',0),(int)get_option('staycore_feedback_page_id',0)]);
        if(!$ids || !is_page($ids)) return;
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow, noarchive',true);
        header('Referrer-Policy: no-referrer',true);
        header('X-Content-Type-Options: nosniff',true);
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0',true);
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
        .scg-camera{border:1px solid var(--line);border-radius:16px;padding:12px;background:var(--bg);display:grid;gap:10px}.scg-camera-stage{position:relative;overflow:hidden;border-radius:12px;background:#111;min-height:190px;display:grid;place-items:center}.scg-camera video,.scg-camera img{display:block;width:100%;max-height:360px;object-fit:contain}.scg-camera-guide{position:absolute;inset:14%;border:2px solid rgba(255,255,255,.88);border-radius:12px;pointer-events:none;box-shadow:0 0 0 999px rgba(0,0,0,.18)}.scg-camera-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.scg-confirm{display:flex;align-items:flex-start;gap:9px;color:var(--ink);font-size:13px;line-height:1.4}.scg-confirm input{width:20px;height:20px;min-height:0;margin-top:1px}.scg-spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(23,23,19,.18);border-top-color:currentColor;border-radius:50%;animation:scg-spin .75s linear infinite;vertical-align:-2px;margin-right:6px}@keyframes scg-spin{to{transform:rotate(360deg)}}
        .scg-required{font-size:11px;color:#8b3f2f}.scg-wa{display:grid;gap:10px;margin-top:18px}.scg-bubble{max-width:88%;padding:12px 14px;border-radius:16px;font-size:15px;line-height:1.45}.scg-bubble.them{background:var(--bg);border-bottom-left-radius:5px}.scg-bubble.me{background:#dcf8c6;margin-left:auto;border-bottom-right-radius:5px}
        .scg-choice{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.scg-choice button{min-height:58px;border-radius:15px;border:1px solid var(--line);background:#fff;font-size:16px;font-weight:700;cursor:pointer}
        .scg-choice button:hover{background:var(--bg)}.scg-help{margin-top:16px;padding:12px;border-radius:14px;background:#fff3d9;font-size:13px;line-height:1.5}
        .staycore-guest *{box-sizing:border-box}.scg-button,.scg-link,.scg-choice button,.scg-field input,.scg-field select,.scg-field textarea{touch-action:manipulation}.scg-button:focus-visible,.scg-link:focus-visible,.scg-choice button:focus-visible,.scg-field input:focus-visible,.scg-field select:focus-visible,.scg-field textarea:focus-visible{outline:3px solid rgba(23,23,19,.18);outline-offset:2px}
        .scg-card{overflow:hidden}.scg-progress{height:4px;background:var(--bg);border-radius:99px;overflow:hidden;margin-bottom:16px}.scg-progress span{display:block;height:100%;width:66%;background:var(--ink);border-radius:99px}.scg-file{min-height:92px;align-content:center}.scg-file input{font-size:14px}.scg-actions .scg-button,.scg-actions .scg-link{width:100%}.scg-camera .scg-button{width:100%}
        .scg-error-summary{margin:14px 0 0;padding:12px 13px;border:2px solid #b42318;border-radius:14px;background:#fff1f0;color:#7a271a}.scg-error-summary[hidden]{display:none!important}.scg-error-summary strong{display:block;font-size:13px;margin-bottom:5px}.scg-error-summary ul{margin:0;padding-left:20px;display:grid;gap:3px}.scg-error-summary a{color:#7a271a;font-size:12px;font-weight:700;text-decoration:underline}
        .scg-inline-error{display:flex;gap:6px;align-items:flex-start;color:#b42318;font-size:12px;line-height:1.35;font-weight:700}.scg-inline-error::before{content:"!";display:grid;place-items:center;flex:0 0 16px;width:16px;height:16px;border-radius:50%;background:#b42318;color:#fff;font-size:10px}
        .scg-field.scg-field-error input,.scg-field.scg-field-error select,.scg-field.scg-field-error textarea{border:2px solid #b42318;background:#fff8f7;box-shadow:0 0 0 3px rgba(180,35,24,.08)}.scg-field.scg-field-error{color:#7a271a}
        .scg-field.scg-field-valid input:not([aria-invalid="true"]),.scg-field.scg-field-valid select:not([aria-invalid="true"]){border-color:#6f9b78}
        .scg-confirm.scg-field-error{padding:9px;border:2px solid #b42318;border-radius:12px;background:#fff8f7}.scg-camera.scg-field-error{border:2px solid #b42318;background:#fff8f7}
        .scg-optional-label{font-size:11px;font-weight:500;color:var(--muted)}
        @media(max-width:600px){.staycore-guest{margin:0 auto;padding:max(10px,env(safe-area-inset-top)) 10px calc(18px + env(safe-area-inset-bottom));max-width:none;min-height:100dvh;background:var(--bg)}.scg-card{border-radius:18px;padding:18px 16px;min-height:calc(100dvh - 20px - env(safe-area-inset-bottom));display:flex;flex-direction:column;justify-content:flex-start;box-shadow:none}.staycore-guest h1{font-size:30px}.staycore-guest h2{font-size:24px}.scg-grid.two{grid-template-columns:1fr}.scg-grid{gap:14px}.scg-field{font-size:13px}.scg-field input,.scg-field select,.scg-field textarea{min-height:52px;border-radius:14px}.scg-summary{padding:15px;margin-top:16px}.scg-actions{margin-top:20px}.scg-button,.scg-link{min-height:54px;font-size:16px;border-radius:15px}.scg-choice{gap:9px}.scg-choice button{min-height:68px}.scg-bubble{max-width:92%;font-size:15px}.scg-note{text-align:left}.scg-status{margin-top:10px;padding:18px}.scg-file{padding:14px}}
        @media(prefers-reduced-motion:reduce){.staycore-guest *{scroll-behavior:auto!important;transition:none!important;animation:none!important}}
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
                <div class="scg-progress" aria-hidden="true"><span></span></div>
                <div id="staycore-ci-body"><div class="scg-loading"><span class="scg-spinner" aria-hidden="true"></span>Loading your booking…</div></div>
            </div>
            <script>
            (()=>{const root=<?php echo wp_json_encode($root); ?>,token=<?php echo wp_json_encode($token); ?>,body=document.getElementById('staycore-ci-body');
            const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const pretty=v=>{const d=new Date(String(v||'').replace(' ','T'));return isNaN(d)?String(v||''):d.toLocaleString('en-IN',{day:'numeric',month:'short',hour:'numeric',minute:'2-digit'})};
            const api=async(method,payload)=>{const url=root+(method==='GET'?'?token='+encodeURIComponent(token):'');const r=await fetch(url,{method,headers:{'Content-Type':'application/json'},body:method==='GET'?undefined:JSON.stringify({...payload,token})});const d=await r.json();if(!r.ok){const e=new Error(d.message||'Could not continue');e.code=d.code||'request_failed';e.data=d.data||{};throw e}return d};
            const done=(kind,title,msg)=>{body.innerHTML='<div class="scg-status '+kind+'"><strong>'+esc(title)+'</strong><div style="margin-top:5px">'+esc(msg)+'</div></div>'};
            const countryCodes='<option value="">Select country code</option><option value="91">🇮🇳 +91 India</option><option value="7">🇷🇺 +7 Russia / Kazakhstan</option><option value="44">🇬🇧 +44 UK</option><option value="1">🇺🇸 +1 US / Canada</option><option value="49">🇩🇪 +49 Germany</option><option value="33">🇫🇷 +33 France</option><option value="972">🇮🇱 +972 Israel</option><option value="971">🇦🇪 +971 UAE</option><option value="61">🇦🇺 +61 Australia</option><option value="39">🇮🇹 +39 Italy</option><option value="34">🇪🇸 +34 Spain</option><option value="31">🇳🇱 +31 Netherlands</option><option value="977">🇳🇵 +977 Nepal</option><option value="94">🇱🇰 +94 Sri Lanka</option><option value="380">🇺🇦 +380 Ukraine</option><option value="48">🇵🇱 +48 Poland</option><option value="other">Other</option>';
            let ocrPromise=null,cameraStream=null,capturedIdData='',ocrIdNumber='',idCaptureMethod='';
            const stopCamera=()=>{if(cameraStream){cameraStream.getTracks().forEach(t=>t.stop());cameraStream=null}};
            addEventListener('pagehide',stopCamera);
            const loadOCR=()=>ocrPromise||(ocrPromise=new Promise((resolve,reject)=>{if(window.Tesseract){resolve(window.Tesseract);return}const s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';s.async=true;s.onload=()=>resolve(window.Tesseract);s.onerror=()=>reject(new Error('Could not load ID scanner. Enter the ID details manually and confirm the captured photo.'));document.head.appendChild(s)}));
            const setScanStatus=(node,message,busy=false)=>{if(!node)return;node.innerHTML=(busy?'<span class="scg-spinner" aria-hidden="true"></span>':'')+esc(message||'')};
            const scanId=async(blob,status)=>{const T=await loadOCR();const r=await T.recognize(blob,'eng',{logger:m=>{if(!status)return;if(m.status==='loading tesseract core')setScanStatus(status,'Loading ID scanner…',true);else if(m.status==='initializing tesseract')setScanStatus(status,'Starting ID scanner…',true);else if(m.status==='recognizing text')setScanStatus(status,'Reading ID… '+Math.round((m.progress||0)*100)+'%',true)}});return String(r?.data?.text||'')};
            const fillIdFromText=(text,form)=>{const t=String(text||'').replace(/\r/g,' '),flat=t.replace(/\s+/g,' ').toUpperCase(),set=(n,v)=>{if(v&&form.elements[n]&&!String(form.elements[n].value||'').trim())form.elements[n].value=v};let type='',number='',nationality='',confident=false;
                if(/AADHAAR|UNIQUE IDENTIFICATION|GOVERNMENT OF INDIA/.test(flat)){type='Aadhaar';const m=flat.match(/\b(\d{4})\s?(\d{4})\s?(\d{4})\b/);if(m){number=m[1]+m[2]+m[3];confident=true}nationality='Indian'}
                else if(/DRIVING LICEN[CS]E|DL\s*(?:NO|NUMBER)/.test(flat)){type='Driving Licence';const m=flat.match(/\b[A-Z]{2}[\s-]?\d{2}[\s-]?(?:19|20)?\d{2}[\s-]?\d{7}\b/);if(m){number=m[0].replace(/[\s-]/g,'');confident=true}}
                else if(/PASSPORT/.test(flat)){type='Passport';let m=flat.match(/(?:PASSPORT\s*(?:NO|NUMBER)?\s*[:#-]?\s*)([A-Z0-9]{6,12})\b/);if(!m&&/REPUBLIC OF INDIA/.test(flat))m=flat.match(/\b([A-Z][0-9]{7})\b/);if(m){number=m[1]||m[0];confident=true}if(/REPUBLIC OF INDIA|NATIONALITY\s*[:\-]?\s*(?:IND|INDIAN)/.test(flat))nationality='Indian'}
                const nat=flat.match(/NATIONALITY\s*[:\-]?\s*([A-Z]{3,20})/);if(nat&&!nationality)nationality=nat[1][0]+nat[1].slice(1).toLowerCase();
                set('id_type',type);set('id_number',number);set('nationality',nationality);
                return {filled:[type&&'ID type',number&&'ID number',nationality&&'nationality'].filter(Boolean),number:confident?number:''};
            };
            const guestFieldWrap=f=>f?.closest('.scg-field,.scg-confirm')||f?.parentElement;
            const guestFieldId=f=>{if(!f)return'';if(!f.id)f.id='scg-'+String(f.name||'field').replace(/[^a-z0-9_-]/gi,'-');return f.id};
            const clearGuestFieldError=f=>{if(!f)return;const w=guestFieldWrap(f);w?.classList.remove('scg-field-error');f.removeAttribute('aria-invalid');const eid=f.getAttribute('aria-describedby');if(eid){document.getElementById(eid)?.remove();f.removeAttribute('aria-describedby')}};
            const setGuestFieldError=(f,msg)=>{if(!f||!msg)return;clearGuestFieldError(f);const w=guestFieldWrap(f);w?.classList.remove('scg-field-valid');const id=guestFieldId(f)+'-error',m=document.createElement('span');m.id=id;m.className='scg-inline-error';m.textContent=msg;f.setAttribute('aria-invalid','true');f.setAttribute('aria-describedby',id);w?.classList.add('scg-field-error');if(w)w.appendChild(m);else f.insertAdjacentElement('afterend',m)};
            const guestFieldMessage=(f,form)=>{if(!f)return'';const v=String(f.value||'').trim(),name=f.name;
                if(name==='country_code'&&!v)return'Select your country code.';
                if(name==='phone'){const n=v.replace(/\D/g,'');if(n.length<6||n.length>12)return'Enter 6 to 12 digits.';const cc=String(form.elements.country_code?.value||'');if(cc&&n.length>10&&n.startsWith(cc))return'Enter the phone number without the country code.'}
                if(name==='nationality'&&v.length<2)return'Enter your nationality.';
                if(name==='id_type'&&!v)return'Choose your ID type.';
                if(name==='id_number'){const n=v.replace(/[^a-z0-9]/gi,'');if(n.length<4)return'Enter your ID number.';if(form.elements.id_type?.value==='Aadhaar'&&v.replace(/\D/g,'').length!==12)return'Aadhaar number must contain 12 digits.'}
                if(name==='email'&&v&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v))return'Enter an email address like name@example.com.';
                if(name==='id_confirm'&&!f.checked)return'Confirm the ID photo matches your details.';
                return''};
            const validateGuestField=(f,form,{touch=false}={})=>{if(!f)return true;const hadError=f.hasAttribute('aria-invalid');if(touch)f.dataset.scTouched='1';clearGuestFieldError(f);guestFieldWrap(f)?.classList.remove('scg-field-valid');const msg=guestFieldMessage(f,form);if(msg){setGuestFieldError(f,msg);if(hadError||!form.querySelector('.scg-error-summary')?.hidden)syncGuestErrorSummary(form);return false}if(f.dataset.scTouched==='1'&&String(f.value||'').trim())guestFieldWrap(f)?.classList.add('scg-field-valid');if(hadError||!form.querySelector('.scg-error-summary')?.hidden)syncGuestErrorSummary(form);return true};
            const showGuestErrors=(form,errors)=>{const box=form.querySelector('.scg-error-summary');if(!box)return;if(!errors.length){box.hidden=true;box.innerHTML='';return}box.hidden=false;box.innerHTML='<strong>Check these details</strong><ul>'+errors.map(e=>'<li><a href="#'+esc(guestFieldId(e.field))+'">'+esc(e.message)+'</a></li>').join('')+'</ul>';box.querySelectorAll('a').forEach((a,i)=>a.onclick=e=>{e.preventDefault();errors[i].field.focus();errors[i].field.scrollIntoView({behavior:'smooth',block:'center'})});if(errors.length>1){box.focus();box.scrollIntoView({behavior:'smooth',block:'start'})}else{errors[0].field.focus();errors[0].field.scrollIntoView({behavior:'smooth',block:'center'})}};
            const syncGuestErrorSummary=form=>{const box=form.querySelector('.scg-error-summary');if(!box)return;const errors=[...form.querySelectorAll('[aria-invalid="true"]')].map(field=>{const id=field.getAttribute('aria-describedby'),node=id?document.getElementById(id):null;return{field,message:node?.textContent||'Check this field.'}});const camera=form.querySelector('.scg-camera.scg-field-error');if(camera&&!capturedIdData){const target=form.querySelector('#scg-open-gallery')||form.querySelector('#scg-open-camera');if(target)errors.push({field:target,message:'Add a clear photo of your ID.'})}if(!errors.length){box.hidden=true;box.innerHTML='';return}box.hidden=false;box.innerHTML='<strong>Check these details</strong><ul>'+errors.map(e=>'<li><a href="#'+esc(guestFieldId(e.field))+'">'+esc(e.message)+'</a></li>').join('')+'</ul>';box.querySelectorAll('a').forEach((a,i)=>a.onclick=e=>{e.preventDefault();errors[i].field.focus();errors[i].field.scrollIntoView({behavior:'smooth',block:'center'})})};
            const validateGuestForm=(form,{submit=false,needImage=false}={})=>{const errors=[];for(const name of ['country_code','phone','nationality','id_type','id_number','email','id_confirm']){const f=form.elements[name];if(!f)continue;if(submit)f.dataset.scTouched='1';const msg=guestFieldMessage(f,form);if(msg){setGuestFieldError(f,msg);errors.push({field:f,message:msg})}else{clearGuestFieldError(f);if(f.dataset.scTouched==='1'&&String(f.value||'').trim())guestFieldWrap(f)?.classList.add('scg-field-valid')}}const camera=form.querySelector('.scg-camera');if(camera){camera.classList.remove('scg-field-error');if(needImage&&!capturedIdData){camera.classList.add('scg-field-error');const target=form.querySelector('#scg-open-camera')||form.querySelector('#scg-open-gallery');if(target)errors.push({field:target,message:'Add a clear photo of your ID.'})}}showGuestErrors(form,errors);return{ok:!errors.length,errors}};
            const bindGuestValidation=(form,needImage)=>{for(const f of [...form.querySelectorAll('input[name],select[name]')]){f.addEventListener('blur',()=>validateGuestField(f,form,{touch:true}));f.addEventListener('change',()=>{f.dataset.scTouched='1';validateGuestField(f,form)});f.addEventListener('input',()=>{if(f.hasAttribute('aria-invalid'))validateGuestField(f,form)})}form.dataset.needImage=needImage?'1':''};
            const showGuestApiError=(form,err)=>{const map={phone_required:'phone',nationality_required:'nationality',id_required:'id_number',id_confirmation_required:'id_confirm',id_mismatch:'id_number'};const name=map[err.code],f=name?form.elements[name]:null;if(f){f.dataset.scTouched='1';setGuestFieldError(f,err.message);showGuestErrors(form,[{field:f,message:err.message}]);return true}if(['id_image_required','bad_id_image','id_image_resolution','id_image_too_large','id_source_required'].includes(err.code)){const camera=form.querySelector('.scg-camera'),target=form.querySelector('#scg-open-gallery')||form.querySelector('#scg-open-camera'),status=form.querySelector('#scg-id-status');camera?.classList.add('scg-field-error');if(status)status.textContent=err.message;if(target)showGuestErrors(form,[{field:target,message:err.message}]);return true}const box=form.querySelector('.scg-error-summary');if(box){box.hidden=false;box.innerHTML='<strong>Could not continue</strong><div>'+esc(err.message)+'</div>';box.focus();box.scrollIntoView({behavior:'smooth',block:'start'});return true}return false};
            const render=d=>{stopCamera();capturedIdData='';ocrIdNumber='';idCaptureMethod='';const m=d.missing||[],needPhone=m.includes('phone'),needId=m.includes('id'),needImage=m.includes('id_image'),needNationality=m.includes('nationality'),needEmail=m.includes('email'),showIdentity=needId||needNationality||needImage;
                const idOptions=['','Passport','Aadhaar','Driving Licence','Other'].map(v=>'<option value="'+esc(v)+'" '+(String(d.id_type||'')===v?'selected':'')+'>'+(v||'Select')+'</option>').join('');
                const phoneFields=needPhone?'<div class="scg-grid two"><label class="scg-field">Country *<select name="country_code">'+countryCodes+'</select></label><label class="scg-field">Phone *<span class="scg-meta">Number only — country code is selected separately</span><input name="phone" inputmode="numeric" autocomplete="tel-national"></label></div>':'';
                const identityFields=showIdentity?'<div class="scg-grid"><label class="scg-field">Nationality *<input name="nationality" value="'+esc(d.nationality||'')+'" autocomplete="country-name"></label><div class="scg-grid two"><label class="scg-field">ID type *<select name="id_type">'+idOptions+'</select></label><label class="scg-field">ID number *<input name="id_number" value="'+esc(d.id_number||'')+'" autocomplete="off"></label></div></div>':'';
                const camera=needImage?'<div class="scg-camera"><div><strong>ID photo *</strong><div class="scg-meta">Use the camera or choose a clear photo.</div></div><div class="scg-camera-stage"><video id="scg-id-video" playsinline muted hidden></video><img id="scg-id-preview" alt="ID preview" hidden><div class="scg-camera-guide" id="scg-camera-guide" hidden></div><div class="scg-meta" id="scg-camera-placeholder">No ID image selected yet.</div></div><canvas id="scg-id-canvas" hidden></canvas><input id="scg-id-gallery" type="file" accept="image/jpeg,image/png,image/webp" hidden><div class="scg-camera-actions"><button class="scg-button" type="button" id="scg-open-camera">Use camera</button><button class="scg-button secondary" type="button" id="scg-open-gallery">Choose photo</button><button class="scg-button" type="button" id="scg-capture-id" hidden>Take photo</button><button class="scg-button secondary" type="button" id="scg-retake-id" hidden>Choose another</button></div><span class="scg-meta" id="scg-id-status">No ID image added yet.</span><label class="scg-confirm"><input name="id_confirm" type="checkbox" value="1"><span>I confirm this image is the guest’s ID and the ID details above match the document.</span></label></div>':'';
                const optional=needEmail?'<details class="scg-optional"><summary>Email <span class="scg-optional-label">optional</span></summary><label class="scg-field">Email<input name="email" type="email" autocomplete="email"></label></details>':'';
                body.innerHTML='<h1>Hi '+esc(d.first_name||'there')+'</h1><div class="scg-meta">'+(d.can_checkin_now?'Confirm your details to check in.':'Add your details now for a faster arrival.')+'</div><div class="scg-summary"><strong>'+esc(d.assignment||'Your stay')+'</strong><span>'+pretty(d.check_in)+' → '+pretty(d.check_out)+'</span><span>Booking '+esc(d.reference||'#'+d.id)+'</span></div><form id="staycore-ci-form" novalidate><div class="scg-error-summary" role="alert" tabindex="-1" hidden></div><div class="scg-grid">'+phoneFields+identityFields+camera+'</div>'+optional+'<div class="scg-actions"><button class="scg-button" type="submit">'+(d.can_checkin_now?'Check in':'Save details')+'</button><div class="scg-note">Phone, nationality and ID are required before check-in.</div></div></form>';
                const form=document.getElementById('staycore-ci-form'),cc=form.elements.country_code,phone=form.elements.phone;bindGuestValidation(form,needImage);
                if(cc)cc.onchange=()=>{if(cc.value==='other'){const raw=prompt('Enter country calling code, numbers only (example 977)')||'',v=raw.replace(/\D/g,'').slice(0,3);if(!/^[1-9]\d{0,2}$/.test(v)){cc.value='';cc.dataset.scTouched='1';setGuestFieldError(cc,'Enter a country calling code with 1 to 3 digits.');return}const opt=document.createElement('option');opt.value=v;opt.textContent='+'+v+' Other';opt.selected=true;cc.appendChild(opt)}cc.dataset.scTouched='1';validateGuestField(cc,form)};
                if(phone)phone.oninput=()=>{phone.value=phone.value.replace(/\D/g,'').slice(0,12);if(phone.hasAttribute('aria-invalid'))validateGuestField(phone,form)};
                if(needImage){
                    const video=document.getElementById('scg-id-video'),preview=document.getElementById('scg-id-preview'),canvas=document.getElementById('scg-id-canvas'),open=document.getElementById('scg-open-camera'),galleryBtn=document.getElementById('scg-open-gallery'),gallery=document.getElementById('scg-id-gallery'),capture=document.getElementById('scg-capture-id'),retake=document.getElementById('scg-retake-id'),guide=document.getElementById('scg-camera-guide'),placeholder=document.getElementById('scg-camera-placeholder'),status=document.getElementById('scg-id-status');
                    const openCamera=async()=>{stopCamera();capturedIdData='';ocrIdNumber='';idCaptureMethod='';preview.hidden=true;retake.hidden=true;placeholder.hidden=true;setScanStatus(status,'Opening camera…',true);try{if(!navigator.mediaDevices?.getUserMedia)throw new Error('Camera access is not supported in this browser. Open this link in Chrome/Safari and allow camera access.');cameraStream=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:'environment'},width:{ideal:1920},height:{ideal:1080}}});video.srcObject=cameraStream;video.hidden=false;guide.hidden=false;capture.hidden=false;open.hidden=true;await video.play();setScanStatus(status,'Place the full ID inside the frame, keep text sharp, then take the photo.',false)}catch(err){stopCamera();video.hidden=true;guide.hidden=true;capture.hidden=true;open.hidden=false;placeholder.hidden=false;setScanStatus(status,err.message||'Camera could not be opened.',false)}};
                    const processImage=async(file,method)=>{if(!file||!/^image\/(jpeg|png|webp)$/i.test(file.type)){setScanStatus(status,'Choose a JPG, PNG or WebP image.',false);return}stopCamera();setScanStatus(status,'Preparing ID image…',true);try{const url=URL.createObjectURL(file),img=new Image();await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=reject;img.src=url});const max=1600,scale=Math.min(1,max/Math.max(img.width,img.height)),w=Math.max(1,Math.round(img.width*scale)),h=Math.max(1,Math.round(img.height*scale));canvas.width=w;canvas.height=h;canvas.getContext('2d').drawImage(img,0,0,w,h);URL.revokeObjectURL(url);capturedIdData=canvas.toDataURL('image/jpeg',.86);idCaptureMethod=method;form.querySelector('.scg-camera')?.classList.remove('scg-field-error');syncGuestErrorSummary(form);video.hidden=true;guide.hidden=true;capture.hidden=true;placeholder.hidden=true;preview.src=capturedIdData;preview.hidden=false;retake.hidden=false;open.hidden=false;galleryBtn.hidden=false;setScanStatus(status,(method==='gallery'?'Gallery image selected. ':'Camera photo captured. ')+'Checking the ID…',true);const blob=await (await fetch(capturedIdData)).blob(),parsed=fillIdFromText(await scanId(blob,status),form);ocrIdNumber=parsed.number||'';for(const name of ['id_type','id_number','nationality']){const field=form.elements[name];if(field&&field.value){field.dataset.scTouched='1';validateGuestField(field,form)}}setScanStatus(status,(method==='gallery'?'Gallery image selected. ':'Camera photo captured. ')+(parsed.filled.length?'Read '+parsed.filled.join(', ')+'. Confirm the details match the image.':'OCR could not confidently read the number; check the ID details manually and confirm them.'),false)}catch(err){setScanStatus(status,'Could not read that ID image. Choose another image or take a new photo.',false)}};
                    open.onclick=openCamera;galleryBtn.onclick=()=>gallery.click();gallery.onchange=()=>{const file=gallery.files?.[0];if(file)processImage(file,'gallery')};retake.onclick=()=>{stopCamera();gallery.value='';capturedIdData='';ocrIdNumber='';idCaptureMethod='';preview.hidden=true;retake.hidden=true;open.hidden=false;galleryBtn.hidden=false;placeholder.hidden=false;setScanStatus(status,'Choose rear camera or gallery.',false)};
                    capture.onclick=async()=>{if(!video.videoWidth||!video.videoHeight){setScanStatus(status,'Camera is not ready yet. Try again.',false);return}const max=1600,scale=Math.min(1,max/Math.max(video.videoWidth,video.videoHeight)),w=Math.max(1,Math.round(video.videoWidth*scale)),h=Math.max(1,Math.round(video.videoHeight*scale));canvas.width=w;canvas.height=h;canvas.getContext('2d').drawImage(video,0,0,w,h);capturedIdData=canvas.toDataURL('image/jpeg',.86);idCaptureMethod='camera';form.querySelector('.scg-camera')?.classList.remove('scg-field-error');syncGuestErrorSummary(form);stopCamera();video.hidden=true;guide.hidden=true;capture.hidden=true;preview.src=capturedIdData;preview.hidden=false;retake.hidden=false;open.hidden=false;galleryBtn.hidden=false;setScanStatus(status,'Camera photo captured. Checking the ID…',true);try{const blob=await (await fetch(capturedIdData)).blob(),parsed=fillIdFromText(await scanId(blob,status),form);ocrIdNumber=parsed.number||'';form.querySelector('.scg-camera')?.classList.remove('scg-field-error');for(const name of ['id_type','id_number','nationality']){const field=form.elements[name];if(field&&field.value){field.dataset.scTouched='1';validateGuestField(field,form)}}setScanStatus(status,parsed.filled.length?'Camera photo captured. Read '+parsed.filled.join(', ')+'. Confirm the details match the photo.':'Camera photo captured. OCR could not confidently read the number; check the ID details manually and confirm them.',false)}catch(err){setScanStatus(status,'Camera photo captured. '+(err.message||'Check the ID details manually before confirming.'),false)}};
                    setScanStatus(status,'Choose rear camera or gallery.',false);
                }
                form.onsubmit=async e=>{e.preventDefault();if(phone)phone.value=phone.value.replace(/\D/g,'').slice(0,12);const validation=validateGuestForm(form,{submit:true,needImage});if(!validation.ok)return;const fd=new FormData(form),p=Object.fromEntries(fd.entries()),btn=form.querySelector('button[type=submit]');if(needImage){p.id_image_data=capturedIdData;p.capture_method=idCaptureMethod||'gallery';p.ocr_id_number=ocrIdNumber}btn.disabled=true;btn.innerHTML='<span class="scg-spinner" aria-hidden="true"></span>Checking & saving…';try{const r=await api('POST',p);stopCamera();if(r.state==='checked_in')done('ok','Checked in','You’re checked in. Welcome!');else if(r.state==='waiting_housekeeping')done('wait','Almost ready','Your details are saved. Your room or bed is still being prepared.');else done('ok','Details saved','Your pre-check-in is complete.')}catch(err){btn.disabled=false;btn.textContent=d.can_checkin_now?'Check in':'Save details';showGuestApiError(form,err)}};
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
                <div class="scg-progress" aria-hidden="true"><span style="width:100%"></span></div>
                <div id="staycore-fb-body"><div class="scg-loading">Opening your checkout message…</div></div>
            </div>
            <script>
            (()=>{const root=<?php echo wp_json_encode($root); ?>,token=<?php echo wp_json_encode($token); ?>,body=document.getElementById('staycore-fb-body');
            const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const api=async(method,payload)=>{const url=root+(method==='GET'?'?token='+encodeURIComponent(token):'');const r=await fetch(url,{method,headers:{'Content-Type':'application/json'},body:method==='GET'?undefined:JSON.stringify({...payload,token})});const d=await r.json();if(!r.ok)throw new Error(d.message||'Could not continue');return d};
            const error=msg=>body.innerHTML='<div class="scg-status error"><strong>Could not open feedback</strong><div style="margin-top:5px">'+esc(msg)+'</div></div>';
            const happy=d=>{const c=d.config||{},share='I had a great stay at '+(c.property_name||'Andaz Vibe Stay')+'. '+(c.site_url||''),review=c.review_url||'',ig=c.instagram_url||'';body.innerHTML='<h1>Thank you ❤️</h1><div class="scg-wa"><div class="scg-bubble them">That means a lot to us. Want to share the experience?</div><div class="scg-bubble me">Happy to.</div></div><div class="scg-actions">'+(review?'<a class="scg-link" target="_blank" rel="noopener" href="'+esc(review)+'">Leave a Google review</a>':'')+'<button class="scg-button secondary" id="scg-share">Share your stay</button>'+(ig?'<a class="scg-link secondary" target="_blank" rel="noopener" href="'+esc(ig)+'">Post about your stay on Instagram</a>':'')+'<a class="scg-link secondary" target="_blank" rel="noopener" href="https://wa.me/?text='+encodeURIComponent(share)+'">Recommend us on WhatsApp</a><button class="scg-button secondary" id="scg-copy">Copy recommendation text</button></div>';document.getElementById('scg-share').onclick=async()=>{if(navigator.share){try{await navigator.share({title:c.property_name||'Andaz Vibe Stay',text:'I had a great stay.',url:c.site_url||location.origin})}catch(e){}}else{try{await navigator.clipboard.writeText(share);document.getElementById('scg-share').textContent='Copied share text'}catch(e){prompt('Copy this',share)}}};document.getElementById('scg-copy').onclick=async()=>{try{await navigator.clipboard.writeText(share);document.getElementById('scg-copy').textContent='Copied'}catch(e){prompt('Copy this',share)}}};
            const unhappy=d=>{const c=d.config||{},number=String(c.management_whatsapp||'').replace(/\D/g,''),message='Hi, I just checked out from '+(c.property_name||'Andaz Vibe Stay')+' ('+(d.reference||'booking')+'). I was not happy with part of my stay. '+(d.message||''),wa=number?'https://wa.me/'+number+'?text='+encodeURIComponent(message):'';body.innerHTML='<h1>We want to fix it.</h1><div class="scg-wa"><div class="scg-bubble them">Thank you for telling us privately. Management has this feedback in StayCore.</div><div class="scg-bubble me">'+esc(d.message||'I need help with my stay.')+'</div><div class="scg-bubble them">We’ll work on this directly with you.</div></div><div class="scg-actions">'+(wa?'<a class="scg-link" target="_blank" rel="noopener" href="'+esc(wa)+'">Message management on WhatsApp</a>':'')+'</div><div class="scg-note">Your feedback is private and has been sent into the service-recovery workflow.</div>'};
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
