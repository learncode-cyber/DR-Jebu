<?php
declare(strict_types=1);

/**
 * One definition per content type. Used by BOTH the public pages (listing/detail)
 * and the generic admin CRUD (jebu-cp-3hr3f/entity.php).
 * Field types: text, slug, textarea, md, image, bool, int, select, relation_id, youtube, datetime, rating
 */
function entities(): array {
    static $E = null;
    if ($E !== null) {
        return $E;
    }
    $seo = [
        ['name' => 'meta_title', 'label' => 'SEO Title (৭০ অক্ষরের মধ্যে)', 'type' => 'text', 'max' => 70],
        ['name' => 'meta_description', 'label' => 'SEO Description (১৭০ অক্ষরের মধ্যে)', 'type' => 'text', 'max' => 170],
    ];
    $tail = [
        ['name' => 'cover_image', 'label' => 'কভার ছবি', 'type' => 'image'],
        ['name' => 'sort_order', 'label' => 'ক্রম (ছোট নম্বর আগে)', 'type' => 'int'],
        ['name' => 'is_active', 'label' => 'সক্রিয় (সাইটে দেখাবে)', 'type' => 'bool'],
    ];
    $base = static fn(array $mid): array => array_merge(
        [
            ['name' => 'title', 'label' => 'শিরোনাম', 'type' => 'text', 'required' => true, 'max' => 200],
            ['name' => 'slug', 'label' => 'URL slug (ইংরেজি ছোট হাতের অক্ষর, সংখ্যা ও -)', 'type' => 'slug', 'required' => true],
            ['name' => 'kicker', 'label' => 'Kicker (ছোট ইংরেজি লেবেল)', 'type' => 'text', 'max' => 160],
            ['name' => 'short_desc', 'label' => 'সংক্ষিপ্ত বিবরণ', 'type' => 'textarea', 'max' => 600],
        ],
        $mid,
        $seo,
        $tail
    );
    $overview = [['name' => 'overview_body', 'label' => 'বিস্তারিত (Markdown: ## শিরোনাম, - তালিকা, **bold**)', 'type' => 'md']];

    $E = [
        'specialties' => [
            'label' => 'বিশেষত্ব', 'singular' => 'বিশেষত্ব', 'table' => 'specialties', 'order' => 'sort_order ASC, id ASC',
            'public' => '/specialties', 'detail_file' => 'specialty-detail', 'icon' => '✚',
            'fields' => $base($overview), 'relations' => [],
        ],
        'services' => [
            'label' => 'সেবাসমূহ', 'singular' => 'সেবা', 'table' => 'services', 'order' => 'sort_order ASC, id ASC',
            'public' => '/services', 'detail_file' => 'service-detail', 'icon' => '❖',
            'fields' => $base(array_merge(
                [['name' => 'specialty_id', 'label' => 'মূল বিশেষত্ব', 'type' => 'relation_id', 'table' => 'specialties']],
                $overview
            )),
            'relations' => [['label' => 'সংশ্লিষ্ট রোগ', 'junction' => 'service_condition', 'self' => 'service_id', 'other' => 'condition_id', 'other_table' => 'conditions']],
        ],
        'conditions' => [
            'label' => 'রোগসমূহ', 'singular' => 'রোগ', 'table' => 'conditions', 'order' => 'sort_order ASC, id ASC',
            'public' => '/conditions', 'detail_file' => 'condition-detail', 'icon' => '◈',
            'fields' => $base(array_merge($overview, [
                ['name' => 'symptoms_body', 'label' => 'লক্ষণ (Markdown)', 'type' => 'md'],
                ['name' => 'causes_body', 'label' => 'কারণ (Markdown)', 'type' => 'md'],
                ['name' => 'diagnosis_body', 'label' => 'রোগ নির্ণয় (Markdown)', 'type' => 'md'],
            ])),
            'relations' => [
                ['label' => 'সংশ্লিষ্ট সেবা', 'junction' => 'service_condition', 'self' => 'condition_id', 'other' => 'service_id', 'other_table' => 'services'],
                ['label' => 'সংশ্লিষ্ট চিকিৎসা', 'junction' => 'condition_treatment', 'self' => 'condition_id', 'other' => 'treatment_id', 'other_table' => 'treatments'],
                ['label' => 'সংশ্লিষ্ট প্রসিডিওর', 'junction' => 'condition_procedure', 'self' => 'condition_id', 'other' => 'procedure_id', 'other_table' => 'procedures'],
            ],
        ],
        'treatments' => [
            'label' => 'চিকিৎসা', 'singular' => 'চিকিৎসা', 'table' => 'treatments', 'order' => 'sort_order ASC, id ASC',
            'public' => '/treatments', 'detail_file' => 'treatment-detail', 'icon' => '℞',
            'fields' => $base($overview),
            'relations' => [['label' => 'যে রোগে প্রযোজ্য', 'junction' => 'condition_treatment', 'self' => 'treatment_id', 'other' => 'condition_id', 'other_table' => 'conditions']],
        ],
        'procedures' => [
            'label' => 'প্রসিডিওর', 'singular' => 'প্রসিডিওর', 'table' => 'procedures', 'order' => 'sort_order ASC, id ASC',
            'public' => '/procedures', 'detail_file' => 'procedure-detail', 'icon' => '✂',
            'fields' => $base($overview),
            'relations' => [['label' => 'যে রোগে প্রযোজ্য', 'junction' => 'condition_procedure', 'self' => 'procedure_id', 'other' => 'condition_id', 'other_table' => 'conditions']],
        ],
        'blog' => [
            'label' => 'ব্লগ', 'singular' => 'লেখা', 'table' => 'blog_posts', 'order' => 'published_at DESC, id DESC',
            'public' => '/blog', 'detail_file' => 'blog-single', 'icon' => '✎',
            'fields' => [
                ['name' => 'title', 'label' => 'শিরোনাম', 'type' => 'text', 'required' => true, 'max' => 200],
                ['name' => 'slug', 'label' => 'URL slug', 'type' => 'slug', 'required' => true],
                ['name' => 'category', 'label' => 'ক্যাটাগরি', 'type' => 'text', 'max' => 80],
                ['name' => 'tags', 'label' => 'ট্যাগ (কমা দিয়ে আলাদা)', 'type' => 'text', 'max' => 255],
                ['name' => 'excerpt', 'label' => 'সারসংক্ষেপ', 'type' => 'textarea', 'max' => 500],
                ['name' => 'body', 'label' => 'লেখা (Markdown: ## শিরোনাম, - তালিকা, **bold**; ছবি যোগ করতে আলাদা লাইনে ![ছবির বিবরণ](/uploads/ছবির-নাম.jpg) — ছবির পাথ গ্যালারি থেকে নিন)', 'type' => 'md', 'required' => true],
                ['name' => 'cover_image', 'label' => 'কভার ছবি', 'type' => 'image'],
                ['name' => 'meta_title', 'label' => 'SEO Title', 'type' => 'text', 'max' => 70],
                ['name' => 'meta_description', 'label' => 'SEO Description', 'type' => 'text', 'max' => 170],
                ['name' => 'focus_keyword', 'label' => 'Focus keyword', 'type' => 'text', 'max' => 120],
                ['name' => 'author_name', 'label' => 'লেখক', 'type' => 'text', 'max' => 120],
                ['name' => 'medically_reviewed_by', 'label' => 'Medically reviewed by', 'type' => 'text', 'max' => 120],
                ['name' => 'status', 'label' => 'স্ট্যাটাস', 'type' => 'select', 'options' => ['draft' => 'Draft', 'published' => 'Published']],
                ['name' => 'robots', 'label' => 'Robots', 'type' => 'select', 'options' => ['index' => 'index', 'noindex' => 'noindex']],
                ['name' => 'published_at', 'label' => 'প্রকাশের সময়', 'type' => 'datetime'],
            ],
            'relations' => [], 'list_extra' => ['status', 'published_at'], 'no_active' => true,
        ],
        'pages' => [
            'label' => 'স্ট্যাটিক পেজ', 'singular' => 'পেজ', 'table' => 'pages', 'order' => 'menu_order ASC, id ASC',
            'public' => '/page', 'detail_file' => 'page', 'icon' => '▤', 'title_col' => 'title',
            'fields' => [
                ['name' => 'title', 'label' => 'পেজের শিরোনাম', 'type' => 'text', 'required' => true, 'max' => 200],
                ['name' => 'slug', 'label' => 'URL slug (ইংরেজি ছোট হাতের অক্ষর, সংখ্যা ও -, যেমন: about-clinic)', 'type' => 'slug', 'required' => true],
                ['name' => 'short_desc', 'label' => 'সংক্ষিপ্ত বিবরণ (শুরুতে থাকবে, সার্চ ফলাফলেও ব্যবহার হবে)', 'type' => 'textarea', 'max' => 300],
                ['name' => 'body', 'label' => 'পেজের লেখা', 'type' => 'rich', 'required' => true],
                ['name' => 'cover_image', 'label' => 'কভার ছবি (ঐচ্ছিক)', 'type' => 'image'],
                ['name' => 'meta_title', 'label' => 'SEO Title (৭০ অক্ষরের মধ্যে)', 'type' => 'text', 'max' => 70],
                ['name' => 'meta_description', 'label' => 'SEO Description (১৭০ অক্ষরের মধ্যে)', 'type' => 'text', 'max' => 170],
                ['name' => 'show_in_menu', 'label' => 'ওয়েবসাইটের মেনুতে দেখাবে', 'type' => 'bool'],
                ['name' => 'menu_order', 'label' => 'মেনুতে ক্রম (ছোট নম্বর আগে)', 'type' => 'int'],
                ['name' => 'is_active', 'label' => 'প্রকাশিত (ওয়েবসাইটে দেখাবে)', 'type' => 'bool'],
            ],
            'relations' => [],
        ],
        'testimonials' => [
            'label' => 'রোগীর অভিজ্ঞতা', 'singular' => 'রিভিউ', 'table' => 'testimonials', 'order' => 'sort_order ASC, id DESC',
            'public' => '/testimonials', 'detail_file' => null, 'icon' => '♥', 'title_col' => 'name',
            'fields' => [
                ['name' => 'name', 'label' => 'নাম', 'type' => 'text', 'required' => true, 'max' => 80],
                ['name' => 'context', 'label' => 'প্রসঙ্গ (যেমন: গর্ভকালীন সেবা)', 'type' => 'text', 'max' => 120],
                ['name' => 'quote', 'label' => 'রোগীর বক্তব্য (রোগীর অনুমতিসহ)', 'type' => 'textarea', 'required' => true, 'max' => 900],
                ['name' => 'rating', 'label' => 'রেটিং (১–৫, ঐচ্ছিক)', 'type' => 'rating'],
                ['name' => 'photo', 'label' => 'ছবি (ঐচ্ছিক)', 'type' => 'image'],
                ['name' => 'sort_order', 'label' => 'ক্রম', 'type' => 'int'],
                ['name' => 'is_active', 'label' => 'সক্রিয়', 'type' => 'bool'],
            ],
            'relations' => [],
        ],
        'faqs' => [
            'label' => 'FAQ', 'singular' => 'প্রশ্ন', 'table' => 'faqs', 'order' => 'sort_order ASC, id ASC',
            'public' => '/faqs', 'detail_file' => null, 'icon' => '?', 'title_col' => 'question',
            'fields' => [
                ['name' => 'question', 'label' => 'প্রশ্ন', 'type' => 'text', 'required' => true, 'max' => 300],
                ['name' => 'answer', 'label' => 'উত্তর', 'type' => 'textarea', 'required' => true],
                ['name' => 'category', 'label' => 'বিভাগ', 'type' => 'text', 'max' => 80],
                ['name' => 'sort_order', 'label' => 'ক্রম', 'type' => 'int'],
                ['name' => 'is_active', 'label' => 'সক্রিয়', 'type' => 'bool'],
            ],
            'relations' => [],
        ],
        'chambers' => [
            'label' => 'চেম্বার ও কর্মস্থল', 'singular' => 'চেম্বার', 'table' => 'chambers', 'order' => 'sort_order ASC, id ASC',
            'public' => '/appointment', 'detail_file' => null, 'icon' => '⌂', 'title_col' => 'name',
            'fields' => [
                ['name' => 'name', 'label' => 'চেম্বার / হাসপাতালের নাম', 'type' => 'text', 'required' => true, 'max' => 200],
                ['name' => 'location', 'label' => 'লোকেশন (ঠিকানা)', 'type' => 'textarea', 'max' => 400],
                ['name' => 'visiting_hours', 'label' => 'বসার সময়', 'type' => 'text', 'max' => 200],
                ['name' => 'phone', 'label' => 'ফোন (ঐচ্ছিক)', 'type' => 'text', 'max' => 30],
                ['name' => 'map_link', 'label' => 'ম্যাপ লিংক (ঐচ্ছিক — খালি রাখলে ঠিকানা থেকে স্বয়ংক্রিয়ভাবে তৈরি হবে)', 'type' => 'text', 'max' => 400],
                ['name' => 'sort_order', 'label' => 'সিরিয়াল নম্বর (ছোট নম্বর আগে দেখাবে)', 'type' => 'int'],
                ['name' => 'is_active', 'label' => 'সক্রিয় (ওয়েবসাইটে দেখাবে)', 'type' => 'bool'],
            ],
            'relations' => [],
        ],
        'gallery' => [
            'label' => 'গ্যালারি', 'singular' => 'ছবি', 'table' => 'gallery_items', 'order' => 'sort_order ASC, id DESC',
            'public' => '/gallery', 'detail_file' => null, 'icon' => '▦', 'title_col' => 'title',
            'fields' => [
                ['name' => 'title', 'label' => 'শিরোনাম / ছবির বিবরণ', 'type' => 'text', 'required' => true, 'max' => 200],
                ['name' => 'category', 'label' => 'বিভাগ (যেমন: চেম্বার, সেমিনার, সার্জারি)', 'type' => 'text', 'max' => 80],
                ['name' => 'image', 'label' => 'ছবি (JPG/PNG/WEBP, সর্বোচ্চ ১০MB)', 'type' => 'image', 'required' => true],
                ['name' => 'caption', 'label' => 'ক্যাপশন (ঐচ্ছিক)', 'type' => 'textarea', 'max' => 400],
                ['name' => 'sort_order', 'label' => 'ক্রম (ছোট নম্বর আগে)', 'type' => 'int'],
                ['name' => 'is_active', 'label' => 'সক্রিয় (ওয়েবসাইটে দেখাবে)', 'type' => 'bool'],
            ],
            'relations' => [],
        ],
        'videos' => [
            'label' => 'ভিডিও', 'singular' => 'ভিডিও', 'table' => 'videos', 'order' => 'sort_order ASC, id DESC',
            'public' => '/videos', 'detail_file' => null, 'icon' => '▶',
            'fields' => [
                ['name' => 'title', 'label' => 'শিরোনাম', 'type' => 'text', 'required' => true, 'max' => 200],
                ['name' => 'youtube_id', 'label' => 'YouTube ভিডিও ID বা লিংক', 'type' => 'youtube', 'required' => true],
                ['name' => 'category', 'label' => 'বিভাগ', 'type' => 'text', 'max' => 80],
                ['name' => 'description', 'label' => 'সংক্ষিপ্ত বিবরণ', 'type' => 'textarea', 'max' => 600],
                ['name' => 'sort_order', 'label' => 'ক্রম', 'type' => 'int'],
                ['name' => 'is_active', 'label' => 'সক্রিয়', 'type' => 'bool'],
            ],
            'relations' => [],
        ],
    ];
    return $E;
}

