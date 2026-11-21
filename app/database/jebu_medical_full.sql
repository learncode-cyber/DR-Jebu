-- =====================================================================
--  ডাঃ জেবুন্নেসা জেবু, ওয়েবসাইট ডাটাবেস (একটাই ফাইল, সব ভার্সনের জন্য)
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
--  ফোন/ইমেইল-এর চূড়ান্ত মান, এগুলো অ্যাডমিন প্যানেল বা config.php থেকে সেট হয়।
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
SELECT 'preconception-care-folic-acid-before-pregnancy', 'গর্ভধারণের আগে প্রস্তুতি', 'গর্ভধারণের প্রস্তুতি, ফলিক অ্যাসিড, প্রি-কনসেপশন', 'গর্ভধারণের আগে প্রস্তুতি: ফলিক অ্যাসিড ও প্রয়োজনীয় পরীক্ষা', 'গর্ভধারণের আগে প্রস্তুতি: ফলিক অ্যাসিড ও পরীক্ষা', 'গর্ভধারণের আগে কোন পরীক্ষা লাগে, ফলিক অ্যাসিড কখন ও কতটুকু নেবেন, এবং জীবনযাত্রায় কী বদলাতে হবে, স্ত্রীরোগ বিশেষজ্ঞের সাধারণ নির্দেশনা।', 'গর্ভধারণের আগে কী খাবেন, কোন পরীক্ষা করাবেন এবং ফলিক অ্যাসিড কেন জরুরি, সহজ ভাষায় বিস্তারিত।', 'গর্ভাবস্থা', '## গর্ভধারণের আগে কেন প্রস্তুতি দরকার?

গর্ভধারণের আগের কয়েক মাস মা ও শিশুর স্বাস্থ্যের ভিত্তি তৈরি করে। অনেক জটিলতা, যেমন শিশুর স্নায়ুনালির ত্রুটি বা গর্ভকালীন ডায়াবেটিস, আগে থেকে পরিকল্পনা করলে অনেক ক্ষেত্রে কমানো যায়। তাই গর্ভধারণের পরিকল্পনা থাকলে আগে একবার ডাক্তারের সঙ্গে কথা বলে নিন।

## ফলিক অ্যাসিড কেন জরুরি?

ফলিক অ্যাসিড (ভিটামিন বি৯) শিশুর মস্তিষ্ক ও মেরুদণ্ডের প্রাথমিক গঠনে ভূমিকা রাখে। গর্ভধারণের অনেক আগেই শিশুর নিউরাল টিউব তৈরি শুরু হয়, তাই গর্ভধারণের জানার আগেই এই ভিটামিন শুরু করা জরুরি।

- **মাত্রা:** সাধারণ নারীদের ক্ষেত্রে সাধারণত দৈনিক ৪০০ মাইক্রোগ্রাম (০.৪ মিগ্রা) ফলিক অ্যাসিড সুপারিশ করা হয়।
- **কখন শুরু করবেন:** গর্ভধারণের অন্তত এক মাস আগে থেকে শুরু করে প্রথম তিন মাস পর্যন্ত চালিয়ে যাওয়ার কথা বলা হয়।
- **উচ্চ মাত্রা:** আগের গর্ভে স্নায়ুনালির ত্রুটি হয়ে থাকলে, বা নির্দিষ্ট ওষুধ খেলে ডাক্তার বেশি মাত্রা লিখতে পারেন। এটি শুধু চিকিৎসকের পরামর্শে নিন।

ফলিক অ্যাসিড শুধু ওষুধ হিসেবে নয়, পালংশাক, মসুর ডাল, ছোলা, কলিজা ও সবুজ শাকসবজি থেকেও পাওয়া যায়। তবে শুধু খাবার থেকে যথেষ্ট পরিমাণ পাওয়া কঠিন, তাই ওষুধ প্রয়োজন হয়।

## গর্ভধারণের আগে কী কী পরীক্ষা করাবেন?

কোন পরীক্ষা লাগবে তা আপনার বয়স, মেডিকেল ইতিহাস ও আগের গর্ভধারণের অভিজ্ঞতার উপর নির্ভর করে। সাধারণত যেগুলো বিবেচনা করা হয়:

- রক্তের গ্রুপ ও Rh ফ্যাক্টর
- সম্পূর্ণ রক্ত পরীক্ষা (CBC), রক্তস্বল্পতা আছে কিনা
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

## সাধারণ কিছু প্রশ্ন

**গর্ভধারণের পর ফলিক অ্যাসিড শুরু করলে কি হবে?**
আগে থেকে শুরু করা সবচেয়ে ভালো। তবে গর্ভধারণের পর যত দ্রুত সম্ভব শুরু করাও উপকারী, তাই দেরি না করে ডাক্তারের পরামর্শ নিন।

**ফলিক অ্যাসিড কি গর্ভধারণের সম্ভাবনা বাড়ায়?**
ফলিক অ্যাসিড প্রধানত শিশুর স্নায়ুনালির ত্রুটি প্রতিরোধে কাজ করে। গর্ভধারণের সম্ভাবনা বাড়ানোর জন্য এটি নির্ধারিত ওষুধ নয়।

## সারসংক্ষেপ

গর্ভধারণের পরিকল্পনা থাকলে অন্তত এক মাস আগে ফলিক অ্যাসিড শুরু করুন এবং প্রয়োজনীয় পরীক্ষা করান। আপনার বয়স ও স্বাস্থ্য অনুযায়ী পরিকল্পনা আলাদা হয়, তাই নিজের ক্ষেত্রে কী করণীয় তা চিকিৎসককে জিজ্ঞেস করুন।

**তথ্যসূত্র:** CDC Folic Acid, https://www.cdc.gov/folic-acid/ · WHO, https://www.who.int', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 0 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM blog_posts WHERE slug = 'preconception-care-folic-acid-before-pregnancy');

INSERT INTO blog_posts (slug, category, tags, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, medically_reviewed_by, published_at)
SELECT 'first-trimester-pregnancy-care-tests', 'গর্ভাবস্থার প্রথম ৩ মাস', 'গর্ভাবস্থার প্রথম ত্রৈমাসিক, NT স্ক্যান, ডেটিং আল্ট্রাসাউন্ড, প্রেগন্যান্সি পরীক্ষা', 'গর্ভাবস্থার প্রথম ৩ মাস: কোন পরীক্ষা কখন করাবেন', 'প্রথম ৩ মাসের পরীক্ষা ও সতর্ক সংকেত', 'গর্ভধারণ নিশ্চিত হলে প্রথম ভিজিটে কী হয়, ডেটিং আল্ট্রাসাউন্ড ও NT স্ক্যান কেন দরকার, এবং কোন লক্ষণে জরুরি চিকিৎসা নিতে হবে।', 'প্রথম ৩ মাসে কোন পরীক্ষা কখন করাবেন, NT স্ক্যান কী এবং কোন লক্ষণে দ্রুত ডাক্তার দেখাবেন, গর্ভবতী মায়ের জন্য সহজ গাইড।', 'গর্ভাবস্থা', '## গর্ভাবস্থার প্রথম ৩ মাস কেন গুরুত্বপূর্ণ?

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

গর্ভধারণ নিশ্চিত হলে দ্রুত একজন স্ত্রীরোগ বিশেষজ্ঞের কাছে নিবন্ধন করুন। ডেটিং আল্ট্রাসাউন্ড, প্রয়োজনীয় রক্ত পরীক্ষা ও NT স্ক্যান সময়মতো করান, এবং উপরে উল্লেখিত সতর্ক সংকেত দেখা দিলে দেরি না করে চিকিৎসকের কাছে যান। এই লেখাটি সাধারণ ধারণা দেওয়ার জন্য। নিজের অবস্থা নিয়ে চিকিৎসকের সঙ্গে কথা বলুন।

**তথ্যসূত্র:** ACOG, https://www.acog.org · WHO, https://www.who.int', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 1 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM blog_posts WHERE slug = 'first-trimester-pregnancy-care-tests');

INSERT INTO blog_posts (slug, category, tags, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, medically_reviewed_by, published_at)
SELECT 'gestational-diabetes-screening-and-control', 'গর্ভকালীন ডায়াবেটিস', 'গর্ভকালীন ডায়াবেটিস, OGTT, গর্ভাবস্থায় ডায়াবেটিস, রক্তে শর্করা', 'গর্ভকালীন ডায়াবেটিস: পরীক্ষা, ঝুঁকি ও নিয়ন্ত্রণ', 'গর্ভকালীন ডায়াবেটিস: পরীক্ষা ও নিয়ন্ত্রণ', 'গর্ভকালীন ডায়াবেটিস কী, কোন সময়ে স্ক্রিনিং হয়, ৭৫ গ্রাম OGTT পরীক্ষার পদ্ধতি, ঝুঁকি এবং নিয়ন্ত্রণের উপায়।', 'গর্ভকালীন ডায়াবেটিস কখন পরীক্ষা করাবেন, OGTT কী, এবং খাদ্য ও ব্যায়ামে কীভাবে রক্তে শর্করা নিয়ন্ত্রণে রাখবেন।', 'গর্ভাবস্থা', '## গর্ভকালীন ডায়াবেটিস কী?

গর্ভাবস্থায় প্রথমবার রক্তে শর্করার মাত্রা বেড়ে গেলে তাকে গর্ভকালীন ডায়াবেটিস (Gestational Diabetes Mellitus, GDM) বলা হয়। গর্ভাবস্থার হরমোন ইনসুলিনের কার্যকারিতা কিছুটা কমিয়ে দেয়, আর সেই সময় অগ্ন্যাশয় যথেষ্ট ইনসুলিন তৈরি করতে না পারলে রক্তে শর্করা বাড়ে। সময়মতো পরীক্ষা করালে ও নিয়ন্ত্রণে রাখলে মা ও শিশু দুজনের ঝুঁকি অনেকটাই কমানো যায়।

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
৪. **ওষুধ:** খাদ্য ও ব্যায়ামে যথেষ্ট না হলে চিকিৎসক ইনসুলিন বা অন্য ওষুধ লিখতে পারেন। প্রয়োজন হলে ইনসুলিন নেওয়া নিরাপদ, তাই চিকিৎসকের নির্দেশ মেনে চলুন।

## নিজে কী খেয়াল রাখবেন?

খুব বেশি তৃষ্ণা, ঘন ঘন প্রস্রাব, ঝাপসা দেখা, অস্বাভাবিক দুর্বলতা বা অতিরিক্ত বমি হলে দ্রুত চিকিৎসকের সাথে যোগাযোগ করুন। রক্তে শর্করা খুব কমে গেলে (হাত কাঁপা, ঘাম, মাথা ঘোরা) মিষ্টি খাবার খেয়ে দ্রুত ডাক্তারকে জানান।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন

**গর্ভকালীন ডায়াবেটিস হলে কি স্বাভাবিক ডেলিভারি সম্ভব?**
হ্যাঁ, সঠিক নিয়ন্ত্রণে বেশিরভাগ নারীর স্বাভাবিক ডেলিভারি হতে পারে। প্রসবের সময় ও পদ্ধতি চিকিৎসক শিশুর ওজন ও মায়ের অবস্থা বিবেচনা করে ঠিক করেন।

**ডেলিভারির পর কি ডায়াবেটিস থাকবে?**
অনেক ক্ষেত্রে ডেলিভারির পর শর্করা স্বাভাবিক হয়ে যায়, তবে ভবিষ্যতে ডায়াবেটিস হওয়ার ঝুঁকি বেশি থাকে। তাই প্রসবের পর ৬-১২ সপ্তাহে আবার পরীক্ষা করানো জরুরি।

## সারসংক্ষেপ

গর্ভকালীন ডায়াবেটিস নিয়ন্ত্রণে রাখা যায় এমন একটি অবস্থা। ২৪-২৮ সপ্তাহে স্ক্রিনিং করান, নিয়মিত চিকিৎসকের সাথে যোগাযোগ রাখুন, এবং খাদ্য ও শারীরিক কার্যকলাপ ঠিক রাখুন। আপনার চিকিৎসার সিদ্ধান্ত চিকিৎসকের সঙ্গে আলোচনা করে নিন।

**তথ্যসূত্র:** American Diabetes Association Standards of Care, https://diabetesjournals.org/care · ACOG, https://www.acog.org', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 2 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM blog_posts WHERE slug = 'gestational-diabetes-screening-and-control');

INSERT INTO blog_posts (slug, category, tags, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, medically_reviewed_by, published_at)
SELECT 'cervical-cancer-prevention-hpv-test-vaccine', 'জরায়ুমুখ ক্যান্সার প্রতিরোধ', 'জরায়ুমুখ ক্যান্সার, পাপ স্মিয়ার, HPV টেস্ট, HPV টিকা', 'জরায়ুমুখ ক্যান্সার প্রতিরোধ: পাপ স্মিয়ার, HPV টেস্ট ও টিকা', 'জরায়ুমুখ ক্যান্সার প্রতিরোধ গাইড', 'পাপ স্মিয়ার ও HPV টেস্টের পার্থক্য, কখন স্ক্রিনিং করাবেন, HPV টিকা কার জন্য, এবং কোন লক্ষণে দেরি না করে পরীক্ষা করাবেন।', 'জরায়ুমুখ ক্যান্সার প্রতিরোধে পাপ স্মিয়ার, HPV টেস্ট ও HPV টিকার গুরুত্ব, নারীদের জন্য সহজ গাইড।', 'নারী স্বাস্থ্য', '## জরায়ুমুখ ক্যান্সার কী?

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

প্রাথমিক পর্যায়ে ধরা পড়লে চিকিৎসা অনেক সহজ এবং ফলাফল ভালো হয়। অনেক ক্ষেত্রে অস্বাভাবিক কোষ অপসারণের ছোট চিকিৎসা দিয়েই সমস্যার সমাধান হয়। তাই লক্ষণ না থাকলেও নির্ধারিত সময়ে স্ক্রিনিং করান।

## সাধারণ কিছু প্রশ্ন

**বিবাহিত না হলে কি এই পরীক্ষা লাগবে?**
HPV সংক্রমণ যৌন সম্পর্কের মাধ্যমে ছড়ায়। আপনার অবস্থা ও প্রয়োজন চিকিৎসক বুঝে পরামর্শ দেবেন।

**পাপ স্মিয়ার কি ব্যথা দেয়?**
সাধারণত সামান্য অস্বস্তি হয়, তবে তীব্র ব্যথা হয় না। পরীক্ষার আগে চিকিৎসককে জানালে তিনি আরামদায়কভাবে করতে সাহায্য করবেন।

**পরীক্ষা নেতিবাচক এলে কি আর পরীক্ষা লাগবে না?**
নির্ধারিত সময় পর আবার স্ক্রিনিং প্রয়োজন হতে পারে। চিকিৎসক আপনাকে সঠিক সময়সূচি জানাবেন।

## সারসংক্ষেপ

জরায়ুমুখ ক্যান্সার প্রতিরোধযোগ্য। নিয়মিত স্ক্রিনিং, বয়স উপযোগী HPV টিকা, এবং অস্বাভাবিক লক্ষণে দ্রুত পরামর্শ, এই তিনটি সবচেয়ে কার্যকর পদক্ষেপ। এই লেখাটি সচেতনতার জন্য। নিজের পরীক্ষা ও সিদ্ধান্ত নিয়ে চিকিৎসকের সঙ্গে কথা বলুন।

**তথ্যসূত্র:** WHO Cervical cancer fact sheet, https://www.who.int/news-room/fact-sheets/detail/cervical-cancer · ACOG, https://www.acog.org', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 3 DAY FROM DUAL
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

## সাফল্য নিয়ে বাস্তব ধারণা

IVF-এর সাফল্য একেকজনের ক্ষেত্রে আলাদা। বয়স, ডিম্বাণুর মান, বন্ধ্যাত্বের কারণ এবং ক্লিনিকের অভিজ্ঞতা সবকিছু প্রভাব ফেলে। বয়স বাড়লে সাধারণত সাফল্যের হার কমে, এবং অনেক দম্পতিকে একাধিকবার চেষ্টা করতে হয়। কোনো ক্লিনিক যদি নিশ্চিত সাফল্যের গ্যারান্টি দেয়, তবে সেটি নিয়ে সতর্ক থাকুন। আপনার ক্ষেত্রে সম্ভাব্য ফলাফল কেমন হতে পারে তা চিকিৎসকের সাথে আলোচনা করে জেনে নিন।

## ঝুঁকি ও জটিলতা

- **ডিম্বাশয় অতি-উদ্দীপনা (OHSS):** ওষুধের প্রতিক্রিয়ায় পেট ফোলা, ব্যথা বা অস্বস্তি হতে পারে। গুরুতর ক্ষেত্রে চিকিৎসার প্রয়োজন হয়।
- **একাধিক গর্ভ:** একসাথে একাধিক ভ্রূণ স্থাপনের কারণে যমজ বা তার বেশি গর্ভ হতে পারে, যা মা ও শিশু উভয়ের জন্য ঝুঁকিপূর্ণ। তাই আধুনিক পদ্ধতিতে সাধারণত একটি ভ্রূণ স্থাপনের চেষ্টা করা হয়।
- **মানসিক চাপ:** বারবার প্রচেষ্টা ও আর্থিক খরচের কারণে মানসিক চাপ হতে পারে। সঙ্গী ও চিকিৎসকের সাথে খোলামেলা কথা বলা সহায়ক।

## সাধারণ কিছু প্রশ্ন

**IVF-এর খরচ কেমন?**
খরচ ক্লিনিক, চিকিৎসার ধরন ও প্রয়োজনীয় ওষুধের উপর নির্ভর করে। শুরুতেই বিস্তারিত খরচের তালিকা চেয়ে নিন।

**IVF কি ব্যথাদায়ক?**
ডিম্বাণু সংগ্রহের সময় সেডেশন দেওয়া হয়, তাই সাধারণত ব্যথা কম হয়। পরবর্তীতে হালকা অস্বস্তি হতে পারে।

**IVF কি ব্যর্থ হলে আর সুযোগ নেই?**
একটি চেষ্টা সফল না হলেও চিকিৎসক কারণ বিশ্লেষণ করে পরবর্তী পরিকল্পনা ঠিক করতে পারেন।

## সারসংক্ষেপ

IVF বন্ধ্যাত্বের একটি কার্যকর চিকিৎসা হতে পারে, তবে এর জন্য সঠিক মূল্যায়ন, বাস্তব প্রত্যাশা ও চিকিৎসকের নিয়মিত তত্ত্বাবধান দরকার। প্রথমে কারণ জানুন, সব বিকল্প বুঝুন, তারপর সিদ্ধান্ত নিন। নিজের ক্ষেত্রে কী করা যায়, তা জানতে খুলনায় চিকিৎসকের সঙ্গে সরাসরি আলোচনা করুন।

**তথ্যসূত্র:** ASRM (Reproductive Facts), https://www.reproductivefacts.org · ESHRE, https://www.eshre.eu', '', 'published', 'index', 'ডাঃ জেবুন্নেসা জেবু', NOW() - INTERVAL 4 DAY FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM blog_posts WHERE slug = 'ivf-treatment-what-to-know');


-- ---- Blog posts (10 published; from old migrations 004/008/010 + 006 + 009) ----
INSERT IGNORE INTO blog_posts (slug, title, excerpt, body, cover_image, status, published_at) VALUES ('pcos-treatment-guide', 'PCOS (পলিসিস্টিক ওভারি সিন্ড্রোম): কারণ, লক্ষণ ও চিকিৎসা', 'PCOS বা পলিসিস্টিক ওভারি সিন্ড্রোম কী, কেন হয়, লক্ষণ কী কী এবং আধুনিক চিকিৎসায় কীভাবে নিয়ন্ত্রণ করা যায়। বিস্তারিত জানুন।', 'PCOS বা পলিসিস্টিক ওভারি সিন্ড্রোম নারীদের একটি সাধারণ হরমোনজনিত সমস্যা, যা প্রজনন বয়সের নারীদের একটি উল্লেখযোগ্য অংশকে প্রভাবিত করে। এটি শুধু মাসিক চক্রের সমস্যা নয়। এর প্রভাব পড়তে পারে ওজন, ত্বক, চুল এবং ভবিষ্যতে গর্ভধারণের সক্ষমতার উপরেও।

## PCOS কী?

PCOS একটি হরমোনজনিত অবস্থা, যেখানে ডিম্বাশয়ে পুরুষ হরমোন (অ্যান্ড্রোজেন) স্বাভাবিকের চেয়ে বেশি তৈরি হয়। এর ফলে ডিম্বস্ফোটন (ovulation) অনিয়মিত হয়ে পড়ে এবং ডিম্বাশয়ে ছোট ছোট সিস্টের মতো গঠন দেখা দিতে পারে।

## সাধারণ লক্ষণসমূহ

- অনিয়মিত মাসিক বা মাসিক বন্ধ থাকা
- মুখে ও শরীরে অতিরিক্ত লোম (হারসুটিজম)
- ব্রণ ও তৈলাক্ত ত্বক
- অতিরিক্ত ওজন বৃদ্ধি, বিশেষত পেটের অংশে
- চুল পাতলা হয়ে যাওয়া
- গর্ভধারণে বিলম্ব বা অসুবিধা

সব লক্ষণ একসাথে সবার মধ্যে দেখা যায় না, একেকজনের ক্ষেত্রে তীব্রতা একেক রকম হতে পারে।

## কেন হয়?

সঠিক কারণ এখনও সম্পূর্ণ জানা না গেলেও কয়েকটি বিষয় গুরুত্বপূর্ণ ভূমিকা রাখে বলে মনে করা হয়:

- **ইনসুলিন রেজিস্ট্যান্স**, শরীর ইনসুলিন ঠিকভাবে ব্যবহার করতে না পারা, যা অ্যান্ড্রোজেন উৎপাদন বাড়িয়ে দেয়
- **বংশগত প্রবণতা**, পরিবারে কারও থাকলে ঝুঁকি বেশি
- **ওজন ও জীবনযাত্রা**, অতিরিক্ত ওজন উপসর্গ বাড়াতে পারে

## নির্ণয় কীভাবে হয়?

সাধারণত তিনটি বিষয় মূল্যায়ন করে PCOS নির্ণয় করা হয়: মাসিকের ধরন, রক্ত পরীক্ষায় হরমোনের মাত্রা, এবং আল্ট্রাসনোগ্রামে ডিম্বাশয়ের গঠন। তিনটির মধ্যে অন্তত দুটি মিললে PCOS নির্ণয় করা হয়।

## চিকিৎসা

PCOS-এর চিকিৎসা নির্ভর করে রোগীর বয়স, লক্ষণ এবং তিনি গর্ভধারণ করতে চান কিনা তার উপর।

### জীবনযাত্রায় পরিবর্তন

সুষম খাদ্যাভ্যাস ও নিয়মিত ব্যায়ামের মাধ্যমে ওজন ৫-১০% কমানো গেলেও অনেক ক্ষেত্রে মাসিক নিয়মিত হতে শুরু করে এবং উপসর্গ কমে।

### ওষুধ

মাসিক নিয়ন্ত্রণে হরমোনাল চিকিৎসা, ইনসুলিন সংবেদনশীলতা বাড়াতে নির্দিষ্ট ওষুধ, এবং গর্ভধারণে সহায়তার জন্য ডিম্বস্ফোটন উদ্দীপক ওষুধ ব্যবহার করা হয়, চিকিৎসকের পরামর্শ অনুযায়ী।

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

PCOS সাধারণ কিন্তু ব্যবস্থাপনাযোগ্য একটি সমস্যা। প্রাথমিক পর্যায়ে শনাক্ত করা গেলে জীবনযাত্রার পরিবর্তন ও সঠিক চিকিৎসার মাধ্যমে ভালো ফলাফল পাওয়া সম্ভব। এই লেখাটি সাধারণ ধারণার জন্য। নিজের অবস্থা নিয়ে চিকিৎসকের সঙ্গে কথা বলুন।', '/uploads/cover-pcos-treatment-guide.jpg', 'published', NOW());

INSERT IGNORE INTO blog_posts (slug, title, excerpt, body, cover_image, status, published_at) VALUES ('normal-delivery-vs-c-section', 'নরমাল ডেলিভারি নাকি সিজারিয়ান? কোনটি কখন প্রয়োজন', 'নরমাল ডেলিভারি ও সিজারিয়ান সেকশনের পার্থক্য, কোন পরিস্থিতিতে কোনটি বেছে নেওয়া হয়, এবং উভয়ের ঝুঁকি-সুবিধা সম্পর্কে জানুন।', 'গর্ভাবস্থার শেষ দিকে প্রায় প্রতিটি পরিবারের মনেই একটি প্রশ্ন ঘোরে। সন্তান জন্ম হবে স্বাভাবিকভাবে (নরমাল ডেলিভারি), নাকি অস্ত্রোপচারের মাধ্যমে (সিজারিয়ান সেকশন)। সিদ্ধান্তটা মূলত মা ও শিশুর নিরাপত্তার কথা ভেবে নেওয়া হয়।

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

গর্ভাবস্থা চলাকালীন নিয়মিত চেকআপ, আল্ট্রাসনোগ্রাম এবং প্রসবের সময়কার পর্যবেক্ষণের ভিত্তিতে চিকিৎসক সিদ্ধান্ত নেন কোন পদ্ধতি মা ও শিশুর জন্য সবচেয়ে নিরাপদ। অনেক সময় প্রসব শুরু হওয়ার পরও পরিস্থিতি বুঝে পরিকল্পনা বদলাতে হয়। এটি চিকিৎসাগত সিদ্ধান্তেরই অংশ।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**সিজারিয়ান কি নরমাল ডেলিভারির চেয়ে বেশি ঝুঁকিপূর্ণ?**
সিজারিয়ান একটি অস্ত্রোপচার হওয়ায় এতে কিছু বাড়তি ঝুঁকি থাকে, তবে প্রয়োজনের সময় এটিই মা ও শিশুর জন্য সবচেয়ে নিরাপদ পথ।

**একবার সিজারিয়ান হলে কি পরের বার সবসময় সিজারিয়ানই করতে হয়?**
সবসময় নয়, নির্দিষ্ট শর্ত পূরণ হলে পরবর্তী গর্ভাবস্থায় নরমাল ডেলিভারির চেষ্টা করা সম্ভব হতে পারে; এটি চিকিৎসকের মূল্যায়নের উপর নির্ভর করে।

**কোন পদ্ধতি বেছে নেওয়া রোগীর নিজের সিদ্ধান্ত?**
চূড়ান্ত সিদ্ধান্ত সবসময় মা ও শিশুর চিকিৎসাগত অবস্থার উপর ভিত্তি করে নেওয়া উচিত, যা একজন অভিজ্ঞ প্রসূতি বিশেষজ্ঞই ভালো বলতে পারেন।

## সারসংক্ষেপ

নরমাল ডেলিভারি ও সিজারিয়ান, দুটোই নিরাপদ প্রসব পদ্ধতি, যখন তা সঠিক পরিস্থিতিতে ও যোগ্য চিকিৎসকের তত্ত্বাবধানে করা হয়। গর্ভাবস্থায় নিয়মিত চেকআপ করানোই সবচেয়ে গুরুত্বপূর্ণ, যাতে সময়মতো সঠিক সিদ্ধান্ত নেওয়া যায়।', '/uploads/cover-normal-delivery-vs-c-section.jpg', 'published', NOW());

INSERT IGNORE INTO blog_posts (slug, title, excerpt, body, cover_image, status, published_at) VALUES ('white-discharge-causes-and-when-to-worry', 'সাদা স্রাব: কখন স্বাভাবিক, কখন চিকিৎসকের কাছে যাবেন', 'সাদা স্রাব বেশিরভাগ সময় স্বাভাবিক হলেও কিছু ক্ষেত্রে এটি সংক্রমণ বা অন্য সমস্যার লক্ষণ হতে পারে। পার্থক্য বোঝার উপায় জানুন।', 'সাদা স্রাব নিয়ে অনেক নারীই দ্বিধায় থাকেন। এটা কি স্বাভাবিক, নাকি কোনো সমস্যার লক্ষণ? বাস্তবে, সামান্য সাদা স্রাব যোনিপথের স্বাভাবিক পরিষ্কার-পরিচ্ছন্নতা প্রক্রিয়ার অংশ। তবে এর রং, গন্ধ বা পরিমাণে পরিবর্তন এলে তা মনোযোগ দেওয়ার প্রয়োজন হতে পারে।

## স্বাভাবিক সাদা স্রাব কেমন হয়?

স্বাভাবিক স্রাব সাধারণত এমন হয়:

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

- **ইস্ট ইনফেকশন**, চুলকানি ও ঘন সাদা স্রাবের সাধারণ কারণ
- **ব্যাকটেরিয়াল ভ্যাজাইনোসিস**, গন্ধযুক্ত স্রাবের অন্যতম কারণ
- **যৌনবাহিত সংক্রমণ**, কিছু ক্ষেত্রে স্রাবের পরিবর্তনের কারণ হতে পারে
- **হরমোনজনিত পরিবর্তন**, গর্ভাবস্থা, জন্মনিয়ন্ত্রণ পদ্ধতি বা মাসিক চক্রের কারণে

## করণীয়

- অন্তর্বাস প্রতিদিন পরিবর্তন করুন ও সুতির অন্তর্বাস ব্যবহার করুন
- অতিরিক্ত সাবান বা সুগন্ধিযুক্ত পণ্য দিয়ে যোনিপথ পরিষ্কার করা এড়িয়ে চলুন, এটি স্বাভাবিক ব্যাকটেরিয়ার ভারসাম্য নষ্ট করতে পারে
- উপসর্গ ২-৩ দিনের বেশি স্থায়ী হলে বা তীব্র হলে চিকিৎসকের পরামর্শ নিন

স্ব-চিকিৎসা হিসেবে দোকান থেকে ওষুধ কিনে ব্যবহার করার আগে সঠিক কারণ নির্ণয় জরুরি, কারণ ভুল ওষুধ সমস্যা আরও জটিল করে তুলতে পারে।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**সাদা স্রাব কি সবসময় সংক্রমণের লক্ষণ?**
না। হালকা, গন্ধহীন সাদা স্রাব সাধারণত স্বাভাবিক এবং চিন্তার কারণ নয়।

**গর্ভাবস্থায় সাদা স্রাব বেড়ে যাওয়া কি স্বাভাবিক?**
হ্যাঁ, গর্ভাবস্থায় হরমোনের পরিবর্তনের কারণে স্রাব কিছুটা বেড়ে যাওয়া সাধারণ ব্যাপার, তবে রং বা গন্ধে পরিবর্তন এলে চিকিৎসকের পরামর্শ নেওয়া উচিত।

**কখন দেরি না করে ডাক্তার দেখানো জরুরি?**
দুর্গন্ধযুক্ত স্রাব, তীব্র চুলকানি, তলপেটে ব্যথা বা জ্বরসহ স্রাব দেখা দিলে দ্রুত চিকিৎসকের পরামর্শ নেওয়া উচিত।

## সারসংক্ষেপ

সাদা স্রাব বেশিরভাগ ক্ষেত্রে স্বাভাবিক শারীরবৃত্তীয় প্রক্রিয়া। তবে রং, গন্ধ বা উপসর্গে পরিবর্তন এলে তা উপেক্ষা না করে একজন স্ত্রীরোগ বিশেষজ্ঞের পরামর্শ নেওয়াই নিরাপদ। এই লেখাটি শুধু তথ্যের জন্য। নিজে রোগ ঠিক করার চেয়ে চিকিৎসকের পরামর্শ বেশি কাজের।', '/uploads/cover-white-discharge-causes-and-when-to-worry.jpg', 'published', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('infertility-causes-and-treatment-khulna', 'বন্ধ্যাত্ব: কারণ, পরীক্ষা ও চিকিৎসার সহজ ব্যাখ্যা', 'বন্ধ্যাত্ব চিকিৎসা। কারণ, পরীক্ষা ও সমাধান। ডাঃ জেবুন্নেসা জেবু', 'বন্ধ্যাত্বের কারণ কী, স্বামী-স্ত্রী উভয়ের কোন পরীক্ষা প্রয়োজন এবং IVF সহ আধুনিক চিকিৎসা পদ্ধতি সম্পর্কে বিস্তারিত জানুন।', 'বন্ধ্যাত্বের কারণ কী, স্বামী-স্ত্রী উভয়ের কোন পরীক্ষা প্রয়োজন এবং IVF সহ আধুনিক চিকিৎসা পদ্ধতি সম্পর্কে বিস্তারিত জানুন।', 'বন্ধ্যাত্ব চিকিৎসা', 'সন্তান না হওয়ার দুশ্চিন্তা একটা দম্পতির জন্য মানসিকভাবে অনেক কঠিন একটা সময়। ভালো খবর হলো, বন্ধ্যাত্বের প্রায় সব কারণই আজকাল চিহ্নিত করা যায়, এবং বেশিরভাগ ক্ষেত্রেই চিকিৎসা সম্ভব।

## বন্ধ্যাত্ব কাকে বলে?

সাধারণত এক বছর নিয়মিত (গর্ভনিরোধক ছাড়া) চেষ্টা করার পরও গর্ভধারণ না হলে তাকে বন্ধ্যাত্ব বলা হয়। স্ত্রীর বয়স ৩৫-এর বেশি হলে ৬ মাস পরই পরীক্ষা শুরু করা ভালো।

## এটা কি শুধু নারীর সমস্যা?

না, এটা একটা সাধারণ ভুল ধারণা। বন্ধ্যাত্বের কারণ:

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
স্বামীর জন্য: সিমেন অ্যানালাইসিস (শুক্রাণু পরীক্ষা)। এটি সহজ ও ব্যথাহীন পরীক্ষা, কিন্তু লজ্জায় অনেকেই এটি করাতে দেরি করেন।

## চিকিৎসার ধাপ

চিকিৎসা নির্ভর করে কারণের উপর:

- **ডিম্বস্ফোটন সমস্যায়**: ওষুধ দিয়ে ডিম্বস্ফোটন নিয়ন্ত্রণ
- **টিউব সমস্যায়**: প্রয়োজনে ল্যাপারোস্কোপিক সার্জারি
- **পুরুষের হালকা সমস্যায়**: IUI (Intrauterine Insemination)
- **জটিল ক্ষেত্রে**: IVF (In Vitro Fertilization), যেখানে ডিম্বাণু ও শুক্রাণু ল্যাবে মিলিত করে ভ্রূণ জরায়ুতে বসানো হয়

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

বন্ধ্যাত্ব কারও একার দোষ নয়। এটি চিকিৎসাযোগ্য একটি অবস্থা, যেখানে দম্পতি দুজনেরই মূল্যায়ন দরকার। দ্রুত পরামর্শ নিলে চিকিৎসার সময়ও কমে আসে। এই লেখাটি শুধু তথ্যের জন্য। ব্যক্তিগত মূল্যায়নের জন্য চিকিৎসকের সঙ্গে কথা বলুন।', '/uploads/cover-infertility-causes-and-treatment-khulna.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('endometriosis-symptoms-and-treatment', 'এন্ডোমেট্রিওসিস: কেন হয়, লক্ষণ ও চিকিৎসা', 'এন্ডোমেট্রিওসিস চিকিৎসা। লক্ষণ ও কারণ। ডাঃ জেবুন্নেসা জেবু', 'এন্ডোমেট্রিওসিস কী, কেন তীব্র মাসিক ব্যথা হয়, এবং কীভাবে নির্ণয় ও চিকিৎসা করা হয় তা বিস্তারিত জানুন।', 'এন্ডোমেট্রিওসিস কী, কেন তীব্র মাসিক ব্যথা হয়, এবং কীভাবে নির্ণয় ও চিকিৎসা করা হয় তা বিস্তারিত জানুন।', 'এন্ডোমেট্রিওসিস চিকিৎসা', '"প্রতি মাসে এমন ব্যথা হয় যে কাজে যেতে পারি না"। এটা এন্ডোমেট্রিওসিসের সবচেয়ে সাধারণ অভিযোগ। দুঃখজনকভাবে অনেকেই এটাকে "স্বাভাবিক মাসিক ব্যথা" ভেবে বছরের পর বছর সহ্য করে যান।

## এন্ডোমেট্রিওসিস কী?

জরায়ুর ভেতরের আস্তরণের (এন্ডোমেট্রিয়াম) মতো টিস্যু জরায়ুর বাইরে, ডিম্বাশয়, ফ্যালোপিয়ান টিউব, বা পেটের অন্য জায়গায় বেড়ে উঠলে তাকে এন্ডোমেট্রিওসিস বলে। এই টিস্যুও মাসিক চক্র অনুযায়ী রক্তক্ষরণ করে, কিন্তু শরীর থেকে বের হতে পারে না, ফলে ব্যথা ও প্রদাহ তৈরি হয়।

## প্রধান লক্ষণ

- তীব্র মাসিক ব্যথা, যা সাধারণ ব্যথানাশকে কমে না
- সহবাসের সময় ব্যথা
- দীর্ঘস্থায়ী পেলভিক (তলপেটের) ব্যথা
- অতিরিক্ত মাসিক রক্তস্রাব
- গর্ভধারণে বিলম্ব বা অসুবিধা
- মলত্যাগ বা প্রস্রাবের সময় ব্যথা (তীব্র ক্ষেত্রে)

কিছু নারীর ক্ষেত্রে কোনো লক্ষণই থাকে না, আবার কারও ক্ষেত্রে সামান্য এন্ডোমেট্রিওসিসেও তীব্র ব্যথা হয়, তীব্রতা আর রোগের ব্যাপ্তি সবসময় সমানুপাতিক নয়।

## কেন হয়?

সঠিক কারণ সম্পূর্ণ জানা নেই, তবে কিছু তত্ত্ব প্রচলিত:

- **রেট্রোগ্রেড মেনস্ট্রুয়েশন**, মাসিক রক্ত উল্টো দিকে টিউব দিয়ে পেটে চলে যাওয়া
- **বংশগত প্রবণতা**
- **রোগ প্রতিরোধ ব্যবস্থার সমস্যা**

## নির্ণয় কীভাবে হয়?

- বিস্তারিত ইতিহাস ও শারীরিক পরীক্ষা
- আল্ট্রাসনোগ্রাম (কিছু ক্ষেত্রে সনাক্ত করা যায়)
- নিশ্চিতভাবে নির্ণয়ের জন্য **ল্যাপারোস্কোপি**, একটি ছোট অস্ত্রোপচার পদ্ধতি, যেখানে সরাসরি দেখে এবং প্রয়োজনে টিস্যু পরীক্ষা করে নিশ্চিত করা হয়

## চিকিৎসার পদ্ধতি

চিকিৎসা নির্ভর করে বয়স, লক্ষণের তীব্রতা এবং গর্ভধারণের ইচ্ছার উপর:

### ওষুধের মাধ্যমে
ব্যথানাশক এবং হরমোনাল চিকিৎসা (জন্মনিয়ন্ত্রণ পিল, বা নির্দিষ্ট হরমোন থেরাপি) দিয়ে উপসর্গ নিয়ন্ত্রণ করা যায়।

### ল্যাপারোস্কোপিক সার্জারি
এন্ডোমেট্রিওসিসের টিস্যু অপসারণ করে ব্যথা কমানো যায় এবং গর্ভধারণেও সহায়তা হতে পারে। বিশেষত ওষুধে কাজ না হলে বা বন্ধ্যাত্ব সমস্যা থাকলে এটি বিবেচনা করা হয়।

### গর্ভধারণ পরিকল্পনায়
এন্ডোমেট্রিওসিস থাকা অবস্থায় গর্ভধারণের ইচ্ছা থাকলে সার্জারি বা সহায়ক প্রজনন পদ্ধতি (IUI/IVF) বিবেচনা করা হতে পারে, প্রতিটি রোগীর ক্ষেত্রে আলাদাভাবে এটি বিবেচনা করা হয়।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**তীব্র মাসিক ব্যথা মানেই কি এন্ডোমেট্রিওসিস?**
সবসময় না, কিন্তু সাধারণ ব্যথানাশকে না কমা তীব্র ব্যথা হলে অবশ্যই একজন স্ত্রীরোগ বিশেষজ্ঞকে দেখানো উচিত।

**এন্ডোমেট্রিওসিস থাকলে কি গর্ভধারণ অসম্ভব?**
না, তবে কিছুটা কঠিন হতে পারে। সঠিক চিকিৎসায় অনেক নারীই স্বাভাবিকভাবে বা সহায়ক পদ্ধতিতে গর্ভধারণ করতে পারেন।

**এটা কি বংশগত?**
পরিবারে থাকলে ঝুঁকি কিছুটা বেশি থাকে, তবে সবার ক্ষেত্রে বংশগতভাবে হয় না।

## সারসংক্ষেপ

তীব্র মাসিক ব্যথাকে "স্বাভাবিক" ভেবে সহ্য করে যাওয়া উচিত না। প্রাথমিক পর্যায়ে নির্ণয় হলে চিকিৎসা সহজ হয় এবং ভবিষ্যতের জটিলতা এড়ানো যায়। এই লেখাটি শুধু তথ্যের জন্য। নিজের অবস্থা নিয়ে চিকিৎসকের পরামর্শ নিন।', '/uploads/cover-endometriosis-symptoms-and-treatment.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('high-risk-pregnancy-care-guide', 'হাই রিস্ক প্রেগন্যান্সি: কোন গর্ভাবস্থাকে ঝুঁকিপূর্ণ বলা হয়', 'হাই রিস্ক প্রেগন্যান্সি ব্যবস্থাপনা। ডাঃ জেবুন্নেসা জেবু', 'হাই রিস্ক প্রেগন্যান্সি কী, কোন কোন কারণে গর্ভাবস্থা ঝুঁকিপূর্ণ হয়ে ওঠে এবং কীভাবে নিরাপদে সামলানো যায়। জানুন।', 'হাই রিস্ক প্রেগন্যান্সি কী, কোন কোন কারণে গর্ভাবস্থা ঝুঁকিপূর্ণ হয়ে ওঠে এবং কীভাবে নিরাপদে সামলানো যায়। জানুন।', 'হাই রিস্ক প্রেগন্যান্সি', '"হাই রিস্ক প্রেগন্যান্সি" শব্দটা শুনে অনেকেই ভয় পেয়ে যান। কিন্তু বাস্তবে এর মানে এই না যে কিছু খারাপ হবেই। এর মানে হলো, এই গর্ভাবস্থায় একটু বাড়তি সতর্কতা ও নিয়মিত পর্যবেক্ষণ প্রয়োজন।

## হাই রিস্ক প্রেগন্যান্সি কী?

যে গর্ভাবস্থায় মা বা শিশুর স্বাস্থ্যঝুঁকির সম্ভাবনা সাধারণের চেয়ে বেশি থাকে, তাকে হাই রিস্ক প্রেগন্যান্সি বলা হয়। এর মানে এই না যে জটিলতা হবেই, বরং এর মানে হলো এটাকে বাড়তি যত্নে রাখতে হবে।

## কোন কোন কারণে ঝুঁকি বাড়ে

- **মায়ের বয়স**, ৩৫ বছরের বেশি বা ১৮ বছরের কম বয়সে গর্ভধারণ
- **উচ্চ রক্তচাপ** বা প্রি-এক্লাম্পসিয়া
- **ডায়াবেটিস** (আগে থেকে থাকা বা গর্ভকালীন ডায়াবেটিস)
- **আগের গর্ভাবস্থায় জটিলতার ইতিহাস** (গর্ভপাত, অকাল প্রসব)
- **যমজ বা তার বেশি সন্তান**
- **থাইরয়েড বা হৃদরোগের মতো পূর্ব-বিদ্যমান রোগ**
- **স্থূলতা বা কম ওজন**
- **প্লাসেন্টার অবস্থানগত সমস্যা**

## কীভাবে ব্যবস্থাপনা করা হয়

### নিয়মিত ও ঘনঘন চেকআপ
সাধারণ গর্ভাবস্থার চেয়ে বেশি ঘন ঘন ভিজিট ও পরীক্ষা হয়। রক্তচাপ, রক্তের শর্করা ও শিশুর বৃদ্ধি নিয়মিত দেখা হয়।

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
না, অনেক হাই রিস্ক গর্ভাবস্থাতেও সঠিক ব্যবস্থাপনায় নরমাল ডেলিভারি সম্ভব হয়, এটা নির্ভর করে নির্দিষ্ট পরিস্থিতির উপর।

**হাই রিস্ক হলে কি বিশ্রামে থাকতে হবে সবসময়?**
সবসময় না, এটা নির্দিষ্ট কারণ ও চিকিৎসকের পরামর্শের উপর নির্ভর করে, প্রতিটি ক্ষেত্রে আলাদা।

**পরের গর্ভাবস্থাতেও কি একই ঝুঁকি থাকবে?**
সবসময় না, তবে আগের ইতিহাস মাথায় রেখে পরবর্তী গর্ভাবস্থায় আরও সতর্ক পর্যবেক্ষণ প্রয়োজন হয়।

## সারসংক্ষেপ

হাই রিস্ক প্রেগন্যান্সির মূল চাবিকাঠি হলো প্রাথমিক শনাক্তকরণ এবং নিয়মিত পর্যবেক্ষণ। ভয় না পেয়ে নিয়মিত চেকআপ চালিয়ে যাওয়াটাই মা ও শিশু উভয়ের জন্য সবচেয়ে নিরাপদ পথ। এই লেখাটি শুধু তথ্যের জন্য। নিজের চিকিৎসা নিয়ে সিদ্ধান্তের আগে চিকিৎসকের সঙ্গে কথা বলুন।', '/uploads/cover-high-risk-pregnancy-care-guide.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('uterine-fibroid-symptoms-treatment', 'জরায়ুর ফাইব্রয়েড: লক্ষণ, কারণ ও চিকিৎসা', 'জরায়ুর ফাইব্রয়েড চিকিৎসা। লক্ষণ ও কারণ। ডাঃ জেবুন্নেসা জেবু', 'জরায়ুর ফাইব্রয়েড কী, এটা কি ক্যান্সার, এবং কোন ক্ষেত্রে অস্ত্রোপচার প্রয়োজন হয় তা বিস্তারিত জানুন।', 'জরায়ুর ফাইব্রয়েড কী, এটা কি ক্যান্সার, এবং কোন ক্ষেত্রে অস্ত্রোপচার প্রয়োজন হয় তা বিস্তারিত জানুন।', 'ফাইব্রয়েড চিকিৎসা', 'জরায়ুর ফাইব্রয়েড নির্ণয় হওয়ার পর অনেকেই ভয় পেয়ে যান, ক্যান্সার ভেবে। ভালো খবর হলো, ফাইব্রয়েড প্রায় সবসময়ই একটা সৌম্য টিউমার, এবং অনেক ক্ষেত্রেই এর কোনো চিকিৎসার প্রয়োজনই হয় না।

## ফাইব্রয়েড কী?

জরায়ুর পেশী টিস্যু থেকে তৈরি হওয়া একটা সৌম্য টিউমার, যা জরায়ুর ভেতরে, দেয়ালে বা বাইরের দিকে হতে পারে। আকারে এটা একটা ছোট মটরদানার মতোও হতে পারে, আবার বড় হয়ে তরমুজের মতো আকারও নিতে পারে।

## কতটা সাধারণ?

প্রজনন বয়সের নারীদের একটা বড় অংশের মধ্যে ফাইব্রয়েড থাকে, অনেকেরই কোনো লক্ষণ থাকে না, তাই না জেনেই থাকে।

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

## চিকিৎসা: সবসময় অস্ত্রোপচার লাগে না

চিকিৎসা নির্ভর করে লক্ষণের তীব্রতা, ফাইব্রয়েডের আকার/অবস্থান, এবং ভবিষ্যতে গর্ভধারণের ইচ্ছার উপর:

- **লক্ষণ না থাকলে**: শুধু নিয়মিত পর্যবেক্ষণ, কোনো চিকিৎসা ছাড়াই
- **হালকা লক্ষণে**: ওষুধ দিয়ে রক্তস্রাব ও ব্যথা নিয়ন্ত্রণ
- **বড় আকারে বা তীব্র লক্ষণে**: ফাইব্রয়েড অপসারণ সার্জারি (মায়োমেক্টমি), জরায়ু রেখে শুধু ফাইব্রয়েডটি সরিয়ে ফেলা হয়। ভবিষ্যতে গর্ভধারণের ইচ্ছা থাকলে এই পদ্ধতি বেছে নেওয়া হয়
- **তীব্র জটিল ক্ষেত্রে**: প্রয়োজনে জরায়ু অপসারণ (হিস্টেরেক্টমি) বিবেচনা করা হয়, সাধারণত যাদের আর সন্তান নেওয়ার ইচ্ছা নেই তাদের ক্ষেত্রে

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**ফাইব্রয়েড কি ক্যান্সারে রূপ নেয়?**
অত্যন্ত বিরল। প্রায় সবসময়ই এটা সৌম্য থেকে যায়।

**ফাইব্রয়েড থাকলে কি গর্ভধারণ সম্ভব না?**
সম্ভব, তবে আকার ও অবস্থানের উপর নির্ভর করে কিছু ক্ষেত্রে অসুবিধা হতে পারে, চিকিৎসকের মূল্যায়ন প্রয়োজন।

**সব ফাইব্রয়েডেই কি অস্ত্রোপচার লাগে?**
না, বেশিরভাগ ক্ষেত্রেই লক্ষণ না থাকলে শুধু পর্যবেক্ষণই যথেষ্ট।

## সারসংক্ষেপ

ফাইব্রয়েড নির্ণয় হলে আতঙ্কিত হওয়ার দরকার নেই, এটা সাধারণত সৌম্য এবং ব্যবস্থাপনাযোগ্য। লক্ষণ অনুযায়ী সঠিক সিদ্ধান্ত নিতে একজন স্ত্রীরোগ বিশেষজ্ঞের পরামর্শ নিন। এই লেখাটি শুধু তথ্যের জন্য।', '/uploads/cover-uterine-fibroid-symptoms-treatment.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('ovarian-cyst-symptoms-and-treatment', 'ডিম্বাশয়ের সিস্ট: কখন চিন্তার কারণ', 'ডিম্বাশয়ের সিস্ট চিকিৎসা। কখন চিন্তার কারণ। ডাঃ জেবুন্নেসা জেবু', 'ডিম্বাশয়ে সিস্ট থাকলে কখন চিন্তার কারণ, কখন এমনিতেই মিলিয়ে যায়, এবং কখন অস্ত্রোপচার প্রয়োজন। জানুন।', 'ডিম্বাশয়ে সিস্ট থাকলে কখন চিন্তার কারণ, কখন এমনিতেই মিলিয়ে যায়, এবং কখন অস্ত্রোপচার প্রয়োজন। জানুন।', 'ওভারিয়ান সিস্ট চিকিৎসা', 'আল্ট্রাসনোগ্রাম রিপোর্টে "ওভারিয়ান সিস্ট" দেখে অনেকেই আতঙ্কিত হয়ে পড়েন। বাস্তবে বেশিরভাগ সিস্টই সম্পূর্ণ স্বাভাবিক এবং নিজে থেকেই মিলিয়ে যায়।

## ওভারিয়ান সিস্ট কী?

ডিম্বাশয়ের ভেতরে বা তার গায়ে তরল-ভর্তি একটা থলি তৈরি হলে তাকে সিস্ট বলে। প্রতি মাসিক চক্রে ডিম্বাশয় স্বাভাবিকভাবেই ছোট ছোট সিস্ট তৈরি করে (ডিম্বস্ফোটনের অংশ হিসেবে), এগুলোকে ফাংশনাল সিস্ট বলে, যা সম্পূর্ণ স্বাভাবিক।

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
- হঠাৎ তীব্র ব্যথা (সিস্ট মোচড় খেলে বা ফেটে গেলে, এটা জরুরি অবস্থা)

## কখন জরুরি চিকিৎসা প্রয়োজন

হঠাৎ তীব্র তলপেট ব্যথার সাথে বমি বা জ্বর হলে দেরি না করে দ্রুত হাসপাতালে যাওয়া উচিত, এটা সিস্ট মোচড় খাওয়া (torsion) বা ফেটে যাওয়ার লক্ষণ হতে পারে।

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

ওভারিয়ান সিস্ট নাম শুনে ভয় না পেয়ে, নিয়মিত ফলো-আপ আল্ট্রাসনোগ্রাম করানোই সবচেয়ে গুরুত্বপূর্ণ। বেশিরভাগ ক্ষেত্রে এটি নিজে থেকেই মিলিয়ে যায়। এই লেখাটি শুধু তথ্যের জন্য। নিজের অবস্থা নিয়ে চিকিৎসকের পরামর্শ নিন।', '/uploads/cover-ovarian-cyst-symptoms-and-treatment.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('irregular-periods-menstrual-problems', 'মাসিকের অনিয়ম: কারণ ও কখন চিকিৎসকের কাছে যাবেন', 'অনিয়মিত মাসিকের চিকিৎসা। কারণ ও সমাধান। ডাঃ জেবুন্নেসা জেবু', 'মাসিক অনিয়মিত হওয়ার সাধারণ কারণগুলো কী কী, এবং কোন লক্ষণে চিকিৎসকের পরামর্শ নেওয়া জরুরি তা জানুন।', 'মাসিক অনিয়মিত হওয়ার সাধারণ কারণগুলো কী কী, এবং কোন লক্ষণে চিকিৎসকের পরামর্শ নেওয়া জরুরি তা জানুন।', 'মাসিক সমস্যার চিকিৎসা', '"এই মাসে মাসিক দেরিতে হলো, আবার পরের মাসে দুইবার হলো"। এই ধরনের অভিযোগ নিয়ে প্রতিদিনই অনেক রোগী আসেন। মাসিকের অনিয়ম অনেক কারণেই হতে পারে, বেশিরভাগই চিকিৎসাযোগ্য।

## স্বাভাবিক মাসিক চক্র কেমন হয়?

সাধারণত ২১ থেকে ৩৫ দিনের মধ্যে একটা চক্র সম্পূর্ণ হয়, এবং রক্তস্রাব ২ থেকে ৭ দিন স্থায়ী হয়। এর বাইরে গেলে বা প্যাটার্নে হঠাৎ পরিবর্তন এলে তাকে অনিয়ম বলা যায়।

## অনিয়মের ধরন

- **মাসিক দেরিতে হওয়া বা বাদ যাওয়া**
- **অতিরিক্ত ঘনঘন মাসিক হওয়া**
- **অতিরিক্ত রক্তস্রাব (Heavy Menstrual Bleeding)**
- **মাসিকের মাঝে রক্তক্ষরণ**
- **তীব্র ব্যথাযুক্ত মাসিক**

## সাধারণ কারণসমূহ

- **PCOS**, অনিয়মিত মাসিকের সবচেয়ে সাধারণ কারণগুলোর একটা
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

কারণ নির্ণয়ের পর চিকিৎসা ঠিক করা হয়, হরমোনাল ওষুধ দিয়ে চক্র নিয়ন্ত্রণ, থাইরয়েডের চিকিৎসা, বা কাঠামোগত কারণ (ফাইব্রয়েড/পলিপ) থাকলে তার প্রয়োজন অনুযায়ী ব্যবস্থা।

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**এক-দুই দিন মাসিক দেরিতে হলেই কি ডাক্তার দেখাতে হবে?**
না, সামান্য হেরফের স্বাভাবিক। বারবার বড় ধরনের অনিয়ম হলেই পরামর্শ নেওয়া উচিত।

**মানসিক চাপে কি সত্যিই মাসিক অনিয়মিত হয়?**
হ্যাঁ, দীর্ঘস্থায়ী মানসিক চাপ হরমোনের ভারসাম্যে প্রভাব ফেলে মাসিক চক্র বদলে দিতে পারে।

**অতিরিক্ত রক্তস্রাব হলে কি রক্তশূন্যতা হতে পারে?**
হ্যাঁ, দীর্ঘদিন ধরে অতিরিক্ত রক্তস্রাব হলে রক্তশূন্যতা (অ্যানিমিয়া) হতে পারে, তাই সময়মতো চিকিৎসা জরুরি।

## সারসংক্ষেপ

মাসিকের অনিয়ম অনেক সময় সাময়িক হলেও, বারবার বা তীব্র হলে এর পেছনে কারণ খুঁজে বের করা জরুরি। এই লেখাটি শুধু তথ্যের জন্য। নিজের অবস্থা নিয়ে চিকিৎসকের সঙ্গে কথা বলুন।', '/uploads/cover-irregular-periods-menstrual-problems.jpg', 'published', 'index', NOW());

INSERT IGNORE INTO blog_posts (slug, title, meta_title, excerpt, meta_description, focus_keyword, body, cover_image, status, robots, published_at) VALUES ('menopause-symptoms-and-care', 'মেনোপজ: শারীরিক পরিবর্তন ও সুস্থ থাকার উপায়', 'মেনোপজ ব্যবস্থাপনা। লক্ষণ ও পরামর্শ। ডাঃ জেবুন্নেসা জেবু', 'মেনোপজের সময় শরীরে কী কী পরিবর্তন আসে, কোন লক্ষণগুলো স্বাভাবিক এবং কীভাবে এই সময়টা সুস্থভাবে পার করা যায়।', 'মেনোপজের সময় শরীরে কী কী পরিবর্তন আসে, কোন লক্ষণগুলো স্বাভাবিক এবং কীভাবে এই সময়টা সুস্থভাবে পার করা যায়।', 'মেনোপজ', 'মেনোপজ কোনো রোগ না। এটা নারীর জীবনের একটা স্বাভাবিক পর্যায়, যেখানে ডিম্বাশয় ধীরে ধীরে হরমোন তৈরি বন্ধ করে দেয় এবং মাসিক চিরতরে থেমে যায়। তবে এই পরিবর্তনটা সহজভাবে সামলাতে সঠিক তথ্য জানা জরুরি।

## মেনোপজ কী?

টানা ১২ মাস মাসিক না হলে তখন তাকে মেনোপজ বলা হয়। সাধারণত ৪৫ থেকে ৫৫ বছর বয়সের মধ্যে এটা হয়, গড় বয়স প্রায় ৫০ এর কাছাকাছি।

## পেরিমেনোপজ, মেনোপজের আগের পর্যায়

মেনোপজের কয়েক বছর আগে থেকেই শরীরে পরিবর্তন শুরু হয়, এই সময়টাকে পেরিমেনোপজ বলে, যেখানে মাসিক অনিয়মিত হতে শুরু করে।

## সাধারণ লক্ষণ

- গরম ভাব/ঘাম হওয়া (Hot Flashes)
- রাতে ঘুমের সমস্যা
- মেজাজের পরিবর্তন, বিরক্তি বা উদ্বেগ
- যোনিপথ শুষ্ক হয়ে যাওয়া
- ওজন বৃদ্ধি, বিশেষত পেটের অংশে
- হাড়ের ঘনত্ব কমে যাওয়া (দীর্ঘমেয়াদে)
- ত্বক ও চুলের পরিবর্তন

সবার ক্ষেত্রে তীব্রতা একরকম না, কারো সামান্য অস্বস্তি হয়, কারো জীবনযাত্রায় বড় প্রভাব ফেলে।

## দীর্ঘমেয়াদী স্বাস্থ্য বিবেচনা

মেনোপজের পর ইস্ট্রোজেন কমে যাওয়ায় দুটো বিষয়ে বাড়তি সতর্কতা প্রয়োজন:

- **হাড়ের স্বাস্থ্য**, অস্টিওপোরোসিসের ঝুঁকি বাড়ে
- **হৃদস্বাস্থ্য**, হৃদরোগের ঝুঁকি কিছুটা বাড়ে

তাই নিয়মিত চেকআপ, ক্যালসিয়াম ও ভিটামিন ডি সমৃদ্ধ খাবার, এবং নিয়মিত ব্যায়াম গুরুত্বপূর্ণ হয়ে ওঠে।

## কীভাবে সহজভাবে পার করা যায়

- নিয়মিত হালকা ব্যায়াম (হাঁটা, যোগব্যায়াম)
- সুষম খাদ্যাভ্যাস, ক্যালসিয়াম, ভিটামিন ডি সমৃদ্ধ খাবার
- পর্যাপ্ত ঘুম ও মানসিক চাপ কমানো
- প্রয়োজনে হরমোন থেরাপি বা অন্য ওষুধ নিয়ে চিকিৎসকের সঙ্গে আলোচনা করুন। এটি সবার জন্য উপযুক্ত নয়, তাই ব্যক্তিভেদে সিদ্ধান্ত নিতে হয়়

## কখন চিকিৎসকের পরামর্শ নেবেন

- মেনোপজের পরও যোনিপথে রক্তক্ষরণ হলে (এর জন্য সব সময় পরীক্ষা দরকার)
- লক্ষণ দৈনন্দিন জীবনে তীব্র প্রভাব ফেললে
- হাড় বা হৃদস্বাস্থ্য নিয়ে দুশ্চিন্তা থাকলে

## প্রায়শই জিজ্ঞাসিত প্রশ্ন (FAQ)

**মেনোপজের পর রক্তক্ষরণ হলে কি চিন্তার কারণ?**
হ্যাঁ, এটা উপেক্ষা করা উচিত না, যত দ্রুত সম্ভব চিকিৎসকের পরামর্শ নেওয়া জরুরি, কারণ নির্ণয়ের জন্য।

**হরমোন থেরাপি কি সবার জন্য নিরাপদ?**
না, এটা ব্যক্তির স্বাস্থ্য ইতিহাসের উপর নির্ভর করে, চিকিৎসকের সাথে আলোচনা করে সিদ্ধান্ত নেওয়া উচিত।

**মেনোপজের পর ওজন বাড়া কি স্বাভাবিক?**
হরমোনের পরিবর্তনে বিপাক হার কিছুটা কমে, তাই এই সময় খাদ্যাভ্যাস ও ব্যায়ামে বাড়তি মনোযোগ দরকার হতে পারে।

## সারসংক্ষেপ

মেনোপজ জীবনের একটা স্বাভাবিক অধ্যায়, ভয়ের কিছু না। সঠিক তথ্য, স্বাস্থ্যকর জীবনযাত্রা এবং প্রয়োজনে চিকিৎসকের সহায়তায় এই সময়টা সুস্থভাবে পার করা সম্পূর্ণ সম্ভব। এই লেখাটি শুধু তথ্যের জন্য। নিজের অবস্থা নিয়ে চিকিৎসকের সঙ্গে কথা বলুন।', '/uploads/cover-menopause-symptoms-and-care.jpg', 'published', 'index', NOW());

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
SELECT 'Citizen Lab Doctor & Diagnostic', 'Islam Tower, 22 KDA Avenue, Moylapota Mor, Khulna', 'বিকাল ৩টা থেকে ৫টা (শুক্রবার বন্ধ)', '', '', 1, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chambers WHERE name = 'Citizen Lab Doctor & Diagnostic');
INSERT INTO chambers (name, location, visiting_hours, phone, map_link, sort_order, is_active)
SELECT 'Khulna City Medical College & Hospital', '25/26, KDA Avenue, Moilapota Square, Khulna Sadar, Khulna', 'দুপুর ২টা থেকে ৩টা (শুক্রবার বন্ধ)', '', '', 2, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chambers WHERE name = 'Khulna City Medical College & Hospital');
INSERT INTO chambers (name, location, visiting_hours, phone, map_link, sort_order, is_active)
SELECT 'Labaid Diagnostic, Khulna', 'KDA Plot No. A5, Majid Sarani, Sonadanga, Khulna', 'সন্ধ্যা ৭টা থেকে ৯টা (শুক্রবার বন্ধ)', '', '', 3, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM chambers WHERE name = 'Labaid Diagnostic, Khulna');

-- Post meta titles (45 chars max, so the layout never cuts them) and reviewer, idempotent.
UPDATE blog_posts SET meta_title = 'গর্ভধারণের আগে ফলিক অ্যাসিড ও পরীক্ষা', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'preconception-care-folic-acid-before-pregnancy';
UPDATE blog_posts SET meta_title = 'প্রথম ৩ মাসের পরীক্ষা ও সতর্ক সংকেত', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'first-trimester-pregnancy-care-tests';
UPDATE blog_posts SET meta_title = 'গর্ভকালীন ডায়াবেটিস: পরীক্ষা ও নিয়ন্ত্রণ', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'gestational-diabetes-screening-and-control';
UPDATE blog_posts SET meta_title = 'জরায়ুমুখ ক্যান্সার প্রতিরোধ: স্ক্রিনিং ও টিকা', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'cervical-cancer-prevention-hpv-test-vaccine';
UPDATE blog_posts SET meta_title = 'IVF কী? কাদের লাগে ও বাস্তব প্রত্যাশা', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'ivf-treatment-what-to-know';
UPDATE blog_posts SET meta_title = 'PCOS: লক্ষণ, কারণ ও চিকিৎসা', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'pcos-treatment-guide';
UPDATE blog_posts SET meta_title = 'নরমাল ডেলিভারি বনাম সিজারিয়ান সেকশন', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'normal-delivery-vs-c-section';
UPDATE blog_posts SET meta_title = 'সাদা স্রাব: স্বাভাবিক কী, কখন সতর্ক হবেন', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'white-discharge-causes-and-when-to-worry';
UPDATE blog_posts SET meta_title = 'বন্ধ্যাত্বের কারণ ও চিকিৎসা, খুলনা', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'infertility-causes-and-treatment-khulna';
UPDATE blog_posts SET meta_title = 'এন্ডোমেট্রিওসিস: লক্ষণ ও চিকিৎসা', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'endometriosis-symptoms-and-treatment';
UPDATE blog_posts SET meta_title = 'হাই রিস্ক গর্ভাবস্থায় যত্ন ও সতর্কতা', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'high-risk-pregnancy-care-guide';
UPDATE blog_posts SET meta_title = 'জরায়ুর ফাইব্রয়েড: লক্ষণ ও চিকিৎসা', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'uterine-fibroid-symptoms-treatment';
UPDATE blog_posts SET meta_title = 'ওভারিয়ান সিস্ট: লক্ষণ ও চিকিৎসা', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'ovarian-cyst-symptoms-and-treatment';
UPDATE blog_posts SET meta_title = 'অনিয়মিত মাসিক: কারণ ও করণীয়', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'irregular-periods-menstrual-problems';
UPDATE blog_posts SET meta_title = 'মেনোপজ: লক্ষণ ও সহজ যত্ন', medically_reviewed_by = 'ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'menopause-symptoms-and-care';

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
-- ---------- Ad tracking accounts: many Meta pixels, GA4 properties and Google Ads accounts ----------
-- Managed from the admin pages (Meta Ads / Google Ads / Google Analytics ট্র্যাকিং).
-- The app also creates this table itself on first use, so this statement is only a safety net.
CREATE TABLE IF NOT EXISTS tracking_accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  platform VARCHAR(10) NOT NULL,
  label VARCHAR(120) NOT NULL DEFAULT '',
  ext_id VARCHAR(40) NOT NULL,
  secret VARCHAR(255) NOT NULL DEFAULT '',
  extra VARCHAR(120) NOT NULL DEFAULT '',
  server_on TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_platform_ext (platform, ext_id),
  KEY idx_platform_active (platform, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS jebu_add_column;

-- ---------- English content columns (bilingual site). Empty means the Bangla text is shown. Safe to re-run. ----------
ALTER TABLE specialties ADD COLUMN IF NOT EXISTS title_en VARCHAR(200) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS kicker_en VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS short_desc_en VARCHAR(600) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS overview_body_en MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS meta_title_en VARCHAR(70) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS meta_description_en VARCHAR(170) NOT NULL DEFAULT '';
ALTER TABLE services ADD COLUMN IF NOT EXISTS title_en VARCHAR(200) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS kicker_en VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS short_desc_en VARCHAR(600) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS overview_body_en MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS meta_title_en VARCHAR(70) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS meta_description_en VARCHAR(170) NOT NULL DEFAULT '';
ALTER TABLE treatments ADD COLUMN IF NOT EXISTS title_en VARCHAR(200) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS kicker_en VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS short_desc_en VARCHAR(600) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS overview_body_en MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS meta_title_en VARCHAR(70) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS meta_description_en VARCHAR(170) NOT NULL DEFAULT '';
ALTER TABLE procedures ADD COLUMN IF NOT EXISTS title_en VARCHAR(200) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS kicker_en VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS short_desc_en VARCHAR(600) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS overview_body_en MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS meta_title_en VARCHAR(70) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS meta_description_en VARCHAR(170) NOT NULL DEFAULT '';
ALTER TABLE conditions ADD COLUMN IF NOT EXISTS title_en VARCHAR(200) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS kicker_en VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS short_desc_en VARCHAR(600) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS overview_body_en MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS symptoms_body_en MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS causes_body_en MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS diagnosis_body_en MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS meta_title_en VARCHAR(70) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS meta_description_en VARCHAR(170) NOT NULL DEFAULT '';
ALTER TABLE blog_posts ADD COLUMN IF NOT EXISTS title_en VARCHAR(255) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS excerpt_en TEXT NULL, ADD COLUMN IF NOT EXISTS body_en MEDIUMTEXT NULL, ADD COLUMN IF NOT EXISTS meta_title_en VARCHAR(70) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS meta_description_en VARCHAR(170) NOT NULL DEFAULT '';
ALTER TABLE faqs ADD COLUMN IF NOT EXISTS question_en VARCHAR(500) NOT NULL DEFAULT '', ADD COLUMN IF NOT EXISTS answer_en TEXT NULL;

-- ---------- English short text (titles, summaries, meta, FAQs). Safe to re-run. ----------
UPDATE specialties SET title_en='Pregnancy & Delivery Care', short_desc_en='Complete maternal care, from the first day of pregnancy to postnatal follow-up.', meta_title_en='Pregnancy & Delivery Care', meta_description_en='Complete maternal care, from the first day of pregnancy to postnatal follow-up. Dr. Jebunnesa Jebu, Khulna.' WHERE slug='antenatal-delivery-care';
UPDATE specialties SET title_en='Fertility Management', short_desc_en='A joint assessment for couples and an evidence-based treatment plan.', meta_title_en='Fertility Management', meta_description_en='A joint assessment for couples and an evidence-based treatment plan. Dr. Jebunnesa Jebu, Khulna.' WHERE slug='fertility-management';
UPDATE specialties SET title_en='Gynaecological Surgery', short_desc_en='Experienced care in complex surgery for fibroids, cysts and reproductive organs.', meta_title_en='Gynaecological Surgery', meta_description_en='Experienced care in complex surgery for fibroids, cysts and reproductive organs. Dr. Jebunnesa Jebu, Khulna.' WHERE slug='gynaecological-surgery';
UPDATE specialties SET title_en='Menstrual Health', short_desc_en='Treatment for irregular periods, heavy bleeding and hormone-related problems.', meta_title_en='Menstrual Health', meta_description_en='Treatment for irregular periods, heavy bleeding and hormone-related problems. Dr. Jebunnesa Jebu, Khulna.' WHERE slug='menstrual-health';
UPDATE specialties SET title_en='Breast Care', short_desc_en='Early screening, regular assessment and a specialist referral when needed.', meta_title_en='Breast Care', meta_description_en='Early screening, regular assessment and a specialist referral when needed. Dr. Jebunnesa Jebu, Khulna.' WHERE slug='breast-care';
UPDATE specialties SET title_en='Women''s Care from Adolescence to Menopause', short_desc_en='Age-appropriate, caring medical advice at every stage of a woman''s life.', meta_title_en='Women''s Care from Adolescence to Menopause', meta_description_en='Age-appropriate, caring medical advice at every stage of a woman''s life. Dr. Jebunnesa Jebu, Khulna.' WHERE slug='lifespan-womens-care';
UPDATE services SET title_en='Infertility Treatment Programme', meta_title_en='Infertility Treatment Programme', meta_description_en='Infertility Treatment Programme with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='infertility-treatment-program';
UPDATE services SET title_en='Pregnancy & Delivery Care Package', meta_title_en='Pregnancy & Delivery Care Package', meta_description_en='Pregnancy & Delivery Care Package with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='pregnancy-delivery-care';
UPDATE services SET title_en='Gynaecological Surgery Services', meta_title_en='Gynaecological Surgery Services', meta_description_en='Gynaecological Surgery Services with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='gynaecological-surgery-service';
UPDATE services SET title_en='Menstrual Health Care', meta_title_en='Menstrual Health Care', meta_description_en='Menstrual Health Care with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='menstrual-health-care';
UPDATE services SET title_en='Gynae Infection & Screening Care', meta_title_en='Gynae Infection & Screening Care', meta_description_en='Gynae Infection & Screening Care with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='gynae-infection-screening-care';
UPDATE services SET title_en='Women''s Lifespan Care', meta_title_en='Women''s Lifespan Care', meta_description_en='Women''s Lifespan Care with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='womens-lifespan-care';
UPDATE services SET title_en='Antenatal Care', meta_title_en='Antenatal Care', meta_description_en='Antenatal Care with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='antenatal-care-service';
UPDATE services SET title_en='Caesarean Section Services', meta_title_en='Caesarean Section Services', meta_description_en='Caesarean Section Services with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='c-section-delivery-service';
UPDATE services SET title_en='Laparoscopic Surgery Services', meta_title_en='Laparoscopic Surgery Services', meta_description_en='Laparoscopic Surgery Services with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='laparoscopic-surgery-service';
UPDATE services SET title_en='Colposcopy & Cervical Screening', meta_title_en='Colposcopy & Cervical Screening', meta_description_en='Colposcopy & Cervical Screening with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='colposcopy-cervical-screening';
UPDATE treatments SET title_en='IVF & Assisted Reproductive Treatment', meta_title_en='IVF & Assisted Reproductive Treatment', meta_description_en='IVF & Assisted Reproductive Treatment with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='ivf-art';
UPDATE treatments SET title_en='Pregnancy & Prenatal Care', meta_title_en='Pregnancy & Prenatal Care', meta_description_en='Pregnancy & Prenatal Care with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='prenatal-pregnancy-care';
UPDATE treatments SET title_en='Pre-conception & Fertility Counselling', meta_title_en='Pre-conception & Fertility Counselling', meta_description_en='Pre-conception & Fertility Counselling with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='fertility-counselling';
UPDATE treatments SET title_en='Reproductive Health & Family Planning', meta_title_en='Reproductive Health & Family Planning', meta_description_en='Reproductive Health & Family Planning with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='family-planning-reproductive-health';
UPDATE procedures SET title_en='Laparoscopic Surgery', meta_title_en='Laparoscopic Surgery', meta_description_en='Laparoscopic Surgery with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='laparoscopic-surgery';
UPDATE procedures SET title_en='Endoscopic Gynaecological Surgery', meta_title_en='Endoscopic Gynaecological Surgery', meta_description_en='Endoscopic Gynaecological Surgery with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='endoscopic-gynae-surgery';
UPDATE procedures SET title_en='Normal Delivery', meta_title_en='Normal Delivery', meta_description_en='Normal Delivery with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='normal-delivery';
UPDATE procedures SET title_en='Caesarean Delivery', meta_title_en='Caesarean Delivery', meta_description_en='Caesarean Delivery with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='c-section';
UPDATE procedures SET title_en='Colposcopy', meta_title_en='Colposcopy', meta_description_en='Colposcopy with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='colposcopy';
UPDATE conditions SET title_en='Polycystic Ovary Syndrome (PCOS)', meta_title_en='Polycystic Ovary Syndrome (PCOS)', meta_description_en='Polycystic Ovary Syndrome (PCOS) with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='pcos';
UPDATE conditions SET title_en='Irregular Periods', meta_title_en='Irregular Periods', meta_description_en='Irregular Periods with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='irregular-periods';
UPDATE conditions SET title_en='Hormone-related Gynaecological Problems', meta_title_en='Hormone-related Gynaecological Problems', meta_description_en='Hormone-related Gynaecological Problems with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='hormonal-imbalance';
UPDATE conditions SET title_en='Endometriosis', meta_title_en='Endometriosis', meta_description_en='Endometriosis with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='endometriosis';
UPDATE conditions SET title_en='Uterine Fibroids', meta_title_en='Uterine Fibroids', meta_description_en='Uterine Fibroids with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='uterine-fibroid';
UPDATE conditions SET title_en='Ovarian Cysts', meta_title_en='Ovarian Cysts', meta_description_en='Ovarian Cysts with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='ovarian-cyst';
UPDATE conditions SET title_en='High-Risk Pregnancy', meta_title_en='High-Risk Pregnancy', meta_description_en='High-Risk Pregnancy with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='high-risk-pregnancy';
UPDATE conditions SET title_en='Repeated IVF Failure', meta_title_en='Repeated IVF Failure', meta_description_en='Repeated IVF Failure with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='repeated-ivf-failure';
UPDATE conditions SET title_en='Recurrent Miscarriage', meta_title_en='Recurrent Miscarriage', meta_description_en='Recurrent Miscarriage with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='recurrent-miscarriage';
UPDATE conditions SET title_en='Tubal Blockage / Hydrosalpinx', meta_title_en='Tubal Blockage / Hydrosalpinx', meta_description_en='Tubal Blockage / Hydrosalpinx with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='tubal-blockage-hydrosalpinx';
UPDATE conditions SET title_en='Uterine Infection', meta_title_en='Uterine Infection', meta_description_en='Uterine Infection with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='uterine-infection';
UPDATE conditions SET title_en='Cervix Problems & Cervical Infection', meta_title_en='Cervix Problems & Cervical Infection', meta_description_en='Cervix Problems & Cervical Infection with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='cervix-problems';
UPDATE conditions SET title_en='Ovulation Disorders', meta_title_en='Ovulation Disorders', meta_description_en='Ovulation Disorders with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='ovulation-disorder';
UPDATE conditions SET title_en='Male Infertility', meta_title_en='Male Infertility', meta_description_en='Male Infertility with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='male-infertility';
UPDATE conditions SET title_en='Pelvic Pain Syndrome', meta_title_en='Pelvic Pain Syndrome', meta_description_en='Pelvic Pain Syndrome with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='pelvic-pain-syndrome';
UPDATE conditions SET title_en='Heavy Menstrual Bleeding', meta_title_en='Heavy Menstrual Bleeding', meta_description_en='Heavy Menstrual Bleeding with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='heavy-menstrual-bleeding';
UPDATE conditions SET title_en='Missed or Delayed Periods', meta_title_en='Missed or Delayed Periods', meta_description_en='Missed or Delayed Periods with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='delayed-absent-periods';
UPDATE conditions SET title_en='Weak Ovarian Function', meta_title_en='Weak Ovarian Function', meta_description_en='Weak Ovarian Function with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='ovarian-function-weakness';
UPDATE conditions SET title_en='Uterine Polyps & Internal Uterine Problems', meta_title_en='Uterine Polyps & Internal Uterine Problems', meta_description_en='Uterine Polyps & Internal Uterine Problems with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='uterine-polyps';
UPDATE conditions SET title_en='Gynaecological Cancer Screening', meta_title_en='Gynaecological Cancer Screening', meta_description_en='Gynaecological Cancer Screening with Dr. Jebunnesa Jebu, Khulna. Read the details and book an appointment.' WHERE slug='gynae-cancer-screening-need';
UPDATE faqs SET question_en='How do I book an appointment?', answer_en='Send your name and mobile number through the appointment form on this website. Our team will contact you by phone or WhatsApp to confirm a time.' WHERE id=1 AND question_en='';
UPDATE faqs SET question_en='Is my appointment confirmed as soon as I send the form?', answer_en='No. Sending the form means your request has reached us. Our team will confirm a time and let you know, so please give a correct mobile number.' WHERE id=2 AND question_en='';
UPDATE faqs SET question_en='What should I bring on my first visit?', answer_en='Bring any earlier reports, ultrasound scans, prescriptions and a list of the medicines you take now. This makes the doctor''s assessment easier.' WHERE id=3 AND question_en='';
UPDATE faqs SET question_en='Can my husband or male partner come with me?', answer_en='Yes. For infertility especially, a joint assessment of the couple is important, so it helps if you both come together.' WHERE id=4 AND question_en='';
UPDATE faqs SET question_en='What should I do in an emergency?', answer_en='If you have severe pain, heavy bleeding or a sudden complication during pregnancy, do not wait for an appointment. Go to the emergency department of the nearest hospital.' WHERE id=5 AND question_en='';
UPDATE faqs SET question_en='Is the content on this website medical advice?', answer_en='No. The information here is for general awareness. For advice about your own condition, please contact the doctor directly.' WHERE id=6 AND question_en='';
UPDATE blog_posts SET title_en='Before Pregnancy: Folic Acid and Tests', excerpt_en='Which tests you need before pregnancy, when to start folic acid and how much, and what to change in your daily routine, from a gynaecologist''s general guidance.', meta_title_en='Before Pregnancy: Folic Acid and Tests', meta_description_en='Which tests you need before pregnancy, when to start folic acid and how much, and what to change in your daily routine, from a gynaecologist''s general...' WHERE slug='preconception-care-folic-acid-before-pregnancy';
UPDATE blog_posts SET title_en='First 3 Months: Tests and Warning Signs', excerpt_en='What happens at the first antenatal visit, why the dating ultrasound and NT scan matter, and which warning signs need urgent medical care.', meta_title_en='First 3 Months: Tests and Warning Signs', meta_description_en='What happens at the first antenatal visit, why the dating ultrasound and NT scan matter, and which warning signs need urgent medical care.' WHERE slug='first-trimester-pregnancy-care-tests';
UPDATE blog_posts SET title_en='Gestational Diabetes: Tests and Control', excerpt_en='What gestational diabetes is, when screening is done, how the 75 g OGTT test works, the risks, and ways to keep blood sugar under control.', meta_title_en='Gestational Diabetes: Tests and Control', meta_description_en='What gestational diabetes is, when screening is done, how the 75 g OGTT test works, the risks, and ways to keep blood sugar under control.' WHERE slug='gestational-diabetes-screening-and-control';
UPDATE blog_posts SET title_en='Cervical Cancer Prevention: Pap, HPV, Vaccine', excerpt_en='The difference between a Pap smear and an HPV test, when to get screened, who the HPV vaccine is for, and which symptoms need a check without delay.', meta_title_en='Cervical Cancer Prevention: Pap, HPV, Vaccine', meta_description_en='The difference between a Pap smear and an HPV test, when to get screened, who the HPV vaccine is for, and which symptoms need a check without delay.' WHERE slug='cervical-cancer-prevention-hpv-test-vaccine';
UPDATE blog_posts SET title_en='IVF: What It Is, Who Needs It, What to Expect', excerpt_en='What IVF (in vitro fertilisation) is, who may need it, the treatment steps, possible risks, and a realistic view of success rates.', meta_title_en='IVF: What It Is, Who Needs It, What to Expect', meta_description_en='What IVF (in vitro fertilisation) is, who may need it, the treatment steps, possible risks, and a realistic view of success rates.' WHERE slug='ivf-treatment-what-to-know';
UPDATE blog_posts SET title_en='PCOS: Causes, Symptoms and Treatment', excerpt_en='What PCOS (polycystic ovary syndrome) is, why it happens, its symptoms, and how modern treatment helps keep it under control.', meta_title_en='PCOS: Causes, Symptoms and Treatment', meta_description_en='What PCOS (polycystic ovary syndrome) is, why it happens, its symptoms, and how modern treatment helps keep it under control.' WHERE slug='pcos-treatment-guide';
UPDATE blog_posts SET title_en='Normal Delivery vs Caesarean Section', excerpt_en='The difference between a normal delivery and a caesarean section, when each is chosen, and the benefits and risks of both.', meta_title_en='Normal Delivery vs Caesarean Section', meta_description_en='The difference between a normal delivery and a caesarean section, when each is chosen, and the benefits and risks of both.' WHERE slug='normal-delivery-vs-c-section';
UPDATE blog_posts SET title_en='White Discharge: When It Is Normal', excerpt_en='White discharge is usually normal, but in some cases it can signal an infection or another problem. Learn how to tell the difference.', meta_title_en='White Discharge: When It Is Normal', meta_description_en='White discharge is usually normal, but in some cases it can signal an infection or another problem. Learn how to tell the difference.' WHERE slug='white-discharge-causes-and-when-to-worry';
UPDATE blog_posts SET title_en='Infertility: Causes, Tests and Treatment', excerpt_en='The causes of infertility, the tests both partners need, and an overview of modern treatments, including IVF.', meta_title_en='Infertility: Causes, Tests and Treatment', meta_description_en='The causes of infertility, the tests both partners need, and an overview of modern treatments, including IVF.' WHERE slug='infertility-causes-and-treatment-khulna';
UPDATE blog_posts SET title_en='Endometriosis: Symptoms and Treatment', excerpt_en='What endometriosis is, why it causes severe period pain, and how it is diagnosed and treated.', meta_title_en='Endometriosis: Symptoms and Treatment', meta_description_en='What endometriosis is, why it causes severe period pain, and how it is diagnosed and treated.' WHERE slug='endometriosis-symptoms-and-treatment';
UPDATE blog_posts SET title_en='High-Risk Pregnancy: Signs and Care', excerpt_en='What a high-risk pregnancy is, which factors make a pregnancy high-risk, and how it is managed safely.', meta_title_en='High-Risk Pregnancy: Signs and Care', meta_description_en='What a high-risk pregnancy is, which factors make a pregnancy high-risk, and how it is managed safely.' WHERE slug='high-risk-pregnancy-care-guide';
UPDATE blog_posts SET title_en='Uterine Fibroids: Symptoms and Treatment', excerpt_en='What uterine fibroids are, whether they are cancer, and when surgery is needed.', meta_title_en='Uterine Fibroids: Symptoms and Treatment', meta_description_en='What uterine fibroids are, whether they are cancer, and when surgery is needed.' WHERE slug='uterine-fibroid-symptoms-treatment';
UPDATE blog_posts SET title_en='Ovarian Cysts: When to Be Concerned', excerpt_en='When an ovarian cyst is a reason for concern, when it often goes away on its own, and when surgery is needed.', meta_title_en='Ovarian Cysts: When to Be Concerned', meta_description_en='When an ovarian cyst is a reason for concern, when it often goes away on its own, and when surgery is needed.' WHERE slug='ovarian-cyst-symptoms-and-treatment';
UPDATE blog_posts SET title_en='Irregular Periods: Causes and Advice', excerpt_en='The common causes of irregular periods, and the symptoms that mean you should see a doctor.', meta_title_en='Irregular Periods: Causes and Advice', meta_description_en='The common causes of irregular periods, and the symptoms that mean you should see a doctor.' WHERE slug='irregular-periods-menstrual-problems';
UPDATE blog_posts SET title_en='Menopause: Changes and Staying Healthy', excerpt_en='The physical changes during menopause, which symptoms are normal, and how to get through this stage in good health.', meta_title_en='Menopause: Changes and Staying Healthy', meta_description_en='The physical changes during menopause, which symptoms are normal, and how to get through this stage in good health.' WHERE slug='menopause-symptoms-and-care';

-- ---------- English blog bodies (body_en). Safe to re-run. ----------
UPDATE blog_posts SET body_en='## What is cervical cancer?

The cervix (neck of the womb) is the lower, narrow part of the uterus that connects to the vagina. Cervical cancer is usually caused by a long-lasting infection with the **human papillomavirus (HPV)**. Most HPV infections clear up on their own, but some types can cause cell changes if they persist for a long time. Screening at the right time can catch these changes before they turn into cancer.

## Which tests are available?

- **Pap smear:** A sample of cells from the cervix is checked for abnormal changes.
- **HPV test:** The cervical sample is tested for the HPV virus, not the blood. It gives a more precise picture of the risk of cancer.
- **VIA/VILI:** A simple visual test available at some health centres.

The World Health Organization (WHO) recommends the **HPV test** as the preferred screening test for women aged 30 to 49, usually every 5 years or less. Your doctor will set the schedule based on your age and previous results.

## Who is the HPV vaccine for?

The HPV vaccine is effective in preventing HPV infection. The WHO recommends vaccination for adolescent girls, usually at ages 9 to 14. If you did not have the vaccine, for age or other reasons, discuss it with your doctor, as the advice can differ by age. Even if you are vaccinated, regular screening is still needed, because the vaccine does not cover every type of HPV.

## When should you see a doctor quickly?

Even if it is not time for screening, do not delay if you have any of these symptoms:

- Bleeding after sex or between periods
- Bleeding after the menopause
- Unusual foul-smelling or blood-stained discharge
- Pain during sex
- Persistent lower abdominal pain or weight loss

These symptoms can point to a problem with the cervix, an infection or another condition, so getting checked is important.

## Why is screening important?

When it is found early, treatment is much easier and the outcome is usually good. In many cases, the abnormal cells can be removed with a minor procedure. So even without symptoms, having screening at the recommended times is one of the most effective steps you can take for your health.

## Common questions

**Do I need this test if I am not married?**
HPV spreads through sexual contact. Your doctor will advise you based on your situation and needs.

**Does a Pap smear hurt?**
It usually causes slight discomfort, but not severe pain. If you tell the doctor before the test, they can help make it comfortable for you.

**If the test is negative, do I not need another one?**
You may need screening again after the recommended interval. Your doctor will tell you the right schedule.

## Summary

Cervical cancer can be prevented. Regular screening, the HPV vaccine at an age that suits you, and quick advice for unusual symptoms are the three most effective steps. This article is for general awareness. Talk to your doctor about your own tests and decisions.

**Sources:** WHO Cervical cancer fact sheet, https://www.who.int/news-room/fact-sheets/detail/cervical-cancer · ACOG, https://www.acog.org' WHERE slug='cervical-cancer-prevention-hpv-test-vaccine';
UPDATE blog_posts SET body_en='"I have such pain every month that I cannot go to work." This is the most common complaint of endometriosis. Sadly, many people think of it as "normal period pain" and put up with it for years.

## What is endometriosis?

Endometriosis is when tissue similar to the lining of the uterus (the endometrium) grows outside the uterus, on the ovaries, fallopian tubes or other parts of the abdomen. This tissue also bleeds with the menstrual cycle, but it cannot leave the body, so it causes pain and inflammation.

## Main symptoms

- Severe period pain that does not improve with ordinary painkillers
- Pain during sex
- Ongoing pelvic pain
- Heavy menstrual bleeding
- Delayed or difficult conception
- Pain when passing stools or urine (in severe cases)

Some women have no symptoms at all, while others with mild endometriosis have severe pain. The severity of pain and the extent of the disease are not always linked.

## Why does it happen?

The exact cause is not fully known, but a few theories are common:

- **Retrograde menstruation:** menstrual blood flowing backwards through the tubes into the abdomen
- **Family history:** a genetic tendency
- **Immune system problems**

## How is it diagnosed?

- A detailed history and physical examination
- Ultrasound (in some cases it can detect the condition)
- For a definite diagnosis, **laparoscopy**, a small surgical procedure in which the doctor looks directly and takes a tissue sample if needed

## Treatment options

Treatment depends on your age, the severity of symptoms and whether you want to become pregnant.

### Medicines

Painkillers and hormonal treatment, such as the contraceptive pill or specific hormone therapy, can control symptoms.

### Laparoscopic surgery

Removing endometriosis tissue can reduce pain and may help with conception, particularly when medicines have not worked or when there are fertility problems.

### Planning a pregnancy

If you have endometriosis and want to conceive, surgery or assisted reproduction (IUI or IVF) may be considered. The decision is made individually for each patient.

## Common questions

**Does severe period pain always mean endometriosis?**
Not always, but if the pain is severe and is not relieved by ordinary painkillers, you should see a gynaecologist.

**Does having endometriosis make pregnancy impossible?**
No, but it can make it harder. With proper treatment, many women conceive naturally or with assisted methods.

**Is it hereditary?**
The risk is somewhat higher if it runs in the family, but it is not inherited in everyone.

## Summary

Severe period pain should not be endured as if it were "normal". If the condition is diagnosed early, treatment is easier and future complications can be avoided. This article is for general information only and is not a substitute for personal medical advice.' WHERE slug='endometriosis-symptoms-and-treatment';
UPDATE blog_posts SET body_en='## Why do the first 3 months matter?

The first trimester (about weeks 1 to 13) is when the baby''s main organs form. Good care and the right tests in this period are very important for the safety of both mother and baby. If you find out you are pregnant, register with a gynaecologist as early as possible.

## What happens at the first visit?

- **Confirming the pregnancy** and noting the date of your last period (LMP)
- **Dating ultrasound:** to calculate the gestational age accurately and check the baby''s position
- **Blood tests:** blood group and Rh factor, complete blood count (CBC), thyroid, hepatitis B, HIV and syphilis screening
- **Urine test**, plus weight and blood pressure measurements
- **Folic acid and iron:** started or confirmed, as your doctor advises

## What is the NT (nuchal translucency) scan?

This ultrasound, usually done between 11 and 13 weeks, measures the clear space at the back of the baby''s neck. Together with a blood test, it is used to assess the risk of some chromosomal conditions, including Down syndrome. It is a screening test, not a final diagnosis. If the result is abnormal, your doctor may suggest further tests.

## What warning signs should you watch for?

Nausea, tiredness and breast tenderness are very common early in pregnancy. However, see a doctor quickly if you have:

- Heavy vaginal bleeding or bleeding with clots
- Severe abdominal pain, especially on one side
- Fever, pain on passing urine, or vomiting that stops you keeping food or water down
- Fluid or liquid leaking from the vagina

These signs can indicate an ectopic pregnancy, a risk of miscarriage or an infection, so do not ignore them.

## Medicines and lifestyle

- Do not take any medicine, herbal product or painkiller on your own during pregnancy. Tell your doctor if you need one.
- Drink enough water, eat a balanced diet, and do light walking.
- Avoid raw or undercooked meat and eggs, and unpasteurised dairy products.
- Stop smoking and alcohol completely.

## Common questions

**Can I work normally in the first 3 months?**
Most women can carry on their normal work in the first trimester. However, if your job involves heavy physical work or risks, discuss it with your doctor.

**Can a heartbeat be seen on ultrasound in the first month?**
A heartbeat can often be seen on ultrasound from around 5 to 6 weeks, although this depends on the exact gestational age.

## Summary

Once you know you are pregnant, register with a gynaecologist quickly. Get the dating ultrasound, the required blood tests and the NT scan on time, and see a doctor without delay if any of the warning signs above appear. This article is for general information. For personal advice, talk to your doctor.

**Sources:** ACOG, https://www.acog.org · WHO, https://www.who.int' WHERE slug='first-trimester-pregnancy-care-tests';
UPDATE blog_posts SET body_en='## What is gestational diabetes?

Gestational diabetes mellitus (GDM) is when blood sugar rises for the first time during pregnancy. Hormones produced in pregnancy reduce how well insulin works, and if the pancreas cannot make enough extra insulin, blood sugar rises. With proper screening and control, the risks to both mother and baby can be greatly reduced.

## When should it be tested?

The American Diabetes Association (ADA) generally recommends screening all pregnant women between **24 and 28 weeks**. However, a test at the first visit is better if you have any of these risk factors:

- Overweight or obesity
- PCOS
- A family history of diabetes
- A previous pregnancy with gestational diabetes, or a baby born large
- Age over 35

## How is it tested?

The most common test is the **75 g glucose tolerance test (OGTT)**. A blood sample is taken on an empty stomach, then a set amount of glucose is dissolved in water and drunk. Blood is taken again after 1 and 2 hours. Your doctor will explain the result based on the laboratory''s guidelines. You are usually asked to fast for several hours before the test, so ask in advance what to do.

## Why does it matter?

If it is not controlled, gestational diabetes can increase these risks:

- **For the mother:** pre-eclampsia (high blood pressure), infections, and a higher future risk of type 2 diabetes
- **For the baby:** being born large, low blood sugar after birth, and in many cases the need for a caesarean delivery

## How is it managed?

1. **Diet:** Balanced meals, plenty of fibre-rich vegetables, measured portions of rice or bread, and less sugar and sweet drinks. Eating small amounts more often can help. Plan your diet with your doctor or a dietitian.
2. **Physical activity:** Daily walking or light exercise, if your doctor agrees.
3. **Blood sugar checks:** Regular monitoring as your doctor advises.
4. **Medicine:** If diet and exercise are not enough, your doctor may prescribe insulin or another medicine. This is safe and should be used when needed.

## What should you watch for at home?

Contact your doctor quickly if you have extreme thirst, frequent urination, blurred vision, unusual weakness or persistent vomiting. If your blood sugar drops too low (shaky hands, sweating, dizziness), eat something sweet and tell your doctor promptly.

## Common questions

**Can I have a normal delivery if I have gestational diabetes?**
Yes, with good control most women can have a normal delivery. The timing and method of delivery are decided by your doctor, based on the baby''s weight and your health.

**Will I still have diabetes after delivery?**
For many women, blood sugar returns to normal after delivery, but the risk of diabetes in the future is higher. So a test 6 to 12 weeks after delivery is important.

## Summary

Gestational diabetes can be managed. Get screened between 24 and 28 weeks, keep regular contact with your doctor, and keep your diet and physical activity on track. This article is for general information. Decisions about your own treatment should be made with your doctor.

**Sources:** American Diabetes Association Standards of Care, https://diabetesjournals.org/care · ACOG, https://www.acog.org' WHERE slug='gestational-diabetes-screening-and-control';
UPDATE blog_posts SET body_en='Many people are frightened when they hear the words "high-risk pregnancy". In reality, it does not mean that something bad will definitely happen. It means that this pregnancy needs extra care and regular monitoring.

## What is a high-risk pregnancy?

A pregnancy is called high-risk when there is a greater chance of health problems for the mother or the baby than usual. It does not mean complications will definitely occur. It means the pregnancy needs closer care.

## What factors increase the risk?

- **The mother''s age:** pregnancy over 35 or under 18
- **High blood pressure** or pre-eclampsia
- **Diabetes**, either existing before pregnancy or gestational diabetes
- **A history of complications** in an earlier pregnancy (miscarriage, preterm birth)
- **Twins or more**
- **Pre-existing conditions** such as thyroid disease or heart disease
- **Obesity or low weight**
- **Problems with the placenta''s position**

## How is it managed?

### Regular and frequent check-ups

More frequent visits and tests than a normal pregnancy are carried out. Blood pressure, blood sugar and the baby''s growth are monitored regularly.

### Additional tests

Extra ultrasounds, Doppler studies or blood tests may be done as needed.

### Lifestyle adjustments

Rest, diet and taking medicines exactly as your doctor advises are essential.

### Birth planning

When, how and where the birth will take place is planned in advance, so that quick action can be taken if an emergency arises.

## When should you see a doctor urgently?

- Severe headache or blurred vision
- Sudden swelling of the hands, feet or face
- Severe abdominal pain
- Bleeding from the vagina
- Reduced or stopped movement of the baby

If you notice these signs, contact your doctor immediately without delay.

## Common questions

**Does high risk always mean a caesarean?**
No. Many high-risk pregnancies still allow a normal delivery with proper management. It depends on the specific situation.

**Do I have to rest in bed all the time if I am high-risk?**
Not always. This depends on the specific cause and your doctor''s advice, and it differs for each woman.

**Will the next pregnancy have the same risk?**
Not always, but with the earlier history in mind, the next pregnancy needs closer monitoring.

## Summary

The key to a high-risk pregnancy is early identification and regular monitoring. Keeping up regular check-ups without fear is the safest path for both mother and baby. This article is for general information only and is not a substitute for personal medical advice.' WHERE slug='high-risk-pregnancy-care-guide';
UPDATE blog_posts SET body_en='Worry about not being able to have a child is a very hard time emotionally for a couple. The good news is that most causes of infertility can now be identified, and in most cases treatment is possible.

## What is infertility?

Infertility is usually defined as not conceiving after one year of regular attempts without contraception. If the woman is over 35, tests are best started after 6 months.

## Is it only a women''s problem?

No, that is a common misconception. The causes of infertility are:

- **Around one-third of cases:** a female factor
- **Around one-third of cases:** a male factor (low sperm count or poor sperm quality)
- **The rest:** a combination of both partners, or the cause remains unknown

So both partners should be tested before treatment begins.

## Common causes in women

- Ovulation not taking place (often because of PCOS)
- Blocked fallopian tubes
- Endometriosis
- Fibroids or structural problems of the uterus
- Reduced ovarian function with age

## Common causes in men

- Low sperm count
- Poor sperm motility
- Hormonal problems

## Which tests are needed?

For the woman: hormone tests, an ultrasound, and if needed a tubal test (HSG).
For the man: a semen analysis (sperm test). It is a simple, painless test, but many couples delay having it done out of embarrassment.

## Treatment steps

Treatment depends on the cause:

- **Ovulation problems:** Medicines to control ovulation
- **Tubal problems:** Laparoscopic surgery if needed
- **Mild male factor:** IUI (intrauterine insemination)
- **Complex cases:** IVF (in vitro fertilisation), in which eggs and sperm are joined in the lab and the embryo is placed in the uterus

## The emotional side matters too

Fertility treatment can take time. Being patient, following your doctor''s advice, and supporting each other emotionally as a couple are just as important for a successful outcome.

## Common questions

**Is infertility always a permanent problem?**
No, in most cases it is treatable. Once the cause is found, many couples go on to have a child with the right treatment.

**Do I have to start with IVF?**
No. First the cause is identified, and treatment starts with the simplest and least costly option. If needed, the couple moves step by step towards IVF.

**Is there anything shameful about the husband having a test?**
Not at all. It is a normal medical test, and it is essential for finding the cause of infertility.

## Summary

Infertility is not one person''s fault. It is a treatable condition that needs both partners to be assessed. Getting advice early can also shorten the time to treatment. This article is for general information only and is not a substitute for a personal assessment.' WHERE slug='infertility-causes-and-treatment-khulna';
UPDATE blog_posts SET body_en='"My period was late this month, and then the next month I had two." Doctors see many patients with complaints like this every day. Irregular periods can have many causes, and most are treatable.

## What is a normal menstrual cycle?

A cycle is usually between 21 and 35 days long, and bleeding lasts 2 to 7 days. Anything outside this range, or a sudden change in the pattern, can be called irregularity.

## Types of irregularity

- **Late or missed periods**
- **Periods that are too frequent**
- **Heavy menstrual bleeding**
- **Bleeding between periods**
- **Painful periods**

## Common causes

- **PCOS**, one of the most common causes of irregular periods
- **Thyroid problems**
- **Stress and lifestyle changes**
- **Sudden weight gain or loss**
- **Fibroids or polyps** in the uterus
- **Perimenopause** (the period before menopause)
- **Effects of contraceptive methods**

## What tests might be needed?

- Hormone tests (thyroid, prolactin and others)
- An ultrasound
- Blood tests if needed (haemoglobin, especially with heavy bleeding)

## When should you see a doctor without delay?

- If you have not had a period for more than 3 months in a row (excluding pregnancy)
- If the bleeding is so heavy that you need to change a pad every hour
- If your periods are accompanied by severe pain that normal painkillers do not relieve
- If there is repeated bleeding between periods
- If there is bleeding again after the menopause

## Treatment

Treatment is decided once the cause is found. It may involve hormonal medicines to regulate the cycle, treatment of the thyroid, or treatment of a structural cause such as fibroids or polyps.

## Common questions

**Do I need to see a doctor if my period is late by just one or two days?**
No, small variations are normal. Advice is needed when there are repeated or significant irregularities.

**Can stress really make periods irregular?**
Yes. Long-term stress can disturb the hormone balance and change the menstrual cycle.

**Can heavy bleeding cause anaemia?**
Yes. Prolonged heavy bleeding can lead to anaemia, so timely treatment is important.

## Summary

Irregular periods are sometimes temporary, but if they are repeated or severe, it is important to find the cause. This article is for general information only and is not a substitute for personal advice.' WHERE slug='irregular-periods-menstrual-problems';
UPDATE blog_posts SET body_en='## What is IVF?

IVF, or in vitro fertilisation, is a treatment in which an egg and sperm are joined in a laboratory outside the body. The resulting embryo is placed in the uterus a few days later. IVF can help with many causes of infertility, such as blocked fallopian tubes, sperm problems or irregular ovulation. However, it is not the first or only option for every couple.

## Who is it suitable for?

- If you have tried to conceive for a long time without success, and other treatments have not worked
- If the fallopian tubes are blocked or damaged
- If the sperm count or movement is low (ICSI may be used if needed)
- If you are older or your ovarian reserve has fallen
- In conditions such as endometriosis

Your doctor decides when IVF is suitable, taking into account your age, all test results and your overall health.

## What are the steps?

1. **Initial assessment:** Blood tests for both partners, hormone measurements and an ultrasound.
2. **Ovarian stimulation:** Injections and medicines are given to help several eggs mature together. Regular ultrasounds and blood tests are done during this stage.
3. **Egg collection:** Mature eggs are collected in a short procedure under light sedation or anaesthesia.
4. **Fertilisation in the lab:** The eggs and sperm are mixed in the lab. If needed, a single sperm is injected directly into an egg (ICSI).
5. **Embryo transfer:** The fertilised embryo is placed in the uterus. This is usually painless.
6. **Pregnancy test:** About two weeks later, a blood test confirms whether you are pregnant.

## What should you realistically expect?

Success with IVF varies from person to person. Age, egg quality, the cause of infertility and the experience of the clinic all have an effect. Success rates generally fall as age increases, and many couples need more than one attempt. If a clinic promises a guaranteed success, be cautious. Discuss the likely outcome for your own case with your doctor.

## Risks and complications

- **Ovarian hyperstimulation syndrome (OHSS):** A reaction to the medicines that can cause bloating, pain or discomfort. Severe cases need treatment.
- **Multiple pregnancy:** Transferring more than one embryo can lead to twins or more, which carries risks for both mother and baby. So modern practice usually aims to transfer a single embryo.
- **Emotional stress:** Repeated attempts and the financial cost can cause stress. Talking openly with your partner and your doctor helps.

## Common questions

**How much does IVF cost?**
The cost depends on the clinic, the type of treatment and the medicines needed. Ask for a detailed list of costs at the start.

**Is IVF painful?**
Sedation is given for egg collection, so pain is usually minimal. Mild discomfort can follow afterwards.

**If IVF fails, is there no chance left?**
Even if one attempt does not succeed, your doctor can review the cause and plan the next steps.

## Summary

IVF can be an effective treatment for infertility, but it needs a proper assessment, realistic expectations and regular supervision by your doctor. First find the cause, understand all the options, and then decide. For personal advice, book an appointment and discuss your situation directly.

**Sources:** ASRM (Reproductive Facts), https://www.reproductivefacts.org · ESHRE, https://www.eshre.eu' WHERE slug='ivf-treatment-what-to-know';
UPDATE blog_posts SET body_en='Menopause is not a disease. It is a natural stage of a woman''s life, in which the ovaries gradually stop producing hormones and periods stop for good. However, to handle this change comfortably, it is important to have accurate information.

## What is menopause?

Menopause is said to have occurred when there have been no periods for 12 consecutive months. It usually happens between the ages of 45 and 55, with the average age being around 50.

## Perimenopause, the stage before menopause

Changes in the body begin a few years before menopause. This period is called perimenopause, and periods become irregular during it.

## Common symptoms

- Hot flushes and sweating
- Sleep problems at night
- Mood changes, irritability or anxiety
- Dryness of the vagina
- Weight gain, especially around the abdomen
- Thinning of bones over the long term (osteoporosis)
- Changes in skin and hair

The severity varies from woman to woman. Some have only mild discomfort, while for others the symptoms have a major impact on daily life.

## Long-term health considerations

After menopause, falling oestrogen levels mean that two areas need extra attention:

- **Bone health:** the risk of osteoporosis increases
- **Heart health:** the risk of heart disease rises somewhat

So regular check-ups, foods rich in calcium and vitamin D, and regular exercise become especially important.

## How to get through it more easily

- Regular light exercise (walking, yoga)
- A balanced diet with calcium and vitamin D rich foods
- Enough sleep and reducing stress
- Hormone therapy or other medicines if needed, discussed with your doctor. These are not suitable for everyone, so the decision depends on the individual

## When should you see a doctor?

- If you have vaginal bleeding after menopause (this always needs investigation)
- If symptoms have a severe effect on your daily life
- If you are worried about your bones or heart health

## Common questions

**Is bleeding after menopause a cause for concern?**
Yes. It should not be ignored, and you should see a doctor as soon as possible so the cause can be found.

**Is hormone therapy safe for everyone?**
No. It depends on your health history, and the decision should be made after discussion with your doctor.

**Is it normal to gain weight after menopause?**
Hormonal changes slow the metabolic rate a little, so diet and exercise may need more attention during this time.

## Summary

Menopause is a natural chapter of life, not something to fear. With accurate information, a healthy lifestyle and, where needed, support from your doctor, this stage can be passed in good health. This article is for general information only and is not a substitute for personal advice.' WHERE slug='menopause-symptoms-and-care';
UPDATE blog_posts SET body_en='Towards the end of pregnancy, almost every family has the same question: will the baby be born normally (vaginal delivery), or by surgery (caesarean section)? The decision is made mainly with the safety of the mother and baby in mind.

## What is a normal delivery?

A normal, or vaginal, delivery is the natural process of birth. The uterus contracts and the baby passes through the birth canal. It is usually associated with a faster recovery and fewer complications.

## What is a caesarean section?

A caesarean section is a surgical procedure in which a small incision is made in the abdomen and uterus to deliver the baby. It is done when needed for the safety of the mother or baby, or when there are complications during a normal delivery.

## When might a caesarean be needed?

- The baby is not in a normal position (for example, breech)
- The placenta covers the cervix (placenta praevia)
- Labour does not progress for a long time
- Signs of distress in the baby''s heartbeat
- High blood pressure or another risk condition in the mother
- In certain situations after a previous caesarean

## Differences between the two

**Recovery time:** After a normal delivery, recovery is usually quicker. A caesarean takes longer.

**Time in hospital:** Usually shorter after a normal delivery and a little longer after a caesarean.

**Future pregnancies:** After a normal delivery, complications are generally fewer. After a caesarean, planning the next pregnancy needs a little more thought.

**Timing:** A normal delivery starts naturally and can take an unpredictable amount of time. A caesarean can be scheduled for a planned time.

## How is the decision made?

Through regular check-ups during pregnancy, ultrasounds and monitoring during labour, your doctor decides which method is safest for the mother and baby. Sometimes the plan has to change even after labour has started. This is normal and is part of the medical decision-making.

## Common questions

**Is a caesarean riskier than a normal delivery?**
A caesarean is a surgery, so it carries some extra risks. However, when it is needed, it is the safest option for both mother and baby.

**If I had a caesarean once, do I always need one again?**
Not always. If certain conditions are met, a trial of a normal delivery may be possible in a later pregnancy. This depends on your doctor''s assessment.

**Who decides the delivery method?**
The final decision should always be based on the medical condition of the mother and baby, and an experienced obstetrician can best advise on this.

## Summary

Both normal delivery and caesarean section are safe ways to give birth when they are used in the right circumstances and under the care of a qualified doctor. Regular check-ups during pregnancy are the most important step, so that the right decision can be made on time.' WHERE slug='normal-delivery-vs-c-section';
UPDATE blog_posts SET body_en='Many people panic when an ultrasound report mentions an "ovarian cyst". In reality, most cysts are completely normal and go away on their own.

## What is an ovarian cyst?

When a fluid-filled sac forms inside or on the surface of an ovary, it is called a cyst. Every menstrual cycle, the ovaries normally produce small cysts as part of ovulation. These are called functional cysts, and they are entirely normal.

## Types of cyst

- **Functional cysts:** the most common, part of the menstrual cycle, usually disappear on their own within 1 to 3 months
- **Dermoid cysts:** formed from cells present since birth, usually benign
- **Endometrioma:** a cyst caused by endometriosis (also known as a "chocolate cyst")
- **Complex cysts:** with a complicated structure that may need further tests

## Symptoms

Most cysts cause no symptoms and are found by chance on an ultrasound. If there are symptoms, they may include:

- A feeling of pressure or heaviness in the lower abdomen
- Pain on one side
- Irregular periods
- Sudden severe pain (if the cyst twists or ruptures, this is an emergency)

## When is urgent treatment needed?

Sudden severe lower abdominal pain with vomiting or fever should lead to going to hospital quickly, as this may be a sign of ovarian torsion (twisting) or rupture.

## How is treatment decided?

Based on the size, type and symptoms:

- **Small, simple cysts:** observation only, with a repeat ultrasound after a few months
- **Symptomatic or large cysts:** removal by laparoscopic surgery, where possible keeping the ovary and removing only the cyst
- **Complex or suspicious features:** further detailed tests (tumour markers, MRI) before a decision is made

## Common questions

**Are all ovarian cysts dangerous?**
No. Most are completely normal and safe, and go away on their own.

**Can a cyst affect pregnancy?**
Simple functional cysts usually do not cause problems. Endometriomas or large cysts can have an effect, and assessment is needed in some cases.

**Can cysts come back?**
Yes. Functional cysts can form in each cycle, and this can be a normal part of the process.

## Summary

Rather than being frightened by the name "ovarian cyst", the most important thing is to have regular follow-up ultrasounds. In most cases, it resolves on its own. This article is for general information only and is not a substitute for personal assessment.' WHERE slug='ovarian-cyst-symptoms-and-treatment';
UPDATE blog_posts SET body_en='PCOS, or polycystic ovary syndrome, is a common hormonal problem in women of reproductive age, affecting a significant share of them. It is not only a problem of the menstrual cycle. It can also affect weight, skin, hair and the ability to conceive in the future.

## What is PCOS?

PCOS is a hormonal condition in which the ovaries produce more male hormones (androgens) than normal. This disrupts ovulation and can cause small cyst-like structures to form in the ovaries.

## Common symptoms

- Irregular periods, or no periods for long stretches
- Extra hair on the face and body (hirsutism)
- Acne and oily skin
- Weight gain, especially around the abdomen
- Thinning hair
- Delayed or difficult conception

Not everyone has all of these symptoms, and their severity varies from person to person.

## Why does it happen?

The exact cause is still not fully understood, but several factors are believed to play an important role:

- **Insulin resistance:** When the body cannot use insulin properly, which increases androgen production
- **Genetics:** The risk is higher if there is a family history
- **Weight and lifestyle:** Excess weight can make symptoms worse

## How is it diagnosed?

PCOS is usually diagnosed by assessing three things: your menstrual pattern, hormone levels in a blood test, and the structure of the ovaries on ultrasound. A diagnosis is made when at least two of the three are present.

## Treatment

Treatment depends on your age, your symptoms and whether you want to become pregnant.

### Lifestyle changes

A balanced diet and regular exercise that reduce weight by 5 to 10 percent often help periods become regular and symptoms improve.

### Medicines

Hormonal treatment to regulate periods, medicines that improve insulin sensitivity, and ovulation-inducing medicines to help conception are used, as advised by your doctor.

### Long-term monitoring

Women with PCOS have a somewhat higher risk of type 2 diabetes and heart disease in the future, so regular health checks are important.

## Common questions

**Can I get pregnant with PCOS?**
Yes. With proper treatment and monitoring, many women with PCOS conceive, either naturally or with medical help.

**Does PCOS go away completely?**
PCOS is a long-term condition, but with good management the symptoms can be well controlled.

**When should I see a doctor?**
If your periods are irregular, if you have not conceived after a long time, or if unusual hair growth or acne increases, see a gynaecologist promptly.

## Summary

PCOS is common but manageable. If it is found early, good results are possible through lifestyle changes and proper treatment. This article is for general information only. For personal advice, contact your doctor directly.' WHERE slug='pcos-treatment-guide';
UPDATE blog_posts SET body_en='## Why prepare before pregnancy?

The months before pregnancy lay the foundation for the health of both mother and baby. Many complications, such as neural tube defects or gestational diabetes, can often be reduced when they are planned for in advance. So if you are planning a pregnancy, it is a good idea to talk to a doctor once beforehand.

## Why is folic acid important?

Folic acid (vitamin B9) plays a role in the early development of the baby''s brain and spinal cord. The neural tube starts forming well before most women know they are pregnant, so starting this vitamin early matters.

- **Dose:** For most women, 400 micrograms (0.4 mg) of folic acid a day is generally recommended.
- **When to start:** It is usually advised to start at least one month before pregnancy and continue through the first three months.
- **Higher doses:** If there has been a neural tube defect in an earlier pregnancy, or if you take certain medicines, a doctor may prescribe a higher dose. Take it only on medical advice.

Folic acid is found in foods such as spinach, lentils, chickpeas, liver and green vegetables. However, it is hard to get enough from food alone, so a supplement is usually needed.

## Which tests should be done before pregnancy?

The tests you need depend on your age, medical history and previous pregnancies. The usual ones include:

- Blood group and Rh factor
- Complete blood count (CBC), to check for anaemia
- Thyroid function (TSH)
- Rubella immunity
- Screening for hepatitis B, HIV and syphilis
- Blood sugar (diabetes) test
- An ultrasound if needed, such as an examination of the uterus and ovaries

Your partner may also need some tests, such as a semen analysis. It is best to get advice together as a couple.

## Weight, lifestyle and vaccines

- **Weight:** Being overweight or underweight can both raise the risk of problems with conception and in pregnancy. A balanced diet and regular walking help.
- **Smoking and alcohol:** Stop before you try to conceive.
- **Vaccines:** Rubella, tetanus and other necessary vaccines are safe to take before pregnancy, but some vaccines cannot be given during pregnancy. Discuss this with your doctor.
- **Medicines:** If you take any regular medicine, tell your doctor before you conceive.

## When should you see a doctor?

- If you have not conceived after a year of regular attempts (after six months if you are over 35).
- If your periods are irregular or have stopped for a long time.
- If you have had a miscarriage, a stillbirth or a baby with a birth defect before.
- If you have a long-term condition such as diabetes, high blood pressure or a thyroid problem.

## Common questions

**What if I start folic acid after I become pregnant?**
Starting before pregnancy is best. But starting as soon as you find out you are pregnant still helps, so do not delay and talk to your doctor.

**Does folic acid increase the chance of getting pregnant?**
Folic acid mainly works to prevent neural tube defects in the baby. It is not a medicine prescribed to increase the chance of conceiving.

## Summary

If you are planning a pregnancy, start folic acid at least a month before, get the necessary tests done and talk to a doctor. Your plan depends on your age and health, so ask your doctor directly about what is right for you.

**Sources:** CDC Folic Acid, https://www.cdc.gov/folic-acid/ · WHO, https://www.who.int' WHERE slug='preconception-care-folic-acid-before-pregnancy';
UPDATE blog_posts SET body_en='Many people are frightened when they are told they have uterine fibroids, thinking of cancer. The good news is that fibroids are almost always benign (non-cancerous) growths, and in many cases no treatment is needed at all.

## What are fibroids?

Fibroids are benign tumours that grow from the muscle tissue of the uterus. They can be inside the uterus, in its wall, or on its outer surface. They can be as small as a pea, or grow large, sometimes as big as a watermelon.

## How common are they?

A large share of women of reproductive age have fibroids. Many have no symptoms, so they do not know they have them.

## What are the symptoms?

- Heavy or prolonged menstrual bleeding
- A feeling of heaviness or pressure in the lower abdomen
- Frequent need to pass urine (when pressing on the bladder)
- Pain in the lower back or abdomen
- Difficulty conceiving (depending on size and position)

## Why do they develop?

The exact cause is not fully known, but the hormone oestrogen and a genetic tendency are believed to play an important role. After menopause, fibroids generally shrink or cause fewer problems.

## Diagnosis

Fibroids are usually found on ultrasound. MRI or other imaging may be used if needed.

## Treatment: surgery is not always needed

Treatment depends on the severity of symptoms, the size and position of the fibroids, and whether you want to become pregnant in the future:

- **No symptoms:** regular monitoring only, with no treatment
- **Mild symptoms:** medicines to control bleeding and pain
- **Large size or severe symptoms:** surgery to remove the fibroids (myomectomy), which leaves the uterus in place and removes only the fibroids. It is chosen when there is a wish to become pregnant in future
- **Severe or complicated cases:** removal of the uterus (hysterectomy) may be considered, usually for women who no longer want children

## Common questions

**Can fibroids turn into cancer?**
This is extremely rare. They almost always remain benign.

**Can I get pregnant if I have fibroids?**
Yes, but depending on their size and position, there may be difficulties in some cases. A doctor''s assessment is needed.

**Do all fibroids need surgery?**
No. In most cases, if there are no symptoms, monitoring alone is enough.

## Summary

If you are diagnosed with fibroids, there is no need to panic. They are usually benign and manageable. See a gynaecologist to decide the right course of action based on your symptoms. This article is for general information only and is not a substitute for personal advice.' WHERE slug='uterine-fibroid-symptoms-treatment';
UPDATE blog_posts SET body_en='Many women feel unsure about white discharge. Is it normal, or a sign of a problem? In reality, a small amount of white discharge is part of the vagina''s normal cleansing process. However, a change in its colour, smell or amount can need attention.

## What does normal white discharge look like?

Normal discharge is usually:

- Clear to whitish in colour
- Odourless or with a very mild smell
- Varying in amount at different times of the menstrual cycle (it may be higher around ovulation)
- Not accompanied by itching or burning

## When can it be a sign of a problem?

Be alert to any of these changes:

- Discharge turning yellow, green or grey
- Discharge with a foul smell
- Itching, burning or pain
- A sudden large increase in amount
- Lower abdominal pain or fever

## Possible causes

- **Yeast infection:** A common cause of itching and thick white discharge
- **Bacterial vaginosis:** One of the main causes of discharge with a fishy smell
- **Sexually transmitted infections:** In some cases they can change the discharge
- **Hormonal changes:** Due to pregnancy, contraceptive methods or the menstrual cycle

## What should you do?

- Change your underwear every day and wear cotton underwear
- Avoid washing the vagina with excess soap or scented products, as this can disrupt the natural balance of bacteria
- See a doctor if the symptoms last more than 2 to 3 days or become severe

Before buying medicine from a pharmacy to treat yourself, it is important to find the right cause, because the wrong medicine can make the problem worse.

## Common questions

**Is white discharge always a sign of infection?**
No. Light, odourless white discharge is usually normal and not a cause for worry.

**Is it normal for white discharge to increase during pregnancy?**
Yes, a slight increase during pregnancy due to hormonal changes is common. However, if the colour or smell changes, see your doctor.

**When should I see a doctor without delay?**
Seek prompt medical advice for discharge with a foul smell, severe itching, lower abdominal pain or fever.

## Summary

In most cases, white discharge is a normal physiological process. However, if its colour, smell or symptoms change, it is safest not to ignore it and to see a gynaecologist. This article is for general information only and is not a substitute for a personal diagnosis.' WHERE slug='white-discharge-causes-and-when-to-worry';

-- ---------- English chamber details and blog tags (name_en, location_en, visiting_hours_en, tags_en). Safe to re-run. ----------
ALTER TABLE chambers ADD COLUMN IF NOT EXISTS name_en VARCHAR(200) NOT NULL DEFAULT '';
ALTER TABLE chambers ADD COLUMN IF NOT EXISTS location_en TEXT NULL;
ALTER TABLE chambers ADD COLUMN IF NOT EXISTS visiting_hours_en VARCHAR(200) NOT NULL DEFAULT '';
ALTER TABLE blog_posts ADD COLUMN IF NOT EXISTS tags_en VARCHAR(255) NOT NULL DEFAULT '';

UPDATE chambers SET name_en = 'Citizen Lab Doctor & Diagnostic', location_en = 'Islam Tower, 22 KDA Avenue, Moylapota Mor, Khulna', visiting_hours_en = '3 PM to 5 PM (closed on Friday)' WHERE name = 'Citizen Lab Doctor & Diagnostic' AND name_en = '';
UPDATE chambers SET name_en = 'Khulna City Medical College & Hospital', location_en = '25/26, KDA Avenue, Moilapota Square, Khulna Sadar, Khulna', visiting_hours_en = '2 PM to 3 PM (closed on Friday)' WHERE name = 'Khulna City Medical College & Hospital' AND name_en = '';
UPDATE chambers SET name_en = 'Labaid Diagnostic, Khulna', location_en = 'KDA Plot No. A5, Majid Sarani, Sonadanga, Khulna', visiting_hours_en = '7 PM to 9 PM (closed on Friday)' WHERE name = 'Labaid Diagnostic, Khulna' AND name_en = '';

UPDATE blog_posts SET tags_en = 'Preparing for pregnancy, Folic acid, Preconception care' WHERE slug = 'preconception-care-folic-acid-before-pregnancy' AND tags_en = '';
UPDATE blog_posts SET tags_en = 'First trimester, NT scan, Dating ultrasound, Pregnancy tests' WHERE slug = 'first-trimester-pregnancy-care-tests' AND tags_en = '';
UPDATE blog_posts SET tags_en = 'Gestational diabetes, OGTT, Diabetes in pregnancy, Blood sugar' WHERE slug = 'gestational-diabetes-screening-and-control' AND tags_en = '';
UPDATE blog_posts SET tags_en = 'Cervical cancer, Pap smear, HPV test, HPV vaccine' WHERE slug = 'cervical-cancer-prevention-hpv-test-vaccine' AND tags_en = '';
UPDATE blog_posts SET tags_en = 'IVF, In vitro fertilisation, Infertility treatment' WHERE slug = 'ivf-treatment-what-to-know' AND tags_en = '';

-- ---------- Public AI chat rate limit (requests per IP) ----------
CREATE TABLE IF NOT EXISTS ai_requests (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip          VARCHAR(45)  NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v62: dual-language medical content for five condition pages (draft for doctor review)
-- Sources: WHO fact sheet on PCOS; Mayo Clinic (ovarian cysts, uterine fibroids, high-risk pregnancy, infertility/IVF);
-- NHS UK (ovarian cyst, pre-eclampsia, fibroids). No phone number or chamber address is written into the text:
-- the site shows the phone from settings (phone_display) in the sidebar and the chamber details come from settings.

UPDATE conditions SET
  title = 'পলিসিস্টিক ওভারি সিন্ড্রোম (PCOS) চিকিৎসা, খুলনা',
  title_en = 'PCOS Treatment in Khulna',
  meta_title = 'PCOS চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'PCOS-এর লক্ষণ, কারণ, রোগ নির্ণয় ও চিকিৎসা। খুলনায় গাইনী ও বন্ধ্যাত্ব বিশেষজ্ঞ ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন।',
  meta_title_en = 'PCOS Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'PCOS in Khulna: symptoms, causes, diagnosis and treatment. Consult gynaecologist Dr. Jebunnesa Jebu for hormone and fertility care.',
  overview_body = '## পলিসিস্টিক ওভারি সিন্ড্রোম কী?
পলিসিস্টিক ওভারি সিন্ড্রোম বা PCOS নারীদের একটি সাধারণ হরমোনজনিত রোগ। এতে শরীরে স্বাভাবিকের চেয়ে বেশি পুরুষ হরমোন (অ্যান্ড্রোজেন) তৈরি হয়। ফলে মাসিক অনিয়মিত হয়, ডিম্বস্ফোটন ঠিকমতো হয় না এবং গর্ভধারণে সমস্যা হতে পারে।

বিশ্ব স্বাস্থ্য সংস্থা (WHO) জানাচ্ছে, প্রজনন বয়সের প্রায় ১০ থেকে ১৩ শতাংশ নারী এই সমস্যায় আক্রান্ত। আক্রান্তদের প্রায় ৭০ শতাংশ জানেন না যে তাদের এই সমস্যা আছে।

নামে "সিস্ট" শব্দ থাকলেও PCOS মানেই ডিম্বাশয়ে সিস্ট নয়। আল্ট্রাসাউন্ডে ডিম্বাশয়ে অনেক ছোট ফলিকল দেখা যেতে পারে, যা রোগ নির্ণয়ের একটি উপাদান মাত্র।

PCOS একটি দীর্ঘমেয়াদি অবস্থা। সঠিক পরিকল্পনা, জীবনধারার পরিবর্তন ও নিয়মিত চিকিৎসায় লক্ষণ নিয়ন্ত্রণে রাখা যায় এবং ডায়াবেটিস ও হৃদ্রোগের ঝুঁকি কমানো যায়। গর্ভধারণের ইচ্ছা থাকলেও সঠিক চিকিৎসায় সন্তান নেওয়ার সম্ভাবনা বাড়ে।',
  symptoms_body = '## PCOS-এর লক্ষণসমূহ
PCOS-এর লক্ষণ প্রত্যেকের ক্ষেত্রে একরকম নয় এবং সময়ের সঙ্গে বদলাতে পারে। সাধারণত যা দেখা যায়:

- অনিয়মিত, ব্যথাযুক্ত বা অতিরিক্ত রক্তস্রাবযুক্ত মাসিক
- গর্ভধারণে অসুবিধা বা বন্ধ্যাত্ব
- মুখে, বুকে বা পেটে অতিরিক্ত লোম গজানো
- মাথার চুল পাতলা হওয়া বা পুরুষের ধাঁচে টাক পড়া
- ব্রণ বা তৈলাক্ত ত্বক',
  causes_body = '## PCOS-এর কারণ ও ঝুঁকি
PCOS-এর সঠিক কারণ এখনো পুরোপুরি জানা যায়নি। তবে কয়েকটি বিষয় ঝুঁকি বাড়ায়:

- পরিবারে মা, বোন বা অন্য আত্মীয়ের PCOS বা টাইপ ২ ডায়াবেটিস থাকলে
- ইনসুলিন রেজিস্ট্যান্স, অর্থাৎ শরীরে ইনসুলিনের কার্যকারিতা কমে যাওয়া। অনেক রোগীর মধ্যে এটি থাকে এবং হরমোনের ভারসাম্য নষ্ট করতে পারে
- স্থূলতা বা অতিরিক্ত ওজন, যা লক্ষণ বাড়াতে পারে এবং দীর্ঘমেয়াদি ঝুঁকি বাড়ায়',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
অন্য কারণগুলো বাদ দেওয়ার পর নিচের তিনটির মধ্যে অন্তত দুটি থাকলে সাধারণত PCOS নির্ণয় করা হয়:

- হাইপারঅ্যান্ড্রোজেনিজমের লক্ষণ বা রক্তে বেশি মাত্রার পুরুষ হরমোন
- অনিয়মিত বা অনুপস্থিত মাসিক
- আল্ট্রাসাউন্ডে PCOS-ধাঁচের ডিম্বাশয়

প্রয়োজনীয় পরীক্ষা:

- রক্তে হরমোন পরীক্ষা (টেস্টোস্টেরন ও অন্যান্য হরমোন)
- ইনসুলিন রেজিস্ট্যান্স ও রক্তে শর্করা পরীক্ষা, কারণ PCOS-এর সঙ্গে ডায়াবেটিস ও হৃদ্রোগের ঝুঁকি জড়িত
- পেলভিক আল্ট্রাসাউন্ড

## চিকিৎসা
PCOS-এর পূর্ণ নিরাময় নেই, তবে নিয়ন্ত্রণ সম্ভব। চিকিৎসা ঠিক করা হয় উপসর্গ, বয়স ও সন্তান নেওয়ার পরিকল্পনার ভিত্তিতে:

- জীবনধারা পরিবর্তন: খাদ্যাভ্যাস, নিয়মিত ব্যায়াম ও ওজন নিয়ন্ত্রণ
- মাসিক নিয়মিত করা ও লোম-ব্রণ কমানোর ওষুধ (যেমন সম্মিলিত গর্ভনিরোধক বড়ি বা অ্যান্ড্রোজেন রিসেপ্টর ব্লকার)
- গর্ভধারণের ইচ্ছা থাকলে ডিম্বস্ফোটনের ওষুধ, প্রয়োজনে IVF
- দীর্ঘমেয়াদি স্বাস্থ্য রক্ষা: রক্তচাপ, ডায়াবেটিস ও কোলেস্টেরল নিয়মিত পরীক্ষা

## খুলনায় PCOS-এর পরামর্শ নিন
খুলনায় PCOS বা বন্ধ্যাত্ব নিয়ে সমস্যায় থাকলে ডাঃ জেবুন্নেসা জেবুর চেম্বারে সঠিক পরীক্ষা ও পরিকল্পিত চিকিৎসার জন্য যোগাযোগ করুন। মাসিক দীর্ঘদিন অনিয়মিত থাকলে, লোম বা ব্রণ বাড়লে, কিংবা এক বছর নিয়মিত চেষ্টার পরেও গর্ভধারণ না হলে দেরি না করে পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## What is PCOS?
Polycystic ovary syndrome (PCOS) is a common hormonal condition in women of reproductive age. The body makes more male hormones (androgens) than usual. This can make periods irregular, stop ovulation from happening regularly and make it harder to conceive.

The World Health Organization estimates that 10 to 13 percent of women of reproductive age are affected, and up to 70 percent of affected women have not been diagnosed.

Despite the name, PCOS does not always mean ovarian cysts. On ultrasound the ovaries may show many small follicles, which is one part of the diagnosis.

PCOS is a long-term condition. With the right plan, lifestyle changes and regular care, symptoms can be controlled and the risk of diabetes and heart disease can be reduced. If you want a baby, the right treatment can improve your chances.',
  symptoms_body_en = '## Symptoms of PCOS
Symptoms vary from person to person and can change over time. Common signs include:

- Irregular, painful or heavy periods
- Difficulty conceiving or infertility
- Excess facial or body hair
- Hair thinning or male-pattern hair loss
- Acne or oily skin',
  causes_body_en = '## Causes and Risk Factors
The exact cause of PCOS is not fully understood. These factors increase the risk:

- A family history of PCOS or type 2 diabetes in a mother, sister or other relative
- Insulin resistance, where the body responds less well to insulin. Many people with PCOS have it, and it can disturb hormone balance
- Obesity or excess weight, which can worsen symptoms and long-term risk',
  diagnosis_body_en = '## Diagnosis and Tests
After other causes are ruled out, PCOS is usually diagnosed when at least two of these three features are present:

- Signs of high androgens, or raised testosterone in blood tests
- Irregular or absent periods
- Polycystic-looking ovaries on ultrasound

Tests usually include:

- Blood tests for hormones such as testosterone
- Tests for insulin resistance and blood sugar, since PCOS is linked to diabetes and heart disease
- Pelvic ultrasound

## Treatment
There is no cure for PCOS, but it can be managed. Treatment depends on symptoms, age and plans for pregnancy:

- Lifestyle changes: diet, regular exercise and weight control
- Medicines to regulate periods and reduce hair growth and acne, such as combined contraceptive pills or androgen receptor blockers
- If pregnancy is planned, ovulation-stimulating medicines, and IVF if needed
- Long-term health checks: blood pressure, blood sugar and cholesterol

## Consult a Specialist in Khulna
If you have PCOS or fertility concerns in Khulna, visit the chamber of Dr. Jebunnesa Jebu for proper tests and a planned treatment. Seek advice if your periods stay irregular, if hair growth or acne is increasing, or if you have not conceived after a year of regular attempts. [Book an appointment](/appointment).'
WHERE slug = 'pcos';

UPDATE conditions SET
  title = 'জরায়ুর ফাইব্রয়েড চিকিৎসা, খুলনা',
  title_en = 'Uterine Fibroids Treatment in Khulna',
  meta_title = 'জরায়ুর ফাইব্রয়েড চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'জরায়ুর ফাইব্রয়েডের লক্ষণ, কারণ, রোগ নির্ণয় ও চিকিৎসা। ফাইব্রয়েড সাধারণত ক্যান্সার নয়; জানুন কখন চিকিৎসা লাগে, খুলনায়।',
  meta_title_en = 'Uterine Fibroids Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Uterine fibroids in Khulna: symptoms, causes, tests and treatment options. Fibroids are usually benign. Consult Dr. Jebunnesa Jebu.',
  overview_body = '## জরায়ুর ফাইব্রয়েড কী?
জরায়ুর ফাইব্রয়েড হলো জরায়ুর পেশিস্তরে গড়ে ওঠা সাধারণত ক্ষতিহীন (বিনাইন) টিউমার। এটি ক্যান্সার নয়। ফাইব্রয়েড ছোট বা বড়, একটি বা একাধিক হতে পারে, এবং জরায়ুর ভেতরে, দেয়ালে বা বাইরের দিকে থাকতে পারে।

প্রজনন বয়সের নারীদের মধ্যে ফাইব্রয়েড অনেক সাধারণ। অনেকের কোনো উপসর্গ থাকে না, তাই নিয়মিত আল্ট্রাসাউন্ডে ধরা পড়ে। কতটুকু চিকিৎসা লাগবে, তা নির্ভর করে উপসর্গ, আকার, সংখ্যা, অবস্থান এবং ভবিষ্যতে সন্তান নেওয়ার পরিকল্পনার ওপর।',
  symptoms_body = '## ফাইব্রয়েডের লক্ষণসমূহ
ফাইব্রয়েডের লক্ষণ নির্ভর করে এর আকার ও অবস্থানের ওপর। সাধারণত যা দেখা যায়:

- অতিরিক্ত বা দীর্ঘস্থায়ী মাসিক রক্তস্রাব, কিংবা ব্যথাযুক্ত মাসিক
- ঘন ঘন প্রস্রাব, বা প্রস্রাবে কষ্ট
- তলপেটে চাপ বা ব্যথা, পেট ফোলা
- কোষ্ঠকাঠিন্য, পেট ফাঁপা
- পিঠ বা নিচের পেটে ব্যথা, সহবাসের সময় ব্যথা
- খুব কম ক্ষেত্রে হঠাৎ তীব্র ব্যথা, যখন ফাইব্রয়েডের রক্ত সরবরাহ কমে যায়

অনেকের কোনো লক্ষণ না থাকলেও ফাইব্রয়েড থাকতে পারে।',
  causes_body = '## ফাইব্রয়েডের কারণ ও ঝুঁকি
ফাইব্রয়েড কেন হয়, তা পুরোপুরি জানা যায়নি। জিনের পরিবর্তন, ইস্ট্রোজেন ও প্রোজেস্টেরন হরমোন এবং ইনসুলিনের মতো গ্রোথ ফ্যাক্টর এর বৃদ্ধিতে ভূমিকা রাখে বলে ধারণা করা হয়। নিচের বিষয়গুলো ঝুঁকি বাড়ায়:

- প্রজনন বয়স
- পরিবারে মা বা বোনের ফাইব্রয়েড থাকা
- ১০ বছর বয়সের আগে প্রথম মাসিক শুরু হওয়া
- স্থূলতা
- ভিটামিন ডি-এর ঘাটতি
- বেশি লাল মাংস এবং কম শাকসবজি, ফল ও দুধের খাবার
- মদ্যপান',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
রোগ নির্ণয় শুরু হয় শারীরিক পরীক্ষা ও উপসর্গ জানার মাধ্যমে। এরপর নিশ্চিত করতে আল্ট্রাসাউন্ড স্ক্যান করা হয়, যাতে ফাইব্রয়েডের সংখ্যা, অবস্থান ও আকার জানা যায়।

প্রয়োজনে রক্ত পরীক্ষা করে দেখা হয় রক্তস্বল্পতা (অ্যানিমিয়া) আছে কি না, বিশেষ করে ভারী মাসিকের ক্ষেত্রে।

## চিকিৎসা
উপসর্গ না থাকলে চিকিৎসার প্রয়োজন নাও হতে পারে। যখন চিকিৎসা লাগে, সাধারণত নিচের ধরনের বিকল্প থাকে:

- ওষুধ: ব্যথানাশক (যেমন ইবুপ্রোফেন, ন্যাপ্রক্সেন), ভারী রক্তস্রাবে ট্রানেক্সামিক অ্যাসিড বা হরমোন ওষুধ
- প্রসিডিউর: ইউটেরাইন আর্টারি এম্বোলাইজেশন (ফাইব্রয়েডের রক্ত সরবরাহ বন্ধ করে সঙ্কুচিত করা), এন্ডোমেট্রিয়াল অ্যাবলেশন
- অস্ত্রোপচার: মায়োমেকটমি (শুধু ফাইব্রয়েড অপসারণ) বা হিস্টেরেক্টমি (জরায়ু অপসারণ)

সন্তান নেওয়ার পরিকল্পনা থাকলে আগেই ডাক্তারকে জানান, কারণ কিছু চিকিৎসা সন্তানধারণের ক্ষমতায় প্রভাব ফেলতে পারে। তীব্র যোনি রক্তস্রাব বা হঠাৎ তীব্র পেট ব্যথা হলে দেরি না করে জরুরি চিকিৎসা নিন।

## খুলনায় ফাইব্রয়েডের পরামর্শ নিন
খুলনায় জরায়ুর ফাইব্রয়েড নিয়ে চিন্তা থাকলে গাইনী ও প্রসূতি চেম্বার খুলনায় ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। আল্ট্রাসাউন্ড রিপোর্ট সঙ্গে আনুন, যাতে ফাইব্রয়েডের আকার ও অবস্থান বুঝে সঠিক সিদ্ধান্ত নেওয়া যায়। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## What are uterine fibroids?
Uterine fibroids are non-cancerous (benign) growths that develop in the muscle wall of the womb. They are not cancer. Fibroids can be small or large, one or several, and may sit inside the womb, within its wall or on its outer surface.

Fibroids are very common in women of reproductive age. Many women have no symptoms and find them on a routine ultrasound. Whether treatment is needed depends on symptoms, size, number, position and plans for a future pregnancy.',
  symptoms_body_en = '## Symptoms of fibroids
Symptoms depend on the size and position of the fibroids. Common signs include:

- Heavy or prolonged periods, or painful periods
- Frequent urination, or difficulty passing urine
- Pelvic pressure or pain, and abdominal swelling
- Constipation and bloating
- Lower back or lower abdominal pain, or pain during sex
- In rare cases, sudden severe pain when a fibroid loses its blood supply

Many women with fibroids have no symptoms at all.',
  causes_body_en = '## Causes and Risk Factors
The exact cause of fibroids is not fully known. Gene changes, the hormones oestrogen and progesterone, and growth factors such as insulin-like growth factor are thought to help them grow. These factors increase the risk:

- Reproductive age
- A mother or sister with fibroids
- Starting periods before age 10
- Obesity
- Low vitamin D
- A diet high in red meat and low in fruit, vegetables and dairy
- Alcohol use',
  diagnosis_body_en = '## Diagnosis and Tests
Diagnosis starts with your symptoms and a physical examination. An ultrasound scan then confirms the fibroids and shows their number, position and size.

A blood test may be done to check for anaemia, especially in women with heavy periods.

## Treatment
If there are no symptoms, treatment may not be needed. When treatment is needed, the options usually include:

- Medicines: pain relief such as ibuprofen or naproxen, and tranexamic acid or hormone medicines for heavy bleeding
- Procedures: uterine artery embolisation, which blocks the blood supply so fibroids shrink, and endometrial ablation
- Surgery: myomectomy (removing only the fibroids) or hysterectomy (removing the womb)

If you plan a pregnancy, tell your doctor early, as some treatments can affect fertility. Seek urgent care for heavy vaginal bleeding or sudden severe pelvic pain.

## Consult a Specialist in Khulna
If you are worried about uterine fibroids in Khulna, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. Bring your ultrasound report so the size and position of the fibroids can be reviewed before deciding on treatment. [Book an appointment](/appointment).'
WHERE slug = 'uterine-fibroid';

UPDATE conditions SET
  title = 'ডিম্বাশয়ের সিস্ট চিকিৎসা, খুলনা',
  title_en = 'Ovarian Cyst Treatment in Khulna',
  meta_title = 'ডিম্বাশয়ের সিস্ট চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'ডিম্বাশয়ের সিস্টের ধরন, লক্ষণ ও বিপদ-সংকেত। কখন নজরদারি যথেষ্ট, কখন জরুরি চিকিৎসা বা অস্ত্রোপচার লাগে, খুলনায় জানুন।',
  meta_title_en = 'Ovarian Cyst Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Ovarian cysts in Khulna: types, symptoms, warning signs, scans and treatment. Know when to watch and when to act. Dr. Jebunnesa Jebu.',
  overview_body = '## ডিম্বাশয়ে সিস্ট কী?
ডিম্বাশয়ে সিস্ট হলো ডিম্বাশয়ের ভেতরে বা গায়ে তরল বা অন্য উপাদানে ভরা একটি থলি। বেশিরভাগ সিস্টই ফাংশনাল সিস্ট। এগুলো মাসিক চক্রের স্বাভাবিক পরিবর্তন থেকে হয় এবং সাধারণত ক্ষতিকর নয়। অনেক ক্ষেত্রে ২ থেকে ৩টি মাসিক চক্রের মধ্যে নিজে থেকেই মিলিয়ে যায়।

কিছু সিস্ট ভিন্ন ধরনের, যেমন ডার্ময়েড সিস্ট, সিস্টাডেনোমা বা এন্ডোমেট্রিওমা। এন্ডোমেট্রিওমা এন্ডোমেট্রিওসিসের সঙ্গে যুক্ত।

আল্ট্রাসাউন্ড রিপোর্টে সিস্ট দেখলেই আতঙ্কিত হওয়ার দরকার নেই। সিস্টের আকার, ধরন ও উপসর্গের ওপর ভিত্তি করে চিকিৎসা ঠিক হয়।',
  symptoms_body = '## লক্ষণসমূহ
বেশিরভাগ সিস্টে কোনো লক্ষণ থাকে না। সিস্ট বড় হলে সাধারণত যা দেখা যায়:

- পেটের একপাশে নিচের দিকে ব্যথা, যা ভোঁতা বা তীব্র হতে পারে
- তলপেটে ভারী বা চাপ লাগা
- পেট ফাঁপা

## জরুরি বিপদ-সংকেত
নিচের লক্ষণগুলো দেখা দিলে দেরি না করে জরুরি চিকিৎসা নিন:

- হঠাৎ তীব্র পেট বা শ্রোণীর ব্যথা
- ব্যথার সঙ্গে জ্বর বা বমি
- শকের লক্ষণ: ঠান্ডা ও ঘামে ভেজা ত্বক, দ্রুত শ্বাস, মাথা ঘোরা বা দুর্বলতা

এই লক্ষণগুলো ডিম্বাশয় মোড় খাওয়া (টর্শন) বা সিস্ট ফেটে যাওয়ার মতো জটিলতার ইঙ্গিত হতে পারে।',
  causes_body = '## কারণ ও ঝুঁকি
- বেশিরভাগ ফাংশনাল সিস্ট মাসিক চক্রের স্বাভাবিক পরিবর্তন থেকে হয়।
- অন্য ধরনের সিস্ট জার্ম সেল, ডিম্বাশয়ের বাইরের আবরণের কোষ বা এন্ডোমেট্রিওসিসের টিস্যু থেকে তৈরি হতে পারে।

ঝুঁকি বাড়ায়:

- ডিম্বস্ফোটনের ওষুধ (যেমন ক্লোমিফিন, লেট্রোজল)
- গর্ভাবস্থা
- এন্ডোমেট্রিওসিস
- গুরুতর শ্রোণী সংক্রমণ
- আগে ডিম্বাশয়ে সিস্ট হওয়ার ইতিহাস',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- চিকিৎসক প্রথমে উপসর্গ জানবেন এবং পেট ও শ্রোণীর শারীরিক পরীক্ষা করবেন।
- ডিম্বাশয়ের আল্ট্রাসাউন্ড (প্রয়োজনে ভ্যাজাইনাল প্রোব) সিস্টের আকার, ধরন ও বৈশিষ্ট্য বুঝতে সবচেয়ে গুরুত্বপূর্ণ পরীক্ষা।
- সিস্ট থাকলে কয়েক সপ্তাহ পরে আবার স্ক্যান করা হতে পারে, অথবা গাইনী বিশেষজ্ঞের কাছে রেফার করা হতে পারে।
- ক্যান্সারের আশঙ্কা থাকলে রক্ত পরীক্ষা করা হয়। তবে রক্তে বেশি মাত্রা মানেই ক্যান্সার নয়, এন্ডোমেট্রিওসিস, ফাইব্রয়েড বা শ্রোণী সংক্রমণেও এটি বাড়তে পারে।

## চিকিৎসা
- বেশিরভাগ সিস্ট কয়েক মাসের মধ্যে নিজে থেকে মিলিয়ে যায়, তাই অনেক ক্ষেত্রে নজরদারিতে রাখা হয়।
- ব্যথার জন্য ওষুধ দেওয়া হতে পারে।
- সিস্ট বড় হলে, উপসর্গ থাকলে বা সন্দেহজনক হলে অস্ত্রোপচার বিবেচনা করা হয়। সম্ভব হলে সন্তানধারণের ক্ষমতা রক্ষা করে চিকিৎসার চেষ্টা করা হয়।
- মেনোপজের পরে সাধারণত এক বছর ধরে নিয়মিত স্ক্যান ও রক্ত পরীক্ষার পরামর্শ দেওয়া হয়।

## খুলনায় সিস্টের পরামর্শ নিন
খুলনায় ডিম্বাশয়ের সিস্ট নিয়ে চিন্তা থাকলে গাইনী ও প্রসূতি চেম্বার খুলনায় ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। আল্ট্রাসাউন্ড রিপোর্ট সঙ্গে আনুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## What is an ovarian cyst?
An ovarian cyst is a sac filled with fluid or other material that forms in or on an ovary. Most are functional cysts. They come from normal changes in the menstrual cycle and are usually harmless. Many disappear on their own within two to three menstrual cycles.

Some cysts are of other types, such as dermoid cysts, cystadenomas or endometriomas. An endometrioma is linked to endometriosis.

Seeing a cyst on an ultrasound report does not mean you should panic. Treatment depends on the size and type of the cyst and on your symptoms.',
  symptoms_body_en = '## Symptoms
Most cysts cause no symptoms. When a cyst is large, common signs include:

- Dull or sharp pain on one side of the lower abdomen
- Heaviness or pressure in the lower abdomen
- Bloating

## Urgent warning signs
Seek urgent care without delay if you have:

- Sudden, intense pain in the belly or pelvis
- Pain with fever or vomiting
- Signs of shock: cold, clammy skin, fast breathing, dizziness or weakness

These can signal complications such as ovarian torsion (the ovary twisting) or a cyst rupturing.',
  causes_body_en = '## Causes and Risk Factors
- Most functional cysts come from normal changes in the menstrual cycle.
- Other types can develop from germ cells, cells on the outer surface of the ovary, or endometriosis tissue.

These factors increase the risk:

- Ovulation-inducing fertility medicines such as clomiphene or letrozole
- Pregnancy
- Endometriosis
- Severe pelvic infection
- A previous ovarian cyst',
  diagnosis_body_en = '## Diagnosis and Tests
- The doctor first asks about your symptoms and examines the abdomen and pelvis.
- An ultrasound of the ovaries, sometimes with a probe placed inside the vagina, is the most important test to assess the size, type and features of a cyst.
- If a cyst is found, a repeat scan may be done after a few weeks, or you may be referred to a gynaecologist.
- If cancer is a concern, blood tests are done. A high level does not necessarily mean cancer, since endometriosis, fibroids or pelvic infection can also raise it.

## Treatment
- Most cysts disappear within a few months, so many are simply monitored.
- Medicines may be given for pain.
- Surgery is considered if the cyst is large, causing symptoms or looks suspicious. Where possible, surgeons aim to preserve fertility.
- After menopause, regular scans and blood tests over about a year are usually recommended.

## Consult a Specialist in Khulna
If you are worried about an ovarian cyst in Khulna, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. Bring your ultrasound report. [Book an appointment](/appointment).'
WHERE slug = 'ovarian-cyst';

UPDATE conditions SET
  title = 'উচ্চ ঝুঁকিপূর্ণ গর্ভাবস্থা, খুলনায় গাইনী ও প্রসূতি যত্ন',
  title_en = 'High-Risk Pregnancy Care in Khulna',
  meta_title = 'উচ্চ ঝুঁকিপূর্ণ গর্ভাবস্থা চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'উচ্চ ঝুঁকিপূর্ণ গর্ভাবস্থার কারণ, নিয়মিত পর্যবেক্ষণ ও জরুরি সতর্কসংকেত। খুলনায় গাইনী ও প্রসূতি বিশেষজ্ঞের নিয়মিত সেবা নিন।',
  meta_title_en = 'High-Risk Pregnancy Care in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'High-risk pregnancy in Khulna: causes, monitoring tests and warning signs. Regular obstetric care with Dr. Jebunnesa Jebu.',
  overview_body = '## উচ্চ ঝুঁকিপূর্ণ গর্ভাবস্থা কী?
উচ্চ ঝুঁকিপূর্ণ গর্ভাবস্থা মানে এমন গর্ভাবস্থা, যেখানে মা বা শিশুর স্বাস্থ্যের ঝুঁকি স্বাভাবিকের চেয়ে বেশি। তাই এখানে বাড়তি পর্যবেক্ষণ ও বিশেষায়িত যত্ন প্রয়োজন।

এর মানে এই নয় যে খারাপ কিছু হবেই। অনেক উচ্চ ঝুঁকিপূর্ণ গর্ভাবস্থাও সঠিক নজরদারি ও সময়মতো চিকিৎসায় নিরাপদে শেষ হয়। ঝুঁকি আগে থেকে চিহ্নিত করা গেলে বড় জটিলতা প্রতিরোধ করা বা দ্রুত সামলানো সম্ভব হয়।',
  symptoms_body = '## লক্ষণ ও সতর্কসংকেত
উচ্চ ঝুঁকিপূর্ণ গর্ভাবস্থার নির্দিষ্ট কোনো একক লক্ষণ নেই। নিচের যে কোনো লক্ষণ দেখা দিলে সঙ্গে সঙ্গে চিকিৎসকের সঙ্গে যোগাযোগ করুন:

- যোনি দিয়ে রক্তপাত, পানির মতো স্রাব, বা পেট বা শ্রোণীতে ব্যথা ও ঢিলেমি
- শিশুর নড়াচড়া স্বাভাবিকের চেয়ে কম মনে হলে
- তীব্র মাথাব্যথা, চোখে ঝাপসা দেখা বা দৃষ্টির পরিবর্তন
- মুখ, হাত বা আঙুলে হঠাৎ বা তীব্র ফোলা
- জ্বর, কাঁপুনি, প্রস্রাবে ব্যথা বা জ্বালা
- বারবার বমি বা বমি বমি ভাব
- বুকে ব্যথা, শ্বাসকষ্ট, মাথা ঘোরা বা অতিরিক্ত দুর্বলতা',
  causes_body = '## কারণ ও ঝুঁকির উৎস
নিচের বিষয়গুলো গর্ভাবস্থাকে উচ্চ ঝুঁকিপূর্ণ করে তুলতে পারে:

- বয়স: ২০ বছরের কম বা ৩৫ বছরের বেশি
- স্বাস্থ্য সমস্যা: উচ্চ রক্তচাপ, স্থূলতা, ডায়াবেটিস, মৃগী, থাইরয়েড রোগ, হৃদ্রোগ বা রক্তের রোগ, অনিয়ন্ত্রিত হাঁপানি, সংক্রমণ
- অভ্যাস: ধূমপান, মদ্যপান, মাদক সেবন
- গর্ভাবস্থার জটিলতা: স্বাভাবিক স্থানে না থেকে অস্বাভাবিক স্থানে প্লাসেন্টা, শিশুর বৃদ্ধি কম হওয়া
- একাধিক গর্ভ (যমজ বা তার বেশি), যা বন্ধ্যাত্ব চিকিৎসার পরে বেশি দেখা যায়
- আগের গর্ভাবস্থার ইতিহাস: প্রি-ইক্লাম্পসিয়া, আগে অকালে সন্তান জন্ম, জেনেটিক সমস্যা, গর্ভপাত বা মৃত সন্তান জন্ম',
  diagnosis_body = '## রোগ নির্ণয় ও পর্যবেক্ষণ
- গর্ভধারণের শুরুতেই ঝুঁকির মূল্যায়ন এবং পূর্ণাঙ্গ ইতিহাস নেওয়া।
- নিয়মিত রক্তচাপ ও প্রস্রাব পরীক্ষা, যাতে প্রি-ইক্লাম্পসিয়া ধরা পড়ে। রক্ত পরীক্ষা করা হয় সংক্রমণ, রক্তস্বল্পতা ও ডায়াবেটিসের জন্য।
- বিশেষ আল্ট্রাসাউন্ড, যা শিশুর বৃদ্ধি ও প্লাসেন্টা পর্যবেক্ষণ করে, সার্ভিক্সের দৈর্ঘ্য মাপে। প্রয়োজনে নন-স্ট্রেস টেস্ট বা বায়োফিজিক্যাল প্রোফাইল করা হয়।
- জেনেটিক স্ক্রিনিং, এবং প্রয়োজনে অ্যামনিওসেন্টেসিস বা সিভিএস (CVS) পরীক্ষা।
- প্রয়োজনে মেটারনাল-ফিটাল মেডিসিন বা জেনেটিক্স বিশেষজ্ঞের রেফারেল।

## খুলনায় নিয়মিত চেকআপ
খুলনায় উচ্চ ঝুঁকিপূর্ণ গর্ভাবস্থায় গাইনী ও প্রসূতি চেম্বার খুলনায় ডাঃ জেবুন্নেসা জেবুর নিয়মিত পর্যবেক্ষণ শুরু করুন। আগের রিপোর্টগুলো সঙ্গে আনুন। জরুরি সতর্কসংকেত থাকলে অপেক্ষা না করে সরাসরি জরুরি সেবায় যান। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## What is a high-risk pregnancy?
A high-risk pregnancy is one in which the risk to the mother or baby is higher than usual, so it needs closer monitoring and specialist care.

This does not mean something will go wrong. Many high-risk pregnancies end safely with the right monitoring and timely treatment. Identifying risks early makes it possible to prevent major complications or manage them quickly.',
  symptoms_body_en = '## Symptoms and warning signs
There is no single symptom that defines a high-risk pregnancy. Contact your care team at once if you notice any of these:

- Vaginal bleeding, watery discharge, or abdominal or pelvic pain or cramping
- Baby moving less than usual
- Severe headache, blurred vision or other changes in vision
- Sudden or severe swelling of the face, hands or fingers
- Fever, chills, or pain or burning when passing urine
- Repeated vomiting or constant nausea
- Chest pain, shortness of breath, dizziness or extreme tiredness',
  causes_body_en = '## Causes and Risk Factors
These factors can make a pregnancy high-risk:

- Age: under 20 or over 35
- Health conditions: high blood pressure, obesity, diabetes, epilepsy, thyroid disease, heart or blood disorders, poorly controlled asthma, infections
- Lifestyle: smoking or vaping, alcohol, drug use
- Pregnancy complications: placenta in an unusual position, poor fetal growth, Rh sensitisation
- Multiple pregnancy (twins or more), which is more likely after fertility treatment
- Past pregnancy history: preeclampsia, earlier preterm birth, a baby with a genetic condition, miscarriage or stillbirth',
  diagnosis_body_en = '## Diagnosis and Monitoring
- Risk assessment and a full history at the start of pregnancy.
- Regular blood pressure and urine checks to detect preeclampsia. Blood tests check for infection, anaemia and diabetes.
- Specialised ultrasound to monitor the baby''s growth and the placenta, and to measure cervical length. Non-stress tests or a biophysical profile may be done when needed.
- Genetic screening, and amniocentesis or chorionic villus sampling (CVS) if a genetic concern needs confirming.
- Referral to a maternal-fetal medicine or genetics specialist when needed.

## Regular check-ups in Khulna
If your pregnancy is high-risk and you are in Khulna, start regular monitoring at the gynaecology and maternity chamber in Khulna with Dr. Jebunnesa Jebu. Bring your previous reports. If you have any urgent warning sign, do not wait: go straight to emergency care. [Book an appointment](/appointment).'
WHERE slug = 'high-risk-pregnancy';

UPDATE conditions SET
  title = 'বারবার IVF ব্যর্থতা: খুলনায় বন্ধ্যাত্ব বিশেষজ্ঞ ডাক্তারের পরামর্শ',
  title_en = 'Repeated IVF Failure: Infertility Specialist in Khulna',
  meta_title = 'বারবার IVF ব্যর্থতা খুলনা | বন্ধ্যাত্ব বিশেষজ্ঞ',
  meta_description = 'IVF বারবার ব্যর্থ হলে কারণ খুঁজে পরিকল্পনা বদলানো জরুরি। খুলনায় বন্ধ্যাত্ব বিশেষজ্ঞ ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন।',
  meta_title_en = 'Repeated IVF Failure in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Repeated IVF failure in Khulna: possible causes, tests for both partners and next treatment options. Infertility care with Dr. Jebunnesa Jebu.',
  overview_body = '## IVF ও বারবার ব্যর্থতা কী?
IVF বা ইনভিট্রো ফার্টিলাইজেশন একটি সহায়ক প্রজনন পদ্ধতি (ART)। এতে নারীর ডিম্বাশয়ে ওষুধ দিয়ে একাধিক ডিম্বাণু তৈরি করা হয়, ল্যাবে সেগুলো শুক্রাণুর সঙ্গে নিষিক্ত করা হয় এবং তৈরি হওয়া ভ্রূণ জরায়ুতে স্থানান্তর করা হয়। বন্ধ্যাত্ব চিকিৎসায় এটি সবচেয়ে বেশি ব্যবহৃত প্রযুক্তি।

একবারের চেষ্টায় সাফল্য না এলে হতাশ হওয়া স্বাভাবিক। তবে বারবার ব্যর্থ হলে কারণ খুঁজে বের করে পরিকল্পনা বদলানো জরুরি। প্রতিটি দম্পতির জন্য কারণ আলাদা হতে পারে।',
  symptoms_body = '## কখন সমস্যা সন্দেহ করা উচিত
বারবার IVF ব্যর্থতার নির্দিষ্ট কোনো লক্ষণ থাকে না। যেসব বিষয় পরীক্ষা করা প্রয়োজন:

- ভ্রূণ স্থানান্তরের পরেও গর্ভধারণ না হওয়া, বা গর্ভধারণের পর বারবার আগেভাগে নষ্ট হওয়া
- অনিয়মিত মাসিক বা ডিম্বস্ফোটনে সমস্যা
- আগের শ্রোণী সংক্রমণ, টিউবে ব্লক বা জরায়ুর সমস্যার ইতিহাস
- কোনো উপসর্গ না থাকলেও বন্ধ্যাত্ব থাকতে পারে, তাই পরীক্ষা জরুরি',
  causes_body = '## সম্ভাব্য কারণ
- ভ্রূণের গুণগত মান
- জরায়ুর ভেতরের আস্তরণের (এন্ডোমেট্রিয়াম) অবস্থা
- জরায়ু বা ফ্যালোপিয়ান টিউবের গঠনগত সমস্যা (যেমন ফাইব্রয়েড, পলিপ, টিউবে পানি জমা বা হাইড্রোসালপিংস)
- ইমিউন বা হরমোনজনিত সমস্যা
- পুরুষের শুক্রাণুর মান
- ওজন, ধূমপান ও অন্য জীবনধারার প্রভাব

কখনো কখনো নির্দিষ্ট কারণ পাওয়া যায় না।',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
উভয় সঙ্গীরই পরীক্ষা করা প্রয়োজন।

স্ত্রীর ক্ষেত্রে:

- রক্তে হরমোন পরীক্ষা, ডিম্বস্ফোটন ও ডিম্বাণুর সঞ্চয় (ovarian reserve) যাচাইয়ের জন্য
- থাইরয়েড পরীক্ষা, প্রয়োজন হলে
- এইচএসজি (HSG): জরায়ু ও টিউব পরীক্ষার এক্স-রে, যাতে ডাই ব্যবহার করা হয়
- শ্রোণীর আল্ট্রাসাউন্ড, প্রয়োজনে স্যালাইন ইনফিউশন সনোগ্রাম

পুরুষের ক্ষেত্রে:

- সিমেন অ্যানালাইসিস (শুক্রাণু পরীক্ষা), সাধারণত একাধিক নমুনায়
- টেস্টোস্টেরনসহ হরমোনের রক্ত পরীক্ষা
- প্রয়োজনে জেনেটিক পরীক্ষা বা স্ক্রোটাল আল্ট্রাসাউন্ড

## চিকিৎসা
কারণ অনুযায়ী পরিকল্পনা বদলানো হয়। সম্ভাব্য পদ্ধতির মধ্যে আছে:

- ডিম্বস্ফোটনের ওষুধ, IUI (ইন্ট্রাইউটেরাইন ইনসেমিনেশন)
- ICSI (শুক্রাণু সরাসরি ডিম্বাণুতে প্রবেশ করানো)
- ভ্রূণের জেনেটিক পরীক্ষা
- টিউব বা জরায়ুর সমস্যায় ল্যাপারোস্কোপি বা অন্য সার্জারি

IVF চলাকালে দ্রুত ওজন বৃদ্ধি বা শ্বাসকষ্ট হলে জরুরি চিকিৎসা নিন।

## খুলনায় বন্ধ্যাত্ব বিশেষজ্ঞ ডাক্তারের পরামর্শ
খুলনায় বারবার IVF ব্যর্থতার কারণ খুঁজতে স্বামী ও স্ত্রী দুজনের পূর্বের রিপোর্ট নিয়ে গাইনী ও প্রসূতি চেম্বার খুলনায় ডাঃ জেবুন্নেসা জেবুর সঙ্গে কথা বলুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## What is IVF, and what does repeated failure mean?
IVF, or in vitro fertilisation, is an assisted reproductive technology (ART). Medicines stimulate the ovaries to produce several eggs, the eggs are fertilised with sperm in the laboratory, and the resulting embryos are transferred into the womb. It is the most widely used treatment in infertility care.

It is natural to feel disappointed after one unsuccessful attempt. If IVF fails repeatedly, the causes need to be investigated and the plan adjusted. The cause can be different for every couple.',
  symptoms_body_en = '## When to suspect a problem
Repeated IVF failure does not have a specific symptom. These points suggest the need for investigation:

- No pregnancy after embryo transfer, or repeated early pregnancy loss
- Irregular periods or problems with ovulation
- A history of pelvic infection, tubal blockage or uterine problems
- Infertility can exist without symptoms, so testing is important',
  causes_body_en = '## Possible causes
- Embryo quality
- The condition of the lining of the womb (endometrium)
- Structural problems of the uterus or fallopian tubes, such as fibroids, polyps or fluid-filled tubes (hydrosalpinx)
- Immune or hormonal problems
- Sperm quality in the male partner
- Weight, smoking and other lifestyle effects

Sometimes no specific cause is found.',
  diagnosis_body_en = '## Diagnosis and Tests
Both partners should be assessed.

For the woman:

- Hormone blood tests to check ovulation and ovarian reserve (egg supply)
- Thyroid tests if needed
- Hysterosalpingography (HSG): an X-ray with dye that checks the uterus and fallopian tubes
- Pelvic ultrasound, and a saline infusion sonogram if needed

For the man:

- Semen analysis, usually on more than one sample
- Hormone blood tests, including testosterone
- Genetic testing or scrotal ultrasound when needed

## Treatment
The plan is adjusted according to the cause. Possible options include:

- Ovulation-stimulating medicines and intrauterine insemination (IUI)
- ICSI, where a single sperm is injected into an egg
- Genetic testing of embryos
- Laparoscopy or other surgery for tubal or uterine problems

Seek urgent care if you have rapid weight gain or shortness of breath during IVF treatment.

## Infertility specialist advice in Khulna
To find the cause of repeated IVF failure in Khulna, bring both partners'' previous reports to the gynaecology and maternity chamber in Khulna and speak with Dr. Jebunnesa Jebu. [Book an appointment](/appointment).'
WHERE slug = 'repeated-ivf-failure';

-- v63 part A: eight condition pages, dual language (draft for doctor review)
-- Sources (names only): WHO, NHS UK, Mayo Clinic, ACOG where fetched. No fees, no success-rate figures, no clinic-specific claims.

UPDATE conditions SET
  meta_title = 'অনিয়মিত মাসিক চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'অনিয়মিত মাসিকের কারণ, লক্ষণ, পরীক্ষা ও চিকিৎসা। কখন গাইনী বিশেষজ্ঞকে দেখাবেন, খুলনায় ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন।',
  meta_title_en = 'Irregular Periods Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Irregular periods in Khulna: causes, symptoms, tests and treatment, and when to see a gynaecologist. Consult Dr. Jebunnesa Jebu.',
  overview_body = '## অনিয়মিত মাসিক কী?
সাধারণত মাসিক চক্রের দৈর্ঘ্য ২১ থেকে ৩৫ দিনের মধ্যে থাকে। দুটি মাসিকের মধ্যে ব্যবধান এর বাইরে গেলে মাসিককে অনিয়মিত বলা হয়। অনেক ক্ষেত্রে এটি ডিম্বস্ফোটন ঠিকমতো না হওয়ার লক্ষণ।

কৈশোরে, গর্ভাবস্থায়, স্তন্যদানের সময় এবং মেনোপজের শুরুতে মাসিক অনিয়মিত হওয়া স্বাভাবিক। কিন্তু প্রজনন বয়সে বারবার অনিয়মিত মাসিক হলে তা পরীক্ষা করিয়ে দেখা উচিত।',
  symptoms_body = '## লক্ষণসমূহ
- মাসিকের ব্যবধান ২১ দিনের কম বা ৩৫ দিনের বেশি
- মাসিক ৭ দিনের বেশি স্থায়ী হওয়া
- দুটি মাসিকের মাঝে রক্তস্রাব
- অনিয়মিত মাসিকের সঙ্গে ওজন বৃদ্ধি, ক্লান্তি, মুখে লোম বা ত্বক শুষ্ক/তৈলাক্ত হওয়া
- কয়েক মাস মাসিক না হওয়া

## জরুরি সতর্কসংকেত
সাধারণত অনিয়মিত মাসিক জরুরি নয়, তবে নিচের লক্ষণ থাকলে দ্রুত চিকিৎসকের কাছে যান: মাসিকের সময় বা মাসিকের বাইরে তীব্র পেট ব্যথা, অতিরিক্ত রক্তস্রাবে মাথা ঘোরা বা দুর্বলতা, অথবা গর্ভধারণের সন্দেহ থাকলে।',
  causes_body = '## কারণ ও ঝুঁকি
- বয়ঃসন্ধি, গর্ভাবস্থা, অথবা মেনোপজের শুরু
- হরমোনজনিত গর্ভনিরোধক (যেমন শুধু প্রোজেস্টোজেন বড়ি, ইনজেকশন, ইন্ট্রাউটেরাইন সিস্টেম)
- ওজনে বড় পরিবর্তন (বেশি ওজন কমা বা বাড়া)
- মানসিক চাপ, উদ্বেগ, অতিরিক্ত ব্যায়াম
- পলিসিস্টিক ওভারি সিনড্রোম (PCOS) বা থাইরয়েড সমস্যা
- কখনো কখনো কোনো নির্দিষ্ট কারণ পাওয়া যায় না',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
প্রথমে মাসিকের তারিখ, উপসর্গ, ওষুধ ও গর্ভনিরোধকের ইতিহাস জানা হয়। এরপর প্রয়োজন অনুযায়ী:
- শারীরিক ও পেলভিক পরীক্ষা
- গর্ভধারণ পরীক্ষা
- থাইরয়েড ও হরমোন রক্ত পরীক্ষা (প্রোল্যাকটিনসহ)
- পেলভিক আল্ট্রাসাউন্ড
- ডিম্বস্ফোটন যাচাইয়ের পরীক্ষা (যেমন প্রোজেস্টেরন রক্ত পরীক্ষা)

## চিকিৎসা
চিকিৎসা নির্ভর করে কারণের ওপর। সবসময় ওষুধের প্রয়োজন হয় না।
- জীবনধারা: স্বাভাবিক ওজন বজায় রাখা, মানসিক চাপ কমানো, ভারসাম্যপূর্ণ খাবার
- থাইরয়েড বা হরমোনজনিত সমস্যায় উপযুক্ত ওষুধ
- PCOS-এ মাসিক নিয়মিত করতে হরমোন ওষুধ, প্রয়োজনে সম্মিলিত বড়ি
- গর্ভধারণের ইচ্ছা থাকলে ডিম্বস্ফোটনের ওষুধ বা অন্য চিকিৎসা

## খুলনায় অনিয়মিত মাসিকের পরামর্শ নিন
খুলনায় গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর কাছে মাসিকের প্রকৃত কারণ খুঁজে বের করুন। মাসিকের ডায়েরি বা ক্যালেন্ডার সঙ্গে আনুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK, Mayo Clinic, WHO।',
  overview_body_en = '## What are irregular periods?
A normal menstrual cycle usually lasts 21 to 35 days. When the gap between periods falls outside this range, periods are called irregular. Often it means ovulation is not happening regularly.

Irregular periods can be normal during puberty, pregnancy, breastfeeding and the start of menopause. But if they keep happening during the reproductive years, they should be checked.',
  symptoms_body_en = '## Symptoms
- Gap between periods under 21 days or over 35 days
- Periods lasting longer than 7 days
- Bleeding between periods
- Irregular periods with weight gain, tiredness, facial hair, or dry or oily skin
- No period for several months

## Urgent warning signs
Irregular periods are usually not an emergency. Seek prompt care if you have severe pelvic pain, dizziness or weakness from heavy bleeding, or if you may be pregnant.',
  causes_body_en = '## Causes and Risk Factors
- Puberty, pregnancy or the start of menopause
- Hormonal contraception, such as progestogen-only pills, injections or the hormonal intrauterine system
- Large weight loss or gain
- Stress, anxiety or excessive exercise
- Polycystic ovary syndrome (PCOS) or thyroid problems
- Sometimes no specific cause is found',
  diagnosis_body_en = '## Diagnosis and Tests
The doctor first asks about your period dates, symptoms, medicines and contraception. Then, as needed:
- Physical and pelvic examination
- Pregnancy test
- Blood tests for thyroid and hormones, including prolactin
- Pelvic ultrasound
- Tests to check ovulation, such as a progesterone blood test

## Treatment
Treatment depends on the cause, and medicines are not always needed.
- Lifestyle: a healthy weight, lower stress and a balanced diet
- Thyroid or other hormone problems: suitable medicines
- PCOS: hormone medicines to regulate periods, sometimes combined pills
- If you want to conceive: ovulation-stimulating medicines or other treatment

## Consult a Specialist in Khulna
For the real cause of irregular periods in Khulna, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. Bring a note of your period dates or a cycle calendar. [Book an appointment](/appointment).

Sources: NHS UK, Mayo Clinic, WHO.'
WHERE slug = 'irregular-periods';

UPDATE conditions SET
  meta_title = 'হরমোনজনিত গাইনী সমস্যা চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'হরমোনজনিত গাইনী সমস্যার লক্ষণ, কারণ ও রোগ নির্ণয়। PCOS ও থাইরয়েডসহ চিকিৎসার বিকল্প জানুন, খুলনায় বিশেষজ্ঞের পরামর্শ নিন।',
  meta_title_en = 'Hormone-related Gynaecology Problems in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Hormone-related gynaecological problems in Khulna: symptoms, causes, tests and treatment, including PCOS and thyroid. Dr. Jebunnesa Jebu.',
  overview_body = '## হরমোনজনিত গাইনী সমস্যা কী?
হরমোন শরীরের মাসিক চক্র, ডিম্বস্ফোটন, ওজন, ত্বক ও লোম নিয়ন্ত্রণ করে। এদের ভারসাম্য নষ্ট হলে মাসিক অনিয়মিত হতে পারে, গর্ভধারণে সমস্যা হতে পারে, এবং ত্বক ও লোমে পরিবর্তন দেখা দিতে পারে।

"হরমোন ইমব্যালেন্স" কোনো একক রোগের নাম নয়। এর মধ্যে সবচেয়ে পরিচিত হলো পলিসিস্টিক ওভারি সিনড্রোম (PCOS), যাতে স্বাভাবিকের চেয়ে বেশি পুরুষ হরমোন তৈরি হয়। থাইরয়েড ও প্রোল্যাকটিন হরমোনের সমস্যাও একই ধরনের উপসর্গ দিতে পারে।',
  symptoms_body = '## লক্ষণসমূহ
- অনিয়মিত, অনুপস্থিত, অতিরিক্ত বা ব্যথাযুক্ত মাসিক
- গর্ভধারণে অসুবিধা
- মুখে বা শরীরে অতিরিক্ত লোম
- চুল পাতলা হওয়া
- ব্রণ বা তৈলাক্ত ত্বক
- ওজন বৃদ্ধি বা ওজন কমানো সত্ত্বেও ওজন নিয়ন্ত্রণে না আসা
- ক্লান্তি ও মেজাজের পরিবর্তন',
  causes_body = '## কারণ ও ঝুঁকি
- PCOS: সঠিক কারণ জানা যায়নি, তবে পরিবারে PCOS বা টাইপ ২ ডায়াবেটিস থাকলে ঝুঁকি বেশি
- থাইরয়েড গ্রন্থির সমস্যা
- প্রোল্যাকটিন হরমোন বেড়ে যাওয়া, যা কিছু ওষুধের কারণেও হতে পারে
- ইনসুলিন রেজিস্ট্যান্স ও স্থূলতা
- মানসিক চাপ ও অতিরিক্ত ওজন ওঠানামা',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
PCOS নির্ণয়ে সাধারণত তিনটির মধ্যে অন্তত দুটি থাকা লাগে, অন্য কারণ বাদ দেওয়ার পর: হাইপারঅ্যান্ড্রোজেনিজমের লক্ষণ বা রক্তে বেশি টেস্টোস্টেরন, অনিয়মিত বা অনুপস্থিত মাসিক, এবং আল্ট্রাসাউন্ডে PCOS-ধাঁচের ডিম্বাশয়।

সাধারণ পরীক্ষা:
- হরমোনের রক্ত পরীক্ষা (টেস্টোস্টেরন, থাইরয়েড, প্রোল্যাকটিন)
- ইনসুলিন রেজিস্ট্যান্স ও রক্তে শর্করা পরীক্ষা
- কোলেস্টেরল পরীক্ষা
- পেলভিক আল্ট্রাসাউন্ড

## চিকিৎসা
- জীবনধারা পরিবর্তন: ওজন নিয়ন্ত্রণ, নিয়মিত ব্যায়াম, ভারসাম্যপূর্ণ খাবার
- মাসিক নিয়মিত করতে হরমোন ওষুধ, যেমন সম্মিলিত গর্ভনিরোধক বড়ি বা প্রোজেস্টিন থেরাপি
- লোম ও ব্রণের জন্য নির্দিষ্ট ওষুধ (চিকিৎসকের পরামর্শে)
- থাইরয়েড বা প্রোল্যাকটিনের সমস্যায় সেই অনুযায়ী ওষুধ
- গর্ভধারণের ইচ্ছা থাকলে ডিম্বস্ফোটনের ওষুধ, প্রয়োজনে IVF

## খুলনায় হরমোনের সমস্যার পরামর্শ নিন
খুলনায় হরমোনজনিত গাইনী সমস্যায় ডাঃ জেবুন্নেসা জেবুর চেম্বারে সঠিক রক্ত পরীক্ষা ও পরিকল্পিত চিকিৎসা নিন। গাইনী ও প্রসূতি চেম্বার খুলনায় সময় নিতে [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: WHO, Mayo Clinic, NHS UK।',
  overview_body_en = '## What are hormone-related gynaecological problems?
Hormones control the menstrual cycle, ovulation, weight, skin and hair growth. When they are out of balance, periods can become irregular, pregnancy can be harder, and skin or hair can change.

Hormone imbalance is not one single disease. The best known example is polycystic ovary syndrome (PCOS), in which the body makes more male hormones than usual. Thyroid and prolactin problems can cause similar symptoms.',
  symptoms_body_en = '## Symptoms
- Irregular, absent, heavy or painful periods
- Difficulty conceiving
- Excess facial or body hair
- Hair thinning
- Acne or oily skin
- Weight gain that does not respond to diet and exercise
- Tiredness and mood changes',
  causes_body_en = '## Causes and Risk Factors
- PCOS: the exact cause is not known, but a family history of PCOS or type 2 diabetes raises the risk
- Thyroid gland problems
- Raised prolactin, which can also be caused by some medicines
- Insulin resistance and obesity
- Long-term stress and weight fluctuations',
  diagnosis_body_en = '## Diagnosis and Tests
For PCOS, at least two of these three features are usually needed, after other causes are excluded: signs of high androgens or raised testosterone, irregular or absent periods, and polycystic-looking ovaries on ultrasound.

Common tests:
- Hormone blood tests (testosterone, thyroid, prolactin)
- Insulin resistance and blood sugar tests
- Cholesterol test
- Pelvic ultrasound

## Treatment
- Lifestyle: weight control, regular exercise and a balanced diet
- Hormone medicines to regulate periods, such as combined pills or progestin therapy
- Specific medicines for excess hair and acne, prescribed by a doctor
- Medicines for thyroid or prolactin problems
- If you want to conceive: ovulation-stimulating medicines, and IVF if needed

## Consult a Specialist in Khulna
For hormone-related gynaecology concerns in Khulna, visit the chamber of Dr. Jebunnesa Jebu for the right blood tests and a planned treatment. [Book an appointment](/appointment).

Sources: WHO, Mayo Clinic, NHS UK.'
WHERE slug = 'hormonal-imbalance';

UPDATE conditions SET
  meta_title = 'এন্ডোমেট্রিওসিস চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'এন্ডোমেট্রিওসিসের লক্ষণ, কারণ, রোগ নির্ণয় ও চিকিৎসা। তলপেটের ব্যথা ও বন্ধ্যাত্বের সম্ভাব্য সম্পর্ক জানুন, খুলনায় পরামর্শ নিন।',
  meta_title_en = 'Endometriosis Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Endometriosis in Khulna: symptoms, causes, diagnosis and treatment options, including pain relief and fertility care. Dr. Jebunnesa Jebu.',
  overview_body = '## এন্ডোমেট্রিওসিস কী?
এন্ডোমেট্রিওসিসে জরায়ুর ভেতরের আস্তরণের মতো টিস্যু জরায়ুর বাইরে বৃদ্ধি পায়। এতে প্রদাহ ও দাগ (স্ক্যার টিস্যু) তৈরি হতে পারে। এটি প্রথম মাসিক থেকে মেনোপজ পর্যন্ত যেকোনো বয়সের নারীর হতে পারে।

WHO অনুযায়ী এটি একটি জটিল রোগ, যা অনেক নারীর জীবনযাত্রা ও প্রজনন ক্ষমতায় প্রভাব ফেলে। সঠিক রোগ নির্ণয়ে সময় লাগতে পারে, তাই লক্ষণ থাকলে আগেভাগে কথা বলা গুরুত্বপূর্ণ।',
  symptoms_body = '## লক্ষণসমূহ
- মাসিকের সময় তীব্র ব্যথা
- মাসিকের পরেও থেকে যাওয়া দীর্ঘস্থায়ী শ্রোণী ব্যথা
- অতিরিক্ত মাসিক রক্তস্রাব বা দুই মাসিকের মাঝে রক্তস্রাব
- সহবাসের সময় বা পরে ব্যথা
- মলত্যাগ বা প্রস্রাবের সময় ব্যথা
- গর্ভধারণে অসুবিধা
- পেট ফাঁপা, বমি বমি ভাব, ক্লান্তি, ডায়রিয়া বা কোষ্ঠকাঠিন্য

কারো কারো কোনো লক্ষণই থাকে না।',
  causes_body = '## কারণ ও ঝুঁকি
এন্ডোমেট্রিওসিসের সঠিক কারণ এখনো জানা যায়নি, এবং এটি প্রতিরোধের নির্দিষ্ট উপায়ও নেই। সম্ভাব্য ব্যাখ্যাগুলোর মধ্যে আছে মাসিকের রক্ত উল্টো পথে শ্রোণীতে ফিরে যাওয়া এবং ইমিউন সিস্টেমের সমস্যা।

ঝুঁকি বাড়ায়:
- কখনো সন্তান জন্ম না দেওয়া
- অল্প বয়সে প্রথম মাসিক বা দেরিতে মেনোপজ
- ২৭ দিনের কম বা অনেক ছোট মাসিক চক্র, অথবা ৭ দিনের বেশি মাসিক
- পরিবারের কারো এন্ডোমেট্রিওসিস থাকা
- দীর্ঘদিন ধরে বেশি ইস্ট্রোজেনের সংস্পর্শ',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- মাসিক ও স্বাস্থ্যের বিস্তারিত ইতিহাস
- শারীরিক ও পেলভিক পরীক্ষা
- আল্ট্রাসাউন্ড বা MRI, যা রোগ নির্ণয়ে সহায়ক
- ল্যাপারোস্কোপি (ক্ষুদ্র ক্যামেরাসহ সার্জারি) দিয়ে রোগ নিশ্চিত করা যায়

রোগ নির্ণয়ে কখনো কখনো কয়েক বছর সময় লাগে, তাই লক্ষণ থাকলে নিয়মিত চিকিৎসকের সঙ্গে কথা বলুন।

## চিকিৎসা
এন্ডোমেট্রিওসিসের পূর্ণ নিরাময় নেই, তবে উপসর্গ নিয়ন্ত্রণ ও দীর্ঘমেয়াদি প্রভাব কমানো যায়:
- ব্যথানাশক ওষুধ (যেমন NSAID)
- হরমোন ওষুধ, যা মাসিক ও রোগের বৃদ্ধি নিয়ন্ত্রণ করে
- ল্যাপারোস্কোপিক সার্জারিতে এন্ডোমেট্রিওসিসের টিস্যু ও দাগ অপসারণ (পুনরায় হতে পারে)
- চিকিৎসা সত্ত্বেও ব্যথা থাকলে ফিজিওথেরাপি বা কগনিটিভ বিহেভিয়ারাল থেরাপি
- গর্ভধারণের ইচ্ছা থাকলে ডিম্বস্ফোটন চিকিৎসা, IUI বা IVF

হিস্টেরেক্টমি (জরায়ু অপসারণ) কিছু ক্ষেত্রে বিবেচনা করা হয়, তবে এটি রোগের পূর্ণ নিরাময় নয়।

## খুলনায় এন্ডোমেট্রিওসিসের পরামর্শ নিন
মাসিকের সময় অসহনীয় ব্যথা বা গর্ভধারণে সমস্যা থাকলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: WHO, Mayo Clinic।',
  overview_body_en = '## What is endometriosis?
In endometriosis, tissue similar to the lining of the womb grows outside the womb. It can cause inflammation and scar tissue. It can affect women from their first period until menopause.

According to WHO it is a complex disease that affects many women''s daily life and fertility. Diagnosis can take time, so it is important to talk to a doctor early if you have symptoms.',
  symptoms_body_en = '## Symptoms
- Severe pain during periods
- Chronic pelvic pain that continues after the period ends
- Heavy periods, or bleeding between periods
- Pain during or after sex
- Pain when passing urine or opening the bowels
- Difficulty conceiving
- Bloating, nausea, tiredness, diarrhoea or constipation

Some women have no symptoms at all.',
  causes_body_en = '## Causes and Risk Factors
The exact cause is not known, and there is no proven way to prevent it. Possible explanations include menstrual blood flowing back into the pelvis and immune system problems.

Risk factors include:
- Never having given birth
- Starting periods early or having menopause late
- Cycles shorter than 27 days, or periods longer than 7 days
- A close relative with endometriosis
- Long exposure to higher lifetime oestrogen',
  diagnosis_body_en = '## Diagnosis and Tests
- Detailed menstrual and medical history
- Physical and pelvic examination
- Ultrasound or MRI, which can support the diagnosis
- Laparoscopy (keyhole surgery with a camera) confirms the diagnosis

Diagnosis can take several years, so keep talking to your doctor if symptoms continue.

## Treatment
There is no cure, but symptoms can be controlled and long-term effects reduced:
- Pain relief, such as NSAIDs
- Hormone medicines that control periods and disease activity
- Keyhole surgery to remove lesions and scar tissue (lesions can come back)
- Physiotherapy or cognitive behavioural therapy if pain continues
- If you want to conceive: ovulation induction, IUI or IVF

Hysterectomy may be considered in some cases, but it is not a cure.

## Consult a Specialist in Khulna
If periods are very painful or pregnancy is difficult, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Sources: WHO, Mayo Clinic.'
WHERE slug = 'endometriosis';

UPDATE conditions SET
  meta_title = 'বারবার গর্ভপাত চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'বারবার গর্ভপাতের কারণ, পরীক্ষা ও চিকিৎসার বিকল্প। দুবার বা তার বেশি গর্ভপাত হলে কী করবেন, খুলনায় বিশেষজ্ঞের পরামর্শ নিন।',
  meta_title_en = 'Recurrent Miscarriage Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Recurrent miscarriage in Khulna: causes, tests and treatment options after two or more pregnancy losses. Consult Dr. Jebunnesa Jebu.',
  overview_body = '## বারবার গর্ভপাত কী?
দুই বা তার বেশি গর্ভপাতকে বারবার গর্ভপাত (রিকারেন্ট প্রেগন্যান্সি লস) বলা হয়। ACOG-এর তথ্য অনুযায়ী দুটি গর্ভপাত পরপর হওয়ার ঘটনা তুলনামূলকভাবে কম, আর দুবার গর্ভপাত হলেই পরীক্ষা করানো উপযুক্ত।

দুবার গর্ভপাত হলে আতঙ্কিত হওয়ার আগে জানুন: বেশির ভাগ গর্ভপাতের কারণ শিশুর ক্রোমোজোমের এলোমেলো পরিবর্তন, যা ভবিষ্যতে সুস্থ গর্ভধারণের বাধা নয়। তবু কারণ খুঁজে দেখা গুরুত্বপূর্ণ।',
  symptoms_body = '## লক্ষণ ও সতর্কসংকেত
বারবার গর্ভপাতের নির্দিষ্ট কোনো লক্ষণ নেই। গর্ভপাতের সময় সাধারণত যেসব লক্ষণ দেখা যায়:
- যোনি দিয়ে রক্তপাত
- পেট বা কোমরে ব্যথা ও মাসিকের ব্যথার মতো টান

## জরুরি সতর্কসংকেত
নিচের লক্ষণ থাকলে এখনই চিকিৎসকের কাছে যান:
- প্রচুর রক্তপাত (ঘণ্টায় দুইটির বেশি প্যাড দুই ঘণ্টার বেশি ভিজে যাওয়া)
- জ্বর, কাঁপুনি বা তলপেটে তীব্র ব্যথা (সংক্রমণের লক্ষণ)',
  causes_body = '## কারণ ও ঝুঁকি
- অর্ধেকের মতো গর্ভপাত শিশুর ক্রোমোজোমের এলোমেলো পরিবর্তনের কারণে হয়। বয়স বাড়লে এর ঝুঁকি বাড়ে।
- কিছু দম্পতির ক্ষেত্রে একজনের ক্রোমোজোম ট্রান্সলোকেশন থাকতে পারে
- জরায়ুর গঠনগত সমস্যা (সেপ্টেট জরায়ু, জরায়ুর ভেতরে দাগ, ফাইব্রয়েড বা পলিপ)
- অ্যান্টিফসফোলিপিড সিনড্রোমের মতো ইমিউন সমস্যা
- নিয়ন্ত্রণহীন ডায়াবেটিস বা চিকিৎসা না করা থাইরয়েড রোগ
- অর্ধেকের বেশি ক্ষেত্রে কারণ খুঁজে পাওয়া যায় না',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- পূর্ণ চিকিৎসা ও গর্ভধারণের ইতিহাস, শারীরিক ও পেলভিক পরীক্ষা
- স্বামী ও স্ত্রী উভয়ের ক্রোমোজোম (জেনেটিক) পরীক্ষা
- ইমিউন সমস্যার রক্ত পরীক্ষা
- জরায়ুর গঠন দেখার জন্য আল্ট্রাসাউন্ড বা অন্য ইমেজিং
- গর্ভাবস্থায় hCG হরমোনের মাত্রা পর্যবেক্ষণ

## চিকিৎসা
- নির্দিষ্ট কারণ পাওয়া গেলে সেটির চিকিৎসা
- জরায়ুর গঠনগত সমস্যায় (যেমন সেপ্টাম) অস্ত্রোপচার, যা পরবর্তী গর্ভধারণের সম্ভাবনা বাড়াতে পারে
- ক্রোমোজোম ট্রান্সলোকেশনে জেনেটিক কাউন্সেলিং, প্রয়োজনে IVF-এর সঙ্গে ভ্রূণের জেনেটিক পরীক্ষা
- অ্যান্টিফসফোলিপিড সিনড্রোমে গর্ভাবস্থায় হেপারিন, কখনো কম মাত্রার অ্যাসপিরিনসহ
- কারণ অজানা থাকলেও অনেক দম্পতির পরবর্তী গর্ভধারণ সফল হয়, এবং নিয়মিত পর্যবেক্ষণে সহায়তা পাওয়া যায়

## খুলনায় বারবার গর্ভপাতের পরামর্শ নিন
দুই বা তার বেশি গর্ভপাতের ইতিহাস থাকলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে আগের সব রিপোর্ট নিয়ে কথা বলুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: ACOG, Mayo Clinic।',
  overview_body_en = '## What is recurrent miscarriage?
Two or more miscarriages are called recurrent pregnancy loss. According to ACOG, two miscarriages in a row is less common, and testing is advised after two or more losses.

Most miscarriages are caused by random chromosome changes in the baby. These changes usually do not stop a healthy future pregnancy, but finding the cause is still important.',
  symptoms_body_en = '## Symptoms and Warning Signs
There are no symptoms specific to repeated miscarriage. Miscarriage itself usually causes:
- Vaginal bleeding
- Back pain or abdominal cramping

## Urgent warning signs
Seek immediate care if you have:
- Heavy bleeding, soaking more than two pads an hour for more than two hours
- Fever, chills or severe lower abdominal pain, which can signal infection',
  causes_body_en = '## Causes and Risk Factors
- About half of miscarriages are caused by random chromosome abnormalities in the baby. The chance rises with age.
- In a small number of couples, one partner has a chromosome translocation
- Problems with the shape of the womb, such as a septate uterus, scarring inside the womb, fibroids or polyps
- Immune problems such as antiphospholipid syndrome
- Poorly controlled diabetes or untreated thyroid disease
- In more than half of cases, no cause is found',
  diagnosis_body_en = '## Diagnosis and Tests
- Full medical and pregnancy history, physical and pelvic examination
- Chromosome (genetic) tests for both partners
- Blood tests for immune problems
- Ultrasound or other imaging to examine the womb
- Monitoring hCG hormone levels in pregnancy

## Treatment
- If a specific cause is found, it is treated
- Surgery for structural problems, such as a uterine septum, can improve the chance of a successful pregnancy
- For chromosome translocation: genetic counselling, and IVF with embryo genetic testing when suitable
- For antiphospholipid syndrome: heparin during pregnancy, sometimes with low-dose aspirin
- Many couples with no identified cause go on to have a successful pregnancy, and careful monitoring helps

## Consult a Specialist in Khulna
If you have had two or more miscarriages, bring all previous reports to Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Sources: ACOG, Mayo Clinic.'
WHERE slug = 'recurrent-miscarriage';

UPDATE conditions SET
  meta_title = 'টিউব ব্লক চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'ফ্যালোপিয়ান টিউব ব্লক ও টিউবে পানি জমার (হাইড্রোসালপিংস) কারণ, পরীক্ষা ও চিকিৎসা। বন্ধ্যাত্বে এর ভূমিকা জানুন, খুলনায় পরামর্শ নিন।',
  meta_title_en = 'Tubal Blockage Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Tubal blockage and hydrosalpinx in Khulna: causes, tests and treatment options for infertility. Consult Dr. Jebunnesa Jebu.',
  overview_body = '## টিউব ব্লক ও হাইড্রোসালপিংস কী?
ফ্যালোপিয়ান টিউব ডিম্বাণুকে ডিম্বাশয় থেকে জরায়ুতে নিয়ে যায়। এই টিউব ক্ষতিগ্রস্ত বা বন্ধ হলে শুক্রাণু ডিম্বাণুতে পৌঁছাতে পারে না, অথবা নিষিক্ত ডিম্বাণু জরায়ুতে পৌঁছাতে পারে না।

হাইড্রোসালপিংস হলো টিউবের ভেতরে তরল জমে ফুলে যাওয়া অবস্থা। এটিও টিউবের বাধা ও বন্ধ্যাত্বের একটি কারণ হতে পারে। IVF-এর আগে এই টিউবের অবস্থা জানা গুরুত্বপূর্ণ।',
  symptoms_body = '## লক্ষণ
টিউবের সমস্যায় প্রায়ই কোনো নির্দিষ্ট লক্ষণ থাকে না। সবচেয়ে সাধারণ লক্ষণ হলো গর্ভধারণ না হওয়া। টিউবে সংক্রমণ বা দাগের কারণে কখনো কখনো শ্রোণীতে ব্যথা হতে পারে।

## জরুরি সতর্কসংকেত
ফার্টিলিটি চিকিৎসার সময় হরমোন ইনজেকশনের কারণে অতিরিক্ত ডিম্বাশয় উদ্দীপনা (ওভারিয়ান হাইপারস্টিমুলেশন) হলে দ্রুত ওজন বৃদ্ধি, ব্যথাযুক্ত বড় ডিম্বাশয়, পেটে তরল বা শ্বাসকষ্ট দেখা দিলে জরুরি চিকিৎসা নিন।',
  causes_body = '## কারণ ও ঝুঁকি
- শ্রোণী সংক্রমণ (পিআইডি), সাধারণত যৌনবাহিত সংক্রমণ থেকে
- আগে পেটের বা শ্রোণীর অস্ত্রোপচার, টিউবের গর্ভাবস্থা (এক্টোপিক প্রেগন্যান্সি) বা অস্ত্রোপচারের ইতিহাস
- ফাইব্রয়েড বা সৌম্য টিউমার, যা টিউব বন্ধ করতে পারে
- এন্ডোমেট্রিওসিসের দাগ',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- এইচএসজি (HSG): জরায়ুতে এক্স-রে ডাই দিয়ে দেখা হয় তরল টিউব দিয়ে বের হয় কি না
- ল্যাপারোস্কোপি: ক্ষুদ্র ক্যামেরা দিয়ে টিউব, ডিম্বাশয় ও জরায়ু দেখা হয় এবং দাগ বা বাধা শনাক্ত করা হয়
- শ্রোণীর আল্ট্রাসাউন্ড
- সনোহিস্টেরোগ্রাম বা হিস্টেরোস্কোপি: জরায়ুর ভেতরের অবস্থা দেখা

## চিকিৎসা
- ল্যাপারোস্কোপিক টিউবাল সার্জারি: দাগ সরানো, টিউব প্রশস্ত করা বা নতুন মুখ তৈরি করা। তবে এটি সব ক্ষেত্রে উপযুক্ত নয়।
- কখনো কখনো জরায়ুর কাছের টিউবের অংশ বন্ধ বা অপসারণ করলে IVF-এর ফল ভালো হতে পারে
- টিউবের সমস্যা গুরুতর হলে IVF সাধারণত সবচেয়ে কার্যকর পদ্ধতি হিসেবে বিবেচিত হয়

## খুলনায় টিউব সমস্যার পরামর্শ নিন
গর্ভধারণের চেষ্টা করেও সফল না হলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। পূর্বের HSG বা আল্ট্রাসাউন্ড রিপোর্ট সঙ্গে আনুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: Mayo Clinic।',
  overview_body_en = '## What are tubal blockage and hydrosalpinx?
The fallopian tubes carry the egg from the ovary to the womb. If a tube is damaged or blocked, sperm cannot reach the egg, or a fertilised egg cannot reach the womb.

Hydrosalpinx means a tube is swollen and filled with fluid. It can also block the tube and affect fertility. Knowing the condition of the tubes is important before IVF.',
  symptoms_body_en = '## Symptoms
Tubal problems often have no specific symptoms. The most common sign is not being able to get pregnant. Scarring or infection can sometimes cause pelvic pain.

## Urgent warning signs
During fertility treatment with hormone injections, seek urgent care for rapid weight gain, enlarged painful ovaries, fluid in the abdomen or shortness of breath, which can signal ovarian hyperstimulation.',
  causes_body_en = '## Causes and Risk Factors
- Pelvic inflammatory disease, usually from sexually transmitted infections
- Previous abdominal or pelvic surgery, or an ectopic pregnancy
- Fibroids or benign growths that can block the tube
- Endometriosis scarring',
  diagnosis_body_en = '## Diagnosis and Tests
- Hysterosalpingography (HSG): X-ray with dye placed in the womb shows whether fluid passes out of the tubes
- Laparoscopy: a small camera examines the tubes, ovaries and womb to find scarring or blockages
- Pelvic ultrasound
- Sonohysterogram or hysteroscopy: shows the inside of the womb

## Treatment
- Laparoscopic tubal surgery to remove adhesions, widen a tube or create a new opening. It is not suitable in every case.
- Removing or blocking a damaged tube near the womb can improve IVF results
- For significant tubal damage, IVF is usually the most effective option

## Consult a Specialist in Khulna
If you have not conceived despite trying, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. Bring your previous HSG or ultrasound reports. [Book an appointment](/appointment).

Source: Mayo Clinic.'
WHERE slug = 'tubal-blockage-hydrosalpinx';

UPDATE conditions SET
  meta_title = 'জরায়ুর সংক্রমণ চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'জরায়ু ও শ্রোণীর সংক্রমণ (পিআইডি)-এর লক্ষণ, কারণ, পরীক্ষা ও চিকিৎসা। দেরি না করে কখন জরুরি সেবা নেবেন, খুলনায় জানুন।',
  meta_title_en = 'Uterine Infection Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Uterine and pelvic infection (PID) in Khulna: symptoms, causes, tests, treatment and urgent warning signs. Dr. Jebunnesa Jebu.',
  overview_body = '## জরায়ুর সংক্রমণ কী?
জরায়ুর সংক্রমণ সাধারণত শ্রোণী সংক্রমণ বা পেলভিক ইনফ্ল্যামেটরি ডিজিজ (পিআইডি) হিসেবে দেখা দেয়। এটি জরায়ু, ফ্যালোপিয়ান টিউব ও ডিম্বাশয়ে সংক্রমণ, যা যেকোনো জরায়ুওয়ালা ব্যক্তির হতে পারে।

সময়মতো চিকিৎসা করলে সাধারণত সহজেই সারে। চিকিৎসা দেরিতে হলে টিউবে দাগ, দীর্ঘস্থায়ী শ্রোণী ব্যথা ও বন্ধ্যাত্বের ঝুঁকি বাড়ে।',
  symptoms_body = '## লক্ষণসমূহ
কিছু মানুষের কোনো লক্ষণ থাকে না। লক্ষণ থাকলে সাধারণত:
- তলপেটের নিচের দিকে ব্যথা
- সহবাসের সময় গভীর ব্যথা
- অস্বাভাবিক ভারী বা ব্যথাযুক্ত মাসিক, বা মাসিকের মাঝে বা সহবাসের পরে রক্তস্রাব
- অস্বাভাবিক বা দুর্গন্ধযুক্ত স্রাব
- জ্বর, কাঁপুনি, প্রস্রাবের সময় ব্যথা বা ঘন ঘন প্রস্রাব

## জরুরি সতর্কসংকেত
নিচের লক্ষণ দেখা দিলে ৯৯৯ নম্বরে কল করুন বা দ্রুত হাসপাতালে যান:
- তীব্র বা ক্রমবর্ধমান শ্রোণী ব্যথা
- ব্যথার সঙ্গে ভারী রক্তস্রাব
- হঠাৎ তীব্র তলপেটের ব্যথা
- ডান পাঁজরের নিচে বা ডান কাঁধে ব্যথা
- মাথা ঘোরা বা জ্ঞান হারানোর মতো অনুভূতি
- খুব বেশি জ্বর বা বমি
- ৩ দিন অ্যান্টিবায়োটিক খাওয়ার পরেও উপসর্গ না কমলে',
  causes_body = '## কারণ ও ঝুঁকি
- সাধারণত যৌনবাহিত সংক্রমণ, যেমন ক্লামিডিয়া ও গনোরিয়া
- যোনিতে স্বাভাবিকভাবে থাকা ব্যাকটেরিয়ার অতিবৃদ্ধি, যেমন ব্যাকটেরিয়াল ভ্যাজিনোসিস
- বিরল ক্ষেত্রে আইইউডি বসানো বা জরায়ুতে অন্য প্রক্রিয়ার সময় জীবাণু প্রবেশ

ঝুঁকি বাড়ায়: আগে পিআইডি বা যৌনবাহিত সংক্রমণ, সন্তান জন্ম, গর্ভপাত, কপার-টি বা হিস্টেরোস্কোপি, একাধিক সঙ্গী, কনডম ব্যবহারে অনিয়ম, ২৫ বছরের কম বয়সে সক্রিয় যৌনজীবন।',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- চিকিৎসকের ইতিহাস, তলপেট ও শ্রোণীর পরীক্ষা, যোনি স্রাবের নমুনা
- রক্ত পরীক্ষা, গর্ভধারণ পরীক্ষা
- আল্ট্রাসাউন্ড স্ক্যান
- প্রয়োজনে যৌন স্বাস্থ্য ক্লিনিকে রেফারেল

## চিকিৎসা
- সাধারণত প্রথমে ইনজেকশন, তারপর প্রায় দুই সপ্তাহ ধরে অ্যান্টিবায়োটিক ট্যাবলেট
- ব্যথার জন্য প্যারাসিটামল বা আইবুপ্রোফেন
- যৌনবাহিত সংক্রমণ থাকলে সঙ্গীরও চিকিৎসা প্রয়োজন
- চিকিৎসা শেষ না হওয়া ও পরীক্ষায় সংক্রমণ মুক্ত প্রমাণিত না হওয়া পর্যন্ত সহবাস থেকে বিরত থাকুন
- গুরুতর বা চিকিৎসা না হওয়া সংক্রমণে হাসপাতালে ভর্তি লাগতে পারে। জটিলতার মধ্যে আছে টিউবে গর্ভধারণ, বন্ধ্যাত্ব, দীর্ঘস্থায়ী ব্যথা ও টিউবো-ওভারিয়ান অ্যাবসেস।

## খুলনায় সংক্রমণের পরামর্শ নিন
খুলনায় তলপেটের ব্যথা বা অস্বাভাবিক স্রাব থাকলে দেরি না করে গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। জরুরি লক্ষণ থাকলে সরাসরি জরুরি সেবায় যান। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK, Mayo Clinic।',
  overview_body_en = '## What is a uterine infection?
A uterine infection is usually pelvic inflammatory disease (PID). It is an infection of the womb, fallopian tubes and ovaries, and can affect anyone with a womb.

If treated early, it usually clears up easily. If treatment is delayed, it can cause scarring of the tubes, long-term pelvic pain and infertility.',
  symptoms_body_en = '## Symptoms
Some people have no symptoms. When symptoms do occur, they can include:
- Lower tummy or pelvic pain
- Deep pain during sex
- Unusually heavy or painful periods, or bleeding between periods or after sex
- Unusual or smelly vaginal discharge
- Fever, chills, pain when passing urine or needing to pass urine often

## Urgent warning signs
Call 999 or go to A&E if you have:
- Severe or worsening pelvic pain
- Pelvic pain with heavy vaginal bleeding
- Sudden sharp lower tummy pain
- Pain under the right ribs or in the right shoulder
- Dizziness or feeling faint
- Very high temperature or repeated vomiting
- No improvement after 3 days of antibiotics',
  causes_body_en = '## Causes and Risk Factors
- Usually sexually transmitted infections such as chlamydia and gonorrhoea
- Overgrowth of bacteria normally present in the vagina, such as bacterial vaginosis
- Rarely, germs entering during coil insertion or other uterine procedures

Risk factors include a previous PID or sexually transmitted infection, childbirth, miscarriage, abortion, having a coil or hysteroscopy, multiple partners, inconsistent condom use, and being sexually active under 25.',
  diagnosis_body_en = '## Diagnosis and Tests
- History, examination of the abdomen and pelvis, and a vaginal discharge sample
- Blood test and pregnancy test
- Ultrasound scan
- Referral to a sexual health clinic when needed

## Treatment
- Usually an antibiotic injection first, then antibiotic tablets for about 2 weeks
- Paracetamol or ibuprofen for pain
- If a sexually transmitted infection is involved, the partner also needs treatment
- Avoid sex until the course is finished, symptoms have gone and tests show clearance
- Severe or untreated infection may need hospital care. Complications include ectopic pregnancy, infertility, chronic pelvic pain and tubo-ovarian abscess.

## Consult a Specialist in Khulna
If you have lower tummy pain or unusual discharge in Khulna, do not delay. Consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. If you have urgent warning signs, go straight to emergency care. [Book an appointment](/appointment).

Sources: NHS UK, Mayo Clinic.'
WHERE slug = 'uterine-infection';

UPDATE conditions SET
  meta_title = 'সার্ভিক্স সমস্যা চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'জরায়ুমুখের (সার্ভিক্স) প্রদাহ ও সংক্রমণের লক্ষণ, কারণ, পরীক্ষা ও চিকিৎসা। সহবাসের পর রক্তস্রাব হলে কী করবেন, খুলনায় জানুন।',
  meta_title_en = 'Cervix Problems Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Cervix problems and cervicitis in Khulna: symptoms, causes, tests and treatment. Bleeding after sex needs checking. Dr. Jebunnesa Jebu.',
  overview_body = '## সার্ভিক্স সমস্যা ও সার্ভিসাইটিস কী?
সার্ভিক্স বা জরায়ুমুখ হলো জরায়ুর নিচের সরু অংশ, যা যোনির সঙ্গে যুক্ত। এখানে প্রদাহ বা সংক্রমণ হলে তাকে সার্ভিসাইটিস বলা হয়। এটি সাধারণত যৌনবাহিত সংক্রমণ, কোনো পণ্যের প্রতিক্রিয়া, অথবা যোনির স্বাভাবিক ব্যাকটেরিয়ার অতিবৃদ্ধির কারণে হয়।

বেশির ভাগ ক্ষেত্রে কোনো লক্ষণ থাকে না এবং নিয়মিত পেলভিক পরীক্ষার সময় ধরা পড়ে। তাই নিয়মিত চেকআপ গুরুত্বপূর্ণ।',
  symptoms_body = '## লক্ষণসমূহ
- অনেক ক্ষেত্রে কোনো লক্ষণই থাকে না
- অস্বাভাবিক যোনি স্রাব, কখনো পুঁজের মতো
- প্রস্রাবের সময় ব্যথা বা সহবাসের সময় ব্যথা
- মাসিকের বাইরে বা সহবাসের পর রক্তস্রাব

## কখন দ্রুত দেখাবেন
স্বাভাবিক নয় এমন স্রাব, মাসিক না হওয়া রক্তস্রাব, বা সহবাসের সময় ব্যথা থাকলে চিকিৎসকের কাছে যান। ভারী রক্তস্রাব বা দুর্গন্ধযুক্ত স্রাব থাকলেও দেরি করবেন না।',
  causes_body = '## কারণ ও ঝুঁকি
- যৌনবাহিত সংক্রমণ: গনোরিয়া, ক্লামিডিয়া, ট্রাইকোমোনিয়াসিস, জেনিটাল হারপিস
- স্পার্মিসাইড, ল্যাটেক্স কনডম, ডুশ বা ডিওডোরেন্টের প্রতি প্রতিক্রিয়া
- ব্যাকটেরিয়াল ভ্যাজিনোসিস
- ঝুঁকি বাড়ায়: অরক্ষিত বা ঝুঁকিপূর্ণ যৌন সম্পর্ক, একাধিক সঙ্গী, অল্প বয়সে যৌন সম্পর্ক, আগে যৌনবাহিত সংক্রমণ
- জন্মগতভাবে বা আগের ক্ষতির কারণে সার্ভিক্স সরু হওয়া',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- স্পেকুলাম দিয়ে পেলভিক পরীক্ষা, ফোলা বা কোমলতা দেখা
- সার্ভিক্স ও যোনির স্রাব সংগ্রহ করে ল্যাবে সংক্রমণ পরীক্ষা
- প্রয়োজনে প্রস্রাব পরীক্ষা

## চিকিৎসা
- যৌনবাহিত সংক্রমণে রোগী ও সঙ্গী উভয়ের চিকিৎসা, সাধারণত অ্যান্টিবায়োটিক দিয়ে। প্রয়োজনে পুনরায় পরীক্ষা।
- জেনিটাল হারপিসে অ্যান্টিভাইরাল ওষুধ উপসর্গ কমায়, তবে সম্পূর্ণ নিরাময় নেই
- কোনো পণ্যের প্রতিক্রিয়া হলে সেটি ব্যবহার বন্ধ করাই যথেষ্ট
- চিকিৎসা শেষ না হওয়া পর্যন্ত সহবাস, ট্যাম্পন বা ডুশ এড়িয়ে চলুন, প্যাড ব্যবহার করুন

চিকিৎসা না হলে গনোরিয়া বা ক্লামিডিয়া জরায়ু ও টিউবে ছড়িয়ে পিআইডি ঘটাতে পারে।

## খুলনায় সার্ভিক্সের পরীক্ষা করান
খুলনায় অস্বাভাবিক স্রাব বা সহবাসের পর রক্তস্রাব হলে গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর কাছে পরীক্ষা করান। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: Mayo Clinic।',
  overview_body_en = '## What are cervix problems and cervicitis?
The cervix is the lower, narrow part of the womb that opens into the vagina. Inflammation or infection of the cervix is called cervicitis. It is usually caused by sexually transmitted infections, a reaction to a product, or overgrowth of normal vaginal bacteria.

Most of the time there are no symptoms, and it is found during a routine pelvic examination. Regular check-ups matter.',
  symptoms_body_en = '## Symptoms
- Often no symptoms at all
- Unusual vaginal discharge, sometimes pus-like
- Pain when passing urine or pain during sex
- Bleeding outside periods or after sex

## When to see a doctor soon
See a doctor for unusual discharge, bleeding that is not a period, or pain during sex. Do not delay if you have heavy bleeding or smelly discharge.',
  causes_body_en = '## Causes and Risk Factors
- Sexually transmitted infections such as gonorrhoea, chlamydia, trichomoniasis and genital herpes
- Reactions to spermicide, latex condoms, douches or deodorants
- Bacterial vaginosis
- Risk factors: unprotected or high-risk sex, multiple partners, early sexual activity, and a previous sexually transmitted infection
- Narrowing of the cervix, which may be inherited or follow earlier damage',
  diagnosis_body_en = '## Diagnosis and Tests
- Pelvic examination with a speculum to look for swelling and tenderness
- A swab of cervical and vaginal fluid sent to a lab for infection testing
- Urine sample if needed

## Treatment
- For sexually transmitted infections: treatment of both the patient and partner, usually with antibiotics, and repeat testing when advised
- Genital herpes: antiviral medicine can shorten symptoms, but there is no cure
- For a product reaction: stopping the product is usually enough
- Until treatment is complete, avoid sex, tampons and douching, and use pads instead

Untreated gonorrhoea or chlamydia can spread to the womb and tubes and cause PID.

## Get your cervix checked in Khulna
If you have unusual discharge or bleeding after sex in Khulna, get checked at the gynaecology and maternity chamber with Dr. Jebunnesa Jebu. [Book an appointment](/appointment).

Source: Mayo Clinic.'
WHERE slug = 'cervix-problems';

UPDATE conditions SET
  meta_title = 'ডিম্বস্ফোটন সমস্যা চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'ডিম্বস্ফোটন সমস্যার লক্ষণ, কারণ, পরীক্ষা ও ফার্টিলিটি চিকিৎসা। ওভুলেশন ঠিক না হলে গর্ভধারণে কী করণীয়, খুলনায় জানুন।',
  meta_title_en = 'Ovulation Disorders Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Ovulation disorders in Khulna: symptoms, causes, tests and fertility treatment options. Consult Dr. Jebunnesa Jebu for infertility care.',
  overview_body = '## ডিম্বস্ফোটন সমস্যা কী?
ডিম্বস্ফোটন হলো ডিম্বাশয় থেকে পরিপক্ব ডিম্বাণু বের হওয়া। নিয়মিত ডিম্বস্ফোটন না হলে বা না হলে, গর্ভধারণ কঠিন হয়। এটি নারীর বন্ধ্যাত্বের বড় একটি কারণ।

মাসিক চক্র ২১ দিনের কম বা ৩৫ দিনের বেশি হলে ডিম্বস্ফোটন নাও হতে পারে। PCOS সবচেয়ে সাধারণ কারণ হিসেবে পরিচিত।',
  symptoms_body = '## লক্ষণসমূহ
- অনিয়মিত বা অনুপস্থিত মাসিক, সবচেয়ে সাধারণ লক্ষণ
- গর্ভধারণে সমস্যা, যা প্রধান উপসর্গ হিসেবে দেখা যায়
- অনেক সময় অন্য কোনো লক্ষণ থাকে না
- PCOS-এর সঙ্গে থাকলে মুখে লোম, ব্রণ বা তৈলাক্ত ত্বক',
  causes_body = '## কারণ ও ঝুঁকি
- হাইপোথ্যালামাস, পিটুইটারি বা ডিম্বাশয়ের হরমোনজনিত সমস্যা
- অতিরিক্ত শারীরিক বা মানসিক চাপ
- খুব বেশি বা খুব কম ওজন, হঠাৎ ওজনের বড় পরিবর্তন
- প্রোল্যাকটিন বেড়ে যাওয়া, কখনো কোনো ওষুধের কারণে
- থাইরয়েড সমস্যা
- প্রাথমিক ডিম্বাশয়ের অপর্যাপ্ততা (ডিম্বাণু তৈরি বন্ধ হয়ে যাওয়া)',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- ওভুলেশন কিট (এলএইচ) দিয়ে ডিম্বস্ফোটনের আগের হরমোন বৃদ্ধি দেখা
- প্রোজেস্টেরন রক্ত পরীক্ষা, যা ডিম্বস্ফোটন হয়েছে কি না নিশ্চিত করে
- ডিম্বস্ফোটন, থাইরয়েড, পিটুইটারি ও প্রোল্যাকটিন হরমোনের রক্ত পরীক্ষা
- ডিম্বাশয়ের সঞ্চয় (অভারিয়ান রিজার্ভ) যাচাইয়ের পরীক্ষা, বিশেষ করে ৩৫ বছরের বেশি বয়সে
- পেলভিক আল্ট্রাসাউন্ড
- PCOS নির্ণয়ের মানদণ্ড অনুযায়ী মূল্যায়ন

## চিকিৎসা
- ক্লোমিফিন সাইট্রেট: সাধারণত ৩৯ বছরের কম বয়সী, PCOS ছাড়া নারীদের প্রথম ধাপের ওষুধ
- লেট্রোজল: সাধারণত ৩৯ বছরের কম বয়সী PCOS-আক্রান্তদের জন্য
- মেটফরমিন: ইনসুলিন রেজিস্ট্যান্স থাকলে, প্রায়ই PCOS-এ
- গোনাডোট্রপিন ইনজেকশন: ডিম্বাণু উৎপাদন বাড়াতে; এইচসিজি ইনজেকশন ডিম্বস্ফোটন ঘটাতে
- প্রোল্যাকটিন বেশি হলে ব্রোমোক্রিপটিন
- উপযুক্ত চিকিৎসায় সাড়া না দিলে IVF বিবেচনা করা হয়

## খুলনায় ডিম্বস্ফোটনের পরামর্শ নিন
এক বছর নিয়মিত চেষ্টার পরেও গর্ভধারণ না হলে, অথবা অনিয়মিত মাসিক থাকলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে পরীক্ষা করান। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: Mayo Clinic, WHO।',
  overview_body_en = '## What are ovulation disorders?
Ovulation is the release of a mature egg from the ovary. If ovulation happens rarely or not at all, becoming pregnant is difficult. This is one of the most common causes of female infertility.

If the cycle is shorter than 21 days or longer than 35 days, ovulation may not be happening. PCOS is the most common known cause.',
  symptoms_body_en = '## Symptoms
- Irregular or absent periods, the most common sign
- Difficulty getting pregnant, which is often the main symptom
- Often no other symptoms
- With PCOS: excess facial or body hair, acne or oily skin',
  causes_body_en = '## Causes and Risk Factors
- Hormone problems involving the hypothalamus, pituitary gland or ovaries
- Excess physical or emotional stress
- Very high or very low body weight, or large recent weight changes
- Raised prolactin, sometimes caused by medicines
- Thyroid problems
- Primary ovarian insufficiency, where the ovaries stop producing eggs',
  diagnosis_body_en = '## Diagnosis and Tests
- Home LH kit to detect the rise in hormone just before ovulation
- Progesterone blood test to confirm that ovulation has happened
- Blood tests for ovulation, thyroid, pituitary and prolactin hormones
- Ovarian reserve tests to assess egg supply, especially over age 35
- Pelvic ultrasound
- Assessment using PCOS criteria when relevant

## Treatment
- Clomiphene citrate: usually the first medicine for women under 39 without PCOS
- Letrozole: usually for women under 39 with PCOS
- Metformin: when insulin resistance is present, often in PCOS
- Gonadotropin injections to stimulate egg production, and hCG to trigger ovulation
- Bromocriptine when raised prolactin is the cause
- IVF is considered when other treatments do not work

## Consult a Specialist in Khulna
If you have not conceived after a year of regular attempts, or your periods are irregular, get tested at the gynaecology and maternity chamber in Khulna with Dr. Jebunnesa Jebu. [Book an appointment](/appointment).

Sources: Mayo Clinic, WHO.'
WHERE slug = 'ovulation-disorder';
-- v63 part B: seven condition pages, dual language (draft for doctor review)
-- Sources (names only): WHO, NHS UK, Mayo Clinic, ACOG. Where the sources do not cover a point, the text says so and asks for a clinic check.

UPDATE conditions SET
  meta_title = 'পুরুষের বন্ধ্যাত্ব চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'পুরুষের বন্ধ্যাত্বের কারণ, শুক্রাণু পরীক্ষা (সিমেন অ্যানালাইসিস) ও চিকিৎসা। স্বামী-স্ত্রী দুজনেরই পরীক্ষা জরুরি, খুলনায় পরামর্শ নিন।',
  meta_title_en = 'Male Infertility Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Male infertility in Khulna: causes, semen analysis, tests and treatment. Both partners should be assessed. Consult Dr. Jebunnesa Jebu.',
  overview_body = '## পুরুষের বন্ধ্যাত্ব কী?
বিশ্ব স্বাস্থ্য সংস্থা (WHO) অনুযায়ী, নিয়মিত অরক্ষিত যৌন মিলনের পরেও ১২ মাস বা তার বেশি সময় গর্ভধারণ না হলে তাকে বন্ধ্যাত্ব বলা হয়। বন্ধ্যাত্বের প্রায় অর্ধেক ক্ষেত্রে পুরুষের কোনো না কোনো ভূমিকা থাকে।

পুরুষের বন্ধ্যাত্বের কারণ হতে পারে শুক্রাণুর সংখ্যা কম হওয়া, শুক্রাণুর চলাচল বা গঠনে সমস্যা, অথবা শুক্রাণু বের হওয়ার পথে বাধা। অনেক ক্ষেত্রে কোনো উপসর্গ থাকে না।',
  symptoms_body = '## লক্ষণসমূহ
- প্রধান লক্ষণ হলো গর্ভধারণ না হওয়া
- অনেক ক্ষেত্রে অন্য কোনো লক্ষণ থাকে না
- যৌন সমস্যা, অণ্ডকোষে ব্যথা বা ফোলা থাকলে সেটিও পরীক্ষার বিষয়',
  causes_body = '## কারণ ও ঝুঁকি
- ভ্যারিকোসিল (অণ্ডকোষের চারপাশের শিরা ফোলা): সবচেয়ে সাধারণ এবং সংশোধনযোগ্য কারণগুলোর একটি
- সংক্রমণ, শুক্রাণু নালীতে বাধা, আগে ভ্যাসেক্টমি, পেট, শ্রোণী বা অণ্ডকোষের অস্ত্রোপচার
- হরমোনের ভারসাম্যহীনতা, ক্রোমোজোমজনিত অবস্থা (যেমন ক্লাইনফেল্টার সিনড্রোম), অণ্ডকোষ নেমে না আসা
- বীর্যপাতের সমস্যা বা কিছু ওষুধ
- শিল্প রাসায়নিক, ভারী ধাতু বা বিকিরণের সংস্পর্শ, অণ্ডকোষের অতিরিক্ত তাপ
- ধূমপান, অতিরিক্ত মদ্যপান, মাদক (অ্যানাবলিক স্টেরয়েডসহ), স্থূলতা
- অনেক ক্ষেত্রে কোনো কারণ পাওয়া যায় না',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
সবচেয়ে গুরুত্বপূর্ণ পরীক্ষা হলো সিমেন অ্যানালাইসিস (শুক্রাণু পরীক্ষা)। এতে শুক্রাণুর সংখ্যা, গঠন ও চলাচল দেখা হয় এবং সংক্রমণ আছে কি না যাচাই করা হয়। ফলাফল এক নমুনায় স্থির হয় না, তাই সাধারণত আরেকবার পরীক্ষা করা হয়। NHS-এর পরামর্শ অনুযায়ী প্রায় ৩ মাস পর আবার পরীক্ষা করা হয়।

অন্যান্য পরীক্ষা:
- শারীরিক পরীক্ষা ও বিস্তারিত ইতিহাস
- অণ্ডকোষের আল্ট্রাসাউন্ড (ভ্যারিকোসিল বা অণ্ডকোষের সমস্যা দেখতে)
- টেস্টোস্টেরনসহ হরমোনের রক্ত পরীক্ষা
- প্রয়োজনে জেনেটিক পরীক্ষা
- বীর্যপাতের পর প্রস্রাব পরীক্ষা (প্রস্রাবে শুক্রাণু থাকলে পিছনের দিকে বীর্যপাত সন্দেহ করা হয়)

সিমেন অ্যানালাইসিসের স্বাভাবিক মানের রেফারেন্স সংখ্যা ল্যাব ও নির্দেশিকা অনুযায়ী আলাদা হয়, তাই ফলাফল চিকিৎসকের সঙ্গে আলোচনা করে বুঝতে হবে।

## চিকিৎসা
- অস্ত্রোপচার: ভ্যারিকোসিল সংশোধন, শুক্রাণু নালীর বাধা অপসারণ, ভ্যাসেক্টমি উল্টানো, বা সরাসরি শুক্রাণু সংগ্রহ
- সংক্রমণে অ্যান্টিবায়োটিক (তবে এতে বন্ধ্যাত্ব সব সময় সারে না)
- হরমোন চিকিৎসা বা যৌন সমস্যায় ওষুধ ও কাউন্সেলিং
- সহায়ক প্রজনন: শুক্রাণু ব্যবহার করে IVF বা ICSI
- শুক্রাণু একেবারে কম হলে দাতা শুক্রাণু বিবেচনা করা হতে পারে
- জীবনধারা: ধূমপান বন্ধ, মদ্যপান সীমিত, স্বাভাবিক ওজন, আঁটসাঁট অন্তর্বাস এড়িয়ে চলা, ক্ষতিকর রাসায়নিক থেকে দূরে থাকা
- বিনা পরামর্শে সাপ্লিমেন্ট বা ভেষজ ওষুধ না খাওয়া ভালো, কারণ এর কার্যকারিতা প্রমাণিত নয়

## খুলনায় পুরুষের বন্ধ্যাত্ব পরীক্ষা করান
এক বছর নিয়মিত অরক্ষিত মিলনের পরেও গর্ভধারণ না হলে, স্বামী ও স্ত্রী উভয়ের পরীক্ষা শুরু করা উচিত। খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন এবং প্রয়োজনে ইউরোলজিস্টের রেফারেল নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: WHO, Mayo Clinic, NHS UK।',
  overview_body_en = '## What is male infertility?
According to WHO, infertility means not achieving pregnancy after 12 months or more of regular unprotected sex. Male factors play a part in up to half of infertile couples.

Male infertility can be caused by low sperm count, sperm that do not move or form well, or blockages that stop sperm from leaving. In many cases there are no symptoms.',
  symptoms_body_en = '## Symptoms
- The main sign is not being able to start a pregnancy
- Often there are no other symptoms
- Sexual problems, or pain or swelling in the testicles, should also be checked',
  causes_body_en = '## Causes and Risk Factors
- Varicocele (swollen veins around the testicles), one of the most common and fixable causes
- Infections, blocked sperm ducts, a previous vasectomy, or surgery on the abdomen, pelvis or scrotum
- Hormone imbalance, chromosome conditions such as Klinefelter syndrome, or undescended testicles
- Problems with ejaculation, or certain medicines
- Exposure to industrial chemicals, heavy metals or radiation, and excess heat around the testicles
- Smoking, heavy alcohol use, drug use (including anabolic steroids) and obesity
- In many cases no cause is found',
  diagnosis_body_en = '## Diagnosis and Tests
The most important test is semen analysis. It measures sperm number, shape and movement, and checks for infection. Results vary between samples, so the test is usually repeated, often about 3 months later according to NHS advice.

Other tests:
- Physical examination and detailed history
- Scrotal ultrasound to look for varicocele or testicular problems
- Hormone blood tests, including testosterone
- Genetic tests when needed
- Urine test after ejaculation (sperm in the urine suggests backward ejaculation)

Reference values for semen analysis differ between labs and guidelines, so the results should be discussed with your doctor.

## Treatment
- Surgery: varicocele repair, removing a blockage in the sperm duct, reversing a vasectomy, or direct sperm retrieval
- Antibiotics for infection, although this does not always restore fertility
- Hormone treatment, or medicines and counselling for sexual problems
- Assisted reproduction: IVF or ICSI using sperm from ejaculation, surgery or a donor
- Donor sperm may be considered if sperm count is very low
- Lifestyle: stop smoking, limit alcohol, keep a healthy weight, avoid tight underwear and chemical exposure
- Do not take supplements without advice, as there is no good proof they work

## Get both partners assessed in Khulna
If you have not conceived after a year of regular unprotected sex, both partners should be assessed. Consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna, and ask for a urology referral if needed. [Book an appointment](/appointment).

Sources: WHO, Mayo Clinic, NHS UK.'
WHERE slug = 'male-infertility';

UPDATE conditions SET
  meta_title = 'দীর্ঘস্থায়ী শ্রোণী ব্যথা চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'দীর্ঘস্থায়ী শ্রোণী ব্যথার কারণ, লক্ষণ, রোগ নির্ণয় ও চিকিৎসা। ৬ মাসের বেশি ব্যথা থাকলে কী করবেন, খুলনায় জানুন।',
  meta_title_en = 'Chronic Pelvic Pain Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Chronic pelvic pain in Khulna: causes, symptoms, diagnosis and treatment options. Pain lasting over 6 months needs review. Dr. Jebunnesa Jebu.',
  overview_body = '## দীর্ঘস্থায়ী শ্রোণী ব্যথা কী?
ACOG-এর সংজ্ঞা অনুযায়ী, তলপেটে ৬ মাস বা তার বেশি সময় ধরে থাকা ব্যথাকে দীর্ঘস্থায়ী শ্রোণী ব্যথা বলা হয়। ব্যথা সারাক্ষণ থাকতে পারে, আবার আসা-যাওয়াও করতে পারে। মাসিক, খাওয়ার পর, প্রস্রাব বা সহবাসের সময় বাড়তে পারে।

অনেক ক্ষেত্রে সুনির্দিষ্ট কারণ খুঁজে পাওয়া যায় না। তবু ব্যথা বাস্তব এবং এর চিকিৎসা আছে। কাজ, ঘুম ও ব্যায়ামে ব্যাঘাত ঘটলে চিকিৎসকের সঙ্গে কথা বলা জরুরি।',
  symptoms_body = '## লক্ষণসমূহ
- তলপেটে গভীর ও স্থায়ী ব্যথা, ভোঁতা ব্যথা, তীব্র ব্যথা বা চাপ লাগা
- সহবাসের সময় ব্যথা, মলত্যাগ বা প্রস্রাবের সময় ব্যথা
- দীর্ঘ সময় বসে বা দাঁড়িয়ে থাকার পর ব্যথা
- ঘন ঘন প্রস্রাব বা প্রস্রাবের তাগিদ, পেট ফাঁপা, কোষ্ঠকাঠিন্য বা ডায়রিয়া
- মাসিকের প্রথম ১-২ দিনের পরেও ব্যথা থাকলে এন্ডোমেট্রিওসিসের সম্ভাবনা বিবেচনা করা হয়

## জরুরি সতর্কসংকেত
সাধারণত দীর্ঘস্থায়ী ব্যথা জরুরি নয়। তবে ব্যথা হঠাৎ তীব্র হলে, জ্বর বা অস্বাভাবিক স্রাবের সঙ্গে থাকলে, অথবা বমি বা রক্তপাত হলে দেরি না করে চিকিৎসকের কাছে যান।',
  causes_body = '## কারণ ও ঝুঁকি
- এন্ডোমেট্রিওসিস, ফাইব্রয়েড, শ্রোণী সংক্রমণ, বেশি ব্যথার মাসিক
- ব্যথাযুক্ত মূত্রাশয় (ইন্টারস্টিশিয়াল সিস্টাইটিস), পেলভিক কনজেশন সিনড্রোম, ইরিটেবল বাওয়েল সিনড্রোম
- পেশি বা হাড়ের সমস্যা, যেমন শ্রোণী তলের পেশির টান, হার্নিয়া
- স্নায়ুর আঘাত, যেমন নিম্ন পেটের বা সিজারিয়ান অস্ত্রোপচারের পর
- ডিম্বাশয় অপসারণের পর অবশিষ্ট টিস্যু, কিডনিতে পাথর, বারবার মূত্রনালীর সংক্রমণ
- ঝুঁকি বাড়ায়: একাধিক ব্যথার সমস্যা, যৌন বা শারীরিক নির্যাতনের ইতিহাস, বিষণ্নতা ও দীর্ঘদিনের মানসিক চাপ',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- ব্যথা কখন শুরু হয়েছে, কতটা তীব্র এবং দৈনন্দিন জীবনে কী প্রভাব ফেলছে, তার বিস্তারিত ইতিহাস
- ব্যথার ডায়েরি রাখা: মাসিক চক্র, খাওয়া, প্রস্রাব, মলত্যাগ, সহবাস ও কার্যকলাপের সঙ্গে ব্যথার সম্পর্ক লিখে রাখা
- শারীরিক ও পেলভিক পরীক্ষা
- রক্ত বা টিস্যু পরীক্ষা এবং প্রয়োজনে ইমেজিং
- প্রয়োজনে গ্যাস্ট্রোএন্টারোলজিস্ট বা ইউরোগাইনোকোলজিস্টের রেফারেল

## চিকিৎসা
- কারণ অনুযায়ী চিকিৎসা: পেলভিক ইনফ্ল্যামেটরি ডিজিজে অ্যান্টিবায়োটিক; এন্ডোমেট্রিওসিস ও বেশি ব্যথার মাসিকে হরমোন চিকিৎসা
- ওষুধে ব্যথা কমানো: NSAID, কিছু ক্ষেত্রে বিষণ্নতার ওষুধ বা স্নায়ু-ব্যথার ওষুধ
- ওষুধে কাজ না হলে অস্ত্রোপচার (যেমন ফাইব্রয়েড বা সিস্ট অপসারণ, ল্যাপারোস্কোপিক এন্ডোমেট্রিওসিস অপসারণ, অথবা হিস্টেরেক্টমি)
- ফিজিওথেরাপি, শ্রোণী তলের ব্যায়াম, রিলাক্সেশন ও বায়োফিডব্যাক
- কগনিটিভ বিহেভিয়ারাল থেরাপি ও প্রয়োজনে যৌন চিকিৎসা সহায়ক হতে পারে
- ভালো ভঙ্গি, নিয়মিত ব্যায়াম ও স্বাস্থ্যকর ওজন

## খুলনায় দীর্ঘস্থায়ী ব্যথার পরামর্শ নিন
ব্যথা দৈনন্দিন কাজে বাধা দিলে বা বাড়তে থাকলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে কথা বলুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: ACOG, Mayo Clinic।',
  overview_body_en = '## What is chronic pelvic pain?
ACOG defines chronic pelvic pain as pain in the pelvic area lasting 6 months or longer. The pain can be constant or come and go. It may get worse with periods, eating, passing urine or sex.

Often no specific cause is found. The pain is still real and treatable. If it affects work, sleep or exercise, it is important to discuss it with a doctor.',
  symptoms_body_en = '## Symptoms
- Deep, persistent pelvic pain, dull ache, sharp pain or pressure
- Pain during sex, or when opening the bowels or passing urine
- Pain after long periods of sitting or standing
- Frequent urination or urgency, bloating, constipation or diarrhoea
- Pain continuing beyond the first 1 or 2 days of a period may suggest endometriosis

## Urgent warning signs
Chronic pain is usually not an emergency. See a doctor without delay if pain suddenly becomes severe, or comes with fever, unusual discharge, vomiting or bleeding.',
  causes_body_en = '## Causes and Risk Factors
- Endometriosis, fibroids, pelvic inflammatory disease, or painful periods
- Painful bladder syndrome (interstitial cystitis), pelvic congestion syndrome, irritable bowel syndrome
- Muscle or bone problems, such as pelvic floor muscle tension or a hernia
- Nerve injury, including after lower abdominal or caesarean surgery
- Leftover ovarian tissue after ovary removal, kidney stones, or repeated urine infections
- Risk factors: more than one pelvic pain condition, a history of sexual or physical abuse, depression and long-term stress',
  diagnosis_body_en = '## Diagnosis and Tests
- Detailed history: when the pain started, how severe it is and how it affects daily life
- A pain diary noting the link with the menstrual cycle, eating, passing urine, bowel movements, sex and activity
- Physical and pelvic examination
- Blood or tissue tests, and imaging if needed
- Referral to a gastroenterologist or urogynaecologist when needed

## Treatment
- Cause-specific treatment: antibiotics for pelvic inflammatory disease; hormone treatment for endometriosis and very painful periods
- Pain relief: NSAIDs, and in some cases antidepressants or nerve-pain medicines
- Surgery if medicines do not help, such as removing fibroids or cysts, keyhole removal of endometriosis, or hysterectomy
- Physiotherapy, pelvic floor exercises, relaxation and biofeedback
- Cognitive behavioural therapy, and sex therapy when needed
- Good posture, regular exercise and a healthy weight

## Consult a Specialist in Khulna
If pain disrupts daily life or keeps getting worse, talk to Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Sources: ACOG, Mayo Clinic.'
WHERE slug = 'pelvic-pain-syndrome';

UPDATE conditions SET
  meta_title = 'অতিরিক্ত মাসিক রক্তস্রাব চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'অতিরিক্ত মাসিক রক্তস্রাব (মেনোরেজিয়া) কী, এর কারণ, পরীক্ষা ও চিকিৎসা। রক্তস্বল্পতা এড়াতে কখন দেখাবেন, খুলনায় জানুন।',
  meta_title_en = 'Heavy Menstrual Bleeding Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Heavy menstrual bleeding in Khulna: causes, tests and treatment options, including medicines and procedures. Dr. Jebunnesa Jebu.',
  overview_body = '## অতিরিক্ত মাসিক রক্তস্রাব কী?
স্বাভাবিকের চেয়ে অনেক বেশি রক্তস্রাবকে মেনোরেজিয়া বা অতিরিক্ত মাসিক বলা হয়। ACOG বলছে, ভারী মাসিক স্বাভাবিক নয় এবং এটি দৈনন্দিন জীবনে বড় ব্যাঘাত ঘটাতে পারে। NHS জানায়, অনেক নারীর মাসিক তাদের জন্য ভারী হলেও চিকিৎসা লাগে তখনই, যখন তা কাজ, ঘুম বা জীবনযাত্রায় প্রভাব ফেলে।

এর কোনো একক সংখ্যাগত সংজ্ঞা নেই। তবে লক্ষণ ও প্রভাবই মূল্যায়নের প্রধান মানদণ্ড।',
  symptoms_body = '## লক্ষণসমূহ
- ৭ দিনের বেশি রক্তস্রাব
- প্রতি ১-২ ঘণ্টায় একটি প্যাড বা ট্যাম্পন ভিজে যাওয়া, বা রাতে একাধিক প্যাড লাগা
- একসঙ্গে দুটি প্যাড ব্যবহার করতে হওয়া
- বড় রক্তের ডেলা যাওয়া (১০ পয়সার মুদ্রার চেয়ে বড়, প্রায় ২.৫ সেন্টিমিটার)
- কাপড় বা বিছানায় রক্ত লেগে যাওয়া, কাজ বা স্কুল কলেজে যেতে না পারা
- ক্লান্তি বা দম ফুরিয়ে আসা (রক্তস্বল্পতার লক্ষণ)

## জরুরি সতর্কসংকেত
প্রচুর রক্তস্রাব, মাথা ঘোরা, অজ্ঞান হওয়ার অনুভূতি বা তীব্র শ্বাসকষ্ট থাকলে দ্রুত জরুরি চিকিৎসা নিন। মাসিকের মাঝে বা সহবাসের পরে রক্তস্রাব, তীব্র মাসিক ব্যথা, বা প্রস্রাব-মলত্যাগ ও সহবাসের সময় ব্যথা থাকলে চিকিৎসকের কাছে যান।',
  causes_body = '## কারণ ও ঝুঁকি
- অনিয়মিত ডিম্বস্ফোটন (বয়ঃসন্ধি, পেরিমেনোপজ, PCOS, থাইরয়েড কম কাজ করা)
- রক্ত জমাট বাঁধার সমস্যা, যেমন ভন উইলব্র্যান্ড ডিজিজ
- কিছু ওষুধ: রক্ত পাতলাকারী, অ্যাসপিরিন, তামার আইইউডি (বিশেষ করে প্রথম বছরে), কিছু কেমোথেরাপি
- এন্ডোমেট্রিওসিস, অ্যাডিনোমায়োসিস, ফাইব্রয়েড, শ্রোণী সংক্রমণ
- গর্ভাবস্থার সমস্যা, যেমন এক্টোপিক গর্ভ বা গর্ভপাত
- জরায়ুর আস্তরণের অস্বাভাবিক বৃদ্ধি বা বিরল ক্ষেত্রে জরায়ু ক্যান্সার (বিশেষ করে মেনোপজের কাছাকাছি বয়সে)
- অনেক ক্ষেত্রে কোনো কারণ পাওয়া যায় না',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- ইতিহাস, ব্যবহৃত ওষুধ, গর্ভনিরোধক ও মাসিক চক্রের রেকর্ড (ক্যালেন্ডার বা অ্যাপ)
- শারীরিক ও পেলভিক পরীক্ষা, গর্ভধারণ ও যৌনবাহিত সংক্রমণের পরীক্ষা
- রক্ত পরীক্ষা: রক্তস্বল্পতা, থাইরয়েড ও রক্ত জমাট বাঁধার সমস্যা
- জরায়ুমুখের পরীক্ষা (প্যাপ স্মিয়ার) এবং জরায়ু, ডিম্বাশয় ও শ্রোণীর আল্ট্রাসাউন্ড
- সোনোহিস্টেরোগ্রাফি (জরায়ুর আস্তরণ দেখতে স্যালাইনসহ আল্ট্রাসাউন্ড) ও হিস্টেরোস্কোপি
- প্রয়োজনে এন্ডোমেট্রিয়াল বায়োপসি বা এমআরআই

## চিকিৎসা
- ব্যথা ও রক্তস্রাব কমাতে NSAID, যেমন আইবুপ্রোফেন বা ন্যাপ্রক্সেন
- ট্রানেক্সামিক অ্যাসিড, শুধু রক্তস্রাবের সময়
- হরমোন চিকিৎসা: সম্মিলিত গর্ভনিরোধক বড়ি, লেভোনরজেস্ট্রেল আইইউডি, প্রোজেস্টেরন; স্বল্পমেয়াদে GnRH ওষুধ
- রক্তস্বল্পতা থাকলে আয়রন সাপ্লিমেন্ট; রক্ত জমাট সমস্যায় উপযুক্ত ওষুধ
- অস্ত্রোপচার বা প্রসিডিউর: এন্ডোমেট্রিয়াল অ্যাবলেশন, এন্ডোমেট্রিয়াল রিসেকশন, ইউটেরাইন আর্টারি এম্বোলাইজেশন, মায়োমেকটমি, হিস্টেরোস্কোপিক ফাইব্রয়েড অপসারণ, হিস্টেরেক্টমি
- অ্যাবলেশনের পর গর্ভধারণ সম্ভব হলেও কম সম্ভাবনা, এবং তা ঝুঁকিপূর্ণ হতে পারে। তাই সন্তানের পরিকল্পনা থাকলে আগেই জানাতে হবে।

## খুলনায় অতিরিক্ত মাসিকের পরামর্শ নিন
মাসিক এতটাই ভারী যে কাজ বা ঘুমে ব্যাঘাত ঘটছে, বা রক্তস্বল্পতার লক্ষণ আছে, তাহলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে কথা বলুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: ACOG, NHS UK, Mayo Clinic।',
  overview_body_en = '## What is heavy menstrual bleeding?
Bleeding much heavier than usual is called menorrhagia or heavy menstrual bleeding. ACOG states that heavy bleeding is not normal and can disrupt daily life. NHS notes that many women have periods that are heavy for them, and treatment helps mainly when they affect work, sleep or daily activities.

There is no single numerical definition. Symptoms and their impact are the main guide.',
  symptoms_body_en = '## Symptoms
- Bleeding lasting more than 7 days
- Soaking a pad or tampon every 1 to 2 hours, or needing several pads overnight
- Needing two products at once
- Clots larger than a 10p coin (about 2.5 cm)
- Blood leaking onto clothes or bedding, or missing work, school or college
- Tiredness or breathlessness, which can be signs of anaemia

## Urgent warning signs
Get urgent care for heavy bleeding with dizziness, feeling faint or severe breathlessness. See a doctor for bleeding between periods or after sex, severe period pain, or pain when passing urine, opening the bowels or during sex.',
  causes_body_en = '## Causes and Risk Factors
- Irregular ovulation, linked to puberty, perimenopause, PCOS or an underactive thyroid
- Bleeding disorders such as von Willebrand disease
- Medicines: blood thinners, aspirin, a copper coil (especially in the first year), and some chemotherapy
- Endometriosis, adenomyosis, fibroids, or pelvic inflammatory disease
- Pregnancy problems such as ectopic pregnancy or miscarriage
- Rarely, womb cancer, especially around menopause
- In many cases no cause is found

## Diagnosis and Tests
- History, current medicines, contraception and a cycle record (calendar or app)
- Physical and pelvic examination, and pregnancy and infection tests
- Blood tests for anaemia, thyroid problems and clotting problems
- Cervical screening (Pap test), and ultrasound of the womb, ovaries and pelvis
- Sonohysterography (saline ultrasound of the womb lining) and hysteroscopy
- Endometrial biopsy or MRI when needed

## Treatment
- Pain and bleeding relief: NSAIDs such as ibuprofen or naproxen
- Tranexamic acid, taken only during bleeding
- Hormone options: combined contraceptive pill, levonorgestrel coil, progesterone; short-term GnRH medicines
- Iron supplements if anaemic; suitable medicines for clotting disorders
- Procedures: endometrial ablation, endometrial resection, uterine artery embolisation, myomectomy, hysteroscopic fibroid removal, or hysterectomy
- Pregnancy after ablation is unlikely but possible, and can be risky. Tell your doctor early if you plan a pregnancy.

## Consult a Specialist in Khulna
If heavy periods disrupt work or sleep, or you have signs of anaemia, speak with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Sources: ACOG, NHS UK, Mayo Clinic.'
WHERE slug = 'heavy-menstrual-bleeding';

UPDATE conditions SET
  meta_title = 'মাসিক বন্ধ বা দেরিতে মাসিক চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'মাসিক বন্ধ (অ্যামেনোরিয়া) বা দেরিতে মাসিকের কারণ, পরীক্ষা ও চিকিৎসা। কখন চিকিৎসকের কাছে যাবেন, খুলনায় পরামর্শ নিন।',
  meta_title_en = 'Absent or Delayed Periods Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Absent or delayed periods in Khulna: causes, hormone tests and treatment. Know when to see a doctor. Dr. Jebunnesa Jebu.',
  overview_body = '## মাসিক বন্ধ বা দেরিতে মাসিক কী?
মাসিক না হওয়াকে অ্যামেনোরিয়া বলা হয়। প্রাথমিক অ্যামেনোরিয়ায় কখনো মাসিক হয়নি। সেকেন্ডারি অ্যামেনোরিয়ায় আগে নিয়মিত মাসিক হতো, কিন্তু পরপর তিনটি বা তার বেশি মাসিক বন্ধ হয়ে গেছে।

এর কারণ গর্ভাবস্থা বা স্তন্যদানের মতো স্বাভাবিক বিষয় থেকে শুরু করে হরমোন, থাইরয়েড বা গঠনগত সমস্যাও হতে পারে। তাই কারণ অনুযায়ী মূল্যায়ন জরুরি।',
  symptoms_body = '## লক্ষণসমূহ
লক্ষণ কারণের ওপর নির্ভর করে:
- স্তনের বোঁটা দিয়ে দুধের মতো স্রাব, গরম অনুভূতি বা ঘাম (মেনোপজ বা প্রোল্যাকটিনের সমস্যায়)
- যোনি শুষ্কতা
- চুল পড়া, মাথাব্যথা, দৃষ্টির পরিবর্তন, মুখে অতিরিক্ত লোম, ব্রণ
- তলপেটে ব্যথা

## কখন দেরি না করে দেখাবেন
- পরপর তিনটি মাসিক বন্ধ থাকলে
- ১৫ বছর বয়সে প্রথম মাসিক না হলে (অন্য বয়ঃসন্ধির পরিবর্তন না থাকলে ১৩ বছর বয়সে)',
  causes_body = '## কারণ ও ঝুঁকি
- স্বাভাবিক কারণ: গর্ভাবস্থা, স্তন্যদান, মেনোপজ
- গর্ভনিরোধক: কিছু ইমপ্ল্যান্ট, বড়ি, হরমোনাল আইইউডি, ইনজেকশন (তামার আইইউডি সাধারণত এমন করে না)
- ওষুধ: কিছু মানসিক রোগের ওষুধ, কেমোথেরাপি, কিছু রক্তচাপের ওষুধ, ওপিওয়েড
- জীবনযাত্রা: মানসিক চাপ, পর্যাপ্ত খাবার না খাওয়া, অতিরিক্ত ব্যায়াম
- হরমোনজনিত: PCOS, থাইরয়েড সমস্যা, পিটুইটারির সৌম্য টিউমার, ৪০ বছরের আগে ডিম্বাশয়ের অপর্যাপ্ততা
- গঠনগত: জরায়ুতে দাগ (অ্যাশারম্যান সিনড্রোম), যোনির বাধা
- ঝুঁকি বাড়ায়: পারিবারিক ইতিহাস, খাওয়ার ব্যাধি, দীর্ঘস্থায়ী উদ্বেগ, জরায়ুতে অস্ত্রোপচার বা ডি অ্যান্ড সি',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- শারীরিক ও পেলভিক পরীক্ষা
- গর্ভধারণ পরীক্ষা (সাধারণত প্রথম পরীক্ষা)
- থাইরয়েড (TSH) ও ডিম্বাশয় কার্যকারিতা (FSH) পরীক্ষা
- প্রোল্যাকটিন পরীক্ষা (বেশি থাকলে পিটুইটারি টিউমার সন্দেহ)
- মুখে লোম বা কণ্ঠস্বর পরিবর্তন থাকলে অ্যান্ড্রোজেন পরীক্ষা
- প্রোজেস্টিন চ্যালেঞ্জ টেস্ট: ৫-১০ দিন হরমোন ওষুধের পর রক্তস্রাব হলে বোঝা যায় ইস্ট্রোজেনের অভাবে মাসিক বন্ধ হয়েছে
- আল্ট্রাসাউন্ড; প্রয়োজনে এমআরআই
- অন্য পরীক্ষায় কারণ না পাওয়া গেলে হিস্টেরোস্কোপি

## চিকিৎসা
- চিকিৎসা নির্ভর করে মূল কারণের ওপর
- কখনো কখনো জন্মনিয়ন্ত্রণ বড়ি বা অন্য হরমোন থেরাপিতে রক্তস্রাব ফিরে আসে
- থাইরয়েড বা পিটুইটারির সমস্যায় ওষুধ
- টিউমার বা বাধা থাকলে অস্ত্রোপচার
- জীবনধারা: নিয়মিত ঘুম, সুষম খাবার, মানসিক চাপ কমানো, ব্যায়ামের মাত্রা ঠিক রাখা, মাসিকের তারিখ ও উপসর্গ লিখে রাখা

## খুলনায় মাসিক বন্ধের পরামর্শ নিন
তিনটি মাসিক পরপর বন্ধ থাকলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে পরীক্ষা করান। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: Mayo Clinic।',
  overview_body_en = '## What are absent or delayed periods?
No period is called amenorrhoea. Primary amenorrhoea means periods have never started. Secondary amenorrhoea means periods used to be regular but have stopped for three or more cycles in a row.

Causes range from normal situations such as pregnancy and breastfeeding to hormone, thyroid or structural problems. The cause decides the right assessment.',
  symptoms_body_en = '## Symptoms
Symptoms depend on the cause:
- Milky discharge from the nipple, hot flushes or sweating (in menopause or prolactin problems)
- Vaginal dryness
- Hair loss, headache, vision changes, excess facial hair or acne
- Pelvic pain

## When to see a doctor without delay
- Three periods in a row are missed
- No first period by age 15 (or by age 13 if there are no other puberty changes)',
  causes_body_en = '## Causes and Risk Factors
- Normal causes: pregnancy, breastfeeding, menopause
- Contraception: some implants, pills, hormonal coils and injections (a copper coil usually does not cause it)
- Medicines: some antipsychotics, chemotherapy, some blood pressure medicines, opioids
- Lifestyle: stress, not eating enough, very intense exercise
- Hormone causes: PCOS, thyroid conditions, a benign pituitary tumour, primary ovarian insufficiency before age 40
- Structural causes: scarring inside the womb (Asherman syndrome), or a blockage of the vagina
- Risk factors: family history, eating disorders, long-lasting anxiety, and surgery or dilation and curettage (D and C) of the womb',
  diagnosis_body_en = '## Diagnosis and Tests
- Physical and pelvic examination
- Pregnancy test, usually the first test
- Thyroid (TSH) and ovarian function (FSH) tests
- Prolactin test, since high levels may suggest a pituitary tumour
- Androgen tests if there is excess facial hair or voice change
- Progestin challenge test: bleeding after 5 to 10 days of a hormone medicine shows that low oestrogen caused the missed periods
- Ultrasound, and MRI if needed
- Hysteroscopy if other tests do not find the cause

## Treatment
- Treatment depends on the underlying cause
- Birth control pills or other hormone therapy can sometimes restart bleeding
- Thyroid or pituitary conditions are treated with medicines
- Surgery if a tumour or blockage is the cause
- Self-care: regular sleep, balanced food, stress management, a balanced exercise routine, and a record of period dates and symptoms

## Consult a Specialist in Khulna
If three periods in a row are missed, get tested at the gynaecology and maternity chamber in Khulna with Dr. Jebunnesa Jebu. [Book an appointment](/appointment).

Source: Mayo Clinic.'
WHERE slug = 'delayed-absent-periods';

UPDATE conditions SET
  meta_title = 'ডিম্বাশয়ের কার্যক্ষমতা কমা চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'ডিম্বাশয়ের সঞ্চয় (রিজার্ভ) কমা ও বয়সজনিত প্রজনন ক্ষমতার পরিবর্তন। কারণ, পরীক্ষা ও পরিকল্পনা নিয়ে খুলনায় পরামর্শ নিন।',
  meta_title_en = 'Low Ovarian Reserve Care in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Low ovarian reserve and age-related fertility decline in Khulna: causes, tests and planning options. Consult Dr. Jebunnesa Jebu.',
  overview_body = '## ডিম্বাশয়ের সঞ্চয় কমা কী?
ডিম্বাশয়ের সঞ্চয় (ওভারিয়ান রিজার্ভ) বলতে বোঝায় ডিম্বাশয়ে কতগুলো ডিম্বাণু অবশিষ্ট আছে এবং তাদের মান কেমন। বয়স বাড়লে স্বাভাবিকভাবেই প্রজনন ক্ষমতা কমে। NHS-এর তথ্য অনুযায়ী বয়সের সঙ্গে উর্বরতা কমে যায়।

ডিম্বাশয়ের সঞ্চয় কম বলতে অনেক সময় বোঝানো হয় ডিম্বাণুর সংখ্যা বা মান আশানুরূপ নয়। এটি সবসময় বন্ধ্যাত্ব বোঝায় না, তবে পরিকল্পনা ও পরীক্ষার জন্য গুরুত্বপূর্ণ।',
  symptoms_body = '## লক্ষণ
ডিম্বাশয়ের সঞ্চয় কমার নির্দিষ্ট কোনো লক্ষণ সবসময় থাকে না। সাধারণত যা লক্ষ্য করা যায়:
- অনিয়মিত মাসিক বা ডিম্বস্ফোটনের সমস্যা
- গর্ভধারণে দীর্ঘ সময় লাগা

## জরুরি সতর্কসংকেত
মাসিক তিন মাস বা তার বেশি বন্ধ থাকলে, বা গরম অনুভূতি, রাতে ঘাম ও যোনি শুষ্কতার সঙ্গে মাসিক বন্ধ হলে চিকিৎসকের কাছে যান।',
  causes_body = '## কারণ ও ঝুঁকি
- বয়স: সবচেয়ে প্রধান কারণ, বিশেষত ৩৫ বছরের পর
- ডিম্বস্ফোটনের সমস্যা, হরমোনজনিত ভারসাম্যহীনতা
- অতিরিক্ত ওজন বা খুব কম ওজন
- অস্ত্রোপচার বা চিকিৎসা (যেমন কিছু কেমোথেরাপি) যা ডিম্বাশয়কে প্রভাবিত করে
- এন্ডোমেট্রিওসিস বা ডিম্বাশয়ে আগের অস্ত্রোপচার
- অনেক ক্ষেত্রে কোনো নির্দিষ্ট কারণ পাওয়া যায় না',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- মাসিক চক্র, বয়স ও প্রজনন ইতিহাসের মূল্যায়ন
- ডিম্বাশয়ের সঞ্চয় যাচাইয়ের রক্ত পরীক্ষা (যেমন এএমএইচ, এফএসএইচ)
- আল্ট্রাসাউন্ডে ডিম্বাশয়ের ফলিকল গণনা
- গর্ভধারণের চেষ্টার ইতিহাস অনুযায়ী দম্পতির মূল্যায়ন

এই পরীক্ষাগুলোর মানে ও ব্যাখ্যা ব্যক্তিভেদে ভিন্ন হয়, তাই ফলাফল চিকিৎসকের সঙ্গে আলোচনা করা উচিত।

## চিকিৎসা ও পরিকল্পনা
- NHS অনুযায়ী ডিম্বস্ফোটন না হলে ওষুধের চিকিৎসা, এবং কারণ অনুযায়ী অস্ত্রোপচার বা IUI ও IVF-এর মতো বিকল্প বিবেচনা করা হয়
- সঞ্চয় কমে গেলে কোন বিকল্প উপযুক্ত তা বয়স, সঞ্চয় ও দম্পতির পরিকল্পনা অনুযায়ী ঠিক করা হয়
- সঞ্চয় বাড়ানোর কোনো প্রমাণিত ওষুধ নেই। ভেষজ বা অনাবশ্যক সাপ্লিমেন্ট নির্ভর না করে চিকিৎসকের পরামর্শ নেওয়া ভালো।
- সন্তান নেওয়ার পরিকল্পনা দেরি না করে আলোচনা করা উচিত

## খুলনায় প্রজনন পরিকল্পনার পরামর্শ নিন
বয়স ৩৫ বছরের বেশি হলে বা গর্ভধারণে সমস্যা থাকলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে আলোচনা করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## What is low ovarian reserve?
Ovarian reserve means how many eggs remain in the ovaries and how good their quality is. As a woman gets older, fertility naturally declines. The NHS notes that fertility declines with age.

Low ovarian reserve does not always mean infertility, but it is important for planning and testing.',
  symptoms_body_en = '## Symptoms
There are no reliable specific symptoms. These are the signs that are usually noticed:
- Irregular periods or ovulation problems
- Taking a long time to conceive

## Urgent warning signs
See a doctor if periods have stopped for 3 months or more, or if hot flushes, night sweats and vaginal dryness come with missed periods.',
  causes_body_en = '## Causes and Risk Factors
- Age: the main factor, especially after 35
- Ovulation problems and hormone imbalance
- Being very overweight or very underweight
- Surgery or treatments, such as some chemotherapy, that affect the ovaries
- Endometriosis or previous ovarian surgery
- In many cases no specific cause is found',
  diagnosis_body_en = '## Diagnosis and Tests
- Assessment of cycle, age and reproductive history
- Blood tests to assess ovarian reserve, such as AMH and FSH
- Ultrasound count of ovarian follicles
- Assessment of the couple based on how long they have tried to conceive

The meaning of these tests varies from person to person, so the results should be discussed with a doctor.

## Treatment and Planning
- If ovulation does not happen, medicines are used, and options such as IUI or IVF are considered depending on the cause
- The right option depends on age, ovarian reserve and the couple''s plans
- There is no proven medicine to increase ovarian reserve. Avoid relying on herbal or unnecessary supplements and seek medical advice.
- Discuss plans for pregnancy early rather than waiting

## Fertility planning in Khulna
If you are over 35 or have difficulty conceiving, discuss your options with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'ovarian-function-weakness';

UPDATE conditions SET
  meta_title = 'জরায়ুর পলিপ চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'জরায়ুর ভেতরে পলিপের লক্ষণ, কারণ, পরীক্ষা ও চিকিৎসা। মাসিকের বাইরে বা মেনোপজের পরে রক্তস্রাব হলে কী করবেন, খুলনায় জানুন।',
  meta_title_en = 'Uterine Polyps Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Uterine polyps in Khulna: symptoms, causes, hysteroscopy, biopsy and treatment. Bleeding after menopause needs checking. Dr. Jebunnesa Jebu.',
  overview_body = '## জরায়ুর পলিপ কী?
জরায়ুর ভেতরের দেয়ালে সংযুক্ত ছোট বৃদ্ধিকে জরায়ুর পলিপ বা এন্ডোমেট্রিয়াল পলিপ বলা হয়। এগুলো জরায়ুর আস্তরণের কোষ থেকে তৈরি হয়। বেশির ভাগ পলিপই সৌম্য (ক্যান্সার নয়)।

অনেক ক্ষেত্রে এর কোনো উপসর্গ থাকে না, আবার কখনো অনিয়মিত রক্তস্রাব হয়। পলিপের বৃদ্ধিতে হরমোনের ভূমিকা আছে বলে ধারণা করা হয়।',
  symptoms_body = '## লক্ষণসমূহ
- মেনোপজের পরে রক্তস্রাব
- মাসিকের মাঝে রক্তস্রাব
- অনিয়মিত মাসিক, মাসিকের দৈর্ঘ্য বা রক্তপাতের পরিমাণে পরিবর্তন, কখনো অতিরিক্ত ভারী মাসিক
- হালকা রক্তের দাগ বা স্পটিং
- অনেকের কোনো উপসর্গই থাকে না

## কখন দ্রুত দেখাবেন
মেনোপজের পরে যেকোনো রক্তস্রাব, বা মাসিকের মাঝে রক্তস্রাব থাকলে চিকিৎসকের কাছে যান।',
  causes_body = '## কারণ ও ঝুঁকি
- হরমোন, বিশেষত ইস্ট্রোজেন, যার প্রতি পলিপ সংবেদনশীল
- ঝুঁকি বাড়ায়: পেরিমেনোপজ বা মেনোপজ, স্থূলতা, ট্যামক্সিফেন ওষুধ, মেনোপজের হরমোন থেরাপি',
  diagnosis_body = '## রোগ নির্ণয় ও পরীক্ষা
- ট্রান্সভ্যাজাইনাল আল্ট্রাসাউন্ড: পলিপ বা জরায়ুর আস্তরণের ঘনত্ব দেখা যায়
- সোনোহিস্টেরোগ্রাফি: জরায়ুতে স্যালাইন দিয়ে আস্তরণ আরও স্পষ্টভাবে দেখা
- হিস্টেরোস্কোপি: পাতলা আলোকিত ক্যামেরা জরায়ুমুখ দিয়ে ঢুকিয়ে জরায়ুর ভেতর দেখা
- এন্ডোমেট্রিয়াল বায়োপসি: ল্যাবে পরীক্ষার জন্য টিস্যু সংগ্রহ (এতে পলিপ ধরা না পড়তে পারে)

## চিকিৎসা
- ছোট ও উপসর্গহীন পলিপ অনেক ক্ষেত্রে নজরদারিতে রাখা হয়, কারণ কিছু পলিপ নিজে থেকে মিলিয়ে যায়
- হরমোন ওষুধ (প্রোজেস্টিন বা GnRH) উপসর্গ কমাতে পারে, তবে সাধারণত স্বল্পমেয়াদে এবং বন্ধ করলে উপসর্গ ফিরে আসতে পারে
- হিস্টেরোস্কোপির মাধ্যমে পলিপ অপসারণ, এবং টিস্যু ল্যাবে ক্যান্সার পরীক্ষার জন্য পাঠানো হয়
- পলিপ আবার হওয়া বিরল, তবে হলে আবার চিকিৎসা লাগতে পারে
- কিছু ক্ষেত্রে পলিপের সঙ্গে প্রি-ক্যান্সারাস পরিবর্তন বা জরায়ু ক্যান্সার থাকতে পারে, তাই অপসারিত টিস্যু পরীক্ষা জরুরি

## খুলনায় জরায়ুর পলিপের পরামর্শ নিন
মেনোপজের পরে রক্তস্রাব বা মাসিকের মাঝে রক্তস্রাব হলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: Mayo Clinic।',
  overview_body_en = '## What are uterine polyps?
Uterine polyps are small growths attached to the inner wall of the womb. They form from cells of the womb lining, and are also called endometrial polyps. Most polyps are benign, meaning not cancer.

Many have no symptoms, while some cause irregular bleeding. Hormones are thought to play a role in their growth.',
  symptoms_body_en = '## Symptoms
- Bleeding after menopause
- Bleeding between periods
- Irregular periods, or changes in how long or how heavy they are, sometimes very heavy periods
- Light spotting
- Many women have no symptoms at all

## When to see a doctor soon
See a doctor for any bleeding after menopause, or bleeding between periods.',
  causes_body_en = '## Causes and Risk Factors
- Hormones, especially oestrogen, to which polyps are sensitive
- Risk factors: perimenopause or menopause, obesity, the medicine tamoxifen, and menopausal hormone therapy',
  diagnosis_body_en = '## Diagnosis and Tests
- Transvaginal ultrasound: may show a polyp or thickened lining
- Sonohysterography: saline placed in the womb to show the lining more clearly
- Hysteroscopy: a thin lighted camera passed through the cervix to view the inside of the womb
- Endometrial biopsy: a tissue sample sent for testing (it may miss the polyp)

## Treatment
- Small polyps without symptoms are often monitored, as some resolve on their own
- Hormone medicines (progestins or GnRH) can ease symptoms, usually for a short time; symptoms often return when they are stopped
- Removal of the polyp during hysteroscopy, with the tissue sent to the lab
- Recurrence is rare, but further treatment may be needed if it happens
- Some polyps are linked with precancerous changes or womb cancer, so the removed tissue must be checked

## Consult a Specialist in Khulna
If you have bleeding after menopause or bleeding between periods, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: Mayo Clinic.'
WHERE slug = 'uterine-polyps';

UPDATE conditions SET
  meta_title = 'জরায়ুমুখ ক্যান্সার স্ক্রিনিং খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'জরায়ুমুখ ক্যান্সার স্ক্রিনিং কেন জরুরি, প্যাপ ও এইচপিভি পরীক্ষা, এবং কখন করাবেন। খুলনায় গাইনী চেম্বারে পরামর্শ নিন।',
  meta_title_en = 'Cervical Cancer Screening in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Gynaecological cancer screening in Khulna: why cervical screening matters, Pap and HPV tests, and when to start. Dr. Jebunnesa Jebu.',
  overview_body = '## গাইনী ক্যান্সার স্ক্রিনিং কেন জরুরি?
স্ক্রিনিং মানে উপসর্গ আসার আগে পরীক্ষা করে জরায়ুমুখের অস্বাভাবিক কোষ বা ঝুঁকিপূর্ণ এইচপিভি (HPV) সংক্রমণ খুঁজে বের করা। সময়মতো ধরা পড়লে চিকিৎসা অনেক সহজ এবং ক্যান্সার হওয়ার আগেই প্রতিরোধ সম্ভব।

প্রধান স্ক্রিনিং পরীক্ষা হলো প্যাপ স্মিয়ার এবং এইচপিভি পরীক্ষা। এ বিষয়ে আন্তর্জাতিক নির্দেশনা (WHO ও ACOG) সময়সীমায় কিছুটা আলাদা, তাই আপনার বয়স ও ইতিহাস অনুযায়ী পরামর্শ নেওয়া দরকার।',
  symptoms_body = '## লক্ষণ
স্ক্রিনিং উপসর্গহীন নারীদের জন্য। তবে নিচের লক্ষণ থাকলে স্ক্রিনিং অপেক্ষা না করে পরীক্ষা করান:
- মাসিকের বাইরে বা সহবাসের পরে রক্তস্রাব
- অস্বাভাবিক যোনি স্রাব
- সহবাসের সময় ব্যথা
- মেনোপজের পরে রক্তস্রাব

## জরুরি সতর্কসংকেত
প্রচুর রক্তস্রাব, তীব্র পেট বা শ্রোণী ব্যথা, অথবা হঠাৎ ওজন কমে যাওয়া থাকলে দ্রুত চিকিৎসকের কাছে যান।',
  causes_body = '## ঝুঁকি বাড়ায় এমন বিষয়
- আগে জরায়ুমুখ ক্যান্সার, এইচআইভি সংক্রমণ, বা দুর্বল রোগপ্রতিরোধ ব্যবস্থা
- গর্ভাবস্থায় ডায়েথাইলস্টিলবেসট্রল (DES) সংস্পর্শ
- হিস্টেরেক্টমির পরে স্ক্রিনিংয়ের প্রয়োজন নির্ভর করে জরায়ুমুখ অপসারণ হয়েছে কি না, অস্ত্রোপচারের কারণ, এবং আগের গুরুতর পরিবর্তন বা ক্যান্সারের ইতিহাসের ওপর
- এইচপিভি টিকা ক্যান্সার সৃষ্টিকারী বেশির ভাগ এইচপিভি থেকে সুরক্ষা দেয়, তবে সব ধরনের থেকে নয়। টিকা নেওয়া নারীরও স্ক্রিনিং নির্দেশনা মানা উচিত।',
  diagnosis_body = '## স্ক্রিনিং পরীক্ষা
- প্যাপ স্মিয়ার: জরায়ুমুখের কোষে অস্বাভাবিক পরিবর্তন খোঁজা
- এইচপিভি পরীক্ষা: ক্যান্সারের সঙ্গে যুক্ত ঝুঁকিপূর্ণ এইচপিভি ধরন খোঁজা। প্যাপের নমুনা দিয়েই প্রায়ই দুটি পরীক্ষা হয়।
- WHO-এর সুপারিশ অনুযায়ী এইচপিভি ডিএনএ পরীক্ষা অ্যাসিটিক অ্যাসিড ভিজুয়াল পরীক্ষা (VIA) বা সাইটোলজির চেয়ে অগ্রাধিকার পায়। নিজে সংগ্রহ করা নমুনাও ব্যবহার করা যায়।
- WHO-এর সাধারণ নারীর জন্য সুপারিশ: ৩০ বছর বয়সে শুরু, এইচপিভি ডিএনএ পরীক্ষা প্রতি ৫ থেকে ১০ বছর অন্তর। এইচআইভি-আক্রান্ত নারীদের জন্য শুরু ২৫ বছর, প্রতি ৩ থেকে ৫ বছর অন্তর।
- ACOG (যুক্তরাষ্ট্র) নির্দেশনায় শুরু ২১ বছর এবং সময়সূচি ভিন্ন। তাই বাংলাদেশের প্রেক্ষাপটে ক্লিনিক্যাল নির্দেশনা অনুসরণ করা হবে।

## চিকিৎসা ও পরবর্তী ধাপ
- অস্বাভাবিক ফল এলে কলপোস্কোপি করে সমস্যাযুক্ত অংশ দেখা হয়, প্রয়োজনে বায়োপসি
- WHO-এর পরামর্শ: স্ক্রিনিং পজিটিভ নারীদের দ্রুত উপযুক্ত চিকিৎসা বা রেফারেল দিতে হবে
- ধরন ও মাত্রা অনুযায়ী চিকিৎসা ঠিক হয়, তাই ফল চিকিৎসকের সঙ্গে আলোচনা করা জরুরি

## খুলনায় স্ক্রিনিংয়ের পরামর্শ নিন
নিয়মিত স্ক্রিনিং বা অস্বাভাবিক ফলাফলের পর খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে আলোচনা করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: ACOG, WHO।',
  overview_body_en = '## Why does gynaecological cancer screening matter?
Screening means testing for abnormal cervical cells or high-risk HPV infection before any symptoms appear. When found early, treatment is much simpler, and cervical cancer can often be prevented.

The main screening tests are the Pap smear and the HPV test. International guidance from WHO and ACOG differs in timing, so advice should be based on your age and history.',
  symptoms_body_en = '## Symptoms
Screening is for women without symptoms. If you have any of these, get tested without waiting for routine screening:
- Bleeding between periods or after sex
- Unusual vaginal discharge
- Pain during sex
- Bleeding after menopause

## Urgent warning signs
Seek urgent care for heavy bleeding, severe abdominal or pelvic pain, or sudden unexplained weight loss.',
  causes_body_en = '## Risk Factors
- Previous cervical cancer, HIV infection, or a weakened immune system
- Exposure to diethylstilbestrol (DES) in pregnancy
- After a hysterectomy, the need for screening depends on whether the cervix was removed, the reason for surgery, and any history of high-grade changes or cancer
- The HPV vaccine protects against most high-risk HPV types linked to cancer, but not all. Vaccinated women should still follow screening advice.',
  diagnosis_body_en = '## Screening Tests
- Pap smear: checks cervical cells for abnormal changes
- HPV test: checks for high-risk HPV types linked to cancer. The same sample is often used for both tests.
- WHO recommends HPV DNA testing over visual inspection with acetic acid (VIA) or cytology. Self-collected samples can also be used.
- WHO general schedule: start at age 30, HPV DNA test every 5 to 10 years. For women living with HIV: start at 25, every 3 to 5 years.
- ACOG (United States) guidance starts at 21 with a different schedule. Clinical advice in Bangladesh should follow the local clinical guidance.

## Treatment and Next Steps
- An abnormal result leads to colposcopy to look closely at the cervix, and a biopsy if needed
- WHO advises that women who screen positive should receive prompt treatment or referral
- Treatment depends on the type and extent of change, so discuss the results with your doctor

## Screening advice in Khulna
For routine screening or after an abnormal result, discuss with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Sources: ACOG, WHO.'
WHERE slug = 'gynae-cancer-screening-need';
-- v63 meta title length fix: titles kept under 60 characters
UPDATE conditions SET meta_title_en = 'Hormone Gynae Problems in Khulna | Dr. Jebunnesa Jebu' WHERE slug = 'hormonal-imbalance';
UPDATE conditions SET meta_title = 'উচ্চ ঝুঁকি গর্ভাবস্থা চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'high-risk-pregnancy';
UPDATE conditions SET meta_title_en = 'Recurrent Miscarriage in Khulna | Dr. Jebunnesa Jebu' WHERE slug = 'recurrent-miscarriage';
UPDATE conditions SET meta_title = 'দীর্ঘস্থায়ী শ্রোণী ব্যথা, খুলনা | ডাঃ জেবুন্নেসা জেবু', meta_title_en = 'Chronic Pelvic Pain in Khulna | Dr. Jebunnesa Jebu' WHERE slug = 'pelvic-pain-syndrome';
UPDATE conditions SET meta_title = 'অতিরিক্ত মাসিক রক্তস্রাব, খুলনা | ডাঃ জেবুন্নেসা জেবু', meta_title_en = 'Heavy Menstrual Bleeding in Khulna | Dr. Jebunnesa Jebu' WHERE slug = 'heavy-menstrual-bleeding';
UPDATE conditions SET meta_title = 'মাসিক বন্ধ বা দেরিতে মাসিক, খুলনা | ডাঃ জেবুন্নেসা জেবু', meta_title_en = 'Absent or Delayed Periods in Khulna | Dr. Jebunnesa Jebu' WHERE slug = 'delayed-absent-periods';
UPDATE conditions SET meta_title = 'ডিম্বাশয়ের সঞ্চয় কমা, খুলনা | ডাঃ জেবুন্নেসা জেবু' WHERE slug = 'ovarian-function-weakness';
UPDATE conditions SET meta_title_en = 'Ovulation Disorders in Khulna | Dr. Jebunnesa Jebu' WHERE slug = 'ovulation-disorder';
-- v63 part C: services, specialties, treatments and procedures overviews, dual language (draft for doctor review)
-- General descriptions only. No fees, packages, success rates or clinic-specific equipment claims.

-- ===== PROCEDURES =====
UPDATE procedures SET
  meta_title = 'ল্যাপারোস্কোপিক সার্জারি খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'ল্যাপারোস্কোপিক (কিহোল) সার্জারি কী, কখন লাগে, কী আশা করবেন ও পুনরুদ্ধার। খুলনায় গাইনী চেম্বারে পরামর্শ নিন।',
  meta_title_en = 'Laparoscopic Surgery in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Laparoscopic (keyhole) surgery in gynaecology: what it is, when it is used, recovery and risks. Consult Dr. Jebunnesa Jebu in Khulna.',
  overview_body = '## ল্যাপারোস্কোপিক সার্জারি কী?
ল্যাপারোস্কোপি বা কিহোল সার্জারিতে পেট বা শ্রোণীতে ছোট ছোট কাটা দিয়ে একটি ক্যামেরা ঢুকিয়ে ভেতরের অবস্থা দেখা হয় এবং প্রয়োজনে চিকিৎসা করা হয়। এটি সাধারণত সাধারণ অ্যানেস্থেশিয়ায় করা হয়।

গাইনোকোলজিতে এটি এন্ডোমেট্রিওসিস, শ্রোণী সংক্রমণ, টিউবে গর্ভধারণ এবং ফাইব্রয়েডের মতো সমস্যা নির্ণয় বা চিকিৎসায় ব্যবহৃত হয়। বড় অস্ত্রোপচারের তুলনায় সাধারণত দ্রুত সেরে ওঠা যায়।

## কখন দরকার হয়
- রোগ নির্ণয়ের জন্য (ডায়াগনস্টিক ল্যাপারোস্কোপি)
- ডিম্বাশয় বা জরায়ুর সমস্যায়, যেমন ফাইব্রয়েড ও এন্ডোমেট্রিওসিসের চিকিৎসা
- টিউবে গর্ভধারণের চিকিৎসা

## খুলনায় পরামর্শ নিন
অস্ত্রোপচারের প্রয়োজন আছে কি না, তা জানতে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে কথা বলুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## What is laparoscopic surgery?
Laparoscopy, or keyhole surgery, uses small cuts in the abdomen or pelvis and a camera to look inside and, if needed, treat the problem. It is done under general anaesthetic.

In gynaecology it is used to diagnose or treat conditions such as endometriosis, pelvic inflammatory disease, ectopic pregnancy and fibroids. Recovery is usually quicker than after open surgery.

## When it is needed
- For diagnosis (diagnostic laparoscopy)
- For problems of the ovaries or womb, such as fibroids or endometriosis
- For treatment of an ectopic pregnancy

## Consult in Khulna
To find out whether surgery is needed, speak with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'laparoscopic-surgery';

UPDATE procedures SET
  meta_title = 'হিস্টেরোস্কোপি ও এন্ডোস্কোপি, খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'হিস্টেরোস্কোপি ও এন্ডোস্কোপিক গাইনী সার্জারি কী, কখন লাগে। জরায়ুর ভেতরের সমস্যায় খুলনায় বিশেষজ্ঞের পরামর্শ নিন।',
  meta_title_en = 'Hysteroscopy Surgery in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Hysteroscopy and endoscopic gynaecological surgery: what they are and when they are used for problems inside the womb. Dr. Jebunnesa Jebu, Khulna.',
  overview_body = '## হিস্টেরোস্কোপি ও এন্ডোস্কোপিক গাইনী সার্জারি কী?
হিস্টেরোস্কোপি হলো একটি পাতলা, আলোকিত ক্যামেরা জরায়ুমুখ দিয়ে জরায়ুর ভেতরে ঢুকিয়ে ভেতরের অবস্থা দেখার পদ্ধতি। একই যন্ত্র দিয়ে প্রয়োজনে জরায়ুর ভেতরের পলিপ বা ছোট টিস্যু অপসারণও করা যায়।

এন্ডোস্কোপিক গাইনী সার্জারি মানে এই ধরনের ক্যামেরা ও যন্ত্রের সাহায্যে অস্ত্রোপচার, যেখানে বড় কাটার প্রয়োজন হয় না। প্রতিটি রোগীর জন্য উপযুক্ততা ও পদ্ধতি আলাদা হয়।

## কখন প্রয়োজন হতে পারে
- অস্বাভাবিক রক্তস্রাবের কারণ খোঁজা
- জরায়ুর ভেতরের পলিপ বা ফাইব্রয়েড অপসারণ
- বারবার গর্ভপাত বা বন্ধ্যাত্বে জরায়ুর ভেতর পরীক্ষা

## খুলনায় পরামর্শ নিন
পদ্ধতিটি আপনার জন্য উপযুক্ত কি না, তা নির্ধারণ করতে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: Mayo Clinic।',
  overview_body_en = '## What are hysteroscopy and endoscopic gynaecological surgery?
Hysteroscopy passes a thin, lighted camera through the cervix into the womb so the inside can be seen. The same instrument can remove a polyp or small tissue from inside the womb when needed.

Endoscopic gynaecological surgery means surgery done with a camera and instruments without large cuts. The suitable method depends on each patient.

## When it may be needed
- To find the cause of abnormal bleeding
- To remove polyps or small growths inside the womb
- To examine the inside of the womb when there are repeated miscarriages or infertility

## Consult in Khulna
To decide whether this procedure suits you, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: Mayo Clinic.'
WHERE slug = 'endoscopic-gynae-surgery';

UPDATE procedures SET
  meta_title = 'নরমাল ডেলিভারি খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'স্বাভাবিক (নরমাল) প্রসবের ধাপ, লেবার কীভাবে হয়, প্রসবের পর কী হয়। খুলনায় গর্ভাবস্থা ও প্রসবের যত্নে পরামর্শ নিন।',
  meta_title_en = 'Normal Delivery in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Normal vaginal delivery: the stages of labour, what happens after birth and how to prepare. Pregnancy and delivery care in Khulna.',
  overview_body = '## স্বাভাবিক (নরমাল) ডেলিভারি কী?
স্বাভাবিক প্রসব মানে যোনিপথ দিয়ে শিশুর জন্ম। লেবার বা প্রসববেদনা সাধারণত তিনটি ধাপে হয়: জরায়ুমুখ খোলা, শিশুকে বের করা, এবং প্ল্যাসেন্টা বের হওয়া।

প্রথম ধাপ (জরায়ুমুখ ৬ সেন্টিমিটারের কম) কয়েক ঘণ্টা থেকে কয়েক দিন লাগতে পারে। সক্রিয় লেবার (৬ থেকে ১০ সেন্টিমিটার) সাধারণত ৪ থেকে ৮ ঘণ্টা বা তার বেশি লাগে। শিশুকে বের করতে কয়েক মিনিট থেকে কয়েক ঘণ্টা লাগতে পারে।

## প্রসবের পরে
- প্ল্যাসেন্টা সাধারণত ৩০ মিনিটের মধ্যে বের হয়
- জরায়ু সংকুচিত হতে থাকে, এবং কোনো ছিঁড়ে গেলে চিকিৎসক তা দেখে সেলাই করেন
- স্তন্যদান উৎসাহিত করা হয়

স্বাভাবিক প্রসবে যোনি ছিঁড়ে যাওয়া সাধারণ ঘটনা। অধিক ছিঁড়লে সংক্রমণ বা মল-মূত্র ধরে রাখতে সমস্যা হতে পারে।

## খুলনায় পরামর্শ নিন
প্রসবের পরিকল্পনা ও গর্ভাবস্থার নিয়মিত যত্নের জন্য খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: Mayo Clinic।',
  overview_body_en = '## What is a normal (vaginal) delivery?
A normal delivery is the birth of the baby through the vagina. Labour usually has three stages: the cervix opens, the baby is pushed out, and the placenta is delivered.

The first stage, when the cervix is under 6 cm, can last from hours to days. Active labour (6 to 10 cm) often lasts 4 to 8 hours or more. Pushing and birth take from a few minutes to a few hours.

## After birth
- The placenta is usually delivered within 30 minutes
- The womb contracts, and any tear is checked and repaired by the clinician
- Breastfeeding is encouraged

Tears during a vaginal birth are common. Severe tears can lead to infection or difficulty controlling urine or stool.

## Consult in Khulna
For birth planning and regular care during pregnancy, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: Mayo Clinic.'
WHERE slug = 'normal-delivery';

UPDATE procedures SET
  meta_title = 'সিজারিয়ান ডেলিভারি খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'সিজারিয়ান সেকশন কী, কখন প্রয়োজন হয়, অস্ত্রোপচারের পর কী আশা করবেন ও ঝুঁকি। খুলনায় প্রসূতি বিশেষজ্ঞের পরামর্শ নিন।',
  meta_title_en = 'Caesarean Delivery in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Caesarean section: when it is needed, what to expect and the risks. Obstetric advice from Dr. Jebunnesa Jebu in Khulna.',
  overview_body = '## সিজারিয়ান সেকশন কী?
সিজারিয়ান সেকশন হলো পেটের নিচের অংশ ও জরায়ুতে কাটা দিয়ে শিশুর জন্ম দেওয়ার অস্ত্রোপচার। NHS-এর মতে এটি একটি বড় অস্ত্রোপচার, যা সাধারণত তখনই করা হয় যখন এটি সবচেয়ে নিরাপদ বিকল্প।

## কখন প্রয়োজন হয়
- পরিকল্পিতভাবে: শিশু উল্টো অবস্থায় থাকলে ও ঘোরানো না গেলে, প্ল্যাসেন্টা নিচে থাকলে, প্রি-ইক্লাম্পসিয়া থাকলে, কিছু সংক্রমণে, অথবা ঝুঁকি বুঝে মায়ের পছন্দে
- জরুরিভাবে: শিশুর অক্সিজেন সরবরাহ কমে গেলে, লেবার অগ্রসর না হলে, বা অতিরিক্ত রক্তস্রাব হলে

## কী আশা করবেন
- সাধারণত স্পাইনাল বা এপিডুরাল অ্যানেস্থেশিয়া দেওয়া হয়
- অস্ত্রোপচার সাধারণত ৪০ থেকে ৫০ মিনিট সময় নেয়
- জটিলতা না হলে বেশির ভাগ নারী ১ থেকে ২ দিনের মধ্যে বাড়ি ফেরেন
- পুনরুদ্ধার সাধারণত স্বাভাবিক প্রসবের চেয়ে বেশি সময় নেয়

## ঝুঁকি
ক্ষতস্থানে বা জরায়ুতে সংক্রমণ, রক্ত জমাট বাঁধা, অতিরিক্ত রক্তক্ষরণ, মূত্রথলি বা আশপাশের অঙ্গে আঘাত। শিশুর ক্ষেত্রে অল্প সময়ের জন্য শ্বাসকষ্ট হতে পারে।

## খুলনায় পরামর্শ নিন
সিজারিয়ান বা স্বাভাবিক প্রসব, কোনটি আপনার জন্য উপযুক্ত তা নিয়ে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে আলোচনা করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## What is a caesarean section?
A caesarean section is an operation to deliver a baby through a cut in the lower abdomen and the womb. The NHS describes it as a major operation, usually done when it is the safest option.

## When it is needed
- Planned: a breech baby that cannot be turned, a low-lying placenta, pre-eclampsia, certain infections, or the mother''s choice after discussing the risks
- Urgent: poor oxygen supply to the baby, labour that is not progressing, or heavy bleeding

## What to expect
- Usually spinal or epidural anaesthesia
- The operation usually takes 40 to 50 minutes
- Without complications, most women go home 1 to 2 days later
- Recovery is usually longer than after a vaginal birth

## Risks
Infection of the wound or womb, blood clots, heavy bleeding, and injury to the bladder or nearby organs. The baby may have temporary breathing difficulty.

## Consult in Khulna
To decide whether a caesarean or a vaginal birth suits you, discuss it with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'c-section';

UPDATE procedures SET
  meta_title = 'কলপোস্কোপি পরীক্ষা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'কলপোস্কোপি কী, কখন করা হয়, পরীক্ষার সময় কী হয় এবং ফলাফল কতদিনে আসে। অস্বাভাবিক স্ক্রিনিং ফলে খুলনায় পরামর্শ নিন।',
  meta_title_en = 'Colposcopy Test in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Colposcopy: what the test is, when it is done, what happens during it and how long results take. Consult Dr. Jebunnesa Jebu in Khulna.',
  overview_body = '## কলপোস্কোপি কী?
কলপোস্কোপি হলো একটি মাইক্রোস্কোপ ও আলো দিয়ে জরায়ুমুখ আরও কাছ থেকে দেখার পরীক্ষা। সাধারণত স্ক্রিনিংয়ে অস্বাভাবিক কোষ ধরা পড়লে এটি করা হয়।

## কখন করা হয়
- স্ক্রিনিং পরীক্ষায় এইচপিভি-সংশ্লিষ্ট অস্বাভাবিক কোষ পাওয়া গেলে
- পরপর দুটি পরীক্ষার ফল অনিশ্চিত হলে
- অস্বাভাবিক রক্তস্রাব, সহবাসের পর রক্তস্রাব সহ উপসর্গের কারণ খুঁজতে

## পরীক্ষার সময় কী হয়
- সাধারণত একজন প্রশিক্ষিত নার্স বা চিকিৎসক করেন, আপনি স্পেকুলাম নিয়ে শোয়া অবস্থায় থাকেন
- জরায়ুমুখে একটি দ্রবণ লাগানো হলে ঝিনঝিনে বা জ্বালাপোড়া অনুভূত হতে পারে
- প্রয়োজনে ছোট বায়োপসি নেওয়া হয়; ফলাফল আসতে ৪ থেকে ৮ সপ্তাহ লাগে
- পরীক্ষা সাধারণত ১৫ থেকে ২০ মিনিট চলে, এবং পরে বাড়ি যাওয়া যায়
- পরীক্ষার আগের ২৪ ঘণ্টা প্রবেশমূলক যৌন মিলন ও যোনিতে কোনো পণ্য ব্যবহার এড়িয়ে চলুন

## খুলনায় পরামর্শ নিন
স্ক্রিনিংয়ের ফলাফল অস্বাভাবিক হলে বা উপসর্গ থাকলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## What is colposcopy?
Colposcopy is a test that looks more closely at the cervix using a microscope with a light. It is usually done after screening finds abnormal cells.

## When it is done
- After screening finds abnormal cells linked to HPV
- When two screening results in a row are unclear
- To find the cause of symptoms such as unusual bleeding or bleeding after sex

## What happens during the test
- It is done by a trained nurse or doctor, with you lying with a speculum in place
- A solution on the cervix may cause tingling or stinging
- A small biopsy may be taken; results take 4 to 8 weeks
- The test usually takes 15 to 20 minutes, and you can normally go home afterwards
- For 24 hours before, avoid penetrative sex and vaginal products

## Consult in Khulna
If your screening result is abnormal or you have symptoms, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'colposcopy';

-- ===== TREATMENTS =====
UPDATE treatments SET
  meta_title = 'IVF ও কৃত্রিম প্রজনন চিকিৎসা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'IVF, ICSI ও IUI কী, প্রক্রিয়া কেমন, কার জন্য উপযুক্ত এবং ঝুঁকি। খুলনায় বন্ধ্যাত্ব বিশেষজ্ঞ ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন।',
  meta_title_en = 'IVF and Assisted Reproduction, Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'IVF, ICSI and IUI explained: how they work, who they suit and the risks. Infertility specialist advice from Dr. Jebunnesa Jebu in Khulna.',
  overview_body = '## IVF ও কৃত্রিম প্রজনন (ART) কী?
সহায়ক প্রজনন প্রযুক্তি বা ART-এর মধ্যে IVF সবচেয়ে বেশি ব্যবহৃত। IVF-এ ওষুধ দিয়ে ডিম্বাশয় উদ্দীপ্ত করে একাধিক ডিম্বাণু সংগ্রহ করা হয়, ল্যাবে শুক্রাণুর সঙ্গে নিষিক্ত করা হয়, এবং তৈরি ভ্রূণ জরায়ুতে স্থানান্তর করা হয়।

ICSI-তে একটি শুক্রাণু সরাসরি একটি ডিম্বাণুতে প্রবেশ করানো হয়। IUI-তে প্রস্তুত শুক্রাণু ডিম্বস্ফোটনের সময়ের কাছাকাছি জরায়ুর ভেতরে দেওয়া হয়।

## কার জন্য বিবেচনা করা হয়
- ফ্যালোপিয়ান টিউবের ক্ষতি, ডিম্বস্ফোটনের সমস্যা, এন্ডোমেট্রিওসিস, ফাইব্রয়েড বা শুক্রাণুর সমস্যা
- অব্যাখ্যাত বন্ধ্যাত্ব
- বয়স বেশি হলে, যেখানে IVF প্রধান চিকিৎসা হিসেবে বিবেচিত হতে পারে
- জেনেটিক সমস্যা এড়াতে ভ্রূণের জেনেটিক পরীক্ষা, বা চিকিৎসার আগে প্রজনন ক্ষমতা সংরক্ষণ

## প্রক্রিয়া ও আশা করণীয়
- ডিম্বাশয় উদ্দীপনার ওষুধ, আল্ট্রাসাউন্ড ও রক্ত পরীক্ষায় অগ্রগতি দেখা হয়
- ডিম্বাণু সংগ্রহ সাধারণত সেডেশনে আল্ট্রাসাউন্ড-নির্দেশিত সূঁচের মাধ্যমে
- ডিম্বাণু সংগ্রহের ২ থেকে ৬ দিন পরে ভ্রূণ স্থানান্তর; গর্ভধারণ পরীক্ষা অন্তত ১২ দিন পরে
- একটি চক্র সাধারণত ২ থেকে ৩ সপ্তাহ লাগে, একাধিক চক্র লাগতে পারে
- IUI কয়েক মিনিটের প্রক্রিয়া, সাধারণত ব্যথানাশক লাগে না

## ঝুঁকি
- মানসিক ও আর্থিক চাপ
- ডিম্বাণু সংগ্রহে রক্তক্ষরণ, সংক্রমণ, বা অঙ্গে আঘাত
- ওভারিয়ান হাইপারস্টিমুলেশন সিনড্রোম
- গর্ভপাত (বয়সের সঙ্গে ঝুঁকি বাড়ে) বা টিউবে গর্ভধারণ
- একাধিক গর্ভ, যা উচ্চ রক্তচাপ, ডায়াবেটিস ও আগাম প্রসবের ঝুঁকি বাড়ায়

## খুলনায় পরামর্শ নিন
IVF বা IUI উপযুক্ত কি না, তা নির্ধারণের জন্য খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে স্বামী-স্ত্রী দুজনের রিপোর্ট নিয়ে আসুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: Mayo Clinic।',
  overview_body_en = '## What are IVF and assisted reproductive technology (ART)?
IVF is the most widely used form of assisted reproductive technology. Medicines stimulate the ovaries to produce several eggs, which are collected and fertilised with sperm in the laboratory. The resulting embryo is then transferred into the womb.

ICSI injects a single sperm directly into an egg. IUI places prepared sperm into the womb close to the time of ovulation.

## Who may be considered
- Tubal damage, ovulation disorders, endometriosis, fibroids or sperm problems
- Unexplained infertility
- Older age, when IVF may be offered as the main treatment
- Embryo genetic testing for inherited conditions, or fertility preservation before treatments that can affect fertility

## What to expect
- Hormone medicines stimulate the ovaries, with ultrasound and blood tests to monitor progress
- Egg collection is usually done under sedation, guided by ultrasound
- Embryo transfer is usually 2 to 6 days after egg collection; a pregnancy blood test is done at least 12 days after
- One cycle takes about 2 to 3 weeks, and more than one cycle may be needed
- IUI takes a few minutes and usually needs no pain relief

## Risks
- Emotional and financial stress
- Bleeding, infection or injury to nearby organs during egg collection
- Ovarian hyperstimulation syndrome
- Miscarriage, which becomes more likely with age, or ectopic pregnancy
- Multiple pregnancy, which raises the risk of high blood pressure, diabetes and early birth

## Consult in Khulna
To find out whether IVF or IUI suits you, bring both partners'' reports to Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: Mayo Clinic.'
WHERE slug = 'ivf-art';

UPDATE treatments SET
  meta_title = 'প্রি-ন্যাটাল কেয়ার, খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'গর্ভাবস্থায় নিয়মিত চেকআপের সময়সূচি, স্ক্যান ও পরীক্ষা কখন হয়। খুলনায় গাইনী ও প্রসূতি চেম্বারে নিয়মিত যত্ন নিন।',
  meta_title_en = 'Pregnancy and Antenatal Care in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Antenatal care schedule in pregnancy: check-ups, scans and tests and when they happen. Regular obstetric care at the chamber in Khulna.',
  overview_body = '## প্রি-ন্যাটাল (গর্ভকালীন) কেয়ার কী?
প্রি-ন্যাটাল কেয়ার বা গর্ভকালীন যত্ন হলো গর্ভাবস্থায় নির্ধারিত সময়ে চেকআপ, স্ক্যান ও পরীক্ষার ধারাবাহিক ব্যবস্থা। সাধারণত মিডওয়াইফ এবং প্রয়োজনে চিকিৎসক এটি দেখাশোনা করেন।

NHS-এর তথ্য অনুযায়ী, প্রথম সন্তানের ক্ষেত্রে কমপক্ষে ১০টি অ্যাপয়েন্টমেন্ট ও ২টি স্ক্যান, এবং আগে সন্তান হলে কমপক্ষে ৭টি অ্যাপয়েন্টমেন্ট থাকে।

## সময়সূচির সাধারণ রূপ
- ৮ থেকে ১২ সপ্তাহ: বুকিং অ্যাপয়েন্টমেন্ট, রক্ত পরীক্ষা ও মাতৃত্বকালীন নথি
- ১১ থেকে ১৪ সপ্তাহ: ডেটিং স্ক্যান
- ১৮ থেকে ২১ সপ্তাহ: ২০ সপ্তাহের স্ক্যান
- ২৪ থেকে ২৮ সপ্তাহ: ঝুঁকি থাকলে গর্ভকালীন ডায়াবেটিস পরীক্ষা
- ৩৬ সপ্তাহ থেকে: শিশুর অবস্থান ও প্রসবের প্রস্তুতি

প্রায় প্রতিটি চেকআপে রক্তচাপ, প্রস্রাব, পেটের মাপ, শিশুর নড়াচড়া ও মানসিক স্বাস্থ্য দেখা হয়।

## খুলনায় পরামর্শ নিন
গর্ভধারণ নিশ্চিত হলেই নিয়মিত যত্ন শুরু করা ভালো। খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর কাছে বুকিং করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## What is antenatal (prenatal) care?
Antenatal care is a planned series of check-ups, scans and tests during pregnancy. It is usually led by a midwife, with a doctor when needed.

According to the NHS, a first pregnancy usually includes at least 10 appointments and 2 scans, and a later pregnancy at least 7 appointments.

## Typical schedule
- 8 to 12 weeks: booking appointment, blood tests and maternity notes
- 11 to 14 weeks: dating scan
- 18 to 21 weeks: 20-week scan
- 24 to 28 weeks: gestational diabetes test if at higher risk
- From 36 weeks: baby''s position and preparation for birth

Most check-ups include blood pressure, urine, bump measurement, baby movements and emotional wellbeing.

## Consult in Khulna
It is best to start regular care as soon as pregnancy is confirmed. Book with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'prenatal-pregnancy-care';

UPDATE treatments SET
  meta_title = 'গর্ভধারণ-পূর্ব পরামর্শ, খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'গর্ভধারণের আগে কী করবেন, ফার্টিলিটি মূল্যায়ন কীভাবে হয় এবং কখন চেষ্টা শুরু করলে কখন দেখাবেন। খুলনায় পরামর্শ নিন।',
  meta_title_en = 'Preconception Counselling in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Preconception counselling and fertility evaluation: health steps before pregnancy, tests and when to seek help. Consult Dr. Jebunnesa Jebu in Khulna.',
  overview_body = '## গর্ভধারণ-পূর্ব পরামর্শ কী?
গর্ভধারণের পরিকল্পনা করার আগে স্বাস্থ্যের কিছু বিষয় দেখে নেওয়াকে গর্ভধারণ-পূর্ব পরামর্শ বলা হয়। এতে থাকে টিকা, সার্ভিক্যাল স্ক্রিনিংয়ের অবস্থা, যৌনবাহিত সংক্রমণের পরীক্ষা (ঝুঁকি থাকলে), ওজন ও জীবনযাত্রা।

ফোলিক অ্যাসিড ও ভিটামিন ডি সুস্থ গর্ভাবস্থায় সহায়ক হতে পারে। তবে নিয়মিত ওষুধ থাকলে চিকিৎসকের পরামর্শ ছাড়া বন্ধ করবেন না।

## কখন পরামর্শ নেবেন
- এক বছর বা তার বেশি সময় ধরে গর্ভনিরোধক ছাড়া চেষ্টা করেও গর্ভধারণ না হলে
- এন্ডোমেট্রিওসিস বা কম শুক্রাণু সংখ্যার মতো পরিচিত সমস্যা থাকলে আরও আগে
- ধূমপান, মদ্যপান বা মাদক ত্যাগের সহায়তা প্রয়োজন হলে

## ফার্টিলিটি মূল্যায়ন
নারীর ক্ষেত্রে পেলভিক পরীক্ষা, হরমোন ও ডিম্বাশয়ের সঞ্চয়ের রক্ত পরীক্ষা, এইচএসজি এবং পেলভিক আল্ট্রাসাউন্ড করা হতে পারে। সবার সব পরীক্ষা লাগে না; চিকিৎসক প্রয়োজন অনুযায়ী ঠিক করেন।

ওজন কম বা বেশি থাকলে গর্ভধারণে অসুবিধা হতে পারে।

## খুলনায় পরামর্শ নিন
গর্ভধারণের পরিকল্পনা নিয়ে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে কথা বলুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK, Mayo Clinic।',
  overview_body_en = '## What is preconception counselling?
Preconception counselling means checking key health matters before you try for a baby. This can include vaccinations, the status of cervical screening, testing for sexually transmitted infections if at risk, weight and lifestyle.

Folic acid and vitamin D can support a healthy pregnancy. Do not stop any prescribed medicine without medical advice.

## When to seek advice
- After a year or more of trying without contraception and without a pregnancy
- Earlier if you have a known condition such as endometriosis or low sperm count
- If you need support to stop smoking, drinking or using drugs

## Fertility evaluation
For women, this may include a pelvic examination, hormone and ovarian reserve blood tests, hysterosalpingography and pelvic ultrasound. Not everyone needs every test; the doctor decides what is needed.

Being underweight or overweight can make conception harder.

## Consult in Khulna
To discuss your plans for pregnancy, speak with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Sources: NHS UK, Mayo Clinic.'
WHERE slug = 'fertility-counselling';

UPDATE treatments SET
  meta_title = 'পরিবার পরিকল্পনা, খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'গর্ভনিরোধক পদ্ধতি, কোনটি কার জন্য উপযুক্ত এবং জরুরি গর্ভনিরোধ। খুলনায় পরিবার পরিকল্পনা ও প্রজনন স্বাস্থ্যে পরামর্শ নিন।',
  meta_title_en = 'Family Planning in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Family planning and reproductive health: contraception options, emergency contraception and protection from infections. Advice in Khulna.',
  overview_body = '## পরিবার পরিকল্পনা ও প্রজনন স্বাস্থ্য কী?
পরিবার পরিকল্পনা মানে কখন ও কতগুলো সন্তান নেবেন তা নিজের স্বাস্থ্য ও পরিস্থিতি বুঝে ঠিক করা। এর জন্য গর্ভনিরোধক পদ্ধতি ব্যবহার করা হয়।

গর্ভনিরোধক পদ্ধতিগুলো মোটামুটি এভাবে ভাগ করা যায়: দীর্ঘস্থায়ী (লং-অ্যাক্টিং), স্বল্পমেয়াদি হরমোনাল, বাধা (ব্যারিয়ার), স্থায়ী এবং জরুরি পদ্ধতি। এছাড়া মাসিক চক্র অনুসরণ করে এড়িয়ে চলার পদ্ধতিও আছে।

## পদ্ধতির ধরন
- দীর্ঘস্থায়ী: তামার আইইউডি (হরমোনমুক্ত), হরমোনাল আইইউএস, বাহুর ইমপ্ল্যান্ট
- স্বল্পমেয়াদি হরমোনাল: সম্মিলিত বড়ি, প্রোজেস্টোজেন-শুধু বড়ি, ইনজেকশন, প্যাচ, যোনি রিং
- বাধা: কনডম, ভেতরের কনডম, ডায়াফ্রাম বা ক্যাপ
- স্থায়ী: নারীর টিউব বন্ধ করা বা পুরুষের ভ্যাসেক্টমি (ফিরিয়ে আনা কঠিন)

কনডম ও ভেতরের কনডম যৌনবাহিত সংক্রমণ থেকে সুরক্ষা দেয়। জরুরি গর্ভনিরোধক বড়ি সহবাসের ৩ বা ৫ দিনের মধ্যে নিতে হয়, ধরন অনুযায়ী। তামার আইইউডিও জরুরি গর্ভনিরোধ হিসেবে ব্যবহার করা যায়।

## খুলনায় পরামর্শ নিন
আপনার স্বাস্থ্য ও পরিকল্পনার জন্য উপযুক্ত পদ্ধতি বেছে নিতে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## What are family planning and reproductive health?
Family planning means deciding when and how many children to have, based on your health and circumstances. Contraception is used to achieve this.

Contraceptive methods are broadly grouped as long-acting, short-acting hormonal, barrier, permanent and emergency. Natural family planning, which tracks the menstrual cycle to avoid sex around ovulation, is another approach.

## Method types
- Long-acting: copper coil (hormone-free), hormonal intrauterine system, arm implant
- Short-acting hormonal: combined pill, progestogen-only pill, injection, patch, vaginal ring
- Barrier: condoms, internal condoms, diaphragm or cap
- Permanent: female sterilisation, or a vasectomy for men (hard to reverse)

Condoms and internal condoms help protect against sexually transmitted infections. Emergency contraceptive pills must be taken within 3 or 5 days after sex, depending on the type. A copper coil can also be used as emergency contraception.

## Consult in Khulna
To choose a method that suits your health and plans, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'family-planning-reproductive-health';

-- ===== SERVICES =====
UPDATE services SET
  meta_title = 'বন্ধ্যাত্ব চিকিৎসা প্রোগ্রাম খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'বন্ধ্যাত্বের পরীক্ষা থেকে চিকিৎসার ধাপ: ওষুধ, IUI, সার্জারি ও IVF। খুলনায় বন্ধ্যাত্ব বিশেষজ্ঞ ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন।',
  meta_title_en = 'Infertility Treatment in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Infertility evaluation and treatment pathway in Khulna: tests for both partners, medicines, IUI, surgery and IVF. Dr. Jebunnesa Jebu.',
  overview_body = '## বন্ধ্যাত্ব চিকিৎসার ধাপ
বন্ধ্যাত্ব চিকিৎসা শুরু হয় স্বামী ও স্ত্রী উভয়ের মূল্যায়ন দিয়ে। কখনো কখনো এক বছর বা তার বেশি সময় নিয়মিত চেষ্টার পরেই পরামর্শ নেওয়া উচিত। তবে পরিচিত সমস্যা থাকলে আরও আগে যোগাযোগ করা যায়।

## মূল্যায়ন
- নারীর ক্ষেত্রে: পেলভিক পরীক্ষা, হরমোন ও ডিম্বাশয়ের সঞ্চয়ের রক্ত পরীক্ষা, এইচএসজি, পেলভিক আল্ট্রাসাউন্ড; কম ক্ষেত্রে হিস্টেরোস্কোপি বা ল্যাপারোস্কোপি
- পুরুষের ক্ষেত্রে: সিমেন অ্যানালাইসিস, হরমোন ও জেনেটিক পরীক্ষা, ইমেজিং

## চিকিৎসার বিকল্প
- ডিম্বস্ফোটনের ওষুধ
- IUI (ইন্ট্রাইউটেরাইন ইনসেমিনেশন)
- অস্ত্রোপচার: জরায়ু বা শ্রোণীর সমস্যায়
- IVF এবং ICSI, যা সবচেয়ে কার্যকর সহায়ক প্রজনন পদ্ধতি হিসেবে ব্যবহৃত হয়

ক্লিনিক্যাল মূল্যায়ন অনুযায়ী কোন ধাপ উপযুক্ত, তা চিকিৎসক ঠিক করেন। প্রতিটি দম্পতির জন্য পথ আলাদা হতে পারে।

## খুলনায় শুরু করুন
খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে বন্ধ্যাত্বের প্রথম ধাপের পরামর্শ নিন এবং সব পূর্ববর্তী রিপোর্ট সঙ্গে আনুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: Mayo Clinic, NHS UK।',
  overview_body_en = '## Infertility treatment pathway
Infertility care starts with an assessment of both partners. Many couples are advised to seek help after a year or more of regular attempts, but sooner if there is a known problem.

## Assessment
- For women: pelvic examination, blood tests for hormones and ovarian reserve, hysterosalpingography, pelvic ultrasound; hysteroscopy or laparoscopy only in some cases
- For men: semen analysis, hormone and genetic tests, imaging when needed

## Treatment options
- Ovulation-stimulating medicines
- IUI (intrauterine insemination)
- Surgery for womb or pelvic problems
- IVF and ICSI, the most widely used assisted reproduction methods

The right step is decided by the clinical assessment, and the pathway can be different for each couple.

## Start in Khulna
Begin with a first consultation with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna, and bring all previous reports. [Book an appointment](/appointment).

Sources: Mayo Clinic, NHS UK.'
WHERE slug = 'infertility-treatment-program';

UPDATE services SET
  meta_title = 'গর্ভাবস্থা ও প্রসব সেবা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'গর্ভকালীন নিয়মিত যত্ন, প্রসবের সময়ের সেবা ও প্রসবের পরের পরিচর্যা। খুলনায় গাইনী ও প্রসূতি বিশেষজ্ঞের পরামর্শ নিন।',
  meta_title_en = 'Pregnancy and Delivery Care in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Pregnancy and delivery care in Khulna: regular antenatal check-ups, labour and birth support, and post-delivery care with Dr. Jebunnesa Jebu.',
  overview_body = '## গর্ভাবস্থা ও প্রসব সেবা
গর্ভাবস্থা থেকে প্রসব পর্যন্ত ধারাবাহিক যত্ন নিশ্চিত করা এই সেবার লক্ষ্য। এতে থাকে নিয়মিত চেকআপ ও স্ক্যান, প্রসবের পরিকল্পনা, এবং প্রসবের পরের পরিচর্যা।

## সেবার মূল অংশ
- গর্ভাবস্থার শুরু থেকে ৪১ সপ্তাহ পর্যন্ত নিয়মিত চেকআপ ও স্ক্যান
- প্রসবের সময়ের ব্যবস্থাপনা: স্বাভাবিক প্রসব, বা প্রয়োজনে সিজারিয়ান
- ঝুঁকি থাকলে বিশেষ পর্যবেক্ষণ
- প্রসবের পরে মা ও শিশুর প্রাথমিক পরিচর্যা, ব্যথা ও ক্ষত দেখা

স্বাভাবিক প্রসবে যোনি ছিঁড়ে যাওয়া সাধারণ এবং সেটি দেখে সেলাই করা হয়। সিজারিয়ানে সংক্রমণ, রক্ত জমাট বাঁধা ও রক্তক্ষরণের ঝুঁকি থাকে।

## খুলনায় পরামর্শ নিন
গর্ভধারণ নিশ্চিত হলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর কাছে নিয়মিত যত্ন শুরু করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK, Mayo Clinic।',
  overview_body_en = '## Pregnancy and delivery care
This service aims to provide continuous care from pregnancy through to birth. It includes regular check-ups and scans, birth planning, and care after delivery.

## Main parts of the service
- Regular check-ups and scans from early pregnancy up to 41 weeks
- Management of labour and birth: vaginal birth, or caesarean when needed
- Closer monitoring when there are risks
- Early postnatal care for mother and baby, including pain and wound checks

Tears during a vaginal birth are common and are checked and repaired. A caesarean carries risks of infection, blood clots and bleeding.

## Consult in Khulna
Once pregnancy is confirmed, start regular care at the gynaecology and maternity chamber in Khulna with Dr. Jebunnesa Jebu. [Book an appointment](/appointment).

Sources: NHS UK, Mayo Clinic.'
WHERE slug = 'pregnancy-delivery-care';

UPDATE services SET
  meta_title = 'গাইনোকোলজিক্যাল সার্জারি সেবা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'নারীর প্রজনন অঙ্গের অস্ত্রোপচার: কিহোল ও ওপেন সার্জারি, কখন দরকার এবং পুনরুদ্ধার। খুলনায় গাইনী সার্জারির পরামর্শ নিন।',
  meta_title_en = 'Gynaecological Surgery in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Gynaecological surgery: keyhole and open procedures, when they are needed and recovery. Surgical advice from Dr. Jebunnesa Jebu in Khulna.',
  overview_body = '## গাইনোকোলজিক্যাল সার্জারি কী?
নারীর প্রজনন অঙ্গের রোগ নির্ণয় বা চিকিৎসার জন্য করা অস্ত্রোপচারকে গাইনোকোলজিক্যাল সার্জারি বলা হয়। এটি কিহোল (ল্যাপারোস্কোপিক) বা ওপেন পদ্ধতিতে হতে পারে।

## কখন প্রয়োজন হয়
- এন্ডোমেট্রিওসিস, শ্রোণী সংক্রমণ, টিউবে গর্ভধারণ ও ফাইব্রয়েডের চিকিৎসায়
- ভারী মাসিক সাধারণ চিকিৎসায় কাজ না করলে
- অস্বাভাবিক পরীক্ষার ফলের পর প্রয়োজনীয় প্রসিডিউরে

## পুনরুদ্ধার
কিহোল সার্জারি সাধারণত ওপেন সার্জারির চেয়ে দ্রুত সেরে ওঠে। সম্পূর্ণ সুস্থ হতে অস্ত্রোপচারের পর প্রায় ৬ থেকে ৮ সপ্তাহ লাগতে পারে।

## খুলনায় পরামর্শ নিন
অস্ত্রোপচার দরকার কি না এবং কোন পদ্ধতি উপযুক্ত, তা জানতে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## What is gynaecological surgery?
Gynaecological surgery is an operation on the female reproductive organs to diagnose or treat a condition. It can be done by keyhole (laparoscopic) or open methods.

## When it is needed
- To treat endometriosis, pelvic inflammatory disease, ectopic pregnancy or fibroids
- For heavy periods that do not respond to standard medical treatment
- As a follow-up procedure after abnormal screening results

## Recovery
Keyhole surgery usually heals faster than open surgery. Full recovery after surgery can take about 6 to 8 weeks.

## Consult in Khulna
To find out whether surgery is needed and which method suits you, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'gynaecological-surgery-service';

UPDATE services SET
  meta_title = 'মাসিক স্বাস্থ্য পরিচর্যা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'ভারী, ব্যথাযুক্ত বা অনিয়মিত মাসিকের সমস্যার কারণ, পরীক্ষা ও চিকিৎসা। খুলনায় মাসিক স্বাস্থ্যের পরামর্শ নিন।',
  meta_title_en = 'Menstrual Health Care in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Menstrual health care in Khulna: heavy, painful or irregular periods, their causes, tests and treatment. Consult Dr. Jebunnesa Jebu.',
  overview_body = '## মাসিক স্বাস্থ্য পরিচর্যা
মাসিক স্বাস্থ্য পরিচর্যা মানে মাসিক চক্রের নিয়মিততা, রক্তস্রাবের পরিমাণ এবং ব্যথার সমস্যা নিয়ে সঠিক মূল্যায়ন ও চিকিৎসা। ভারী মাসিক অনেক সময় স্বাভাবিক হতে পারে, তবে তা দৈনন্দিন জীবনে প্রভাব ফেললে চিকিৎসা নেওয়া উচিত।

## সাধারণ সমস্যা
- ভারী মাসিক বা ৭ দিনের বেশি রক্তস্রাব
- ব্যথাযুক্ত মাসিক
- অনিয়মিত মাসিক বা মাসিকের মাঝে রক্তস্রাব
- ভারী মাসিকে রক্তস্বল্পতা, যা রক্ত পরীক্ষায় ধরা পড়ে

প্রতি ১ থেকে ২ ঘণ্টায় প্যাড বদলাতে হলে, বা ১০ পয়সার মুদ্রার চেয়ে বড় রক্তের ডেলা গেলে চিকিৎসকের সঙ্গে কথা বলুন।

## চিকিৎসা
ব্যথানাশক ওষুধ, ট্রানেক্সামিক অ্যাসিড, হরমোন চিকিৎসা, রক্তস্বল্পতার জন্য আয়রন। প্রয়োজনে রেফারেল ও বিশেষ পরীক্ষা।

## খুলনায় পরামর্শ নিন
মাসিকের সমস্যায় খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## Menstrual health care
Menstrual health care means proper assessment and treatment of problems with cycle regularity, the amount of bleeding and period pain. Heavy periods can be normal for some women, but treatment is worth considering if they affect daily life.

## Common problems
- Heavy periods, or bleeding lasting more than 7 days
- Painful periods
- Irregular periods, or bleeding between periods
- Anaemia from heavy periods, which blood tests can detect

Speak with a doctor if you need to change a pad every 1 to 2 hours, or pass clots larger than a 10p coin (about 2.5 cm).

## Treatment
Pain relief medicines, tranexamic acid, hormone treatment, and iron for anaemia. Referral and special tests when needed.

## Consult in Khulna
For period problems, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'menstrual-health-care';

UPDATE services SET
  meta_title = 'গাইনী সংক্রমণ ও স্ক্রিনিং সেবা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'জরায়ু ও শ্রোণীর সংক্রমণের চিকিৎসা এবং জরায়ুমুখ স্ক্রিনিং। লক্ষণ থাকলে বা নিয়মিত চেকআপে খুলনায় পরামর্শ নিন।',
  meta_title_en = 'Gynae Infection Care in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Gynaecological infection treatment and cervical screening in Khulna: PID symptoms, urgent warning signs and screening tests. Dr. Jebunnesa Jebu.',
  overview_body = '## গাইনী সংক্রমণ ও স্ক্রিনিং সেবা
এই সেবায় রয়েছে শ্রোণী সংক্রমণ (পিআইডি) সহ প্রজনন অঙ্গের সংক্রমণের চিকিৎসা, এবং জরায়ুমুখের কোষ পরীক্ষার মাধ্যমে স্ক্রিনিং। সময়মতো চিকিৎসা করলে সংক্রমণ সহজেই সারে এবং জটিলতা এড়ানো যায়।

## কখন পরামর্শ নেবেন
- তলপেটের ব্যথা, অস্বাভাবিক স্রাব বা সহবাসের পর রক্তস্রাব
- ৩ দিন অ্যান্টিবায়োটিকের পরও উপসর্গ না কমলে
- নিয়মিত স্ক্রিনিং বা স্ক্রিনিংয়ে অস্বাভাবিক ফল এলে

## জরুরি সতর্কসংকেত
তীব্র বা বাড়তে থাকা শ্রোণী ব্যথা, ভারী রক্তস্রাবের সঙ্গে ব্যথা, খুব বেশি জ্বর বা মাথা ঘোরা থাকলে ৯৯৯ নম্বরে কল করুন বা জরুরি বিভাগে যান।

## খুলনায় পরামর্শ নিন
সংক্রমণের চিকিৎসা বা স্ক্রিনিংয়ের জন্য খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে যোগাযোগ করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## Gynae infection and screening care
This service covers treatment of infections of the reproductive organs, including pelvic inflammatory disease (PID), and screening through cervical cell tests. Early treatment usually clears infections easily and prevents complications.

## When to seek advice
- Lower tummy pain, unusual discharge or bleeding after sex
- Symptoms that do not improve after 3 days of antibiotics
- Routine screening, or an abnormal screening result

## Urgent warning signs
Call 999 or go to A&E for severe or worsening pelvic pain, pain with heavy bleeding, very high fever or dizziness.

## Consult in Khulna
For infection treatment or screening, contact Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'gynae-infection-screening-care';

UPDATE services SET
  meta_title = 'নারী স্বাস্থ্য, কিশোরী থেকে মেনোপজ | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'কিশোরী বয়স থেকে মেনোপজ পর্যন্ত নারীর মাসিক, গর্ভনিরোধ, গর্ভধারণ ও মেনোপজের যত্ন। খুলনায় গাইনী চেম্বারে পরামর্শ নিন।',
  meta_title_en = 'Women''s Health Across Life Stages | Dr. Jebunnesa Jebu',
  meta_description_en = 'Women''s health from adolescence to menopause in Khulna: periods, contraception, pregnancy planning and menopause care. Dr. Jebunnesa Jebu.',
  overview_body = '## কিশোরী থেকে মেনোপজ পর্যন্ত নারী স্বাস্থ্য
নারীর জীবনের প্রতিটি ধাপে স্বাস্থ্যের চাহিদা আলাদা। কৈশোরে মাসিক শুরু ও বয়ঃসন্ধির পরিবর্তন, প্রজনন বয়সে গর্ভনিরোধ ও গর্ভধারণের পরিকল্পনা, এবং পরবর্তীতে মেনোপজের যত্ন।

মেনোপজ সাধারণত ৪৫ থেকে ৫৫ বছরের মধ্যে হয়, তবে আগেও হতে পারে। এই সময় হরমোনের মাত্রা কমে যায় এবং মাসিক বন্ধ হয়।

## মেনোপজের যত্ন
- হরমোন রিপ্লেসমেন্ট থেরাপি (HRT): সাধারণত ইস্ট্রোজেন, জরায়ু থাকলে সঙ্গে প্রোজেস্টোজেন
- হট ফ্লাশ বা মানসিক পরিবর্তনে হরমোনমুক্ত বিকল্প, যেমন কিছু বিষণ্নতার ওষুধ বা কগনিটিভ বিহেভিয়ারাল থেরাপি
- কম যৌন ইচ্ছায় চিকিৎসকের পরামর্শে টেস্টোস্টেরন জেল বা ক্রিম
- ভেষজ প্রতিকারের প্রমাণ খুব কম এবং অন্য ওষুধের সঙ্গে সংঘর্ষ হতে পারে, তাই চিকিৎসকের সঙ্গে আলোচনা করুন

## খুলনায় পরামর্শ নিন
বয়সের প্রতিটি ধাপে নারী স্বাস্থ্য নিয়ে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## Women''s health from adolescence to menopause
A woman''s health needs change at each stage of life. In adolescence there are the first periods and puberty changes. In the reproductive years there are contraception and pregnancy planning. Later comes menopause.

Menopause usually happens between ages 45 and 55, but can happen earlier. Hormone levels fall during this time and periods stop.

## Menopause care
- Hormone replacement therapy (HRT): usually oestrogen, with progestogen added if you still have a womb
- Non-hormonal options for hot flushes or mood changes, such as some antidepressants or cognitive behavioural therapy
- For low libido, testosterone gel or cream may be offered by a doctor
- Herbal remedies have very little evidence and can interact with other medicines, so discuss them with your doctor

## Consult in Khulna
For women''s health at every stage of life, speak with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'womens-lifespan-care';

UPDATE services SET
  meta_title = 'গর্ভকালীন পরিচর্যা, খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'গর্ভকালীন নিয়মিত পরিচর্যা, স্ক্যান ও পরীক্ষার সময়সূচি। খুলনায় গর্ভবতী মায়েদের নিয়মিত যত্নে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন।',
  meta_title_en = 'Antenatal Care Service in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Antenatal care in Khulna: scheduled check-ups, scans and tests during pregnancy. Regular care for expecting mothers with Dr. Jebunnesa Jebu.',
  overview_body = '## অ্যান্টিনেটাল কেয়ার কী?
অ্যান্টিনেটাল কেয়ার হলো গর্ভাবস্থায় পরিকল্পিত অ্যাপয়েন্টমেন্ট, স্ক্যান ও পরীক্ষার ধারাবাহিক ব্যবস্থা। প্রথম সন্তানের ক্ষেত্রে সাধারণত অন্তত ১০টি অ্যাপয়েন্টমেন্ট ও ২টি স্ক্যান, এবং পরের সন্তানে অন্তত ৭টি অ্যাপয়েন্টমেন্ট থাকে।

## সাধারণ সময়সূচি
- ৮ থেকে ১২ সপ্তাহ: বুকিং, রক্ত পরীক্ষা
- ১১ থেকে ১৪ সপ্তাহ: ডেটিং স্ক্যান
- ১৮ থেকে ২১ সপ্তাহ: ২০ সপ্তাহের স্ক্যান
- পরবর্তী চেকআপে: রক্তচাপ, প্রস্রাব, শিশুর অবস্থান ও প্রসবের প্রস্তুতি

সঙ্গীও চাইলে অ্যাপয়েন্টমেন্টে আসতে পারেন।

## খুলনায় পরামর্শ নিন
গর্ভধারণ নিশ্চিত হলে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে নিয়মিত যত্ন শুরু করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## What is antenatal care?
Antenatal care is a planned series of appointments, scans and tests during pregnancy. A first pregnancy usually includes at least 10 appointments and 2 scans, and a later pregnancy at least 7 appointments.

## Typical schedule
- 8 to 12 weeks: booking and blood tests
- 11 to 14 weeks: dating scan
- 18 to 21 weeks: 20-week scan
- Later check-ups: blood pressure, urine, baby''s position and preparation for birth

Your partner is welcome to attend appointments if you wish.

## Consult in Khulna
Once pregnancy is confirmed, start regular care with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'antenatal-care-service';

UPDATE services SET
  meta_title = 'সিজারিয়ান ডেলিভারি সেবা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'সিজারিয়ান ডেলিভারি কখন প্রয়োজন, অস্ত্রোপচারের পর কী আশা করবেন ও ঝুঁকি। খুলনায় প্রসূতি বিশেষজ্ঞের পরামর্শ নিন।',
  meta_title_en = 'Caesarean Delivery Service in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Caesarean delivery service in Khulna: when it is needed, what to expect after surgery and the risks. Obstetric advice from Dr. Jebunnesa Jebu.',
  overview_body = '## সিজারিয়ান ডেলিভারি সেবা
সিজারিয়ান ডেলিভারি হলো পেট ও জরায়ুতে কাটা দিয়ে শিশুর জন্ম। এটি সাধারণত তখনই করা হয় যখন এটি সবচেয়ে নিরাপদ বিকল্প।

পরিকল্পিত সিজারিয়ান দরকার হতে পারে শিশু উল্টো অবস্থায় থাকলে, প্ল্যাসেন্টা নিচে থাকলে, বা প্রি-ইক্লাম্পসিয়া থাকলে। জরুরি প্রয়োজন হয় শিশুর অক্সিজেন কমে গেলে, লেবার না এগোলে, বা অতিরিক্ত রক্তক্ষরণ হলে।

অস্ত্রোপচার সাধারণত ৪০ থেকে ৫০ মিনিট লাগে। জটিলতা না হলে বেশির ভাগ নারী ১ থেকে ২ দিনের মধ্যে বাড়ি ফেরেন। পুনরুদ্ধার সাধারণত স্বাভাবিক প্রসবের চেয়ে বেশি সময় নেয়।

## খুলনায় পরামর্শ নিন
সিজারিয়ান ও স্বাভাবিক প্রসবের বিকল্প নিয়ে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে আলোচনা করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## Caesarean delivery service
A caesarean delivery is a surgical birth through a cut in the abdomen and womb. It is usually done when it is the safest option.

A planned caesarean may be needed if the baby is breech, the placenta is low-lying, or pre-eclampsia is present. An urgent caesarean may be needed if the baby''s oxygen supply falls, labour is not progressing, or there is heavy bleeding.

The operation usually takes 40 to 50 minutes. Without complications, most women go home in 1 to 2 days. Recovery usually takes longer than after a vaginal birth.

## Consult in Khulna
To discuss caesarean and vaginal birth options, speak with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'c-section-delivery-service';

UPDATE services SET
  meta_title = 'ল্যাপারোস্কোপিক সার্জারি সেবা খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'ল্যাপারোস্কোপিক (কিহোল) সার্জারি সেবা: কখন দরকার, রোগ নির্ণয় ও চিকিৎসা, এবং পুনরুদ্ধার। খুলনায় পরামর্শ নিন।',
  meta_title_en = 'Laparoscopic Surgery Service in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Laparoscopic (keyhole) surgery service in Khulna: when it is needed, diagnosis and treatment, and recovery. Consult Dr. Jebunnesa Jebu.',
  overview_body = '## ল্যাপারোস্কোপিক সার্জারি সেবা
ছোট কাটা দিয়ে ক্যামেরার সাহায্যে পেট বা শ্রোণীর ভেতরের সমস্যা নির্ণয় ও চিকিৎসা করা হয়। সাধারণ অ্যানেস্থেশিয়ায় এটি করা হয়।

এন্ডোমেট্রিওসিস, শ্রোণী সংক্রমণ, টিউবে গর্ভধারণ, ফাইব্রয়েড ও জরায়ু বা ডিম্বাশয়ের অপসারণের মতো চিকিৎসায় ব্যবহৃত হয়।

ডায়াগনস্টিক পদ্ধতি সাধারণত ৩০ থেকে ৬০ মিনিট লাগে এবং বেশির ভাগ রোগী একই দিনে বা পরের দিন বাড়ি ফেরেন। অস্ত্রোপচারের পর সম্পূর্ণ সুস্থ হতে ৬ থেকে ৮ সপ্তাহ লাগতে পারে।

## খুলনায় পরামর্শ নিন
অস্ত্রোপচারের প্রয়োজন নিয়ে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে কথা বলুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## Laparoscopic surgery service
Problems inside the abdomen or pelvis are diagnosed and treated through small cuts with a camera. It is done under general anaesthetic.

It is used to treat endometriosis, pelvic inflammatory disease, ectopic pregnancy, fibroids, and in some cases removal of the womb or ovaries.

A diagnostic procedure usually takes 30 to 60 minutes, and most people go home the same day or the next day. Full recovery after surgery can take 6 to 8 weeks.

## Consult in Khulna
To discuss whether you need surgery, speak with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'laparoscopic-surgery-service';

UPDATE services SET
  meta_title = 'কলপোস্কোপি ও স্ক্রিনিং, খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'সার্ভিকাল স্ক্রিনিং ও কলপোস্কোপির উদ্দেশ্য, পরীক্ষার সময় ও ফল। অস্বাভাবিক ফলাফলে খুলনায় গাইনী চেম্বারে পরামর্শ নিন।',
  meta_title_en = 'Colposcopy and Cervical Screening | Dr. Jebunnesa Jebu',
  meta_description_en = 'Cervical screening and colposcopy in Khulna: purpose, what happens during the test and when results come. Dr. Jebunnesa Jebu.',
  overview_body = '## সার্ভিকাল স্ক্রিনিং ও কলপোস্কোপি
সার্ভিকাল স্ক্রিনিংয়ে জরায়ুমুখ থেকে কোষ সংগ্রহ করে এইচপিভি-সংশ্লিষ্ট পরিবর্তন খোঁজা হয়। স্ক্রিনিংয়ে অস্বাভাবিক বা অনিশ্চিত ফল এলে কলপোস্কোপি করা হয়।

## কখন দরকার
- স্ক্রিনিংয়ে অস্বাভাবিক কোষ পাওয়া গেলে
- পরপর দুটি পরীক্ষার ফল অনিশ্চিত হলে
- সহবাসের পর রক্তস্রাবসহ অস্বাভাবিক রক্তস্রাবের কারণ খুঁজতে

## পরীক্ষার সময়
- স্ক্রিনিং পরীক্ষা ৫ মিনিটের কম সময় নেয়, পুরো অ্যাপয়েন্টমেন্ট প্রায় ১০ মিনিট
- কলপোস্কোপি ১৫ থেকে ২০ মিনিট চলে; প্রয়োজনে বায়োপসি, ফল ৪ থেকে ৮ সপ্তাহে
- অস্বাভাবিক কোষ পেলে একই দিনে চিকিৎসা (যেমন LLETZ) করা যেতে পারে

## খুলনায় পরামর্শ নিন
স্ক্রিনিং বা কলপোস্কোপির জন্য খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে যোগাযোগ করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।

তথ্যসূত্র: NHS UK।',
  overview_body_en = '## Cervical screening and colposcopy
Cervical screening collects cells from the cervix to look for changes linked to HPV. If the screening result is abnormal or unclear, colposcopy is done.

## When it is needed
- Abnormal cells found on screening
- Two screening results in a row are unclear
- To find the cause of abnormal bleeding, including bleeding after sex

## During the appointment
- The screening test takes under 5 minutes, and the whole appointment about 10 minutes
- Colposcopy takes 15 to 20 minutes; a biopsy may be taken, with results in 4 to 8 weeks
- If abnormal cells are found, treatment such as LLETZ may be done the same day

## Consult in Khulna
For screening or colposcopy, contact Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).

Source: NHS UK.'
WHERE slug = 'colposcopy-cervical-screening';

-- ===== SPECIALTIES =====
UPDATE specialties SET
  meta_title = 'গর্ভাবস্থা ও প্রসব বিশেষত্ব খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'প্রসূতি বিভাগে গর্ভকালীন যত্ন ও প্রসব সেবার বিশেষত্ব। খুলনায় গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন।',
  meta_title_en = 'Antenatal and Delivery Care, Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Antenatal and delivery care specialty: pregnancy check-ups, scans and birth care. Obstetric consultation with Dr. Jebunnesa Jebu in Khulna.',
  overview_body = '## গর্ভাবস্থা ও প্রসব সেবা
প্রসূতি বিশেষত্বে গর্ভাবস্থার শুরু থেকে প্রসব ও প্রসব-পরবর্তী সময় পর্যন্ত মা ও শিশুর স্বাস্থ্য দেখা হয়। নিয়মিত চেকআপ, স্ক্যান ও পরীক্ষা দিয়ে ঝুঁকি আগে থেকে চিহ্নিত করা এর মূল লক্ষ্য।

## খুলনায় পরামর্শ নিন
খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে গর্ভকালীন যত্ন ও প্রসবের পরিকল্পনা নিয়ে আলোচনা করুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## Antenatal and delivery care
The obstetric specialty looks after the health of mother and baby from early pregnancy through birth and the postnatal period. Its main aim is to identify risks early through regular check-ups, scans and tests.

## Consult in Khulna
To discuss antenatal care and birth planning, speak with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).'
WHERE slug = 'antenatal-delivery-care';

UPDATE specialties SET
  meta_title = 'বন্ধ্যাত্ব ব্যবস্থাপনা, খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'বন্ধ্যাত্বের মূল্যায়ন, ওষুধ, IUI ও IVF-এর ব্যবস্থাপনা। খুলনায় বন্ধ্যাত্ব বিশেষজ্ঞ ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন।',
  meta_title_en = 'Fertility Management in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Fertility management in Khulna: assessment, medicines, IUI and IVF. Infertility specialist advice from Dr. Jebunnesa Jebu.',
  overview_body = '## বন্ধ্যাত্ব ব্যবস্থাপনা
বন্ধ্যাত্ব ব্যবস্থাপনায় গর্ভধারণে সমস্যার কারণ খুঁজে বের করা হয় এবং স্বামী-স্ত্রী দুজনের জন্য পরিকল্পিত চিকিৎসা দেওয়া হয়। এতে ওষুধ, IUI, সার্জারি এবং প্রয়োজনে IVF অন্তর্ভুক্ত থাকতে পারে।

এক বছর বা তার বেশি সময় নিয়মিত চেষ্টার পরে বা পরিচিত সমস্যা থাকলে বিশেষজ্ঞের পরামর্শ নেওয়া উচিত।

## খুলনায় পরামর্শ নিন
খুলনায় বন্ধ্যাত্ব বিশেষজ্ঞ ডাঃ জেবুন্নেসা জেবুর চেম্বারে সব রিপোর্ট নিয়ে আসুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## Fertility management
Fertility management means finding the reasons for difficulty conceiving and offering a planned treatment for both partners. It can include medicines, IUI, surgery and, when needed, IVF.

Specialist advice is recommended after a year or more of regular attempts, or sooner if a known problem exists.

## Consult in Khulna
Bring all your reports to the infertility specialist, Dr. Jebunnesa Jebu, in Khulna. [Book an appointment](/appointment).'
WHERE slug = 'fertility-management';

UPDATE specialties SET
  meta_title = 'গাইনোকোলজিকাল সার্জারি বিশেষত্ব খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'নারীর প্রজনন অঙ্গের অস্ত্রোপচার বিশেষত্ব: কিহোল ও ওপেন সার্জারি। খুলনায় গাইনী সার্জারির পরামর্শ নিন।',
  meta_title_en = 'Gynaecological Surgery, Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Gynaecological surgery specialty: keyhole and open operations on the reproductive organs. Surgical consultation in Khulna.',
  overview_body = '## গাইনোকোলজিকাল সার্জারি
এই বিশেষত্বে নারীর প্রজনন অঙ্গের রোগ নির্ণয় ও চিকিৎসার জন্য অস্ত্রোপচার করা হয়। কিহোল (ল্যাপারোস্কোপিক) পদ্ধতিতে দ্রুত পুনরুদ্ধার হয়, তবে প্রতিটি রোগীর জন্য পদ্ধতি আলাদা।

## খুলনায় পরামর্শ নিন
অস্ত্রোপচার প্রয়োজন কি না তা জানতে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## Gynaecological surgery
This specialty performs operations on the female reproductive organs to diagnose and treat conditions. Keyhole (laparoscopic) methods often allow faster recovery, but the method is chosen for each patient.

## Consult in Khulna
To find out whether surgery is needed, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).'
WHERE slug = 'gynaecological-surgery';

UPDATE specialties SET
  meta_title = 'মাসিক চক্রের সমস্যা বিশেষত্ব খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'ভারী, ব্যথাযুক্ত বা অনিয়মিত মাসিক এবং মাসিক বন্ধ থাকার মূল্যায়ন ও চিকিৎসা। খুলনায় গাইনী চেম্বারে পরামর্শ নিন।',
  meta_title_en = 'Menstrual Cycle Problems, Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Menstrual cycle problems in Khulna: heavy, painful and irregular periods, and missed periods. Assessment and treatment with Dr. Jebunnesa Jebu.',
  overview_body = '## মাসিক চক্রের সমস্যা
মাসিক চক্রের সমস্যায় ভারী, ব্যথাযুক্ত বা অনিয়মিত মাসিক এবং মাসিক বন্ধ থাকার মূল্যায়ন করা হয়। অনেক সমস্যার কারণ হরমোন, থাইরয়েড বা জীবনযাত্রার সঙ্গে জড়িত, এবং সেই অনুযায়ী চিকিৎসা দেওয়া হয়।

## খুলনায় পরামর্শ নিন
মাসিকের সমস্যা নিয়ে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## Menstrual cycle problems
This specialty assesses and treats heavy, painful or irregular periods and missed periods. Many causes are linked to hormones, thyroid problems or lifestyle, and treatment is planned accordingly.

## Consult in Khulna
For period problems, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).'
WHERE slug = 'menstrual-health';

UPDATE specialties SET
  meta_title = 'স্তনের স্বাস্থ্য পরামর্শ খুলনা | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'স্তন পরীক্ষা (ব্রেস্ট অ্যাওয়ারনেস), কোন পরিবর্তনে ডাক্তার দেখাবেন। খুলনায় নারী স্বাস্থ্যের পরামর্শ নিন।',
  meta_title_en = 'Breast Health Advice in Khulna | Dr. Jebunnesa Jebu',
  meta_description_en = 'Breast health advice in Khulna: breast awareness, what changes to check and when to see a doctor. Women''s health consultation.',
  overview_body = '## স্তনের স্বাস্থ্য পরামর্শ
স্তন পরীক্ষা বা ব্রেস্ট অ্যাওয়ারনেস মানে নিয়মিত নিজের স্তন দেখে জানা, স্বাভাবিক অবস্থা কেমন। মাসিক চক্র, গর্ভাবস্থা, স্তন্যদান ও মেনোপজের সঙ্গে স্তনে পরিবর্তন হতে পারে।

প্রতি মাসে একবার আয়নায় দেখে এবং হালকা চাপ দিয়ে পরীক্ষা করা যায়। স্তনে বা বগলে গলা থাকলে, ত্বকে কোঁচকানো বা লালচে ভাব হলে, বোঁটা থেকে স্রাব বা রক্ত গেলে, বা ব্যথা দীর্ঘদিন থাকলে নিয়মিত চিকিৎসকের কাছে যান।

## খুলনায় পরামর্শ নিন
স্তনের যেকোনো পরিবর্তনে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর সঙ্গে কথা বলুন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## Breast health advice
Breast awareness means regularly checking your breasts so you know what is normal for you. The breasts can change with the menstrual cycle, pregnancy, breastfeeding and after menopause.

Check monthly by looking in a mirror and feeling each breast and armpit gently. See a doctor if you notice a lump or swelling in the breast, chest or armpit, skin dimpling or redness, a change in size or shape, nipple discharge (possibly bloody), an inverted nipple, a rash or sores, or pain that does not go away.

## Consult in Khulna
For any breast change, speak with Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).'
WHERE slug = 'breast-care';

UPDATE specialties SET
  meta_title = 'নারী স্বাস্থ্য, কিশোরী থেকে মেনোপজ | ডাঃ জেবুন্নেসা জেবু',
  meta_description = 'জীবনের বিভিন্ন ধাপে নারী স্বাস্থ্যের যত্ন: মাসিক, গর্ভনিরোধ, গর্ভধারণ ও মেনোপজ। খুলনায় গাইনী চেম্বারে পরামর্শ নিন।',
  meta_title_en = 'Women''s Health Across Life Stages | Dr. Jebunnesa Jebu',
  meta_description_en = 'Women''s health care across life stages in Khulna: periods, contraception, pregnancy and menopause. Gynaecology consultation with Dr. Jebunnesa Jebu.',
  overview_body = '## কিশোরী থেকে মেনোপজ পর্যন্ত নারী স্বাস্থ্য
কৈশোর, প্রজনন বয়স ও মেনোপজ, প্রতিটি ধাপে নারীর স্বাস্থ্যের চাহিদা আলাদা। এই বিশেষত্বে মাসিক, গর্ভনিরোধ, গর্ভধারণের পরিকল্পনা ও মেনোপজের যত্ন একসঙ্গে দেখা হয়।

## খুলনায় পরামর্শ নিন
জীবনের যেকোনো ধাপে নারী স্বাস্থ্যের প্রশ্নে খুলনার গাইনী ও প্রসূতি চেম্বারে ডাঃ জেবুন্নেসা জেবুর পরামর্শ নিন। [অ্যাপয়েন্টমেন্ট নিন](/appointment)।',
  overview_body_en = '## Women''s health across life stages
Adolescence, the reproductive years and menopause each bring different health needs. This specialty looks at periods, contraception, pregnancy planning and menopause care together.

## Consult in Khulna
For any women''s health question at any life stage, consult Dr. Jebunnesa Jebu at the gynaecology and maternity chamber in Khulna. [Book an appointment](/appointment).'
WHERE slug = 'lifespan-womens-care';
