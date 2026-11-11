<?php
declare(strict_types=1);
require __DIR__.'/config.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI ONLY\n");}
if(BOT_TOKEN==='8809114401:AAE5nK1htav6TplJB7H9rJdVtsRXIV8YCUs'||OWNER_ID===7351854399){fwrite(STDERR,"EDIT CONFIG.PHP FIRST.\n");exit(1);}
function blank_db():array{return ['users'=>[],'permissions'=>[],'channels'=>[],'states'=>[],'exchanges'=>[],'invite_map'=>[],'offset'=>0,'next_exchange_id'=>1];}
function db_read():array{
 if(!file_exists(DB_FILE)||filesize(DB_FILE)===0){$d=blank_db();db_write($d);return $d;}
 $d=json_decode((string)@file_get_contents(DB_FILE),true);
 if(!is_array($d)){$bak=DB_FILE.'.bad-'.date('YmdHis');@copy(DB_FILE,$bak);$d=blank_db();db_write($d);return $d;}
 foreach(blank_db() as $k=>$v)if(!array_key_exists($k,$d))$d[$k]=$v;return $d;
}
function db_write(array $d):void{
 $j=json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 if($j===false)throw new RuntimeException('DATABASE JSON ERROR');
 $tmp=DB_FILE.'.tmp';$n=@file_put_contents($tmp,$j);
 if($n===false||$n!==strlen($j)){@unlink($tmp);throw new RuntimeException('DATABASE WRITE FAILED');}
 if(!@rename($tmp,DB_FILE)){if(file_exists(DB_FILE))@unlink(DB_FILE);if(!@rename($tmp,DB_FILE)){@unlink($tmp);throw new RuntimeException('DATABASE RENAME FAILED');}}
}
function api(string $method,array $p=[]):array{
 $ctx=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($p),'timeout'=>REQUEST_TIMEOUT,'ignore_errors'=>true]]);
 $raw=@file_get_contents('https://api.telegram.org/bot'.BOT_TOKEN.'/'.$method,false,$ctx);
 $j=json_decode((string)$raw,true);return is_array($j)?$j:['ok'=>false,'description'=>'NETWORK ERROR'];
}
function me():array{static $m=null;if($m===null){$r=api('getMe');if(empty($r['ok']))throw new RuntimeException('BOT TOKEN ERROR: '.($r['description']??''));$m=$r['result'];}return $m;}
function owner(int $id):bool{return $id===(int)OWNER_ID;}
function allowed(int $id,array $d):bool{return owner($id)||!empty($d['permissions'][(string)$id]);}
function up(string $s):string{return function_exists('mb_strtoupper')?mb_strtoupper($s,'UTF-8'):strtoupper($s);}
// Convert normal CAPITAL ENGLISH into the requested Unicode small-caps style.
function fancy(string $s):string{
 $map=['A'=>'ᴀ','B'=>'ʙ','C'=>'ᴄ','D'=>'ᴅ','E'=>'ᴇ','F'=>'ꜰ','G'=>'ɢ','H'=>'ʜ','I'=>'ɪ','J'=>'ᴊ','K'=>'ᴋ','L'=>'ʟ','M'=>'ᴍ','N'=>'ɴ','O'=>'ᴏ','P'=>'ᴘ','Q'=>'ǫ','R'=>'ʀ','S'=>'ꜱ','T'=>'ᴛ','U'=>'ᴜ','V'=>'ᴠ','W'=>'ᴡ','X'=>'x','Y'=>'ʏ','Z'=>'ᴢ'];
 return strtr($s,$map);
}
// Keep invite URLs untouched so Telegram links remain clickable.
function styled_text(string $text):string{
 $parts=preg_split('~(https?://[^\s]+)~iu',$text,-1,PREG_SPLIT_DELIM_CAPTURE);
 foreach($parts as $i=>$part){if(!preg_match('~^https?://~i',$part))$parts[$i]=fancy(up($part));}
 return implode('',$parts);
}
function send(int|string $to,string $text,?array $kb=null):array{$p=['chat_id'=>$to,'text'=>styled_text($text),'disable_web_page_preview'=>true];if($kb)$p['reply_markup']=json_encode($kb,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);return api('sendMessage',$p);}
function btn(string $t,string $d,string $s='primary'):array{return ['text'=>fancy(up($t)),'callback_data'=>$d,'style'=>$s];}
function main_kb(int $uid,array $d):array{
 if(owner($uid))return ['inline_keyboard'=>[
  [btn('ADD USER','add_user','success'),btn('ADD OWNER CHANNEL','add_owner_channel','primary')],
  [btn('M2M EXCHANGE','m2m','success'),btn('MY CHANNEL','my_channel','primary')],
  [btn('MY EXCHANGES','my_exchanges','primary'),btn('HELP','help','primary')]
 ]];
 return ['inline_keyboard'=>[
  [btn('ADD YOUR CHANNEL','add_your_channel','success')],
  [btn('MY CHANNEL','my_channel','primary'),btn('MY EXCHANGES','my_exchanges','primary')],
  [btn('HELP','help','primary')]
 ]];
}
function remember(array &$d,array $u):void{
 $id=(string)($u['id']??'');if($id==='')return;$old=$d['users'][$id]??[];
 $d['users'][$id]=array_merge($old,['id'=>(int)$id,'first_name'=>(string)($u['first_name']??''),'username'=>(string)($u['username']??''),'last_seen'=>time(),'started_at'=>$old['started_at']??time()]);
}
function channel_info(string $cid,int $uid):array{
 $r=api('getChat',['chat_id'=>$cid]);if(empty($r['ok']))return ['ok'=>false,'error'=>'CHANNEL NOT FOUND. CHECK ID AND BOT ADMIN STATUS.'];
 if(($r['result']['type']??'')!=='channel')return ['ok'=>false,'error'=>'PLEASE ENTER A TELEGRAM CHANNEL ID.'];
 $bm=api('getChatMember',['chat_id'=>$cid,'user_id'=>(int)me()['id']]);
 if(empty($bm['ok'])||!in_array(($bm['result']['status']??''),['administrator','creator'],true))return ['ok'=>false,'error'=>'PLEASE MAKE BOT ADMIN IN THIS CHANNEL.'];
 if(($bm['result']['status']??'')!=='creator'&&empty($bm['result']['can_invite_users']))return ['ok'=>false,'error'=>'BOT NEEDS MANAGE INVITE LINKS PERMISSION.'];
 $um=api('getChatMember',['chat_id'=>$cid,'user_id'=>$uid]);
 if(empty($um['ok'])||!in_array(($um['result']['status']??''),['administrator','creator'],true))return ['ok'=>false,'error'=>'YOU MUST BE CHANNEL OWNER OR ADMIN.'];
 $c=$r['result'];return ['ok'=>true,'channel'=>['id'=>(string)$c['id'],'title'=>up((string)($c['title']??'CHANNEL')),'owner_id'=>$uid,'added_at'=>time()]];
}
function add_channel(array &$d,int $uid,string $cid,string $kind):string{
 $cid=trim($cid);if(!preg_match('/^-100\d{5,}$/',$cid))return 'INVALID CHANNEL ID. USE -100XXXXXXXXXX FORMAT.';
 $r=channel_info($cid,$uid);if(empty($r['ok']))return $r['error'];
 $c=$r['channel'];$c['kind']=$kind;$d['channels'][$cid]=$c;
 return "CHANNEL ADDED SUCCESSFULLY.\n\nCHANNEL: {$c['title']}\nCHANNEL ID: {$cid}";
}
function my_channel_ids(array $d,int $uid):array{$o=[];foreach($d['channels'] as $id=>$c)if((int)$c['owner_id']===$uid)$o[]=$id;return $o;}
function count_req(array $e,int|string $cid):int{$key=(string)$cid;return count($e['requests'][$key]??[]);}
function start_exchange(array &$d,int $ownerId,int $partnerId,int $target):array{
 if($target<1||$target>MAX_TARGET)return ['ok'=>false,'text'=>'TARGET MUST BE BETWEEN 1 AND '.MAX_TARGET.'.'];
 if($partnerId===$ownerId)return ['ok'=>false,'text'=>'PLEASE SELECT A DIFFERENT PARTNER USER.'];
 if(!isset($d['permissions'][(string)$partnerId]))return ['ok'=>false,'text'=>'THIS USER IS NOT ADDED YET. USE ADD USER FIRST.'];
 $aa=my_channel_ids($d,$ownerId);$bb=my_channel_ids($d,$partnerId);
 if(!$aa)return ['ok'=>false,'text'=>'FIRST ADD YOUR OWNER CHANNEL.'];
 if(!$bb)return ['ok'=>false,'text'=>'PARTNER HAS NOT ADDED THEIR CHANNEL YET.'];
 $a=$aa[0];$b=$bb[0];if($a===$b)return ['ok'=>false,'text'=>'CHANNELS MUST BE DIFFERENT.'];
 $la=api('createChatInviteLink',['chat_id'=>$a,'name'=>'M2M-OWNER-'.time(),'creates_join_request'=>true]);
 if(empty($la['ok']))return ['ok'=>false,'text'=>'OWNER CHANNEL LINK ERROR: '.up((string)($la['description']??''))];
 $lb=api('createChatInviteLink',['chat_id'=>$b,'name'=>'M2M-PARTNER-'.time(),'creates_join_request'=>true]);
 if(empty($lb['ok'])){api('revokeChatInviteLink',['chat_id'=>$a,'invite_link'=>$la['result']['invite_link']]);return ['ok'=>false,'text'=>'PARTNER CHANNEL LINK ERROR: '.up((string)($lb['description']??''))];}
 $id=(int)$d['next_exchange_id']++;$linkA=$la['result']['invite_link'];$linkB=$lb['result']['invite_link'];
 $d['exchanges'][(string)$id]=['id'=>$id,'owner_id'=>$ownerId,'partner_id'=>$partnerId,'channel_a'=>$a,'channel_b'=>$b,'link_a'=>$linkA,'link_b'=>$linkB,'target'=>$target,'status'=>'active','created_at'=>time(),'requests'=>[$a=>[],$b=>[]],'closed'=>[$a=>false,$b=>false]];
 $d['invite_map'][$linkA]=['exchange_id'=>$id,'channel_id'=>$a];$d['invite_map'][$linkB]=['exchange_id'=>$id,'channel_id'=>$b];
 $ca=$d['channels'][$a]['title'];$cb=$d['channels'][$b]['title'];
 send($ownerId,"M2M EXCHANGE #{$id} STARTED.\n\nYOUR CHANNEL: {$ca}\nYOUR REQUEST LINK:\n{$linkA}\n\nPARTNER CHANNEL: {$cb}\nPARTNER REQUEST LINK:\n{$linkB}\n\nTARGET: ".$target." REQUESTS PER CHANNEL.");
 send($partnerId,"M2M EXCHANGE #{$id} STARTED.\n\nYOUR CHANNEL: {$cb}\nYOUR REQUEST LINK:\n{$linkB}\n\nOWNER CHANNEL: {$ca}\nOWNER REQUEST LINK:\n{$linkA}\n\nTARGET: ".$target." REQUESTS PER CHANNEL.");
 send(OWNER_ID,"ADMIN UPDATE: M2M EXCHANGE #{$id} STARTED.\nOWNER: {$ownerId}\nPARTNER: {$partnerId}\nCHANNEL A: {$ca}\nCHANNEL B: {$cb}");
 return ['ok'=>true,'text'=>"M2M EXCHANGE #{$id} CONNECTED.\nBOTH USERS RECEIVED BOTH CHANNEL LINKS.\nREQUEST COUNTING STARTED."];
}
function status_text(array $d,int $uid):string{
 $s="YOUR M2M EXCHANGES\n";$n=0;foreach($d['exchanges'] as $e){if(!owner($uid)&&(int)$e['owner_id']!==$uid&&(int)$e['partner_id']!==$uid)continue;$a=count_req($e,(string)$e['channel_a']);$b=count_req($e,(string)$e['channel_b']);$s.="\n#{$e['id']} ".up($e['status'])."\nOWNER CHANNEL: {$a}/{$e['target']}\nPARTNER CHANNEL: {$b}/{$e['target']}\n";$n++;}return $n?$s:'NO EXCHANGES FOUND.';
}
function on_request(array $jr,array &$d):void{
 $cid=(string)($jr['chat']['id']??'');$u=$jr['from']??[];$uid=(int)($u['id']??0);$link=(string)($jr['invite_link']['invite_link']??'');
 if(!$uid||!$link||empty($d['invite_map'][$link])||!empty($u['is_bot']))return;
 $map=$d['invite_map'][$link];$eid=(string)$map['exchange_id'];if(empty($d['exchanges'][$eid]))return;$e=&$d['exchanges'][$eid];
 if($e['status']!=='active'||$cid!==(string)$map['channel_id']){unset($e);return;}
 if(!isset($e['requests'][$cid]))$e['requests'][$cid]=[];if(isset($e['requests'][$cid][(string)$uid])){unset($e);return;}
 $e['requests'][$cid][(string)$uid]=['user_id'=>$uid,'name'=>up((string)($u['first_name']??'')),'date'=>(int)($jr['date']??time())];
 $a=count_req($e,(string)$e['channel_a']);$b=count_req($e,(string)$e['channel_b']);$target=(int)$e['target'];
 $side=$cid===(string)$e['channel_a']?'OWNER':'PARTNER';
 $msg="NEW JOIN REQUEST\nEXCHANGE: #{$eid}\nSIDE: {$side}\nUSER ID: {$uid}\nOWNER CHANNEL: {$a}/{$target}\nPARTNER CHANNEL: {$b}/{$target}\nSTATUS: REQUEST RECEIVED. NOT ACCEPTED.";
 foreach(array_unique([(int)$e['owner_id'],(int)$e['partner_id'],(int)OWNER_ID]) as $to)send($to,$msg);
 $justCompleted=sync_exchange_closures($e);
 if($justCompleted){$done="M2M EXCHANGE #{$eid} COMPLETED.\nOWNER REQUESTS: {$a}\nPARTNER REQUESTS: {$b}\nBOTH LINKS REVOKED.\nTHESE ARE REQUEST COUNTS, NOT APPROVED MEMBERS.";foreach(array_unique([(int)$e['owner_id'],(int)$e['partner_id'],(int)OWNER_ID]) as $to)send($to,$done);}
 unset($e);
}
function sync_exchange_closures(array &$e):bool{
 if(($e['status']??'')!=='active')return false;
 $target=(int)$e['target'];
 foreach([(string)$e['channel_a']=>'link_a',(string)$e['channel_b']=>'link_b'] as $ch=>$linkKey){
  if(count_req($e,$ch)>=$target&&empty($e['closed'][$ch])){
   $rv=api('revokeChatInviteLink',['chat_id'=>$ch,'invite_link'=>$e[$linkKey]]);
   if(!empty($rv['ok']))$e['closed'][$ch]=true;
  }
 }
 $a=(string)$e['channel_a'];$b=(string)$e['channel_b'];
 if(count_req($e,$a)>=$target&&count_req($e,$b)>=$target&&!empty($e['closed'][$a])&&!empty($e['closed'][$b])){$e['status']='completed';return true;}
 return false;
}
function retry_pending_closures(array &$d):void{
 foreach($d['exchanges'] as &$e){
  $justCompleted=sync_exchange_closures($e);
  if($justCompleted){$a=count_req($e,(string)$e['channel_a']);$b=count_req($e,(string)$e['channel_b']);$done="M2M EXCHANGE #{$e['id']} COMPLETED.\nOWNER REQUESTS: {$a}\nPARTNER REQUESTS: {$b}\nBOTH LINKS REVOKED.\nTHESE ARE REQUEST COUNTS, NOT APPROVED MEMBERS.";foreach(array_unique([(int)$e['owner_id'],(int)$e['partner_id'],(int)OWNER_ID]) as $to)send($to,$done);}
 }
 unset($e);
}
function home(int $chat,int $uid,array $d):void{send($chat,"WELCOME TO DADDY  M2M.\n\nOWNER: TAP ADD USER, ADD OWNER CHANNEL, THEN M2M EXCHANGE AND SET YOUR TARGET.\nPARTNER: TAP ADD YOUR CHANNEL. OWNER WILL CONNECT YOU.\n\nBOT COUNTS UNIQUE JOIN REQUESTS. IT DOES NOT AUTO-ACCEPT.",main_kb($uid,$d));}
function cb(array $c,array &$d):void{
 $uid=(int)($c['from']['id']??0);$chat=$c['message']['chat']['id']??$uid;$data=(string)($c['data']??'');
 api('answerCallbackQuery',['callback_query_id'=>$c['id']??'']);
 switch($data){
  case 'add_user':if(!owner($uid)){send($chat,'OWNER ONLY.');return;}$d['states'][(string)$uid]='add_user_id';send($chat,"SEND PARTNER TELEGRAM USER ID.\nPARTNER MUST PRESS /START FIRST.");return;
  case 'add_owner_channel':if(!owner($uid)){send($chat,'OWNER ONLY.');return;}$d['states'][(string)$uid]='add_owner_channel';send($chat,"SEND YOUR CHANNEL ID.\nEXAMPLE: -1001234567890");return;
  case 'add_your_channel':if(!allowed($uid,$d)){send($chat,'OWNER HAS NOT ADDED YOU YET.');return;}$d['states'][(string)$uid]='add_partner_channel';send($chat,"SEND YOUR CHANNEL ID.\nEXAMPLE: -1001234567890");return;
  case 'm2m':if(!owner($uid)){send($chat,'ONLY OWNER CAN START THE CONNECTION. ASK OWNER TO CONNECT YOU.');return;}$d['states'][(string)$uid]='m2m_partner_id';send($chat,"SEND THE PARTNER USER ID.\nAFTER THAT, SEND YOUR REQUIRED REQUEST TARGET.\nEXAMPLE: 500, 1000, 5000 OR ANY NUMBER UP TO ".MAX_TARGET.".");return;
  case 'my_channel':$ids=my_channel_ids($d,$uid);if(!$ids){send($chat,'NO CHANNEL ADDED YET.');return;}$t="YOUR CHANNELS\n";foreach($ids as $id)$t.="\n".$d['channels'][$id]['title']."\n".$id."\n";send($chat,$t);return;
  case 'my_exchanges':send($chat,status_text($d,$uid));return;
  case 'help':send($chat,"SIMPLE FLOW:\n1. OWNER TAPS ADD USER AND SENDS USER ID.\n2. PARTNER TAPS ADD YOUR CHANNEL.\n3. OWNER TAPS ADD OWNER CHANNEL.\n4. OWNER TAPS M2M EXCHANGE AND SENDS PARTNER ID.\n5. BOTH RECEIVE BOTH REQUEST LINKS.\n6. UNIQUE JOIN REQUESTS ARE COUNTED.\n7. OWNER SETS A CUSTOM TARGET FOR EVERY EXCHANGE.\n8. TARGET COMPLETE = LINKS REVOKED AUTOMATICALLY.\n\nBOT DOES NOT ACCEPT REQUESTS.");return;
 }
}
function msg(array $m,array &$d):void{
 if(($m['chat']['type']??'')!=='private'||empty($m['from']['id']))return;
 $uid=(int)$m['from']['id'];$chat=$m['chat']['id'];$text=trim((string)($m['text']??''));if($text==='')return;
 if($text==='/start'){home($chat,$uid,$d);return;}
 if($text==='/status'){send($chat,status_text($d,$uid));return;}
 if(preg_match('/^\/stop\s+(\d+)$/',$text,$mm)&&owner($uid)){
  $eid=$mm[1];if(empty($d['exchanges'][$eid])){send($chat,'EXCHANGE NOT FOUND.');return;}
  $e=&$d['exchanges'][$eid];foreach(['channel_a'=>'link_a','channel_b'=>'link_b'] as $ck=>$lk)api('revokeChatInviteLink',['chat_id'=>$e[$ck],'invite_link'=>$e[$lk]]);$e['status']='stopped';unset($e);send($chat,"EXCHANGE #{$eid} STOPPED.");return;
 }
 $id=(string)$uid;$state=$d['states'][$id]??'';
 if($state==='add_user_id'){
  if(!owner($uid)){unset($d['states'][$id]);return;}
  if(!ctype_digit($text)||!isset($d['users'][$text])){send($chat,'USER NOT FOUND. ASK THEM TO PRESS /START FIRST, THEN SEND THEIR ID.');return;}
  $d['permissions'][$text]=['added_by'=>$uid,'added_at'=>time()];unset($d['states'][$id]);
  send($chat,"USER {$text} ADDED.\nTHEY CAN NOW SEE ADD YOUR CHANNEL.");
  send((int)$text,"OWNER ADDED YOU TO DADDY  M2M.\nOPEN /START AND TAP ADD YOUR CHANNEL.");return;
 }
 if($state==='add_owner_channel'||$state==='add_partner_channel'){
  $kind=$state==='add_owner_channel'?'owner':'partner';
  if($kind==='owner'&&!owner($uid)){send($chat,'OWNER ONLY.');unset($d['states'][$id]);return;}
  if($kind==='partner'&&!allowed($uid,$d)){send($chat,'OWNER HAS NOT ADDED YOU.');unset($d['states'][$id]);return;}
  $r=add_channel($d,$uid,$text,$kind);if(str_contains($r,'SUCCESSFULLY'))unset($d['states'][$id]);send($chat,$r,main_kb($uid,$d));return;
 }
 if($state==='m2m_partner_id'){
  if(!owner($uid)){unset($d['states'][$id]);return;}
  if(!ctype_digit($text)){send($chat,'SEND NUMERIC USER ID.');return;}
  $partnerId=(int)$text;
  if($partnerId===$uid||!isset($d['permissions'][(string)$partnerId])){send($chat,'PARTNER NOT FOUND OR NOT ADDED. USE ADD USER FIRST. SEND A VALID PARTNER USER ID.');return;}
  $d['states'][$id]=['step'=>'m2m_target','partner_id'=>$partnerId];
  send($chat,"NOW SEND THE REQUIRED REQUEST TARGET FOR THIS EXCHANGE.\nEXAMPLES: 100, 500, 1000, 10000.\nMAXIMUM: ".MAX_TARGET.".");return;
 }
 if(is_array($state)&&($state['step']??'')==='m2m_target'){
  if(!owner($uid)){unset($d['states'][$id]);return;}
  if(!ctype_digit($text)||(int)$text<1||(int)$text>MAX_TARGET){send($chat,'INVALID TARGET. SEND A NUMBER FROM 1 TO '.MAX_TARGET.'.');return;}
  $r=start_exchange($d,$uid,(int)$state['partner_id'],(int)$text);unset($d['states'][$id]);send($chat,$r['text'],main_kb($uid,$d));return;
 }
 send($chat,'PLEASE USE THE MENU BUTTONS.',main_kb($uid,$d));
}
function main():void{
 $m=me();echo "DADDY SIMPLE M2M RUNNING AS @".($m['username']??'BOT')."\nPRESS CTRL+C TO STOP.\n";
 while(true){
  $d=db_read();$r=api('getUpdates',['offset'=>(int)$d['offset'],'timeout'=>POLL_TIMEOUT,'allowed_updates'=>json_encode(['message','callback_query','chat_join_request'])]);
  if(empty($r['ok'])){fwrite(STDERR,"POLLING ERROR: ".up((string)($r['description']??''))."\n");sleep(3);continue;}
  foreach($r['result']??[] as $u){$d['offset']=(int)$u['update_id']+1;try{
   if(isset($u['message'])){remember($d,$u['message']['from']??[]);msg($u['message'],$d);}
   if(isset($u['callback_query'])){remember($d,$u['callback_query']['from']??[]);cb($u['callback_query'],$d);}
   if(isset($u['chat_join_request']))on_request($u['chat_join_request'],$d);
  }catch(Throwable $e){error_log('HANDLER ERROR: '.$e->getMessage());}db_write($d);}
  retry_pending_closures($d);
  if(empty($r['result']))db_write($d);
 }
}
try{main();}catch(Throwable $e){fwrite(STDERR,"FATAL: ".$e->getMessage()."\n");exit(1);}
