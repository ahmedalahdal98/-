<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Strict',
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);

require __DIR__ . '/config.php';

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '{}', true);
    return is_array($data) ? $data : [];
}

function telegramRequest(string $method,array $params=[])
{
    if(!defined('TELEGRAM_BOT_TOKEN')||TELEGRAM_BOT_TOKEN===''||strpos(TELEGRAM_BOT_TOKEN,'PUT_')===0) throw new RuntimeException('توكن Telegram غير مضبوط');
    $curl=curl_init('https://api.telegram.org/bot'.TELEGRAM_BOT_TOKEN.'/'.$method);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    $response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$error=curl_error($curl);curl_close($curl);$decoded=json_decode((string)$response,true);
    if($status!==200||empty($decoded['ok'])) throw new RuntimeException('Telegram API: '.($decoded['description']??("HTTP $status $error")));
    return $decoded['result']??[];
}

function telegramGroups(PDO $pdo,bool $onlyLinked=false): array
{
    $where=$onlyLinked?" WHERE g.is_active=1 AND EXISTS(SELECT 1 FROM telegram_group_colleges c WHERE c.group_id=g.id)":'';
    $groups=[];foreach($pdo->query('SELECT g.* FROM telegram_groups g'.$where.' ORDER BY g.title')->fetchAll() as $row){$statement=$pdo->prepare('SELECT college FROM telegram_group_colleges WHERE group_id=? ORDER BY college');$statement->execute([$row['id']]);$colleges=$statement->fetchAll(PDO::FETCH_COLUMN);$groups[]=['id'=>(int)$row['id'],'chatId'=>$row['chat_id'],'title'=>$row['title'],'college'=>$colleges[0]??'','colleges'=>$colleges,'active'=>(bool)$row['is_active'],'waedGroup'=>(bool)($row['is_waed_group']??0)];}return $groups;
}

function telegramStudentMessage(array $record,string $link,string $waedLink=''): string
{
    $waed=$waedLink!==''?"\n\n⭐ رابط مجموعة برنامج واعد التميز النوعي:\n$waedLink":'';
    return "👋 أهلًا {$record['student_name']}،\n\n🎉 ألف مبروك قبولك مرة ثانية، ونتمنى لك التوفيق في رحلتك الجامعية.\n\n🔗 هذا رابط الكلية الخاص فيك:\n$link$waed\n\n⚠️ تنبيه مهم:\n🔒 الروابط مخصصة لك فقط، فلا تشاركها مع أي شخص.\n⏳ يرجى الدخول على الروابط خلال 24 ساعة، لأنها بعد انتهاء المدة راح تتوقف.";
}

function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

function googleAccessToken(): string
{
    static $token = '';
    if ($token !== '') return $token;
    if (!defined('GOOGLE_SERVICE_ACCOUNT_FILE') || !is_readable(GOOGLE_SERVICE_ACCOUNT_FILE)) throw new RuntimeException('ملف حساب خدمة Google غير موجود أو غير مقروء');
    $credentials = json_decode((string) file_get_contents(GOOGLE_SERVICE_ACCOUNT_FILE), true, 512, JSON_THROW_ON_ERROR);
    $now = time();
    $header = base64Url(json_encode(['alg'=>'RS256','typ'=>'JWT'], JSON_THROW_ON_ERROR));
    $claims = base64Url(json_encode(['iss'=>$credentials['client_email'],'scope'=>'https://www.googleapis.com/auth/spreadsheets','aud'=>$credentials['token_uri'],'iat'=>$now,'exp'=>$now+3600], JSON_THROW_ON_ERROR));
    $unsigned = $header . '.' . $claims;
    if (!openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('تعذر توقيع طلب Google');
    $assertion = $unsigned . '.' . base64Url($signature);
    $curl = curl_init($credentials['token_uri']);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$assertion]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
    $response=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $error=curl_error($curl); curl_close($curl);
    $decoded=json_decode((string)$response,true);
    if($status!==200||empty($decoded['access_token'])) throw new RuntimeException("تعذر الحصول على تصريح Google: HTTP $status $error");
    return $token=(string)$decoded['access_token'];
}

function googleRequest(string $method,string $url,?array $body=null): array
{
    $curl=curl_init($url); $headers=['Authorization: Bearer '.googleAccessToken(),'Content-Type: application/json'];
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_HTTPHEADER=>$headers]);
    if($body!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $response=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $error=curl_error($curl); curl_close($curl);
    $decoded=json_decode((string)$response,true);
    if($status<200||$status>=300) throw new RuntimeException('Google Sheets API: HTTP '.$status.' '.$error.' '.($decoded['error']['message']??''));
    return is_array($decoded)?$decoded:[];
}

function ensureGoogleSheet(string $title,array $headers): void
{
    static $ready=[]; if(isset($ready[$title])) return;
    $id=GOOGLE_SPREADSHEET_ID; $meta=googleRequest('GET','https://sheets.googleapis.com/v4/spreadsheets/'.$id.'?fields=sheets.properties');
    $exists=false; foreach($meta['sheets']??[] as $sheet) if(($sheet['properties']['title']??'')===$title){$exists=true;break;}
    if(!$exists) googleRequest('POST','https://sheets.googleapis.com/v4/spreadsheets/'.$id.':batchUpdate',['requests'=>[['addSheet'=>['properties'=>['title'=>$title]]]]]);
    $range=rawurlencode("'$title'!A1:Z1"); googleRequest('PUT','https://sheets.googleapis.com/v4/spreadsheets/'.$id.'/values/'.$range.'?valueInputOption=RAW',['values'=>[$headers]]);
    $ready[$title]=true;
}

