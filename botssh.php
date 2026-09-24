<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// === DADOS ===
$token = '8999330752:AAF-JcIr6AwhK7uPkrOCtFUPUaN294SKDBk';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments";
$offset = 0;
$sessao = [];
$pagamentos = [];
$testes_feitos = [];

// Planos
$planos = [
    1 => ['dias' => 1, 'valor' => 1.00, 'nome' => '1 Dia — R$ 1,00'],
    2 => ['dias' => 5, 'valor' => 4.00, 'nome' => '5 Dias — R$ 4,00'],
    3 => ['dias' => 10, 'valor' => 10.00, 'nome' => '10 Dias — R$ 10,00'],
    4 => ['dias' => 15, 'valor' => 14.00, 'nome' => '15 Dias — R$ 14,00'],
    5 => ['dias' => 30, 'valor' => 20.00, 'nome' => '30 Dias — R$ 20,00'],
];

// Operadoras
$operadoras = [
    'vivo'  => ['nome' => '📱 Vivo',  'valores' => [10,20,30,50,100]],
    'claro' => ['nome' => '📱 Claro', 'valores' => [15,25,35,50,75]],
    'tim'   => ['nome' => '📱 Tim',   'valores' => [10,20,40,60,80]],
    'oi'    => ['nome' => '📱 Oi',    'valores' => [15,30,50,70,100]],
];

