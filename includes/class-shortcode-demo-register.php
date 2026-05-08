<?php
namespace CB\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode: [cb_demo_register]
 *
 * Renders a standalone demo-class registration form with:
 *   - Student Name
 *   - Parent's Phone Number
 *   - Course dropdown (all published cb_course posts)
 *   - OTP verification step
 *
 * Saves confirmed registrations to wp_cb_demo_registrations — the same
 * table used by the single course page form.
 *
 * Usage: add [cb_demo_register] to any page/post.
 */
class Shortcode_Demo_Register {

    public function init(): void {
        add_shortcode( 'cb_demo_register', [ $this, 'render' ] );

        // Enqueue nonce on every frontend page so the shortcode works
        // wherever it is placed (theme may not call wp_enqueue_scripts
        // the same way the singular-course loader does).
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function enqueue_assets(): void {
        // Only enqueue when the shortcode is actually on the current page.
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'cb_demo_register' ) ) {
            return;
        }
        wp_enqueue_script( 'jquery' );
        wp_localize_script( 'jquery', 'CB_Public', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cb_demo_nonce' ),
        ] );
    }

    public function render( $atts ): string {
        $atts = shortcode_atts( [], $atts, 'cb_demo_register' );

        // Fetch all published courses for the dropdown.
        $courses = get_posts( [
            'post_type'      => 'cb_course',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    'key'     => '_cb_course_active',
                    'value'   => '1',
                    'compare' => '=',
                ],
                [
                    'key'     => '_cb_course_active',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );

        // Unique prefix so multiple shortcodes on one page don't conflict.
        $uid = 'cbdr_' . wp_rand( 1000, 9999 );

        // Inline nonce fallback (used when CB_Public is not yet defined by
        // the main enqueue — e.g. shortcode inside a widget area).
        $nonce = wp_create_nonce( 'cb_demo_nonce' );

        ob_start();
        ?>
        <div id="<?php echo esc_attr( $uid ); ?>" class="cbdr-wrap">

            <!-- Step 1: Name + Phone + Course -->
            <form id="<?php echo esc_attr( $uid ); ?>-step1" class="cbdr-form" onsubmit="cbdrSendOtp_<?php echo esc_attr( $uid ); ?>(event)">

                <div class="cbdr-field">
                    <input type="text"
                           id="<?php echo esc_attr( $uid ); ?>-name"
                           placeholder="Student Name"
                           required>
                </div>

                <div class="cbdr-field">
                    <input type="tel"
                           id="<?php echo esc_attr( $uid ); ?>-phone"
                           placeholder="Parent's Phone Number"
                           required>
                </div>

                <div class="cbdr-field cbdr-select-wrap">
                    <span class="cbdr-select-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    </span>
                    <select id="<?php echo esc_attr( $uid ); ?>-course" class="cbdr-course-select" required>
                        <option value="" disabled selected>-- Select a Course --</option>
                        <?php foreach ( $courses as $course ) : ?>
                        <option value="<?php echo esc_attr( $course->ID ); ?>">
                            <?php echo esc_html( $course->post_title ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="cbdr-select-arrow">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                    </span>
                </div>

                <div class="cbdr-err" id="<?php echo esc_attr( $uid ); ?>-err1" style="display:none"></div>

                <button type="submit" class="cbdr-btn" id="<?php echo esc_attr( $uid ); ?>-send-btn">
                    Register Now
                </button>

            </form>

            <!-- Step 2: OTP entry (hidden initially) -->
            <form id="<?php echo esc_attr( $uid ); ?>-step2"
                  class="cbdr-form"
                  style="display:none"
                  onsubmit="cbdrVerifyOtp_<?php echo esc_attr( $uid ); ?>(event)">

                <p class="cbdr-otp-hint">
                    A 6-digit OTP has been sent to
                    <strong id="<?php echo esc_attr( $uid ); ?>-phone-display"></strong>.
                    Enter it below to confirm your registration.
                </p>

                <div class="cbdr-field">
                    <input type="text"
                           id="<?php echo esc_attr( $uid ); ?>-otp"
                           placeholder="Enter 6-digit OTP"
                           maxlength="6"
                           pattern="\d{6}"
                           required
                           style="letter-spacing:4px;font-size:20px;font-weight:700;text-align:center">
                </div>

                <div class="cbdr-err" id="<?php echo esc_attr( $uid ); ?>-err2" style="display:none"></div>

                <div class="cbdr-btn-row">
                    <button type="submit" class="cbdr-btn" id="<?php echo esc_attr( $uid ); ?>-verify-btn">
                        Verify &amp; Confirm
                    </button>
                    <button type="button"
                            class="cbdr-btn cbdr-btn--ghost"
                            onclick="cbdrResend_<?php echo esc_attr( $uid ); ?>()">
                        Resend OTP
                    </button>
                </div>

            </form>

            <!-- Success message -->
            <div id="<?php echo esc_attr( $uid ); ?>-ok" class="cbdr-ok" style="display:none">
                ✓ Registration confirmed! We will contact you shortly to schedule your demo class.
            </div>

        </div><!-- /.cbdr-wrap -->

        <style>
        #<?php echo esc_attr( $uid ); ?>{font-family:system-ui,-apple-system,sans-serif;box-sizing:border-box;;margin:0 auto}
        #<?php echo esc_attr( $uid ); ?> *,#<?php echo esc_attr( $uid ); ?> *::before,#<?php echo esc_attr( $uid ); ?> *::after{box-sizing:border-box}
        #<?php echo esc_attr( $uid ); ?> .cbdr-form{display:flex;flex-direction:column;gap:12px}
        #<?php echo esc_attr( $uid ); ?> .cbdr-field{position:relative;width:100%}
        #<?php echo esc_attr( $uid ); ?> .cbdr-field input,
        #<?php echo esc_attr( $uid ); ?> .cbdr-field select{
            width:100%;padding:14px 18px;font-size:15px;font-family:inherit;
            border:1.5px solid #d1d5db;border-radius:10px;outline:none;
            background:#fff;color:#1e293b;appearance:none;-webkit-appearance:none;
            transition:border-color .2s,box-shadow .2s}
        #<?php echo esc_attr( $uid ); ?> .cbdr-field input:focus,
        #<?php echo esc_attr( $uid ); ?> .cbdr-field select:focus{border-color:#244092;box-shadow:0 0 0 3px rgba(36,64,146,.12)}
        #<?php echo esc_attr( $uid ); ?> .cbdr-select-wrap{position:relative;background:#f8fafc;border-radius:10px}
        #<?php echo esc_attr( $uid ); ?> .cbdr-select-wrap select{padding-left:44px;padding-right:44px;color:#64748b;font-weight:500;cursor:pointer}
        #<?php echo esc_attr( $uid ); ?> .cbdr-select-wrap select.cbdr-selected{color:#1e293b}
        #<?php echo esc_attr( $uid ); ?> .cbdr-select-icon{
            position:absolute;left:14px;top:50%;transform:translateY(-50%);
            pointer-events:none;color:#244092;display:flex;align-items:center}
        #<?php echo esc_attr( $uid ); ?> .cbdr-select-arrow{
            position:absolute;right:14px;top:50%;transform:translateY(-50%);
            pointer-events:none;color:#64748b;display:flex;align-items:center;
            transition:transform .2s}
        #<?php echo esc_attr( $uid ); ?> .cbdr-select-wrap:focus-within .cbdr-select-arrow{transform:translateY(-50%) rotate(180deg)}
        #<?php echo esc_attr( $uid ); ?> .cbdr-select-wrap:focus-within{background:#fff}
        #<?php echo esc_attr( $uid ); ?> .cbdr-select-wrap:focus-within .cbdr-select-icon{color:#EF3E26}
        #<?php echo esc_attr( $uid ); ?> .cbdr-btn{
            width:100%;padding:16px;font-size:16px;font-weight:700;font-family:inherit;
            background:#EF3E26;color:#fff;border:none;border-radius:50px;cursor:pointer;
            transition:background .2s,transform .15s;margin-top:4px}
        #<?php echo esc_attr( $uid ); ?> .cbdr-btn:hover{background:#d63418;transform:translateY(-1px)}
        #<?php echo esc_attr( $uid ); ?> .cbdr-btn:disabled{background:#94a3b8;cursor:not-allowed;transform:none}
        #<?php echo esc_attr( $uid ); ?> .cbdr-btn--ghost{background:#64748b;margin-top:0}
        #<?php echo esc_attr( $uid ); ?> .cbdr-btn--ghost:hover{background:#475569}
        #<?php echo esc_attr( $uid ); ?> .cbdr-btn-row{display:flex;gap:10px}
        #<?php echo esc_attr( $uid ); ?> .cbdr-btn-row .cbdr-btn{flex:1;margin-top:0}
        #<?php echo esc_attr( $uid ); ?> .cbdr-err{color:#dc2626;font-size:13px;padding:8px 12px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca}
        #<?php echo esc_attr( $uid ); ?> .cbdr-ok{color:#166534;font-size:15px;font-weight:600;padding:16px 20px;background:#f0fdf4;border-radius:10px;border:1.5px solid #86efac;text-align:center}
        #<?php echo esc_attr( $uid ); ?> .cbdr-otp-hint{font-size:14px;color:#475569;margin:0 0 4px;line-height:1.6}
        </style>

        <script>
        (function(){
            var UID   = <?php echo json_encode( $uid ); ?>;
            var NONCE = <?php echo json_encode( $nonce ); ?>;
            var AJAX  = (typeof CB_Public !== 'undefined' && CB_Public.ajax_url)
                        ? CB_Public.ajax_url
                        : <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            var _phone = '';

            function $id(s){ return document.getElementById(UID + '-' + s); }

            // Style the select differently once a real course is chosen.
            var courseEl = $id('course');
            if (courseEl) {
                courseEl.addEventListener('change', function() {
                    this.classList.toggle('cbdr-selected', !!this.value);
                });
            }

            function showErr(step, msg){
                var el = $id('err' + step);
                el.textContent    = msg;
                el.style.display  = 'block';
            }
            function hideErr(step){
                $id('err' + step).style.display = 'none';
            }

            window['cbdrSendOtp_' + UID] = function(e) {
                e.preventDefault();
                hideErr(1);
                var name     = $id('name').value.trim();
                var phone    = $id('phone').value.trim();
                var courseEl = $id('course');
                var courseId = courseEl ? courseEl.value : '0';
                var btn      = $id('send-btn');

                if ( ! courseId ) {
                    showErr(1, 'Please select a course.');
                    return;
                }

                btn.disabled    = true;
                btn.textContent = 'Sending OTP…';

                fetch(AJAX, {
                    method : 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body   : new URLSearchParams({
                        action   : 'cb_demo_send_otp',
                        nonce    : (typeof CB_Public !== 'undefined' ? CB_Public.nonce : NONCE),
                        name     : name,
                        phone    : phone,
                        course_id: courseId
                    })
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res.success) {
                        _phone = res.data.phone || phone;
                        $id('phone-display').textContent = _phone;
                        $id('step1').style.display = 'none';
                        $id('step2').style.display = 'flex';
                        $id('otp').focus();
                    } else {
                        showErr(1, res.data && res.data.message ? res.data.message : 'Something went wrong.');
                        btn.disabled    = false;
                        btn.textContent = 'Register Now';
                    }
                })
                .catch(function(){
                    showErr(1, 'Network error. Please try again.');
                    btn.disabled    = false;
                    btn.textContent = 'Register Now';
                });
            };

            window['cbdrVerifyOtp_' + UID] = function(e) {
                e.preventDefault();
                hideErr(2);
                var otp      = $id('otp').value.trim();
                var name     = $id('name').value.trim();
                var courseEl = $id('course');
                var courseId = courseEl ? courseEl.value : '0';
                var btn      = $id('verify-btn');

                btn.disabled    = true;
                btn.textContent = 'Verifying…';

                fetch(AJAX, {
                    method : 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body   : new URLSearchParams({
                        action   : 'cb_demo_verify_otp',
                        nonce    : (typeof CB_Public !== 'undefined' ? CB_Public.nonce : NONCE),
                        name     : name,
                        phone    : _phone,
                        otp      : otp,
                        course_id: courseId
                    })
                })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res.success) {
                        $id('step2').style.display = 'none';
                        $id('ok').style.display    = 'block';
                    } else {
                        showErr(2, res.data && res.data.message ? res.data.message : 'Verification failed.');
                        btn.disabled    = false;
                        btn.textContent = 'Verify & Confirm';
                    }
                })
                .catch(function(){
                    showErr(2, 'Network error. Please try again.');
                    btn.disabled    = false;
                    btn.textContent = 'Verify & Confirm';
                });
            };

            window['cbdrResend_' + UID] = function() {
                $id('step2').style.display = 'none';
                $id('step1').style.display = 'flex';
                var btn = $id('send-btn');
                btn.disabled    = false;
                btn.textContent = 'Register Now';
                $id('otp').value = '';
                hideErr(1);
                hideErr(2);
            };
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
