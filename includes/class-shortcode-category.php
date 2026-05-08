<?php
namespace CB\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode: [cb_category_courses]
 * ONE page handles ALL departments via URL + Sub-department tabs.
 *
 * Setup (one time):
 * 1. Create a WordPress page with slug: course-category
 * 2. Add shortcode: [cb_category_courses]
 * 3. Go to Settings → Permalinks → Save Changes once
 */
class Shortcode_Category {

    public function init(): void {
        add_action( 'init',         [ $this, 'register_rewrite_rule' ] );
        add_filter( 'query_vars',   [ $this, 'add_query_var' ] );
        add_action( 'wp',           [ $this, 'maybe_flush_rewrite' ] );
        add_shortcode( 'cb_category_courses', [ $this, 'render' ] );
    }

    public function register_rewrite_rule(): void {
        add_rewrite_rule(
            '^course-category/([^/]+)/?$',
            'index.php?pagename=course-category&cb_dept_slug=$matches[1]',
            'top'
        );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = 'cb_dept_slug';
        return $vars;
    }

    public function maybe_flush_rewrite(): void {
        if ( ! get_option( 'cb_dept_rewrite_flushed' ) ) {
            flush_rewrite_rules();
            update_option( 'cb_dept_rewrite_flushed', '1' );
        }
    }

    public function render( $atts ): string {
        $atts = shortcode_atts( [ 'slug' => '' ], $atts, 'cb_category_courses' );

        $slug = get_query_var( 'cb_dept_slug', '' );
        if ( ! $slug ) $slug = sanitize_title( $atts['slug'] );
        if ( ! $slug ) $slug = get_post_field( 'post_name', get_queried_object_id() );
        if ( ! $slug ) {
            $parts = explode( '/', trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' ) );
            $key   = array_search( 'course-category', $parts );
            if ( $key !== false && isset( $parts[ $key + 1 ] ) ) {
                $slug = sanitize_title( $parts[ $key + 1 ] );
            }
        }
        if ( ! $slug ) {
            return '<p style="color:#94a3b8;padding:20px;text-align:center">Department not found.</p>';
        }

        $term = get_term_by( 'slug', $slug, 'cb_category' );
        if ( ! $term || is_wp_error( $term ) ) {
            $term = get_term_by( 'name', ucwords( str_replace( '-', ' ', $slug ) ), 'cb_category' );
        }
        if ( ! $term || is_wp_error( $term ) ) {
            return '<p style="color:#94a3b8;padding:20px;text-align:center">No department found for: <strong>' . esc_html( $slug ) . '</strong></p>';
        }

        global $wpdb;
        $sub_depts = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cb_subcategories WHERE category_id = %d ORDER BY sort_order ASC, name ASC",
            $term->term_id
        ) );

        $all_courses = get_posts( [
            'post_type'      => 'cb_course',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => [ 'meta_value_num' => 'ASC', 'date' => 'DESC' ],
            'meta_key'       => '_cb_age_min',
            'tax_query'      => [ [ 'taxonomy' => 'cb_category', 'field' => 'term_id', 'terms' => $term->term_id ] ],
        ] );

        // Group courses by sub-department
        $courses_by_sub = [ 0 => $all_courses ];
        foreach ( $sub_depts as $sub ) {
            $courses_by_sub[ $sub->id ] = [];
        }
        foreach ( $all_courses as $course ) {
            $sub_id = (int) get_post_meta( $course->ID, '_cb_subcategory_id', true );
            if ( $sub_id && isset( $courses_by_sub[ $sub_id ] ) ) {
                $courses_by_sub[ $sub_id ][] = $course;
            }
        }

        $uid = 'cb-cat-' . wp_rand( 1000, 9999 );
        ob_start();
        ?>
        <div id="<?php echo esc_attr( $uid ); ?>" class="cb-cat-wrap">

