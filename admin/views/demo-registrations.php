<?php
defined( 'ABSPATH' ) || exit;

global $wpdb;

// Pagination.
$page     = max( 1, absint( $_GET['paged'] ?? 1 ) );
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

// Optional search by phone.
$search = sanitize_text_field( $_GET['s'] ?? '' );
$where  = '';
if ( $search ) {
    $where = $wpdb->prepare(
        " WHERE r.phone LIKE %s OR r.student_name LIKE %s",
        '%' . $wpdb->esc_like( $search ) . '%',
        '%' . $wpdb->esc_like( $search ) . '%'
    );
}

$total = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}cb_demo_registrations r" . $where
);

$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT r.*, p.post_title AS course_title
         FROM {$wpdb->prefix}cb_demo_registrations r
         LEFT JOIN {$wpdb->posts} p ON p.ID = r.course_id"
        . $where .
        " ORDER BY r.registered_at DESC
         LIMIT %d OFFSET %d",
        $per_page, $offset
    ),
    ARRAY_A
);

$total_pages = (int) ceil( $total / $per_page );

// Handle inline delete.
if ( ! empty( $_GET['delete_id'] ) && check_admin_referer( 'cb_delete_demo_' . absint( $_GET['delete_id'] ) ) ) {
    $wpdb->delete( $wpdb->prefix . 'cb_demo_registrations', [ 'id' => absint( $_GET['delete_id'] ) ] );
    wp_redirect( admin_url( 'admin.php?page=course-builder-demo&deleted=1' ) );
    exit;
}
?>
<div class="wrap">
    <h1 style="font-family:'Plus Jakarta Sans',sans-serif;color:#244092;font-weight:800;margin-bottom:24px">
        📋 Demo Class Registrations
    </h1>

    <?php if ( ! empty( $_GET['deleted'] ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>✓ Registration deleted.</p></div>
    <?php endif; ?>

    <!-- Search -->
    <form method="get" style="margin-bottom:16px;display:flex;gap:8px;align-items:center">
        <input type="hidden" name="page" value="course-builder-demo">
        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
               placeholder="Search by name or phone…"
               style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:260px">
        <button type="submit" class="button">Search</button>
        <?php if ( $search ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=course-builder-demo' ) ); ?>" class="button">Clear</a>
        <?php endif; ?>
        <span style="margin-left:auto;font-size:13px;color:#64748b">
            <?php echo number_format( $total ); ?> registration<?php echo $total !== 1 ? 's' : ''; ?> total
        </span>
    </form>

    <?php if ( empty( $rows ) ) : ?>
    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:48px;text-align:center;color:#94a3b8">
        <div style="font-size:40px;margin-bottom:12px">📭</div>
        <p style="margin:0;font-size:15px"><?php echo $search ? 'No registrations match your search.' : 'No demo class registrations yet.'; ?></p>
    </div>
    <?php else : ?>

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(36,64,146,.06)">
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0">
                    <th style="padding:12px 16px;text-align:left;font-weight:700;color:#374151">#</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;color:#374151">Student Name</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;color:#374151">Phone</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;color:#374151">Course</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;color:#374151">Registered At</th>
                    <th style="padding:12px 16px;text-align:left;font-weight:700;color:#374151">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $i => $row ) :
                $delete_url = wp_nonce_url(
                    admin_url( 'admin.php?page=course-builder-demo&delete_id=' . (int) $row['id'] ),
                    'cb_delete_demo_' . (int) $row['id']
                );
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;<?php echo ( $i % 2 === 0 ) ? '' : 'background:#fafbfc'; ?>">
                    <td style="padding:12px 16px;color:#94a3b8"><?php echo (int) $row['id']; ?></td>
                    <td style="padding:12px 16px;font-weight:600;color:#1e293b">
                        <?php echo esc_html( $row['student_name'] ); ?>
                    </td>
                    <td style="padding:12px 16px;color:#475569;font-family:monospace">
                        <?php echo esc_html( $row['phone'] ); ?>
                    </td>
                    <td style="padding:12px 16px;color:#475569">
                        <?php if ( $row['course_id'] && $row['course_title'] ) : ?>
                            <a href="<?php echo esc_url( get_permalink( (int) $row['course_id'] ) ); ?>" target="_blank"
                               style="color:#244092;text-decoration:none">
                                <?php echo esc_html( $row['course_title'] ); ?>
                            </a>
                        <?php else : ?>
                            <span style="color:#94a3b8">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:12px 16px;color:#64748b">
                        <?php echo esc_html( wp_date( 'd M Y, g:i a', strtotime( $row['registered_at'] ) ) ); ?>
                    </td>
                    <td style="padding:12px 16px">
                        <a href="<?php echo esc_url( $delete_url ); ?>"
                           onclick="return confirm('Delete this registration? This cannot be undone.')"
                           style="color:#dc2626;font-size:12px;text-decoration:none;font-weight:600">
                            🗑 Delete
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ( $total_pages > 1 ) : ?>
    <div style="display:flex;gap:6px;margin-top:16px;align-items:center;justify-content:flex-end">
        <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
            <?php
            $purl = admin_url( 'admin.php?page=course-builder-demo&paged=' . $p . ( $search ? '&s=' . urlencode( $search ) : '' ) );
            $active = ( $p === $page );
            ?>
            <a href="<?php echo esc_url( $purl ); ?>"
               style="padding:6px 12px;border-radius:6px;font-size:13px;text-decoration:none;
                      <?php echo $active ? 'background:#244092;color:#fff;font-weight:700' : 'background:#f1f5f9;color:#374151'; ?>">
                <?php echo $p; ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
