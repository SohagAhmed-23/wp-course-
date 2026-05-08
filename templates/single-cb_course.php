<?php
/**
 * Course Builder — Single Course Page
 * Design: Pedago Kids reference (Poppins, #244092 primary, #EF3E26 accent)
 */
defined( 'ABSPATH' ) || exit;

$cid       = get_the_ID();
$title     = get_the_title();
$subtitle  = get_post_meta( $cid, '_cb_subtitle',          true );
$duration  = (int) get_post_meta( $cid, '_cb_duration_months', true );
$age_min   = (int) get_post_meta( $cid, '_cb_age_min',         true );
$live      = (int) get_post_meta( $cid, '_cb_live_classes',    true );
$wc_id     = (int) get_post_meta( $cid, '_cb_wc_product_id',   true );
$video_url = get_post_meta( $cid, '_cb_video_url', true );
$thumb     = get_the_post_thumbnail_url( $cid, 'large' ) ?: '';
// Fallback: if no post thumbnail, try _cb_photo_id (legacy meta)
if ( ! $thumb ) {
	$_cb_pid = (int) get_post_meta( $cid, '_cb_photo_id', true );
	if ( $_cb_pid ) $thumb = wp_get_attachment_image_url( $_cb_pid, 'large' ) ?: '';
}

$objectives = array_values( array_filter( (array) json_decode( get_post_meta( $cid, '_cb_learning_objectives', true ) ?: '[]', true ), 'trim' ) );
$overview   = array_values( array_filter( (array) json_decode( get_post_meta( $cid, '_cb_programme_overview',  true ) ?: '[]', true ), 'trim' ) );
$units_raw  = (array) json_decode( get_post_meta( $cid, '_cb_course_content', true ) ?: '[]', true );
$units      = array_values( array_filter( $units_raw, fn( $u ) => ! empty( $u['title'] ) ) );
$support    = array_values( array_filter( (array) json_decode( get_post_meta( $cid, '_cb_additional_support', true ) ?: '[]', true ), 'trim' ) );

/* Course active status */
$course_active = get_post_meta( $cid, '_cb_course_active', true );
// Default to active if the meta has never been set (backward compatibility)
if ( '' === $course_active ) {
	$course_active = '1';
}
$course_active = (bool) $course_active;

/* Unique Features — dynamic with default fallback */
$_raw_features     = get_post_meta( $cid, '_cb_unique_features', true );
$_decoded_features = $_raw_features ? json_decode( $_raw_features, true ) : null;
$_has_custom_features = is_array( $_decoded_features ) && count( $_decoded_features ) > 0;

/**
 * Normalize garbled icon strings caused by wp_magic_quotes stripping \u backslashes.
 * e.g. "ud83euddE0" → "🧠"
 */
$_icon_fix_map = [
	'ud83euddE0' => '🧠', 'ud83euddE1' => '🧡',
	'ud83cudfb5' => '🎵',
	'ud83cudfc6' => '🏆',
	'ud83ddcf1'  => '📱', 'ud83ddCf1'  => '📱',
	'ud83cudf0d' => '🌍',
	'ud83ddcca'  => '📊', 'ud83ddCca'  => '📊',
	// also cover uppercase variants
	'ud83eudde0' => '🧠',
];

if ( $_has_custom_features ) {
	$unique_features = array_map( function( $f ) use ( $_icon_fix_map ) {
		$raw = strtolower( trim( $f['icon'] ?? '' ) );
		if ( isset( $_icon_fix_map[ $raw ] ) ) {
			$f['icon'] = $_icon_fix_map[ $raw ];
		} elseif ( isset( $_icon_fix_map[ $f['icon'] ?? '' ] ) ) {
			$f['icon'] = $_icon_fix_map[ $f['icon'] ];
		}
		return $f;
	}, $_decoded_features );
} else {
	// Default fallback features
	$unique_features = [
		[ 'icon' => '🧠', 'title' => 'Neuro-Friendly Design',   'description' => 'Multi-sensory tasks for all learning styles' ],
		[ 'icon' => '🎵', 'title' => 'Music-Led Learning',       'description' => 'Jolly Songs make phonics memorable and fun' ],
		[ 'icon' => '🏆', 'title' => 'Achievement Badges',       'description' => 'Digital rewards after every completed unit' ],
		[ 'icon' => '📱', 'title' => 'Mobile-Friendly Sessions', 'description' => 'Learn on any device, anywhere' ],
		[ 'icon' => '🌍', 'title' => 'Native-Level Instructors', 'description' => 'Qualified TEFL-certified teachers' ],
		[ 'icon' => '📊', 'title' => 'Data-Driven Progress',     'description' => "Real-time analytics for each child's growth" ],
	];
}

/* Batch Schedule — dynamic */
$_raw_schedules   = get_post_meta( $cid, '_cb_batch_schedules', true );
$_decoded_schedules = $_raw_schedules ? json_decode( $_raw_schedules, true ) : null;
$batch_schedules  = is_array( $_decoded_schedules ) ? $_decoded_schedules : [];

