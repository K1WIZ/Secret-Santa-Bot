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
    ]
];