function upsertGoogleRow(string $sheet,array $headers,array $row): void
{
    ensureGoogleSheet($sheet,$headers); $id=GOOGLE_SPREADSHEET_ID; $column=rawurlencode("'$sheet'!A:A");
    $values=googleRequest('GET','https://sheets.googleapis.com/v4/spreadsheets/'.$id.'/values/'.$column); $rowNumber=0;
    foreach($values['values']??[] as $index=>$value) if((string)($value[0]??'')===(string)$row[0]){$rowNumber=$index+1;break;}
    if($rowNumber){$range=rawurlencode("'$sheet'!A$rowNumber:Z$rowNumber");googleRequest('PUT','https://sheets.googleapis.com/v4/spreadsheets/'.$id.'/values/'.$range.'?valueInputOption=RAW',['values'=>[$row]]);}
    else{$range=rawurlencode("'$sheet'!A:Z");googleRequest('POST','https://sheets.googleapis.com/v4/spreadsheets/'.$id.'/values/'.$range.':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',['values'=>[$row]]);}
}

function clearGoogleRow(string $sheet,string $rowId,array $headers): void
{
    ensureGoogleSheet($sheet,$headers); $id=GOOGLE_SPREADSHEET_ID; $column=rawurlencode("'$sheet'!A:A"); $values=googleRequest('GET','https://sheets.googleapis.com/v4/spreadsheets/'.$id.'/values/'.$column);
    foreach($values['values']??[] as $index=>$value) if((string)($value[0]??'')===$rowId){$number=$index+1;$range=rawurlencode("'$sheet'!A$number:Z$number");googleRequest('POST','https://sheets.googleapis.com/v4/spreadsheets/'.$id.'/values/'.$range.':clear',[]);break;}
}

function sendToGoogleSheets(string $entity,string $action,array $data=[]): void
{
    if(!defined('GOOGLE_SPREADSHEET_ID')||GOOGLE_SPREADSHEET_ID==='') return;
    $recordHeaders=['id','اسم الطالب','رقم الجوال','الرقم الجامعي','الكلية','طريقة التحقق','الموظف','التاريخ','تحذير','سبب التحذير','النوع','برنامج واعد التميز النوعي','حالة الطالب'];
    $alertHeaders=['id','recordId','اسم الطالب','رقم الجوال','الرقم الجامعي','الكلية','طريقة التحقق','الموظف','سبب التنبيه','سبب الموظفة','التاريخ','مقروء','النوع','برنامج واعد التميز النوعي','حالة الطالب'];
    try{
        if($entity==='record'&&$action==='upsert') upsertGoogleRow('السجلات',$recordHeaders,[$data['id'],$data['studentName'],$data['phone'],$data['universityId'],$data['college'],$data['method'],$data['employeeName'],$data['createdAt'],$data['warning']?'نعم':'لا',$data['warningReason']??'',$data['studentGender']??'',$data['waedProgram']??'',$data['previousBatch']??'']);
        elseif($entity==='record'&&$action==='delete') clearGoogleRow('السجلات',(string)$data['id'],$recordHeaders);
        elseif($entity==='alert'&&$action==='upsert') upsertGoogleRow('التنبيهات',$alertHeaders,[$data['id'],$data['recordId'],$data['studentName'],$data['phone'],$data['universityId'],$data['college'],$data['method'],$data['employeeName'],$data['reason'],$data['employeeNote']??'',$data['createdAt'],$data['read']?'نعم':'لا',$data['studentGender']??'',$data['waedProgram']??'',$data['previousBatch']??'']);
        elseif($entity==='alert'&&$action==='delete') clearGoogleRow('التنبيهات',(string)$data['id'],$alertHeaders);
        elseif($entity==='alerts'&&$action==='clear'){ensureGoogleSheet('التنبيهات',$alertHeaders);$range=rawurlencode("'التنبيهات'!A2:Z");googleRequest('POST','https://sheets.googleapis.com/v4/spreadsheets/'.GOOGLE_SPREADSHEET_ID.'/values/'.$range.':clear',[]);}
    }catch(Throwable $error){error_log('Google Sheets sync failed: '.$error->getMessage());}
}

function requireAdmin(): void
{
    if (($_SESSION['role'] ?? '') !== 'admin') {
        respond(['ok' => false, 'message' => 'غير مصرح'], 401);
    }
}

