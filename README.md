# 🎄 Secret Santa E-mail Bot

Welcome to the **Secret Santa E-mail Bot**, the festive automation gremlin that handles all the chaos of drawing names without the need for paper scraps, family arguments, or “who did I get last year again?” debates.

This bot:

- Draws randomized Secret Santa pairings  
- Prevents same-household assignments  
- Avoids repeating last year’s pairings  
- Sends beautiful Christmas-themed HTML emails  
- Sends the master list privately to the admin  
- Runs automatically every Thanksgiving Day at noon  
- Can also be triggered manually with `-force`  

In other words:

> **It’s the holiday elf you always wanted — one that doesn’t eat your cookies or unionize.**

---

## 🎁 Features

- 🎅 Automatic Thanksgiving Day draw  
- 🌐 Manual run via CLI `-force`  
- 💌 Sends individual HTML emails to participants  
- 🎄 Colorful Christmas-themed template  
- 🔒 Avoids same-family matchups  
- 🔁 Avoids pairing repeats from last year
- ✨ Secret Santas can view their person's Wish List, and set their own
- 📬 Sends admin a complete pairing list  
- 🧪 Admin dashboard:  
  - Test pairings  
  - Commit pairings to DB  
  - Test SMTP email  
- 🗄 Uses MySQL for persistent year-over-year tracking  
- 📮 Uses PHPMailer + Gmail SMTP  

---

## 🛠 Installation & Setup (Ubuntu Server)

### 1. Install dependencies

```
sudo apt update
sudo apt install apache2 php php-mbstring php-xml php-mysql php-cli unzip composer
sudo mkdir -p /var/www/html/ss
cd /var/www/html/ss
composer require phpmailer/phpmailer
```
### 2. Create a new database and use the SQL script to initialize a table
```
CREATE TABLE participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name  VARCHAR(100) NOT NULL,
    email      VARCHAR(255) NOT NULL,
    family_unit INT NOT NULL,

    -- Wishlist fields
    wish_item1 VARCHAR(255) NULL,
    wish_item2 VARCHAR(255) NULL,
    wish_item3 VARCHAR(255) NULL,

    -- Per-user secret key for wishlist access
    wish_key   VARCHAR(64)  NULL,

    -- Optional but recommended: enforce unique keys once generated
    UNIQUE KEY uniq_wish_key (wish_key)
);

-- Table storing pairings for each year
CREATE TABLE secret_santa_pairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year INT NOT NULL,
    giver_id INT NOT NULL,
    receiver_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```
(Add your recipients to the table!)

### 3. Create a config.php file
```
<?php
// config.php

return [
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=secret_santa;charset=utf8mb4',
        'user'     => 'dbuser',
        'password' => 'dbpassword',
    ],

    // Gmail SMTP settings (use an App Password – NOT your raw Gmail password)
    'smtp' => [
        'host'       => 'smtp.gmail.com',
        'port'       => 587,
        'username'   => 'yourgmail@gmail.com',
        'password'   => 'your_app_password_here',
        'from_email' => 'yourgmail@gmail.com',
        'from_name'  => 'Secret Santa Bot',
    ],
    'app' => [
        // No trailing slash
        'base_url' => 'https://yout.domain/ss'
    ],
];
```
⚠️ Use a Gmail App Password, not your actual Gmail password!

### 4. Set permissions on files
```
sudo chown -R www-data:www-data /var/www/html/ss
sudo chmod -R 755 /var/www/html/ss
```
### 5. Add a cron job to automatically generate and email secret santa recipients
```
0 12 * 11 *   root  /usr/bin/php /var/www/html/wizworks/ss/secret_santa.php >/var/log/secret_santa.log 2>&1
```
(NOTE: the script will check if it's Thanksgiving Day when run, and if it is, will generate and email pairings)

### 6. Testing
If you wish to test the script, you can do so by calling the script and using the `-force` flag like so:
```
php /var/www/html/ss/secret_santa.php -force
```

You can also use the Admin page to test pairings without emailing or writing to the DB, using your favorite web browser:
```
https://yourdomain.com/ss/admin_secret_santa.php?key=YourSecretKey
```
Tools available:

- Generate pairings
- Save pairings to the database
- Test email functionality
- Confirm “avoid last year” logic

### ☕ Buy Me a Coffee
If this project saved your holiday sanity or prevented at least one family meltdown…

You can buy me a coffee on Venmo:

👉 @wizworks

Cheers, and happy gifting! 🎄😄
