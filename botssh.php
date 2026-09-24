<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

$token = '8591852336:AAHHK2tuPC0tjJK9G8gcjBk8x2FxylSVQu8';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments";
$offset = 0;
$sessao = [];
$pagamentos_pendentes = [];
$testes_feitos = [];

$planos = [
    ['dias' => 1,  'valor' => 1.00,  'nome' => '1 Dia — R$ 1,00'],
    ['dias' => 5,  'valor' => 4.00,  'nome' => '5 Dias — R$ 4,00'],
    ['dias' => 10, 'valor' => 10.00, 'nome' => '10 Dias — R$ 10,00'],
    ['dias' => 15, 'valor' => 14.00, 'nome' => '15 Dias — R$ 14,00'],
    ['dias' => 30, 'valor' => 20.00, 'nome' => '30 Dias — R$ 20,00'],
];

$operadoras = [
    'vivo'  => ['nome' => '📱 Vivo',  'valores' => [10, 15, 20, 30, 50]],
    'claro' => ['nome' => '📱 Claro', 'valores' => [10, 15, 20, 30, 50]],
    'tim'   => ['nome' => '📱 Tim',   'valores' => [10, 15, 20, 30, 50]],
    'oi'    => ['nome' => '📱 Oi',    'valores' => [10, 15, 20, 30, 50]],
];

function getIpPublic(){
    $ch = curl_init("https://api.ipify.org");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $ip = trim(curl_exec($ch));
    curl_close($ch);
    return $ip ?: gethostbyname(gethostname());
}

function criarContaSSHReal($dias){
    $usuario = 'u'.substr(md5(uniqid(mt_rand(), true)), 0, 8);
    $senha = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 10);
    $expira_data = date('d/m/Y', strtotime("+$dias days"));
    $ip_servidor = getIpPublic();

    exec("userdel -r $usuario 2>/dev/null");
    exec("useradd -M -s /bin/false $usuario");
    exec("echo '$usuario:$senha' | chpasswd");
    if ($dias > 0) {
        $data_exp = date('Y-m-d', strtotime("+$dias days"));
        exec("chage -E $data_exp $usuario");
    }
    
    return [
        'ok' => true,
        'usuario' => $usuario,
        'senha' => $senha,
        'ip' => $ip_servidor,
        'expira' => $expira_data,
        'texto' => "✅ <b>CONTA SSH CRIADA!</b>\n\n🌐 Servidor: <code>$ip_servidor</code>\n👤 Usuário: <code>$usuario</code>\n🔑 Senha: <code>$senha</code>\n📅 Válido até: <b>$expira_data</b>"
    ];
}

function enviar($dados) {
    global $api;
    $ch = curl_init($api."sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
}

function podeTestar($uid) {
    global $testes_feitos;
    $hoje = date('Y-m-d');
    if (isset($testes_feitos[$uid]) && $testes_feitos[$uid] === $hoje) return false;
    $testes_feitos[$uid] = $hoje;
    return true;
}

function gerarPix($valor, $desc, $mp_token) {
    global $api_mp;
    $dados = [
        'transaction_amount' => (float)$valor,
        'description' => $desc,
        'payment_method_id' => 'pix',
        'payer' => ['email' => 'c'.uniqid().'@gmail.com']
    ];
    $ch = curl_init($api_mp);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer '.$mp_token]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (isset($resp['point_of_interaction']['transaction_data']['qr_code'])) {
        return ['ok' => true, 'pix' => $resp['point_of_interaction']['transaction_data']['qr_code'], 'id' => $resp['id']];
    }
    return ['ok' => false, 'erro' => $resp['message'] ?? 'Erro'];
}

