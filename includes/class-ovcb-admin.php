<?php
/**
 * Admin page and tabs.
 *
 * @package OV_Content_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OVCB_Admin {

	const SLUG = 'ov-content-bridge';
	const CAP  = 'manage_options';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ovcb_export', array( 'OVCB_Exporter', 'handle' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OVCB_FILE ), array( __CLASS__, 'action_links' ) );
		OVCB_Jobs::init();
	}

	public static function menu() {
		self::$hook = add_menu_page(
			'OV Content Bridge',
			'انتقال محتوا',
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-randomize',
			58.73
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">تنظیمات</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return; // Load nothing on any other admin screen.
		}
		wp_enqueue_style( 'ovcb-admin', OVCB_URL . 'assets/admin.css', array(), OVCB_VERSION );
		wp_enqueue_script( 'ovcb-admin', OVCB_URL . 'assets/admin.js', array(), OVCB_VERSION, true );
		wp_localize_script(
			'ovcb-admin',
			'OVCB_DATA',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ovcb_ajax' ),
			)
		);
	}

	private static function tabs() {
		return array(
			'export'   => array( 'dashicons-download', 'خروجی (اکسپورت)' ),
			'products' => array( 'dashicons-cart', 'ایمپورت محصولات' ),
			'posts'    => array( 'dashicons-media-document', 'ایمپورت نوشته‌ها' ),
			'linking'  => array( 'dashicons-admin-links', 'به‌روزرسانی و لینک‌سازی داخلی' ),
			'history'  => array( 'dashicons-backup', 'تاریخچه و بازگردانی' ),
			'help'     => array( 'dashicons-editor-help', 'راهنما' ),
		);
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'export'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'export';
		}
		echo '<div class="wrap ovcb-wrap">';
		echo '<h1 class="ovcb-title"><span class="dashicons dashicons-randomize"></span> OV Content Bridge <small>نسخه ' . esc_html( OVCB_VERSION ) . '</small></h1>';
		echo '<nav class="nav-tab-wrapper ovcb-tabs">';
		foreach ( $tabs as $key => $t ) {
			$url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $key );
			printf(
				'<a href="%s" class="nav-tab %s"><span class="dashicons %s"></span> %s</a>',
				esc_url( $url ),
				$key === $tab ? 'nav-tab-active' : '',
				esc_attr( $t[0] ),
				esc_html( $t[1] )
			);
		}
		echo '</nav><div class="ovcb-body">';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div></div>';
	}

	/* ------------------------------------------------------------------ */

	private static function env_notice() {
		$seo    = OVCB_Helpers::seo_plugin();
		$labels = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'Rank Math',
			'seopress' => 'SEOPress',
			'aioseo'   => 'All in One SEO (متا در فیلد داخلی افزونه ذخیره می‌شود)',
			'none'     => 'پیدا نشد',
		);
		echo '<div class="ovcb-env">';
		echo '<span>ووکامرس: <b>' . ( function_exists( 'wc_get_product' ) ? 'فعال' : 'غیرفعال' ) . '</b></span>';
		echo '<span>افزونه سئو: <b>' . esc_html( $labels[ $seo ] ) . '</b></span>';
		echo '<span>قالب: <b>' . esc_html( wp_get_theme()->get( 'Name' ) ) . '</b></span>';
		echo '</div>';
	}

	private static function sample_link( $file, $label ) {
		printf(
			'<a class="button button-small" href="%s" download>%s</a> ',
			esc_url( OVCB_URL . 'assets/samples/' . $file ),
			esc_html( $label )
		);
	}

	private static function result_box() {
		?>
		<div class="ovcb-result" hidden>
			<div class="ovcb-progress"><div class="ovcb-bar"></div></div>
			<div class="ovcb-summary"></div>
			<div class="ovcb-warnings"></div>
			<div class="ovcb-actions"></div>
			<table class="widefat striped ovcb-log">
				<thead><tr><th>#</th><th>وضعیت</th><th>عنوان</th><th>توضیح</th><th>پیوندها</th></tr></thead>
				<tbody></tbody>
			</table>
		</div>
		<?php
	}

	private static function common_import_fields( $kind ) {
		?>
		<tr>
			<th scope="row">فایل</th>
			<td><input type="file" name="file" accept=".json,.csv" required>
				<p class="description">JSON (پیشنهادی) یا CSV با سطر اول به‌عنوان نام ستون‌ها.</p></td>
		</tr>
		<tr>
			<th scope="row">اگر مورد از قبل وجود داشت</th>
			<td>
				<select name="match">
					<option value="update">به‌روزرسانی شود، وگرنه ساخته شود</option>
					<option value="skip" selected>رد شود (فقط موارد جدید ساخته شوند)</option>
					<option value="update_only">فقط به‌روزرسانی (مورد جدید ساخته نشود)</option>
					<option value="create">همیشه مورد جدید ساخته شود</option>
				</select>
				<p class="description">
					<?php echo 'products' === $kind ? 'تطبیق به ترتیب: شناسه (id) ← SKU ← نامک (slug).' : 'تطبیق به ترتیب: شناسه (id) ← نامک (slug).'; ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">گزینه‌ها</th>
			<td class="ovcb-checks">
				<label><input type="checkbox" name="force_draft" value="1" checked> موارد جدید «پیش‌نویس» ذخیره شوند (فقط کافی است بعداً دکمه انتشار را بزنید)</label>
				<label><input type="checkbox" name="create_terms" value="1" checked> دسته‌بندی/برچسب‌هایی که وجود ندارند ساخته شوند</label>
				<label><input type="checkbox" name="images" value="1" checked> تصاویر از آدرس اینترنتی دانلود و در کتابخانه رسانه ذخیره شوند</label>
				<label><input type="checkbox" name="update_slug" value="1"> هنگام به‌روزرسانی، نامک هم تغییر کند (معمولاً خاموش بماند)</label>
			</td>
		</tr>
		<?php
		self::dry_run_row();
	}

	private static function dry_run_row() {
		?>
		<tr class="ovcb-dry-row">
			<th scope="row">پیش‌نمایش</th>
			<td><label><input type="checkbox" name="dry_run" value="1" checked> <b>اجرای آزمایشی (Dry Run)</b> — هیچ تغییری ذخیره نمی‌شود؛ فقط گزارش می‌دهد چه اتفاقی می‌افتد.</label></td>
		</tr>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Tabs                                                                 */
	/* ------------------------------------------------------------------ */

	private static function tab_export() {
		self::env_notice();
		?>
		<div class="ovcb-card">
			<h2>خروجی گرفتن از محتوای سایت</h2>
			<p>این فایل شامل عنوان، نامک، آدرس، دسته‌ها، برچسب‌ها، متای سئو، سرتیترها، لینک‌های داخلی ورودی/خروجی و محتوای کامل است. همین فایل را برای تحلیل و نوشتن مقاله‌ها ارسال کنید.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ovcb_export">
				<?php wp_nonce_field( 'ovcb_export' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">چه چیزهایی؟</th>
						<td class="ovcb-checks">
							<label><input type="checkbox" name="types[]" value="post" checked> نوشته‌ها / مقالات</label>
							<label><input type="checkbox" name="types[]" value="product" <?php checked( function_exists( 'wc_get_product' ) ); ?> <?php disabled( ! function_exists( 'wc_get_product' ) ); ?>> محصولات ووکامرس</label>
							<label><input type="checkbox" name="types[]" value="page"> برگه‌ها</label>
							<label><input type="checkbox" name="taxonomies" value="1" checked> دسته‌بندی‌ها و برچسب‌ها (با آدرس و تعداد)</label>
						</td>
					</tr>
					<tr>
						<th scope="row">وضعیت</th>
						<td class="ovcb-checks ovcb-inline">
							<label><input type="checkbox" name="statuses[]" value="publish" checked> منتشرشده</label>
							<label><input type="checkbox" name="statuses[]" value="draft"> پیش‌نویس</label>
							<label><input type="checkbox" name="statuses[]" value="pending"> در انتظار</label>
							<label><input type="checkbox" name="statuses[]" value="future"> زمان‌بندی‌شده</label>
							<label><input type="checkbox" name="statuses[]" value="private"> خصوصی</label>
						</td>
					</tr>
					<tr>
						<th scope="row">محتوا</th>
						<td>
							<select name="content">
								<option value="both">HTML کامل + متن ساده (پیشنهادی برای تحلیل و لینک‌سازی)</option>
								<option value="html">فقط HTML کامل</option>
								<option value="text">فقط متن ساده (حجم کمتر)</option>
								<option value="none">بدون محتوا (فقط فهرست و متادیتا)</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">قالب فایل</th>
						<td><label><input type="checkbox" name="pretty" value="1" checked> JSON خوانا (با تورفتگی)</label></td>
					</tr>
				</table>
				<?php submit_button( 'دانلود فایل خروجی JSON', 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private static function tab_products() {
		self::env_notice();
		if ( ! function_exists( 'wc_get_product' ) ) {
			echo '<div class="notice notice-error inline"><p>برای ایمپورت محصولات باید ووکامرس فعال باشد.</p></div>';
			return;
		}
		?>
		<div class="ovcb-card">
			<h2>ایمپورت / به‌روزرسانی محصولات</h2>
			<p>محصولات ساده و خارجی ساخته می‌شوند. برای محصولات متغیرِ موجود فقط متن‌ها، دسته‌ها، تصاویر و سئو به‌روز می‌شود (قیمت و متغیرها دست نمی‌خورند).
			<?php self::sample_link( 'products-sample.json', 'نمونه JSON' ); ?><?php self::sample_link( 'products-sample.csv', 'نمونه CSV' ); ?></p>
			<form class="ovcb-job-form" data-kind="products" enctype="multipart/form-data">
				<table class="form-table" role="presentation">
					<?php self::common_import_fields( 'products' ); ?>
				</table>
				<?php submit_button( 'شروع', 'primary', 'submit', false ); ?>
			</form>
			<?php self::result_box(); ?>
		</div>
		<?php
	}

	private static function tab_posts() {
		self::env_notice();
		?>
		<div class="ovcb-card">
			<h2>ایمپورت نوشته‌ها و مقالات</h2>
			<p>مقاله‌ها به‌صورت پیش‌نویس ساخته می‌شوند؛ دسته‌بندی، برچسب، تصویر شاخص، عنوان و توضیحات متا و کلمه کلیدی کانونی (Yoast / Rank Math / SEOPress) هم تنظیم می‌شود.
			<?php self::sample_link( 'posts-sample.json', 'نمونه JSON' ); ?></p>
			<form class="ovcb-job-form" data-kind="posts" enctype="multipart/form-data">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">نوع محتوا</th>
						<td><select name="post_type"><option value="post">نوشته (مقاله)</option><option value="page">برگه</option></select></td>
					</tr>
					<?php self::common_import_fields( 'posts' ); ?>
				</table>
				<?php submit_button( 'شروع', 'primary', 'submit', false ); ?>
			</form>
			<?php self::result_box(); ?>
		</div>
		<?php
	}

	private static function tab_linking() {
		self::env_notice();
		?>
		<div class="ovcb-card">
			<h2>به‌روزرسانی محتوا و لینک‌سازی داخلی</h2>
			<p>دو نوع فایل پذیرفته می‌شود (یا ترکیب هر دو در یک فایل):</p>
			<ol>
				<li><b>فایل خروجی ویرایش‌شده</b> (کلیدهای <code>posts</code>، <code>products</code>، <code>pages</code> یا <code>updates</code>): موارد فقط با <b>شناسه (id)</b> یا <b>نامک (slug)</b> پیدا می‌شوند و فقط فیلدهای انتخاب‌شده به‌روز می‌شوند. مواردی که تغییری نکرده‌اند دست نمی‌خورند.</li>
				<li><b>قوانین لینک</b> (کلید <code>link_rules</code>): «عبارت ← مقصد». افزونه در متن نوشته‌ها/محصولات، اولین رخداد عبارت را (خارج از تیترها، لینک‌های موجود، کدها و شورت‌کدها) به مقصد لینک می‌کند.</li>
			</ol>
			<p><?php self::sample_link( 'linking-sample.json', 'نمونه فایل لینک‌سازی' ); ?></p>
			<form class="ovcb-job-form" data-kind="linking" enctype="multipart/form-data">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">فایل</th>
						<td><input type="file" name="file" accept=".json" required></td>
					</tr>
					<tr>
						<th scope="row">فیلدهای قابل به‌روزرسانی<br><small>(برای فایل ویرایش‌شده)</small></th>
						<td class="ovcb-checks">
							<label><input type="checkbox" name="fields[]" value="content" checked> محتوا / توضیحات کامل</label>
							<label><input type="checkbox" name="fields[]" value="excerpt"> خلاصه / توضیح کوتاه محصول</label>
							<label><input type="checkbox" name="fields[]" value="seo"> عنوان و توضیحات متا و کلمه کلیدی</label>
							<label><input type="checkbox" name="fields[]" value="title"> عنوان</label>
						</td>
					</tr>
					<tr>
						<th scope="row">دامنه قوانین لینک</th>
						<td class="ovcb-checks">
							<span class="ovcb-inline">
								<label><input type="checkbox" name="scope_types[]" value="post" checked> نوشته‌ها</label>
								<label><input type="checkbox" name="scope_types[]" value="product" <?php checked( function_exists( 'wc_get_product' ) ); ?>> محصولات</label>
								<label><input type="checkbox" name="scope_types[]" value="page"> برگه‌ها</label>
							</span>
							<span class="ovcb-inline">
								<label><input type="checkbox" name="scope_status[]" value="publish" checked> منتشرشده</label>
								<label><input type="checkbox" name="scope_status[]" value="draft" checked> پیش‌نویس</label>
								<label><input type="checkbox" name="scope_status[]" value="future"> زمان‌بندی‌شده</label>
							</span>
						</td>
					</tr>
					<tr>
						<th scope="row">محدودیت‌ها</th>
						<td class="ovcb-checks">
							<label>حداکثر لینک جدید در هر نوشته/محصول: <input type="number" name="max_links" value="5" min="1" max="50" class="small-text"></label>
							<label><input type="checkbox" name="skip_linked" value="1" checked> اگر به مقصد از قبل لینک داده شده، دوباره لینک نده</label>
							<label><input type="checkbox" name="short_desc" value="1"> در توضیح کوتاه محصولات هم لینک بده</label>
							<label><input type="checkbox" name="elementor" value="1" checked> در ویجت‌های «ویرایشگر متن» المنتور هم لینک بده</label>
						</td>
					</tr>
					<?php self::dry_run_row(); ?>
				</table>
				<?php submit_button( 'شروع', 'primary', 'submit', false ); ?>
			</form>
			<?php self::result_box(); ?>
		</div>
		<?php
	}

	private static function tab_history() {
		$log    = array_reverse( OVCB_Jobs::log(), true );
		$kinds  = array(
			'posts'    => 'ایمپورت نوشته',
			'products' => 'ایمپورت محصول',
			'linking'  => 'به‌روزرسانی/لینک‌سازی',
		);
		$labels = array(
			'created'   => 'ساخته شد',
			'updated'   => 'به‌روز شد',
			'skipped'   => 'رد شد',
			'unchanged' => 'بدون تغییر',
			'error'     => 'خطا',
		);
		?>
		<div class="ovcb-card">
			<h2>تاریخچه عملیات و بازگردانی</h2>
			<p>قبل از هر تغییر، نسخه قبلی هر مورد ذخیره می‌شود. با «بازگردانی»، موارد به‌روزشده به حالت قبل برمی‌گردند و موارد تازه ساخته‌شده به زباله‌دان منتقل می‌شوند. <b>اگر چند عملیات روی یک مورد انجام شده، از جدیدترین شروع کنید.</b></p>
			<?php if ( ! $log ) : ?>
				<p><em>هنوز عملیاتی انجام نشده است.</em></p>
			<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>زمان</th><th>نوع</th><th>فایل</th><th>نتیجه</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $log as $id => $row ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'Y/m/d H:i', (int) $row['time'] ) ); ?></td>
						<td><?php echo esc_html( isset( $kinds[ $row['kind'] ] ) ? $kinds[ $row['kind'] ] : $row['kind'] ); ?></td>
						<td><code><?php echo esc_html( $row['file'] ); ?></code></td>
						<td>
							<?php
							$parts = array();
							foreach ( (array) $row['counts'] as $k => $v ) {
								$parts[] = ( isset( $labels[ $k ] ) ? $labels[ $k ] : $k ) . ': ' . (int) $v;
							}
							echo esc_html( implode( ' ، ', $parts ) );
							if ( empty( $row['done'] ) ) {
								echo ' <span class="ovcb-badge warn">نیمه‌کاره</span>';
							}
							?>
						</td>
						<td>
							<?php if ( ! empty( $row['undone'] ) ) : ?>
								<span class="ovcb-badge">بازگردانی شد</span>
							<?php elseif ( ! empty( $row['ids'] ) ) : ?>
								<button type="button" class="button ovcb-undo" data-job="<?php echo esc_attr( $id ); ?>">بازگردانی (<?php echo count( $row['ids'] ); ?> مورد)</button>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function tab_help() {
		?>
		<div class="ovcb-card ovcb-help">
			<h2>روند کار پیشنهادی</h2>
			<ol>
				<li>از تب <b>خروجی</b> یک فایل JSON از نوشته‌ها و محصولات بگیرید و برای تحلیل کلمات کلیدی و نوشتن مقاله ارسال کنید.</li>
				<li>فایل مقاله‌های جدید را در تب <b>ایمپورت نوشته‌ها</b> ابتدا با «اجرای آزمایشی» و بعد به‌صورت واقعی ایمپورت کنید. همه مقاله‌ها پیش‌نویس می‌شوند.</li>
				<li>مقاله‌ها را در «نوشته‌ها ← پیش‌نویس‌ها» مرور کنید و دکمه انتشار را بزنید.</li>
				<li>فایل لینک‌سازی (قوانین لینک یا محتوای ویرایش‌شده) را در تب <b>لینک‌سازی داخلی</b> اجرا کنید تا مقاله‌های قدیمی و محصولات به مقاله‌های جدید لینک شوند.</li>
				<li>اگر نتیجه مطلوب نبود، از تب <b>تاریخچه</b> عملیات را بازگردانی کنید.</li>
			</ol>

			<h2>فیلدهای فایل نوشته‌ها</h2>
			<table class="widefat striped"><tbody>
				<tr><td><code>title</code></td><td>عنوان (الزامی برای مورد جدید)</td></tr>
				<tr><td><code>slug</code></td><td>نامک (فارسی یا انگلیسی)</td></tr>
				<tr><td><code>content</code></td><td>محتوای HTML (بلوک‌های گوتنبرگ هم پشتیبانی می‌شود)</td></tr>
				<tr><td><code>excerpt</code></td><td>خلاصه</td></tr>
				<tr><td><code>categories</code> / <code>tags</code></td><td>آرایه یا «الف|ب». برای زیردسته: <code>"والد > فرزند"</code></td></tr>
				<tr><td><code>featured_image</code></td><td>آدرس تصویر یا شناسه پیوست</td></tr>
				<tr><td><code>seo</code></td><td><code>{"title":"…","description":"…","focus_keyword":"…"}</code></td></tr>
				<tr><td><code>status</code> / <code>date</code> / <code>author</code></td><td>اختیاری (با گزینه «پیش‌نویس» وضعیت نادیده گرفته می‌شود)</td></tr>
			</tbody></table>

			<h2>فیلدهای فایل محصولات</h2>
			<table class="widefat striped"><tbody>
				<tr><td><code>name</code>, <code>slug</code>, <code>sku</code></td><td>نام، نامک، کد انبار</td></tr>
				<tr><td><code>regular_price</code>, <code>sale_price</code></td><td>قیمت (ارقام فارسی و جداکننده هزارگان مشکلی ندارد)</td></tr>
				<tr><td><code>description</code>, <code>short_description</code></td><td>توضیحات کامل و کوتاه (HTML)</td></tr>
				<tr><td><code>categories</code>, <code>tags</code></td><td>مانند نوشته‌ها</td></tr>
				<tr><td><code>stock_quantity</code>, <code>stock_status</code>, <code>manage_stock</code></td><td>موجودی (<code>instock</code> / <code>outofstock</code> / <code>onbackorder</code>)</td></tr>
				<tr><td><code>featured_image</code>, <code>gallery</code></td><td>تصویر شاخص و گالری (آدرس‌ها)</td></tr>
				<tr><td><code>attributes</code></td><td><code>[{"name":"رنگ","options":["مشکی","سفید"]}]</code> — در CSV ستون <code>attribute:رنگ</code></td></tr>
				<tr><td><code>seo</code></td><td>مانند نوشته‌ها</td></tr>
			</tbody></table>

			<h2>قوانین لینک (<code>link_rules</code>)</h2>
			<table class="widefat striped"><tbody>
				<tr><td><code>anchor</code> یا <code>anchors</code></td><td>عبارت (یا چند عبارت هم‌معنی) که باید لینک شود. فاصله و نیم‌فاصله و «ی/ي» و «ک/ك» یکسان در نظر گرفته می‌شوند.</td></tr>
				<tr><td><code>url</code> یا <code>target</code></td><td>آدرس مقصد، یا <code>{"id":123}</code> / <code>{"slug":"…","type":"product"}</code></td></tr>
				<tr><td><code>max_per_post</code></td><td>حداکثر تعداد لینک این قانون در هر نوشته (پیش‌فرض ۱)</td></tr>
				<tr><td><code>max_total</code></td><td>حداکثر تعداد نوشته‌هایی که این قانون در آن‌ها اعمال می‌شود (۰ = نامحدود)</td></tr>
				<tr><td><code>apply_to</code> / <code>exclude</code></td><td>فقط در این نوشته‌ها / به‌جز این نوشته‌ها (شناسه یا نامک)</td></tr>
				<tr><td><code>title</code>, <code>new_tab</code>, <code>nofollow</code></td><td>اختیاری</td></tr>
			</tbody></table>

			<h2>سازگاری</h2>
			<ul>
				<li>افزونه فقط در پیشخوان بارگذاری می‌شود و هیچ کد، استایل یا اسکریپتی در سایت (فرانت‌اند) اجرا نمی‌کند؛ بنابراین با قالب وودمارت و سایر افزونه‌ها تداخلی ندارد.</li>
				<li>فایل‌های CSS/JS فقط در صفحه همین افزونه بارگذاری می‌شوند و به هیچ کتابخانه خارجی (حتی jQuery) وابسته نیستند.</li>
				<li>تمام نام‌ها با پیشوند <code>ovcb</code> هستند. محصولات با API رسمی ووکامرس ذخیره می‌شوند و با HPOS سازگار است.</li>
				<li>برای نوشته‌های ساخته‌شده با المنتور، جایگزینی کامل محتوا انجام نمی‌شود (چون المنتور محتوا را جای دیگری نگه می‌دارد)؛ اما قوانین لینک داخل ویجت «ویرایشگر متن» اعمال می‌شوند.</li>
			</ul>
		</div>
		<?php
	}
}
