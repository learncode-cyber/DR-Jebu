<?php
declare(strict_types=1);

function lead_form_fields(string $kind, array $old, array $services): void {
    $v = static fn(string $k): string => h((string) ($old[$k] ?? ''));
    ?>
      <input type="hidden" name="token" value="<?= h(form_token()) ?>">
      <input type="hidden" name="source_page" value="<?= $v('source_page') ?>">
      <input type="hidden" name="utm_source" value="<?= $v('utm_source') ?>">
      <input type="hidden" name="utm_medium" value="<?= $v('utm_medium') ?>">
      <input type="hidden" name="utm_campaign" value="<?= $v('utm_campaign') ?>">
      <div class="hp" aria-hidden="true"><label>ওয়েবসাইট <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="form-grid">
        <div><label for="f-name">আপনার নাম *</label><input id="f-name" type="text" name="name" required maxlength="100" autocomplete="name" value="<?= $v('name') ?>"></div>
        <div><label for="f-phone">মোবাইল নম্বর *</label><input id="f-phone" type="tel" name="phone" required maxlength="25" inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX" value="<?= $v('phone') ?>"></div>
<?php if ($kind === 'appointment'): ?>
        <div><label for="f-age">বয়স</label><input id="f-age" type="number" name="age" min="1" max="120" inputmode="numeric" value="<?= $v('age') ?>"></div>
        <div><label for="f-service">যে বিষয়ে পরামর্শ চান</label>
          <select id="f-service" name="service_interest">
            <option value="">— নির্বাচন করুন —</option>
<?php foreach ($services as $s): ?>
            <option value="<?= h($s) ?>" <?= (($old['service_interest'] ?? '') === $s) ? 'selected' : '' ?>><?= h($s) ?></option>
<?php endforeach; ?>
            <option value="অন্যান্য" <?= (($old['service_interest'] ?? '') === 'অন্যান্য') ? 'selected' : '' ?>>অন্যান্য</option>
          </select></div>
        <div><label for="f-date">পছন্দের তারিখ</label><input id="f-date" type="date" name="preferred_date" value="<?= $v('preferred_date') ?>"></div>
        <div><label for="f-day">দিন</label><input id="f-day" type="text" name="preferred_day" maxlength="20" placeholder="তারিখ দিলে নিজে থেকে বসবে" value="<?= $v('preferred_day') ?>"></div>
<?php endif; ?>
        <div class="full"><label for="f-email">ইমেইল (ঐচ্ছিক)</label><input id="f-email" type="email" name="email" maxlength="190" autocomplete="email" value="<?= $v('email') ?>"></div>
        <div class="full"><label for="f-msg"><?= $kind === 'contact' ? 'আপনার বার্তা *' : 'অতিরিক্ত তথ্য (ঐচ্ছিক)' ?></label><textarea id="f-msg" name="message" maxlength="1500" <?= $kind === 'contact' ? 'required' : '' ?>><?= $v('message') ?></textarea>
          <p class="note">অনুগ্রহ করে এখানে ব্যক্তিগত মেডিকেল রিপোর্ট বা সংবেদনশীল তথ্য লিখবেন না — চেম্বারে আলোচনা করা হবে।</p></div>
        <div class="full"><button type="submit" class="btn btn-primary btn-block"><?= $kind === 'appointment' ? 'অ্যাপয়েন্টমেন্ট অনুরোধ পাঠান' : 'বার্তা পাঠান' ?></button></div>
      </div>
<?php
}