/* Batch Schedule — split into fixed Morning / Evening arrays */
$_morning_times = [];
$_evening_times = [];
foreach ( $batch_schedules as $s ) {
	$_grp  = strtolower( sanitize_text_field( $s['group'] ?? '' ) );
	$_time = sanitize_text_field( $s['time'] ?? '' );
	if ( ! $_time ) continue;
	if ( strpos( $_grp, 'morning' ) !== false ) {
		$_morning_times[] = $_time;
	} else {
		$_evening_times[] = $_time;
	}
}
$_has_schedule = ! empty( $_morning_times ) || ! empty( $_evening_times );

/* Dept / taxonomy */
$terms     = wp_get_post_terms( $cid, 'cb_category' );
$dept_id   = ( ! empty( $terms ) && ! is_wp_error( $terms ) ) ? (int) $terms[0]->term_id : 0;
$dept_name = ( ! empty( $terms ) && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';

/* Teachers in same dept */
$dept_teachers = [];
if ( $dept_id ) {
	foreach ( get_posts( [ 'post_type' => 'cb_teacher', 'posts_per_page' => -1, 'post_status' => 'publish' ] ) as $t ) {
		$tcats = array_map( 'intval', (array) json_decode( get_post_meta( $t->ID, '_cb_categories', true ) ?: '[]', true ) );
		if ( in_array( $dept_id, $tcats, true ) ) $dept_teachers[] = $t;
	}
}

/* WooCommerce */
$product   = ( $wc_id && function_exists( 'wc_get_product' ) ) ? wc_get_product( $wc_id ) : null;
$is_free   = false;
$vars_data = [];
$s_price   = '';
$s_url     = '';

if ( $product ) {
	if ( $product->get_type() === 'variable' ) {
		foreach ( $product->get_available_variations() as $v ) {
			$var = wc_get_product( $v['variation_id'] );
			if ( ! $var ) continue;
			$lbls = [];
			foreach ( $v['attributes'] as $k => $val ) {
				$tx     = str_replace( 'attribute_', '', $k );
				$tobj   = get_term_by( 'slug', $val, $tx );
				$lbls[] = $tobj ? $tobj->name : ucfirst( str_replace( '-', ' ', $val ) );
			}
			$label = implode( ' / ', $lbls ) ?: 'Option';
			$vars_data[] = [
				'label'      => $label,
				'price_html' => wc_price( $var->get_price() ),
				'period'     => strtolower( $label ),
				'url'        => add_query_arg( [ 'add-to-cart' => $v['variation_id'] ], wc_get_checkout_url() ),
			];
		}
	} elseif ( (float) $product->get_price() == 0 ) {
		$is_free = true;
	} else {
		$s_price = $product->get_price_html();
		$s_url   = add_query_arg( [ 'add-to-cart' => $wc_id ], wc_get_checkout_url() );
	}
}

/* Similar courses */
$similar = $dept_id ? get_posts( [
	'post_type' => 'cb_course', 'post_status' => 'publish',
	'posts_per_page' => 4, 'post__not_in' => [ $cid ],
	'tax_query' => [ [ 'taxonomy' => 'cb_category', 'field' => 'term_id', 'terms' => $dept_id ] ],
] ) : [];

$unit_count = count( $units );

/* Video embed helper */
if ( ! function_exists( 'cb_embed_url' ) ) {
	function cb_embed_url( string $url ): string {
		if ( preg_match( '/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\s]+)/', $url, $m ) )
			return 'https://www.youtube.com/embed/' . $m[1] . '?rel=0&modestbranding=1';
		if ( preg_match( '/vimeo\.com\/(\d+)/', $url, $m ) )
			return 'https://player.vimeo.com/video/' . $m[1];
		return '';
	}
}
$embed_url = $video_url ? cb_embed_url( $video_url ) : '';

get_header();
?>
<div class="cbo">

<!-- ══════════════════════  HERO  ══════════════════════ -->
<section class="cbo__hero">
	<div class="cbo__container">

		
		<h1 class="cbo__course-title"><?php echo esc_html( $title ); ?></h1>

		<?php if ( $subtitle ) : ?>
		<p class="cbo__course-tagline"><?php echo esc_html( $subtitle ); ?></p>
		<?php endif; ?>

		<div class="cbo__hero-meta">
			<?php if ( $unit_count ) : ?><span class="cbo__meta-pill"><?php echo $unit_count; ?> Units</span><?php endif; ?>
			<?php if ( $live )        : ?><span class="cbo__meta-pill"><?php echo $live; ?> Live Classes</span><?php endif; ?>
			<?php if ( $duration )    : ?><span class="cbo__meta-pill"><?php echo $duration; ?> Months</span><?php endif; ?>
			<?php if ( $age_min )     : ?><span class="cbo__meta-pill">Age <?php echo $age_min; ?>+</span><?php endif; ?>
			<span class="cbo__meta-pill">Online Live</span>
		</div>

	</div>
</section>

<!-- ══════════════════════  BODY  ══════════════════════ -->
<div class="cbo__container">
<div class="cbo__layout">

	<!-- ═════ LEFT COLUMN ═════ -->
	<div class="cbo__left-col">

		<!-- Learning Objectives -->
		<?php if ( ! empty( $objectives ) ) : ?>
		<div class="cbo__card">
			<div class="cbo__card-header">
				<div class="cbo__card-icon">🎯</div>
				<h2>Learning Objectives</h2>
			</div>
			<ul class="cbo__styled-list">
				<?php foreach ( $objectives as $obj ) : ?><li><?php echo esc_html( $obj ); ?></li><?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- Programme Overview -->
		<?php if ( ! empty( $overview ) ) : ?>
		<div class="cbo__card">
			<div class="cbo__card-header">
				<div class="cbo__card-icon">📋</div>
				<h2>Programme Overview</h2>
			</div>
			<ul class="cbo__styled-list cbo__styled-list--icon">
				<?php foreach ( $overview as $pt ) : ?><li>📌 <?php echo esc_html( $pt ); ?></li><?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- Course Contents -->
		<?php if ( ! empty( $units ) ) : ?>
		<div class="cbo__card">
			<div class="cbo__card-header">
				<div class="cbo__card-icon">📚</div>
				<h2>Course Contents</h2>
			</div>
			<div class="cbo__units-grid">
				<?php foreach ( $units as $i => $unit ) : ?>
				<div class="cbo__unit-item">
					<span class="cbo__unit-num"><?php printf( '%02d', $i + 1 ); ?></span>
					<div>
						<strong><?php echo esc_html( $unit['title'] ); ?></strong>
						<?php if ( ! empty( $unit['lessons'] ) ) : ?><p><?php echo esc_html( $unit['lessons'] ); ?></p><?php endif; ?>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<!-- Additional Support -->
		<?php if ( ! empty( $support ) ) : ?>
		<div class="cbo__card">
			<div class="cbo__card-header">
				<div class="cbo__card-icon">🤝</div>
				<h2>Additional Support</h2>
			</div>
			<ul class="cbo__styled-list">
				<?php foreach ( $support as $s ) : ?><li><?php echo esc_html( $s ); ?></li><?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<!-- Unique Features -->
		<?php if ( ! empty( $unique_features ) ) : ?>
		<div class="cbo__card">
			<div class="cbo__card-header">
				<div class="cbo__card-icon">✨</div>
				<h2>Unique Features</h2>
			</div>
			<div class="cbo__features-grid">
				<?php foreach ( $unique_features as $f ) : ?>
				<div class="cbo__feature-item">
					<span class="cbo__feat-icon"><?php echo esc_html( $f['icon'] ?? '' ); ?></span>
					<div><strong><?php echo esc_html( $f['title'] ?? '' ); ?></strong><p><?php echo esc_html( $f['description'] ?? '' ); ?></p></div>
				</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<!-- Course Instructors -->
		<?php if ( ! empty( $dept_teachers ) ) : ?>
		<div class="cbo__card">
			<div class="cbo__card-header">
				<div class="cbo__card-icon">👩‍🏫</div>
				<h2>Course Instructors</h2>
			</div>
			<div class="cbo__instructors-grid">
				<?php foreach ( $dept_teachers as $t ) :
					$pid       = (int) get_post_meta( $t->ID, '_cb_photo_id', true );
					$photo     = $pid ? wp_get_attachment_image_url( $pid, 'medium' ) : '';
					$desig     = get_post_meta( $t->ID, '_cb_designation', true );
					$parts     = explode( ' ', trim( $t->post_title ) );
					$ini       = strtoupper( ( $parts[0][0] ?? '' ) . ( end( $parts )[0] ?? '' ) );
				?>
				<a href="<?php echo esc_url( get_permalink( $t->ID ) ); ?>" class="cbo__instructor-card cbo__instructor-card--link">
					<div class="cbo__instructor-img-wrap">
						<?php if ( $photo ) : ?>
						<img src="<?php echo esc_url( $photo ); ?>" alt="<?php echo esc_attr( $t->post_title ); ?>">
						<?php else : ?>
						<div class="cbo__instructor-ini"><?php echo esc_html( $ini ); ?></div>
						<?php endif; ?>
						<div class="cbo__instructor-overlay"><span>View Profile →</span></div>
					</div>
					<div class="cbo__instructor-info">
						<strong><?php echo esc_html( $t->post_title ); ?></strong>
						<?php if ( $desig ) : ?><span class="cbo__instructor-desig"><?php echo esc_html( $desig ); ?></span><?php endif; ?>
					</div>
				</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

	</div><!-- /left-col -->

	<!-- ═════ RIGHT SIDEBAR ═════ -->
	<div class="cbo__sidebar">

		<!-- ENROL CARD -->
		<div class="cbo__enrol-card">
			<h3 class="cbo__enrol-title">Enrol in this Course</h3>

			<?php if ( ! empty( $vars_data ) ) : ?>
			<div class="cbo__price-toggle">
				<?php foreach ( $vars_data as $i => $v ) : ?>
				<button class="cbo__toggle-btn<?php echo $i === 0 ? ' active' : ''; ?>"
					data-html="<?php echo esc_attr( $v['price_html'] ); ?>"
					data-period="<?php echo esc_attr( $v['period'] ); ?>"
					data-url="<?php echo esc_url( $v['url'] ); ?>">
					<?php echo esc_html( $v['label'] ); ?>
				</button>
				<?php endforeach; ?>
			</div>
			<div class="cbo__price-block">
				<span class="cbo__price-amount" id="cboPriceVal"><?php echo wp_kses_post( $vars_data[0]['price_html'] ); ?></span>
				<span class="cbo__price-period" id="cboPricePer">/ <?php echo esc_html( $vars_data[0]['period'] ); ?></span>
			</div>
			<p class="cbo__price-note" id="cboPriceNote">Pay one time – Save on yearly plan</p>
			<a class="cbo__enrol-btn" id="cboEnrolBtn" href="<?php echo esc_url( $vars_data[0]['url'] ); ?>">🚀 Enrol Now</a>

			<?php elseif ( $is_free ) : ?>
			<p class="cbo__price-free">✓ This course is FREE</p>
			<a class="cbo__enrol-btn" 
			   href="<?php echo esc_url( wc_get_checkout_url() . '?add-to-cart=' . $wc_id ); ?>">
			   🚀 Enrol Free
			</a>

			<?php elseif ( $s_price ) : ?>
			<div class="cbo__price-block">
				<span class="cbo__price-amount"><?php echo wp_kses_post( $s_price ); ?></span>
			</div>
			<a class="cbo__enrol-btn" href="<?php echo esc_url( $s_url ); ?>">🚀 Enrol Now</a>

			<?php else : ?>
			<a class="cbo__enrol-btn cbo__enrol-btn--ghost" href="#cbo-demo">Register Interest</a>
			<?php endif; ?>

			<ul class="cbo__enrol-perks">
				<?php if ( $unit_count ) : ?><li>✅ Full access to all <?php echo $unit_count; ?> units</li><?php endif; ?>
				<li>✅ Live sessions + recordings</li>
				<li>✅ Certificate on completion</li>
			</ul>
		</div>

		<!-- COURSE EXPLAINER VIDEO (always shown between Enrol and Demo) -->
		<div class="cbo__card">
			<div class="cbo__card-header">
				<div class="cbo__card-icon">🎬</div>
				<h3>Course Explainer</h3>
			</div>
			<div class="cbo__video-box">
				<?php if ( $embed_url ) : ?>
				<iframe src="<?php echo esc_url( $embed_url ); ?>" frameborder="0" allowfullscreen loading="lazy"></iframe>
				<?php elseif ( $thumb ) : ?>
				<img src="<?php echo esc_url( $thumb ); ?>" alt="" class="cbo__video-thumb">
				<div class="cbo__play-overlay">
					<div class="cbo__play-btn">
						<svg viewBox="0 0 24 24" fill="white" width="36" height="36"><path d="M8 5v14l11-7z"/></svg>
					</div>
				</div>
				<div class="cbo__video-label">Watch Course Overview</div>
				<?php else : ?>
				<div class="cbo__video-placeholder">
					<div class="cbo__play-btn"><svg viewBox="0 0 24 24" fill="white" width="36" height="36"><path d="M8 5v14l11-7z"/></svg></div>
					<p>Add a YouTube URL in the course editor to show the explainer video here.</p>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<!-- REGISTER DEMO CLASS -->
		<div class="cbo__card" id="cbo-demo">
			<div class="cbo__card-header">
				<div class="cbo__card-icon">📝</div>
				<h3>Register for Demo Class</h3>
			</div>
			<p class="cbo__demo-sub">Join a free 30-minute demo class — no commitment required.</p>

			<!-- Step 1: Name + Phone -->
			<form id="cboDemoStep1" onsubmit="cboDemoSendOtp(event)">
				<div class="cbo__form-group">
					<label>Student Name</label>
					<input type="text" id="cboDemoName" placeholder="e.g. Ayaan Rahman" required>
				</div>
				<div class="cbo__form-group">
					<label>Parent Phone Number</label>
					<input type="tel" id="cboDemoPhone" placeholder="01XXXXXXXXX" required>
				</div>
				<button type="submit" class="cbo__enrol-btn" id="cboDemoSendBtn" style="margin-top:4px">
					📨 Send OTP
				</button>
				<div class="cbo__form-err" id="cboDemoErr1" style="display:none;color:#dc2626;font-size:13px;margin-top:8px"></div>
			</form>

			<!-- Step 2: OTP verification (hidden initially) -->
			<form id="cboDemoStep2" style="display:none" onsubmit="cboDemoVerifyOtp(event)">
				<p style="font-size:13px;color:#475569;margin:0 0 12px">
					A 6-digit OTP has been sent to <strong id="cboDemoPhoneDisplay"></strong>. Enter it below.
				</p>
				<div class="cbo__form-group">
					<label>OTP Code</label>
					<input type="text" id="cboDemoOtpInput" placeholder="Enter 6-digit OTP" maxlength="6" pattern="\d{6}" required
					       style="letter-spacing:4px;font-size:18px;font-weight:700">
				</div>
				<div style="display:flex;gap:8px;margin-top:4px">
					<button type="submit" class="cbo__enrol-btn" id="cboDemoVerifyBtn">✅ Verify &amp; Book</button>
					<button type="button" class="cbo__enrol-btn" id="cboDemoResendBtn"
					        style="background:#64748b;font-size:13px"
					        onclick="cboDemoResend()">Resend OTP</button>
				</div>
				<div class="cbo__form-err" id="cboDemoErr2" style="display:none;color:#dc2626;font-size:13px;margin-top:8px"></div>
			</form>

			<!-- Success -->
			<div class="cbo__form-ok" id="cboDemoOk" style="display:none">
				✓ Registration confirmed! We will contact you shortly to schedule your demo class.
			</div>
		</div>
		<?php /* Expose course ID for AJAX */ ?>
		<script>var CB_DemoCourseId=<?php echo (int) get_the_ID(); ?>;</script>

		<!-- BATCH SCHEDULE -->
		<?php if ( $_has_schedule ) : ?>
		<div class="cbo__card">
			<div class="cbo__card-header">
				<div class="cbo__card-icon">🗓️</div>
				<h3>Batch Schedule</h3>
			</div>
			<div class="cbo__schedule-grid">

				<?php if ( ! empty( $_morning_times ) ) : ?>
				<div>
					<div class="cbo__schedule-heading cbo__schedule-heading--morning">☀️ Morning</div>
					<?php foreach ( $_morning_times as $time ) : ?>
					<div class="cbo__time-pill"><?php echo esc_html( $time ); ?></div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<?php if ( ! empty( $_evening_times ) ) : ?>
				<div>
					<div class="cbo__schedule-heading cbo__schedule-heading--evening">🌙 Evening</div>
					<?php foreach ( $_evening_times as $time ) : ?>
					<div class="cbo__time-pill"><?php echo esc_html( $time ); ?></div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

			</div>
		</div>
		<?php endif; ?>

		<!-- 24/7 SUPPORT -->
		<div class="cbo__support-card">
			<div class="cbo__support-badge">24/7</div>
			<h3>Always Here For You</h3>
			<ul class="cbo__support-list">
				<li><span class="cbo__s-icon">📞</span><span>Parent helpline &amp; support</span></li>
				<li><span class="cbo__s-icon">🔐</span><span>Secure access to recordings</span></li>
				<li><span class="cbo__s-icon">🔄</span><span>Missed class recovery</span></li>
				<li><span class="cbo__s-icon">👨‍👩‍👧</span><span>Parent–teacher sessions</span></li>
				<li><span class="cbo__s-icon">📈</span><span>Learning progress tracking</span></li>
			</ul>
		</div>

	</div><!-- /sidebar -->

</div><!-- .cbo__layout -->
</div><!-- .cbo__container -->



<style>
/* ════════════════════════════════════════════
   SIMILAR COURSES — pixel-perfect card style
   ════════════════════════════════════════════ */
.cbo__similar {
	padding: 60px 0 80px;
	background: #f7f8fc;
}
.cbo__similar-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
	gap: 28px;
	padding: 8px 4px;
}

