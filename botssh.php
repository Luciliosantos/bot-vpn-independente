<?php
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(0);

// ==============================================
// DADOS — TUDO AQUI
// ==============================================
$token = '8995379428:AAEdxzxUPguxuX51HNjUQ8c65HkjPzV4MZY';
$admin_id = 7761133138;
$mp_token = 'APP_USR-7527190269570273-090920-8e00f0eee8a23cb2fdd7f7d8db4a4dbf-226024458';

$api = "https://api.telegram.org/bot$token/";
$api_mp = "https://api.mercadopago.com/v1/payments";
$offset = 0;
$sessao = [];
$pagamentos_pendentes = [];
$testes_feitos = [];
$ultima_msg_acumulada = []; // Apenas mensagens em cima

// Planos SSH
$planos = [
    1 => ['dias' => 1,  'valor' => 1.00,  'nome' => '1 Dia — R$ 1,00'],
    2 => ['dias' => 5,  'valor' => 4.00,  'nome' => '5 Dias — R$ 4,00'],
    3 => ['dias' => 10, 'valor' => 10.00, 'nome' => '10 Dias — R$ 10,00'],
    4 => ['dias' => 15, 'valor' => 14.00, 'nome' => '15 Dias — R$ 14,00'],
    5 => ['dias' => 30, 'valor' => 20.00, 'nome' => '30 Dias — R$ 20,00'],
];

// Operadoras de recarga
$operadoras = [
    'vivo'  => ['nome' => '📱 Vivo',  'valores' => [10, 20, 30, 50, 100]],
    'claro' => ['nome' => '📱 Claro', 'valores' => [15, 25, 35, 50, 75]],
    'tim'   => ['nome' => '📱 Tim',   'valores' => [10, 20, 40, 60, 80]],
    'oi'    => ['nome' => '📱 Oi',    'valores' => [15, 30, 50, 70, 100]],
];

function limparAcumulada($cid) {
    global $api, $ultima_msg_acumulada;
    if (!empty($ultima_msg_acumulada[$cid])) {
        $ch = curl_init($api."deleteMessage");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'chat_id' => $cid,
            'message_id' => $ultima_msg_acumulada[$cid]
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_exec($ch);
        curl_close($ch);
    }
}

function enviar($dados, $salvar_acumulada=false) {
    global $api, $ultima_msg_acumulada;
    $ch = curl_init($api."sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($dados));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($salvar_acumulada && $resp) {
        $r = json_decode($resp, true);
        if (isset($r['result']['message_id'])) {
            $ultima_msg_acumulada[$dados['chat_id']] = $r['result']['message_id'];
        }
    }
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

function gerarAcesso($dias) {
    $login = 'ssh_'.substr(md5(uniqid()), 0, 8);
    $senha = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%&'), 0, 10);
    $expira = date('d/m/Y', strtotime("+$dias days"));
    return "🔐 <b>DADOS DE ACESSO</b>\n\n👤 Login: <code>$login</code>\n🔑 Senha: <code>$senha</code>\n📅 Válido até: $expira";
}

function gerarPix($valor, $desc, $mp_token) {
    global $api_mp;
    $dados = [
        'transaction_amount' => (float)$valor,
        'description' => $desc,
        'payment_method_id' => 'pix',
        'payer' => ['email' => 'cliente@exemplo.com']
    ];
    $ch = curl_init("$api_mp?access_token=$mp_token");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dados));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);
    
    if (isset($resp['point_of_interaction']['transaction_data']['qr_code']) && isset($resp['id'])) {
        return ['ok' => true, 'pix' => $resp['point_of_interaction']['transaction_data']['qr_code'], 'id' => $resp['id']];
    }
    return ['ok' => false];
}

echo "✅ BOT INICIADO!\n";

