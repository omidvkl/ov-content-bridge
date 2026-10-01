<div align="center">
  <h1>OV Content Bridge</h1>
  <p><strong>A powerful WordPress plugin for content export, import, update, and smart internal linking.</strong></p>
  <p>
    <a href="#english-version">🇬🇧 English</a> •
    <a href="#persian-version">🇮🇷 فارسی</a>
  </p>
</div>

---

<a id="english-version"></a>
## 🇬🇧 English Version

**OV Content Bridge** is a versatile tool designed to streamline your content management workflow in WordPress. It allows you to export posts and WooCommerce products to JSON, import them as drafts, update existing content dynamically (by ID or Slug), and build smart, rule-based internal links across your site.

### 🌟 Key Features

*   **Advanced Export:** Export Posts, Pages, Products, and Taxonomies to JSON/CSV formats. Filter by status and select content format (HTML/Plain Text).
*   **Draft Imports:** Safely import new articles or products as drafts. Automatically assigns categories, tags, featured images, and SEO metadata (Yoast, RankMath, SEOPress, AIOSEO).
*   **WooCommerce Support:** Fully supports WooCommerce HPOS. Imports/updates Simple and External products, including prices, stock status, galleries, and attributes.
*   **Smart Internal Linking:** 
    *   Define link rules (`Anchor Text` ➔ `Target URL/ID/Slug`).
    *   Set maximum link limits per post and globally.
    *   Automatically skips already linked targets.
    *   Works inside Gutenberg, Classic Editor, and Elementor Text Widgets.
*   **Dry Run (Preview):** Simulate any import or update job before making actual changes to your database.
*   **History & Undo:** Every modification creates a snapshot. You can easily rollback/undo an entire job with a single click.

### ⚙️ Installation

1. Upload the `ov-content-bridge` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the **OV Content Bridge** menu in the WordPress dashboard.

### 🚀 Recommended Workflow

1. **Export:** Export existing content to JSON for keyword analysis or external writing.
2. **Import:** Import newly written articles using the **Dry Run** feature first, then run it for real (they will be saved as Drafts).
3. **Publish:** Review drafts and publish them.
4. **Internal Linking:** Run your JSON rules file to automatically link older posts/products to your newly published articles.
5. **Rollback (If needed):** Use the History tab to undo any job if the results aren't as expected.

---

<a id="persian-version"></a>
## 🇮🇷 فارسی (Persian)

**افزونه OV Content Bridge** یک ابزار جامع برای مدیریت، انتقال و سئوی محتوای وردپرس است. این افزونه به شما اجازه می‌دهد از محتوا خروجی بگیرید، مقالات و محصولات جدید را ایمپورت کنید، محتوای قبلی را به‌روزرسانی کرده و یک شبکه قدرتمند از لینک‌سازی داخلی بر اساس قوانین مشخص ایجاد کنید.

### 🌟 امکانات کلیدی

*   **خروجی‌گیر پیشرفته (Export):** دریافت خروجی JSON از نوشته‌ها، برگه‌ها، محصولات و دسته‌بندی‌ها با امکان فیلتر بر اساس وضعیت انتشار.
*   **ایمپورت ایمن (Import):** وارد کردن مقالات و محصولات جدید به‌صورت پیش‌نویس (Draft). پشتیبانی از دسته‌بندی‌ها، برچسب‌ها، تصاویر شاخص و متای سئو (سازگار با Yoast, RankMath, SEOPress).
*   **پشتیبانی کامل از ووکامرس:** سازگار با معماری جدید ووکامرس (HPOS). امکان ایمپورت/به‌روزرسانی محصولات ساده و خارجی (قیمت، موجودی انبار، گالری تصاویر و ویژگی‌ها).
*   **لینک‌سازی داخلی هوشمند:**
    *   تعریف قوانین لینک‌سازی (`کلمه کلیدی` ➔ `آدرس/شناسه مقصد`).
    *   محدودسازی تعداد لینک‌ها در هر مقاله و در کل سایت.
    *   جلوگیری هوشمند از لینک دادن تکراری به یک مقصد.
    *   پشتیبانی از ویرایشگر کلاسیک، گوتنبرگ و ویجت متنی المنتور.
*   **اجرای آزمایشی (Dry Run):** پیش‌نمایش دقیق اتفاقاتی که قرار است بیفتد، بدون ذخیره تغییرات در دیتابیس.
*   **تاریخچه و بازگردانی (Undo):** قبل از هر تغییر، یک بکاپ خودکار از پست گرفته می‌شود تا در صورت نارضایتی، کل عملیات با یک کلیک بازگردانی شود.

### ⚙️ نصب افزونه

۱. پوشه `ov-content-bridge` را در مسیر `/wp-content/plugins/` هاست خود آپلود کنید.
۲. افزونه را از بخش «افزونه‌ها» در پیشخوان وردپرس فعال کنید.
۳. از منوی سمت راست پیشخوان، روی **انتقال محتوا (OV Content Bridge)** کلیک کنید.

### 🚀 روند کار پیشنهادی (Workflow)

۱. **خروجی:** از تب خروجی، یک فایل JSON از سایت بگیرید تا کلمات کلیدی را تحلیل کنید.
۲. **ایمپورت مقالات:** مقالات جدید را در تب مربوطه ایمپورت کنید (ابتدا تیک اجرای آزمایشی را بزنید). مقالات به‌صورت پیش‌نویس ذخیره می‌شوند.
۳. **انتشار:** مقالات را بازبینی کرده و منتشر کنید.
۴. **لینک‌سازی داخلی:** فایل قوانین لینک (Rules) را در تب لینک‌سازی آپلود کنید تا مقالات قدیمی به مقالات جدید به صورت خودکار لینک شوند.
۵. **بازگردانی:** در صورت بروز هرگونه مشکل، از تب «تاریخچه و بازگردانی» عملیات را لغو (Undo) کنید.