            <!-- HERO -->
            <div class="cb-cat-hero">
                <div class="cb-cat-hero-orb1"></div>
                <div class="cb-cat-hero-orb2"></div>
                <div class="cb-cat-hero-inner">
                    <h1 class="cb-cat-hero-title"><?php echo esc_html( $term->name ); ?></h1>
                    <?php if ( $term->description ) : ?>
                    <p class="cb-cat-hero-desc"><?php echo esc_html( $term->description ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- COURSES BODY -->
            <div class="cb-cat-body">

                <?php if ( ! empty( $sub_depts ) ) : ?>
                <!-- SUB-DEPARTMENT TABS -->
                <div class="cb-cat-tabs">
                    <button class="cb-cat-tab active" data-sub="0">
                        All
                        <span class="cb-cat-tab-count"><?php echo count( $all_courses ); ?></span>
                    </button>
                    <?php foreach ( $sub_depts as $sub ) : ?>
                    <button class="cb-cat-tab" data-sub="<?php echo esc_attr( $sub->id ); ?>">
                        <?php echo esc_html( $sub->name ); ?>
                        <span class="cb-cat-tab-count"><?php echo count( $courses_by_sub[ $sub->id ] ?? [] ); ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- COURSE PANELS -->
                <?php
                $panels = [ 0 => $all_courses ];
                foreach ( $sub_depts as $sub ) $panels[ $sub->id ] = $courses_by_sub[ $sub->id ] ?? [];
                ?>

                <?php foreach ( $panels as $sub_id => $courses ) : ?>
                <div class="cb-cat-panel<?php echo $sub_id === 0 ? ' active' : ''; ?>" data-panel="<?php echo esc_attr( $sub_id ); ?>">
                    <?php if ( empty( $courses ) ) : ?>
                    <div class="cb-cat-empty">
                        <div style="font-size:3rem;margin-bottom:14px">📭</div>
                        <p>No courses in this sub-department yet.</p>
                    </div>
                    <?php else : ?>
                    <div class="cb-cat-grid">
                        <?php foreach ( $courses as $i => $course ) :
                            $cid   = $course->ID;
                            $thumb = get_the_post_thumbnail_url( $cid, 'large' );
                            if ( ! $thumb ) {
                                $pid   = (int) get_post_meta( $cid, '_cb_photo_id', true );
                                $thumb = $pid ? wp_get_attachment_image_url( $pid, 'large' ) : '';
                            }
                            if ( ! $thumb ) $thumb = 'https://via.placeholder.com/600x400?text=Course';
                            $live     = (int) get_post_meta( $cid, '_cb_live_classes',    true );
                            $dur      = (int) get_post_meta( $cid, '_cb_duration_months', true );
                            $age      = (int) get_post_meta( $cid, '_cb_age_min',         true );
                            $duration = $dur ? $dur . ' Months' : 'Self Paced';
                            $excerpt  = wp_trim_words( get_the_excerpt( $cid ), 10, '...' );
                        ?>
                        <a href="<?php echo esc_url( get_permalink( $cid ) ); ?>" class="cb-cat-card" style="animation-delay:<?php echo $i * 0.07; ?>s">
                            <div class="cb-cat-img" style="background-image:url('<?php echo esc_url( $thumb ); ?>')" role="img" aria-label="<?php echo esc_attr( $course->post_title ); ?>"></div>
                            <div class="cb-cat-content">
                                <div class="cb-cat-meta-row">
                                    <?php if ( $live ) : ?>
                                    <span class="cb-cat-meta-item">
                                        <svg width="34" height="33" viewBox="0 0 36 35" fill="none"><path d="M0 17.5C0 7.83502 7.83502 0 17.5 0H18.5C28.165 0 36 7.83502 36 17.5C36 27.165 28.165 35 18.5 35H17.5C7.83502 35 0 27.165 0 17.5Z" fill="#EF3E26" fill-opacity="0.1"/><path d="M17.06 23.0875C15.7279 23.9201 14 22.9624 14 21.3915V13.6085C14 12.0376 15.7279 11.0799 17.06 11.9125L23.2864 15.804C24.5397 16.5873 24.5397 18.4127 23.2864 19.196L17.06 23.0875Z" fill="#EF3E26"/></svg>
                                        <span class="cb-cat-meta-dur"><b><?php echo $live; ?> Live</b><b>Class</b></span>
                                    </span>
                                    <?php endif; ?>
                                    <span class="cb-cat-meta-item">
                                        <svg width="34" height="33" viewBox="0 0 36 35" fill="none"><path d="M0 17.5C0 7.83502 7.83502 0 17.5 0H18.5C28.165 0 36 7.83502 36 17.5C36 27.165 28.165 35 18.5 35H17.5C7.83502 35 0 27.165 0 17.5Z" fill="#244092" fill-opacity="0.1"/><path d="M15.5 10.6818V11.3636H14.1C13.5204 11.3636 12.994 11.5934 12.6153 11.963C12.2366 12.3325 12 12.8445 12 13.4091V22.9545C12 23.5191 12.2359 24.0318 12.6153 24.4007C12.9947 24.7695 13.5204 25 14.1 25H23.9C24.4796 25 25.006 24.7702 25.3847 24.4007C25.7634 24.0311 26 23.5191 26 22.9545V13.4091C26 12.8445 25.7641 12.3318 25.3847 11.963C25.0053 11.5941 24.4796 11.3636 23.9 11.3636H22.5V10.6818C22.5 10.3055 22.1864 10 21.8 10C21.4136 10 21.1 10.3055 21.1 10.6818V11.3636H16.9V10.6818C16.9 10.3055 16.5864 10 16.2 10C15.8136 10 15.5 10.3055 15.5 10.6818ZM24.6 15.4545H13.4V13.4091C13.4 13.2209 13.4777 13.0511 13.6051 12.927C13.7325 12.803 13.9068 12.7273 14.1 12.7273H15.5V13.4091C15.5 13.7855 15.8136 14.0909 16.2 14.0909C16.5864 14.0909 16.9 13.7855 16.9 13.4091V12.7273H21.1V13.4091C21.1 13.7855 21.4136 14.0909 21.8 14.0909C22.1864 14.0909 22.5 13.7855 22.5 13.4091V12.7273H23.9C24.0932 12.7273 24.2675 12.803 24.3949 12.927C24.5223 13.0511 24.6 13.2209 24.6 13.4091V15.4545ZM13.4 16.8182H24.6V22.9545C24.6 23.1427 24.5223 23.3125 24.3949 23.4366C24.2675 23.5607 24.0932 23.6364 23.9 23.6364H14.1C13.9068 23.6364 13.7325 23.5607 13.6051 23.4366C13.4777 23.3125 13.4 23.1427 13.4 22.9545V16.8182Z" fill="#244092"/></svg>
                                        <span class="cb-cat-meta-dur"><b>Duration</b><b><?php echo esc_html( $duration ); ?></b></span>
                                    </span>
                                </div>
                                <h3 class="cb-cat-card-title"><?php echo esc_html( wp_trim_words( $course->post_title, 7, '...' ) ); ?></h3>
                                <?php if ( $excerpt ) : ?><p class="cb-cat-excerpt"><?php echo esc_html( $excerpt ); ?></p><?php endif; ?>
                                <?php if ( $age ) : ?><p class="cb-cat-age">Suitable Age: <span><?php echo $age; ?>+</span></p><?php endif; ?>
                                <span class="cb-cat-learn">Learn More <svg width="8" height="8" viewBox="0 0 8 8" fill="none"><path d="M0.667 1.333H5.724L0.195 6.862C-0.065 7.123 -0.065 7.545 0.195 7.805C0.456 8.065 0.878 8.065 1.138 7.805L6.667 2.276V7.333C6.667 7.701 6.965 8 7.333 8C7.701 8 8 7.701 8 7.333V0.667C8 0.298 7.702 0 7.333 0H0.667C0.299 0 0 0.299 0 0.667C0 1.035 0.299 1.333 0.667 1.333Z" fill="#EF3E26"/></svg></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

            </div><!-- /.cb-cat-body -->
        </div>

        <style>
        #<?php echo esc_attr($uid); ?>{font-family:system-ui;box-sizing:border-box}
        #<?php echo esc_attr($uid); ?> *,#<?php echo esc_attr($uid); ?> *::before,#<?php echo esc_attr($uid); ?> *::after{box-sizing:border-box}
        #<?php echo esc_attr($uid); ?> .cb-cat-hero{width:100vw;margin-left:calc(-50vw + 50%);background:#eaeaea;padding:80px 32px 90px;text-align:center;position:relative;overflow:hidden;margin-bottom:0}
        #<?php echo esc_attr($uid); ?> .cb-cat-hero-inner{position:relative;z-index:2;max-width:700px;margin:0 auto}
        #<?php echo esc_attr($uid); ?> .cb-cat-hero-title{font-size:6rem;font-weight:900;color:#244092;margin:0 0 16px;line-height:1.15;letter-spacing:-.5px}
        #<?php echo esc_attr($uid); ?> .cb-cat-hero-desc{font-size:15px;color:rgba(255,255,255,.75);margin:0 0 28px;line-height:1.7}
        #<?php echo esc_attr($uid); ?> .cb-cat-body{max-width:1200px;margin:0 auto;padding:48px 20px 64px}
        #<?php echo esc_attr($uid); ?> .cb-cat-tabs{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:36px;border-bottom:2px solid #e2e8f0;padding-bottom:0}
        #<?php echo esc_attr($uid); ?> .cb-cat-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border:none;background:none;font-family:system-ui;font-size:22px;font-weight:700;color:#64748b;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px;border-radius:8px 8px 0 0;transition:color .2s,border-color .2s,background .2s}
        #<?php echo esc_attr($uid); ?> .cb-cat-tab:hover{color:#244092;background:#f8fafc}
        #<?php echo esc_attr($uid); ?> .cb-cat-tab.active{color:#244092;border-bottom-color:#EF3E26;background:#fff}
        #<?php echo esc_attr($uid); ?> .cb-cat-tab-count{background:#f1f5f9;color:#64748b;font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;transition:background .2s,color .2s}
        #<?php echo esc_attr($uid); ?> .cb-cat-tab.active .cb-cat-tab-count{background:#EF3E26;color:#fff}
        #<?php echo esc_attr($uid); ?> .cb-cat-panel{display:none}
        #<?php echo esc_attr($uid); ?> .cb-cat-panel.active{display:block;animation:cbCatIn .3s ease}
        #<?php echo esc_attr($uid); ?> .cb-cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:28px;align-items:stretch}
        #<?php echo esc_attr($uid); ?> .cb-cat-card{display:flex;flex-direction:column;background:#fff;border-radius:14px;overflow:hidden;position:relative;border-right:3px solid #EF3E26;border-bottom:3px solid #EF3E26;box-shadow:0 6px 24px rgba(0,0,0,.10);text-decoration:none;transition:transform .28s ease,box-shadow .28s ease;animation:cbCatIn .4s ease both}
        #<?php echo esc_attr($uid); ?> .cb-cat-card::before{content:"";position:absolute;inset:0;border-radius:14px;pointer-events:none;z-index:3;box-shadow:inset 0 18px 30px -22px rgba(36,64,146,.9),inset 0 -18px 30px -28px rgba(36,64,146,.5),inset 12px 0 24px -28px rgba(36,64,146,.35),inset -12px 0 24px -28px rgba(36,64,146,.35)}
        #<?php echo esc_attr($uid); ?> .cb-cat-card:hover{transform:translateY(-7px);box-shadow:0 18px 48px rgba(0,0,0,.15)}
        #<?php echo esc_attr($uid); ?> .cb-cat-card:hover .cb-cat-content{transform:translateY(-4px);transition:transform .22s ease}
        #<?php echo esc_attr($uid); ?> .cb-cat-card:hover .cb-cat-card-title{color:#EF3E26}
        #<?php echo esc_attr($uid); ?> .cb-cat-card:hover .cb-cat-learn{color:#EF3E26}
        #<?php echo esc_attr($uid); ?> .cb-cat-img{height:360px;flex-shrink:0;background-size:cover;background-position:center;border-radius:11px 11px 0 0}
        #<?php echo esc_attr($uid); ?> .cb-cat-content{flex:1;padding:16px 18px 20px;position:relative;z-index:2;display:flex;flex-direction:column}
        #<?php echo esc_attr($uid); ?> .cb-cat-meta-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
        #<?php echo esc_attr($uid); ?> .cb-cat-meta-item{display:flex;align-items:center;gap:7px;font-size:1.3rem}
        #<?php echo esc_attr($uid); ?> .cb-cat-meta-dur{display:flex;flex-direction:column;line-height:1.25;color:#244092}
        #<?php echo esc_attr($uid); ?> .cb-cat-card-title{font-size:2.3rem;font-weight:900;color:#244092;margin:0 0 8px;line-height:1.25;transition:color .2s}
        #<?php echo esc_attr($uid); ?> .cb-cat-excerpt{font-size:13px;color:#64748b;line-height:1.5;margin:0 0 8px;flex:1}
        #<?php echo esc_attr($uid); ?> .cb-cat-age{font-size:17px;font-weight:900;color:#EF3E26;margin:4px 0 10px}
        #<?php echo esc_attr($uid); ?> .cb-cat-age span{color:#EF3E26}
        #<?php echo esc_attr($uid); ?> .cb-cat-learn{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:#EF3E26;margin-top:auto;transition:color .2s}
        #<?php echo esc_attr($uid); ?> .cb-cat-empty{text-align:center;padding:60px 20px;color:#94a3b8}
        #<?php echo esc_attr($uid); ?> .cb-cat-empty p{font-size:15px;margin:0}
        @keyframes cbCatIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
        @media(max-width:900px){#<?php echo esc_attr($uid); ?> .cb-cat-grid{grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px}}
        @media(max-width:600px){
            #<?php echo esc_attr($uid); ?> .cb-cat-hero{padding:52px 20px 60px}
            #<?php echo esc_attr($uid); ?> .cb-cat-body{padding:32px 16px 48px}
            #<?php echo esc_attr($uid); ?> .cb-cat-tabs{gap:6px}
            #<?php echo esc_attr($uid); ?> .cb-cat-tab{padding:8px 14px;font-size:13px}
            #<?php echo esc_attr($uid); ?> .cb-cat-grid{grid-template-columns:1fr 1fr;gap:14px}
            #<?php echo esc_attr($uid); ?> .cb-cat-img{height:200px}
        }
        @media(max-width:420px){
            #<?php echo esc_attr($uid); ?> .cb-cat-grid{grid-template-columns:1fr}
            #<?php echo esc_attr($uid); ?> .cb-cat-tab{font-size:12px;padding:7px 10px}
        }
        </style>

        <script>
        (function(){
            var wrap = document.getElementById('<?php echo esc_js( $uid ); ?>');
            if (!wrap) return;
            var tabs   = wrap.querySelectorAll('.cb-cat-tab');
            var panels = wrap.querySelectorAll('.cb-cat-panel');
            tabs.forEach(function(tab){
                tab.addEventListener('click', function(){
                    var sub = tab.getAttribute('data-sub');
                    tabs.forEach(function(t){ t.classList.remove('active'); });
                    panels.forEach(function(p){ p.classList.remove('active'); });
                    tab.classList.add('active');
                    var panel = wrap.querySelector('.cb-cat-panel[data-panel="'+sub+'"]');
                    if (panel) panel.classList.add('active');
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