/** Handles GET (render), POST (validate -> lead_create -> PRG redirect) for appointment & contact pages. */
function lead_form_page(string $kind): void {
    $path = $kind === 'appointment' ? '/appointment' : '/contact';
    $error = '';
    $old = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $old = array_map(static fn($x) => is_string($x) ? $x : '', $_POST);
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            redirect($path . '?sent=1'); // honeypot hit: pretend success, store nothing
        }
        if (!form_token_ok((string) ($_POST['token'] ?? ''))) {
            $error = 'ফর্মের সময়সীমা শেষ বা অনুরোধটি সন্দেহজনক। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।';
        } else {
            try {
                $res = lead_create($_POST, $kind);
            } catch (Throwable $e) {
                error_log('[jebu] lead_create: ' . $e->getMessage());
                $res = ['ok' => false, 'error' => 'অনুরোধটি এই মুহূর্তে সংরক্ষণ করা যায়নি। অনুগ্রহ করে সরাসরি ফোন করুন: ' . setting('phone_display')];
            }
            if ($res['ok']) {
                if (empty($res['duplicate'])) {
                    notifications_dispatch_after_response();
                }
                redirect($path . '?sent=1');
            }
            $error = (string) ($res['error'] ?? 'কিছু ভুল হয়েছে।');
        }
    }
    $sent = isset($_GET['sent']);
    $services = [];
    if ($kind === 'appointment') {
        try {
            $services = array_column(q_all('SELECT title FROM services WHERE is_active = 1 ORDER BY sort_order, id'), 'title');
        } catch (Throwable $e) {
        }
    }
    $title = $kind === 'appointment' ? 'অ্যাপয়েন্টমেন্ট / সিরিয়াল' : 'যোগাযোগ';
    $lead = $kind === 'appointment'
        ? 'নাম ও মোবাইল নম্বর দিয়ে অনুরোধ পাঠান — আমাদের টিম যোগাযোগ করে সময় নিশ্চিত করবে।'
        : 'প্রশ্ন, পরামর্শ বা অ্যাপয়েন্টমেন্ট সংক্রান্ত যেকোনো বিষয়ে আমাদের জানান।';
    page_start([
        'title' => $title, 'description' => $lead, 'path' => $path, 'breadcrumb' => [[$title, null]],
        'robots' => $sent ? 'noindex, follow' : 'index, follow',
        'schema' => [[
            '@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => $title, 'url' => abs_url($path),
            'about' => ['@type' => 'Physician', 'name' => setting('doctor_name_bn'), 'telephone' => '+' . preg_replace('/\D/', '', setting('whatsapp_intl'))],
        ]],
    ]);
    page_hero($kind === 'appointment' ? 'Appointment' : 'Contact', $title, $lead, [[$title, null]]);
    $addr = trim(setting('chamber_address'));
    ?>
<section class="section">
  <div class="wrap split" style="align-items:start">
    <div class="form-card reveal">
<?php if ($sent): ?>
      <div class="alert ok" role="status">ধন্যবাদ! আপনার অনুরোধ আমরা পেয়েছি। আমাদের টিম শীঘ্রই আপনার মোবাইল নম্বরে যোগাযোগ করবে।</div>
      <p>দ্রুত সিরিয়াল নিশ্চিত করতে চাইলে সরাসরি জানাতে পারেন:</p>
      <p><a class="btn btn-primary" href="<?= h(wa_link(setting('wa_message'))) ?>" target="_blank" rel="noopener" data-track="whatsapp_click">হোয়াটসঅ্যাপে জানান</a>
         <a class="btn btn-outline" href="<?= h(tel_link()) ?>" data-track="phone_click">কল করুন</a></p>
      <p class="note">জরুরি অবস্থায় (তীব্র ব্যথা, অতিরিক্ত রক্তপাত, গর্ভাবস্থায় হঠাৎ জটিলতা) অপেক্ষা না করে নিকটস্থ হাসপাতালের জরুরি বিভাগে যান।</p>
<?php else: ?>
<?php if ($error !== ''): ?><div class="alert err" role="alert"><?= h($error) ?></div><?php endif; ?>
      <form method="post" action="<?= h($path) ?>" data-lead>
<?php lead_form_fields($kind, $old, $services); ?>
      </form>
<?php endif; ?>
    </div>
    <div class="reveal">
      <div class="box">
        <h3>যোগাযোগের তথ্য</h3>
        <ul class="info-list">
          <li><b>ফোন</b><a href="<?= h(tel_link()) ?>" data-track="phone_click"><?= h(setting('phone_display')) ?></a></li>
          <li><b>হোয়াটসঅ্যাপ</b><a href="<?= h(wa_link(setting('wa_message'))) ?>" target="_blank" rel="noopener" data-track="whatsapp_click"><?= h(setting('whatsapp_display')) ?></a></li>
<?php if (setting('email') !== ''): ?><li><b>ইমেইল</b><a href="mailto:<?= h(setting('email')) ?>"><?= h(setting('email')) ?></a></li><?php endif; ?>
          <li><b>কর্মস্থল</b><span><?= h(setting('workplace')) ?></span></li>
<?php if (!chamber_rows() && $addr !== ''): ?><li><b>চেম্বার</b><span><?= nl2br(h($addr), false) ?></span></li><?php endif; ?>
<?php if (!chamber_rows()): ?>
          <li><b>সময়</b><span><?= h(setting('chamber_hours')) ?></span></li>
<?php endif; ?>
        </ul>
      </div>
<?php if (chamber_rows()): ?>
      <h3 class="chamber-title">চেম্বার ও বসার স্থান</h3>
      <?= chamber_cards_html() ?>
<?php endif; ?>
<?php if (chamber_map_src() !== ''): ?>
      <div class="map" style="margin-top:1.2rem"><iframe src="<?= h(chamber_map_src()) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="চেম্বারের ম্যাপ"></iframe></div>
<?php endif; ?>
    </div>
  </div>
</section>
<?php
    page_end();
}
