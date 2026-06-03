<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
  "status" => "ok",
  "message" => "Suojaapuhelin phone model API toimii"
], JSON_UNESCAPED_UNICODE);
