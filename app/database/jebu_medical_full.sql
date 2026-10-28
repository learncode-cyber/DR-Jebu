-- =====================================================================
--  ডাঃ জেবুন্নেসা জেবু — ওয়েবসাইট ডাটাবেস (একটাই ফাইল, সব ভার্সনের জন্য)
--  jebu_medical_full.sql
--
--  ব্যবহার:
--    * নতুন ডাটাবেসে (নতুন ডোমেইন/হোস্টিং) একবার ইম্পোর্ট করুন। এটাই যথেষ্ট।
--    * পুরনো ডাটাবেসে আবার ইম্পোর্ট করলেও কোনো এরর আসবে না: যা আছে তা থাকবে,
--      যা নেই তা যোগ হবে, এবং আপনার এডিট করা লেখা/নম্বর মুছবে না।
--    * ভবিষ্যতের প্রতিটি নতুন ভার্সনে ডাটাবেসের পরিবর্তন এই ফাইলের শেষে যোগ হবে।
--      নতুন কলাম লাগলে "Future column helper" সেকশনে একটি লাইন যোগ করুন।
--
--  নিয়ম (ডেভেলপার): প্রতিটি CREATE TABLE হবে "IF NOT EXISTS", প্রতিটি seed INSERT হবে
--  "IGNORE" বা "WHERE NOT EXISTS" দিয়ে গার্ড করা, আর কলাম যোগ হবে helper দিয়ে।
--
--  এই ফাইলে নেই (ইচ্ছাকৃতভাবে): অ্যাডমিন পাসওয়ার্ড, APP_KEY, ডাটাবেস পাসওয়ার্ড,
--  ফোন/ইমেইল-এর চূড়ান্ত মান — এগুলো অ্যাডমিন প্যানেল বা config.php থেকে সেট হয়।
--  সাইট সেটিংসের ডিফল্ট মান কোড (app/defaults.php) থেকে আসে, তাই এখানে আলাদা করে রাখা হয়নি।
-- =====================================================================
SET NAMES utf8mb4;