/* Card */
.cbo__course-card {
	background: #fff;
	border-radius: 18px;
	overflow: hidden;
	position: relative;
	border: 2px solid transparent;
	border-right-color: #EF3E26;
	border-bottom-color: #EF3E26;
box-shadow:rgba(50, 50, 93, 0.25) 0px 50px 100px -20px, #ffffff 0px 30px 60px -30px, #244092 0px -94px 44px 45px inset;
	display: flex;
	flex-direction: column;
	transition: transform .28s ease, box-shadow .28s ease;
	opacity: 0;
	transform: translateY(24px);
}
.cbo__course-card:hover {
	transform: translateY(-8px);
	box-shadow: 0 20px 40px rgba(36,64,146,0.16);
}

/* Blue inset shadow — image area only */
.cbo__course-card::before {
	content: "";
	position: absolute;
	top: 0; left: 0; right: 0;
	height: 320px;
	border-radius: 16px 16px 0 0;
	pointer-events: none;
	z-index: 2;
	box-shadow:
		inset 0 22px 32px -20px rgba(36,64,146,0.85),
		inset 14px 0 28px -24px rgba(36,64,146,0.40),
		inset -14px 0 28px -24px rgba(36,64,146,0.40);
}

/* Link wrapper */
.cbo__course-card a.cbo__cc-link {
	position: relative;
	z-index: 1;
	text-decoration: none;
	display: flex;
	flex-direction: column;
	flex: 1;
}

