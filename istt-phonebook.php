<?php
/**
 * Snippet Name: ISTT Phonebook
 * Version: 1.0.0
 * Updated: 2026-09-12
 * Shortcode: [istt_phonebook]
 *
 * IMPORTANT: When pasting into the Code Snippets plugin, omit this opening <?php line.
 */

if (!defined('ABSPATH')) {
    exit;
}

function istt_pb_fields()
{
    return [
        'zone'           => 'istt_contact_zone',
        'email'          => 'istt_contact_email',
        'external_phone' => 'istt_contact_external_phone',
        'internal_phone' => 'istt_contact_internal_phone',
    ];
}

function istt_pb_enqueue_assets()
{
    wp_enqueue_style('dashicons');
    wp_enqueue_script(
        'istt-phonebook-qrcode',
        'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
        [],
        '1.0.0',
        true
    );
}

function istt_pb_normalize_digits($value)
{
    return strtr((string) $value, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function istt_pb_phone_href($value)
{
    return preg_replace('/[^0-9+]/', '', istt_pb_normalize_digits($value));
}

function istt_pb_get_meta_value($post_id, $field_name)
{
    return function_exists('get_field')
        ? get_field($field_name, $post_id)
        : get_post_meta($post_id, $field_name, true);
}

function istt_pb_format_acf_value($value)
{
    if (empty($value)) {
        return '';
    }

    if (is_array($value) && isset($value['label'])) {
        return sanitize_text_field($value['label']);
    }

    if (is_array($value)) {
        $items = [];
        foreach ($value as $item) {
            if (is_array($item) && isset($item['label'])) {
                $items[] = sanitize_text_field($item['label']);
            } elseif (is_scalar($item)) {
                $items[] = sanitize_text_field($item);
            }
        }
        return implode('، ', array_filter($items));
    }

    return sanitize_text_field($value);
}

function istt_pb_protected_email($email)
{
    $email = sanitize_email($email);
    if (!$email) {
        return '—';
    }

    return sprintf(
        '<span class="istt-pb-protected-email">%s</span>',
        esc_html(strrev($email))
    );
}

function istt_pb_contact_photo($post_id, $person_name, $size = 'thumbnail')
{
    if (has_post_thumbnail($post_id)) {
        return get_the_post_thumbnail($post_id, $size, [
            'loading'  => 'lazy',
            'decoding' => 'async',
            'alt'      => esc_attr($person_name),
        ]);
    }

    return '<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>';
}

function istt_pb_get_contacts_term()
{
    $term = get_term_by('slug', 'contacts', 'category');
    return (!$term || is_wp_error($term)) ? false : $term;
}

function istt_pb_get_zones($term_id)
{
    global $wpdb;

    $term = get_term(absint($term_id), 'category');
    if (!$term || is_wp_error($term)) {
        return [];
    }

    $field_name = istt_pb_fields()['zone'];
    $cache_key  = 'zones_' . md5($term->term_taxonomy_id);
    $cached     = wp_cache_get($cache_key, 'istt_phonebook');

    if ($cached !== false) {
        return $cached;
    }

    $raw_values = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm.meta_value
         FROM {$wpdb->term_relationships} tr
         INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
         INNER JOIN {$wpdb->postmeta} pm
            ON pm.post_id = p.ID AND pm.meta_key = %s
         WHERE tr.term_taxonomy_id = %d
           AND p.post_type = 'post'
           AND p.post_status = 'publish'
           AND pm.meta_value <> ''
         ORDER BY pm.meta_value ASC",
        $field_name,
        absint($term->term_taxonomy_id)
    ));

    $field_object = null;
    if ($raw_values && function_exists('get_field_object')) {
        $sample_id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE tr.term_taxonomy_id = %d
               AND p.post_type = 'post'
               AND p.post_status = 'publish'
             LIMIT 1",
            $field_name,
            absint($term->term_taxonomy_id)
        ));

        if ($sample_id) {
            $field_object = get_field_object($field_name, $sample_id, false, false);
        }
    }

    $zones = [];
    foreach ($raw_values as $raw_value) {
        $decoded = maybe_unserialize($raw_value);
        foreach ((is_array($decoded) ? $decoded : [$decoded]) as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $value = sanitize_text_field($value);
            if ($value === '') {
                continue;
            }
            $zones[$value] = (
                is_array($field_object) &&
                !empty($field_object['choices']) &&
                isset($field_object['choices'][$value])
            ) ? $field_object['choices'][$value] : $value;
        }
    }

    asort($zones, SORT_NATURAL | SORT_FLAG_CASE);
    wp_cache_set($cache_key, $zones, 'istt_phonebook', 300);
    return $zones;
}

function istt_pb_get_units($term_id, $zone = '')
{
    global $wpdb;

    $term = get_term(absint($term_id), 'category');
    if (!$term || is_wp_error($term)) {
        return [];
    }

    $zone      = sanitize_text_field($zone);
    $cache_key = 'units_' . md5($term->term_taxonomy_id . '|' . $zone);
    $cached    = wp_cache_get($cache_key, 'istt_phonebook');

    if ($cached !== false) {
        return $cached;
    }

    if ($zone !== '') {
        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT TRIM(p.post_excerpt) AS unit_name
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             INNER JOIN {$wpdb->postmeta} zm
                ON zm.post_id = p.ID
               AND zm.meta_key = %s
               AND zm.meta_value = %s
             WHERE tr.term_taxonomy_id = %d
               AND p.post_type = 'post'
               AND p.post_status = 'publish'
               AND TRIM(p.post_excerpt) <> ''
             ORDER BY unit_name ASC",
            istt_pb_fields()['zone'],
            $zone,
            absint($term->term_taxonomy_id)
        ));
    } else {
        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT TRIM(p.post_excerpt) AS unit_name
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tr.term_taxonomy_id = %d
               AND p.post_type = 'post'
               AND p.post_status = 'publish'
               AND TRIM(p.post_excerpt) <> ''
             ORDER BY unit_name ASC",
            absint($term->term_taxonomy_id)
        ));
    }

    $units = [];
    foreach ($values as $value) {
        $value = sanitize_text_field($value);
        if ($value !== '') {
            $units[$value] = $value;
        }
    }

    natcasesort($units);
    wp_cache_set($cache_key, $units, 'istt_phonebook', 300);
    return $units;
}

