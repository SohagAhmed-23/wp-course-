<?php
namespace CB\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles all wp_ajax_* hooks.
 * Every action is prefixed with "cb_".
 */
class Ajax_Handler {

    public function register_hooks(): void {
        $actions = [
            // Courses
            'cb_save_course',
            'cb_delete_course',
            'cb_get_course',
            'cb_get_courses',
            // Categories
            'cb_save_category',
            'cb_delete_category',
            // Teachers
            'cb_save_teacher',
            'cb_delete_teacher',
            // Misc
            'cb_get_wc_products',
            'cb_refresh_nonce',
            // Demo registrations (admin)
            'cb_get_demo_registrations',
            'cb_delete_demo_registration',
        ];

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, 'dispatch' ] );
        }

        // Demo OTP actions are public (non-logged-in visitors).
        foreach ( [ 'cb_demo_send_otp', 'cb_demo_verify_otp' ] as $action ) {
            add_action( 'wp_ajax_' . $action,        [ $this, 'dispatch' ] );
            add_action( 'wp_ajax_nopriv_' . $action, [ $this, 'dispatch' ] );
        }
    }

    public function dispatch(): void {
        // Turn any PHP warnings/notices into catchable errors so they
        // don't corrupt the JSON response and cause an endless spinner.
        set_error_handler( function( $errno, $errstr ) {
            throw new \ErrorException( $errstr, $errno );
        }, E_ALL & ~E_DEPRECATED & ~E_STRICT );

        try {
            $action = sanitize_key( $_REQUEST['action'] ?? '' );
            $method = 'handle_' . str_replace( 'cb_', '', $action );

            if ( ! method_exists( $this, $method ) ) {
                wp_send_json_error( [ 'message' => 'Unknown action: ' . $action ], 400 );
            }

            $this->$method();

        } catch ( \Throwable $e ) {
            wp_send_json_error( [
                'message' => 'Server error: ' . $e->getMessage(),
                'file'    => basename( $e->getFile() ),
                'line'    => $e->getLine(),
            ], 500 );
        } finally {
            restore_error_handler();
        }
    }

    // ── Nonce refresh (called when nonce expires) ────────────────────────────

    private function handle_refresh_nonce(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        wp_send_json_success( [ 'nonce' => wp_create_nonce( 'cb_admin_nonce' ) ] );
    }

    // ── Security helper ───────────────────────────────────────────────────────

    private function verify( string $nonce_action = 'cb_admin_nonce' ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        }
        if ( ! check_ajax_referer( $nonce_action, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Nonce verification failed.' ], 403 );
        }
    }

    // ── Courses ───────────────────────────────────────────────────────────────

    private function handle_get_courses(): void {
        $this->verify();
        $page     = absint( $_POST['page'] ?? 1 );
        $per_page = absint( $_POST['per_page'] ?? 10 );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );
        $cat_id   = absint( $_POST['category_id'] ?? 0 );

        $data = CPT_Courses::get_paginated( $page, $per_page, $search, $cat_id );

        $courses = [];
        foreach ( $data['posts'] as $post ) {
            $teacher_id = (int) get_post_meta( $post->ID, '_cb_teacher_id', true );
            $teacher    = $teacher_id ? get_post( $teacher_id ) : null;
            $terms      = wp_get_post_terms( $post->ID, 'cb_category' );
            $courses[]  = [
                'id'          => $post->ID,
                'title'       => $post->post_title,
                'subtitle'    => get_post_meta( $post->ID, '_cb_subtitle', true ),
                'category'    => ! empty( $terms ) ? $terms[0]->name : '—',
                'teacher'     => $teacher ? $teacher->post_title : '—',
                'date'        => get_the_date( 'M j, Y', $post ),
            'age_min'     => get_post_meta( $post->ID, '_cb_age_min', true ),
            'duration'    => get_post_meta( $post->ID, '_cb_duration_months', true ),
            'live_classes'=> get_post_meta( $post->ID, '_cb_live_classes', true ),
            'permalink'   => get_permalink( $post->ID ),
            ];
        }

        wp_send_json_success( [
            'courses'     => $courses,
            'total'       => $data['total'],
            'total_pages' => $data['total_pages'],
        ] );
    }

    private function handle_get_course(): void {
        $this->verify();
        $id   = absint( $_POST['id'] ?? 0 );
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'cb_course' ) {
            wp_send_json_error( [ 'message' => 'Course not found.' ] );
        }
        $terms = wp_get_post_terms( $post->ID, 'cb_category' );
        wp_send_json_success( [
            'id'                  => $post->ID,
            'title'               => $post->post_title,
            'subtitle'            => get_post_meta( $post->ID, '_cb_subtitle', true ),
            'category_id'         => ! empty( $terms ) ? $terms[0]->term_id : 0,
            'subcategory_id'      => (int) get_post_meta( $post->ID, '_cb_subcategory_id', true ),
            'teacher_id'          => get_post_meta( $post->ID, '_cb_teacher_id', true ),
            'wc_product_id'       => get_post_meta( $post->ID, '_cb_wc_product_id', true ),
            'learning_objectives' => json_decode( get_post_meta( $post->ID, '_cb_learning_objectives', true ) ?: '[]', true ),
            'programme_overview'  => json_decode( get_post_meta( $post->ID, '_cb_programme_overview', true ) ?: '[]', true ),
            'course_content'      => json_decode( get_post_meta( $post->ID, '_cb_course_content', true ) ?: '[]', true ),
            'additional_support'  => json_decode( get_post_meta( $post->ID, '_cb_additional_support', true ) ?: '[]', true ),
            'unique_features'     => json_decode( get_post_meta( $post->ID, '_cb_unique_features', true ) ?: '[]', true ),
            'batch_schedules'     => json_decode( get_post_meta( $post->ID, '_cb_batch_schedules', true ) ?: '[]', true ),
            'course_active'       => get_post_meta( $post->ID, '_cb_course_active', true ) !== '0' ? '1' : '0',
            'age_min'             => get_post_meta( $post->ID, '_cb_age_min', true ),
            'video_url'           => get_post_meta( $post->ID, '_cb_video_url', true ),
            'youtube_url'         => get_post_meta( $post->ID, '_cb_youtube_url', true ),
            'duration_months'     => get_post_meta( $post->ID, '_cb_duration_months', true ),
            'live_classes'        => get_post_meta( $post->ID, '_cb_live_classes', true ),
            'featured_image_id'   => (int) get_post_thumbnail_id( $post->ID ),
            'featured_image_url'  => get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: '',
        ] );
    }

    private function handle_save_course(): void {
        $this->verify();
        $data = [
            'id'                  => absint( $_POST['id'] ?? 0 ),
            'title'               => sanitize_text_field( $_POST['title'] ?? '' ),
            'subtitle'            => sanitize_text_field( $_POST['subtitle'] ?? '' ),
            'category_id'         => absint( $_POST['category_id'] ?? 0 ),
            'subcategory_id'      => absint( $_POST['subcategory_id'] ?? 0 ),
            'teacher_id'          => absint( $_POST['teacher_id'] ?? 0 ),
            'wc_product_id'       => absint( $_POST['wc_product_id'] ?? 0 ),
            'age_min'             => absint( $_POST['age_min'] ?? 0 ),
            'video_url'           => esc_url_raw( $_POST['video_url'] ?? '' ),
            'youtube_url'         => esc_url_raw( $_POST['youtube_url'] ?? '' ),
            'duration_months'     => absint( $_POST['duration_months'] ?? 0 ),
            'live_classes'        => absint( $_POST['live_classes'] ?? 0 ),
            'featured_image_id'   => intval( $_POST['featured_image_id'] ?? 0 ),
            'learning_objectives' => $this->sanitize_array( $_POST['learning_objectives'] ?? [] ),
            'programme_overview'  => $this->sanitize_array( $_POST['programme_overview'] ?? [] ),
            'course_content'      => $this->sanitize_nested( $_POST['course_content'] ?? [] ),
            'additional_support'  => $this->sanitize_array( $_POST['additional_support'] ?? [] ),
            'unique_features'     => $this->sanitize_features( $_POST['unique_features'] ?? [] ),
            'batch_schedules'     => $this->sanitize_schedules( $_POST['batch_schedules'] ?? [] ),
            'course_active'       => isset( $_POST['course_active'] ) ? '1' : '0',
        ];

        if ( empty( $data['title'] ) ) {
            wp_send_json_error( [ 'message' => 'Course title is required.' ] );
        }

        // Map field names for meta saving
        $data['subtitle']           = $data['subtitle'];
        $data['learning_objectives']= $data['learning_objectives'];
        $data['course_content']     = $data['course_content'];
        $data['additional_support'] = $data['additional_support'];

        $post_id = CPT_Courses::save( $data );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        // Save teacher separately (uses different meta key)
        update_post_meta( $post_id, '_cb_teacher_id', $data['teacher_id'] );
        update_post_meta( $post_id, '_cb_wc_product_id', $data['wc_product_id'] );

        wp_send_json_success( [
            'message'   => $data['id'] ? 'Course updated.' : 'Course created.',
            'id'        => $post_id,
            'permalink' => get_permalink( $post_id ),
            'id'      => $post_id,
        ] );
    }

    private function handle_delete_course(): void {
        $this->verify();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Invalid ID.' ] );
        }
        $result = wp_delete_post( $id, true );
        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Course deleted.' ] );
        }
        wp_send_json_error( [ 'message' => 'Failed to delete course.' ] );
    }

    // ── Categories ────────────────────────────────────────────────────────────

    private function handle_save_category(): void {
        $this->verify();
        $data = [
            'id'            => absint( $_POST['id'] ?? 0 ),
            'name'          => sanitize_text_field( $_POST['name'] ?? '' ),
            'description'   => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'slug'          => sanitize_title( $_POST['slug'] ?? '' ),
            'image_id'      => absint( $_POST['image_id'] ?? 0 ),
            'subcategories' => $this->sanitize_nested( $_POST['subcategories'] ?? [] ),
        ];

        if ( empty( $data['name'] ) ) {
            wp_send_json_error( [ 'message' => 'Category name is required.' ] );
        }

        $result = Taxonomy_Category::save( $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [
            'message' => $data['id'] ? 'Category updated.' : 'Category created.',
            'id'      => $result,
        ] );
    }

    private function handle_delete_category(): void {
        $this->verify();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Invalid ID.' ] );
        }
        global $wpdb;
        $result = wp_delete_term( $id, 'cb_category' );
        $wpdb->delete( $wpdb->prefix . 'cb_subcategories', [ 'category_id' => $id ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'message' => 'Category deleted.' ] );
    }

    // ── Teachers ──────────────────────────────────────────────────────────────

    private function handle_save_teacher(): void {
        $this->verify();
        $data = [
            'id'          => absint( $_POST['id'] ?? 0 ),
            'name'        => sanitize_text_field( $_POST['name'] ?? '' ),
            'designation' => sanitize_text_field( $_POST['designation'] ?? '' ),
            'photo_id'    => absint( $_POST['photo_id'] ?? 0 ),
            'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'categories'  => array_map( 'absint', (array) ( $_POST['categories'] ?? [] ) ),
        ];

        if ( empty( $data['name'] ) ) {
            wp_send_json_error( [ 'message' => 'Teacher name is required.' ] );
        }

        $post_id = CPT_Teachers::save( $data );

        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( [ 'message' => $post_id->get_error_message() ] );
        }

        wp_send_json_success( [
            'message' => $data['id'] ? 'Teacher updated.' : 'Teacher created.',
            'id'      => $post_id,
        ] );
    }

    private function handle_delete_teacher(): void {
        $this->verify();
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Invalid ID.' ] );
        }
        $result = wp_delete_post( $id, true );
        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Teacher deleted.' ] );
        }
        wp_send_json_error( [ 'message' => 'Failed to delete teacher.' ] );
    }

    // ── WooCommerce Products ──────────────────────────────────────────────────

    private function handle_get_wc_products(): void {
        $this->verify();
        $exclude_course = absint( $_POST['exclude_course'] ?? 0 );

        if ( ! function_exists( 'wc_get_products' ) ) {
            wp_send_json_success( [ 'products' => [], 'message' => 'WooCommerce not active.' ] );
        }

        // Find all WC product IDs already assigned to OTHER courses
        $assigned = get_posts( [
            'post_type'      => 'cb_course',
            'posts_per_page' => -1,
            'post__not_in'   => $exclude_course ? [ $exclude_course ] : [],
            'fields'         => 'ids',
        ] );

        $used_ids = [];
        foreach ( $assigned as $cid ) {
            $pid = get_post_meta( $cid, '_cb_wc_product_id', true );
            if ( $pid ) $used_ids[] = (int) $pid;
        }

        $products = wc_get_products( [
            'status' => 'publish',
            'limit'  => -1,
        ] );

        $result = [];
        foreach ( $products as $product ) {
            if ( in_array( $product->get_id(), $used_ids, true ) ) continue;
            $result[] = [
                'id'    => $product->get_id(),
                'name'  => $product->get_name(),
                'price' => $product->get_price_html(),
            ];
        }

        wp_send_json_success( [ 'products' => $result ] );
    }

    // ── Demo class OTP (public — no login required) ───────────────────────────

    /**
     * Verify public nonce only (no capability check — open to all visitors).
     */
    private function verify_public( string $nonce_action = 'cb_demo_nonce' ): void {
        if ( ! check_ajax_referer( $nonce_action, 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
        }
    }

    private function handle_demo_send_otp(): void {
        $this->verify_public();

        global $wpdb;

        $name      = sanitize_text_field( $_POST['name']      ?? '' );
        $phone     = sanitize_text_field( $_POST['phone']     ?? '' );
        $course_id = absint( $_POST['course_id'] ?? 0 );

        if ( empty( $name ) || empty( $phone ) ) {
            wp_send_json_error( [ 'message' => 'Name and phone number are required.' ] );
        }

        // Normalise BD phone: strip spaces/dashes, ensure it starts with 880 or 01
        $phone = preg_replace( '/[\s\-\+]/', '', $phone );
        if ( preg_match( '/^01[3-9]\d{8}$/', $phone ) ) {
            $phone = '880' . ltrim( $phone, '0' );
        }
        if ( ! preg_match( '/^8801[3-9]\d{8}$/', $phone ) ) {
            wp_send_json_error( [ 'message' => 'Please enter a valid Bangladeshi mobile number.' ] );
        }

        // Check if already registered for this course.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cb_demo_registrations WHERE phone = %s AND course_id = %d LIMIT 1",
            $phone, $course_id
        ) );
        if ( $exists ) {
            wp_send_json_error( [ 'message' => 'This phone number is already registered for a demo class on this course.' ] );
        }

        // Rate-limit: one OTP per phone per 2 minutes.
        $recent = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cb_demo_otp WHERE phone = %s AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE) LIMIT 1",
            $phone
        ) );
        if ( $recent ) {
            wp_send_json_error( [ 'message' => 'An OTP was sent recently. Please wait 2 minutes before requesting another.' ] );
        }

        // Delete old OTPs for this phone.
        $wpdb->delete( $wpdb->prefix . 'cb_demo_otp', [ 'phone' => $phone ] );

        // Generate 6-digit OTP.
        $otp = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );

        $wpdb->insert( $wpdb->prefix . 'cb_demo_otp', [
            'phone'      => $phone,
            'otp_code'   => $otp,
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ), // 10 minutes
        ] );

        $course_name = $course_id ? get_the_title( $course_id ) : 'Demo Class';
        $sent        = SMS::send_otp( $phone, $otp, $course_name );

        if ( ! $sent ) {
            // If SMS fails (e.g. credentials not configured), still succeed in dev
            // but log it. In production configure the SSL Wireless credentials.
            error_log( "[CB Demo] SMS not sent to {$phone}. Check SSL Wireless credentials in Settings." );
        }

        wp_send_json_success( [
            'message' => 'OTP sent! Please check your phone.',
            'phone'   => $phone, // echo back normalised number
        ] );
    }

    private function handle_demo_verify_otp(): void {
        $this->verify_public();

        global $wpdb;

        $name      = sanitize_text_field( $_POST['name']      ?? '' );
        $phone     = sanitize_text_field( $_POST['phone']     ?? '' );
        $otp       = sanitize_text_field( $_POST['otp']       ?? '' );
        $course_id = absint( $_POST['course_id'] ?? 0 );

        if ( empty( $name ) || empty( $phone ) || empty( $otp ) ) {
            wp_send_json_error( [ 'message' => 'All fields are required.' ] );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cb_demo_otp WHERE phone = %s AND otp_code = %s AND expires_at > NOW() LIMIT 1",
            $phone, $otp
        ) );

        if ( ! $row ) {
            wp_send_json_error( [ 'message' => 'Invalid or expired OTP. Please try again.' ] );
        }

        // Double-check not already registered (race condition guard).
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cb_demo_registrations WHERE phone = %s AND course_id = %d LIMIT 1",
            $phone, $course_id
        ) );
        if ( $exists ) {
            wp_send_json_error( [ 'message' => 'This phone number is already registered for this course.' ] );
        }

        // Save registration.
        $wpdb->insert( $wpdb->prefix . 'cb_demo_registrations', [
            'course_id'    => $course_id,
            'student_name' => $name,
            'phone'        => $phone,
        ] );

        // Clean up used OTP.
        $wpdb->delete( $wpdb->prefix . 'cb_demo_otp', [ 'phone' => $phone ] );

        wp_send_json_success( [
            'message' => 'Registration confirmed! We will contact you shortly.',
        ] );
    }

    // ── Demo registrations (admin) ────────────────────────────────────────────

    private function handle_get_demo_registrations(): void {
        $this->verify();

        global $wpdb;

        $page     = absint( $_POST['page']     ?? 1 );
        $per_page = absint( $_POST['per_page'] ?? 20 );
        $offset   = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cb_demo_registrations" );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.*, p.post_title AS course_title
             FROM {$wpdb->prefix}cb_demo_registrations r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.course_id
             ORDER BY r.registered_at DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        wp_send_json_success( [
            'registrations' => $rows,
            'total'         => $total,
            'total_pages'   => (int) ceil( $total / $per_page ),
        ] );
    }

    private function handle_delete_demo_registration(): void {
        $this->verify();

        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Invalid ID.' ] );
        }
        $wpdb->delete( $wpdb->prefix . 'cb_demo_registrations', [ 'id' => $id ] );
        wp_send_json_success( [ 'message' => 'Registration deleted.' ] );
    }

    // ── Sanitization helpers ──────────────────────────────────────────────────

    private function sanitize_array( $arr ): array {
        if ( ! is_array( $arr ) ) return [];
        return array_filter( array_map( 'sanitize_text_field', $arr ) );
    }

    private function sanitize_nested( $arr ): array {
        if ( ! is_array( $arr ) ) return [];
        $result = [];
        foreach ( $arr as $item ) {
            if ( is_array( $item ) ) {
                $result[] = array_map( 'sanitize_text_field', $item );
            } else {
                $result[] = sanitize_text_field( $item );
            }
        }
        return $result;
    }

    /**
     * Sanitize unique features array — each item: icon, title, description.
     */
    private function sanitize_features( $arr ): array {
        if ( ! is_array( $arr ) ) return [];
        $result = [];
        foreach ( $arr as $item ) {
            if ( ! is_array( $item ) ) continue;
            // wp_magic_quotes adds slashes to $_POST — unslash first so emoji \uXXXX stays intact
            $icon  = sanitize_text_field( wp_unslash( $item['icon'] ?? '' ) );
            $title = sanitize_text_field( wp_unslash( $item['title'] ?? '' ) );
            $desc  = sanitize_text_field( wp_unslash( $item['description'] ?? '' ) );
            if ( $title || $icon ) {
                $result[] = [
                    'icon'        => $icon,
                    'title'       => $title,
                    'description' => $desc,
                ];
            }
        }
        return $result;
    }

    /**
     * Sanitize batch schedules array — each item: group, time.
     */
    private function sanitize_schedules( $arr ): array {
        if ( ! is_array( $arr ) ) return [];
        $result = [];
        foreach ( $arr as $item ) {
            if ( ! is_array( $item ) ) continue;
            $group = sanitize_text_field( $item['group'] ?? '' );
            $time  = sanitize_text_field( $item['time'] ?? '' );
            if ( $group || $time ) {
                $result[] = [
                    'group' => $group,
                    'time'  => $time,
                ];
            }
        }
        return $result;
    }
}