while (true) {
    foreach ($pagamentos_pendentes as $uid => $pg) {
        if (time() - $pg['tempo'] > 900) {
            unset($pagamentos_pendentes[$uid]);
            continue;
        }
        $ch = curl_init($api_mp."/".$pg['mp_id']."?access_token=$mp_token");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($resp['status']) && $resp['status'] === 'approved') {
            if ($pg['tipo'] === 'ssh') {
                enviar([
                    'chat_id' => $uid,
                    'text' => "✅ <b>PAGAMENTO CONFIRMADO!</b>\n\n".gerarAcesso($pg['dias']),
                    'parse_mode' => 'html'
                ]);
                enviar([
                    'chat_id' => $GLOBALS['admin_id'],
                    'text' => "💰 PAGO — SSH\n👤 $uid | {$pg['dias']} dias | R$ ".number_format($pg['valor'],2,',','')
                ]);
            } else {
                enviar([
                    'chat_id' => $uid,
                    'text' => "✅ <b>PAGAMENTO CONFIRMADO!</b>\n📱 Recarga em processamento!\nPode demorar até 8h.",
                    'parse_mode' => 'html'
                ]);
                enviar([
                    'chat_id' => $GLOBALS['admin_id'],
                    'text' => "💰 PAGO — RECARGA\n👤 Usuário: $uid\n📱 Número: {$pg['numero']}\n📶 Operadora: {$pg['nome_op']}\n💰 Valor: R$ ".number_format($pg['valor'],2,',','')."\n\n👉 Faça a recarga e avise o cliente!",
                    'parse_mode' => 'html'
                ]);
            }
            unset($pagamentos_pendentes[$uid]);
        }
    }

    $url = $api."getUpdates?offset=$offset&timeout=15";
    $resp = @file_get_contents($url);
    if ($resp === false) { sleep(2); continue; }
    
    $dados = json_decode($resp, true);
    if (!isset($dados['result'])) { sleep(1); continue; }
    
    foreach ($dados['result'] as $at) {
        $offset = $at['update_id'] + 1;
        
        if (isset($at['callback_query'])) {
            $cb = $at['callback_query'];
            $cid = $cb['message']['chat']['id'];
            $uid = $cb['from']['id'];
            $data = $cb['data'];
            file_get_contents($api."answerCallbackQuery?id=".$cb['id']);
            
            limparAcumulada($cid);
            
            if (strpos($data, 'plano_') === 0) {
                $pid = (int)substr($data, 6);
                if (!isset($planos[$pid])) continue;
                $pl = $planos[$pid];
                $pix = gerarPix($pl['valor'], "Plano SSH {$pl['dias']} dias", $mp_token);
                if ($pix['ok']) {
                    $pagamentos_pendentes[$uid] = [
                        'mp_id' => $pix['id'],
                        'tipo' => 'ssh',
                        'dias' => $pl['dias'],
                        'valor' => $pl['valor'],
                        'tempo' => time()
                    ];
                    enviar([
                        'chat_id' => $cid,
                        'text' => "💳 <b>PAGAMENTO VIA PIX</b>\n\n⏳ {$pl['nome']}\n💰 Valor: R$ ".number_format($pl['valor'],2,',','')."\n\n📋 Copie e cole:\n<pre>{$pix['pix']}</pre>\n✅ Após pagar, receba os dados automático!",
                        'parse_mode' => 'html'
                    ], true);
                } else {
                    enviar(['chat_id'=>$cid, 'text'=>'❌ Erro ao gerar PIX'], true);
                }
                continue;
            }
            
            if (strpos($data, 'op_') === 0) {
                $op_chave = substr($data, 3);
                if (!isset($operadoras[$op_chave])) continue;
                $sessao[$cid]['op'] = $op_chave;
                $nome_op = $operadoras[$op_chave]['nome'];
                
                $botoes_valores = [];
                foreach ($operadoras[$op_chave]['valores'] as $v) {
                    $botoes_valores[] = [['text' => "R$ $v,00", 'callback_data' => "val_{$op_chave}_{$v}"]];
                }
                enviar([
                    'chat_id' => $cid,
                    'text' => "💰 Valores disponíveis — $nome_op:",
                    'reply_markup' => json_encode(['inline_keyboard' => $botoes_valores])
                ], true);
                continue;
            }
            
            if (strpos($data, 'val_') === 0) {
                $partes = explode('_', $data);
                if (count($partes) !== 3) continue;
                list(, $op_chave, $valor_escolhido) = $partes;
                if (!isset($operadoras[$op_chave])) continue;
                
                $sessao[$cid]['op'] = $op_chave;
                $sessao[$cid]['valor'] = (int)$valor_escolhido;
                $sessao[$cid]['etapa'] = 'digitar_numero';
                
                enviar([
                    'chat_id' => $cid,
                    'text' => "📱 Digite o número com DDD:\nExemplo: 11999998888"
                ], true);
                continue;
            }
            continue;
        }
        
        if (!isset($at['message'])) continue;
        $msg = $at['message'];
        $cid = $msg['chat']['id'];
        $uid = $msg['from']['id'];
        $txt = trim($msg['text'] ?? '');
        
        if (isset($sessao[$cid]['etapa']) && $sessao[$cid]['etapa'] === 'digitar_numero') {
            limparAcumulada($cid);
            
            $num = preg_replace('/\D/', '', $txt);
            if (strlen($num) < 10 || strlen($num) > 11) {
                enviar(['chat_id' => $cid, 'text' => "❌ Número inválido! Digite com DDD:\nExemplo: 11999998888"], true);
                continue;
            }
            
            $op_chave = $sessao[$cid]['op'];
            $valor = (int)$sessao[$cid]['valor'];
            $nome_op = $operadoras[$op_chave]['nome'];
            
            $pix = gerarPix($valor, "Recarga $nome_op — $num", $mp_token);
            if (!$pix['ok']) {
                enviar(['chat_id' => $cid, 'text' => '❌ Erro ao gerar PIX. Tente novamente.'], true);
                unset($sessao[$cid]);
                continue;
            }
            
            $pagamentos_pendentes[$uid] = [
                'mp_id' => $pix['id'],
                'tipo' => 'recarga',
                'numero' => $num,
                'op' => $op_chave,
                'nome_op' => $nome_op,
                'valor' => $valor,
                'tempo' => time()
            ];
            
            enviar([
                'chat_id' => $cid,
                'text' => "💳 <b>PAGAMENTO VIA PIX — RECARGA</b>\n\n📱 Número: <code>$num</code>\n📶 Operadora: $nome_op\n💰 Valor: R$ ".number_format($valor,2,',','')."\n\n📋 Copie e cole:\n<pre>{$pix['pix']}</pre>\n✅ Após pagar, processamos automaticamente!\n⌛ Pode demorar até 8h.",
                'parse_mode' => 'html'
            ], true);
            
            unset($sessao[$cid]);
            continue;
        }
        
        // === MENU PRINCIPAL — NÃO APAGA O TECLADO DE BAIXO! ===
        if ($txt === '/start' || $txt === 'Voltar') {
            limparAcumulada($cid);
            enviar(['chat_id' => $cid, 'text' => '👋 Bem-vindo! Escolha uma opção:', 'reply_markup' => json_encode(teclado([
                ['Comprar SSH', 'Teste Grátis'],
                ['Recarga de Celular', 'Ajuda']
            ]))], true);
        }
        elseif ($txt === 'Comprar SSH') {
            limparAcumulada($cid);
            $botoes = [];
            foreach ($planos as $pid => $pl) {
                $botoes[] = [['text' => $pl['nome'], 'callback_data' => "plano_$pid"]];
            }
            enviar(['chat_id' => $cid, 'text' => '🛒 Escolha seu plano:', 'reply_markup' => json_encode(['inline_keyboard' => $botoes])], true);
        }
        elseif ($txt === 'Teste Grátis') {
            limparAcumulada($cid);
            if (podeTestar($uid)) {
                enviar(['chat_id' => $cid, 'text' => "✅ TESTE LIBERADO!\n\n".gerarAcesso(1)."\n\n⚠️ 1 teste por dia.", 'parse_mode' => 'html'], true);
                enviar(['chat_id' => $admin_id, 'text' => "🎁 Novo teste — $uid"]);
            } else {
                enviar(['chat_id' => $cid, 'text' => '⏰ Já usou o teste hoje!', 'reply_markup' => json_encode(teclado([['Comprar SSH'], ['Voltar']]))], true);
            }
        }
        elseif ($txt === 'Recarga de Celular') {
            limparAcumulada($cid);
            $botoes = [];
            foreach ($operadoras as $chave => $op) {
                $botoes[] = [['text' => $op['nome'], 'callback_data' => "op_$chave"]];
            }
            enviar(['chat_id' => $cid, 'text' => '📱 Escolha a operadora:', 'reply_markup' => json_encode(['inline_keyboard' => $botoes])], true);
        }
        elseif ($txt === 'Ajuda') {
            limparAcumulada($cid);
            enviar(['chat_id' => $cid, 'text' => 'ℹ️ <b>AJUDA</b>\n\n🛒 Comprar SSH → Escolher → Pagar → Receber automático\n📱 Recarga → Operadora → Valor → Número → Pagar → Processamos\n🎁 Teste → 1 por dia\n⌛ Recarga pode demorar até 8h.\n\nDúvidas? Fale com o administrador.', 'parse_mode' => 'html'], true);
        }
    }
    usleep(500000);
}
