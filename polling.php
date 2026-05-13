<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);

$token = getenv('BOT_TOKEN');
$apiUrl = "https://api.telegram.org/bot$token";

$lastUpdateId = 0;

while (true) {
    $response = file_get_contents($apiUrl . "/getUpdates?offset=" . ($lastUpdateId + 1) . "&timeout=30");
    $updates = json_decode($response, true);
    
    if (isset($updates['result'])) {
        foreach ($updates['result'] as $update) {
            $lastUpdateId = $update['update_id'];
            
            if (isset($update['message'])) {
                $chatId = $update['message']['chat']['id'];
                $text = $update['message']['text'] ?? '';
                
                if ($text == '/start') {
                    file_get_contents($apiUrl . "/sendMessage?chat_id=$chatId&text=✅ Бот работает!");
                }
            }
        }
    }
    
    sleep(1);
}