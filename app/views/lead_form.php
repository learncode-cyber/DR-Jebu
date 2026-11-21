<?php
declare(strict_types=1);

/**
 * $services is a list of ['v' => value stored in the lead (Bangla title), 'l' => label shown to the visitor].
 * The stored value never changes with the language, so leads stay consistent.
 */
function lead_form_fields(string $kind, array $old, array $services): void {
    $v = static fn(string $k): string => h((string) ($old[$k] ?? ''));
    ?>
      <input type="hidden" name="token" value="<?= h(form_token()) ?>">
      <input type="hidden" name="source_page" value="<?= $v('source_page') ?>">
      <input type="hidden" name="utm_source" value="<?= $v('utm_source') ?>">
      <input type="hidden" name="utm_medium" value="<?= $v('utm_medium') ?>">
      <input type="hidden" name="utm_campaign" value="<?= $v('utm_campaign') ?>">
      <div class="hp" aria-hidden="true"><label><?= h(tr('ওয়েবসাইট', 'Website')) ?> <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="form-grid">
        <div><label for="f-name"><?= h(tr('আপনার নাম *', 'Your name *')) ?></label><input id="f-name" type="text" name="name" required maxlength="100" autocomplete="name" value="<?= $v('name') ?>"></div>
        <div><label for="f-phone"><?= h(tr('মোবাইল নম্বর *', 'Mobile number *')) ?></label><input id="f-phone" type="tel" name="phone" required maxlength="16" inputmode="numeric" pattern="\+?[0-9]{8,15}" title="<?= h(tr('শুধু সংখ্যা লিখুন, যেমন 01XXXXXXXXX', 'Digits only, e.g. 01XXXXXXXXX')) ?>" data-digits="phone" autocomplete="tel" placeholder="01XXXXXXXXX" value="<?= $v('phone') ?>"></div>
<?php if ($kind === 'appointment'): ?>
        <div><label for="f-age"><?= h(tr('বয়স', 'Age')) ?></label><input id="f-age" type="text" name="age" maxlength="3" inputmode="numeric" pattern="[0-9]{1,3}" title="<?= h(tr('শুধু সংখ্যা (১-১২০)', 'Digits only (1-120)')) ?>" data-digits="age" value="<?= $v('age') ?>"></div>
        <div><label for="f-service"><?= h(tr('যে বিষয়ে পরামর্শ চান', 'Consultation topic')) ?></label>
          <select id="f-service" name="service_interest">
            <option value=""><?= h(tr('নির্বাচন করুন', 'Select')) ?></option>
<?php foreach ($services as $s): ?>
            <option value="<?= h($s['v']) ?>" <?= (($old['service_interest'] ?? '') === $s['v']) ? 'selected' : '' ?>><?= h($s['l']) ?></option>
<?php endforeach; ?>
            <option value="অন্যান্য" <?= (($old['service_interest'] ?? '') === 'অন্যান্য') ? 'selected' : '' ?>><?= h(tr('অন্যান্য', 'Other')) ?></option>
          </select></div>
        <div><label for="f-date"><?= h(tr('পছন্দের তারিখ', 'Preferred date')) ?></label><input id="f-date" type="date" name="preferred_date" value="<?= $v('preferred_date') ?>"></div>
        <div><label for="f-day"><?= h(tr('দিন', 'Day')) ?></label><input id="f-day" type="text" name="preferred_day" maxlength="20" placeholder="<?= h(tr('তারিখ দিলে নিজে থেকে বসবে', 'Filled in automatically from the date')) ?>" value="<?= $v('preferred_day') ?>"></div>
<?php endif; ?>
        <div class="full"><label for="f-email"><?= h(tr('ইমেইল (ঐচ্ছিক)', 'Email (optional)')) ?></label><input id="f-email" type="email" name="email" maxlength="190" autocomplete="email" value="<?= $v('email') ?>"></div>
        <div class="full"><label for="f-msg"><?= h($kind === 'contact' ? tr('আপনার বার্তা *', 'Your message *') : tr('অতিরিক্ত তথ্য (ঐচ্ছিক)', 'Additional information (optional)')) ?></label><textarea id="f-msg" name="message" maxlength="1500" <?= $kind === 'contact' ? 'required' : '' ?>><?= $v('message') ?></textarea>
          <p class="note"><?= h(tr('অনুগ্রহ করে এখানে ব্যক্তিগত মেডিকেল রিপোর্ট বা সংবেদনশীল তথ্য লিখবেন না, চেম্বারে আলোচনা করা হবে।', 'Please do not write personal medical reports or sensitive information here. We will discuss them at the chamber.')) ?></p></div>
        <div class="full"><button type="submit" class="btn btn-primary btn-block"><?= h($kind === 'appointment' ? tr('অ্যাপয়েন্টমেন্ট অনুরোধ পাঠান', 'Send appointment request') : tr('বার্তা পাঠান', 'Send message')) ?></button></div>
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
            redirect(lang_path($path) . '?sent=1'); // honeypot hit: pretend success, store nothing
        }
        if (!form_token_ok((string) ($_POST['token'] ?? ''))) {
            $error = tr('ফর্মের সময়সীমা শেষ বা অনুরোধটি সন্দেহজনক। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।', 'The form has expired or the request looks suspicious. Refresh the page and try again.');
        } else {
            try {
                $res = lead_create($_POST, $kind);
            } catch (Throwable $e) {
                error_log('[jebu] lead_create: ' . $e->getMessage());
                $res = ['ok' => false, 'error' => tr('অনুরোধটি এই মুহূর্তে সংরক্ষণ করা যায়নি। অনুগ্রহ করে সরাসরি ফোন করুন: ', 'We could not save your request right now. Please call us directly: ') . setting('phone_display')];
            }
            if ($res['ok']) {
                $eid = bin2hex(random_bytes(16));
                if (empty($res['duplicate'])) {
                    notifications_dispatch_after_response();
                    tracking_after_lead(tracking_lead_context($_POST, $kind, $eid, abs_url($path)));
                }
                redirect(lang_path($path) . '?sent=1&eid=' . $eid);
            }
            $error = (string) ($res['error'] ?? tr('কিছু ভুল হয়েছে।', 'Something went wrong.'));
        }
    }
    $sent = isset($_GET['sent']);
    $services = [];
    if ($kind === 'appointment') {
        try {
            // Not localize_rows: the stored value must stay the Bangla title; only the label follows the language
            foreach (q_all('SELECT title, title_en FROM services WHERE is_active = 1 ORDER BY sort_order, id') as $sv) {
                $services[] = ['v' => (string) $sv['title'], 'l' => tr((string) $sv['title'], trim((string) $sv['title_en']) !== '' ? (string) $sv['title_en'] : (string) $sv['title'])];
            }
        } catch (Throwable $e) {
        }
    }
    $title = tr($kind === 'appointment' ? 'অ্যাপয়েন্টমেন্ট / সিরিয়াল' : 'যোগাযোগ', $kind === 'appointment' ? 'Appointment / slot' : 'Contact');
    $lead = $kind === 'appointment'
        ? tr('নাম ও মোবাইল নম্বর দিয়ে অনুরোধ পাঠান, আমাদের টিম যোগাযোগ করে সময় নিশ্চিত করবে।', 'Send your request with your name and mobile number. Our team will contact you to confirm the time.')
        : tr('প্রশ্ন, পরামর্শ বা অ্যাপয়েন্টমেন্ট সংক্রান্ত যেকোনো বিষয়ে আমাদের জানান।', 'Tell us about any question, advice or appointment matter.');
    page_start([
        'title' => $title, 'description' => $lead, 'path' => $path, 'breadcrumb' => [[$title, null]],
        'full_title' => ($kind === 'appointment' ? 'অ্যাপয়েন্টমেন্ট ও সিরিয়াল' : 'যোগাযোগ ও অ্যাপয়েন্টমেন্ট') . ' | ' . setting('doctor_name_bn'),
        'robots' => $sent ? 'noindex, follow' : 'index, follow',
        'schema' => [[
            '@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => $title, 'url' => abs_url($path),
            'about' => ['@type' => 'Physician', 'name' => doctor_schema_name(), 'telephone' => '+' . preg_replace('/\D/', '', setting('whatsapp_intl'))],
        ]],
    ]);
    page_hero($kind === 'appointment' ? 'Appointment' : 'Contact', $title, $lead, [[$title, null]]);
    $addr = trim(setting('chamber_address'));
    ?>