/* Image */
.cbo__cc-image {
	height: 400px;
	background-size: cover;
	background-position: center top;
	border-radius: 16px 16px 0 0;
	flex-shrink: 0;
}

/* Body */
.cbo__cc-body {
	padding: 16px 18px 18px;
	background: #fff;
	display: flex;
	flex-direction: column;
	flex: 1;
	gap:0px;
}

/* Meta row */
.cbo__cc-meta {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 20px;
	margin-bottom: 12px;
}
.cbo__cc-meta-item {
	display: flex;
	align-items: center;
	
	font-family: 'Poppins', sans-serif;
	font-size: 11px;
	color: #374151;
	line-height: 1.3;
}
.cbo__cc-meta-item svg {
	flex-shrink: 0;
	width: 36px;
	height: 35px;
}
.cbo__cc-meta-lines {
	display: flex;
	flex-direction: column;
}
.cbo__cc-meta-lines strong {
	font-family: 'Poppins', sans-serif;
	font-size: 17px;
	font-weight: 700;
	color: #244092;
	line-height: 1.2;
}
.cbo__cc-meta-lines span {
	font-family: 'Poppins', sans-serif;
	font-size: 17px;
	color: #244092;
	font-weight: 400;
	line-height: 1.2;
}

/* Title */
.cbo__cc-title {
	font-family: 'Poppins', sans-serif;
	font-size: 2.6rem;
	font-weight: 900;
	color: #244092;
	margin: 0 0 6px;
	line-height: 1.3;
	transition: color .22s ease;
}
/* .cbo__course-card:hover .cbo__cc-title { color: #EF3E26; } */

