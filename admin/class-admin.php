<?php
namespace CB\Admin;

defined( 'ABSPATH' ) || exit;

class Admin {

    public function init(): void {
        add_action( 'admin_init',            [ $this, 'handle_purge' ] );
        add_action( 'admin_init',            [ $this, 'handle_settings_save' ] );
        add_action( 'wp_ajax_cb_dismiss_version_banner', [ $this, 'handle_dismiss_banner' ] );
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    // ── Purge seed data (one-time cleanup) ───────────────────────────────────

    public function handle_purge(): void {
        if ( empty( $_GET['cb_purge_seed'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        require_once CB_PLUGIN_DIR . 'includes/class-seed-data.php';
        \CB\Core\Seed_Data::purge();
        wp_redirect( admin_url( 'admin.php?page=course-builder&cb_purged=1' ) );
        exit;
    }

    // ── Settings: save handler ───────────────────────────────────────────────

    public function handle_settings_save(): void {
        if (
            ! isset( $_POST['cb_settings_nonce'] ) ||
            ! wp_verify_nonce( $_POST['cb_settings_nonce'], 'cb_save_settings' ) ||
            ! current_user_can( 'manage_options' )
        ) {
            return;
        }
        $delete_on_uninstall = isset( $_POST['cb_delete_data_on_uninstall'] ) ? 1 : 0;
        update_option( 'cb_delete_data_on_uninstall', $delete_on_uninstall );

        // SSL Wireless iSMS credentials.
        update_option( 'cb_sslwireless_api_token', sanitize_text_field( $_POST['cb_sslwireless_api_token'] ?? '' ) );
        update_option( 'cb_sslwireless_sid',       sanitize_text_field( $_POST['cb_sslwireless_sid']       ?? '' ) );

        wp_redirect( admin_url( 'admin.php?page=course-builder-settings&cb_saved=1' ) );
        exit;
    }

    // ── Admin menus ───────────────────────────────────────────────────────────

    public function register_menus(): void {
        add_menu_page(
            __( 'Course Builder', 'course-builder' ),
            __( 'Course Builder', 'course-builder' ),
            'manage_options',
            'course-builder',
            [ $this, 'render_courses_list' ],
            'dashicons-welcome-learn-more',
            26
        );

        add_submenu_page(
            'course-builder',
            __( 'All Courses', 'course-builder' ),
            __( 'All Courses', 'course-builder' ),
            'manage_options',
            'course-builder',
            [ $this, 'render_courses_list' ]
        );

        add_submenu_page(
            'course-builder',
            __( 'Add New Course', 'course-builder' ),
            __( 'Add New Course', 'course-builder' ),
            'manage_options',
            'course-builder-add',
            [ $this, 'render_course_add' ]
        );

        add_submenu_page(
            'course-builder',
            __( 'Departments', 'course-builder' ),
            __( 'Departments', 'course-builder' ),
            'manage_options',
            'course-builder-categories',
            [ $this, 'render_categories' ]
        );

        add_submenu_page(
            'course-builder',
            __( 'Teachers', 'course-builder' ),
            __( 'Teachers', 'course-builder' ),
            'manage_options',
            'course-builder-teachers',
            [ $this, 'render_teachers' ]
        );

        add_submenu_page(
            'course-builder',
            __( 'Demo Registrations', 'course-builder' ),
            __( 'Demo Registrations', 'course-builder' ),
            'manage_options',
            'course-builder-demo',
            [ $this, 'render_demo_registrations' ]
        );

        add_submenu_page(
            'course-builder',
            __( 'Settings', 'course-builder' ),
            __( 'Settings', 'course-builder' ),
            'manage_options',
            'course-builder-settings',
            [ $this, 'render_settings' ]
        );
    }

    // ── Page renderers ────────────────────────────────────────────────────────

    public function render_courses_list(): void {
        include CB_PLUGIN_DIR . 'admin/views/courses-list.php';
    }

    public function render_course_add(): void {
        include CB_PLUGIN_DIR . 'admin/views/course-add.php';
    }

    public function render_categories(): void {
        include CB_PLUGIN_DIR . 'admin/views/categories.php';
    }

    public function render_teachers(): void {
        include CB_PLUGIN_DIR . 'admin/views/teachers.php';
    }

    public function render_demo_registrations(): void {
        include CB_PLUGIN_DIR . 'admin/views/demo-registrations.php';
    }

    public function handle_dismiss_banner(): void {
        if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'cb_dismiss_banner' ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(); return;
        }
        update_option( 'cb_last_seen_version', sanitize_text_field( $_POST['version'] ?? CB_VERSION ) );
        wp_send_json_success();
    }

    public function render_settings(): void {
        $saved     = ! empty( $_GET['cb_saved'] );
        $checked   = get_option( 'cb_delete_data_on_uninstall', 0 );
        $api_token = get_option( 'cb_sslwireless_api_token', '' );
        $sid       = get_option( 'cb_sslwireless_sid', '' );
        ?>
        <div class="wrap">
            <h1 style="font-family:'Plus Jakarta Sans',sans-serif;color:#244092;font-weight:800;margin-bottom:24px">
                ⚙️ Course Builder — Settings
            </h1>
            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>✓ Settings saved.</p></div>
            <?php endif; ?>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:28px;max-width:600px;box-shadow:0 2px 12px rgba(36,64,146,.08)">
                <form method="post" action="">
                    <?php wp_nonce_field( 'cb_save_settings', 'cb_settings_nonce' ); ?>

                    <h2 style="font-size:16px;font-weight:700;color:#1e293b;margin:0 0 6px">Data Management</h2>
                    <p style="font-size:13px;color:#64748b;margin:0 0 20px;line-height:1.6">
                        By default, all your courses, teachers and departments are <strong>preserved</strong> when you update or reinstall the plugin.
                        Data is only deleted when you tick the option below <em>and</em> then click Delete on the Plugins screen.
                    </p>

                    <label style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;padding:16px;background:#fff5f5;border:1.5px solid #fecaca;border-radius:8px">
                        <input type="checkbox" name="cb_delete_data_on_uninstall" value="1"
                            <?php checked( 1, $checked ); ?>
                            style="margin-top:3px;width:16px;height:16px;accent-color:#ef3e26;flex-shrink:0">
                        <div>
                            <strong style="display:block;font-size:13.5px;color:#991b1b;margin-bottom:3px">
                                ⚠️ Delete ALL data when plugin is uninstalled
                            </strong>
                            <span style="font-size:12.5px;color:#7f1d1d;line-height:1.5">
                                When enabled, deleting the plugin will permanently remove all courses, teachers, departments and subcategories from your database. This cannot be undone.
                                <br><strong>Leave unticked to safely update the plugin without losing data.</strong>
                            </span>
                        </div>
                    </label>

                    <hr style="margin:28px 0;border-color:#e2e8f0">

                    <h2 style="font-size:16px;font-weight:700;color:#1e293b;margin:0 0 6px">📱 SSL Wireless iSMS</h2>
                    <p style="font-size:13px;color:#64748b;margin:0 0 16px;line-height:1.6">
                        These credentials are used to send OTP SMS for demo class registration.
                        Obtain them from your <a href="https://sms.sslwireless.com" target="_blank" rel="noopener">SSL Wireless dashboard</a>.
                    </p>

                    <div style="display:flex;flex-direction:column;gap:12px">
                        <div>
                            <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px">
                                API Token
                            </label>
                            <input type="text" name="cb_sslwireless_api_token"
                                   value="<?php echo esc_attr( $api_token ); ?>"
                                   placeholder="Your SSL Wireless API token"
                                   style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px">
                        </div>
                        <div>
                            <label style="display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px">
                                Sender ID (SID)
                            </label>
                            <input type="text" name="cb_sslwireless_sid"
                                   value="<?php echo esc_attr( $sid ); ?>"
                                   placeholder="e.g. PEDAGOSITE"
                                   style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px">
                        </div>
                    </div>

                    <div style="margin-top:20px">
                        <button type="submit" class="button button-primary" style="background:#244092;border-color:#162860;font-weight:700">
                            Save Settings
                        </button>
                    </div>
                </form>

                <hr style="margin:28px 0;border-color:#e2e8f0">

                <h2 style="font-size:16px;font-weight:700;color:#1e293b;margin:0 0 6px">Plugin Info</h2>
                <table style="font-size:13px;color:#475569;border-collapse:collapse;width:100%">
                    <tr><td style="padding:5px 0;color:#94a3b8;width:160px">Version</td><td><?php echo CB_VERSION; ?></td></tr>
                    <tr><td style="padding:5px 0;color:#94a3b8">Total Courses</td>
                        <td><?php echo wp_count_posts('cb_course')->publish ?? 0; ?></td></tr>
                    <tr><td style="padding:5px 0;color:#94a3b8">Total Teachers</td>
                        <td><?php echo wp_count_posts('cb_teacher')->publish ?? 0; ?></td></tr>
                    <tr><td style="padding:5px 0;color:#94a3b8">Data on Uninstall</td>
                        <td><?php echo $checked ? '<span style="color:#dc2626;font-weight:700">Will be DELETED</span>' : '<span style="color:#16a34a;font-weight:700">✓ Safe (preserved)</span>'; ?></td></tr>
                </table>
            </div>
        </div>
        <?php
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        $our_pages = [
            'toplevel_page_course-builder',
            'course-builder_page_course-builder-add',
            'course-builder_page_course-builder-categories',
            'course-builder_page_course-builder-teachers',
            'course-builder_page_course-builder-demo',
            'course-builder_page_course-builder-settings',
        ];

        if ( ! in_array( $hook, $our_pages, true ) ) {
            return;
        }

        // Bootstrap 5
        wp_enqueue_style(
            'bootstrap5',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            [],
            '5.3.3'
        );

        // Select2
        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0'
        );

        // Our admin CSS
        wp_enqueue_style(
            'cb-admin',
            CB_PLUGIN_URL . 'assets/css/admin.css',
            [ 'bootstrap5', 'select2' ],
            CB_VERSION
        );

        // WP Media (for image uploads)
        wp_enqueue_media();

        // jQuery (built-in)
        wp_enqueue_script( 'jquery' );

        // Bootstrap 5 JS
        wp_enqueue_script(
            'bootstrap5',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
            [ 'jquery' ],
            '5.3.3',
            true
        );

        // Select2
        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            [ 'jquery' ],
            '4.1.0',
            true
        );

        // Our admin JS
        wp_enqueue_script(
            'cb-admin',
            CB_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery', 'bootstrap5', 'select2' ],
            CB_VERSION,
            true
        );

        // Pass PHP data to JS
        wp_localize_script( 'cb-admin', 'CB_Admin', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'cb_admin_nonce' ),
            'plugin_url' => CB_PLUGIN_URL,
            'site_url'   => get_site_url(),
            'page'       => $hook,
        ] );
    }
}