<section class="section">
  <div class="wrap split" style="align-items:start">
    <div class="form-card reveal">
<?php if ($sent): ?>
<?= tracking_thanks_html($kind, (string) ($_GET['eid'] ?? '')) ?>
      <div class="alert ok" role="status"><?= h(tr('ধন্যবাদ! আপনার অনুরোধ আমরা পেয়েছি। আমাদের টিম শীঘ্রই আপনার মোবাইল নম্বরে যোগাযোগ করবে।', 'Thank you! We have received your request. Our team will contact you on your mobile number shortly.')) ?></div>
      <p><?= h(tr('দ্রুত সিরিয়াল নিশ্চিত করতে চাইলে সরাসরি জানাতে পারেন:', 'To confirm your slot quickly, you can also contact us directly:')) ?></p>
      <p><a class="btn btn-primary" href="<?= h(wa_link(setting('wa_message'))) ?>" target="_blank" rel="noopener" data-track="whatsapp_click"><?= h(tr('হোয়াটসঅ্যাপে জানান', 'Message on WhatsApp')) ?></a>
         <a class="btn btn-outline" href="<?= h(tel_link()) ?>" data-track="phone_click"><?= h(tr('কল করুন', 'Call us')) ?></a></p>
      <p class="note"><?= h(tr('জরুরি অবস্থায় (তীব্র ব্যথা, অতিরিক্ত রক্তপাত, গর্ভাবস্থায় হঠাৎ জটিলতা) অপেক্ষা না করে নিকটস্থ হাসপাতালের জরুরি বিভাগে যান।', 'In an emergency (severe pain, heavy bleeding, sudden complications in pregnancy), do not wait. Go to the emergency department of the nearest hospital.')) ?></p>
