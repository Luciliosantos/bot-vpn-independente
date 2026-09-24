<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// === DADOS ===
$token = '8995379428:AAEdxzxUPguxuX51HNjUQ8c65HkjPzV4MZY';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments";
$ultimo_id = 0;
$sessao = [];
$pagamentos = [];
$teste_dia = [];

$planos = [
    1 => ['dias'=>1, 'valor'=>1, 'nome'=>'1 Dia — R$ 1,00'],
    2 => ['dias'=>5, 'valor'=>4, 'nome'=>'5 Dias — R$ 4,00'],
    3 => ['dias'=>10, 'valor'=>10, 'nome'=>'10 Dias — R$ 10,00'],
    4 => ['dias'=>15, 'valor'=>14, 'nome'=>'15 Dias — R$ 14,00'],
    5 => ['dias'=>30, 'valor'=>20, 'nome'=>'30 Dias — R$ 20,00'],
];

$operadoras = [
    'vivo'  => ['nome'=>'📱 Vivo', 'valores'=>[10,20,30,50,100]],
    'claro' => ['nome'=>'📱 Claro','valores'=>[15,25,35,50,75]],
    'tim'   => ['nome'=>'📱 Tim',  'valores'=>[10,20,40,60,80]],
    'oi'    => ['nome'=>'📱 Oi',   'valores'=>[15,30,50,70,100]],
];

function comeca_com($t,$i){return substr($t,0,strlen($i))===$i;}

function enviar($d){
    global $api;
    $ch=curl_init($api."sendMessage");
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($d));
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_TIMEOUT,10);
    curl_exec($ch);curl_close($ch);
}

function gerarCred(){
    $l='u'.substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'),0,8);
    $s=substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'),0,10);
    return [$l,$s];
}

function gerarPix($valor,$desc){
    global $api_mp,$mp_token;
    $d=json_encode([
        'transaction_amount'=>(float)$valor,
        'description'=>$desc,
        'payment_method_id'=>'pix',
        'payer'=>['email'=>'cliente@exemplo.com']
    ]);
    $ch=curl_init("$api_mp?access_token=$mp_token");
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$d);
    curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json']);
    curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
    curl_setopt($ch,CURLOPT_TIMEOUT,20);
    $r=json_decode(curl_exec($ch),true);curl_close($ch);
    
    $pix='';
    if(!empty($r['point_of_interaction']['transaction_data']['qr_code']))$pix=$r['point_of_interaction']['transaction_data']['qr_code'];
    elseif(!empty($r['qr_code']))$pix=$r['qr_code'];
    elseif(!empty($r['ticket_url']))$pix=$r['ticket_url'];
    
    return $pix&&!empty($r['id'])?['ok'=>1,'pix'=>$pix,'id'=>$r['id']]:['ok'=>0,'erro'=>$r['message']??'Erro PIX'];
}

echo "✅ BOT LIGADO — Aguardando...\n";