function enviar($dados) {
    global $api;
    $ch = curl_init($api."sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function teclado($botoes, $res=true) {
    return ['keyboard' => $botoes, 'resize_keyboard' => $res];
}

function podeTestar($uid) {
    global $testes_feitos;
    $hoje = date('Y-m-d');
    if (isset($testes_feitos[$uid]) && $testes_feitos[$uid]===$hoje) return false;
    $testes_feitos[$uid] = $hoje;
    return true;
}

echo "✅ BOT INICIADO — Funcionando!\n";

while (true) {
    $url = $api."getUpdates?offset=$offset&timeout=15";
    $resp = @file_get_contents($url);
    if ($resp===false) { sleep(2); continue; }
    $dados = json_decode($resp, true);
    if (!isset($dados['result'])) { sleep(1); continue; }
    
    foreach ($dados['result'] as $at) {
        $offset = $at['update_id']+1;
        
        if (isset($at['callback_query'])) {
            $cb = $at['callback_query'];
            $cid = $cb['message']['chat']['id'];
            $uid = $cb['from']['id'];
            $data = $cb['data'];
            file_get_contents($api."answerCallbackQuery?id=".$cb['id']);
            
            if (strpos($data, 'plano_')===0) {
                $pid = (int)substr($data,6);
                if (!isset($planos[$pid])) continue;
                $pl = $planos[$pid];
                
                $mp_dados = [
                    'transaction_amount' => $pl['valor'],
                    'description' => "Plano SSH — {$pl['dias']} dias",
                    'payment_method_id' => 'pix',
                    'payer' => ['email' => 'cliente@teste.com']
                ];
                $ch = curl_init("$api_mp?access_token=$mp_token");
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($mp_dados));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $mp_resp = json_decode(curl_exec($ch), true);
                curl_close($ch);
                
                if (isset($mp_resp['point_of_interaction']['transaction_data']['qr_code'])) {
                    $pix = $mp_resp['point_of_interaction']['transaction_data']['qr_code'];
                    enviar([
                        'chat_id' => $cid,
                        'text' => "💳 PIX — {$pl['nome']}\nValor: R$ ".number_format($pl['valor'],2,',','')."\n\nCopia e cola:\n<pre>$pix</pre>\nApós pagar envio os dados!",
                        'parse_mode' => 'html'
                    ]);
                } else {
                    enviar(['chat_id'=>$cid, 'text'=>'❌ Erro ao gerar PIX']);
                }
                continue;
            }
            
            if (strpos($data, 'op_')===0) {
                $op = substr($data,3);
                if (!isset($operadoras[$op])) continue;
                $sessao[$cid]['op'] = $op;
                $botoes = [];
                foreach ($operadoras[$op]['valores'] as $v) {
                    $botoes[] = [['text'=>"R$ $v,00", 'callback_data'=>"val_{$op}_$v"]];
                }
                enviar(['chat_id'=>$cid, 'text'=>'💰 Valores:', 'reply_markup'=>json_encode(['inline_keyboard'=>$botoes])]);
                continue;
            }
            
            if (strpos($data, 'val_')===0) {
                list(,,$op,$v) = explode('_',$data);
                $sessao[$cid]['op']=$op;
                $sessao[$cid]['val']=$v;
                $sessao[$cid]['etapa']='numero';
                enviar(['chat_id'=>$cid, 'text'=>'📱 Digite o número com DDD:']);
                continue;
            }
            continue;
        }
        
        if (!isset($at['message'])) continue;
        $msg = $at['message'];
        $cid = $msg['chat']['id'];
        $uid = $msg['from']['id'];
        $txt = trim($msg['text'] ?? '');
        
        if (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa']==='numero') {
            $num = preg_replace('/\D/','',$txt);
            if (strlen($num)<10 || strlen($num)>11) {
                enviar(['chat_id'=>$cid, 'text'=>'❌ Número inválido!']);
                continue;
            }
            $op = $sessao[$cid]['op'];
            $v = $sessao[$cid]['val'];
            enviar(['chat_id'=>$admin_id, 'text'=>"🔔 PEDIDO RECARGA\nUsuário: $cid\nNúmero: $num\nOperadora: {$operadoras[$op]['nome']}\nValor: R$ $v,00"]);
            enviar(['chat_id'=>$cid, 'text'=>"✅ Pedido recebido!\nNúmero: $num\nOperadora: {$operadoras[$op]['nome']}\nValor: R$ $v,00"]);
            unset($sessao[$cid]);
            continue;
        }
        
        if ($txt==='/start' || $txt==='Voltar') {
            enviar(['chat_id'=>$cid, 'text'=>'👋 Escolha:', 'reply_markup'=>json_encode(teclado([
                ['Comprar SSH','Teste Grátis'],
                ['Recarga de Celular','Ajuda']
            ]))]);
        }
        elseif ($txt==='Comprar SSH') {
            $botoes=[];
            foreach ($planos as $pid=>$pl) $botoes[]=[['text'=>$pl['nome'],'callback_data'=>"plano_$pid"]];
            enviar(['chat_id'=>$cid, 'text'=>'🛒 Escolha o plano:', 'reply_markup'=>json_encode(['inline_keyboard'=>$botoes])]);
        }
        elseif ($txt==='Teste Grátis') {
            if (podeTestar($uid)) {
                $login='teste_'.substr(md5($uid.time()),0,6);
                enviar(['chat_id'=>$cid, 'text'=>"✅ Teste liberado!\nLogin: $login\nSenha: 12345678\nVálido 24h"]);
                enviar(['chat_id'=>$admin_id, 'text'=>"🎁 Teste de $uid"]);
            } else {
                enviar(['chat_id'=>$cid, 'text'=>'⏰ Já usou o teste hoje!', 'reply_markup'=>json_encode(teclado([['Comprar SSH'],['Voltar']]))]);
            }
        }
        elseif ($txt==='Recarga de Celular') {
            $botoes=[];
            foreach ($operadoras as $k=>$op) $botoes[]=[['text'=>$op['nome'],'callback_data'=>"op_$k"]];
            enviar(['chat_id'=>$cid, 'text'=>'📱 Escolha a operadora:', 'reply_markup'=>json_encode(['inline_keyboard'=>$botoes])]);
        }
        elseif ($txt==='Ajuda') {
            enviar(['chat_id'=>$cid, 'text'=>'ℹ️ Comprar → Pagar → Receber\nTeste → 1/dia\nRecarga → Preencher dados', 'parse_mode'=>'html']);
        }
    }
    sleep(1);
}