function entity(string $key): array {
    $e = entities()[$key] ?? null;
    if (!$e) {
        throw new InvalidArgumentException('unknown entity ' . $key);
    }
    $e['key'] = $key;
    $e['title_col'] = $e['title_col'] ?? 'title';
    return $e;
}

function entity_url(string $key, string $slug): string {
    return entity($key)['public'] . '/' . $slug;
}

function entity_rows(string $key, int $limit = 0): array {
    $e = entity($key);
    $sql = 'SELECT * FROM ' . $e['table'] . ' WHERE is_active = 1 ORDER BY ' . $e['order'];
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
    }
    return q_all($sql);
}

/** Linked rows through a junction table (only active ones). */
function entity_related(string $junction, string $selfCol, int $selfId, string $otherCol, string $otherTable): array {
    return q_all(
        'SELECT t.* FROM ' . $otherTable . ' t JOIN ' . $junction . ' j ON j.' . $otherCol . ' = t.id WHERE j.' . $selfCol . ' = ? AND t.is_active = 1 ORDER BY t.sort_order ASC, t.id ASC',
        [$selfId]
    );
}

function youtube_id(string $in): string {
    $in = trim($in);
    if (preg_match('~(?:youtu\.be/|v=|embed/|shorts/)([A-Za-z0-9_-]{11})~', $in, $m)) {
        return $m[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $in) ? $in : '';
}