while(true){
    // Verifica pagamentos
    foreach($pagamentos as $uid=>$p){
        if(time()-$p['tempo']>900){unset($pagamentos[$uid]);continue;}
        $ch=curl_init("$api_mp/{$p['pid']}?access_token=$mp_token");
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
        $r=json_decode(curl_exec($ch),true);curl_close($ch);
        if(($r['status']??'')==='approved'){
            if($p['tipo']==='ssh'){
                list($login,$senha)=gerarCred();
                enviar(['chat_id'=>$uid,'text'=>"✅ <b>PAGO!</b>\n👤 Login: <code>$login</code>\n🔑 Senha: <code>$senha</code>",'parse_mode'=>'html']);
                enviar(['chat_id'=>$admin_id,'text'=>"💰 VENDA\nUsuário: $uid\nPlano: {$p['dias']} dias\nValor: R$".number_format($p['valor'],2,',','')]);
            }else{
                enviar(['chat_id'=>$uid,'text'=>"✅ <b>PAGO!</b>\n📱 Recarga em até 8h!",'parse_mode'=>'html']);
                enviar(['chat_id'=>$admin_id,'text'=>"💰 RECARGA\nUsuário: $uid\n📱 {$p['num']} — {$p['op']}\nValor: R$".number_format($p['valor'],2,',','')]);
            }
            unset($pagamentos[$uid]);
        }
    }

    // Pega updates — OFFSET CORRIGIDO
    $url=$api."getUpdates?offset=".($ultimo_id+1)."&timeout=10";
    $r=@file_get_contents($url);
    if(!$r){usleep(500000);continue;}
    $d=json_decode($r,true);
    if(empty($d['result']))continue;

    foreach($d['result'] as $up){
        $ultimo_id=$up['update_id']; // ✅ AVANÇA SEMPRE

        if(!empty($up['callback_query'])){
            $cb=$up['callback_query'];
            $cid=$cb['message']['chat']['id'];
            $uid=$cb['from']['id'];
            $txt=$cb['data'];
            file_get_contents($api."answerCallbackQuery?id=".$cb['id']);

            if(comeca_com($txt,'plano_')){
                $id=(int)substr($txt,6);
                if(!isset($planos[$id]))continue;
                $pl=$planos[$id];
                $pix=gerarPix($pl['valor'],$pl['nome']);
                if($pix['ok']){
                    $pagamentos[$uid]=['pid'=>$pix['id'],'tipo'=>'ssh','dias'=>$pl['dias'],'valor'=>$pl['valor'],'tempo'=>time()];
                    enviar(['chat_id'=>$cid,'text'=>"💳 <b>PIX — {$pl['nome']}</b>\nValor: R$ ".number_format($pl['valor'],2,',','')."\nCopie e cole:\n<pre>{$pix['pix']}</pre>",'parse_mode'=>'html']);
                }else enviar(['chat_id'=>$cid,'text'=>"❌ ".$pix['erro']]);
            }
            elseif(comeca_com($txt,'op_')){
                $op=substr($txt,3);
                $sessao[$cid]['op']=$op;
                $b=[];foreach($operadoras[$op]['valores']as$v)$b[][]=['text'=>"R$ $v,00",'callback_data'=>"val_{$op}_$v"];
                enviar(['chat_id'=>$cid,'text'=>"💰 Valores — {$operadoras[$op]['nome']}",'reply_markup'=>json_encode(['inline_keyboard'=>$b])]);
            }
            elseif(comeca_com($txt,'val_')){
                $e=explode('_',$txt);
                list(,$op,$v)=$e;
                $sessao[$cid]=['op'=>$op,'valor'=>$v,'etapa'=>'num'];
                enviar(['chat_id'=>$cid,'text'=>"📱 Digite o número com DDD:\nEx: 11999998888"]);
            }
            continue;
        }

        if(empty($up['message']))continue;
        $msg=$up['message'];
        $cid=$msg['chat']['id'];
        $uid=$msg['from']['id'];
        $txt=trim($msg['text']??'');

        if(($sessao[$cid]['etapa']??'')==='num'){
            $num=preg_replace('/\D/','',$txt);
            if(strlen($num)<10||strlen($num)>11){
                enviar(['chat_id'=>$cid,'text'=>"❌ Número inválido!"]);continue;
            }
            $op=$sessao[$cid]['op'];
            $v=$sessao[$cid]['valor'];
            $pix=gerarPix($v,"Recarga $num — {$operadoras[$op]['nome']}");
            if(!$pix['ok']){
                enviar(['chat_id'=>$cid,'text'=>"❌ ".$pix['erro']]);
                unset($sessao[$cid]);continue;
            }
            $pagamentos[$uid]=['pid'=>$pix['id'],'tipo'=>'recarga','op'=>$operadoras[$op]['nome'],'num'=>$num,'valor'=>$v,'tempo'=>time()];
            enviar(['chat_id'=>$cid,'text'=>"💳 <b>PIX — RECARGA</b>\n📱 $num\n📶 {$operadoras[$op]['nome']}\n💰 R$ ".number_format($v,2,',','')."\nCopie e cole:\n<pre>{$pix['pix']}</pre>",'parse_mode'=>'html']);
            unset($sessao[$cid]);continue;
        }

        if($txt==='/start'||$txt==='Voltar'){
            enviar(['chat_id'=>$cid,'text'=>'👋 Escolha:','reply_markup'=>json_encode(['keyboard'=>[['Comprar SSH','Teste Grátis'],['Recarga de Celular','Ajuda']],'resize_keyboard'=>true])]);
        }
        elseif($txt==='Comprar SSH'){
            $b=[];foreach($planos as$p)$b[][]=['text'=>$p['nome'],'callback_data'=>"plano_{$p['dias']}"];
            enviar(['chat_id'=>$cid,'text'=>'🛒 Escolha o plano:','reply_markup'=>json_encode(['inline_keyboard'=>$b])]);
        }
        elseif($txt==='Teste Grátis'){
            $hoje=date('Ymd');
            if(($teste_dia[$uid]??'')!==$hoje){
                list($login,$senha)=gerarCred();
                $teste_dia[$uid]=$hoje;
                enviar(['chat_id'=>$cid,'text'=>"✅ <b>TESTE LIBERADO!</b>\n👤 Login: <code>$login</code>\n🔑 Senha: <code>$senha</code>",'parse_mode'=>'html']);
            }else enviar(['chat_id'=>$cid,'text'=>'⏰ Já usou hoje! Volta amanhã.']);
        }
        elseif($txt==='Recarga de Celular'){
            $b=[];foreach($operadoras as$k=>$o)$b[][]=['text'=>$o['nome'],'callback_data'=>"op_$k"];
            enviar(['chat_id'=>$cid,'text'=>'📱 Escolha a operadora:','reply_markup'=>json_encode(['inline_keyboard'=>$b])]);
        }
        elseif($txt==='Ajuda'){
            enviar(['chat_id'=>$cid,'text'=>"ℹ️ AJUDA\n\n🛒 Comprar SSH → Pagar → Receber\n📱 Recarga → Pagar → Até 8h\n🎁 Teste → 1x/dia",'parse_mode'=>'html']);
        }
    }
    usleep(200000);
}
