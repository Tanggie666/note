<?php
require __DIR__ . '/app/lib.php';
$_SESSION = [];
session_destroy();
redirect('index.php');