<?php else: ?>
<?php if ($error !== ''): ?><div class="alert err" role="alert"><?= h($error) ?></div><?php endif; ?>
      <form method="post" action="<?= h($path) ?>" data-lead>
<?php lead_form_fields($kind, $old, $services); ?>
      </form>
<?php endif; ?>
    </div>
    <div class="reveal">
      <div class="box">
        <h3><?= h(tr('যোগাযোগের তথ্য', 'Contact details')) ?></h3>
        <ul class="info-list">
          <li><b><?= h(tr('ফোন', 'Phone')) ?></b><a href="<?= h(tel_link()) ?>" data-track="phone_click"><?= h(setting('phone_display')) ?></a></li>
          <li><b><?= h(tr('হোয়াটসঅ্যাপ', 'WhatsApp')) ?></b><a href="<?= h(wa_link(setting('wa_message'))) ?>" target="_blank" rel="noopener" data-track="whatsapp_click"><?= h(setting('whatsapp_display')) ?></a></li>
<?php if (setting('email') !== ''): ?><li><b><?= h(tr('ইমেইল', 'Email')) ?></b><a href="mailto:<?= h(setting('email')) ?>"><?= h(setting('email')) ?></a></li><?php endif; ?>
          <li><b><?= h(tr('কর্মস্থল', 'Workplace')) ?></b><span><?= h(s_tr('workplace')) ?></span></li>
<?php if (!chamber_rows() && $addr !== ''): ?><li><b><?= h(tr('চেম্বার', 'Chamber')) ?></b><span><?= nl2br(h($addr), false) ?></span></li><?php endif; ?>
<?php if (!chamber_rows()): ?>
          <li><b><?= h(tr('সময়', 'Hours')) ?></b><span><?= h(s_tr('chamber_hours')) ?></span></li>
<?php endif; ?>
        </ul>
      </div>
<?php if (chamber_rows()): ?>
      <h3 class="chamber-title"><?= h(tr('চেম্বার ও বসার স্থান', 'Chambers and locations')) ?></h3>
      <?= chamber_cards_html() ?>
<?php endif; ?>
<?php if (chamber_map_src() !== ''): ?>
      <div class="map" style="margin-top:1.2rem"><iframe src="<?= h(chamber_map_src()) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="<?= h(tr('চেম্বারের ম্যাপ', 'Chamber map')) ?>"></iframe></div>
<?php endif; ?>
    </div>
  </div>
</section>
<?php
    page_end();
}