while (true) {
    foreach ($pagamentos_pendentes as $uid => $pg) {
        if (time() - $pg['tempo'] > 900) { unset($pagamentos_pendentes[$uid]); continue; }
        $ch = curl_init("$api_mp/{$pg['mp_id']}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer '.$mp_token]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($resp['status']) && $resp['status'] === 'approved') {
            if ($pg['tipo'] === 'ssh') {
                $conta = criarContaSSHReal($pg['dias']);
                enviar(['chat_id' => $uid, 'text' => "✅ <b>PAGO!</b>\n\n".$conta['texto'], 'parse_mode' => 'html']);
                enviar(['chat_id' => $GLOBALS['admin_id'], 'text' => "💰 VENDA: $uid — R$ {$pg['valor']}"]);
            } else {
                enviar(['chat_id' => $uid, 'text' => "✅ <b>PAGO!</b>\n📱 Recarga em processamento!\nPode demorar até 8h.", 'parse_mode' => 'html']);
                enviar(['chat_id' => $GLOBALS['admin_id'], 'text' => "💰 RECARGA: $uid — {$pg['numero']} — R$ {$pg['valor']}"]);
            }
            unset($pagamentos_pendentes[$uid]);
        }
    }

    $resp = @file_get_contents($api."getUpdates?offset=$offset&timeout=10");
    if ($resp === false) { sleep(1); continue; }
    $dados = json_decode($resp, true);
    if (!isset($dados['result'])) { sleep(1); continue; }
    
    foreach ($dados['result'] as $at) {
        $offset = $at['update_id'] + 1;
        if (!isset($at['message'])) continue;
        
        $msg = $at['message'];
        $cid = $msg['chat']['id'];
        $uid = $msg['from']['id'];
        $txt = trim($msg['text'] ?? '');
        
        if ($txt === 'Voltar' || $txt === '🔙 Voltar') {
            unset($sessao[$cid]);
            enviar(['chat_id' => $cid, 'text' => '👋 Menu Principal', 'reply_markup' => json_encode(['keyboard' => [['Comprar SSH', 'Teste Grátis'], ['Recarga de Celular', 'Ajuda']], 'resize_keyboard' => true])]);
            continue;
        }
        
        if ($txt === '/start') {
            unset($sessao[$cid]);
            enviar(['chat_id' => $cid, 'text' => '👋 Bem-vindo! Escolha:', 'reply_markup' => json_encode(['keyboard' => [['Comprar SSH', 'Teste Grátis'], ['Recarga de Celular', 'Ajuda']], 'resize_keyboard' => true])]);
        }
        elseif ($txt === 'Comprar SSH') {
            $b = []; foreach ($planos as $p) $b[] = [$p['nome']]; $b[] = ['🔙 Voltar'];
            enviar(['chat_id' => $cid, 'text' => '🛒 Escolha o plano:', 'reply_markup' => json_encode(['keyboard' => $b, 'resize_keyboard' => true])]);
            $sessao[$cid]['etapa'] = 'plano';
        }
        elseif ($txt === 'Teste Grátis') {
            if (podeTestar($uid)) {
                $c = criarContaSSHReal(1);
                enviar(['chat_id' => $cid, 'text' => "✅ TESTE!\n\n".$c['texto'], 'parse_mode' => 'html']);
                enviar(['chat_id' => $admin_id, 'text' => "TESTE: $uid"]);
            } else {
                enviar(['chat_id' => $cid, 'text' => '⏰ Já usou hoje! Compre um plano.', 'reply_markup' => json_encode(['keyboard' => [['Comprar SSH'], ['🔙 Voltar']]], 'resize_keyboard' => true])]);
            }
        }
        elseif ($txt === 'Recarga de Celular') {
            $b = []; foreach ($operadoras as $o) $b[] = [$o['nome']]; $b[] = ['🔙 Voltar'];
            enviar(['chat_id' => $cid, 'text' => '📱 Escolha operadora:', 'reply_markup' => json_encode(['keyboard' => $b, 'resize_keyboard' => true])]);
            $sessao[$cid]['etapa'] = 'operadora';
        }
        elseif ($txt === 'Ajuda') {
            enviar(['chat_id' => $cid, 'text' => 'ℹ️ Compre → Pague → Receba\n⏳ Recarga até 8h', 'reply_markup' => json_encode(['keyboard' => [['🔙 Voltar']], 'resize_keyboard' => true])]);
        }
        elseif (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa'] === 'plano') {
            foreach ($planos as $p) {
                if ($txt === $p['nome']) {
                    $pix = gerarPix($p['valor'], "SSH {$p['dias']}d", $mp_token);
                    if ($pix['ok']) {
                        $pagamentos_pendentes[$uid] = ['mp_id' => $pix['id'], 'tipo' => 'ssh', 'dias' => $p['dias'], 'valor' => $p['valor'], 'tempo' => time()];
                        enviar(['chat_id' => $cid, 'text' => "💳 PIX — {$p['nome']}\nValor: R$ ".number_format($p['valor'],2,',','')."\n\n<pre>{$pix['pix']}</pre>\nApós pagar, recebe automático!", 'parse_mode' => 'html']);
                    } else { enviar(['chat_id' => $cid, 'text' => '❌ '.$pix['erro']]); }
                    break;
                }
            }
        }
        elseif (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa'] === 'operadora') {
            foreach ($operadoras as $k => $o) {
                if ($txt === $o['nome']) {
                    $sessao[$cid]['op'] = $k;
                    $sessao[$cid]['etapa'] = 'numero';
                    enviar(['chat_id' => $cid, 'text' => '📱 Digite número com DDD:', 'reply_markup' => json_encode(['keyboard' => [['🔙 Voltar']], 'resize_keyboard' => true])]);
                    break;
                }
            }
        }
        elseif (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa'] === 'numero') {
            $num = preg_replace('/\D/', '', $txt);
            if (strlen($num) >= 10 && strlen($num) <= 11) {
                $sessao[$cid]['num'] = $num;
                $sessao[$cid]['etapa'] = 'valor';
                $op = $operadoras[$sessao[$cid]['op']];
                $b = []; foreach ($op['valores'] as $v) $b[] = ["R$ $v,00"]; $b[] = ['🔙 Voltar'];
                enviar(['chat_id' => $cid, 'text' => '💰 Valor:', 'reply_markup' => json_encode(['keyboard' => $b, 'resize_keyboard' => true])]);
            } else {
                enviar(['chat_id' => $cid, 'text' => '❌ Número inválido! Com DDD.']);
            }
        }
        elseif (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa'] === 'valor') {
            $val = preg_replace('/\D/', '', $txt);
            if ($val) {
                $op = $operadoras[$sessao[$cid]['op']];
                $pix = gerarPix($val, "Recarga {$op['nome']} {$sessao[$cid]['num']}", $mp_token);
                if ($pix['ok']) {
                    $pagamentos_pendentes[$uid] = ['mp_id' => $pix['id'], 'tipo' => 'recarga', 'nome_op' => $op['nome'], 'numero' => $sessao[$cid]['num'], 'valor' => $val, 'tempo' => time()];
                    unset($sessao[$cid]);
                    enviar(['chat_id' => $cid, 'text' => "💳 PIX — RECARGA\nOperadora: {$op['nome']}\nNúmero: {$sessao[$cid]['num']}\nValor: R$ $val,00\n\n<pre>{$pix['pix']}</pre>\nApós pagar, processamos!\nPode demorar até 8h.", 'parse_mode' => 'html']);
                } else { enviar(['chat_id' => $cid, 'text' => '❌ '.$pix['erro']]); }
            }
        }
    }
    usleep(300000);
}