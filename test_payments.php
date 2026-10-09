<?php
require 'config/app.php';
$db = Database::conn();
$stmt = $db->query("UPDATE payments SET payment_method = 'Shopee' WHERE payment_method = '' AND payment_date > '2026-09-07' AND id = 38");
echo "Table updated successfully!";
