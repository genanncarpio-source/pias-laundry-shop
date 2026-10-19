<?php
// Password changes are handled through the authenticated profile or admin tools.
require_once __DIR__ . '/includes/auth.php';
require_admin();
flash_set('error', 'Use Employees to reset an account password, or My Account to change your own password.');
redirect('employees.php');