function istt_pb_get_contact_tags($term_id, $limit = 8)
{
    global $wpdb;

    $category = get_term(absint($term_id), 'category');
    if (!$category || is_wp_error($category)) {
        return [];
    }

    $limit     = min(20, max(1, absint($limit)));
    $cache_key = 'tags_' . md5($category->term_taxonomy_id . '|' . $limit);
    $cached    = wp_cache_get($cache_key, 'istt_phonebook');

    if ($cached !== false) {
        return $cached;
    }

    $tags = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT t.term_id, t.name, t.slug
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->term_relationships} cr
            ON cr.object_id = p.ID AND cr.term_taxonomy_id = %d
         INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
         INNER JOIN {$wpdb->term_taxonomy} tt
            ON tt.term_taxonomy_id = tr.term_taxonomy_id
           AND tt.taxonomy = 'post_tag'
         INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
         WHERE p.post_type = 'post' AND p.post_status = 'publish'
         ORDER BY t.name ASC
         LIMIT %d",
        absint($category->term_taxonomy_id),
        $limit
    ));

    wp_cache_set($cache_key, $tags, 'istt_phonebook', 300);
    return $tags;
}

function istt_pb_posts_where($where, $query)
{
    global $wpdb;

    if (!$query->get('istt_pb_query')) {
        return $where;
    }

    $search = trim((string) $query->get('istt_pb_search'));
    $unit   = trim((string) $query->get('istt_pb_unit'));

    if ($search !== '') {
        $like      = '%' . $wpdb->esc_like(istt_pb_normalize_digits($search)) . '%';
        $meta_keys = array_values(istt_pb_fields());
        $holders   = implode(', ', array_fill(0, count($meta_keys), '%s'));
        $sql       = " AND (
            {$wpdb->posts}.post_title LIKE %s
            OR {$wpdb->posts}.post_excerpt LIKE %s
            OR EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} sm
                WHERE sm.post_id = {$wpdb->posts}.ID
                  AND sm.meta_key IN ({$holders})
                  AND sm.meta_value LIKE %s
            )
        )";
        $where .= $wpdb->prepare($sql, array_merge([$like, $like], $meta_keys, [$like]));
    }

    if ($unit !== '') {
        $where .= $wpdb->prepare(
            " AND TRIM({$wpdb->posts}.post_excerpt) = %s",
            $unit
        );
    }

    return $where;
}

function istt_pb_query($args = [])
{
    $args = wp_parse_args($args, [
        'term_id'        => 0,
        'search'         => '',
        'zone'           => '',
        'unit'           => '',
        'tag_id'         => 0,
        'paged'          => 1,
        'posts_per_page' => 20,
    ]);

    $tax_query = [
        'relation' => 'AND',
        [
            'taxonomy' => 'category',
            'field'    => 'term_id',
            'terms'    => [absint($args['term_id'])],
        ],
    ];

    if ($args['tag_id']) {
        $tax_query[] = [
            'taxonomy' => 'post_tag',
            'field'    => 'term_id',
            'terms'    => [absint($args['tag_id'])],
        ];
    }

    $query_args = [
        'post_type'              => 'post',
        'post_status'            => 'publish',
        'posts_per_page'         => min(50, max(1, absint($args['posts_per_page']))),
        'paged'                  => max(1, absint($args['paged'])),
        'orderby'                => 'title',
        'order'                  => 'ASC',
        'ignore_sticky_posts'    => true,
        'update_post_meta_cache' => true,
        'update_post_term_cache' => false,
        'tax_query'              => $tax_query,
        'istt_pb_query'          => true,
        'istt_pb_search'         => sanitize_text_field($args['search']),
        'istt_pb_unit'           => sanitize_text_field($args['unit']),
    ];

    if ($args['zone'] !== '') {
        $query_args['meta_query'] = [[
            'key'     => istt_pb_fields()['zone'],
            'value'   => sanitize_text_field($args['zone']),
            'compare' => '=',
        ]];
    }

    add_filter('posts_where', 'istt_pb_posts_where', 10, 2);
    $query = new WP_Query($query_args);
    remove_filter('posts_where', 'istt_pb_posts_where', 10);
    return $query;
}

function istt_pb_pagination($query, $page)
{
    $total = absint($query->max_num_pages);
    $page  = max(1, absint($page));
    if ($total <= 1) {
        return '';
    }

    ob_start(); ?>
    <nav class="istt-pb-pagination" aria-label="صفحه‌بندی دفتر تلفن">
        <button type="button" class="istt-pb-page-button" data-page="<?php echo esc_attr(max(1, $page - 1)); ?>" <?php disabled($page <= 1); ?>>
            <span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span> قبلی
        </button>
        <span>صفحه <?php echo esc_html(number_format_i18n($page)); ?> از <?php echo esc_html(number_format_i18n($total)); ?></span>
        <button type="button" class="istt-pb-page-button" data-page="<?php echo esc_attr(min($total, $page + 1)); ?>" <?php disabled($page >= $total); ?>>
            بعدی <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
        </button>
    </nav>
    <?php return ob_get_clean();
}