function bootstrap(PDO $pdo): void
{
    $genderColumn=$pdo->query("SHOW COLUMNS FROM records LIKE 'student_gender'");
    if(!$genderColumn->fetch()) $pdo->exec("ALTER TABLE records ADD COLUMN student_gender VARCHAR(10) NOT NULL DEFAULT '' AFTER student_name");
    $alertGenderColumn=$pdo->query("SHOW COLUMNS FROM alerts LIKE 'student_gender'");
    if(!$alertGenderColumn->fetch()) $pdo->exec("ALTER TABLE alerts ADD COLUMN student_gender VARCHAR(10) NOT NULL DEFAULT '' AFTER student_name");
    $waedColumn=$pdo->query("SHOW COLUMNS FROM records LIKE 'waed_program'");
    if(!$waedColumn->fetch()) $pdo->exec("ALTER TABLE records ADD COLUMN waed_program VARCHAR(3) NOT NULL DEFAULT '' AFTER student_gender");
    $alertWaedColumn=$pdo->query("SHOW COLUMNS FROM alerts LIKE 'waed_program'");
    if(!$alertWaedColumn->fetch()) $pdo->exec("ALTER TABLE alerts ADD COLUMN waed_program VARCHAR(3) NOT NULL DEFAULT '' AFTER student_gender");
    $previousColumn=$pdo->query("SHOW COLUMNS FROM records LIKE 'previous_batch'");
    $previousInfo=$previousColumn->fetch();if(!$previousInfo) $pdo->exec("ALTER TABLE records ADD COLUMN previous_batch VARCHAR(20) NOT NULL DEFAULT '' AFTER waed_program");
    elseif(strtolower((string)$previousInfo['Type'])!=='varchar(20)') $pdo->exec("ALTER TABLE records MODIFY previous_batch VARCHAR(20) NOT NULL DEFAULT ''");
    $alertPreviousColumn=$pdo->query("SHOW COLUMNS FROM alerts LIKE 'previous_batch'");
    $alertPreviousInfo=$alertPreviousColumn->fetch();if(!$alertPreviousInfo) $pdo->exec("ALTER TABLE alerts ADD COLUMN previous_batch VARCHAR(20) NOT NULL DEFAULT '' AFTER waed_program");
    elseif(strtolower((string)$alertPreviousInfo['Type'])!=='varchar(20)') $pdo->exec("ALTER TABLE alerts MODIFY previous_batch VARCHAR(20) NOT NULL DEFAULT ''");
    $columnCheck=$pdo->query("SHOW COLUMNS FROM alerts LIKE 'employee_note'");
    if(!$columnCheck->fetch()) $pdo->exec("ALTER TABLE alerts ADD COLUMN employee_note TEXT NOT NULL AFTER reason");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_groups (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, chat_id VARCHAR(30) NOT NULL UNIQUE, title VARCHAR(255) NOT NULL, college VARCHAR(150) NOT NULL DEFAULT '', is_active TINYINT(1) NOT NULL DEFAULT 1, registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $waedGroupColumn=$pdo->query("SHOW COLUMNS FROM telegram_groups LIKE 'is_waed_group'");
    if(!$waedGroupColumn->fetch()) $pdo->exec("ALTER TABLE telegram_groups ADD COLUMN is_waed_group TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_group_colleges (group_id INT UNSIGNED NOT NULL, college VARCHAR(150) NOT NULL, PRIMARY KEY(group_id,college), INDEX idx_tgc_college(college)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO telegram_group_colleges (group_id,college) SELECT id,college FROM telegram_groups WHERE college<>''");
    $pdo->exec("CREATE TABLE IF NOT EXISTS telegram_links (id VARCHAR(36) PRIMARY KEY, record_id VARCHAR(36) NOT NULL, group_id INT UNSIGNED NOT NULL, chat_id VARCHAR(30) NOT NULL, invite_link TEXT NOT NULL, link_name VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL, created_by VARCHAR(36) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, joined_user_id VARCHAR(30) NOT NULL DEFAULT '', joined_user_name VARCHAR(255) NOT NULL DEFAULT '', joined_at DATETIME NULL, revoked_at DATETIME NULL, revoked_by VARCHAR(36) NOT NULL DEFAULT '', INDEX idx_tg_record(record_id), INDEX idx_tg_link_name(link_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $statement->execute(['admin_password_hash']);
    if (!$statement->fetchColumn()) {
        $insert = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)');
        $insert->execute(['admin_password_hash', password_hash('112233112233053', PASSWORD_DEFAULT)]);
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM employees')->fetchColumn() === 0) {
        $insertEmployee = $pdo->prepare('INSERT INTO employees (id, name, password_hash) VALUES (?, ?, ?)');
        $insertEmployee->execute(['e1', 'سارة أحمد', password_hash('1111', PASSWORD_DEFAULT)]);
        $insertEmployee->execute(['e2', 'محمد خالد', password_hash('2222', PASSWORD_DEFAULT)]);
    }
}

function options(PDO $pdo): array
{
    $colleges = ['health' => [], 'science' => [], 'literary' => []];
    foreach ($pdo->query('SELECT id, category, name FROM colleges ORDER BY category, sort_order, id') as $row) {
        $colleges[$row['category']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
    }
    $methods = $pdo->query('SELECT id, name FROM verification_methods ORDER BY sort_order, id')->fetchAll();
    return ['colleges' => $colleges, 'methods' => $methods];
}

function adminState(PDO $pdo): array
{
    $employees = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'name' => $row['name'], 'blocked' => (bool) $row['is_blocked'],
    ], $pdo->query('SELECT id, name, is_blocked FROM employees ORDER BY created_at')->fetchAll());
    $records = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'studentName' => $row['student_name'], 'studentGender' => $row['student_gender'] ?? '', 'waedProgram' => $row['waed_program'] ?? '', 'previousBatch' => $row['previous_batch'] ?? '', 'phone' => $row['phone'],
        'universityId' => $row['university_id'], 'college' => $row['college'],
        'method' => $row['verification_method'], 'employeeId' => $row['employee_id'],
        'employeeName' => $row['employee_name'], 'warning' => (bool) $row['has_warning'],
        'warningReason' => $row['warning_reason'], 'createdAt' => $row['created_at'],
    ], $pdo->query('SELECT * FROM records ORDER BY created_at DESC')->fetchAll());
    $alerts = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'recordId' => $row['record_id'], 'reason' => $row['reason'],
        'employeeNote' => $row['employee_note'] ?? '',
        'employeeId' => $row['employee_id'], 'employeeName' => $row['employee_name'],
        'studentName' => $row['student_name'], 'studentGender' => $row['student_gender'] ?? '', 'waedProgram' => $row['waed_program'] ?? '', 'previousBatch' => $row['previous_batch'] ?? '', 'phone' => $row['phone'],
        'universityId' => $row['university_id'], 'college' => $row['college'],
        'method' => $row['verification_method'], 'read' => (bool) $row['is_read'],
        'createdAt' => $row['created_at'],
    ], $pdo->query('SELECT * FROM alerts ORDER BY created_at DESC')->fetchAll());
    $phones = $pdo->query('SELECT phone FROM blocked_phones ORDER BY created_at DESC')->fetchAll(PDO::FETCH_COLUMN);
    return compact('employees', 'records', 'alerts') + ['blockedPhones' => $phones];
}