/* Excerpt */
.cbo__cc-excerpt {
	font-family: 'Poppins', sans-serif;
	color: #6b7280;
	font-size: 12px;
	margin: 0 0 10px;
	line-height: 1.5;
}

/* Suitable Age */
.cbo__cc-age {
	font-family: 'Poppins', sans-serif;
	font-size: 20px;
	font-weight: 600;
	color: #EF3E26;
	margin: 0 0 8px;
}

/* Learn More */
.cbo__cc-more {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-family: 'Poppins', sans-serif;
	font-size: 13px;
	font-weight: 400;
	color: #EF3E26;
	text-decoration: none;
	margin-top: auto;
	padding-top: 4px;
	transition: opacity .2s;
}
.cbo__cc-more:hover { opacity: .75; }

@media (max-width: 768px) {
	.cbo__similar-grid { grid-template-columns: 1fr 1fr; gap: 16px; }
	
	.cbo__course-card::before { height: 220px; }
}
@media (max-width: 480px) {
	.cbo__similar-grid { grid-template-columns: 1fr; }
}
</style>


<!-- ══════════════════════  SIMILAR COURSES  ══════════════════════ -->
<?php if ( ! empty( $similar ) ) : ?>
<section class="cbo__similar">
	<div class="cbo__container">

		<div class="cbo__section-header">
			<h2>Similar Courses</h2>
			<p>Continue your child's learning journey</p>
		</div>

		<div class="cbo__similar-grid">
		<?php foreach ( $similar as $idx => $sc ) :
			$sc_thumb = get_the_post_thumbnail_url( $sc->ID, 'full' ) ?: '';
			if ( ! $sc_thumb ) {
				$_spid = (int) get_post_meta( $sc->ID, '_cb_photo_id', true );
				if ( $_spid ) $sc_thumb = wp_get_attachment_image_url( $_spid, 'full' ) ?: '';
			}
			if ( ! $sc_thumb ) $sc_thumb = 'https://via.placeholder.com/600x600?text=Course';

			$sc_dur  = (int) get_post_meta( $sc->ID, '_cb_duration_months', true );
			$sc_live = (int) get_post_meta( $sc->ID, '_cb_live_classes',    true );
			$sc_excerpt = get_the_excerpt( $sc->ID );
		?>
		<div class="cbo__course-card">

			<a class="cbo__cc-link" href="<?php echo esc_url( get_permalink( $sc->ID ) ); ?>">

				<!-- Photo (background-image, matches shortcode) -->
				<div class="cbo__cc-image"
				     style="background-image:url('<?php echo esc_url( $sc_thumb ); ?>')"
				     role="img"
				     aria-label="<?php echo esc_attr( $sc->post_title ); ?>">
				</div>

				<!-- White body -->
				<div class="cbo__cc-body">

					<!-- Meta row with exact SVGs from shortcode -->
					<div class="cbo__cc-meta">

						<?php if ( $sc_live ) : ?>
						<span class="cbo__cc-meta-item">
							<svg width="36" height="35" viewBox="0 0 36 35" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0">
								<path d="M0 17.5C0 7.83502 7.83502 0 17.5 0H18.5C28.165 0 36 7.83502 36 17.5C36 27.165 28.165 35 18.5 35H17.5C7.83502 35 0 27.165 0 17.5Z" fill="#EF3E26" fill-opacity="0.1"/>
								<path d="M17.06 23.0875C15.7279 23.9201 14 22.9624 14 21.3915V13.6085C14 12.0376 15.7279 11.0799 17.06 11.9125L23.2864 15.804C24.5397 16.5873 24.5397 18.4127 23.2864 19.196L17.06 23.0875Z" fill="#EF3E26"/>
							</svg>
							<span class="cbo__cc-meta-lines">
								<strong><?php echo $sc_live; ?> Live</strong>
								<span>Classes</span>
							</span>
						</span>
						<?php endif; ?>

						<?php if ( $sc_dur ) : ?>
						<span class="cbo__cc-meta-item">
							<svg width="36" height="35" viewBox="0 0 36 35" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0">
								<path d="M0 17.5C0 7.83502 7.83502 0 17.5 0H18.5C28.165 0 36 7.83502 36 17.5C36 27.165 28.165 35 18.5 35H17.5C7.83502 35 0 27.165 0 17.5Z" fill="#244092" fill-opacity="0.1"/>
								<path d="M15.5 10.6818V11.3636H14.1C13.5204 11.3636 12.994 11.5934 12.6153 11.963C12.2366 12.3325 12 12.8445 12 13.4091V22.9545C12 23.5191 12.2359 24.0318 12.6153 24.4007C12.9947 24.7695 13.5204 25 14.1 25H23.9C24.4796 25 25.006 24.7702 25.3847 24.4007C25.7634 24.0311 26 23.5191 26 22.9545V13.4091C26 12.8445 25.7641 12.3318 25.3847 11.963C25.0053 11.5941 24.4796 11.3636 23.9 11.3636H22.5V10.6818C22.5 10.3055 22.1864 10 21.8 10C21.4136 10 21.1 10.3055 21.1 10.6818V11.3636H16.9V10.6818C16.9 10.3055 16.5864 10 16.2 10C15.8136 10 15.5 10.3055 15.5 10.6818ZM24.6 15.4545H13.4V13.4091C13.4 13.2209 13.4777 13.0511 13.6051 12.927C13.7325 12.803 13.9068 12.7273 14.1 12.7273H15.5V13.4091C15.5 13.7855 15.8136 14.0909 16.2 14.0909C16.5864 14.0909 16.9 13.7855 16.9 13.4091V12.7273H21.1V13.4091C21.1 13.7855 21.4136 14.0909 21.8 14.0909C22.1864 14.0909 22.5 13.7855 22.5 13.4091V12.7273H23.9C24.0932 12.7273 24.2675 12.803 24.3949 12.927C24.5223 13.0511 24.6 13.2209 24.6 13.4091V15.4545ZM13.4 16.8182H24.6V22.9545C24.6 23.1427 24.5223 23.3125 24.3949 23.4366C24.2675 23.5607 24.0932 23.6364 23.9 23.6364H14.1C13.9068 23.6364 13.7325 23.5607 13.6051 23.4366C13.4777 23.3125 13.4 23.1427 13.4 22.9545V16.8182Z" fill="#244092"/>
							</svg>
							<span class="cbo__cc-meta-lines">
								<span>Duration</span>
								<strong><?php echo $sc_dur; ?> Months</strong>
							</span>
						</span>
						<?php endif; ?>

					</div>

					<!-- Title -->
					<h4 class="cbo__cc-title"><?php echo esc_html( wp_trim_words( $sc->post_title, 6, '...' ) ); ?></h4>

					<!-- Excerpt -->
					<?php if ( $sc_excerpt ) : ?>
					<p class="cbo__cc-excerpt"><?php echo esc_html( wp_trim_words( $sc_excerpt, 10, '...' ) ); ?></p>
					<?php endif; ?>

					<!-- Suitable Age -->
					<?php $sc_age = (int) get_post_meta( $sc->ID, '_cb_age_min', true ); ?>
					<?php if ( $sc_age ) : ?>
					<p class="cbo__cc-age">Suitable age: <?php echo $sc_age; ?>+</p>
					<?php endif; ?>

					<!-- Learn More -->
					<span class="cbo__cc-more">
						<span style="font-weight:400;font-size:13px;margin-right:6px;">Learn More</span>
						<svg width="8" height="8" viewBox="0 0 8 8" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M0.666819 1.33333H5.72405L0.195496 6.862C-0.0651653 7.12267 -0.0651653 7.54467 0.195496 7.80467C0.456157 8.06467 0.878148 8.06533 1.13814 7.80467L6.66669 2.276V7.33333C6.66669 7.70133 6.96535 8 7.33335 8C7.70134 8 8 7.70133 8 7.33333V0.666667C8 0.576 7.982 0.49 7.94933 0.411333C7.91667 0.332667 7.86867 0.259333 7.80534 0.196L7.804 0.194667C7.74267 0.133333 7.66934 0.0840001 7.58868 0.0506668C7.51001 0.0180001 7.42401 0 7.33335 0H0.666819C0.298827 0 0.000166517 0.298667 0.000166517 0.666667C0.000166517 1.03467 0.298827 1.33333 0.666819 1.33333Z" fill="#EF3E26"/>
						</svg>
					</span>

				</div><!-- /.cbo__cc-body -->

			</a><!-- /.cbo__cc-link -->

		</div><!-- /.cbo__course-card -->
		<?php endforeach; ?>
		</div><!-- /.cbo__similar-grid -->

	</div><!-- /.cbo__container -->