function istt_pb_render_results($args = [])
{
    $args = wp_parse_args($args, [
        'term_id' => 0, 'search' => '', 'zone' => '', 'unit' => '',
        'tag_id' => 0, 'paged' => 1, 'posts_per_page' => 20,
    ]);

    $fields = istt_pb_fields();
    $zones  = istt_pb_get_zones($args['term_id']);
    $query  = istt_pb_query($args);
    $title  = ($args['zone'] !== '' && isset($zones[$args['zone']]))
        ? $zones[$args['zone']]
        : 'همه مخاطبان';

    ob_start();
    if (!$query->have_posts()) { ?>
        <div class="istt-pb-empty">
            <span class="dashicons dashicons-search" aria-hidden="true"></span>
            <p>نتیجه‌ای مطابق جست‌وجوی شما پیدا نشد.</p>
        </div>
        <?php return ob_get_clean();
    } ?>

    <div class="istt-pb-results-head">
        <div>
            <span><?php echo esc_html(number_format_i18n($query->found_posts)); ?> نفر در سازمان یافت شد</span>
            <h2><?php echo esc_html($title); ?></h2>
        </div>
        <?php if ($args['unit'] !== '') : ?>
            <span class="istt-pb-current-unit"><?php echo esc_html($args['unit']); ?></span>
        <?php endif; ?>
    </div>

    <div class="istt-pb-contacts-list">
        <?php $index = 0; while ($query->have_posts()) : $query->the_post();
            $index++;
            $post_id = get_the_ID();
            $name    = get_the_title();
            $unit    = trim(wp_strip_all_tags(get_post_field('post_excerpt', $post_id)));
            $raw_zone = get_post_meta($post_id, $fields['zone'], true);
            $zone = isset($zones[$raw_zone]) ? $zones[$raw_zone] : istt_pb_format_acf_value(istt_pb_get_meta_value($post_id, $fields['zone']));
            $email    = istt_pb_get_meta_value($post_id, $fields['email']);
            $external = istt_pb_get_meta_value($post_id, $fields['external_phone']);
            $internal = istt_pb_get_meta_value($post_id, $fields['internal_phone']);
        ?>
            <article class="istt-pb-contact-row" style="--pb-row-index:<?php echo esc_attr($index); ?>">
                <div class="istt-pb-contact-photo"><?php echo istt_pb_contact_photo($post_id, $name); ?></div>
                <div class="istt-pb-contact-identity">
                    <h3><?php echo esc_html($name); ?></h3>
                    <p><?php echo $unit !== '' ? esc_html($unit) : 'واحد سازمانی ثبت نشده'; ?></p>
                </div>
                <div class="istt-pb-contact-zone"><?php echo $zone !== '' ? esc_html($zone) : '—'; ?></div>
                <div class="istt-pb-contact-phone">
                    <span class="dashicons dashicons-phone" aria-hidden="true"></span>
                    <span><?php echo $internal ? esc_html($internal) : '—'; ?></span>
                </div>
                <div class="istt-pb-contact-email-status">
                    <?php if ($email) : ?><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><?php else : ?>—<?php endif; ?>
                </div>
                <button type="button" class="istt-pb-details-button" data-contact-id="<?php echo esc_attr($post_id); ?>">
                    اطلاعات بیشتر <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                </button>

                <template id="istt-pb-contact-<?php echo esc_attr($post_id); ?>">
                    <div class="istt-pb-sidebar-profile">
                        <div class="istt-pb-sidebar-photo"><?php echo istt_pb_contact_photo($post_id, $name, 'medium'); ?></div>
                        <h2><?php echo esc_html($name); ?></h2>
                        <p class="istt-pb-sidebar-position"><?php echo $unit !== '' ? esc_html($unit) : '—'; ?></p>
                        <div class="istt-pb-sidebar-divider"></div>
                        <dl class="istt-pb-sidebar-data">
                            <div><dt><span class="dashicons dashicons-building"></span> حوزه</dt><dd><?php echo $zone !== '' ? esc_html($zone) : '—'; ?></dd></div>
                            <div><dt><span class="dashicons dashicons-networking"></span> واحد سازمانی</dt><dd><?php echo $unit !== '' ? esc_html($unit) : '—'; ?></dd></div>
                            <div><dt><span class="dashicons dashicons-phone"></span> شماره داخلی</dt><dd><?php echo $internal ? esc_html($internal) : '—'; ?></dd></div>
                            <div><dt><span class="dashicons dashicons-phone"></span> شماره مستقیم</dt><dd><?php echo $external ? esc_html($external) : '—'; ?></dd></div>
                            <div><dt><span class="dashicons dashicons-email-alt"></span> رایانامه</dt><dd><?php echo istt_pb_protected_email($email); ?></dd></div>
                        </dl>
                        <?php if ($external) : ?>
                            <a class="istt-pb-sidebar-call" href="tel:<?php echo esc_attr(istt_pb_phone_href($external)); ?>">
                                <span class="dashicons dashicons-phone"></span> تماس
                            </a>
                        <?php endif; ?>
                        <section class="istt-pb-qr-section" data-qr-contact="<?php echo esc_attr($post_id); ?>">
                            <div class="istt-pb-qr-title">
                                <span class="dashicons dashicons-smartphone"></span>
                                <div><h3>ذخیره مخاطب</h3><p>کد را با دوربین تلفن همراه اسکن کنید یا فایل مخاطب را دریافت کنید.</p></div>
                            </div>
                            <div class="istt-pb-qr-box">
                                <span class="dashicons dashicons-update istt-pb-qr-loader"></span>
                                <span>در حال ساخت QR Code...</span>
                            </div>
                            <a href="#" class="istt-pb-vcard-download" hidden download>
                                <span class="dashicons dashicons-download"></span> دانلود فایل مخاطب
                            </a>
                            <div class="istt-pb-qr-error" hidden>ساخت QR Code ممکن نشد.</div>
                        </section>
                    </div>
                </template>
            </article>
        <?php endwhile; ?>
    </div>
    <?php echo istt_pb_pagination($query, $args['paged']);
    wp_reset_postdata();
    return ob_get_clean();
}

