<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
flash_set('info', 'Cash closing is outside the scope of this system.');
redirect('dashboard.php');