-- ---------- Staff accounts & security ----------
CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL,
  email          VARCHAR(190) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('admin','manager','reception','doctor','editor') NOT NULL DEFAULT 'reception',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at  DATETIME     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip            VARCHAR(45)  NOT NULL,
  email         VARCHAR(190) NOT NULL DEFAULT '',
  attempted_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(120) NOT NULL,
  detail      VARCHAR(500) NOT NULL DEFAULT '',
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS not_found_log (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  url         VARCHAR(500) NOT NULL,
  referrer    VARCHAR(500) NOT NULL DEFAULT '',
  user_agent  VARCHAR(300) NOT NULL DEFAULT '',
  hits        INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_url (url(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Site settings (key/value; overrides defaults in app/defaults.php) ----------
CREATE TABLE IF NOT EXISTS site_settings (
  skey        VARCHAR(80) NOT NULL PRIMARY KEY,
  svalue      MEDIUMTEXT  NOT NULL,
  updated_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Leads: appointment requests + contact queries ----------
CREATE TABLE IF NOT EXISTS leads (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind             ENUM('appointment','contact') NOT NULL DEFAULT 'appointment',
  name             VARCHAR(100) NOT NULL,
  phone            VARCHAR(25)  NOT NULL,
  email            VARCHAR(190) NOT NULL DEFAULT '',
  age              VARCHAR(3)   NULL,
  preferred_date   VARCHAR(40)  NULL,
  preferred_day    VARCHAR(20)  NULL,
  service_interest VARCHAR(160) NOT NULL DEFAULT '',
  message          VARCHAR(1500) NOT NULL DEFAULT '',
  source_page      VARCHAR(190) NOT NULL DEFAULT '',
  utm_source       VARCHAR(100) NOT NULL DEFAULT '',
  utm_medium       VARCHAR(100) NOT NULL DEFAULT '',
  utm_campaign     VARCHAR(150) NOT NULL DEFAULT '',
  status           ENUM('new','contacted','booked','visited','closed','spam') NOT NULL DEFAULT 'new',
  assigned_to      INT UNSIGNED NULL,
  notes            TEXT NULL,
  ip               VARCHAR(45)  NOT NULL DEFAULT '',
  user_agent       VARCHAR(300) NOT NULL DEFAULT '',
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_leads_status_created (status, created_at),
  INDEX idx_leads_phone (phone),
  CONSTRAINT fk_leads_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lead tracking structure: every state change / dispatch is an event row.
CREATE TABLE IF NOT EXISTS lead_events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id     INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  event_type  VARCHAR(40)  NOT NULL,
  detail      VARCHAR(500) NOT NULL DEFAULT '',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lead_events_lead (lead_id, created_at),
  CONSTRAINT fk_lead_events_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notification dispatch queue (email / webhook). Retried by cron/dispatch.php.
CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id     INT UNSIGNED NOT NULL,
  channel     ENUM('email','webhook','telegram') NOT NULL,
  recipient   VARCHAR(255) NOT NULL DEFAULT '',
  subject     VARCHAR(255) NOT NULL DEFAULT '',
  payload     MEDIUMTEXT   NOT NULL,
  status      ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(300) NOT NULL DEFAULT '',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at     DATETIME     NULL,
  INDEX idx_notif_status (status, attempts),
  CONSTRAINT fk_notif_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Blog ----------
CREATE TABLE IF NOT EXISTS blog_posts (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug                  VARCHAR(160) NOT NULL UNIQUE,
  category              VARCHAR(80)  NOT NULL DEFAULT '',
  tags                  VARCHAR(255) NOT NULL DEFAULT '',
  title                 VARCHAR(200) NOT NULL,
  meta_title            VARCHAR(70)  NOT NULL DEFAULT '',
  excerpt               VARCHAR(500) NOT NULL DEFAULT '',
  meta_description      VARCHAR(170) NOT NULL DEFAULT '',
  focus_keyword         VARCHAR(120) NOT NULL DEFAULT '',
  body                  LONGTEXT     NOT NULL,
  cover_image           VARCHAR(255) NOT NULL DEFAULT '',
  status                ENUM('draft','published') NOT NULL DEFAULT 'draft',
  robots                ENUM('index','noindex') NOT NULL DEFAULT 'index',
  author_name           VARCHAR(120) NOT NULL DEFAULT 'ডাঃ জেবুন্নেসা জেবু',
  medically_reviewed_by VARCHAR(120) NOT NULL DEFAULT '',
  published_at          DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_blog_status_published (status, published_at),
  INDEX idx_blog_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS old_slugs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  old_slug   VARCHAR(160) NOT NULL,
  post_id    INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_old_slug (old_slug),
  INDEX idx_old_slugs_post (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Entity model ----------
CREATE TABLE IF NOT EXISTS specialties (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL UNIQUE,
  kicker VARCHAR(160) NOT NULL DEFAULT '',
  title VARCHAR(200) NOT NULL,
  short_desc VARCHAR(600) NOT NULL DEFAULT '',
  overview_body MEDIUMTEXT NULL,
  cover_image VARCHAR(255) NOT NULL DEFAULT '',
  meta_title VARCHAR(70) NOT NULL DEFAULT '',
  meta_description VARCHAR(170) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_specialties_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL UNIQUE,
  specialty_id INT UNSIGNED NULL,
  kicker VARCHAR(160) NOT NULL DEFAULT '',
  title VARCHAR(200) NOT NULL,
  short_desc VARCHAR(600) NOT NULL DEFAULT '',
  overview_body MEDIUMTEXT NULL,
  cover_image VARCHAR(255) NOT NULL DEFAULT '',
  meta_title VARCHAR(70) NOT NULL DEFAULT '',
  meta_description VARCHAR(170) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_services_active_order (is_active, sort_order),
  CONSTRAINT fk_services_specialty FOREIGN KEY (specialty_id) REFERENCES specialties(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conditions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL UNIQUE,
  kicker VARCHAR(160) NOT NULL DEFAULT '',
  title VARCHAR(200) NOT NULL,
  short_desc VARCHAR(600) NOT NULL DEFAULT '',
  overview_body MEDIUMTEXT NULL,
  symptoms_body MEDIUMTEXT NULL,
  causes_body MEDIUMTEXT NULL,
  diagnosis_body MEDIUMTEXT NULL,
  cover_image VARCHAR(255) NOT NULL DEFAULT '',
  meta_title VARCHAR(70) NOT NULL DEFAULT '',
  meta_description VARCHAR(170) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_conditions_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS treatments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL UNIQUE,
  kicker VARCHAR(160) NOT NULL DEFAULT '',
  title VARCHAR(200) NOT NULL,
  short_desc VARCHAR(600) NOT NULL DEFAULT '',
  overview_body MEDIUMTEXT NULL,
  cover_image VARCHAR(255) NOT NULL DEFAULT '',
  meta_title VARCHAR(70) NOT NULL DEFAULT '',
  meta_description VARCHAR(170) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_treatments_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS procedures (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL UNIQUE,
  kicker VARCHAR(160) NOT NULL DEFAULT '',
  title VARCHAR(200) NOT NULL,
  short_desc VARCHAR(600) NOT NULL DEFAULT '',
  overview_body MEDIUMTEXT NULL,
  cover_image VARCHAR(255) NOT NULL DEFAULT '',
  meta_title VARCHAR(70) NOT NULL DEFAULT '',
  meta_description VARCHAR(170) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_procedures_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_condition (
  service_id INT UNSIGNED NOT NULL, condition_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (service_id, condition_id),
  CONSTRAINT fk_sc_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
  CONSTRAINT fk_sc_condition FOREIGN KEY (condition_id) REFERENCES conditions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS condition_treatment (
  condition_id INT UNSIGNED NOT NULL, treatment_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (condition_id, treatment_id),
  CONSTRAINT fk_ct_condition FOREIGN KEY (condition_id) REFERENCES conditions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ct_treatment FOREIGN KEY (treatment_id) REFERENCES treatments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS condition_procedure (
  condition_id INT UNSIGNED NOT NULL, procedure_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (condition_id, procedure_id),
  CONSTRAINT fk_cp_condition FOREIGN KEY (condition_id) REFERENCES conditions(id) ON DELETE CASCADE,
  CONSTRAINT fk_cp_procedure FOREIGN KEY (procedure_id) REFERENCES procedures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Homepage / content modules ----------
CREATE TABLE IF NOT EXISTS testimonials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  context VARCHAR(120) NOT NULL DEFAULT '',
  quote VARCHAR(900) NOT NULL,
  rating TINYINT UNSIGNED NULL,
  photo VARCHAR(255) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_testimonials_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Static pages (owner / marketing team can add pages from the admin)
CREATE TABLE IF NOT EXISTS pages (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title            VARCHAR(200)  NOT NULL DEFAULT '',
  slug             VARCHAR(160)  NOT NULL UNIQUE,
  short_desc       VARCHAR(300)  NOT NULL DEFAULT '',
  body             MEDIUMTEXT    NULL,
  cover_image      VARCHAR(255)  NOT NULL DEFAULT '',
  meta_title       VARCHAR(70)   NOT NULL DEFAULT '',
  meta_description VARCHAR(170)  NOT NULL DEFAULT '',
  show_in_menu     TINYINT(1)    NOT NULL DEFAULT 0,
  menu_order       INT           NOT NULL DEFAULT 0,
  is_active        TINYINT(1)    NOT NULL DEFAULT 1,
  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP     NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faqs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(80) NOT NULL DEFAULT '',
  question VARCHAR(300) NOT NULL,
  answer TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_faqs_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS videos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  youtube_id VARCHAR(20) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT '',
  description VARCHAR(600) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_videos_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  SEED DATA
-- =====================================================================

-- ---- Blog articles (added in v23 editorial update; each insert runs only once per slug) ----
INSERT INTO blog_posts (slug, category, tags, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, medically_reviewed_by, published_at)
SELECT 'preconception-care-folic-acid-before-pregnancy', 'গর্ভধারণের আগে প্রস্তুতি', 'গর্ভধারণের প্রস্তুতি, ফলিক অ্যাসিড, প্রি-কনসেপশন', 'গর্ভধারণের আগে প্রস্তুতি: ফলিক অ্যাসিড ও প্রয়োজনীয় পরীক্ষা', 'গর্ভধারণের আগে প্রস্তুতি: ফলিক অ্যাসিড ও পরীক্ষা', 'গর্ভধারণের আগে কোন পরীক্ষা লাগে, ফলিক অ্যাসিড কখন ও কতটুকু নেবেন, এবং জীবনযাত্রায় কী বদলাতে হবে — স্ত্রীরোগ বিশেষজ্ঞের সাধারণ নির্দেশনা।', 'গর্ভধারণের আগে কী খাবেন, কোন পরীক্ষা করাবেন এবং ফলিক অ্যাসিড কেন জরুরি — সহজ ভাষায় বিস্তারিত।', 'গর্ভাবস্থা', '## গর্ভধারণের আগে কেন প্রস্তুতি দরকার?

গর্ভধারণের আগের কয়েক মাস মা ও শিশুর স্বাস্থ্যের ভিত্তি তৈরি করে। অনেক জটিলতা, যেমন শিশুর স্নায়ুনালির ত্রুটি বা গর্ভকালীন ডায়াবেটিস, আগে থেকে পরিকল্পনা করলে অনেক ক্ষেত্রে কমানো যায়। তাই গর্ভধারণের পরিকল্পনা থাকলে ডাক্তারের সাথে একবার পরামর্শ নেওয়া উত্তম।

## ফলিক অ্যাসিড কেন জরুরি?

ফলিক অ্যাসিড (ভিটামিন বি৯) শিশুর মস্তিষ্ক ও মেরুদণ্ডের প্রাথমিক গঠনে ভূমিকা রাখে। গর্ভধারণের অনেক আগেই শিশুর নিউরাল টিউব তৈরি শুরু হয়, তাই গর্ভধারণের জানার আগেই এই ভিটামিন শুরু করা জরুরি।

- **মাত্রা:** সাধারণ নারীদের ক্ষেত্রে সাধারণত দৈনিক ৪০০ মাইক্রোগ্রাম (০.৪ মিগ্রা) ফলিক অ্যাসিড সুপারিশ করা হয়।
- **কখন শুরু করবেন:** গর্ভধারণের অন্তত এক মাস আগে থেকে শুরু করে প্রথম তিন মাস পর্যন্ত চালিয়ে যাওয়ার কথা বলা হয়।
- **উচ্চ মাত্রা:** আগের গর্ভে স্নায়ুনালির ত্রুটি হয়ে থাকলে, বা নির্দিষ্ট ওষুধ খেলে ডাক্তার বেশি মাত্রা লিখতে পারেন। এটি শুধু চিকিৎসকের পরামর্শে নিন।

ফলিক অ্যাসিড শুধু ওষুধ হিসেবে নয়, পালংশাক, মসুর ডাল, ছোলা, কলিজা ও সবুজ শাকসবজি থেকেও পাওয়া যায়। তবে শুধু খাবার থেকে যথেষ্ট পরিমাণ পাওয়া কঠিন, তাই ওষুধ প্রয়োজন হয়।

## গর্ভধারণের আগে কী কী পরীক্ষা করাবেন?

কোন পরীক্ষা লাগবে তা আপনার বয়স, মেডিকেল ইতিহাস ও আগের গর্ভধারণের অভিজ্ঞতার উপর নির্ভর করে। সাধারণত যেগুলো বিবেচনা করা হয়:

- রক্তের গ্রুপ ও Rh ফ্যাক্টর
- সম্পূর্ণ রক্ত পরীক্ষা (CBC) — রক্তস্বল্পতা আছে কিনা
- থাইরয়েড ফাংশন (TSH)
- রুবেলা ইমিউনিটি
- হেপাটাইটিস B, HIV ও সিফিলিস স্ক্রিনিং
- রক্তে শর্করা (ডায়াবেটিস) পরীক্ষা
- প্রয়োজনে আল্ট্রাসনোগ্রাম, যেমন জরায়ু ও ডিম্বাশয় পরীক্ষা

পুরুষ সঙ্গীরও কিছু পরীক্ষা লাগতে পারে, যেমন বীর্য বিশ্লেষণ। দম্পতি হিসেবে একসাথে পরামর্শ নেওয়া ভালো।

## ওজন, জীবনযাত্রা ও টিকা

- **ওজন:** অতিরিক্ত ওজন বা খুব কম ওজন দুটোই গর্ভধারণ ও গর্ভকালীন জটিলতার ঝুঁকি বাড়াতে পারে। সুষম খাদ্য ও নিয়মিত হাঁটা উপকারী।
- **ধূমপান ও অ্যালকোহল:** গর্ভধারণের আগে থেকেই বন্ধ করা উচিত।
- **টিকা:** রুবেলা, টিটেনাস ও প্রয়োজনীয় অন্যান্য টিকা গর্ভধারণের আগে নেওয়া নিরাপদ, তবে কিছু টিকা গর্ভাবস্থায় দেওয়া যায় না। তাই ডাক্তারের সাথে আলোচনা করুন।
- **ওষুধ:** নিয়মিত কোনো ওষুধ খেলে গর্ভধারণের আগে ডাক্তারকে জানান।

## কখন ডাক্তার দেখাবেন?

- এক বছর নিয়মিতভাবে চেষ্টা করেও গর্ভধারণ না হলে (৩৫ বছরের বেশি হলে ৬ মাস)।
- মাসিক অনিয়মিত বা দীর্ঘদিন বন্ধ থাকলে।
- আগে গর্ভপাত, মৃতজন্ম বা শিশুর জন্মগত সমস্যা হয়ে থাকলে।
- দীর্ঘমেয়াদি রোগ, যেমন ডায়াবেটিস, উচ্চ রক্তচাপ বা থাইরয়েড সমস্যা থাকলে।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন

**গর্ভধারণের পর ফলিক অ্যাসিড শুরু করলে কি হবে?**
আগে থেকে শুরু করা সবচেয়ে ভালো। তবে গর্ভধারণের পর যত দ্রুত সম্ভব শুরু করাও উপকারী, তাই দেরি না করে ডাক্তারের পরামর্শ নিন।

**ফলিক অ্যাসিড কি গর্ভধারণের সম্ভাবনা বাড়ায়?**
ফলিক অ্যাসিড প্রধানত শিশুর স্নায়ুনালির ত্রুটি প্রতিরোধে কাজ করে। গর্ভধারণের সম্ভাবনা বাড়ানোর জন্য এটি নির্ধারিত ওষুধ নয়।

## সারসংক্ষেপ

গর্ভধারণের পরিকল্পনা থাকলে অন্তত এক মাস আগে থেকে ফলিক অ্যাসিড শুরু করুন, প্রয়োজনীয় পরীক্ষা করান এবং ডাক্তারের সাথে পরামর্শ নিন। আপনার বয়স ও স্বাস্থ্য অনুযায়ী পরিকল্পনা আলাদা হয়, তাই ব্যক্তিগত পরামর্শের জন্য সরাসরি চিকিৎসকের সাথে যোগাযোগ করুন।

**তথ্যসূত্র:** CDC Folic Acid — https://www.cdc.gov/folic-acid/ · WHO — https://www.who.int', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 0 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM blog_posts WHERE slug = 'preconception-care-folic-acid-before-pregnancy');

INSERT INTO blog_posts (slug, category, tags, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, medically_reviewed_by, published_at)
SELECT 'first-trimester-pregnancy-care-tests', 'গর্ভাবস্থার প্রথম ৩ মাস', 'গর্ভাবস্থার প্রথম ত্রৈমাসিক, NT স্ক্যান, ডেটিং আল্ট্রাসাউন্ড, প্রেগন্যান্সি পরীক্ষা', 'গর্ভাবস্থার প্রথম ৩ মাস: কোন পরীক্ষা কখন করাবেন', 'প্রথম ৩ মাসের পরীক্ষা ও সতর্ক সংকেত', 'গর্ভধারণ নিশ্চিত হলে প্রথম ভিজিটে কী হয়, ডেটিং আল্ট্রাসাউন্ড ও NT স্ক্যান কেন দরকার, এবং কোন লক্ষণে জরুরি চিকিৎসা নিতে হবে।', 'প্রথম ৩ মাসে কোন পরীক্ষা কখন করাবেন, NT স্ক্যান কী এবং কোন লক্ষণে দ্রুত ডাক্তার দেখাবেন — গর্ভবতী মায়ের জন্য সহজ গাইড।', 'গর্ভাবস্থা', '## গর্ভাবস্থার প্রথম ৩ মাস কেন গুরুত্বপূর্ণ?

প্রথম ত্রৈমাসিক (প্রথম ১২-১৩ সপ্তাহ) শিশুর প্রধান অঙ্গগুলোর গঠনের সময়। এই সময়ের স্বাস্থ্যযত্ন এবং সঠিক পরীক্ষা মা ও শিশুর নিরাপত্তার জন্য খুব গুরুত্বপূর্ণ। গর্ভধারণ নিশ্চিত হলে প্রথম সুযোগেই একজন স্ত্রীরোগ বিশেষজ্ঞের কাছে নিবন্ধন করা উচিত।

## প্রথম ভিজিটে কী কী হয়?

- **গর্ভাবস্থা নিশ্চিত করা** এবং শেষ মাসিকের তারিখ (LMP) জেনে নেওয়া
- **ডেটিং আল্ট্রাসাউন্ড:** গর্ভকাল সঠিকভাবে নির্ধারণ ও শিশুর অবস্থান দেখা
- **রক্ত পরীক্ষা:** রক্তের গ্রুপ ও Rh ফ্যাক্টর, সম্পূর্ণ রক্ত পরীক্ষা (CBC), থাইরয়েড, হেপাটাইটিস B, HIV ও সিফিলিস স্ক্রিনিং
- **প্রস্রাব পরীক্ষা** এবং ওজন, রক্তচাপ পরিমাপ
- **ফলিক অ্যাসিড ও আয়রন** শুরু বা নিশ্চিত করা, ডাক্তারের নির্দেশমতো

## নিউক্যাল ট্রান্সলুসেন্সি (NT) স্ক্যান কী?

সাধারণত ১১ থেকে ১৩ সপ্তাহের মধ্যে করা এই আল্ট্রাসাউন্ড শিশুর ঘাড়ের পেছনের স্বচ্ছ স্থানের পরিমাপ দেখে। রক্ত পরীক্ষার সাথে মিলিয়ে ডাউন সিন্ড্রোমসহ কিছু ক্রোমোজোমগত সমস্যার ঝুঁকি মূল্যায়ন করা হয়। এটি একটি স্ক্রিনিং, চূড়ান্ত রোগ নির্ণয় নয়। ফল অস্বাভাবিক এলে আরও পরীক্ষার পরামর্শ দেওয়া হয়।

## প্রথম ত্রৈমাসিকে কী সতর্ক থাকবেন?

প্রথম দিকে বমিভাব, ক্লান্তি ও বুক ভারী লাগা খুব স্বাভাবিক। তবে নিচের লক্ষণগুলো দেখা দিলে দ্রুত চিকিৎসকের কাছে যান:

- যোনিপথে বেশি রক্তপাত বা রক্তের সাথে জমাট বাঁধা
- তীব্র পেটব্যথা, বিশেষ করে এক পাশে
- জ্বর, প্রস্রাবে জ্বালা বা বমি যা খাবার-পানি রাখতে দেয় না
- যোনি দিয়ে পানি বা তরল বের হওয়া

এই লক্ষণগুলো অ্যাক্টোপিক গর্ভ, গর্ভপাতের আশঙ্কা বা সংক্রমণের ইঙ্গিত হতে পারে, তাই অবহেলা করবেন না।

## ওষুধ ও জীবনযাত্রা

- গর্ভাবস্থায় কোনো ওষুধ, হারবাল পণ্য বা ব্যথানাশক নিজে থেকে খাবেন না। প্রয়োজন হলে চিকিৎসককে জানান।
- পর্যাপ্ত পানি, সুষম খাদ্য, এবং হালকা হাঁটা উপকারী।
- কাঁচা বা আধা-সিদ্ধ মাংস, ডিম ও দুগ্ধজাত পণ্য যা পাস্তুরাইজড নয়, এড়িয়ে চলুন।
- ধূমপান ও অ্যালকোহল সম্পূর্ণ বর্জন করুন।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন

**প্রথম ৩ মাসে কি স্বাভাবিকভাবে কাজ করা যায়?**
বেশিরভাগ নারী প্রথম ত্রৈমাসিকে স্বাভাবিক কাজ চালিয়ে যেতে পারেন। তবে ভারী পরিশ্রম বা ঝুঁকিপূর্ণ কাজ থাকলে চিকিৎসকের সাথে আলোচনা করুন।

**প্রথম মাসে শিশুর হৃদস্পন্দন কি দেখা যায়?**
সাধারণত ৫-৬ সপ্তাহের আশেপাশে আল্ট্রাসাউন্ডে হৃদস্পন্দন দেখা যেতে পারে, তবে এটি নির্ভর করে সঠিক গর্ভকালের উপর।

## সারসংক্ষেপ

গর্ভধারণ নিশ্চিত হলে দ্রুত একজন স্ত্রীরোগ বিশেষজ্ঞের কাছে নিবন্ধন করুন। ডেটিং আল্ট্রাসাউন্ড, প্রয়োজনীয় রক্ত পরীক্ষা ও NT স্ক্যান সময়মতো করান, এবং উপরে উল্লেখিত সতর্ক সংকেত দেখা দিলে দেরি না করে চিকিৎসকের কাছে যান। এই লেখাটি সাধারণ তথ্যের জন্য; ব্যক্তিগত পরামর্শের জন্য আপনার চিকিৎসকের সাথে যোগাযোগ করুন।

**তথ্যসূত্র:** ACOG — https://www.acog.org · WHO — https://www.who.int', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 1 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM blog_posts WHERE slug = 'first-trimester-pregnancy-care-tests');

INSERT INTO blog_posts (slug, category, tags, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, medically_reviewed_by, published_at)
SELECT 'gestational-diabetes-screening-and-control', 'গর্ভকালীন ডায়াবেটিস', 'গর্ভকালীন ডায়াবেটিস, OGTT, গর্ভাবস্থায় ডায়াবেটিস, রক্তে শর্করা', 'গর্ভকালীন ডায়াবেটিস: পরীক্ষা, ঝুঁকি ও নিয়ন্ত্রণ', 'গর্ভকালীন ডায়াবেটিস: পরীক্ষা ও নিয়ন্ত্রণ', 'গর্ভকালীন ডায়াবেটিস কী, কোন সময়ে স্ক্রিনিং হয়, ৭৫ গ্রাম OGTT পরীক্ষার পদ্ধতি, ঝুঁকি এবং নিয়ন্ত্রণের উপায়।', 'গর্ভকালীন ডায়াবেটিস কখন পরীক্ষা করাবেন, OGTT কী, এবং খাদ্য ও ব্যায়ামে কীভাবে রক্তে শর্করা নিয়ন্ত্রণে রাখবেন।', 'গর্ভাবস্থা', '## গর্ভকালীন ডায়াবেটিস কী?

গর্ভাবস্থায় প্রথমবার রক্তে শর্করার মাত্রা বেড়ে গেলে তাকে গর্ভকালীন ডায়াবেটিস (Gestational Diabetes Mellitus, GDM) বলা হয়। গর্ভাবস্থার হরমোন ইনসুলিনের কার্যকারিতা কিছুটা কমিয়ে দেয়, আর সেই সময় অগ্ন্যাশয় যথেষ্ট ইনসুলিন তৈরি করতে না পারলে রক্তে শর্করা বাড়ে। সঠিক পরীক্ষা ও নিয়ন্ত্রণ করলে মা ও শিশু দুজনের ঝুঁকি উল্লেখযোগ্যভাবে কমানো যায়।

## কখন পরীক্ষা করাতে হবে?

আন্তর্জাতিক ডায়াবেটিস সংস্থা (ADA) সাধারণত **২৪ থেকে ২৮ সপ্তাহের মধ্যে** সব গর্ভবতী নারীর জন্য গর্ভকালীন ডায়াবেটিস স্ক্রিনিং সুপারিশ করে। তবে নিচের ঝুঁকিগুলো থাকলে প্রথম ভিজিটেই পরীক্ষা করানো ভালো:

- ওজন বেশি বা স্থূলতা
- পিসিওএস (PCOS)
- পরিবারে ডায়াবেটিসের ইতিহাস
- আগের গর্ভে গর্ভকালীন ডায়াবেটিস বা বড় আকারের শিশু জন্ম
- ৩৫ বছরের বেশি বয়স

## পরীক্ষার পদ্ধতি

সবচেয়ে সাধারণ পরীক্ষা হলো **৭৫ গ্রাম গ্লুকোজ টলারেন্স টেস্ট (OGTT)**। প্রথমে খালি পেটে রক্ত নেওয়া হয়, তারপর একটি নির্দিষ্ট পরিমাণ গ্লুকোজ পানিতে গুলে খাওয়ানো হয়, এবং ১ ও ২ ঘণ্টা পর আবার রক্ত নেওয়া হয়। কোন মাত্রাকে স্বাভাবিক ধরা হবে তা ল্যাবের নির্দেশিকা অনুযায়ী ডাক্তার ব্যাখ্যা করবেন। পরীক্ষার আগে সাধারণত কয়েক ঘণ্টা উপোস থাকতে বলা হয়; কখন কী করতে হবে তা আগেই জেনে নিন।

## কেন এটি গুরুত্বপূর্ণ?

নিয়ন্ত্রণ না থাকলে গর্ভকালীন ডায়াবেটিস থেকে নিচের ঝুঁকিগুলো বাড়তে পারে:

- **মায়ের জন্য:** প্রিএক্ল্যাম্পসিয়া (উচ্চ রক্তচাপ), সংক্রমণ, এবং ভবিষ্যতে টাইপ-২ ডায়াবেটিসের ঝুঁকি
- **শিশুর জন্য:** বেশি ওজন নিয়ে জন্ম, জন্মের পর রক্তে শর্করা কমে যাওয়া, এবং অনেক ক্ষেত্রে সিজারিয়ান ডেলিভারির প্রয়োজন

## নিয়ন্ত্রণের উপায়

১. **খাদ্যাভ্যাস:** সুষম খাবার, পর্যাপ্ত আঁশযুক্ত সবজি, মাপা ভাত বা রুটি, এবং মিষ্টি ও চিনিযুক্ত পানীয় কম। প্রতিবার অল্প অল্প করে বেশিবার খাওয়া উপকারী। খাদ্য পরিকল্পনা চিকিৎসক বা ডায়েটিশিয়ানের সাথে ঠিক করুন।
২. **শারীরিক কার্যকলাপ:** চিকিৎসকের অনুমতি সাপেক্ষে প্রতিদিন হাঁটা বা হালকা ব্যায়াম।
৩. **রক্তে শর্করা মাপা:** চিকিৎসকের নির্দেশমতো নিয়মিত পরিমাপ।
৪. **ওষুধ:** খাদ্য ও ব্যায়ামে যথেষ্ট না হলে চিকিৎসক ইনসুলিন বা অন্য ওষুধ লিখতে পারেন। এটি নিরাপদ এবং প্রয়োজনে নেওয়া উচিত।

## নিজে কী খেয়াল রাখবেন?

খুব বেশি তৃষ্ণা, ঘন ঘন প্রস্রাব, ঝাপসা দেখা, অস্বাভাবিক দুর্বলতা বা অতিরিক্ত বমি হলে দ্রুত চিকিৎসকের সাথে যোগাযোগ করুন। রক্তে শর্করা খুব কমে গেলে (হাত কাঁপা, ঘাম, মাথা ঘোরা) মিষ্টি খাবার খেয়ে দ্রুত ডাক্তারকে জানান।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন

**গর্ভকালীন ডায়াবেটিস হলে কি স্বাভাবিক ডেলিভারি সম্ভব?**
হ্যাঁ, সঠিক নিয়ন্ত্রণে বেশিরভাগ নারীর স্বাভাবিক ডেলিভারি হতে পারে। প্রসবের সময় ও পদ্ধতি চিকিৎসক শিশুর ওজন ও মায়ের অবস্থা বিবেচনা করে ঠিক করেন।

**ডেলিভারির পর কি ডায়াবেটিস থাকবে?**
অনেক ক্ষেত্রে ডেলিভারির পর শর্করা স্বাভাবিক হয়ে যায়, তবে ভবিষ্যতে ডায়াবেটিস হওয়ার ঝুঁকি বেশি থাকে। তাই প্রসবের পর ৬-১২ সপ্তাহে আবার পরীক্ষা করানো জরুরি।

## সারসংক্ষেপ

গর্ভকালীন ডায়াবেটিস একটি ব্যবস্থাপনাযোগ্য অবস্থা। ২৪-২৮ সপ্তাহে স্ক্রিনিং করান, নিয়মিত চিকিৎসকের সাথে যোগাযোগ রাখুন, এবং খাদ্য ও শারীরিক কার্যকলাপ ঠিক রাখুন। এই লেখাটি সাধারণ তথ্যের জন্য; আপনার নিজের চিকিৎসার সিদ্ধান্ত চিকিৎসকের পরামর্শে নিন।

**তথ্যসূত্র:** American Diabetes Association Standards of Care — https://diabetesjournals.org/care · ACOG — https://www.acog.org', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 2 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM blog_posts WHERE slug = 'gestational-diabetes-screening-and-control');

INSERT INTO blog_posts (slug, category, tags, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, medically_reviewed_by, published_at)
SELECT 'cervical-cancer-prevention-hpv-test-vaccine', 'জরায়ুমুখ ক্যান্সার প্রতিরোধ', 'জরায়ুমুখ ক্যান্সার, পাপ স্মিয়ার, HPV টেস্ট, HPV টিকা', 'জরায়ুমুখ ক্যান্সার প্রতিরোধ: পাপ স্মিয়ার, HPV টেস্ট ও টিকা', 'জরায়ুমুখ ক্যান্সার প্রতিরোধ গাইড', 'পাপ স্মিয়ার ও HPV টেস্টের পার্থক্য, কখন স্ক্রিনিং করাবেন, HPV টিকা কার জন্য, এবং কোন লক্ষণে দেরি না করে পরীক্ষা করাবেন।', 'জরায়ুমুখ ক্যান্সার প্রতিরোধে পাপ স্মিয়ার, HPV টেস্ট ও HPV টিকার গুরুত্ব — নারীদের জন্য সহজ গাইড।', 'নারী স্বাস্থ্য', '## জরায়ুমুখ ক্যান্সার কী?

জরায়ুমুখ (সার্ভিক্স) জরায়ুর নিচের সরু অংশ, যা যোনিপথের সাথে যুক্ত। জরায়ুমুখ ক্যান্সার সাধারণত **হিউম্যান প্যাপিলোমা ভাইরাস (HPV)**-এর দীর্ঘস্থায়ী সংক্রমণের কারণে হয়। বেশিরভাগ HPV সংক্রমণ নিজে থেকেই সেরে যায়, কিন্তু কিছু ধরনের ভাইরাস দীর্ঘদিন থাকলে কোষের পরিবর্তন ঘটাতে পারে। সঠিক সময়ে স্ক্রিনিং করালে এই পরিবর্তন ক্যান্সার হওয়ার আগেই ধরা যায়।

## কোন পরীক্ষাগুলো আছে?

- **পাপ স্মিয়ার (Pap smear):** জরায়ুমুখের কোষ নমুনা নিয়ে অস্বাভাবিক পরিবর্তন খোঁজা হয়।
- **HPV টেস্ট:** রক্তে নয়, জরায়ুমুখের নমুনায় HPV ভাইরাস আছে কিনা পরীক্ষা করা হয়। এটি ক্যান্সারের ঝুঁকি আরও নির্ভুলভাবে বোঝায়।
- **VIA/VILI:** কিছু স্বাস্থ্যকেন্দ্রে সহজলভ্য দৃশ্যমান পরীক্ষা।

বিশ্ব স্বাস্থ্য সংস্থা (WHO) ৩০ থেকে ৪৯ বছর বয়সী নারীদের জন্য **HPV টেস্ট**-কে প্রাধান্য দেওয়ার সুপারিশ করে, সাধারণত প্রতি ৫ বছর বা তার কম সময় পর পর। আপনার বয়স ও আগের ফলাফল অনুযায়ী সময়সূচি চিকিৎসক ঠিক করবেন।

## HPV টিকা কার জন্য?

HPV টিকা HPV সংক্রমণ প্রতিরোধে কার্যকর। WHO কিশোরী মেয়েদের (সাধারণত ৯-১৪ বছর বয়সে) টিকা নেওয়ার সুপারিশ করে। বয়সের কারণে বা অন্য কোনো কারণে টিকা না নিয়ে থাকলে চিকিৎসকের সাথে আলোচনা করুন; বয়সভেদে বিবেচনা আলাদা হতে পারে। টিকা নিলেও নিয়মিত স্ক্রিনিং প্রয়োজন, কারণ টিকা সব ধরনের HPV-কে ঢাকে না।

## কখন দ্রুত ডাক্তার দেখাবেন?

স্ক্রিনিংয়ের সময় না হলেও নিচের লক্ষণগুলো দেখা দিলে দেরি করবেন না:

- সহবাসের পর বা দুই মাসিকের মাঝে রক্তপাত
- মেনোপজের পর রক্তপাত
- অস্বাভাবিক দুর্গন্ধযুক্ত বা রক্তমিশ্রিত স্রাব
- সহবাসের সময় ব্যথা
- তলপেটে দীর্ঘস্থায়ী ব্যথা বা ওজন কমে যাওয়া

এই লক্ষণগুলো জরায়ুমুখের সমস্যা, সংক্রমণ বা অন্য রোগের ইঙ্গিত হতে পারে, তাই পরীক্ষা করিয়ে নেওয়া জরুরি।

## স্ক্রিনিং কেন জরুরি?

প্রাথমিক পর্যায়ে ধরা পড়লে চিকিৎসা অনেক সহজ এবং ফলাফল ভালো হয়। অনেক ক্ষেত্রে অস্বাভাবিক কোষ অপসারণের ছোট চিকিৎসা দিয়েই সমস্যার সমাধান হয়। তাই লক্ষণ না থাকলেও নির্ধারিত সময়ে স্ক্রিনিং করানো নিজের স্বাস্থ্যের জন্য সবচেয়ে কার্যকর পদক্ষেপ।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন

**বিবাহিত না হলে কি এই পরীক্ষা লাগবে?**
HPV সংক্রমণ যৌন সম্পর্কের মাধ্যমে ছড়ায়। আপনার অবস্থা ও প্রয়োজন চিকিৎসক বুঝে পরামর্শ দেবেন।

**পাপ স্মিয়ার কি ব্যথা দেয়?**
সাধারণত সামান্য অস্বস্তি হয়, তবে তীব্র ব্যথা হয় না। পরীক্ষার আগে চিকিৎসককে জানালে তিনি আরামদায়কভাবে করতে সাহায্য করবেন।

**পরীক্ষা নেতিবাচক এলে কি আর পরীক্ষা লাগবে না?**
নির্ধারিত সময় পর আবার স্ক্রিনিং প্রয়োজন হতে পারে। চিকিৎসক আপনাকে সঠিক সময়সূচি জানাবেন।

## সারসংক্ষেপ

জরায়ুমুখ ক্যান্সার প্রতিরোধযোগ্য। নিয়মিত স্ক্রিনিং, বয়স উপযোগী HPV টিকা, এবং অস্বাভাবিক লক্ষণে দ্রুত পরামর্শ — এই তিনটি সবচেয়ে কার্যকর পদক্ষেপ। এই লেখাটি সাধারণ সচেতনতার জন্য; ব্যক্তিগত পরীক্ষা ও সিদ্ধান্তের জন্য চিকিৎসকের সাথে কথা বলুন।

**তথ্যসূত্র:** WHO Cervical cancer fact sheet — https://www.who.int/news-room/fact-sheets/detail/cervical-cancer · ACOG — https://www.acog.org', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 3 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM blog_posts WHERE slug = 'cervical-cancer-prevention-hpv-test-vaccine');

INSERT INTO blog_posts (slug, category, tags, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, medically_reviewed_by, published_at)
SELECT 'ivf-treatment-what-to-know', 'আইভিএফ চিকিৎসা', 'আইভিএফ, IVF, ইন ভিট্রো ফার্টিলাইজেশন, বন্ধ্যাত্ব চিকিৎসা', 'আইভিএফ (IVF) কী? কাদের লাগে, ধাপ ও বাস্তব প্রত্যাশা', 'আইভিএফ (IVF): ধাপ ও বাস্তব প্রত্যাশা', 'আইভিএফ বা ইন ভিট্রো ফার্টিলাইজেশন কী, কাদের লাগে, চিকিৎসার ধাপ, সম্ভাব্য ঝুঁকি এবং সাফল্য নিয়ে বাস্তবসম্মত ধারণা।', 'আইভিএফ কী, কাদের জন্য উপযুক্ত, চিকিৎসার ধাপগুলো কী, এবং ঝুঁকি ও বাস্তব প্রত্যাশা নিয়ে সহজ আলোচনা।', 'বন্ধ্যাত্ব', '## আইভিএফ (IVF) কী?

IVF বা ইন ভিট্রো ফার্টিলাইজেশন এমন একটি চিকিৎসা, যেখানে ডিম্বাণু ও শুক্রাণু শরীরের বাইরে ল্যাবরেটরিতে মিলিত করা হয়। তৈরি হওয়া ভ্রূণ কয়েকদিন পর জরায়ুতে স্থাপন করা হয়। বন্ধ্যাত্বের অনেক কারণে, যেমন ফ্যালোপিয়ান টিউবের সমস্যা, পুরুষের শুক্রাণুর মান, বা ডিম্বস্ফোটনের অনিয়ম, এই পদ্ধতি সহায়ক হতে পারে। তবে সব দম্পতির জন্য এটি প্রথম বা একমাত্র পথ নয়।

## কাদের জন্য উপযুক্ত?

- দীর্ঘদিন চেষ্টা করেও গর্ভধারণ না হলে এবং অন্য চিকিৎসায় কাজ না হলে
- ফ্যালোপিয়ান টিউব বন্ধ বা ক্ষতিগ্রস্ত থাকলে
- পুরুষের শুক্রাণুর সংখ্যা বা গতি কম হলে (প্রয়োজনে ICSI পদ্ধতি)
- বয়স বেশি হলে বা ডিম্বাণুর সংখ্যা কমে গেলে
- এন্ডোমেট্রিওসিসের মতো অবস্থায়

কখন IVF উপযুক্ত তা দম্পতির বয়স, সব পরীক্ষার ফলাফল এবং সামগ্রিক স্বাস্থ্য বিবেচনা করে চিকিৎসক ঠিক করেন।

## ধাপগুলো কী কী?

১. **প্রাথমিক মূল্যায়ন:** দুজনের রক্ত পরীক্ষা, হরমোন মাপা, এবং আল্ট্রাসাউন্ড।
২. **ডিম্বাশয় উদ্দীপনা:** কয়েকটি ডিম্বাণু একসাথে পরিপক্ব করতে ইনজেকশন ও ওষুধ দেওয়া হয়। এর সময় নিয়মিত আল্ট্রাসাউন্ড ও রক্ত পরীক্ষা হয়।
৩. **ডিম্বাণু সংগ্রহ:** হালকা অজ্ঞান বা সেডেশনে ছোট একটি প্রক্রিয়ায় পরিপক্ব ডিম্বাণু সংগ্রহ করা হয়।
৪. **ল্যাবে নিষিক্তকরণ:** ডিম্বাণু ও শুক্রাণু ল্যাবে মেশানো হয়, প্রয়োজনে সরাসরি একটি শুক্রাণু ডিম্বাণুতে প্রবেশ করানো হয় (ICSI)।
৫. **ভ্রূণ স্থানান্তর:** নিষিক্ত ভ্রূণ জরায়ুতে স্থাপন করা হয়। এটি সাধারণত ব্যথাহীন।
৬. **গর্ভ পরীক্ষা:** প্রায় দুই সপ্তাহ পর রক্ত পরীক্ষায় গর্ভধারণ নিশ্চিত করা হয়।

## বাস্তব প্রত্যাশা কী হওয়া উচিত?

IVF-এর সাফল্য একেকজনের ক্ষেত্রে আলাদা। বয়স, ডিম্বাণুর মান, বন্ধ্যাত্বের কারণ এবং ক্লিনিকের অভিজ্ঞতা সবকিছু প্রভাব ফেলে। বয়স বাড়লে সাধারণত সাফল্যের হার কমে, এবং অনেক দম্পতিকে একাধিকবার চেষ্টা করতে হয়। কোনো ক্লিনিক যদি নিশ্চিত সাফল্যের গ্যারান্টি দেয়, তবে সেটি নিয়ে সতর্ক থাকুন। আপনার ক্ষেত্রে সম্ভাব্য ফলাফল কেমন হতে পারে তা চিকিৎসকের সাথে আলোচনা করে জেনে নিন।

## ঝুঁকি ও জটিলতা

- **ডিম্বাশয় অতি-উদ্দীপনা (OHSS):** ওষুধের প্রতিক্রিয়ায় পেট ফোলা, ব্যথা বা অস্বস্তি হতে পারে। গুরুতর ক্ষেত্রে চিকিৎসার প্রয়োজন হয়।
- **একাধিক গর্ভ:** একসাথে একাধিক ভ্রূণ স্থাপনের কারণে যমজ বা তার বেশি গর্ভ হতে পারে, যা মা ও শিশু উভয়ের জন্য ঝুঁকিপূর্ণ। তাই আধুনিক পদ্ধতিতে সাধারণত একটি ভ্রূণ স্থাপনের চেষ্টা করা হয়।
- **মানসিক চাপ:** বারবার প্রচেষ্টা ও আর্থিক খরচের কারণে মানসিক চাপ হতে পারে। সঙ্গী ও চিকিৎসকের সাথে খোলামেলা কথা বলা সহায়ক।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন

**IVF-এর খরচ কেমন?**
খরচ ক্লিনিক, চিকিৎসার ধরন ও প্রয়োজনীয় ওষুধের উপর নির্ভর করে। শুরুতেই বিস্তারিত খরচের তালিকা চেয়ে নিন।

**IVF কি ব্যথাদায়ক?**
ডিম্বাণু সংগ্রহের সময় সেডেশন দেওয়া হয়, তাই সাধারণত ব্যথা কম হয়। পরবর্তীতে হালকা অস্বস্তি হতে পারে।

**IVF কি ব্যর্থ হলে আর সুযোগ নেই?**
একটি চেষ্টা সফল না হলেও চিকিৎসক কারণ বিশ্লেষণ করে পরবর্তী পরিকল্পনা ঠিক করতে পারেন।

## সারসংক্ষেপ

IVF বন্ধ্যাত্বের একটি কার্যকর চিকিৎসা হতে পারে, তবে এর জন্য সঠিক মূল্যায়ন, বাস্তব প্রত্যাশা ও চিকিৎসকের নিয়মিত তত্ত্বাবধান দরকার। প্রথমে কারণ জানুন, সব বিকল্প বুঝুন, তারপর সিদ্ধান্ত নিন। ব্যক্তিগত পরামর্শের জন্য খুলনায় অ্যাপয়েন্টমেন্ট নিয়ে সরাসরি আলোচনা করুন।

**তথ্যসূত্র:** ASRM (Reproductive Facts) — https://www.reproductivefacts.org · ESHRE — https://www.eshre.eu', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 4 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM blog_posts WHERE slug = 'ivf-treatment-what-to-know');


-- ---- Blog posts (10 published; from old migrations 004/008/010 + 006 + 009) ----
INSERT IGNORE INTO blog_posts (slug, title, excerpt, body, cover_image, status, published_at) VALUES ('pcos-treatment-guide', 'PCOS (পলিসিস্টিক ওভারি সিন্ড্রোম): কারণ, লক্ষণ ও চিকিৎসা', 'PCOS বা পলিসিস্টিক ওভারি সিন্ড্রোম কী, কেন হয়, লক্ষণ কী কী এবং আধুনিক চিকিৎসায় কীভাবে নিয়ন্ত্রণ করা যায় — বিস্তারিত জানুন।', 'PCOS বা পলিসিস্টিক ওভারি সিন্ড্রোম নারীদের একটি সাধারণ হরমোনজনিত সমস্যা, যা প্রজনন বয়সের নারীদের একটি উল্লেখযোগ্য অংশকে প্রভাবিত করে। এটি শুধু মাসিক চক্রের সমস্যা নয় — এর প্রভাব পড়তে পারে ওজন, ত্বক, চুল এবং ভবিষ্যতে গর্ভধারণের সক্ষমতার উপরেও।

## PCOS কী?

PCOS একটি হরমোনজনিত অবস্থা, যেখানে ডিম্বাশয়ে পুরুষ হরমোন (অ্যান্ড্রোজেন) স্বাভাবিকের চেয়ে বেশি তৈরি হয়। এর ফলে ডিম্বস্ফোটন (ovulation) অনিয়মিত হয়ে পড়ে এবং ডিম্বাশয়ে ছোট ছোট সিস্টের মতো গঠন দেখা দিতে পারে।

## সাধারণ লক্ষণসমূহ

- অনিয়মিত মাসিক বা মাসিক বন্ধ থাকা
- মুখে ও শরীরে অতিরিক্ত লোম (হারসুটিজম)
- ব্রণ ও তৈলাক্ত ত্বক
- অতিরিক্ত ওজন বৃদ্ধি, বিশেষত পেটের অংশে
- চুল পাতলা হয়ে যাওয়া
- গর্ভধারণে বিলম্ব বা অসুবিধা

সব লক্ষণ একসাথে সবার মধ্যে দেখা যায় না — একেকজনের ক্ষেত্রে তীব্রতা একেক রকম হতে পারে।

## কেন হয়?

সঠিক কারণ এখনও সম্পূর্ণ জানা না গেলেও কয়েকটি বিষয় গুরুত্বপূর্ণ ভূমিকা রাখে বলে মনে করা হয়:

- **ইনসুলিন রেজিস্ট্যান্স** — শরীর ইনসুলিন ঠিকভাবে ব্যবহার করতে না পারা, যা অ্যান্ড্রোজেন উৎপাদন বাড়িয়ে দেয়
- **বংশগত প্রবণতা** — পরিবারে কারও থাকলে ঝুঁকি বেশি
- **ওজন ও জীবনযাত্রা** — অতিরিক্ত ওজন উপসর্গ বাড়াতে পারে

## নির্ণয় কীভাবে হয়?

সাধারণত তিনটি বিষয় মূল্যায়ন করে PCOS নির্ণয় করা হয়: মাসিকের ধরন, রক্ত পরীক্ষায় হরমোনের মাত্রা, এবং আল্ট্রাসনোগ্রামে ডিম্বাশয়ের গঠন। তিনটির মধ্যে অন্তত দুটি মিললে PCOS নির্ণয় করা হয়।

## চিকিৎসা

PCOS-এর চিকিৎসা নির্ভর করে রোগীর বয়স, লক্ষণ এবং তিনি গর্ভধারণ করতে চান কিনা তার উপর।

### জীবনযাত্রায় পরিবর্তন

সুষম খাদ্যাভ্যাস ও নিয়মিত ব্যায়ামের মাধ্যমে ওজন ৫-১০% কমানো গেলেও অনেক ক্ষেত্রে মাসিক নিয়মিত হতে শুরু করে এবং উপসর্গ কমে।

### ওষুধ

মাসিক নিয়ন্ত্রণে হরমোনাল চিকিৎসা, ইনসুলিন সংবেদনশীলতা বাড়াতে নির্দিষ্ট ওষুধ, এবং গর্ভধারণে সহায়তার জন্য ডিম্বস্ফোটন উদ্দীপক ওষুধ ব্যবহার করা হয় — চিকিৎসকের পরামর্শ অনুযায়ী।

### দীর্ঘমেয়াদী পর্যবেক্ষণ

PCOS থাকা নারীদের ভবিষ্যতে টাইপ-২ ডায়াবেটিস ও হৃদরোগের ঝুঁকি কিছুটা বেশি থাকে বলে নিয়মিত স্বাস্থ্য পরীক্ষা গুরুত্বপূর্ণ।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**PCOS থাকলে কি গর্ভধারণ সম্ভব?**
হ্যাঁ, সঠিক চিকিৎসা ও পর্যবেক্ষণে PCOS থাকা অনেক নারীই স্বাভাবিকভাবে বা চিকিৎসা সহায়তায় গর্ভধারণ করেন।

**PCOS কি সম্পূর্ণ সেরে যায়?**
PCOS একটি দীর্ঘমেয়াদী অবস্থা, তবে সঠিক ব্যবস্থাপনায় লক্ষণ ভালোভাবে নিয়ন্ত্রণে রাখা যায়।

**কখন ডাক্তার দেখানো উচিত?**
মাসিক অনিয়মিত হলে, দীর্ঘদিন গর্ভধারণ না হলে, বা অস্বাভাবিক লোম/ব্রণ বৃদ্ধি পেলে দ্রুত একজন স্ত্রীরোগ বিশেষজ্ঞের পরামর্শ নেওয়া উচিত।

## সারসংক্ষেপ

PCOS সাধারণ কিন্তু ব্যবস্থাপনাযোগ্য একটি সমস্যা। প্রাথমিক পর্যায়ে শনাক্ত করা গেলে জীবনযাত্রার পরিবর্তন ও সঠিক চিকিৎসার মাধ্যমে ভালো ফলাফল পাওয়া সম্ভব। এই লেখাটি সাধারণ তথ্যের উদ্দেশ্যে — ব্যক্তিগত পরামর্শের জন্য সরাসরি চিকিৎসকের সাথে যোগাযোগ করুন।', '/uploads/cover-pcos-treatment-guide.jpg', 'published', NOW());

INSERT IGNORE INTO blog_posts (slug, title, excerpt, body, cover_image, status, published_at) VALUES ('normal-delivery-vs-c-section', 'নরমাল ডেলিভারি নাকি সিজারিয়ান? কোনটি কখন প্রয়োজন', 'নরমাল ডেলিভারি ও সিজারিয়ান সেকশনের পার্থক্য, কোন পরিস্থিতিতে কোনটি বেছে নেওয়া হয়, এবং উভয়ের ঝুঁকি-সুবিধা সম্পর্কে জানুন।', 'গর্ভাবস্থার শেষ পর্যায়ে প্রায় প্রতিটি পরিবারের মনেই একটি প্রশ্ন ঘোরে — সন্তান জন্ম হবে স্বাভাবিকভাবে (নরমাল ডেলিভারি), নাকি অস্ত্রোপচারের মাধ্যমে (সিজারিয়ান সেকশন)। সিদ্ধান্তটা মা ও শিশুর নিরাপত্তার উপর নির্ভর করে নেওয়া হয়, ব্যক্তিগত পছন্দের উপর নয়।

## নরমাল ডেলিভারি কী?

নরমাল বা যোনিপথে প্রসব হলো প্রসবের স্বাভাবিক প্রক্রিয়া, যেখানে জরায়ুর সংকোচনের মাধ্যমে শিশু যোনিপথ দিয়ে জন্ম নেয়। এটি সাধারণত দ্রুত সেরে ওঠা এবং কম জটিলতার সাথে সম্পর্কিত।

## সিজারিয়ান সেকশন কী?

সিজারিয়ান একটি অস্ত্রোপচার প্রক্রিয়া, যেখানে পেট ও জরায়ুতে ছোট অস্ত্রোপচার করে শিশুকে বের করে আনা হয়। মা বা শিশুর নিরাপত্তার প্রয়োজনে, অথবা নরমাল ডেলিভারিতে জটিলতা দেখা দিলে এটি করা হয়।

## কখন সিজারিয়ান প্রয়োজন হতে পারে

- শিশুর অবস্থান স্বাভাবিক না হওয়া (যেমন ব্রীচ পজিশন)
- প্লাসেন্টা জরায়ুমুখ ঢেকে রাখা (প্লাসেন্টা প্রিভিয়া)
- প্রসব দীর্ঘ সময় ধরে না এগোনো
- শিশুর হৃদস্পন্দনে জটিলতার লক্ষণ
- মায়ের উচ্চ রক্তচাপ বা অন্য কোনো ঝুঁকিপূর্ণ অবস্থা
- আগের গর্ভাবস্থায় সিজারিয়ান হয়ে থাকলে নির্দিষ্ট পরিস্থিতিতে

## দুটোর মধ্যে পার্থক্য

**সুস্থ হতে সময়:** নরমাল ডেলিভারিতে সাধারণত দ্রুত সুস্থ হওয়া যায়; সিজারিয়ানে তুলনামূলক বেশি সময় লাগে।

**হাসপাতালে থাকার সময়:** নরমাল ডেলিভারিতে কম, সিজারিয়ানে কিছুটা বেশি।

**পরবর্তী গর্ভাবস্থা:** নরমাল ডেলিভারির পর সাধারণত জটিলতা কম থাকে; সিজারিয়ানের পর পরবর্তী গর্ভাবস্থার পরিকল্পনা একটু ভেবে করতে হয়।

**সময়সীমা:** নরমাল ডেলিভারি স্বাভাবিকভাবে শুরু হয়ে কিছুটা অপ্রত্যাশিত সময় নেয়; সিজারিয়ান নির্দিষ্ট সময়ে পরিকল্পনামাফিক সম্পন্ন করা যায়।

## সিদ্ধান্ত কীভাবে নেওয়া হয়?

গর্ভাবস্থা চলাকালীন নিয়মিত চেকআপ, আল্ট্রাসনোগ্রাম এবং প্রসবের সময়কার পর্যবেক্ষণের ভিত্তিতে চিকিৎসক সিদ্ধান্ত নেন কোন পদ্ধতি মা ও শিশুর জন্য সবচেয়ে নিরাপদ। অনেক সময় প্রসব শুরু হওয়ার পরও পরিস্থিতি অনুযায়ী পরিকল্পনা বদলাতে হতে পারে — এটি স্বাভাবিক এবং সম্পূর্ণ চিকিৎসাগত সিদ্ধান্ত।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**সিজারিয়ান কি নরমাল ডেলিভারির চেয়ে বেশি ঝুঁকিপূর্ণ?**
সিজারিয়ান একটি অস্ত্রোপচার হওয়ায় এতে কিছু বাড়তি ঝুঁকি থাকে, তবে প্রয়োজনের সময় এটিই মা ও শিশুর জন্য সবচেয়ে নিরাপদ পথ।

**একবার সিজারিয়ান হলে কি পরের বার সবসময় সিজারিয়ানই করতে হয়?**
সবসময় নয় — নির্দিষ্ট শর্ত পূরণ হলে পরবর্তী গর্ভাবস্থায় নরমাল ডেলিভারির চেষ্টা করা সম্ভব হতে পারে; এটি চিকিৎসকের মূল্যায়নের উপর নির্ভর করে।

**কোন পদ্ধতি বেছে নেওয়া রোগীর নিজের সিদ্ধান্ত?**
চূড়ান্ত সিদ্ধান্ত সবসময় মা ও শিশুর চিকিৎসাগত অবস্থার উপর ভিত্তি করে নেওয়া উচিত, যা একজন অভিজ্ঞ প্রসূতি বিশেষজ্ঞই ভালো বলতে পারেন।

## সারসংক্ষেপ

নরমাল ডেলিভারি ও সিজারিয়ান — দুটোই নিরাপদ প্রসব পদ্ধতি, যখন তা সঠিক পরিস্থিতিতে ও যোগ্য চিকিৎসকের তত্ত্বাবধানে করা হয়। গর্ভাবস্থায় নিয়মিত চেকআপ করানোই সবচেয়ে গুরুত্বপূর্ণ, যাতে সময়মতো সঠিক সিদ্ধান্ত নেওয়া যায়।', '/uploads/cover-normal-delivery-vs-c-section.jpg', 'published', NOW());

INSERT IGNORE INTO blog_posts (slug, title, excerpt, body, cover_image, status, published_at) VALUES ('white-discharge-causes-and-when-to-worry', 'সাদা স্রাব: কখন স্বাভাবিক, কখন চিকিৎসকের কাছে যাবেন', 'সাদা স্রাব বেশিরভাগ সময় স্বাভাবিক হলেও কিছু ক্ষেত্রে এটি সংক্রমণ বা অন্য সমস্যার লক্ষণ হতে পারে — পার্থক্য বোঝার উপায় জানুন।', 'সাদা স্রাব নিয়ে অনেক নারীই দ্বিধায় থাকেন — এটা কি স্বাভাবিক, নাকি কোনো সমস্যার লক্ষণ? বাস্তবে, সামান্য সাদা স্রাব যোনিপথের স্বাভাবিক পরিষ্কার-পরিচ্ছন্নতা প্রক্রিয়ার অংশ। তবে এর রং, গন্ধ বা পরিমাণে পরিবর্তন এলে তা মনোযোগ দেওয়ার প্রয়োজন হতে পারে।

## স্বাভাবিক সাদা স্রাব কেমন হয়?

স্বাভাবিক স্রাব সাধারণত —

- রং স্বচ্ছ থেকে সাদাটে
- গন্ধহীন বা খুব হালকা গন্ধযুক্ত
- মাসিক চক্রের বিভিন্ন সময়ে পরিমাণে কিছুটা হেরফের হয় (ডিম্বস্ফোটনের সময় বেশি হতে পারে)
- চুলকানি বা জ্বালাপোড়া থাকে না

## কখন এটি সমস্যার লক্ষণ হতে পারে

নিচের যেকোনো পরিবর্তন দেখা দিলে সতর্ক হওয়া উচিত:

- স্রাবের রং হলুদ, সবুজ বা ধূসর হয়ে যাওয়া
- দুর্গন্ধযুক্ত স্রাব
- সাথে চুলকানি, জ্বালাপোড়া বা ব্যথা
- পরিমাণ হঠাৎ অনেক বেড়ে যাওয়া
- তলপেটে ব্যথা বা জ্বর

## সম্ভাব্য কারণ

- **ইস্ট ইনফেকশন** — চুলকানি ও ঘন সাদা স্রাবের সাধারণ কারণ
- **ব্যাকটেরিয়াল ভ্যাজাইনোসিস** — গন্ধযুক্ত স্রাবের অন্যতম কারণ
- **যৌনবাহিত সংক্রমণ** — কিছু ক্ষেত্রে স্রাবের পরিবর্তনের কারণ হতে পারে
- **হরমোনজনিত পরিবর্তন** — গর্ভাবস্থা, জন্মনিয়ন্ত্রণ পদ্ধতি বা মাসিক চক্রের কারণে

## করণীয়

- অন্তর্বাস প্রতিদিন পরিবর্তন করুন ও সুতির অন্তর্বাস ব্যবহার করুন
- অতিরিক্ত সাবান বা সুগন্ধিযুক্ত পণ্য দিয়ে যোনিপথ পরিষ্কার করা এড়িয়ে চলুন — এটি স্বাভাবিক ব্যাকটেরিয়ার ভারসাম্য নষ্ট করতে পারে
- উপসর্গ ২-৩ দিনের বেশি স্থায়ী হলে বা তীব্র হলে চিকিৎসকের পরামর্শ নিন

স্ব-চিকিৎসা হিসেবে দোকান থেকে ওষুধ কিনে ব্যবহার করার আগে সঠিক কারণ নির্ণয় জরুরি, কারণ ভুল ওষুধ সমস্যা আরও জটিল করে তুলতে পারে।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**সাদা স্রাব কি সবসময় সংক্রমণের লক্ষণ?**
না। হালকা, গন্ধহীন সাদা স্রাব সাধারণত স্বাভাবিক এবং চিন্তার কারণ নয়।

**গর্ভাবস্থায় সাদা স্রাব বেড়ে যাওয়া কি স্বাভাবিক?**
হ্যাঁ, গর্ভাবস্থায় হরমোনের পরিবর্তনের কারণে স্রাব কিছুটা বেড়ে যাওয়া সাধারণ ব্যাপার — তবে রং বা গন্ধে পরিবর্তন এলে চিকিৎসকের পরামর্শ নেওয়া উচিত।

**কখন দেরি না করে ডাক্তার দেখানো জরুরি?**
দুর্গন্ধযুক্ত স্রাব, তীব্র চুলকানি, তলপেটে ব্যথা বা জ্বরসহ স্রাব দেখা দিলে দ্রুত চিকিৎসকের পরামর্শ নেওয়া উচিত।

## সারসংক্ষেপ

সাদা স্রাব বেশিরভাগ ক্ষেত্রে স্বাভাবিক শারীরবৃত্তীয় প্রক্রিয়া। তবে রং, গন্ধ বা উপসর্গে পরিবর্তন এলে তা উপেক্ষা না করে একজন স্ত্রীরোগ বিশেষজ্ঞের পরামর্শ নেওয়াই নিরাপদ। এই লেখাটি সাধারণ তথ্যের উদ্দেশ্যে, ব্যক্তিগত রোগনির্ণয়ের বিকল্প নয়।', '/uploads/cover-white-discharge-causes-and-when-to-worry.jpg', 'published', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('infertility-causes-and-treatment-khulna', 'বন্ধ্যাত্ব: কারণ, পরীক্ষা ও চিকিৎসার সহজ ব্যাখ্যা', 'বন্ধ্যাত্ব চিকিৎসা — কারণ, পরীক্ষা ও সমাধান | ডাঃ জেবুন্নেসা জেবু', 'বন্ধ্যাত্বের কারণ কী, স্বামী-স্ত্রী উভয়ের কোন পরীক্ষা প্রয়োজন এবং IVF সহ আধুনিক চিকিৎসা পদ্ধতি সম্পর্কে বিস্তারিত জানুন।', 'বন্ধ্যাত্বের কারণ কী, স্বামী-স্ত্রী উভয়ের কোন পরীক্ষা প্রয়োজন এবং IVF সহ আধুনিক চিকিৎসা পদ্ধতি সম্পর্কে বিস্তারিত জানুন।', 'বন্ধ্যাত্ব চিকিৎসা', 'সন্তান না হওয়ার দুশ্চিন্তা একটা দম্পতির জন্য মানসিকভাবে অনেক কঠিন একটা সময়। ভালো খবর হলো — বন্ধ্যাত্বের প্রায় সব কারণই আজকাল চিহ্নিত করা যায়, এবং বেশিরভাগ ক্ষেত্রেই চিকিৎসা সম্ভব।

## বন্ধ্যাত্ব কাকে বলে?

সাধারণত এক বছর নিয়মিত (গর্ভনিরোধক ছাড়া) চেষ্টা করার পরও গর্ভধারণ না হলে তাকে বন্ধ্যাত্ব বলা হয়। স্ত্রীর বয়স ৩৫-এর বেশি হলে ৬ মাস পরই পরীক্ষা শুরু করা ভালো।

## এটা কি শুধু নারীর সমস্যা?

না — এটা একটা সাধারণ ভুল ধারণা। বন্ধ্যাত্বের কারণ:

- প্রায় **এক-তৃতীয়াংশ ক্ষেত্রে নারীর সমস্যা**
- প্রায় **এক-তৃতীয়াংশ ক্ষেত্রে পুরুষের সমস্যা** (শুক্রাণুর সংখ্যা বা গুণগত মান কম)
- বাকি ক্ষেত্রে **উভয়ের মিলিত কারণ**, অথবা কারণ অজানা থেকে যায়

তাই চিকিৎসা শুরুর আগে দুজনেরই পরীক্ষা হওয়া জরুরি।

## নারীর সাধারণ কারণ

- ডিম্বস্ফোটন না হওয়া (PCOS-এর কারণে প্রায়ই)
- ফ্যালোপিয়ান টিউব বন্ধ থাকা
- এন্ডোমেট্রিওসিস
- জরায়ুর ফাইব্রয়েড বা গঠনগত সমস্যা
- বয়সজনিত ডিম্বাশয় ফাংশন কমে যাওয়া

## পুরুষের সাধারণ কারণ

- শুক্রাণুর সংখ্যা কম
- শুক্রাণুর গতিশীলতা কম
- হরমোনজনিত সমস্যা

## কোন পরীক্ষা প্রয়োজন?

স্ত্রীর জন্য: হরমোন পরীক্ষা, আল্ট্রাসনোগ্রাম, প্রয়োজনে টিউব পরীক্ষা (HSG)।
স্বামীর জন্য: সিমেন অ্যানালাইসিস (শুক্রাণু পরীক্ষা) — এটা সহজ ও ব্যথাহীন একটা পরীক্ষা, কিন্তু অনেক পরিবারই লজ্জায় করাতে দেরি করে ফেলেন।

## চিকিৎসার ধাপ

চিকিৎসা নির্ভর করে কারণের উপর:

- **ডিম্বস্ফোটন সমস্যায়**: ওষুধ দিয়ে ডিম্বস্ফোটন নিয়ন্ত্রণ
- **টিউব সমস্যায়**: প্রয়োজনে ল্যাপারোস্কোপিক সার্জারি
- **হালকা পুরুষ-ফ্যাক্টরে**: IUI (Intrauterine Insemination)
- **জটিল ক্ষেত্রে**: IVF (In Vitro Fertilization) — যেখানে ডিম্বাণু ও শুক্রাণু ল্যাবে মিলিত করে ভ্রূণ জরায়ুতে বসানো হয়

## মানসিক দিকটাও গুরুত্বপূর্ণ

বন্ধ্যাত্বের চিকিৎসা কিছুটা সময়সাপেক্ষ হতে পারে। ধৈর্য ধরে চিকিৎসকের পরামর্শ মেনে চলা এবং দম্পতি একে অপরকে মানসিক সহায়তা দেওয়াটা চিকিৎসার সাফল্যের জন্য সমান গুরুত্বপূর্ণ।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**বন্ধ্যাত্ব কি সবসময় স্থায়ী সমস্যা?**
না, বেশিরভাগ ক্ষেত্রেই এটা চিকিৎসাযোগ্য। কারণ নির্ণয়ের পর সঠিক চিকিৎসায় অনেক দম্পতিই সন্তান লাভ করেন।

**প্রথমেই কি IVF করাতে হবে?**
না। প্রথমে কারণ নির্ণয় করে সবচেয়ে সহজ ও কম খরচের চিকিৎসা থেকে শুরু করা হয়, প্রয়োজন হলে ধাপে ধাপে IVF-এর দিকে যাওয়া হয়।

**স্বামীর পরীক্ষা করাতে কী লজ্জার কিছু আছে?**
একেবারেই না। এটা একটা স্বাভাবিক চিকিৎসাগত পরীক্ষা, এবং বন্ধ্যাত্বের কারণ নির্ণয়ে এটা অপরিহার্য।

## সারসংক্ষেপ

বন্ধ্যাত্ব একা বহন করার মতো বোঝা না — এটা একটা চিকিৎসাযোগ্য অবস্থা যেখানে দম্পতির উভয়ের মূল্যায়ন প্রয়োজন। দ্রুত পরামর্শ নিলে চিকিৎসার সময়ও কমে আসে। এই লেখাটি সাধারণ তথ্যের উদ্দেশ্যে, ব্যক্তিগত মূল্যায়নের বিকল্প নয়।', '/uploads/cover-infertility-causes-and-treatment-khulna.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('endometriosis-symptoms-and-treatment', 'এন্ডোমেট্রিওসিস: কেন হয়, লক্ষণ ও চিকিৎসা', 'এন্ডোমেট্রিওসিস চিকিৎসা — লক্ষণ ও কারণ | ডাঃ জেবুন্নেসা জেবু', 'এন্ডোমেট্রিওসিস কী, কেন তীব্র মাসিক ব্যথা হয়, এবং কীভাবে নির্ণয় ও চিকিৎসা করা হয় তা বিস্তারিত জানুন।', 'এন্ডোমেট্রিওসিস কী, কেন তীব্র মাসিক ব্যথা হয়, এবং কীভাবে নির্ণয় ও চিকিৎসা করা হয় তা বিস্তারিত জানুন।', 'এন্ডোমেট্রিওসিস চিকিৎসা', '"প্রতি মাসে এমন ব্যথা হয় যে কাজে যেতে পারি না" — এটা এন্ডোমেট্রিওসিসের সবচেয়ে সাধারণ অভিযোগ। দুঃখজনকভাবে অনেকেই এটাকে "স্বাভাবিক মাসিক ব্যথা" ভেবে বছরের পর বছর সহ্য করে যান।

## এন্ডোমেট্রিওসিস কী?

জরায়ুর ভেতরের আস্তরণের (এন্ডোমেট্রিয়াম) মতো টিস্যু জরায়ুর বাইরে — ডিম্বাশয়, ফ্যালোপিয়ান টিউব, বা পেটের অন্য জায়গায় বেড়ে উঠলে তাকে এন্ডোমেট্রিওসিস বলে। এই টিস্যুও মাসিক চক্র অনুযায়ী রক্তক্ষরণ করে, কিন্তু শরীর থেকে বের হতে পারে না — ফলে ব্যথা ও প্রদাহ তৈরি হয়।

## প্রধান লক্ষণ

- তীব্র মাসিক ব্যথা, যা সাধারণ ব্যথানাশকে কমে না
- সহবাসের সময় ব্যথা
- দীর্ঘস্থায়ী পেলভিক (তলপেটের) ব্যথা
- অতিরিক্ত মাসিক রক্তস্রাব
- গর্ভধারণে বিলম্ব বা অসুবিধা
- মলত্যাগ বা প্রস্রাবের সময় ব্যথা (তীব্র ক্ষেত্রে)

কিছু নারীর ক্ষেত্রে কোনো লক্ষণই থাকে না, আবার কারও ক্ষেত্রে সামান্য এন্ডোমেট্রিওসিসেও তীব্র ব্যথা হয় — তীব্রতা আর রোগের ব্যাপ্তি সবসময় সমানুপাতিক নয়।

## কেন হয়?

সঠিক কারণ সম্পূর্ণ জানা নেই, তবে কিছু তত্ত্ব প্রচলিত:

- **রেট্রোগ্রেড মেনস্ট্রুয়েশন** — মাসিক রক্ত উল্টো দিকে টিউব দিয়ে পেটে চলে যাওয়া
- **বংশগত প্রবণতা**
- **রোগ প্রতিরোধ ব্যবস্থার সমস্যা**

## নির্ণয় কীভাবে হয়?

- বিস্তারিত ইতিহাস ও শারীরিক পরীক্ষা
- আল্ট্রাসনোগ্রাম (কিছু ক্ষেত্রে সনাক্ত করা যায়)
- নিশ্চিতভাবে নির্ণয়ের জন্য **ল্যাপারোস্কোপি** — একটি ছোট অস্ত্রোপচার পদ্ধতি, যেখানে সরাসরি দেখে এবং প্রয়োজনে টিস্যু পরীক্ষা করে নিশ্চিত করা হয়

## চিকিৎসার পদ্ধতি

চিকিৎসা নির্ভর করে বয়স, লক্ষণের তীব্রতা এবং গর্ভধারণের ইচ্ছার উপর:

### ওষুধের মাধ্যমে
ব্যথানাশক এবং হরমোনাল চিকিৎসা (জন্মনিয়ন্ত্রণ পিল, বা নির্দিষ্ট হরমোন থেরাপি) দিয়ে উপসর্গ নিয়ন্ত্রণ করা যায়।

### ল্যাপারোস্কোপিক সার্জারি
এন্ডোমেট্রিওসিসের টিস্যু অপসারণ করে ব্যথা কমানো এবং গর্ভধারণের সম্ভাবনা বাড়ানো যায় — বিশেষত যেসব ক্ষেত্রে ওষুধে কাজ না হয় বা বন্ধ্যাত্ব সমস্যা যুক্ত থাকে।

### গর্ভধারণ পরিকল্পনায়
এন্ডোমেট্রিওসিস থাকা অবস্থায় গর্ভধারণের ইচ্ছা থাকলে সার্জারি বা সহায়ক প্রজনন পদ্ধতি (IUI/IVF) বিবেচনা করা হতে পারে — এটা case-by-case সিদ্ধান্ত।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**তীব্র মাসিক ব্যথা মানেই কি এন্ডোমেট্রিওসিস?**
সবসময় না, কিন্তু সাধারণ ব্যথানাশকে না কমা তীব্র ব্যথা হলে অবশ্যই একজন স্ত্রীরোগ বিশেষজ্ঞকে দেখানো উচিত।

**এন্ডোমেট্রিওসিস থাকলে কি গর্ভধারণ অসম্ভব?**
না, তবে কিছুটা কঠিন হতে পারে। সঠিক চিকিৎসায় অনেক নারীই স্বাভাবিকভাবে বা সহায়ক পদ্ধতিতে গর্ভধারণ করতে পারেন।

**এটা কি বংশগত?**
পরিবারে থাকলে ঝুঁকি কিছুটা বেশি থাকে, তবে সবার ক্ষেত্রে বংশগতভাবে হয় না।

## সারসংক্ষেপ

তীব্র মাসিক ব্যথাকে "স্বাভাবিক" ভেবে সহ্য করে যাওয়া উচিত না। প্রাথমিক পর্যায়ে নির্ণয় হলে চিকিৎসা সহজ হয় এবং ভবিষ্যতের জটিলতা এড়ানো যায়। এই লেখাটি সাধারণ তথ্যের উদ্দেশ্যে, ব্যক্তিগত পরামর্শের বিকল্প নয়।', '/uploads/cover-endometriosis-symptoms-and-treatment.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('high-risk-pregnancy-care-guide', 'হাই রিস্ক প্রেগন্যান্সি: কোন গর্ভাবস্থাকে ঝুঁকিপূর্ণ বলা হয়', 'হাই রিস্ক প্রেগন্যান্সি ব্যবস্থাপনা | ডাঃ জেবুন্নেসা জেবু', 'হাই রিস্ক প্রেগন্যান্সি কী, কোন কোন কারণে গর্ভাবস্থা ঝুঁকিপূর্ণ হয়ে ওঠে এবং কীভাবে নিরাপদে সামলানো যায় — জানুন।', 'হাই রিস্ক প্রেগন্যান্সি কী, কোন কোন কারণে গর্ভাবস্থা ঝুঁকিপূর্ণ হয়ে ওঠে এবং কীভাবে নিরাপদে সামলানো যায় — জানুন।', 'হাই রিস্ক প্রেগন্যান্সি', '"হাই রিস্ক প্রেগন্যান্সি" শব্দটা শুনে অনেকেই ভয় পেয়ে যান। কিন্তু বাস্তবে এর মানে এই না যে কিছু খারাপ হবেই — এর মানে হলো, এই গর্ভাবস্থায় একটু বাড়তি সতর্কতা ও নিয়মিত পর্যবেক্ষণ প্রয়োজন।

## হাই রিস্ক প্রেগন্যান্সি কী?

যে গর্ভাবস্থায় মা বা শিশুর স্বাস্থ্যঝুঁকির সম্ভাবনা সাধারণের চেয়ে বেশি থাকে, তাকে হাই রিস্ক প্রেগন্যান্সি বলা হয়। এর মানে এই না যে জটিলতা হবেই — বরং এর মানে হলো এটাকে বাড়তি যত্নে রাখতে হবে।

## কোন কোন কারণে ঝুঁকি বাড়ে

- **মায়ের বয়স** — ৩৫ বছরের বেশি বা ১৮ বছরের কম বয়সে গর্ভধারণ
- **উচ্চ রক্তচাপ** বা প্রি-এক্লাম্পসিয়া
- **ডায়াবেটিস** (আগে থেকে থাকা বা গর্ভকালীন ডায়াবেটিস)
- **আগের গর্ভাবস্থায় জটিলতার ইতিহাস** (গর্ভপাত, অকাল প্রসব)
- **যমজ বা তার বেশি সন্তান**
- **থাইরয়েড বা হৃদরোগের মতো পূর্ব-বিদ্যমান রোগ**
- **স্থূলতা বা কম ওজন**
- **প্লাসেন্টার অবস্থানগত সমস্যা**

## কীভাবে ব্যবস্থাপনা করা হয়

### নিয়মিত ও ঘনঘন চেকআপ
সাধারণ গর্ভাবস্থার চেয়ে বেশি ঘনঘন visit ও পরীক্ষা করা হয় — রক্তচাপ, ব্লাড সুগার, শিশুর বৃদ্ধি নিয়মিত পর্যবেক্ষণ করা হয়।

### অতিরিক্ত পরীক্ষা
প্রয়োজন অনুযায়ী অতিরিক্ত আল্ট্রাসনোগ্রাম, ডপলার স্টাডি, বা রক্ত পরীক্ষা করা হতে পারে।

### জীবনযাত্রায় সমন্বয়
বিশ্রাম, খাদ্যাভ্যাস এবং ওষুধ গ্রহণে চিকিৎসকের নির্দেশনা কঠোরভাবে মেনে চলা জরুরি।

### প্রসবের পরিকল্পনা
কখন, কীভাবে এবং কোথায় প্রসব হবে তা আগে থেকেই পরিকল্পনা করে রাখা হয়, যাতে জরুরি প্রয়োজনে দ্রুত ব্যবস্থা নেওয়া যায়।

## কখন জরুরিভাবে চিকিৎসকের কাছে যাবেন

- তীব্র মাথাব্যথা বা চোখে ঝাপসা দেখা
- হঠাৎ হাত-পা-মুখ ফুলে যাওয়া
- তলপেটে তীব্র ব্যথা
- যোনিপথে রক্তক্ষরণ
- শিশুর নড়াচড়া কমে যাওয়া বা বন্ধ হয়ে যাওয়া

এই লক্ষণগুলো দেখলে দেরি না করে সাথে সাথে চিকিৎসকের সাথে যোগাযোগ করা উচিত।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**হাই রিস্ক মানেই কি সিজারিয়ান লাগবে?**
না, অনেক হাই রিস্ক গর্ভাবস্থাতেও সঠিক ব্যবস্থাপনায় নরমাল ডেলিভারি সম্ভব হয় — এটা নির্ভর করে নির্দিষ্ট পরিস্থিতির উপর।

**হাই রিস্ক হলে কি বিশ্রামে থাকতে হবে সবসময়?**
সবসময় না — এটা নির্দিষ্ট কারণ ও চিকিৎসকের পরামর্শের উপর নির্ভর করে, প্রতিটি ক্ষেত্রে আলাদা।

**পরের গর্ভাবস্থাতেও কি একই ঝুঁকি থাকবে?**
সবসময় না, তবে আগের ইতিহাস মাথায় রেখে পরবর্তী গর্ভাবস্থায় আরও সতর্ক পর্যবেক্ষণ প্রয়োজন হয়।

## সারসংক্ষেপ

হাই রিস্ক প্রেগন্যান্সির মূল চাবিকাঠি হলো প্রাথমিক শনাক্তকরণ এবং নিয়মিত পর্যবেক্ষণ। ভয় না পেয়ে নিয়মিত চেকআপ চালিয়ে যাওয়াটাই মা ও শিশু উভয়ের জন্য সবচেয়ে নিরাপদ পথ। এই লেখাটি সাধারণ তথ্যের উদ্দেশ্যে, ব্যক্তিগত চিকিৎসা পরামর্শের বিকল্প নয়।', '/uploads/cover-high-risk-pregnancy-care-guide.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('uterine-fibroid-symptoms-treatment', 'জরায়ুর ফাইব্রয়েড: লক্ষণ, কারণ ও চিকিৎসা', 'জরায়ুর ফাইব্রয়েড চিকিৎসা — লক্ষণ ও কারণ | ডাঃ জেবুন্নেসা জেবু', 'জরায়ুর ফাইব্রয়েড কী, এটা কি ক্যান্সার, এবং কোন ক্ষেত্রে অস্ত্রোপচার প্রয়োজন হয় তা বিস্তারিত জানুন।', 'জরায়ুর ফাইব্রয়েড কী, এটা কি ক্যান্সার, এবং কোন ক্ষেত্রে অস্ত্রোপচার প্রয়োজন হয় তা বিস্তারিত জানুন।', 'ফাইব্রয়েড চিকিৎসা', 'জরায়ুর ফাইব্রয়েড নির্ণয় হওয়ার পর অনেকেই ভয় পেয়ে যান, ক্যান্সার ভেবে। ভালো খবর হলো — ফাইব্রয়েড প্রায় সবসময়ই একটা সৌম্য (non-cancerous) টিউমার, এবং অনেক ক্ষেত্রেই এর কোনো চিকিৎসার প্রয়োজনই হয় না।

## ফাইব্রয়েড কী?

জরায়ুর পেশী টিস্যু থেকে তৈরি হওয়া একটা সৌম্য (benign) টিউমার, যা জরায়ুর ভেতরে, দেয়ালে বা বাইরের দিকে হতে পারে। আকারে এটা একটা ছোট মটরদানার মতোও হতে পারে, আবার বড় হয়ে তরমুজের মতো আকারও নিতে পারে।

## কতটা সাধারণ?

প্রজনন বয়সের নারীদের একটা বড় অংশের মধ্যে ফাইব্রয়েড থাকে — অনেকেরই কোনো লক্ষণ থাকে না, তাই না জেনেই থাকে।

## লক্ষণ কী কী

- অতিরিক্ত বা দীর্ঘস্থায়ী মাসিক রক্তস্রাব
- তলপেটে ভারী ভাব বা চাপ অনুভব করা
- ঘনঘন প্রস্রাবের প্রয়োজন (মূত্রথলিতে চাপ পড়লে)
- কোমর বা তলপেটে ব্যথা
- গর্ভধারণে অসুবিধা (আকার ও অবস্থানভেদে)

## কেন হয়?

সঠিক কারণ পুরোপুরি জানা নেই, তবে হরমোন (ইস্ট্রোজেন) এবং বংশগত প্রবণতা গুরুত্বপূর্ণ ভূমিকা রাখে বলে মনে করা হয়। মেনোপজের পর সাধারণত ফাইব্রয়েড ছোট হয়ে আসে বা সমস্যা কমে যায়।

## নির্ণয়

সাধারণত আল্ট্রাসনোগ্রামেই ফাইব্রয়েড শনাক্ত করা যায়। প্রয়োজনে MRI বা অন্য ইমেজিং করা হতে পারে।

## চিকিৎসা — সবসময় অস্ত্রোপচার লাগে না

চিকিৎসা নির্ভর করে লক্ষণের তীব্রতা, ফাইব্রয়েডের আকার/অবস্থান, এবং ভবিষ্যতে গর্ভধারণের ইচ্ছার উপর:

- **লক্ষণ না থাকলে**: শুধু নিয়মিত পর্যবেক্ষণ, কোনো চিকিৎসা ছাড়াই
- **হালকা লক্ষণে**: ওষুধ দিয়ে রক্তস্রাব ও ব্যথা নিয়ন্ত্রণ
- **বড় আকারে বা তীব্র লক্ষণে**: ফাইব্রয়েড অপসারণ সার্জারি (মায়োমেক্টমি) — জরায়ু রেখে শুধু ফাইব্রয়েড ফেলে দেওয়া হয়, যা ভবিষ্যতে গর্ভধারণের ইচ্ছা থাকলে বেছে নেওয়া হয়
- **তীব্র জটিল ক্ষেত্রে**: প্রয়োজনে জরায়ু অপসারণ (হিস্টেরেক্টমি) বিবেচনা করা হয়, সাধারণত যাদের আর সন্তান নেওয়ার ইচ্ছা নেই তাদের ক্ষেত্রে

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**ফাইব্রয়েড কি ক্যান্সারে রূপ নেয়?**
অত্যন্ত বিরল। প্রায় সবসময়ই এটা সৌম্য থেকে যায়।

**ফাইব্রয়েড থাকলে কি গর্ভধারণ সম্ভব না?**
সম্ভব, তবে আকার ও অবস্থানের উপর নির্ভর করে কিছু ক্ষেত্রে অসুবিধা হতে পারে — চিকিৎসকের মূল্যায়ন প্রয়োজন।

**সব ফাইব্রয়েডেই কি অস্ত্রোপচার লাগে?**
না, বেশিরভাগ ক্ষেত্রেই লক্ষণ না থাকলে শুধু পর্যবেক্ষণই যথেষ্ট।

## সারসংক্ষেপ

ফাইব্রয়েড নির্ণয় হলে আতঙ্কিত হওয়ার দরকার নেই — এটা সাধারণত সৌম্য এবং ব্যবস্থাপনাযোগ্য। লক্ষণ অনুযায়ী সঠিক সিদ্ধান্ত নিতে একজন স্ত্রীরোগ বিশেষজ্ঞের পরামর্শ নিন। এই লেখাটি সাধারণ তথ্যের উদ্দেশ্যে, ব্যক্তিগত পরামর্শের বিকল্প নয়।', '/uploads/cover-uterine-fibroid-symptoms-treatment.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('ovarian-cyst-symptoms-and-treatment', 'ডিম্বাশয়ের সিস্ট: কখন চিন্তার কারণ', 'ডিম্বাশয়ের সিস্ট চিকিৎসা — কখন চিন্তার কারণ | ডাঃ জেবুন্নেসা জেবু', 'ডিম্বাশয়ে সিস্ট থাকলে কখন চিন্তার কারণ, কখন এমনিতেই মিলিয়ে যায়, এবং কখন অস্ত্রোপচার প্রয়োজন — জানুন।', 'ডিম্বাশয়ে সিস্ট থাকলে কখন চিন্তার কারণ, কখন এমনিতেই মিলিয়ে যায়, এবং কখন অস্ত্রোপচার প্রয়োজন — জানুন।', 'ওভারিয়ান সিস্ট চিকিৎসা', 'আল্ট্রাসনোগ্রাম রিপোর্টে "ওভারিয়ান সিস্ট" দেখে অনেকেই আতঙ্কিত হয়ে পড়েন। বাস্তবে বেশিরভাগ সিস্টই সম্পূর্ণ স্বাভাবিক এবং নিজে থেকেই মিলিয়ে যায়।

## ওভারিয়ান সিস্ট কী?

ডিম্বাশয়ের ভেতরে বা তার গায়ে তরল-ভর্তি একটা থলি তৈরি হলে তাকে সিস্ট বলে। প্রতি মাসিক চক্রে ডিম্বাশয় স্বাভাবিকভাবেই ছোট ছোট সিস্ট তৈরি করে (ডিম্বস্ফোটনের অংশ হিসেবে) — এগুলোকে ফাংশনাল সিস্ট বলে, যা সম্পূর্ণ স্বাভাবিক।

## সিস্টের ধরন

- **ফাংশনাল সিস্ট**: সবচেয়ে সাধারণ, মাসিক চক্রের অংশ, সাধারণত ১-৩ মাসের মধ্যে নিজে থেকেই মিলিয়ে যায়
- **ডার্ময়েড সিস্ট**: জন্মগত কোষ থেকে তৈরি, সাধারণত সৌম্য
- **এন্ডোমেট্রিওমা**: এন্ডোমেট্রিওসিসের কারণে তৈরি সিস্ট ("চকলেট সিস্ট" নামেও পরিচিত)
- **জটিল সিস্ট**: গঠন জটিল, আরও পরীক্ষার প্রয়োজন হতে পারে

## লক্ষণ

বেশিরভাগ সিস্টেই কোনো লক্ষণ থাকে না, দৈবক্রমে আল্ট্রাসনোগ্রামে ধরা পড়ে। লক্ষণ থাকলে:

- তলপেটে চাপ বা ভারী ভাব
- এক পাশে ব্যথা
- মাসিক চক্রে অনিয়ম
- হঠাৎ তীব্র ব্যথা (সিস্ট মোচড় খেলে বা ফেটে গেলে — এটা জরুরি অবস্থা)

## কখন জরুরি চিকিৎসা প্রয়োজন

হঠাৎ তীব্র তলপেট ব্যথার সাথে বমি বা জ্বর হলে দেরি না করে দ্রুত হাসপাতালে যাওয়া উচিত — এটা সিস্ট মোচড় খাওয়া (torsion) বা ফেটে যাওয়ার লক্ষণ হতে পারে।

## চিকিৎসা কীভাবে ঠিক হয়

আকার, ধরন এবং লক্ষণের উপর ভিত্তি করে:

- **ছোট, সাধারণ সিস্ট**: শুধু পর্যবেক্ষণ, কয়েক মাস পর আবার আল্ট্রাসনোগ্রাম করে দেখা হয়
- **লক্ষণযুক্ত বা বড় সিস্ট**: ল্যাপারোস্কোপিক সার্জারির মাধ্যমে অপসারণ, যেখানে সম্ভব ডিম্বাশয় সংরক্ষণ করেই সিস্ট ফেলে দেওয়া হয়
- **জটিল বা সন্দেহজনক গঠনে**: আরও বিস্তারিত পরীক্ষা (টিউমার মার্কার, MRI) করে সিদ্ধান্ত নেওয়া হয়

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**সব ওভারিয়ান সিস্টই কি বিপজ্জনক?**
না, বেশিরভাগই সম্পূর্ণ স্বাভাবিক এবং নিরাপদ, নিজে থেকেই মিলিয়ে যায়।

**সিস্ট থাকলে কি গর্ভধারণে সমস্যা হয়?**
সাধারণ ফাংশনাল সিস্টে সাধারণত সমস্যা হয় না। এন্ডোমেট্রিওমা বা বড় সিস্টে প্রভাব পড়তে পারে, ক্ষেত্রবিশেষে মূল্যায়ন প্রয়োজন।

**সিস্ট কি বারবার হতে পারে?**
হ্যাঁ, ফাংশনাল সিস্ট প্রতি চক্রে তৈরি ও মিলিয়ে যাওয়া স্বাভাবিক প্রক্রিয়ার অংশ হতে পারে।

## সারসংক্ষেপ

ওভারিয়ান সিস্ট নাম শুনে ভয় না পেয়ে, নিয়মিত ফলো-আপ আল্ট্রাসনোগ্রাম করানোই সবচেয়ে গুরুত্বপূর্ণ। বেশিরভাগ ক্ষেত্রে এটা নিজে থেকেই ঠিক হয়ে যায়। এই লেখাটি সাধারণ তথ্যের উদ্দেশ্যে, ব্যক্তিগত মূল্যায়নের বিকল্প নয়।', '/uploads/cover-ovarian-cyst-symptoms-and-treatment.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('irregular-periods-menstrual-problems', 'মাসিকের অনিয়ম: কারণ ও কখন চিকিৎসকের কাছে যাবেন', 'অনিয়মিত মাসিকের চিকিৎসা — কারণ ও সমাধান | ডাঃ জেবুন্নেসা জেবু', 'মাসিক অনিয়মিত হওয়ার সাধারণ কারণগুলো কী কী, এবং কোন লক্ষণে চিকিৎসকের পরামর্শ নেওয়া জরুরি তা জানুন।', 'মাসিক অনিয়মিত হওয়ার সাধারণ কারণগুলো কী কী, এবং কোন লক্ষণে চিকিৎসকের পরামর্শ নেওয়া জরুরি তা জানুন।', 'মাসিক সমস্যার চিকিৎসা', '"এই মাসে মাসিক দেরিতে হলো, আবার পরের মাসে দুইবার হলো" — এই ধরনের অভিযোগ নিয়ে প্রতিদিনই অনেক রোগী আসেন। মাসিকের অনিয়ম অনেক কারণেই হতে পারে, বেশিরভাগই চিকিৎসাযোগ্য।

## স্বাভাবিক মাসিক চক্র কেমন হয়?

সাধারণত ২১ থেকে ৩৫ দিনের মধ্যে একটা চক্র সম্পূর্ণ হয়, এবং রক্তস্রাব ২ থেকে ৭ দিন স্থায়ী হয়। এর বাইরে গেলে বা প্যাটার্নে হঠাৎ পরিবর্তন এলে তাকে অনিয়ম বলা যায়।

## অনিয়মের ধরন

- **মাসিক দেরিতে হওয়া বা বাদ যাওয়া**
- **অতিরিক্ত ঘনঘন মাসিক হওয়া**
- **অতিরিক্ত রক্তস্রাব (Heavy Menstrual Bleeding)**
- **মাসিকের মাঝে রক্তক্ষরণ**
- **তীব্র ব্যথাযুক্ত মাসিক**

## সাধারণ কারণসমূহ

- **PCOS** — অনিয়মিত মাসিকের সবচেয়ে সাধারণ কারণগুলোর একটা
- **থাইরয়েড সমস্যা**
- **মানসিক চাপ ও জীবনযাত্রার পরিবর্তন**
- **হঠাৎ ওজন বৃদ্ধি বা কমে যাওয়া**
- **জরায়ুর ফাইব্রয়েড বা পলিপ**
- **পেরিমেনোপজ** (মেনোপজের আগের সময়কাল)
- **জন্মনিয়ন্ত্রণ পদ্ধতির প্রভাব**

## কোন পরীক্ষা প্রয়োজন হতে পারে

- হরমোন পরীক্ষা (থাইরয়েড, প্রোল্যাক্টিন ইত্যাদি)
- আল্ট্রাসনোগ্রাম
- প্রয়োজনে রক্ত পরীক্ষা (হিমোগ্লোবিন, বিশেষত অতিরিক্ত রক্তস্রাবে)

## কখন দেরি না করে চিকিৎসকের কাছে যাবেন

- একটানা ৩ মাসের বেশি মাসিক না হলে (গর্ভাবস্থা বাদে)
- এত বেশি রক্তস্রাব যে ঘণ্টায় প্যাড বদলাতে হয়
- মাসিকের সাথে তীব্র ব্যথা যা স্বাভাবিক ব্যথানাশকে কমে না
- মাসিকের মাঝে বারবার রক্তক্ষরণ
- মেনোপজের পর আবার রক্তক্ষরণ শুরু হওয়া

## চিকিৎসা

কারণ নির্ণয়ের পর চিকিৎসা ঠিক করা হয় — হরমোনাল ওষুধ দিয়ে চক্র নিয়ন্ত্রণ, থাইরয়েডের চিকিৎসা, বা কাঠামোগত কারণ (ফাইব্রয়েড/পলিপ) থাকলে তার প্রয়োজন অনুযায়ী ব্যবস্থা।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**এক-দুই দিন মাসিক দেরিতে হলেই কি ডাক্তার দেখাতে হবে?**
না, সামান্য হেরফের স্বাভাবিক। বারবার বড় ধরনের অনিয়ম হলেই পরামর্শ নেওয়া উচিত।

**মানসিক চাপে কি সত্যিই মাসিক অনিয়মিত হয়?**
হ্যাঁ, দীর্ঘস্থায়ী মানসিক চাপ হরমোনের ভারসাম্যে প্রভাব ফেলে মাসিক চক্র বদলে দিতে পারে।

**অতিরিক্ত রক্তস্রাব হলে কি রক্তশূন্যতা হতে পারে?**
হ্যাঁ, দীর্ঘদিন ধরে অতিরিক্ত রক্তস্রাব হলে রক্তশূন্যতা (অ্যানিমিয়া) হতে পারে, তাই সময়মতো চিকিৎসা জরুরি।

## সারসংক্ষেপ

মাসিকের অনিয়ম অনেক সময় সাময়িক হলেও, বারবার বা তীব্র হলে এর পেছনে কারণ খুঁজে বের করা জরুরি। এই লেখাটি সাধারণ তথ্যের উদ্দেশ্যে, ব্যক্তিগত পরামর্শের বিকল্প নয়।', '/uploads/cover-irregular-periods-menstrual-problems.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('menopause-symptoms-and-care', 'মেনোপজ: শারীরিক পরিবর্তন ও সুস্থ থাকার উপায়', 'মেনোপজ ব্যবস্থাপনা — লক্ষণ ও পরামর্শ | ডাঃ জেবুন্নেসা জেবু', 'মেনোপজের সময় শরীরে কী কী পরিবর্তন আসে, কোন লক্ষণগুলো স্বাভাবিক এবং কীভাবে এই সময়টা সুস্থভাবে পার করা যায়।', 'মেনোপজের সময় শরীরে কী কী পরিবর্তন আসে, কোন লক্ষণগুলো স্বাভাবিক এবং কীভাবে এই সময়টা সুস্থভাবে পার করা যায়।', 'মেনোপজ', 'মেনোপজ কোনো রোগ না — এটা নারীর জীবনের একটা স্বাভাবিক পর্যায়, যেখানে ডিম্বাশয় ধীরে ধীরে হরমোন তৈরি বন্ধ করে দেয় এবং মাসিক চিরতরে থেমে যায়। তবে এই পরিবর্তনটা সহজভাবে সামলাতে সঠিক তথ্য জানা জরুরি।

## মেনোপজ কী?

টানা ১২ মাস মাসিক না হলে তখন তাকে মেনোপজ বলা হয়। সাধারণত ৪৫ থেকে ৫৫ বছর বয়সের মধ্যে এটা হয়, গড় বয়স প্রায় ৫০ এর কাছাকাছি।

## পেরিমেনোপজ — মেনোপজের আগের পর্যায়

মেনোপজের কয়েক বছর আগে থেকেই শরীরে পরিবর্তন শুরু হয় — এই সময়টাকে পেরিমেনোপজ বলে, যেখানে মাসিক অনিয়মিত হতে শুরু করে।

## সাধারণ লক্ষণ

- গরম ভাব/ঘাম হওয়া (Hot Flashes)
- রাতে ঘুমের সমস্যা
- মেজাজের পরিবর্তন, বিরক্তি বা উদ্বেগ
- যোনিপথ শুষ্ক হয়ে যাওয়া
- ওজন বৃদ্ধি, বিশেষত পেটের অংশে
- হাড়ের ঘনত্ব কমে যাওয়া (দীর্ঘমেয়াদে)
- ত্বক ও চুলের পরিবর্তন

সবার ক্ষেত্রে তীব্রতা একরকম না — কারো সামান্য অস্বস্তি হয়, কারো জীবনযাত্রায় বড় প্রভাব ফেলে।

## দীর্ঘমেয়াদী স্বাস্থ্য বিবেচনা

মেনোপজের পর ইস্ট্রোজেন কমে যাওয়ায় দুটো বিষয়ে বাড়তি সতর্কতা প্রয়োজন:

- **হাড়ের স্বাস্থ্য** — অস্টিওপোরোসিসের ঝুঁকি বাড়ে
- **হৃদস্বাস্থ্য** — হৃদরোগের ঝুঁকি কিছুটা বাড়ে

তাই নিয়মিত চেকআপ, ক্যালসিয়াম ও ভিটামিন ডি সমৃদ্ধ খাবার, এবং নিয়মিত ব্যায়াম গুরুত্বপূর্ণ হয়ে ওঠে।

## কীভাবে সহজভাবে পার করা যায়

- নিয়মিত হালকা ব্যায়াম (হাঁটা, যোগব্যায়াম)
- সুষম খাদ্যাভ্যাস — ক্যালসিয়াম, ভিটামিন ডি সমৃদ্ধ খাবার
- পর্যাপ্ত ঘুম ও মানসিক চাপ কমানো
- প্রয়োজনে হরমোন থেরাপি বা অন্য ওষুধ নিয়ে চিকিৎসকের সাথে আলোচনা — এটা সবার জন্য উপযুক্ত না, ব্যক্তিভেদে সিদ্ধান্ত নিতে হয়

## কখন চিকিৎসকের পরামর্শ নেবেন

- মেনোপজের পরও যোনিপথে রক্তক্ষরণ হলে (এটা সবসময়ই পরীক্ষার দাবি রাখে)
- লক্ষণ দৈনন্দিন জীবনে তীব্র প্রভাব ফেললে
- হাড় বা হৃদস্বাস্থ্য নিয়ে দুশ্চিন্তা থাকলে

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**মেনোপজের পর রক্তক্ষরণ হলে কি চিন্তার কারণ?**
হ্যাঁ, এটা উপেক্ষা করা উচিত না — যত দ্রুত সম্ভব চিকিৎসকের পরামর্শ নেওয়া জরুরি, কারণ নির্ণয়ের জন্য।

**হরমোন থেরাপি কি সবার জন্য নিরাপদ?**
না, এটা ব্যক্তির স্বাস্থ্য ইতিহাসের উপর নির্ভর করে — চিকিৎসকের সাথে আলোচনা করে সিদ্ধান্ত নেওয়া উচিত।

**মেনোপজের পর কি ওজন বাড়াই স্বাভাবিক?**
হরমোনের পরিবর্তনে বিপাক হার কিছুটা কমে, তাই এই সময় খাদ্যাভ্যাস ও ব্যায়ামে বাড়তি মনোযোগ দরকার হতে পারে।

## সারসংক্ষেপ

মেনোপজ জীবনের একটা স্বাভাবিক অধ্যায়, ভয়ের কিছু না। সঠিক তথ্য, স্বাস্থ্যকর জীবনযাত্রা এবং প্রয়োজনে চিকিৎসকের সহায়তায় এই সময়টা সুস্থভাবে পার করা সম্পূর্ণ সম্ভব। এই লেখাটি সাধারণ তথ্যের উদ্দেশ্যে, ব্যক্তিগত পরামর্শের বিকল্প নয়।', '/uploads/cover-menopause-symptoms-and-care.jpg', 'published', 'index', NOW());

UPDATE blog_posts SET focus_keyword = 'PCOS চিকিৎসা', meta_description = excerpt
WHERE slug = 'pcos-treatment-guide' AND focus_keyword = '';

UPDATE blog_posts SET focus_keyword = 'নরমাল ডেলিভারি না সিজারিয়ান', meta_description = excerpt
WHERE slug = 'normal-delivery-vs-c-section' AND focus_keyword = '';

UPDATE blog_posts SET focus_keyword = 'সাদা স্রাব কারণ', meta_description = excerpt
WHERE slug = 'white-discharge-causes-and-when-to-worry' AND focus_keyword = '';

UPDATE blog_posts SET cover_image = '/uploads/cover-pcos-treatment-guide.jpg'
WHERE slug = 'pcos-treatment-guide';

UPDATE blog_posts SET cover_image = '/uploads/cover-normal-delivery-vs-c-section.jpg'
WHERE slug = 'normal-delivery-vs-c-section';

UPDATE blog_posts SET cover_image = '/uploads/cover-white-discharge-causes-and-when-to-worry.jpg'
WHERE slug = 'white-discharge-causes-and-when-to-worry';

UPDATE blog_posts SET cover_image = '/uploads/cover-infertility-causes-and-treatment-khulna.jpg'
WHERE slug = 'infertility-causes-and-treatment-khulna';

UPDATE blog_posts SET cover_image = '/uploads/cover-endometriosis-symptoms-and-treatment.jpg'
WHERE slug = 'endometriosis-symptoms-and-treatment';

UPDATE blog_posts SET cover_image = '/uploads/cover-high-risk-pregnancy-care-guide.jpg'
WHERE slug = 'high-risk-pregnancy-care-guide';

-- ---- Entity seeds (carried over from old migrations 018-023) ----
INSERT IGNORE INTO specialties (slug, kicker, title, short_desc, sort_order) VALUES
 ('antenatal-delivery-care', 'Antenatal & Delivery', 'গর্ভাবস্থা ও প্রসব সেবা', 'গর্ভধারণের প্রথম দিন থেকে প্রসব-পরবর্তী পরিচর্যা পর্যন্ত সম্পূর্ণ মাতৃযত্ন।', 1),
 ('fertility-management', 'Fertility', 'বন্ধ্যাত্ব ব্যবস্থাপনা', 'দম্পতির সম্মিলিত মূল্যায়ন এবং প্রমাণভিত্তিক চিকিৎসা পরিকল্পনা।', 2),
 ('gynaecological-surgery', 'Surgical', 'গাইনোকোলজিকাল সার্জারি', 'ফাইব্রয়েড, সিস্ট এবং প্রজনন অঙ্গের জটিল অস্ত্রোপচারে অভিজ্ঞতা।', 3),
 ('menstrual-health', 'Menstrual Health', 'মাসিক চক্রের সমস্যা', 'অনিয়মিত মাসিক, অতিরিক্ত রক্তস্রাব এবং হরমোনজনিত জটিলতার সমাধান।', 4),
 ('breast-care', 'Breast Care', 'ব্রেস্ট সম্পর্কিত পরামর্শ', 'প্রাথমিক স্ক্রিনিং, নিয়মিত মূল্যায়ন এবং প্রয়োজনে সুনির্দিষ্ট রেফারেল।', 5),
 ('lifespan-womens-care', 'Lifespan Care', 'কিশোরী থেকে মেনোপজ', 'নারীর জীবনের প্রতিটি পর্যায়ে বয়সোপযোগী, যত্নশীল চিকিৎসা পরামর্শ।', 6);

INSERT IGNORE INTO services (slug, title, kicker, specialty_id, sort_order) VALUES
 ('infertility-treatment-program', 'বন্ধ্যাত্ব চিকিৎসা প্রোগ্রাম', 'Infertility Management', (SELECT id FROM specialties WHERE slug = 'fertility-management'), 1),
 ('pregnancy-delivery-care', 'গর্ভাবস্থা ও প্রসব সেবা প্যাকেজ', 'Pregnancy & Delivery', (SELECT id FROM specialties WHERE slug = 'antenatal-delivery-care'), 2),
 ('gynaecological-surgery-service', 'গাইনোকোলজিক্যাল সার্জারি সেবা', 'Surgical Care', (SELECT id FROM specialties WHERE slug = 'gynaecological-surgery'), 3),
 ('menstrual-health-care', 'মাসিক স্বাস্থ্য পরিচর্যা', 'Menstrual Health', (SELECT id FROM specialties WHERE slug = 'menstrual-health'), 4),
 ('gynae-infection-screening-care', 'গাইনী সংক্রমণ ও স্ক্রিনিং সেবা', 'Screening', NULL, 5),
 ('womens-lifespan-care', 'কিশোরী থেকে মেনোপজ পরিচর্যা', 'Lifespan Care', (SELECT id FROM specialties WHERE slug = 'lifespan-womens-care'), 6),
 ('antenatal-care-service', 'অ্যান্টিনেটাল কেয়ার (গর্ভকালীন পরিচর্যা)', 'Antenatal Care', (SELECT id FROM specialties WHERE slug = 'antenatal-delivery-care'), 7),
 ('c-section-delivery-service', 'সিজারিয়ান সেকশন সেবা', 'C-Section', (SELECT id FROM specialties WHERE slug = 'antenatal-delivery-care'), 8),
 ('laparoscopic-surgery-service', 'ল্যাপারোস্কোপিক সার্জারি সেবা', 'Laparoscopic Surgery', (SELECT id FROM specialties WHERE slug = 'gynaecological-surgery'), 9),
 ('colposcopy-cervical-screening', 'কলপোস্কোপি ও সার্ভিকাল স্ক্রিনিং', 'Colposcopy', NULL, 10);

INSERT IGNORE INTO conditions (slug, title, sort_order) VALUES
 ('pcos', 'পলিসিস্টিক ওভারি সিন্ড্রোম (PCOS)', 1),
 ('irregular-periods', 'অনিয়মিত মাসিক', 2),
 ('hormonal-imbalance', 'হরমোন ইমব্যালেন্সজনিত গাইনী সমস্যা', 3),
 ('endometriosis', 'এন্ডোমেট্রিওসিস', 4),
 ('uterine-fibroid', 'জরায়ুর ফাইব্রয়েড', 5),
 ('ovarian-cyst', 'ডিম্বাশয়ের সিস্ট', 6),
 ('high-risk-pregnancy', 'উচ্চ ঝুঁকিপূর্ণ গর্ভাবস্থা', 7),
 ('repeated-ivf-failure', 'বারবার IVF ব্যর্থতা', 8),
 ('recurrent-miscarriage', 'বারবার গর্ভপাত', 9),
 ('tubal-blockage-hydrosalpinx', 'টিউব ব্লক / হাইড্রোসালপিনক্স', 10),
 ('uterine-infection', 'জরায়ুর সংক্রমণ', 11),
 ('cervix-problems', 'সার্ভিক্স সমস্যা ও সার্ভিকাল ইনফেকশন', 12),
 ('ovulation-disorder', 'ডিম্বস্ফোটন সমস্যা', 13),
 ('male-infertility', 'পুরুষ বন্ধ্যাত্ব', 14),
 ('pelvic-pain-syndrome', 'পেলভিক পেইন সিন্ড্রোম', 15),
 ('heavy-menstrual-bleeding', 'মাসিকের অতিরিক্ত রক্তপাত', 16),
 ('delayed-absent-periods', 'মাসিক বন্ধ বা দেরিতে হওয়া', 17),
 ('ovarian-function-weakness', 'ডিম্বাশয় ফাংশন দুর্বলতা', 18),
 ('uterine-polyps', 'পলিপ ও জরায়ুর অভ্যন্তরীণ সমস্যা', 19),
 ('gynae-cancer-screening-need', 'গাইনী ক্যান্সার স্ক্রিনিং প্রয়োজনীয়তা', 20);

INSERT IGNORE INTO treatments (slug, title, sort_order) VALUES
 ('ivf-art', 'IVF ও কৃত্রিম প্রজনন চিকিৎসা', 1),
 ('prenatal-pregnancy-care', 'প্রেগন্যান্সি ও প্রি-ন্যাটাল কেয়ার', 2),
 ('fertility-counselling', 'গর্ভধারণ-পূর্ব পরামর্শ ও ফার্টিলিটি কাউন্সেলিং', 3),
 ('family-planning-reproductive-health', 'প্রজনন স্বাস্থ্য ও পরিবার পরিকল্পনা', 4);

INSERT IGNORE INTO procedures (slug, title, sort_order) VALUES
 ('laparoscopic-surgery', 'ল্যাপারোস্কোপিক সার্জারি', 1),
 ('endoscopic-gynae-surgery', 'এন্ডোস্কোপিক গাইনোকোলজিক্যাল সার্জারি', 2),
 ('normal-delivery', 'নরমাল ডেলিভারি', 3),
 ('c-section', 'সিজারিয়ান ডেলিভারি', 4),
 ('colposcopy', 'কলপোস্কোপি', 5);

-- ---- Blog categories (old migrations did not assign any) ----
UPDATE blog_posts SET category = 'বন্ধ্যাত্ব'        WHERE slug IN ('infertility-causes-and-treatment-khulna');
UPDATE blog_posts SET category = 'গর্ভাবস্থা ও প্রসব' WHERE slug IN ('normal-delivery-vs-c-section','high-risk-pregnancy-care-guide');
UPDATE blog_posts SET category = 'গাইনী সমস্যা'     WHERE slug IN ('pcos-treatment-guide','white-discharge-causes-and-when-to-worry','endometriosis-symptoms-and-treatment','uterine-fibroid-symptoms-treatment','ovarian-cyst-symptoms-and-treatment','irregular-periods-menstrual-problems');
UPDATE blog_posts SET category = 'মেনোপজ'          WHERE slug IN ('menopause-symptoms-and-care');

-- ---- Operational FAQs (editable in admin; no clinical claims) ----
-- FAQ rows: no slug column, so each question is inserted only when it is not already there
INSERT INTO faqs (category, question, answer, sort_order)
SELECT 'অ্যাপয়েন্টমেন্ট', 'কীভাবে সিরিয়াল বা অ্যাপয়েন্টমেন্ট নেব?', 'ওয়েবসাইটের অ্যাপয়েন্টমেন্ট ফর্মে নাম ও মোবাইল নম্বর দিয়ে অনুরোধ পাঠান। আমাদের টিম আপনার সঙ্গে ফোন বা হোয়াটসঅ্যাপে যোগাযোগ করে সময় নিশ্চিত করবে।', 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM faqs WHERE question = 'কীভাবে সিরিয়াল বা অ্যাপয়েন্টমেন্ট নেব?');
INSERT INTO faqs (category, question, answer, sort_order)
SELECT 'অ্যাপয়েন্টমেন্ট', 'ফর্ম পাঠানোর পরই কি সিরিয়াল নিশ্চিত হয়?', 'না। ফর্ম পাঠানো মানে আপনার অনুরোধ আমাদের কাছে পৌঁছেছে। সময় নিশ্চিত করে আমাদের টিম আপনাকে জানাবে, তাই অনুগ্রহ করে সঠিক মোবাইল নম্বর দিন।', 2 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM faqs WHERE question = 'ফর্ম পাঠানোর পরই কি সিরিয়াল নিশ্চিত হয়?');
INSERT INTO faqs (category, question, answer, sort_order)
SELECT 'প্রথম ভিজিট', 'প্রথমবার আসার সময় কী কী সঙ্গে আনব?', 'আগের কোনো রিপোর্ট, আল্ট্রাসনোগ্রাম, প্রেসক্রিপশন এবং চলমান ওষুধের তালিকা সঙ্গে আনলে চিকিৎসকের মূল্যায়ন সহজ হয়।', 3 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM faqs WHERE question = 'প্রথমবার আসার সময় কী কী সঙ্গে আনব?');
INSERT INTO faqs (category, question, answer, sort_order)
SELECT 'প্রথম ভিজিট', 'পুরুষ সঙ্গী কি সঙ্গে আসতে পারবেন?', 'হ্যাঁ। বিশেষ করে বন্ধ্যাত্বের ক্ষেত্রে দম্পতির সম্মিলিত মূল্যায়ন গুরুত্বপূর্ণ, তাই স্বামী/স্ত্রী একসঙ্গে এলে ভালো।', 4 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM faqs WHERE question = 'পুরুষ সঙ্গী কি সঙ্গে আসতে পারবেন?');
INSERT INTO faqs (category, question, answer, sort_order)
SELECT 'জরুরি অবস্থা', 'জরুরি অবস্থায় কী করব?', 'তীব্র ব্যথা, অতিরিক্ত রক্তপাত বা গর্ভাবস্থায় হঠাৎ জটিলতা দেখা দিলে অ্যাপয়েন্টমেন্টের জন্য অপেক্ষা না করে নিকটস্থ হাসপাতালের জরুরি বিভাগে যান।', 5 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM faqs WHERE question = 'জরুরি অবস্থায় কী করব?');
INSERT INTO faqs (category, question, answer, sort_order)
SELECT 'ওয়েবসাইট', 'এই ওয়েবসাইটের লেখা কি চিকিৎসা পরামর্শ?', 'না। ওয়েবসাইটের তথ্য সাধারণ সচেতনতার জন্য। নিজের অবস্থার জন্য নির্দিষ্ট চিকিৎসা পরামর্শ পেতে সরাসরি চিকিৎসকের সঙ্গে যোগাযোগ করুন।', 6 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM faqs WHERE question = 'এই ওয়েবসাইটের লেখা কি চিকিৎসা পরামর্শ?');

-- ---------- Gallery (clinic / seminar / work photos) ----------
CREATE TABLE IF NOT EXISTS gallery_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT '',
  image VARCHAR(255) NOT NULL,
  caption VARCHAR(400) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_gallery_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- AI assistant conversation transcripts ----------
CREATE TABLE IF NOT EXISTS ai_conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lead_id INT UNSIGNED NULL,
  transcript MEDIUMTEXT NOT NULL,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SEO change log (created automatically by app/seo.php too; this is for fresh installs)
CREATE TABLE IF NOT EXISTS seo_change_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  url VARCHAR(255) NOT NULL,
  change_type VARCHAR(40) NOT NULL,
  detail VARCHAR(500) NULL,
  indexnow TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  INDEX idx_seo_created (created_at)
) DEFAULT CHARSET=utf8mb4;

-- ---------- Chambers / places where the doctor sits (admin-managed, serial order) ----------
CREATE TABLE IF NOT EXISTS chambers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  location TEXT NULL,
  visiting_hours VARCHAR(200) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  map_link VARCHAR(400) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_chambers_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: web-researched directory data, owner to verify in admin. Re-running never duplicates.
INSERT INTO chambers (name, location, visiting_hours, phone, map_link, sort_order, is_active)
SELECT 'Citizen Lab Doctor & Diagnostic', 'Islam Tower, 22 KDA Avenue, Moylapota Mor, Khulna', 'বিকাল ৩টা – ৫টা (শুক্রবার বন্ধ)', '', '', 1, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chambers WHERE name = 'Citizen Lab Doctor & Diagnostic');
INSERT INTO chambers (name, location, visiting_hours, phone, map_link, sort_order, is_active)
SELECT 'Khulna City Medical College & Hospital', '25/26, KDA Avenue, Moilapota Square, Khulna Sadar, Khulna', 'দুপুর ২টা – ৩টা (শুক্রবার বন্ধ)', '', '', 2, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chambers WHERE name = 'Khulna City Medical College & Hospital');
INSERT INTO chambers (name, location, visiting_hours, phone, map_link, sort_order, is_active)
SELECT 'Labaid Diagnostic, Khulna', 'KDA Plot No. A5, Majid Sarani, Sonadanga, Khulna', 'সন্ধ্যা ৭টা – ৯টা (শুক্রবার বন্ধ)', '', '', 3, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chambers WHERE name = 'Labaid Diagnostic, Khulna');

-- ---------- Notifications: Telegram channel (safe on old and new databases) ----------
ALTER TABLE notifications MODIFY channel ENUM('email','webhook','telegram') NOT NULL;

-- ---------- Future column helper ----------
-- MySQL has no "ADD COLUMN IF NOT EXISTS", so this small procedure checks first.
-- Usage for a future version (one line each, inside the section below):
--   CALL jebu_add_column('leads', 'source', "VARCHAR(40) NOT NULL DEFAULT ''");
DROP PROCEDURE IF EXISTS jebu_add_column;
DELIMITER //
CREATE PROCEDURE jebu_add_column(IN tbl VARCHAR(64), IN col VARCHAR(64), IN def TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col) THEN
    SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', def);
    PREPARE stmt FROM @ddl;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//
DELIMITER ;

-- ---------- Future versions: add new column/table statements below this line ----------
-- (currently none)

DROP PROCEDURE IF EXISTS jebu_add_column;