</section>
<?php endif; ?>

</div><!-- .cbo -->

<script>
/* Price toggle */
(function(){
	var tabs = document.querySelectorAll('.cbo__toggle-btn');
	if (!tabs.length) return;
	tabs.forEach(function(tab){
		tab.addEventListener('click', function(){
			tabs.forEach(function(t){ t.classList.remove('active'); });
			this.classList.add('active');
			var pv  = document.getElementById('cboPriceVal');
			var pp  = document.getElementById('cboPricePer');
			var pn  = document.getElementById('cboPriceNote');
			var btn = document.getElementById('cboEnrolBtn');
			if (pv)  pv.innerHTML   = this.dataset.html;
			if (pp)  pp.textContent = '/ ' + this.dataset.period;
			if (btn) btn.href       = this.dataset.url;
			if (pn)  pn.style.visibility = (this.dataset.period.indexOf('year') !== -1) ? 'visible' : 'hidden';
		});
	});
})();

/* Demo form — OTP flow */
var _cboDemoPhone = '';

function cboDemoSendOtp(e) {
	e.preventDefault();
	var name  = document.getElementById('cboDemoName').value.trim();
	var phone = document.getElementById('cboDemoPhone').value.trim();
	var btn   = document.getElementById('cboDemoSendBtn');
	var err   = document.getElementById('cboDemoErr1');
	err.style.display = 'none';
	btn.disabled = true;
	btn.textContent = 'Sending…';
	fetch((typeof CB_Public !== 'undefined' ? CB_Public.ajax_url : '/wp-admin/admin-ajax.php'), {
		method : 'POST',
		headers: {'Content-Type':'application/x-www-form-urlencoded'},
		body   : new URLSearchParams({
			action   : 'cb_demo_send_otp',
			nonce    : (typeof CB_Public !== 'undefined' ? CB_Public.nonce : ''),
			name     : name,
			phone    : phone,
			course_id: (typeof CB_DemoCourseId !== 'undefined' ? CB_DemoCourseId : 0)
		})
	}).then(function(r){ return r.json(); }).then(function(res) {
		if (res.success) {
			_cboDemoPhone = res.data.phone || phone;
			document.getElementById('cboDemoPhoneDisplay').textContent = _cboDemoPhone;
			document.getElementById('cboDemoStep1').style.display = 'none';
			document.getElementById('cboDemoStep2').style.display = 'block';
			document.getElementById('cboDemoOtpInput').focus();
		} else {
			err.textContent   = res.data && res.data.message ? res.data.message : 'Something went wrong.';
			err.style.display = 'block';
			btn.disabled      = false;
			btn.textContent   = '📨 Send OTP';
		}
	}).catch(function() {
		err.textContent   = 'Network error. Please try again.';
		err.style.display = 'block';
		btn.disabled      = false;
		btn.textContent   = '📨 Send OTP';
	});
}