try {
    $pdo = db();
    bootstrap($pdo);
    $action = $_GET['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === '' || $action === 'health') {
            respond(['ok' => true, 'message' => 'تم الاتصال بقاعدة البيانات بنجاح']);
        }
        if ($action === 'options') {
            respond(['ok' => true, 'data' => options($pdo)]);
        }
        if ($action === 'blocked_phones') {
            requireAdmin();
            $phones = $pdo->query('SELECT phone FROM blocked_phones ORDER BY created_at DESC')->fetchAll(PDO::FETCH_COLUMN);
            respond(['ok' => true, 'data' => $phones]);
        }
        if ($action === 'admin_state') {
            requireAdmin();
            respond(['ok' => true, 'data' => adminState($pdo)]);
        }
        if ($action === 'google_test') {
            requireAdmin();
            try {
                ensureGoogleSheet('السجلات',['id','اسم الطالب','رقم الجوال','الرقم الجامعي','الكلية','طريقة التحقق','الموظف','التاريخ','تحذير','سبب التحذير']);
                ensureGoogleSheet('التنبيهات',['id','recordId','اسم الطالب','رقم الجوال','الرقم الجامعي','الكلية','طريقة التحقق','الموظف','سبب التنبيه','سبب الموظفة','التاريخ','مقروء']);
                respond(['ok'=>true,'message'=>'تم الاتصال بـ Google Sheets بنجاح']);
            } catch (Throwable $error) {
                error_log('Google Sheets test failed: '.$error->getMessage());
                respond(['ok'=>false,'message'=>'تعذر الاتصال بـ Google Sheets'],500);
            }
        }
        if ($action === 'google_path') {
            requireAdmin();
            $path = defined('GOOGLE_SERVICE_ACCOUNT_FILE') ? GOOGLE_SERVICE_ACCOUNT_FILE : '';
            respond(['ok'=>true,'path'=>$path,'exists'=>$path!==''&&file_exists($path),'readable'=>$path!==''&&is_readable($path)]);
        }
        if ($action === 'google_sync_all') {
            requireAdmin();
            try{
                $state=adminState($pdo);$spreadsheetId=GOOGLE_SPREADSHEET_ID;
                $recordHeaders=['id','اسم الطالب','رقم الجوال','الرقم الجامعي','الكلية','طريقة التحقق','الموظف','التاريخ','تحذير','سبب التحذير','النوع','برنامج واعد التميز النوعي','حالة الطالب'];
                $recordRows=[$recordHeaders];foreach($state['records'] as $record)$recordRows[]=[$record['id'],$record['studentName'],$record['phone'],$record['universityId'],$record['college'],$record['method'],$record['employeeName'],$record['createdAt'],$record['warning']?'نعم':'لا',$record['warningReason']??'',$record['studentGender']??'',$record['waedProgram']??'',$record['previousBatch']??''];
                $alertHeaders=['id','recordId','اسم الطالب','رقم الجوال','الرقم الجامعي','الكلية','طريقة التحقق','الموظف','سبب التنبيه','سبب الموظفة','التاريخ','مقروء','النوع','برنامج واعد التميز النوعي','حالة الطالب'];
                $alertRows=[$alertHeaders];foreach($state['alerts'] as $alert)$alertRows[]=[$alert['id'],$alert['recordId'],$alert['studentName'],$alert['phone'],$alert['universityId'],$alert['college'],$alert['method'],$alert['employeeName'],$alert['reason'],$alert['employeeNote']??'',$alert['createdAt'],$alert['read']?'نعم':'لا',$alert['studentGender']??'',$alert['waedProgram']??'',$alert['previousBatch']??''];
                ensureGoogleSheet('السجلات',$recordHeaders);ensureGoogleSheet('التنبيهات',$alertHeaders);
                foreach([['السجلات',$recordRows],['التنبيهات',$alertRows]] as $sheetData){$sheet=$sheetData[0];$rows=$sheetData[1];$clearRange=rawurlencode("'$sheet'!A1:Z");googleRequest('POST','https://sheets.googleapis.com/v4/spreadsheets/'.$spreadsheetId.'/values/'.$clearRange.':clear',[]);$writeRange=rawurlencode("'$sheet'!A1:Z".count($rows));googleRequest('PUT','https://sheets.googleapis.com/v4/spreadsheets/'.$spreadsheetId.'/values/'.$writeRange.'?valueInputOption=RAW',['values'=>$rows]);}
                respond(['ok'=>true,'message'=>'اكتملت المزامنة الجماعية بنجاح','records'=>count($state['records']),'alerts'=>count($state['alerts'])]);
            }catch(Throwable $error){error_log('Google bulk sync failed: '.$error->getMessage());respond(['ok'=>false,'message'=>'تعذرت المزامنة الجماعية مع Google Sheets'],500);}
        }
        if($action==='telegram_groups'){
            if(!isset($_SESSION['role'])) respond(['ok'=>false,'message'=>'سجل الدخول مجددًا'],401);
            respond(['ok'=>true,'data'=>telegramGroups($pdo,$_SESSION['role']!=='admin')]);
        }
        if($action==='employee_today_count'){
            if(($_SESSION['role']??'')!=='employee') respond(['ok'=>false,'message'=>'سجل الدخول مجددًا'],401);
            $riyadh=new DateTimeZone('Asia/Riyadh');$utc=new DateTimeZone('UTC');$start=(new DateTimeImmutable('today',$riyadh))->setTimezone($utc);$end=$start->modify('+1 day');
            $statement=$pdo->prepare('SELECT COUNT(DISTINCT phone) FROM records WHERE employee_id=? AND created_at>=? AND created_at<?');$statement->execute([(string)$_SESSION['employee_id'],$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
            respond(['ok'=>true,'count'=>(int)$statement->fetchColumn(),'date'=>(new DateTimeImmutable('today',$riyadh))->format('Y-m-d')]);
        }
        if($action==='telegram_links'){
            requireAdmin();
            $rows=$pdo->query("SELECT l.*,g.title AS group_title,(SELECT GROUP_CONCAT(c.college SEPARATOR '، ') FROM telegram_group_colleges c WHERE c.group_id=g.id) AS college FROM telegram_links l JOIN telegram_groups g ON g.id=l.group_id ORDER BY l.created_at DESC")->fetchAll();
            respond(['ok'=>true,'data'=>$rows]);
        }
        respond(['ok' => false, 'message' => 'طلب غير معروف'], 404);
    }

    $data = input();
    $action = $data['action'] ?? '';

    if ($action === 'admin_login') {
        $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $statement->execute(['admin_password_hash']);
        $hash = (string) $statement->fetchColumn();
        if (!password_verify((string) ($data['password'] ?? ''), $hash)) {
            respond(['ok' => false, 'message' => 'كلمة المرور غير صحيحة'], 401);
        }
        session_regenerate_id(true);
        $_SESSION['role'] = 'admin';
        respond(['ok' => true]);
    }

    if ($action === 'employee_login') {
        $password = (string) ($data['password'] ?? '');
        foreach ($pdo->query('SELECT id, name, password_hash, is_blocked FROM employees') as $employee) {
            if (!password_verify($password, $employee['password_hash'])) continue;
            if ((bool) $employee['is_blocked']) respond(['ok' => false, 'message' => 'تم حظر هذا الحساب، تواصل مع المدير'], 403);
            session_regenerate_id(true);
            $_SESSION['role'] = 'employee';
            $_SESSION['employee_id'] = $employee['id'];
            $_SESSION['employee_name'] = $employee['name'];
            respond(['ok' => true, 'employee' => ['id' => $employee['id'], 'name' => $employee['name'], 'blocked' => false]]);
        }
        respond(['ok' => false, 'message' => 'كلمة المرور غير صحيحة'], 401);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        respond(['ok' => true]);
    }

    if ($action === 'check_phone') {
        $phone = trim((string) ($data['phone'] ?? ''));
        if (!preg_match('/^05\d{8}$/', $phone)) {
            respond(['ok' => false, 'message' => 'رقم الجوال غير صحيح'], 422);
        }
        $statement = $pdo->prepare('SELECT 1 FROM blocked_phones WHERE phone = ? LIMIT 1');
        $statement->execute([$phone]);
        respond(['ok' => true, 'blocked' => (bool) $statement->fetchColumn()]);
    }

    if ($action === 'submit_record') {
        if (($_SESSION['role'] ?? '') !== 'employee') respond(['ok' => false, 'message' => 'سجل الدخول مجددًا'], 401);
        $studentName = trim((string) ($data['studentName'] ?? ''));
        $studentGender = trim((string) ($data['studentGender'] ?? ''));
        $waedProgram = trim((string) ($data['waedProgram'] ?? ''));
        $previousBatch = trim((string) ($data['previousBatch'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $universityId = trim((string) ($data['universityId'] ?? ''));
        $college = trim((string) ($data['college'] ?? ''));
        $method = trim((string) ($data['method'] ?? ''));
        $previousStatuses=['منسحب','معتذر','تحويل داخلي','تحويل خارجي','طالب جديد'];$needsUniversity=['معتذر','تحويل داخلي','تحويل خارجي'];
        $specialStatus=in_array($previousBatch,$needsUniversity,true);$validUniversity=$universityId===''||preg_match('/^(23|24|25|26|27)\d{5}$/',$universityId);
        if ($studentName === '' || !in_array($studentGender, ['طالب','طالبة'], true) || !in_array($waedProgram, ['نعم','لا'], true) || !in_array($previousBatch, $previousStatuses, true) || ($specialStatus && $universityId === '') || !preg_match('/^05\d{8}$/', $phone) || !$validUniversity || $college === '' || $method === '') {
            respond(['ok' => false, 'message' => 'بيانات التسجيل غير صحيحة'], 422);
        }
        $blockedCheck = $pdo->prepare('SELECT 1 FROM blocked_phones WHERE phone = ?');
        $blockedCheck->execute([$phone]);
        $blocked = (bool) $blockedCheck->fetchColumn();
        if ($universityId !== '') {
            $duplicateCheck = $pdo->prepare('SELECT phone, university_id FROM records WHERE phone = ? OR university_id = ?');
            $duplicateCheck->execute([$phone, $universityId]);
        } else {
            $duplicateCheck = $pdo->prepare('SELECT phone, university_id FROM records WHERE phone = ?');
            $duplicateCheck->execute([$phone]);
        }
        $matches = $duplicateCheck->fetchAll();
        $phoneDuplicate = count(array_filter($matches, static fn(array $row): bool => $row['phone'] === $phone)) > 0;
        $universityDuplicate = $universityId !== '' && count(array_filter($matches, static fn(array $row): bool => $row['university_id'] === $universityId)) > 0;
        $reasons = array_filter([$blocked ? 'رقم الجوال محظور' : '', $phoneDuplicate ? 'رقم الجوال مكرر' : '', $universityDuplicate ? 'الرقم الجامعي مكرر' : '']);
        $reason = implode('، ', $reasons);
        $warning = $reason !== '';
        $id = (string) ($data['id'] ?? '');
        if ($id === '') respond(['ok' => false, 'message' => 'معرف السجل مفقود'], 422);
        $createdAt = date('Y-m-d H:i:s');
        $employeeId = (string) $_SESSION['employee_id'];
        $employeeName = (string) $_SESSION['employee_name'];
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO records (id, student_name, student_gender, waed_program, previous_batch, phone, university_id, college, verification_method, employee_id, employee_name, has_warning, warning_reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$id, $studentName, $studentGender, $waedProgram, $previousBatch, $phone, $universityId, $college, $method, $employeeId, $employeeName, $warning ? 1 : 0, $reason, $createdAt]);
        $alert = null;
        if ($warning) {
            $alertId = (string) ($data['alertId'] ?? '');
            $insertAlert = $pdo->prepare('INSERT INTO alerts (id, record_id, reason, employee_note, employee_id, employee_name, student_name, student_gender, waed_program, previous_batch, phone, university_id, college, verification_method, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insertAlert->execute([$alertId, $id, $reason, '', $employeeId, $employeeName, $studentName, $studentGender, $waedProgram, $previousBatch, $phone, $universityId, $college, $method, $createdAt]);
            $alert = ['id'=>$alertId,'recordId'=>$id,'reason'=>$reason,'employeeNote'=>'','employeeId'=>$employeeId,'employeeName'=>$employeeName,'studentName'=>$studentName,'studentGender'=>$studentGender,'waedProgram'=>$waedProgram,'previousBatch'=>$previousBatch,'phone'=>$phone,'universityId'=>$universityId,'college'=>$college,'method'=>$method,'createdAt'=>$createdAt,'read'=>false];
        }
        $pdo->commit();
        $record = ['id'=>$id,'studentName'=>$studentName,'studentGender'=>$studentGender,'waedProgram'=>$waedProgram,'previousBatch'=>$previousBatch,'phone'=>$phone,'universityId'=>$universityId,'college'=>$college,'method'=>$method,'employeeId'=>$employeeId,'employeeName'=>$employeeName,'createdAt'=>$createdAt,'warning'=>$warning,'warningReason'=>$reason];
        sendToGoogleSheets('record', 'upsert', $record);
        if ($alert !== null) sendToGoogleSheets('alert', 'upsert', $alert);
        respond(['ok' => true, 'record' => $record, 'alert' => $alert]);
    }

    if ($action === 'add_alert_note') {
        if (($_SESSION['role'] ?? '') !== 'employee') respond(['ok'=>false,'message'=>'سجل الدخول مجددًا'],401);
        $recordId=(string)($data['recordId']??''); $note=trim((string)($data['note']??''));
        $statement=$pdo->prepare('UPDATE alerts SET employee_note=? WHERE record_id=? AND employee_id=?');
        $statement->execute([$note,$recordId,(string)$_SESSION['employee_id']]);
        $lookup=$pdo->prepare('SELECT * FROM alerts WHERE record_id=? AND employee_id=? LIMIT 1'); $lookup->execute([$recordId,(string)$_SESSION['employee_id']]); $row=$lookup->fetch();
        if($row){$item=['id'=>$row['id'],'recordId'=>$row['record_id'],'reason'=>$row['reason'],'employeeNote'=>$row['employee_note'],'employeeId'=>$row['employee_id'],'employeeName'=>$row['employee_name'],'studentName'=>$row['student_name'],'studentGender'=>$row['student_gender']??'','waedProgram'=>$row['waed_program']??'','previousBatch'=>$row['previous_batch']??'','phone'=>$row['phone'],'universityId'=>$row['university_id'],'college'=>$row['college'],'method'=>$row['verification_method'],'read'=>(bool)$row['is_read'],'createdAt'=>$row['created_at']];sendToGoogleSheets('alert','upsert',$item);}
        respond(['ok'=>true]);
    }

    if($action==='create_telegram_link'){
        if(($_SESSION['role']??'')!=='employee') respond(['ok'=>false,'message'=>'سجل الدخول مجددًا'],401);
        $recordId=(string)($data['recordId']??'');$groupId=(int)($data['groupId']??0);$employeeId=(string)$_SESSION['employee_id'];
        $recordQuery=$pdo->prepare('SELECT * FROM records WHERE id=? AND employee_id=?');$recordQuery->execute([$recordId,$employeeId]);$record=$recordQuery->fetch();
        if(!$record) respond(['ok'=>false,'message'=>'السجل غير موجود'],404);
        if(in_array(($record['previous_batch']??''),['معتذر','تحويل داخلي','تحويل خارجي'],true)) respond(['ok'=>false,'message'=>'لا يمكن إنشاء رابط تيليجرام لهذه الحالة'],422);
        $groupQuery=$pdo->prepare("SELECT g.* FROM telegram_groups g WHERE g.id=? AND g.is_active=1 AND EXISTS(SELECT 1 FROM telegram_group_colleges c WHERE c.group_id=g.id AND c.college=?)");$groupQuery->execute([$groupId,$record['college']]);$group=$groupQuery->fetch();
        if(!$group) respond(['ok'=>false,'message'=>'مجموعة الكلية غير مربوطة'],422);
        $existing=$pdo->prepare('SELECT l.*,g.is_waed_group FROM telegram_links l JOIN telegram_groups g ON g.id=l.group_id WHERE l.record_id=? AND l.revoked_at IS NULL');$existing->execute([$recordId]);$oldLinks=$existing->fetchAll();
        $collegeLink='';$waedLink='';foreach($oldLinks as $old){if((int)$old['is_waed_group']===1)$waedLink=$old['invite_link'];elseif((int)$old['group_id']===$groupId)$collegeLink=$old['invite_link'];}
        $expires=time()+86400;$insert=$pdo->prepare('INSERT INTO telegram_links (id,record_id,group_id,chat_id,invite_link,link_name,expires_at,created_by) VALUES (?,?,?,?,?,?,?,?)');
        $createLink=function(array $target)use($pdo,$insert,$record,$recordId,$employeeId,$expires){$result=telegramRequest('createChatInviteLink',['chat_id'=>$target['chat_id'],'name'=>substr($record['phone'],0,32),'expire_date'=>$expires,'member_limit'=>1]);$link=(string)$result['invite_link'];$insert->execute([bin2hex(random_bytes(16)),$recordId,$target['id'],$target['chat_id'],$link,$record['phone'],date('Y-m-d H:i:s',$expires),$employeeId]);return $link;};
        if($collegeLink==='')$collegeLink=$createLink($group);
        if(($record['waed_program']??'')==='نعم'){$waedQuery=$pdo->query('SELECT * FROM telegram_groups WHERE is_waed_group=1 AND is_active=1 LIMIT 1');$waedGroup=$waedQuery->fetch();if(!$waedGroup)respond(['ok'=>false,'message'=>'لم يتم تحديد مجموعة برنامج واعد في الإدارة'],422);if((int)$waedGroup['id']!==(int)$group['id']&&$waedLink==='')$waedLink=$createLink($waedGroup);}
        respond(['ok'=>true,'link'=>$collegeLink,'waedLink'=>$waedLink,'message'=>telegramStudentMessage($record,$collegeLink,$waedLink)]);
    }

    requireAdmin();

    if($action==='telegram_setup'){
        $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';$dir=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/');$url=$scheme.'://'.$_SERVER['HTTP_HOST'].$dir.'/telegram_webhook.php';
        $result=telegramRequest('setWebhook',['url'=>$url,'secret_token'=>TELEGRAM_WEBHOOK_SECRET,'allowed_updates'=>['message','chat_member']]);respond(['ok'=>true,'message'=>'تم تفعيل Webhook','url'=>$url,'result'=>$result]);
    }
    if($action==='link_telegram_group'){
        $id=(int)($data['id']??0);$colleges=$data['colleges']??[];if(!is_array($colleges))$colleges=[];$pdo->beginTransaction();$delete=$pdo->prepare('DELETE FROM telegram_group_colleges WHERE group_id=?');$delete->execute([$id]);$insert=$pdo->prepare('INSERT INTO telegram_group_colleges (group_id,college) VALUES (?,?)');foreach($colleges as $college){$college=trim((string)$college);if($college!=='')$insert->execute([$id,$college]);}$legacy=$pdo->prepare('UPDATE telegram_groups SET college=? WHERE id=?');$legacy->execute([(string)($colleges[0]??''),$id]);$pdo->commit();respond(['ok'=>true,'data'=>telegramGroups($pdo)]);
    }
    if($action==='toggle_telegram_group'){
        $statement=$pdo->prepare('UPDATE telegram_groups SET is_active=NOT is_active WHERE id=?');$statement->execute([(int)($data['id']??0)]);respond(['ok'=>true,'data'=>telegramGroups($pdo)]);
    }
    if($action==='set_waed_group'){
        $id=(int)($data['id']??0);$pdo->beginTransaction();$pdo->exec('UPDATE telegram_groups SET is_waed_group=0');$statement=$pdo->prepare('UPDATE telegram_groups SET is_waed_group=1,is_active=1 WHERE id=?');$statement->execute([$id]);$pdo->commit();respond(['ok'=>true,'data'=>telegramGroups($pdo)]);
    }
    if($action==='revoke_telegram_link'){
        $id=(string)($data['id']??'');$query=$pdo->prepare('SELECT * FROM telegram_links WHERE id=?');$query->execute([$id]);$link=$query->fetch();if(!$link)respond(['ok'=>false,'message'=>'الرابط غير موجود'],404);
        if($link['revoked_at']===null){telegramRequest('revokeChatInviteLink',['chat_id'=>$link['chat_id'],'invite_link'=>$link['invite_link']]);if($link['joined_user_id']!==''){telegramRequest('banChatMember',['chat_id'=>$link['chat_id'],'user_id'=>$link['joined_user_id']]);telegramRequest('unbanChatMember',['chat_id'=>$link['chat_id'],'user_id'=>$link['joined_user_id'],'only_if_banned'=>true]);}$update=$pdo->prepare('UPDATE telegram_links SET revoked_at=NOW(),revoked_by=? WHERE id=?');$update->execute(['admin',$id]);}
        respond(['ok'=>true,'message'=>$link['joined_user_id']!==''?'تم إلغاء الرابط وإخراج المستخدم':'تم إلغاء الرابط']);
    }

    if ($action === 'add_college') {
        $category = (string) ($data['category'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));
        if (!in_array($category, ['health', 'science', 'literary'], true) || $name === '') {
            respond(['ok' => false, 'message' => 'بيانات الكلية غير صحيحة'], 422);
        }
        $statement = $pdo->prepare('INSERT INTO colleges (category, name) VALUES (?, ?)');
        $statement->execute([$category, $name]);
        respond(['ok' => true, 'data' => options($pdo)]);
    }

    if ($action === 'delete_college') {
        $statement = $pdo->prepare('DELETE FROM colleges WHERE id = ?');
        $statement->execute([(int) ($data['id'] ?? 0)]);
        respond(['ok' => true, 'data' => options($pdo)]);
    }

    if ($action === 'add_method') {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') respond(['ok' => false, 'message' => 'اكتب طريقة التحقق'], 422);
        $statement = $pdo->prepare('INSERT INTO verification_methods (name) VALUES (?)');
        $statement->execute([$name]);
        respond(['ok' => true, 'data' => options($pdo)]);
    }

    if ($action === 'delete_method') {
        $statement = $pdo->prepare('DELETE FROM verification_methods WHERE id = ?');
        $statement->execute([(int) ($data['id'] ?? 0)]);
        respond(['ok' => true, 'data' => options($pdo)]);
    }

    if ($action === 'block_phone') {
        $phone = trim((string) ($data['phone'] ?? ''));
        if (!preg_match('/^05\d{8}$/', $phone)) {
            respond(['ok' => false, 'message' => 'رقم الجوال غير صحيح'], 422);
        }
        $statement = $pdo->prepare('INSERT IGNORE INTO blocked_phones (phone) VALUES (?)');
        $statement->execute([$phone]);
        $phones = $pdo->query('SELECT phone FROM blocked_phones ORDER BY created_at DESC')->fetchAll(PDO::FETCH_COLUMN);
        respond(['ok' => true, 'data' => $phones]);
    }

    if ($action === 'unblock_phone') {
        $phone = trim((string) ($data['phone'] ?? ''));
        $statement = $pdo->prepare('DELETE FROM blocked_phones WHERE phone = ?');
        $statement->execute([$phone]);
        $phones = $pdo->query('SELECT phone FROM blocked_phones ORDER BY created_at DESC')->fetchAll(PDO::FETCH_COLUMN);
        respond(['ok' => true, 'data' => $phones]);
    }

    if ($action === 'add_employee') {
        $id = (string) ($data['id'] ?? ''); $name = trim((string) ($data['name'] ?? '')); $password = (string) ($data['password'] ?? '');
        if ($id === '' || $name === '' || $password === '') respond(['ok'=>false,'message'=>'بيانات الموظف غير مكتملة'],422);
        foreach ($pdo->query('SELECT password_hash FROM employees') as $row) if (password_verify($password, $row['password_hash'])) respond(['ok'=>false,'message'=>'كلمة المرور مستخدمة مسبقًا'],409);
        $statement=$pdo->prepare('INSERT INTO employees (id,name,password_hash) VALUES (?,?,?)');
        $statement->execute([$id,$name,password_hash($password,PASSWORD_DEFAULT)]);
        respond(['ok'=>true,'data'=>adminState($pdo)]);
    }

    if ($action === 'toggle_employee') {
        $statement=$pdo->prepare('UPDATE employees SET is_blocked = NOT is_blocked WHERE id = ?');
        $statement->execute([(string)($data['id']??'')]);
        respond(['ok'=>true,'data'=>adminState($pdo)]);
    }

    if ($action === 'edit_record') {
        $statement=$pdo->prepare('UPDATE records SET student_name=?,phone=?,university_id=?,college=?,verification_method=? WHERE id=?');
        $statement->execute([(string)$data['studentName'],(string)$data['phone'],(string)$data['universityId'],(string)$data['college'],(string)$data['method'],(string)$data['id']]);
        $alert=$pdo->prepare('UPDATE alerts SET student_name=?,phone=?,university_id=?,college=?,verification_method=? WHERE record_id=?');
        $alert->execute([(string)$data['studentName'],(string)$data['phone'],(string)$data['universityId'],(string)$data['college'],(string)$data['method'],(string)$data['id']]);
        $state=adminState($pdo);
        foreach($state['records'] as $record) if($record['id']===(string)$data['id']) sendToGoogleSheets('record','upsert',$record);
        foreach($state['alerts'] as $item) if($item['recordId']===(string)$data['id']) sendToGoogleSheets('alert','upsert',$item);
        respond(['ok'=>true,'data'=>$state]);
    }

    if ($action === 'delete_record') {
        $recordId=(string)($data['id']??'');
        $alertIds=[]; $lookup=$pdo->prepare('SELECT id FROM alerts WHERE record_id=?'); $lookup->execute([$recordId]); $alertIds=$lookup->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        $statement=$pdo->prepare('DELETE FROM alerts WHERE record_id=?'); $statement->execute([$recordId]);
        $statement=$pdo->prepare('DELETE FROM records WHERE id=?'); $statement->execute([$recordId]);
        $pdo->commit(); sendToGoogleSheets('record','delete',['id'=>$recordId]); foreach($alertIds as $alertId) sendToGoogleSheets('alert','delete',['id'=>$alertId]); respond(['ok'=>true,'data'=>adminState($pdo)]);
    }

    if ($action === 'mark_alerts_read') {
        $pdo->exec('UPDATE alerts SET is_read=1');
        $state=adminState($pdo); foreach($state['alerts'] as $item) sendToGoogleSheets('alert','upsert',$item); respond(['ok'=>true,'data'=>$state]);
    }

    if ($action === 'clear_alerts') {
        $pdo->exec('DELETE FROM alerts');
        sendToGoogleSheets('alerts','clear'); respond(['ok'=>true,'data'=>adminState($pdo)]);
    }

    if ($action === 'delete_alert') {
        $alertId=(string)($data['id']??''); $statement=$pdo->prepare('DELETE FROM alerts WHERE id=?'); $statement->execute([$alertId]); sendToGoogleSheets('alert','delete',['id'=>$alertId]);
        respond(['ok'=>true,'data'=>adminState($pdo)]);
    }

    respond(['ok' => false, 'message' => 'طلب غير معروف'], 404);
} catch (PDOException $error) {
    error_log('API database error: ' . $error->getMessage());
    $code = (int) ($error->errorInfo[1] ?? $error->getCode());
    $message = $code === 1062 ? 'الخيار موجود مسبقًا' : 'حدث خطأ في قاعدة البيانات';
    respond(['ok' => false, 'message' => $message, 'code' => $code], 500);
} catch (Throwable $error) {
    error_log('API error: ' . $error->getMessage());
    respond(['ok' => false, 'message' => 'حدث خطأ في الخادم'], 500);
}
