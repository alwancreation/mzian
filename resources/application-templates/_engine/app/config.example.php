<?php

// Copy to config.php (never commit it). Generate the hash with:
//   php -r 'echo password_hash("your-long-password", PASSWORD_DEFAULT), PHP_EOL;'
return [
    'admin_email' => 'owner@example.com',
    'admin_password_hash' => 'REPLACE_WITH_THE_OUTPUT_OF_password_hash()',
    'notify_email' => 'owner@example.com',
    'timezone' => 'Africa/Casablanca',
    // Relative to this directory; keep it outside the public web root.
    'data_dir' => 'data',
];
