<?php
require_once __DIR__ . '/auth.php';

require_method('POST');
require_same_origin();
logout_user();
ok();
