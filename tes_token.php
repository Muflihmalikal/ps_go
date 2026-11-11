<?php
putenv('APP_KEY=' . str_repeat('k', 40));
require __DIR__ . '/config/security.php';

$t = encrypt_id(42);
var_dump(decrypt_id($t));             // string(2) "42"
var_dump(decrypt_id($t . 'x'));       // bool(false)  token diubah
var_dump(decrypt_id($t, 'lain'));     // bool(false)  konteks berbeda
var_dump(decrypt_id(encrypt_id('GG-20261107-0001', 'sewa'), 'sewa'));  // string(16) "GG-20261107-0001"