function istt_phonebook_shortcode($atts)
{
    istt_pb_enqueue_assets();
    $atts = shortcode_atts(['posts_per_page' => 20, 'tags_limit' => 8], $atts, 'istt_phonebook');
    $term = istt_pb_get_contacts_term();
    if (!$term) {
        return '<div class="istt-pb-error">دسته‌بندی با نامک contacts پیدا نشد.</div>';
    }

    $term_id = absint($term->term_id);
    $per_page = min(50, max(1, absint($atts['posts_per_page'])));
    $tags_limit = min(20, max(1, absint($atts['tags_limit'])));
    $zones = istt_pb_get_zones($term_id);
    $units = istt_pb_get_units($term_id);
    $tags  = istt_pb_get_contact_tags($term_id, $tags_limit);
    $id    = wp_unique_id('istt-phonebook-');
    $nonce = wp_create_nonce('istt_phonebook_filter');

    ob_start(); ?>
    <section id="<?php echo esc_attr($id); ?>" class="istt-pb" dir="rtl" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
        <div class="istt-pb-hero">
            <div class="istt-pb-eyebrow">ارتباط با شهرک</div>
            <h1>دنبال چه کسی هستید؟</h1>
            <p>نام، سمت، واحد سازمانی یا شماره داخلی موردنظر خود را جست‌وجو کنید.</p>

            <form class="istt-pb-form">
                <input type="hidden" name="action" value="istt_phonebook_filter">
                <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="term_id" value="<?php echo esc_attr($term_id); ?>">
                <input type="hidden" name="tag_id" value="0">
                <input type="hidden" name="paged" value="1">
                <input type="hidden" name="posts_per_page" value="<?php echo esc_attr($per_page); ?>">

                <div class="istt-pb-search-box">
                    <input type="search" name="search" placeholder="نام، سمت، واحد یا داخلی را جست‌وجو کنید..." autocomplete="off">
                    <button type="submit" aria-label="جست‌وجو"><span class="dashicons dashicons-search"></span></button>
                </div>

                <?php if ($tags) : ?>
                    <div class="istt-pb-start-section">
                        <h2>از کجا شروع کنم؟</h2>
                        <p>موضوع موردنظر خود را انتخاب کنید تا گزینه‌های مرتبط نمایش داده شوند.</p>
                        <div class="istt-pb-tag-links">
                            <?php $tag_index = 0; foreach ($tags as $tag) : $tag_index++;
                                $url = get_term_link(absint($tag->term_id), 'post_tag');
                                if (is_wp_error($url)) { $url = '#'; }
                            ?>
                                <a href="<?php echo esc_url($url); ?>" class="istt-pb-tag-link" data-tag-id="<?php echo esc_attr($tag->term_id); ?>" style="--pb-tag-index:<?php echo esc_attr($tag_index); ?>">
                                    <?php echo esc_html($tag->name); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="istt-pb-filter-area">
                    <div class="istt-pb-zone-filters">
                        <button type="button" class="istt-pb-zone active" data-zone="">همه</button>
                        <?php foreach ($zones as $value => $label) : ?>
                            <button type="button" class="istt-pb-zone" data-zone="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="zone" value="">
                    <div class="istt-pb-unit-filter">
                        <label for="<?php echo esc_attr($id . '-unit'); ?>">واحد سازمانی</label>
                        <select id="<?php echo esc_attr($id . '-unit'); ?>" name="unit">
                            <option value="">همه واحدهای سازمانی</option>
                            <?php foreach ($units as $value => $label) : ?>
                                <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="button" class="istt-pb-reset"><span class="dashicons dashicons-image-rotate"></span> پاک‌کردن فیلترها</button>
                </div>
            </form>
        </div>

        <div class="istt-pb-loading" hidden><span class="dashicons dashicons-update"></span> در حال دریافت اطلاعات...</div>
        <div class="istt-pb-results"><?php echo istt_pb_render_results(['term_id' => $term_id, 'paged' => 1, 'posts_per_page' => $per_page]); ?></div>

        <div class="istt-pb-sidebar-layer" aria-hidden="true">
            <button type="button" class="istt-pb-sidebar-backdrop" aria-label="بستن اطلاعات مخاطب"></button>
            <aside class="istt-pb-sidebar" role="dialog" aria-modal="true" aria-label="جزئیات اطلاعات تماس">
                <div class="istt-pb-sidebar-top">
                    <span>اطلاعات تماس</span>
                    <button type="button" class="istt-pb-sidebar-close" aria-label="بستن"><span class="dashicons dashicons-no-alt"></span></button>
                </div>
                <div class="istt-pb-sidebar-content"></div>
            </aside>
        </div>
    </section>

    <style>
        #<?php echo esc_attr($id); ?>{--p:#07594f;--ph:#064a43;--a:#78bdb0;--al:#e9f5f2;--t:#102636;--x:#34495a;--m:#7a8996;--b:#e4e9ed;--l:#f5f7f8;--w:#fff;width:100%;color:var(--x);font-family:inherit}
        #<?php echo esc_attr($id); ?> *{box-sizing:border-box}
        #<?php echo esc_attr($id); ?> button,#<?php echo esc_attr($id); ?> input,#<?php echo esc_attr($id); ?> select{font-family:inherit}
        #<?php echo esc_attr($id); ?> .dashicons{width:auto;height:auto;font-size:inherit;line-height:1}
        #<?php echo esc_attr($id); ?> .istt-pb-hero{padding:58px 20px 34px;text-align:center;animation:pbUp .65s ease both}
        #<?php echo esc_attr($id); ?> .istt-pb-eyebrow{margin-bottom:14px;color:var(--p);font-size:14px}
        #<?php echo esc_attr($id); ?> .istt-pb-hero>h1{margin:0;color:var(--t);font-size:clamp(32px,4.3vw,58px);font-weight:800;line-height:1.35;letter-spacing:-1.5px}
        #<?php echo esc_attr($id); ?> .istt-pb-hero>p{margin:12px 0 25px;color:var(--m);font-size:15px}
        #<?php echo esc_attr($id); ?> .istt-pb-form{width:min(1080px,100%);margin:auto}
        #<?php echo esc_attr($id); ?> .istt-pb-search-box{position:relative;width:min(700px,100%);margin:auto;animation:pbUp .65s .08s ease both}
        #<?php echo esc_attr($id); ?> .istt-pb-search-box input{width:100%;height:62px;padding:0 24px 0 68px;border:1px solid var(--b);border-radius:32px;outline:0;background:var(--w);color:var(--t);font-size:14px;box-shadow:0 10px 35px rgba(29,49,66,.08);transition:.2s}
        #<?php echo esc_attr($id); ?> .istt-pb-search-box input:focus{border-color:var(--a);box-shadow:0 14px 40px rgba(29,49,66,.12);transform:translateY(-1px)}
        #<?php echo esc_attr($id); ?> .istt-pb-search-box button{position:absolute;top:50%;left:11px;display:flex;width:44px;height:44px;align-items:center;justify-content:center;padding:0;border:0;border-radius:50%;transform:translateY(-50%);background:transparent;color:var(--t);font-size:22px;cursor:pointer}
        #<?php echo esc_attr($id); ?> .istt-pb-start-section{margin-top:38px}
        #<?php echo esc_attr($id); ?> .istt-pb-start-section h2{margin:0 0 5px;color:var(--t);font-size:23px}
        #<?php echo esc_attr($id); ?> .istt-pb-start-section>p{margin:0 0 18px;color:var(--m);font-size:13px}
        #<?php echo esc_attr($id); ?> .istt-pb-tag-links{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
        #<?php echo esc_attr($id); ?> .istt-pb-tag-link{display:flex;min-height:78px;align-items:center;justify-content:center;padding:15px;border:1px solid var(--b);border-radius:10px;background:var(--w);color:var(--t);text-align:center;text-decoration:none;font-size:14px;font-weight:600;line-height:1.8;opacity:0;transform:translateY(12px);animation:pbTag .45s ease forwards;animation-delay:calc(var(--pb-tag-index)*55ms);transition:.22s}
        #<?php echo esc_attr($id); ?> .istt-pb-tag-link:hover{transform:translateY(-4px);border-color:var(--a);box-shadow:0 12px 25px rgba(24,61,67,.08)}
        #<?php echo esc_attr($id); ?> .istt-pb-tag-link.active{border-color:var(--a);background:var(--al);color:var(--p)}
        #<?php echo esc_attr($id); ?> .istt-pb-filter-area{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:12px;margin-top:25px}
        #<?php echo esc_attr($id); ?> .istt-pb-zone-filters{display:flex;flex-wrap:wrap;justify-content:center;gap:8px}
        #<?php echo esc_attr($id); ?> .istt-pb-zone{min-height:40px;padding:0 22px;border:1px solid var(--b);border-radius:22px;background:var(--l);color:var(--x);cursor:pointer;transition:.2s}
        #<?php echo esc_attr($id); ?> .istt-pb-zone:hover{transform:translateY(-2px)}
        #<?php echo esc_attr($id); ?> .istt-pb-zone.active{border-color:var(--p);background:var(--p);color:var(--w)}
        #<?php echo esc_attr($id); ?> .istt-pb-unit-filter{display:flex;align-items:center;gap:8px}
        #<?php echo esc_attr($id); ?> .istt-pb-unit-filter label{color:var(--m);font-size:12px;white-space:nowrap}
        #<?php echo esc_attr($id); ?> .istt-pb-unit-filter select{min-width:190px;height:42px;padding:0 13px;border:1px solid var(--b);border-radius:21px;outline:0;background:var(--w);color:var(--x)}
        #<?php echo esc_attr($id); ?> .istt-pb-reset{display:inline-flex;min-height:42px;align-items:center;gap:6px;padding:0 15px;border:0;background:transparent;color:var(--m);cursor:pointer}
        #<?php echo esc_attr($id); ?> .istt-pb-results{width:min(1120px,calc(100% - 40px));margin:0 auto 70px;transition:opacity .2s}
        #<?php echo esc_attr($id); ?> .istt-pb-results-head{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;padding:18px 0;border-bottom:1px solid var(--b);text-align:right}
        #<?php echo esc_attr($id); ?> .istt-pb-results-head span{color:var(--m);font-size:12px}
        #<?php echo esc_attr($id); ?> .istt-pb-results-head h2{margin:24px 0 0;color:var(--t);font-size:20px}
        #<?php echo esc_attr($id); ?> .istt-pb-current-unit{padding:7px 13px;border-radius:18px;background:var(--al);color:var(--p)!important}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-row{display:grid;grid-template-columns:68px minmax(210px,1.45fr) minmax(150px,1fr) minmax(100px,.65fr) 55px 145px;gap:14px;align-items:center;min-height:88px;padding:10px 14px;border-bottom:1px solid var(--b);text-align:right;opacity:0;transform:translateY(12px);animation:pbRow .42s ease forwards;animation-delay:calc(var(--pb-row-index)*35ms);transition:.22s}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-row:hover{z-index:1;transform:translateX(-4px);background:var(--al);box-shadow:0 10px 25px rgba(29,60,67,.05)}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-photo{display:flex;width:58px;height:58px;align-items:center;justify-content:center;overflow:hidden;border-radius:50%;background:var(--l);color:var(--p)}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-photo img,#<?php echo esc_attr($id); ?> .istt-pb-sidebar-photo img{width:100%;height:100%;object-fit:cover;transition:transform .3s}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-row:hover .istt-pb-contact-photo img{transform:scale(1.06)}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-identity h3{margin:0 0 4px;color:var(--t);font-size:15px;font-weight:700}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-identity p{margin:0;color:var(--m);font-size:12px;line-height:1.7}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-zone{font-size:12px;line-height:1.7}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-phone{display:flex;align-items:center;justify-content:flex-end;gap:9px;direction:ltr;font-size:13px}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-phone .dashicons,#<?php echo esc_attr($id); ?> .istt-pb-contact-email-status .dashicons{color:var(--p);font-size:19px}
        #<?php echo esc_attr($id); ?> .istt-pb-contact-email-status{text-align:center}
        #<?php echo esc_attr($id); ?> .istt-pb-details-button{display:flex;min-height:44px;align-items:center;justify-content:center;gap:7px;padding:0 12px;border:0;border-radius:8px;background:transparent;color:var(--p);cursor:pointer;white-space:nowrap;transition:.2s}
        #<?php echo esc_attr($id); ?> .istt-pb-details-button:hover{gap:11px;background:var(--w)}
        #<?php echo esc_attr($id); ?> .istt-pb-loading{width:min(1120px,calc(100% - 40px));margin:15px auto;padding:18px;color:var(--p);text-align:center}
        #<?php echo esc_attr($id); ?> .istt-pb-loading .dashicons,#<?php echo esc_attr($id); ?> .istt-pb-qr-loader{margin-left:8px;animation:pbSpin .8s linear infinite}
        #<?php echo esc_attr($id); ?>.is-loading .istt-pb-results{opacity:.35;pointer-events:none}
        #<?php echo esc_attr($id); ?> .istt-pb-empty{padding:55px 20px;border-block:1px solid var(--b);color:var(--m);text-align:center}
        #<?php echo esc_attr($id); ?> .istt-pb-empty .dashicons{font-size:30px}
        #<?php echo esc_attr($id); ?> .istt-pb-pagination{display:flex;align-items:center;justify-content:center;gap:18px;margin-top:28px}
        #<?php echo esc_attr($id); ?> .istt-pb-page-button{display:flex;min-height:44px;align-items:center;gap:8px;padding:0 18px;border:1px solid var(--b);border-radius:9px;background:var(--w);color:var(--x);cursor:pointer}
        #<?php echo esc_attr($id); ?> .istt-pb-page-button:disabled{opacity:.4;cursor:not-allowed}
        #<?php echo esc_attr($id); ?> .istt-pb-pagination>span{color:var(--m);font-size:12px}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-layer{position:fixed;inset:0;z-index:999999;visibility:hidden;pointer-events:none}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-layer.open{visibility:visible;pointer-events:auto}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-backdrop{position:absolute;inset:0;width:100%;height:100%;padding:0;border:0;border-radius:0;background:rgba(14,30,42,.3);opacity:0;transition:opacity .3s}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-layer.open .istt-pb-sidebar-backdrop{opacity:1}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar{position:absolute;top:0;right:0;width:min(430px,100%);height:100%;overflow-y:auto;background:var(--w);box-shadow:-20px 0 60px rgba(15,35,48,.15);transform:translateX(105%);transition:transform .34s cubic-bezier(.22,1,.36,1)}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-layer.open .istt-pb-sidebar{transform:translateX(0)}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-top{position:sticky;top:0;z-index:2;display:flex;min-height:70px;align-items:center;justify-content:space-between;padding:0 24px;border-bottom:1px solid var(--b);background:rgba(255,255,255,.96)}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-close{display:flex;width:44px;height:44px;align-items:center;justify-content:center;padding:0;border:1px solid var(--b);border-radius:50%;background:var(--w);font-size:24px;cursor:pointer;transition:.2s}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-close:hover{transform:rotate(90deg)}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-content{padding:34px 28px 40px}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-profile{text-align:center;animation:pbUp .4s .12s ease both}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-photo{display:flex;width:116px;height:116px;align-items:center;justify-content:center;overflow:hidden;margin:0 auto 18px;border-radius:50%;background:var(--l);color:var(--p)}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-photo .dashicons{font-size:42px}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-profile>h2{margin:0 0 8px;color:var(--t);font-size:24px}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-position{margin:0;color:var(--m);font-size:13px;line-height:1.8}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-divider{height:1px;margin:25px 0;background:var(--b)}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-data{display:grid;gap:4px;margin:0;text-align:right}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-data>div{padding:15px 0;border-bottom:1px solid var(--b)}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-data dt{display:flex;align-items:center;gap:10px;margin-bottom:7px;color:var(--m);font-size:12px}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-data dt .dashicons{width:18px;color:var(--p);font-size:18px;text-align:center}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-data dd{margin:0 28px 0 0;color:var(--t);font-size:14px;font-weight:600;overflow-wrap:anywhere}
        #<?php echo esc_attr($id); ?> .istt-pb-protected-email{display:inline-block;direction:rtl;unicode-bidi:bidi-override;font-weight:500}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-call{display:flex;min-height:52px;align-items:center;justify-content:center;gap:10px;margin-top:25px;border-radius:9px;background:var(--p);color:var(--w);text-decoration:none;font-weight:700;transition:.2s}
        #<?php echo esc_attr($id); ?> .istt-pb-sidebar-call:hover{transform:translateY(-2px);background:var(--ph);color:var(--w)}
        #<?php echo esc_attr($id); ?> .istt-pb-qr-section{margin-top:28px;padding:20px;border:1px solid var(--b);border-radius:16px;background:var(--l);text-align:right}
        #<?php echo esc_attr($id); ?> .istt-pb-qr-title{display:flex;align-items:flex-start;gap:12px}
        #<?php echo esc_attr($id); ?> .istt-pb-qr-title>.dashicons{margin-top:3px;color:var(--p);font-size:25px}
        #<?php echo esc_attr($id); ?> .istt-pb-qr-title h3{margin:0 0 5px;color:var(--t);font-size:15px}
        #<?php echo esc_attr($id); ?> .istt-pb-qr-title p{margin:0;color:var(--m);font-size:11px;line-height:1.8}
        #<?php echo esc_attr($id); ?> .istt-pb-qr-box{display:flex;width:200px;min-height:200px;align-items:center;justify-content:center;flex-direction:column;gap:9px;margin:20px auto;padding:13px;border-radius:13px;background:var(--w);color:var(--m);text-align:center}
        #<?php echo esc_attr($id); ?> .istt-pb-qr-box canvas,#<?php echo esc_attr($id); ?> .istt-pb-qr-box img{display:block;width:174px!important;height:174px!important}
        #<?php echo esc_attr($id); ?> .istt-pb-vcard-download{display:flex;min-height:46px;align-items:center;justify-content:center;gap:8px;border:1px solid var(--b);border-radius:9px;background:var(--w);color:var(--t);text-decoration:none;font-size:13px}
        #<?php echo esc_attr($id); ?> .istt-pb-vcard-download[hidden]{display:none}
        #<?php echo esc_attr($id); ?> .istt-pb-qr-error{color:#a33c3c;font-size:12px;text-align:center}
        body.istt-pb-sidebar-open{overflow:hidden}
        @keyframes pbUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
        @keyframes pbTag{to{opacity:1;transform:translateY(0)}}
        @keyframes pbRow{to{opacity:1;transform:translateY(0)}}
        @keyframes pbSpin{to{transform:rotate(360deg)}}
        @media(max-width:960px){
            #<?php echo esc_attr($id); ?> .istt-pb-tag-links{grid-template-columns:repeat(3,minmax(0,1fr))}
            #<?php echo esc_attr($id); ?> .istt-pb-contact-row{grid-template-columns:64px minmax(180px,1fr) minmax(130px,.8fr) 100px 45px}
            #<?php echo esc_attr($id); ?> .istt-pb-details-button{grid-column:1/-1}
        }
        @media(max-width:700px){
            #<?php echo esc_attr($id); ?> .istt-pb-hero{padding:38px 14px 24px}
            #<?php echo esc_attr($id); ?> .istt-pb-hero>h1{font-size:34px;letter-spacing:-.7px}
            #<?php echo esc_attr($id); ?> .istt-pb-hero>p{max-width:320px;margin:12px auto 25px;font-size:13px;line-height:1.9}
            #<?php echo esc_attr($id); ?> .istt-pb-search-box input{height:56px;padding-right:18px;font-size:13px}
            #<?php echo esc_attr($id); ?> .istt-pb-tag-links{grid-template-columns:1fr 1fr}
            #<?php echo esc_attr($id); ?> .istt-pb-tag-link{min-height:68px;padding:11px;font-size:12px}
            #<?php echo esc_attr($id); ?> .istt-pb-zone-filters{width:calc(100vw - 28px);justify-content:flex-start;flex-wrap:nowrap;overflow-x:auto;padding:2px 0 9px;scrollbar-width:none}
            #<?php echo esc_attr($id); ?> .istt-pb-zone-filters::-webkit-scrollbar{display:none}
            #<?php echo esc_attr($id); ?> .istt-pb-zone{flex:0 0 auto;min-height:44px}
            #<?php echo esc_attr($id); ?> .istt-pb-filter-area,#<?php echo esc_attr($id); ?> .istt-pb-unit-filter{width:100%}
            #<?php echo esc_attr($id); ?> .istt-pb-unit-filter{display:grid;text-align:right}
            #<?php echo esc_attr($id); ?> .istt-pb-unit-filter select{width:100%;min-width:0;height:48px;border-radius:12px}
            #<?php echo esc_attr($id); ?> .istt-pb-results{width:calc(100% - 24px);margin-bottom:45px}
            #<?php echo esc_attr($id); ?> .istt-pb-results-head{align-items:flex-start;flex-direction:column}
            #<?php echo esc_attr($id); ?> .istt-pb-results-head h2{margin-top:14px}
            #<?php echo esc_attr($id); ?> .istt-pb-contact-row{grid-template-columns:58px 1fr auto;gap:11px;min-height:0;margin-bottom:10px;padding:14px;border:1px solid var(--b);border-radius:14px;background:var(--w)}
            #<?php echo esc_attr($id); ?> .istt-pb-contact-row:hover{transform:translateY(-2px)}
            #<?php echo esc_attr($id); ?> .istt-pb-contact-photo{width:52px;height:52px}
            #<?php echo esc_attr($id); ?> .istt-pb-contact-zone{grid-column:2/-1;padding-top:7px;border-top:1px solid var(--b)}
            #<?php echo esc_attr($id); ?> .istt-pb-contact-phone{grid-column:2;justify-content:flex-end}
            #<?php echo esc_attr($id); ?> .istt-pb-contact-email-status{grid-column:3}
            #<?php echo esc_attr($id); ?> .istt-pb-details-button{grid-column:1/-1;min-height:46px;background:var(--al)}
            #<?php echo esc_attr($id); ?> .istt-pb-sidebar{top:auto;right:0;bottom:0;width:100%;height:min(90dvh,820px);border-radius:24px 24px 0 0;transform:translateY(105%);box-shadow:0 -20px 60px rgba(15,35,48,.16)}
            #<?php echo esc_attr($id); ?> .istt-pb-sidebar-layer.open .istt-pb-sidebar{transform:translateY(0)}
            #<?php echo esc_attr($id); ?> .istt-pb-sidebar-top{min-height:62px;padding:0 18px;border-radius:24px 24px 0 0}
            #<?php echo esc_attr($id); ?> .istt-pb-sidebar-content{padding:25px 20px 35px}
            #<?php echo esc_attr($id); ?> .istt-pb-sidebar-photo{width:96px;height:96px}
            #<?php echo esc_attr($id); ?> .istt-pb-pagination{gap:8px}
            #<?php echo esc_attr($id); ?> .istt-pb-page-button{min-height:44px;padding:0 11px}
        }
        @media(max-width:430px){
            #<?php echo esc_attr($id); ?> .istt-pb-tag-links{grid-template-columns:1fr}
            #<?php echo esc_attr($id); ?> .istt-pb-tag-link{min-height:58px}
            #<?php echo esc_attr($id); ?> .istt-pb-pagination>span{font-size:10px}
        }
        @media(prefers-reduced-motion:reduce){#<?php echo esc_attr($id); ?> *,#<?php echo esc_attr($id); ?> *:before,#<?php echo esc_attr($id); ?> *:after{animation-duration:.01ms!important;transition-duration:.01ms!important}}
    </style>

    <script>
    (function(){
        const w=document.getElementById(<?php echo wp_json_encode($id); ?>);if(!w)return;
        const f=w.querySelector('.istt-pb-form'),r=w.querySelector('.istt-pb-results'),loading=w.querySelector('.istt-pb-loading'),search=f.querySelector('[name="search"]'),zone=f.querySelector('[name="zone"]'),unit=f.querySelector('[name="unit"]'),tag=f.querySelector('[name="tag_id"]'),page=f.querySelector('[name="paged"]'),layer=w.querySelector('.istt-pb-sidebar-layer'),side=w.querySelector('.istt-pb-sidebar-content'),close=w.querySelector('.istt-pb-sidebar-close'),backdrop=w.querySelector('.istt-pb-sidebar-backdrop');
        let controller=null,timer=null,lastFocus=null;const cards=new Map();
        const nonce=()=>f.querySelector('[name="nonce"]').value;
        function units(list){unit.innerHTML='<option value="">همه واحدهای سازمانی</option>';Object.entries(list).forEach(([v,l])=>{const o=document.createElement('option');o.value=v;o.textContent=l;unit.appendChild(o)})}
        function load(opts={}){opts=Object.assign({delay:0,updateUnits:false,scroll:false},opts);clearTimeout(timer);timer=setTimeout(async()=>{if(controller)controller.abort();controller=new AbortController();const fd=new FormData(f);fd.set('update_units',opts.updateUnits?'1':'0');w.classList.add('is-loading');loading.hidden=false;try{const res=await fetch(w.dataset.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin',signal:controller.signal});const data=await res.json();if(!data.success)throw new Error();r.innerHTML=data.data.html;if(opts.updateUnits&&data.data.units)units(data.data.units);if(opts.scroll)window.scrollTo({top:r.getBoundingClientRect().top+scrollY-100,behavior:'smooth'})}catch(e){if(e.name!=='AbortError')r.innerHTML='<div class="istt-pb-empty"><p>خطا در دریافت اطلاعات.</p></div>'}finally{w.classList.remove('is-loading');loading.hidden=true}},opts.delay)}
        function decode64(s){const b=atob(s),a=Uint8Array.from(b,c=>c.charCodeAt(0));return window.TextDecoder?new TextDecoder('utf-8').decode(a):decodeURIComponent(Array.from(a,x=>'%'+x.toString(16).padStart(2,'0')).join(''))}
        function waitQR(cb,n=0){if(window.QRCode)return cb();if(n>40)return cb(new Error());setTimeout(()=>waitQR(cb,n+1),100)}
        function drawQR(section,data){const box=section.querySelector('.istt-pb-qr-box'),err=section.querySelector('.istt-pb-qr-error'),dl=section.querySelector('.istt-pb-vcard-download');waitQR(e=>{if(e){box.hidden=true;err.hidden=false;return}box.innerHTML='';try{new QRCode(box,{text:data.vcard,width:174,height:174,colorDark:'#103d38',colorLight:'#fff',correctLevel:QRCode.CorrectLevel.M});dl.href=URL.createObjectURL(new Blob([data.vcard],{type:'text/vcard;charset=utf-8'}));dl.download=data.filename;dl.hidden=false}catch(x){box.hidden=true;err.hidden=false}})}
        async function qr(id){const section=side.querySelector('[data-qr-contact]');if(!section)return;if(cards.has(id))return drawQR(section,cards.get(id));const fd=new FormData();fd.set('action','istt_phonebook_vcard');fd.set('nonce',nonce());fd.set('post_id',id);try{const res=await fetch(w.dataset.ajaxUrl,{method:'POST',body:fd,credentials:'same-origin'}),data=await res.json();if(!data.success)throw new Error();const item={vcard:decode64(data.data.vcard),filename:data.data.filename||'contact.vcf'};cards.set(id,item);drawQR(section,item)}catch(e){section.querySelector('.istt-pb-qr-box').hidden=true;section.querySelector('.istt-pb-qr-error').hidden=false}}
        f.addEventListener('submit',e=>{e.preventDefault();page.value=1;load()});search.addEventListener('input',()=>{page.value=1;load({delay:350})});
        w.querySelectorAll('.istt-pb-zone').forEach(b=>b.addEventListener('click',()=>{w.querySelectorAll('.istt-pb-zone').forEach(x=>x.classList.remove('active'));b.classList.add('active');zone.value=b.dataset.zone;unit.value='';page.value=1;load({updateUnits:true})}));
        unit.addEventListener('change',()=>{page.value=1;load()});
        w.querySelectorAll('.istt-pb-tag-link').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();const active=a.classList.contains('active');w.querySelectorAll('.istt-pb-tag-link').forEach(x=>x.classList.remove('active'));tag.value=active?'0':a.dataset.tagId;if(!active)a.classList.add('active');page.value=1;load()}));
        w.querySelector('.istt-pb-reset').addEventListener('click',()=>{search.value='';zone.value='';unit.value='';tag.value='0';page.value='1';w.querySelectorAll('.istt-pb-zone').forEach(x=>x.classList.toggle('active',x.dataset.zone===''));w.querySelectorAll('.istt-pb-tag-link').forEach(x=>x.classList.remove('active'));load({updateUnits:true})});
        r.addEventListener('click',e=>{const pb=e.target.closest('.istt-pb-page-button');if(pb&&!pb.disabled){page.value=pb.dataset.page;load({scroll:true});return}const db=e.target.closest('.istt-pb-details-button');if(!db)return;const id=db.dataset.contactId,t=r.querySelector('#istt-pb-contact-'+id);if(!t)return;lastFocus=db;side.innerHTML='';side.appendChild(t.content.cloneNode(true));layer.classList.add('open');layer.setAttribute('aria-hidden','false');document.body.classList.add('istt-pb-sidebar-open');close.focus();qr(id)});
        function shut(){layer.classList.remove('open');layer.setAttribute('aria-hidden','true');document.body.classList.remove('istt-pb-sidebar-open');setTimeout(()=>side.innerHTML='',350);if(lastFocus)lastFocus.focus()}
        close.addEventListener('click',shut);backdrop.addEventListener('click',shut);document.addEventListener('keydown',e=>{if(e.key==='Escape'&&layer.classList.contains('open'))shut()});
    })();
    </script>
    <?php return ob_get_clean();
}

add_shortcode('istt_phonebook', 'istt_phonebook_shortcode');

function istt_pb_ajax_filter()
{
    check_ajax_referer('istt_phonebook_filter', 'nonce');
    $term = istt_pb_get_contacts_term();
    $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;

    if (!$term || $term_id !== absint($term->term_id)) {
        wp_send_json_error(['message' => 'Invalid category.']);
    }

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $zone   = isset($_POST['zone']) ? sanitize_text_field(wp_unslash($_POST['zone'])) : '';
    $unit   = isset($_POST['unit']) ? sanitize_text_field(wp_unslash($_POST['unit'])) : '';
    $tag_id = isset($_POST['tag_id']) ? absint($_POST['tag_id']) : 0;
    $paged  = isset($_POST['paged']) ? max(1, absint($_POST['paged'])) : 1;
    $per_page = isset($_POST['posts_per_page']) ? min(50, max(1, absint($_POST['posts_per_page']))) : 20;

    if ($tag_id && !term_exists($tag_id, 'post_tag')) {
        $tag_id = 0;
    }

    $response = ['html' => istt_pb_render_results([
        'term_id' => $term_id, 'search' => $search, 'zone' => $zone,
        'unit' => $unit, 'tag_id' => $tag_id, 'paged' => $paged,
        'posts_per_page' => $per_page,
    ])];

    if (!empty($_POST['update_units'])) {
        $response['units'] = istt_pb_get_units($term_id, $zone);
    }

    wp_send_json_success($response);
}

add_action('wp_ajax_istt_phonebook_filter', 'istt_pb_ajax_filter');
add_action('wp_ajax_nopriv_istt_phonebook_filter', 'istt_pb_ajax_filter');

function istt_pb_vcard_escape($value)
{
    return str_replace(
        ["\\", ";", ",", "\r\n", "\r", "\n"],
        ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
        wp_strip_all_tags((string) $value)
    );
}

function istt_pb_build_vcard($post_id)
{
    $fields = istt_pb_fields();
    $name   = get_the_title($post_id);
    $unit   = trim(wp_strip_all_tags(get_post_field('post_excerpt', $post_id)));
    $raw_zone = get_post_meta($post_id, $fields['zone'], true);
    $term = istt_pb_get_contacts_term();
    $zones = $term ? istt_pb_get_zones($term->term_id) : [];
    $zone = isset($zones[$raw_zone]) ? $zones[$raw_zone] : istt_pb_format_acf_value(istt_pb_get_meta_value($post_id, $fields['zone']));
    $email = sanitize_email(istt_pb_get_meta_value($post_id, $fields['email']));
    $external = istt_pb_phone_href(istt_pb_get_meta_value($post_id, $fields['external_phone']));
    $internal = sanitize_text_field(istt_pb_get_meta_value($post_id, $fields['internal_phone']));

    $lines = [
        'BEGIN:VCARD',
        'VERSION:3.0',
        'FN:' . istt_pb_vcard_escape($name),
        'N:' . istt_pb_vcard_escape($name) . ';;;;',
        'ORG:' . istt_pb_vcard_escape('شهرک علمی و تحقیقاتی اصفهان'),
    ];
    if ($unit !== '')     { $lines[] = 'TITLE:' . istt_pb_vcard_escape($unit); }
    if ($zone !== '')     { $lines[] = 'X-DEPARTMENT:' . istt_pb_vcard_escape($zone); }
    if ($external !== '') { $lines[] = 'TEL;TYPE=WORK,VOICE:' . istt_pb_vcard_escape($external); }
    if ($internal !== '') { $lines[] = 'X-PHONE-INTERNAL:' . istt_pb_vcard_escape($internal); }
    if ($email !== '')    { $lines[] = 'EMAIL;TYPE=WORK,INTERNET:' . istt_pb_vcard_escape($email); }
    $lines[] = 'END:VCARD';
    return implode("\r\n", $lines);
}

function istt_pb_ajax_vcard()
{
    check_ajax_referer('istt_phonebook_filter', 'nonce');
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

    if (
        !$post_id ||
        get_post_type($post_id) !== 'post' ||
        get_post_status($post_id) !== 'publish' ||
        !has_category('contacts', $post_id)
    ) {
        wp_send_json_error(['message' => 'Invalid contact.']);
    }

    $filename = sanitize_file_name(get_the_title($post_id));
    wp_send_json_success([
        'vcard'   => base64_encode(istt_pb_build_vcard($post_id)),
        'filename' => ($filename ?: 'contact') . '.vcf',
    ]);
}

add_action('wp_ajax_istt_phonebook_vcard', 'istt_pb_ajax_vcard');
add_action('wp_ajax_nopriv_istt_phonebook_vcard', 'istt_pb_ajax_vcard');