function cboDemoVerifyOtp(e) {
	e.preventDefault();
	var otp  = document.getElementById('cboDemoOtpInput').value.trim();
	var name = document.getElementById('cboDemoName').value.trim();
	var btn  = document.getElementById('cboDemoVerifyBtn');
	var err  = document.getElementById('cboDemoErr2');
	err.style.display = 'none';
	btn.disabled = true;
	btn.textContent = 'Verifying…';
	fetch((typeof CB_Public !== 'undefined' ? CB_Public.ajax_url : '/wp-admin/admin-ajax.php'), {
		method : 'POST',
		headers: {'Content-Type':'application/x-www-form-urlencoded'},
		body   : new URLSearchParams({
			action   : 'cb_demo_verify_otp',
			nonce    : (typeof CB_Public !== 'undefined' ? CB_Public.nonce : ''),
			name     : name,
			phone    : _cboDemoPhone,
			otp      : otp,
			course_id: (typeof CB_DemoCourseId !== 'undefined' ? CB_DemoCourseId : 0)
		})
	}).then(function(r){ return r.json(); }).then(function(res) {
		if (res.success) {
			document.getElementById('cboDemoStep2').style.display = 'none';
			document.getElementById('cboDemoOk').style.display    = 'block';
		} else {
			err.textContent   = res.data && res.data.message ? res.data.message : 'Verification failed.';
			err.style.display = 'block';
			btn.disabled      = false;
			btn.textContent   = '✅ Verify & Book';
		}
	}).catch(function() {
		err.textContent   = 'Network error. Please try again.';
		err.style.display = 'block';
		btn.disabled      = false;
		btn.textContent   = '✅ Verify & Book';
	});
}

function cboDemoResend() {
	document.getElementById('cboDemoStep2').style.display = 'none';
	document.getElementById('cboDemoStep1').style.display = 'block';
	document.getElementById('cboDemoSendBtn').disabled    = false;
	document.getElementById('cboDemoSendBtn').textContent = '📨 Send OTP';
	document.getElementById('cboDemoOtpInput').value      = '';
}

/* Scroll reveal */
(function(){
	if (!('IntersectionObserver' in window)) return;
	var els = document.querySelectorAll('.cbo__card,.cbo__enrol-card,.cbo__support-card,.cbo__course-card');
	els.forEach(function(el){
		el.style.cssText += 'opacity:0;transform:translateY(24px);transition:opacity .5s ease,transform .5s ease;';
	});
	var obs = new IntersectionObserver(function(entries){
		entries.forEach(function(e){
			if(e.isIntersecting){
				e.target.style.opacity='1';
				e.target.style.transform='translateY(0)';
				obs.unobserve(e.target);
			}
		});
	},{threshold:0.08});
	els.forEach(function(el){ obs.observe(el); });
})();
</script>
<?php get_footer(); ?>
