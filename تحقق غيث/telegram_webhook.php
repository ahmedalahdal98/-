<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

if (!hash_equals((string) TELEGRAM_WEBHOOK_SECRET, (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
    http_response_code(403);
    exit;
}

$update=json_decode(file_get_contents('php://input')?:'{}',true);
$pdo=db();
$message=$update['message']??null;

if($message&&isset($message['chat']['id'])&&preg_match('/^\/register(?:@\w+)?$/',(string)($message['text']??''))){
    $chat=$message['chat'];$chatId=(string)$chat['id'];$title=(string)($chat['title']??$chatId);
    $statement=$pdo->prepare("INSERT INTO telegram_groups (chat_id,title) VALUES (?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),is_active=1");$statement->execute([$chatId,$title]);
    telegramWebhookRequest('sendMessage',['chat_id'=>$chatId,'text'=>'✅ تم تسجيل المجموعة. اربطها بالكلية من لوحة المدير.']);
}

$change=$update['chat_member']??null;
if($change&&isset($change['invite_link']['invite_link'],$change['new_chat_member']['user']['id'])){
    $status=(string)($change['new_chat_member']['status']??'');
    if(in_array($status,['member','administrator','creator'],true)){
        $user=$change['new_chat_member']['user'];$name=trim((string)($user['first_name']??'').' '.(string)($user['last_name']??''));if(!empty($user['username']))$name.=' @'.$user['username'];
        $statement=$pdo->prepare("UPDATE telegram_links SET joined_user_id=?,joined_user_name=?,joined_at=NOW() WHERE invite_link=? AND joined_user_id='' LIMIT 1");
        $statement->execute([(string)$user['id'],$name,(string)$change['invite_link']['invite_link']]);
    }
}

http_response_code(200);
echo 'OK';

function telegramWebhookRequest(string $method,array $params): void
{
    $curl=curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/'.$method);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($params,JSON_UNESCAPED_UNICODE),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);curl_exec($curl);curl_close($curl);
}
